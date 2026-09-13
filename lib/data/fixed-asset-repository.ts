import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import {
  assertFixedAssetTransition,
  validateFixedAssetDisposal,
  validateFixedAssetRegistration,
  validateFixedAssetValuation,
  type FixedAssetAction,
} from "@/lib/domain/fixed-asset";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

type PriorCommand = { request_hash: string; resource_id: string };
type OrganisationScope = { id: string };

export class FixedAssetResourceError extends Error {
  readonly status: number;

  constructor(message: string, status = 422) {
    super(message);
    this.name = "FixedAssetResourceError";
    this.status = status;
  }
}

function validateKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new FixedAssetResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

async function resolveOrganisation(db: D1Database, actor: UserContext, requestedOrganisationId?: string | null): Promise<OrganisationScope> {
  if (isNationalScope(actor)) {
    const row = requestedOrganisationId
      ? await db.prepare("SELECT id FROM organisations WHERE id=? AND status='ACTIVE'").bind(requestedOrganisationId).first<OrganisationScope>()
      : await db.prepare("SELECT id FROM organisations WHERE status='ACTIVE' ORDER BY id LIMIT 1").first<OrganisationScope>();
    if (!row) throw new FixedAssetResourceError("No active organisation is available in the requested scope.", 404);
    return row;
  }
  const row = await db.prepare("SELECT id FROM organisations WHERE taxpayer_id=? AND status='ACTIVE' LIMIT 1").bind(actor.taxpayerId ?? "__none__").first<OrganisationScope>();
  if (!row) throw new AccessDeniedError("Your account is not assigned to an active taxpayer organisation.");
  if (requestedOrganisationId && requestedOrganisationId !== row.id) throw new AccessDeniedError("The requested organisation is outside your authorised scope.");
  return row;
}

async function replay(db: D1Database, actorId: string, command: string, key: string, hash: string) {
  const prior = await db.prepare("SELECT request_hash,resource_id FROM command_idempotency WHERE actor_id=? AND command_type=? AND idempotency_key=?").bind(actorId, command, key).first<PriorCommand>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different fixed asset command.");
  return prior.resource_id;
}

function commandRecord(db: D1Database, actorId: string, command: string, key: string, hash: string, resourceType: string, resourceId: string, now: string) {
  return db.prepare("INSERT INTO command_idempotency VALUES (?,?,?,?,?,?,?,?)").bind(crypto.randomUUID(), actorId, command, key, hash, resourceType, resourceId, now);
}

function outbox(db: D1Database, aggregateType: string, aggregateId: string, eventType: string, partitionKey: string, payload: Record<string, unknown>, now: string) {
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), aggregateType, aggregateId, eventType, 1, partitionKey, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

type AssetRow = { id: string; organisation_id: string; status: string };

async function loadAssetForActor(db: D1Database, actor: UserContext, id: string): Promise<AssetRow> {
  const row = await db.prepare("SELECT id,organisation_id,status FROM fixed_assets WHERE id=?").bind(id).first<AssetRow>();
  if (!row) throw new FixedAssetResourceError("Fixed asset was not found.", 404);
  if (!isNationalScope(actor)) {
    const org = await db.prepare("SELECT id FROM organisations WHERE taxpayer_id=? AND id=?").bind(actor.taxpayerId ?? "__none__", row.organisation_id).first<{ id: string }>();
    if (!org) throw new AccessDeniedError("This asset is outside your authorised organisation scope.");
  }
  return row;
}

