import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError } from "@/lib/auth";
import { isNationalScope } from "@/lib/domain/access";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import {
  assertTaxpayerSystemTransition,
  validateTaxpayerSystemRegistration,
  validateTaxpayerSystemSuspension,
  validateTaxpayerSystemSync,
  type TaxpayerSystemAction,
} from "@/lib/domain/taxpayer-system";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

type PriorCommand = { request_hash: string; resource_id: string };

export class TaxpayerSystemResourceError extends Error {
  readonly status: number;

  constructor(message: string, status = 422) {
    super(message);
    this.name = "TaxpayerSystemResourceError";
    this.status = status;
  }
}

function validateKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new TaxpayerSystemResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

async function replay(db: D1Database, actorId: string, command: string, key: string, hash: string) {
  const prior = await db.prepare("SELECT request_hash,resource_id FROM command_idempotency WHERE actor_id=? AND command_type=? AND idempotency_key=?").bind(actorId, command, key).first<PriorCommand>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different taxpayer system command.");
  return prior.resource_id;
}

function commandRecord(db: D1Database, actorId: string, command: string, key: string, hash: string, resourceType: string, resourceId: string, now: string) {
  return db.prepare("INSERT INTO command_idempotency VALUES (?,?,?,?,?,?,?,?)").bind(crypto.randomUUID(), actorId, command, key, hash, resourceType, resourceId, now);
}

/** Delegates to the single shared hash-chain writer — see lib/data/audit-repository.ts's appendAuditEvent. */
async function auditRecord(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>, now: string) {
  return appendAuditEvent(db, actor, action, resourceType, resourceId, details, now);
}

function outbox(db: D1Database, aggregateType: string, aggregateId: string, eventType: string, partitionKey: string, payload: Record<string, unknown>, now: string) {
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), aggregateType, aggregateId, eventType, 1, partitionKey, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

type OrganisationForRegistration = { organisationId: string; taxpayerId: string; vatNumber: string; tin: string };

/**
 * Registered Taxpayer Systems are always the actor's own organisation's
 * property — unlike integration_connections there is no platform-wide
 * registration, since an ERP/POS/accounting system is always someone's own
 * business system. A national/no-taxpayer actor cannot register one (there
 * is no taxpayer identity to register it against).
 */
async function resolveOrganisationForRegistration(db: D1Database, actor: UserContext): Promise<OrganisationForRegistration> {
  if (!actor.taxpayerId) throw new AccessDeniedError("Only a taxpayer's own organisation may register a system.");
  const row = await db.prepare(`SELECT o.id AS organisation_id,t.id AS taxpayer_id,t.vat_number,t.tin FROM organisations o
    JOIN taxpayers t ON t.id=o.taxpayer_id WHERE o.taxpayer_id=? AND o.status='ACTIVE'`).bind(actor.taxpayerId).first<{ organisation_id: string; taxpayer_id: string; vat_number: string; tin: string }>();
  if (!row) throw new AccessDeniedError("Your account is not assigned to an active organisation.");
  return { organisationId: row.organisation_id, taxpayerId: row.taxpayer_id, vatNumber: row.vat_number, tin: row.tin };
}

type RegistrationRow = { id: string; organisation_id: string; registration_status: string };

/**
 * A tenant actor may only ever load their own organisation's registration
 * (register/suspend/sync are all self-service). A national-scope actor may
 * load any organisation's registration — approving/reviewing another
 * taxpayer's system is the entire point of the approval gate below.
 */
async function loadRegistrationForActor(db: D1Database, actor: UserContext, id: string): Promise<RegistrationRow> {
  const row = await db.prepare("SELECT id,organisation_id,registration_status FROM taxpayer_system_registrations WHERE id=?").bind(id).first<RegistrationRow>();
  if (!row) throw new TaxpayerSystemResourceError("Taxpayer system registration was not found.", 404);
  if (!isNationalScope(actor)) {
    if (!actor.taxpayerId) throw new AccessDeniedError("Only that organisation's own taxpayer may manage its registered systems.");
    const org = await db.prepare("SELECT id FROM organisations WHERE taxpayer_id=? AND id=?").bind(actor.taxpayerId, row.organisation_id).first<{ id: string }>();
    if (!org) throw new AccessDeniedError("This registration is outside your authorised organisation scope.");
  }
  return row;
}

