import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError } from "@/lib/auth";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import { assertIntegrationTransition, validateIntegrationRegistration, validateIntegrationSuspension, validateSyncStart, type IntegrationAction } from "@/lib/domain/integration";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

type PriorCommand = { request_hash: string; resource_id: string };

export class IntegrationResourceError extends Error {
  readonly status: number;

  constructor(message: string, status = 422) {
    super(message);
    this.name = "IntegrationResourceError";
    this.status = status;
  }
}

function validateKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new IntegrationResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

async function replay(db: D1Database, actorId: string, command: string, key: string, hash: string) {
  const prior = await db.prepare("SELECT request_hash,resource_id FROM command_idempotency WHERE actor_id=? AND command_type=? AND idempotency_key=?").bind(actorId, command, key).first<PriorCommand>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different integration command.");
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

/**
 * Any actor with no taxpayerId at all — a national NamRA role or a
 * platform-technical role (SUPER_ADMIN/INFRASTRUCTURE_ADMIN, neither of
 * which is in isNationalScope's own NATIONAL_SCOPE_ROLES list, since
 * neither represents a tax-administration function — see
 * lib/domain/access.ts) — registers a platform-wide connection
 * (organisation_id NULL). Any actor with a taxpayerId registers for their
 * own active organisation only; a tenant actor can never register on
 * behalf of another organisation or platform-wide.
 */
async function resolveOrganisationForRegistration(db: D1Database, actor: UserContext): Promise<string | null> {
  if (!actor.taxpayerId) return null;
  const row = await db.prepare("SELECT id FROM organisations WHERE taxpayer_id=? AND status='ACTIVE'").bind(actor.taxpayerId).first<{ id: string }>();
  if (!row) throw new AccessDeniedError("Your account is not assigned to an active organisation.");
  return row.id;
}

type ConnectionRow = { id: string; organisation_id: string | null; configuration_status: string; provider_key: string };

/** Loads a connection and enforces its ownership boundary: a tenant actor may only ever act on their own organisation's row (never a platform-wide one), and a platform/national actor may only ever act on a platform-wide row (never reach into a specific tenant's connection) — kept deliberately symmetric and simple for this phase. */
async function loadConnectionForActor(db: D1Database, actor: UserContext, id: string): Promise<ConnectionRow> {
  const row = await db.prepare("SELECT id,organisation_id,configuration_status,provider_key FROM integration_connections WHERE id=?").bind(id).first<ConnectionRow>();
  if (!row) throw new IntegrationResourceError("Integration connection was not found.", 404);
  if (row.organisation_id) {
    if (!actor.taxpayerId) throw new AccessDeniedError("Only that connection's own organisation may manage it.");
    const org = await db.prepare("SELECT id FROM organisations WHERE taxpayer_id=? AND id=?").bind(actor.taxpayerId, row.organisation_id).first<{ id: string }>();
    if (!org) throw new AccessDeniedError("This connection is outside your authorised organisation scope.");
  } else if (actor.taxpayerId) {
    throw new AccessDeniedError("Only a national or platform-scope actor may manage a platform-wide connection.");
  }
  return row;
}

/**
 * Module 10 Phase A: RegisterIntegration. Deliberately provider-agnostic —
 * the same command shape registers a SaaS/ERP connection or (in principle)
 * a government one, though in practice the four government/banking/
 * treasury connections this deployment actually needs (ITAS, BIPA,
 * bank-org1, treasury) are already seeded directly in db/runtime.ts with
 * their own free-text "REQUIRES_*_CONTRACT" status, and RegisterIntegration
 * registering the same provider_key again for the same organisation scope
 * is refused as a conflict — there is deliberately no path for this
 * command to ever touch those four rows.
 */
export async function registerIntegration(payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateIntegrationRegistration(payload);
  const db = await ensureDatabase();
  const organisationId = await resolveOrganisationForRegistration(db, actor);

  const hash = await sha256Hex(stableStringify(input));
  const prior = await replay(db, actor.userId, "REGISTER_INTEGRATION", key, hash);
  if (prior) return db.prepare("SELECT * FROM integration_connections WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const existing = await db.prepare("SELECT id FROM integration_connections WHERE provider_key=? AND COALESCE(organisation_id,'')=COALESCE(?,'')").bind(input.provider_key, organisationId).first<{ id: string }>();
  if (existing) throw new RepositoryConflictError(`A connection for ${input.provider_key} already exists as ${existing.id}.`);

  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO integration_connections
        (id,organisation_id,provider_key,category,display_name,capabilities,endpoint_reference,credential_reference,configuration_status,operational_status,data_classification,last_health_check_at,last_health_outcome,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
      id, organisationId, input.provider_key, input.category, input.display_name, JSON.stringify(input.capabilities),
      input.endpoint_reference ?? null, input.credential_reference ?? null, "DRAFT", "DISABLED", input.data_classification,
      null, null, now, now,
    ),
    commandRecord(db, actor.userId, "REGISTER_INTEGRATION", key, hash, "INTEGRATION_CONNECTION", id, now),
    outbox(db, "INTEGRATION_CONNECTION", id, "IntegrationRegistered", organisationId ?? "platform", { provider_key: input.provider_key, category: input.category, organisation_id: organisationId, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "INTEGRATION_REGISTERED", "INTEGRATION_CONNECTION", id, { providerKey: input.provider_key, category: input.category, organisationId, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM integration_connections WHERE id=?").bind(id).first<Record<string, unknown>>();
}

async function transitionIntegration(id: string, action: IntegrationAction, actor: UserContext, key: string, correlationId: string, extraDetails: Record<string, unknown>) {
  validateKey(key);
  const db = await ensureDatabase();
  const connection = await loadConnectionForActor(db, actor, id);
  const target = assertIntegrationTransition(action, connection.configuration_status);

  const hash = await sha256Hex(stableStringify({ connection_id: id, action, extraDetails }));
  const command = action === "APPROVE" ? "APPROVE_INTEGRATION" : "SUSPEND_INTEGRATION";
  const prior = await replay(db, actor.userId, command, key, hash);
  if (prior) return db.prepare("SELECT * FROM integration_connections WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const operationalStatus = target === "CONFIGURED" ? "OPERATIONAL" : "DISABLED";
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE integration_connections SET configuration_status=?, operational_status=?, updated_at=? WHERE id=?").bind(target, operationalStatus, now, id),
    commandRecord(db, actor.userId, command, key, hash, "INTEGRATION_CONNECTION", id, now),
    outbox(db, "INTEGRATION_CONNECTION", id, action === "APPROVE" ? "IntegrationApproved" : "IntegrationSuspended", connection.organisation_id ?? "platform", { integration_connection_id: id, from_status: connection.configuration_status, to_status: target, correlation_id: correlationId, ...extraDetails }, now),
    await auditRecord(db, actor, action === "APPROVE" ? "INTEGRATION_APPROVED" : "INTEGRATION_SUSPENDED", "INTEGRATION_CONNECTION", id, { fromStatus: connection.configuration_status, toStatus: target, correlationId, ...extraDetails }, now),
  ]);
  return db.prepare("SELECT * FROM integration_connections WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/** ApproveIntegration: DRAFT or SUSPENDED -> CONFIGURED (and operational_status -> OPERATIONAL). No maker-checker requirement — Module 10 Phase A names no such rule, unlike Module 4/9's case/refund lifecycles. */
export async function approveIntegration(id: string, actor: UserContext, key: string, correlationId: string) {
  return transitionIntegration(id, "APPROVE", actor, key, correlationId, {});
}

/** SuspendIntegration: CONFIGURED -> SUSPENDED (and operational_status -> DISABLED). Requires a recorded reason. */
export async function suspendIntegration(id: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  const input = validateIntegrationSuspension(payload);
  return transitionIntegration(id, "SUSPEND", actor, key, correlationId, { reason: input.reason });
}

/**
 * StartSync. This pilot has no live per-provider connector implementation
 * for any provider — that is explicitly out of scope for the generic
 * connector model this phase builds (see the matrix's own "Required
 * closure: per-provider contracts, credentials, conformance sandbox" for
 * domain #25). Every StartSync therefore completes immediately, recording
 * an honest FAILED sync_jobs row with a typed reason — never a fabricated
 * COMPLETED with invented record counts. This still proves the command's
 * full shape (idempotent, audited, tenant/platform-scoped, only runnable
 * against an approved CONFIGURED connection) end to end.
 */
export async function startSync(id: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateSyncStart(payload);
  const db = await ensureDatabase();
  const connection = await loadConnectionForActor(db, actor, id);
  if (connection.configuration_status !== "CONFIGURED") throw new RepositoryConflictError("A sync can only be started for an approved, CONFIGURED connection.");

  const hash = await sha256Hex(stableStringify({ connection_id: id, input }));
  const prior = await replay(db, actor.userId, "START_SYNC", key, hash);
  if (prior) return db.prepare("SELECT * FROM sync_jobs WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const jobId = crypto.randomUUID();
  const now = new Date().toISOString();
  const noConnectorReason = "No live connector implementation exists for this provider yet — this pilot builds the typed StartSync command shape, not a working per-provider data pipe.";
  await db.batch([
    db.prepare(`INSERT INTO sync_jobs
        (id,integration_connection_id,organisation_id,job_type,direction,status,cursor,records_read,records_written,error_count,requested_by,requested_at,started_at,completed_at,last_error)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
      jobId, id, connection.organisation_id, input.job_type, input.direction, "FAILED", null, 0, 0, 1,
      actor.userId, now, now, now, noConnectorReason,
    ),
    commandRecord(db, actor.userId, "START_SYNC", key, hash, "SYNC_JOB", jobId, now),
    outbox(db, "SYNC_JOB", jobId, "SyncJobFailed", connection.organisation_id ?? "platform", { integration_connection_id: id, job_type: input.job_type, direction: input.direction, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "SYNC_STARTED", "SYNC_JOB", jobId, { integrationConnectionId: id, jobType: input.job_type, direction: input.direction, outcome: "FAILED_NO_CONNECTOR", correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM sync_jobs WHERE id=?").bind(jobId).first<Record<string, unknown>>();
}

/** GetHealth: the connection's own stored status plus its 10 most recent sync attempts — a real health *projection*, not a live probe (there is nothing to live-probe; see StartSync). Connection discovery/listing itself is already covered by the existing GET /api/v1/platform snapshot's own `integrations` array — deliberately not duplicated here. */
export async function getIntegrationHealth(id: string, actor: UserContext) {
  const db = await ensureDatabase();
  const connection = await loadConnectionForActor(db, actor, id);
  const full = await db.prepare("SELECT * FROM integration_connections WHERE id=?").bind(connection.id).first<Record<string, unknown>>();
  const recentSyncJobs = await db.prepare("SELECT id,job_type,direction,status,records_read,records_written,error_count,requested_at,completed_at,last_error FROM sync_jobs WHERE integration_connection_id=? ORDER BY requested_at DESC LIMIT 10").bind(connection.id).all<Record<string, unknown>>();
  return { connection: full, recentSyncJobs: recentSyncJobs.results };
}