/** RegisterFixedAsset: serves both the Immovable and Movable Asset Management pages — they share this one table/lifecycle and differ only by asset_class. */
export async function registerFixedAsset(payload: unknown, actor: UserContext, key: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateKey(key);
  const input = validateFixedAssetRegistration(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(db, actor, requestedOrganisationId);

  const hash = await sha256Hex(stableStringify(input));
  const prior = await replay(db, actor.userId, "REGISTER_FIXED_ASSET", key, hash);
  if (prior) return db.prepare("SELECT * FROM fixed_assets WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const existing = await db.prepare("SELECT id FROM fixed_assets WHERE organisation_id=? AND asset_code=?").bind(organisation.id, input.asset_code).first<{ id: string }>();
  if (existing) throw new RepositoryConflictError(`An asset with code ${input.asset_code} already exists as ${existing.id}.`);
  if (input.custodian_employee_id) {
    const employee = await db.prepare("SELECT id FROM employees WHERE id=? AND organisation_id=?").bind(input.custodian_employee_id, organisation.id).first<{ id: string }>();
    if (!employee) throw new FixedAssetResourceError("custodian_employee_id does not exist in the authorised organisation.");
  }

  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO fixed_assets
        (id,organisation_id,asset_class,asset_code,category,description,serial_or_registration_number,location_or_address,custodian_employee_id,acquisition_date,acquisition_cost_cents,current_value_cents,status,disposal_reason,disposed_at,created_by,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
      id, organisation.id, input.asset_class, input.asset_code, input.category, input.description,
      input.serial_or_registration_number ?? null, input.location_or_address, input.custodian_employee_id ?? null,
      input.acquisition_date, input.acquisition_cost_cents, input.current_value_cents, "ACTIVE", null, null, actor.userId, now, now,
    ),
    commandRecord(db, actor.userId, "REGISTER_FIXED_ASSET", key, hash, "FIXED_ASSET", id, now),
    outbox(db, "FIXED_ASSET", id, "FixedAssetRegistered", organisation.id, { asset_class: input.asset_class, asset_code: input.asset_code, organisation_id: organisation.id, correlation_id: correlationId }, now),
    await appendAuditEvent(db, actor, "FIXED_ASSET_REGISTERED", "FIXED_ASSET", id, { assetClass: input.asset_class, assetCode: input.asset_code, organisationId: organisation.id, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM fixed_assets WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/** RecordFixedAssetValuation: updates current_value_cents without touching the lifecycle status. */
export async function recordFixedAssetValuation(id: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateFixedAssetValuation(payload);
  const db = await ensureDatabase();
  const asset = await loadAssetForActor(db, actor, id);
  if (asset.status === "DISPOSED") throw new RepositoryConflictError("A disposed asset can no longer be revalued.");

  const hash = await sha256Hex(stableStringify({ asset_id: id, input }));
  const prior = await replay(db, actor.userId, "RECORD_FIXED_ASSET_VALUATION", key, hash);
  if (prior) return db.prepare("SELECT * FROM fixed_assets WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE fixed_assets SET current_value_cents=?, updated_at=? WHERE id=?").bind(input.current_value_cents, now, id),
    commandRecord(db, actor.userId, "RECORD_FIXED_ASSET_VALUATION", key, hash, "FIXED_ASSET", id, now),
    outbox(db, "FIXED_ASSET", id, "FixedAssetValuationRecorded", asset.organisation_id, { fixed_asset_id: id, current_value_cents: input.current_value_cents, correlation_id: correlationId }, now),
    await appendAuditEvent(db, actor, "FIXED_ASSET_VALUATION_RECORDED", "FIXED_ASSET", id, { currentValueCents: input.current_value_cents, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM fixed_assets WHERE id=?").bind(id).first<Record<string, unknown>>();
}

const FIXED_ASSET_ACTION_EVENT_TYPE: Record<FixedAssetAction, string> = {
  FLAG_MAINTENANCE: "FixedAssetFlaggedForMaintenance",
  RESTORE: "FixedAssetRestored",
  DISPOSE: "FixedAssetDisposed",
};

async function transitionFixedAsset(id: string, action: FixedAssetAction, actor: UserContext, key: string, correlationId: string, extraDetails: Record<string, unknown>, columnUpdates: string, columnValues: unknown[]) {
  validateKey(key);
  const db = await ensureDatabase();
  const asset = await loadAssetForActor(db, actor, id);
  const target = assertFixedAssetTransition(action, asset.status);

  const hash = await sha256Hex(stableStringify({ asset_id: id, action, extraDetails }));
  const command = `${action}_FIXED_ASSET`;
  const prior = await replay(db, actor.userId, command, key, hash);
  if (prior) return db.prepare("SELECT * FROM fixed_assets WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`UPDATE fixed_assets SET status=?, updated_at=?${columnUpdates} WHERE id=?`).bind(target, now, ...columnValues, id),
    commandRecord(db, actor.userId, command, key, hash, "FIXED_ASSET", id, now),
    outbox(db, "FIXED_ASSET", id, FIXED_ASSET_ACTION_EVENT_TYPE[action], asset.organisation_id, { fixed_asset_id: id, from_status: asset.status, to_status: target, correlation_id: correlationId, ...extraDetails }, now),
    await appendAuditEvent(db, actor, `FIXED_ASSET_${action}D`, "FIXED_ASSET", id, { fromStatus: asset.status, toStatus: target, correlationId, ...extraDetails }, now),
  ]);
  return db.prepare("SELECT * FROM fixed_assets WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/** FlagFixedAssetMaintenance: ACTIVE -> UNDER_MAINTENANCE. */
export function flagFixedAssetMaintenance(id: string, actor: UserContext, key: string, correlationId: string) {
  return transitionFixedAsset(id, "FLAG_MAINTENANCE", actor, key, correlationId, {}, "", []);
}

/** RestoreFixedAsset: UNDER_MAINTENANCE -> ACTIVE. */
export function restoreFixedAsset(id: string, actor: UserContext, key: string, correlationId: string) {
  return transitionFixedAsset(id, "RESTORE", actor, key, correlationId, {}, "", []);
}

/** DisposeFixedAsset: ACTIVE or UNDER_MAINTENANCE -> DISPOSED. */
export async function disposeFixedAsset(id: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  const input = validateFixedAssetDisposal(payload);
  const now = new Date().toISOString();
  return transitionFixedAsset(id, "DISPOSE", actor, key, correlationId, { reason: input.reason }, ", disposal_reason=?, disposed_at=?", [input.reason, now]);
}

/** GetFixedAsset. */
export async function getFixedAsset(id: string, actor: UserContext) {
  const db = await ensureDatabase();
  const asset = await loadAssetForActor(db, actor, id);
  return db.prepare("SELECT * FROM fixed_assets WHERE id=?").bind(asset.id).first<Record<string, unknown>>();
}

/** ListFixedAssets: optionally filtered by asset_class (IMMOVABLE or MOVABLE) so the two Operations pages can each show only their own kind. */
export async function listFixedAssets(actor: UserContext, assetClass?: string | null, requestedOrganisationId?: string | null) {
  const db = await ensureDatabase();
  if (isNationalScope(actor)) {
    const result = assetClass
      ? await db.prepare("SELECT * FROM fixed_assets WHERE asset_class=? ORDER BY created_at DESC").bind(assetClass).all<Record<string, unknown>>()
      : await db.prepare("SELECT * FROM fixed_assets ORDER BY created_at DESC").all<Record<string, unknown>>();
    return result.results;
  }
  const organisation = await resolveOrganisation(db, actor, requestedOrganisationId);
  const result = assetClass
    ? await db.prepare("SELECT * FROM fixed_assets WHERE organisation_id=? AND asset_class=? ORDER BY created_at DESC").bind(organisation.id, assetClass).all<Record<string, unknown>>()
    : await db.prepare("SELECT * FROM fixed_assets WHERE organisation_id=? ORDER BY created_at DESC").bind(organisation.id).all<Record<string, unknown>>();
  return result.results;
}