/** RegisterTaxpayerSystem. The submitted vat_registration_number (and tin, if provided) must match the actor's own taxpayer record — this framework registers a taxpayer's own system, never a claim about someone else's identity. */
export async function registerTaxpayerSystem(payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateTaxpayerSystemRegistration(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisationForRegistration(db, actor);

  if (input.vat_registration_number !== organisation.vatNumber.toUpperCase()) {
    throw new TaxpayerSystemResourceError("vat_registration_number must match your own taxpayer's registered VAT number.");
  }
  if (input.tin && input.tin !== organisation.tin.toUpperCase()) {
    throw new TaxpayerSystemResourceError("tin must match your own taxpayer's registered TIN.");
  }

  const hash = await sha256Hex(stableStringify(input));
  const prior = await replay(db, actor.userId, "REGISTER_TAXPAYER_SYSTEM", key, hash);
  if (prior) return db.prepare("SELECT * FROM taxpayer_system_registrations WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const existing = await db.prepare("SELECT id FROM taxpayer_system_registrations WHERE organisation_id=? AND system_name=? AND system_vendor=?")
    .bind(organisation.organisationId, input.system_name, input.system_vendor).first<{ id: string }>();
  if (existing) throw new RepositoryConflictError(`A registration for ${input.system_name} (${input.system_vendor}) already exists as ${existing.id}.`);

  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO taxpayer_system_registrations
        (id,organisation_id,taxpayer_id,vat_registration_number,tin,company_registration_number,system_name,system_vendor,system_category,credential_reference,api_status,registration_status,security_status,last_synchronization_at,created_by,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
      id, organisation.organisationId, organisation.taxpayerId, input.vat_registration_number, input.tin ?? null, input.company_registration_number ?? null,
      input.system_name, input.system_vendor, input.system_category, input.credential_reference ?? null,
      "NOT_CONNECTED", "DRAFT", "NOT_ASSESSED", null, actor.userId, now, now,
    ),
    commandRecord(db, actor.userId, "REGISTER_TAXPAYER_SYSTEM", key, hash, "TAXPAYER_SYSTEM_REGISTRATION", id, now),
    outbox(db, "TAXPAYER_SYSTEM_REGISTRATION", id, "TaxpayerSystemRegistered", organisation.organisationId, { system_name: input.system_name, system_vendor: input.system_vendor, organisation_id: organisation.organisationId, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "TAXPAYER_SYSTEM_REGISTERED", "TAXPAYER_SYSTEM_REGISTRATION", id, { systemName: input.system_name, systemVendor: input.system_vendor, organisationId: organisation.organisationId, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM taxpayer_system_registrations WHERE id=?").bind(id).first<Record<string, unknown>>();
}

async function transitionTaxpayerSystem(id: string, action: TaxpayerSystemAction, actor: UserContext, key: string, correlationId: string, extraDetails: Record<string, unknown>) {
  validateKey(key);
  const db = await ensureDatabase();
  const registration = await loadRegistrationForActor(db, actor, id);
  const target = assertTaxpayerSystemTransition(action, registration.registration_status);

  const hash = await sha256Hex(stableStringify({ registration_id: id, action, extraDetails }));
  const command = action === "APPROVE" ? "APPROVE_TAXPAYER_SYSTEM" : "SUSPEND_TAXPAYER_SYSTEM";
  const prior = await replay(db, actor.userId, command, key, hash);
  if (prior) return db.prepare("SELECT * FROM taxpayer_system_registrations WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE taxpayer_system_registrations SET registration_status=?, updated_at=? WHERE id=?").bind(target, now, id),
    commandRecord(db, actor.userId, command, key, hash, "TAXPAYER_SYSTEM_REGISTRATION", id, now),
    outbox(db, "TAXPAYER_SYSTEM_REGISTRATION", id, action === "APPROVE" ? "TaxpayerSystemApproved" : "TaxpayerSystemSuspended", registration.organisation_id, { taxpayer_system_registration_id: id, from_status: registration.registration_status, to_status: target, correlation_id: correlationId, ...extraDetails }, now),
    await auditRecord(db, actor, action === "APPROVE" ? "TAXPAYER_SYSTEM_APPROVED" : "TAXPAYER_SYSTEM_SUSPENDED", "TAXPAYER_SYSTEM_REGISTRATION", id, { fromStatus: registration.registration_status, toStatus: target, correlationId, ...extraDetails }, now),
  ]);
  return db.prepare("SELECT * FROM taxpayer_system_registrations WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/**
 * ApproveTaxpayerSystem: DRAFT or SUSPENDED -> APPROVED. This is the
 * "Integration approval" field the master prompt names as its own concept,
 * distinct from the taxpayer's own registration act — restricted to a
 * national-scope (NamRA) actor regardless of what the caller's licensed
 * permission alone would otherwise allow, matching the same isNationalScope
 * defense-in-depth pattern lib/data/compliance-repository.ts uses for every
 * NamRA-only compliance/risk action.
 */
export async function approveTaxpayerSystem(id: string, actor: UserContext, key: string, correlationId: string) {
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may approve a taxpayer system registration.");
  return transitionTaxpayerSystem(id, "APPROVE", actor, key, correlationId, {});
}

/** SuspendTaxpayerSystem: APPROVED -> SUSPENDED. Self-service — the owning taxpayer may pause their own system (e.g. decommissioning), same as SuspendIntegration. Requires a recorded reason. */
export async function suspendTaxpayerSystem(id: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  const input = validateTaxpayerSystemSuspension(payload);
  return transitionTaxpayerSystem(id, "SUSPEND", actor, key, correlationId, { reason: input.reason });
}

/** RecordSynchronization: the taxpayer's own system reports its post-sync connectivity state and a fresh last_synchronization_at timestamp. Only meaningful for an APPROVED registration. */
export async function recordTaxpayerSystemSync(id: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateTaxpayerSystemSync(payload);
  const db = await ensureDatabase();
  const registration = await loadRegistrationForActor(db, actor, id);
  if (registration.registration_status !== "APPROVED") throw new RepositoryConflictError("Synchronization can only be recorded for an approved registration.");

  const hash = await sha256Hex(stableStringify({ registration_id: id, input }));
  const prior = await replay(db, actor.userId, "RECORD_TAXPAYER_SYSTEM_SYNC", key, hash);
  if (prior) return db.prepare("SELECT * FROM taxpayer_system_registrations WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE taxpayer_system_registrations SET api_status=?, last_synchronization_at=?, updated_at=? WHERE id=?").bind(input.api_status, now, now, id),
    commandRecord(db, actor.userId, "RECORD_TAXPAYER_SYSTEM_SYNC", key, hash, "TAXPAYER_SYSTEM_REGISTRATION", id, now),
    outbox(db, "TAXPAYER_SYSTEM_REGISTRATION", id, "TaxpayerSystemSynchronized", registration.organisation_id, { taxpayer_system_registration_id: id, api_status: input.api_status, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "TAXPAYER_SYSTEM_SYNCHRONIZED", "TAXPAYER_SYSTEM_REGISTRATION", id, { apiStatus: input.api_status, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM taxpayer_system_registrations WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/** GetTaxpayerSystem: a single registration, subject to the same tenant/national load-scope rule as the write commands. */
export async function getTaxpayerSystem(id: string, actor: UserContext) {
  const db = await ensureDatabase();
  const registration = await loadRegistrationForActor(db, actor, id);
  return db.prepare("SELECT * FROM taxpayer_system_registrations WHERE id=?").bind(registration.id).first<Record<string, unknown>>();
}

/** ListTaxpayerSystems: a tenant actor sees only their own organisation's registrations; a national-scope actor sees every registered system across all taxpayers (the whole point of NamRA needing this register at all). */
export async function listTaxpayerSystems(actor: UserContext) {
  const db = await ensureDatabase();
  if (isNationalScope(actor)) {
    const result = await db.prepare("SELECT * FROM taxpayer_system_registrations ORDER BY created_at DESC").all<Record<string, unknown>>();
    return result.results;
  }
  if (!actor.taxpayerId) throw new AccessDeniedError("Your account is not assigned to an active organisation.");
  const result = await db.prepare(`SELECT r.* FROM taxpayer_system_registrations r
    JOIN organisations o ON o.id=r.organisation_id
    WHERE o.taxpayer_id=? ORDER BY r.created_at DESC`).bind(actor.taxpayerId).all<Record<string, unknown>>();
  return result.results;
}
