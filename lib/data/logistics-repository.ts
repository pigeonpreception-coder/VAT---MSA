import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import {
  assertLogisticsDeliveryTransition,
  validateLogisticsDeliveryCancellation,
  validateLogisticsDeliveryCreation,
  type LogisticsDeliveryAction,
} from "@/lib/domain/logistics";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

type PriorCommand = { request_hash: string; resource_id: string };
type OrganisationScope = { id: string };

export class LogisticsResourceError extends Error {
  readonly status: number;

  constructor(message: string, status = 422) {
    super(message);
    this.name = "LogisticsResourceError";
    this.status = status;
  }
}

function validateKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new LogisticsResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

async function resolveOrganisation(db: D1Database, actor: UserContext, requestedOrganisationId?: string | null): Promise<OrganisationScope> {
  if (isNationalScope(actor)) {
    const row = requestedOrganisationId
      ? await db.prepare("SELECT id FROM organisations WHERE id=? AND status='ACTIVE'").bind(requestedOrganisationId).first<OrganisationScope>()
      : await db.prepare("SELECT id FROM organisations WHERE status='ACTIVE' ORDER BY id LIMIT 1").first<OrganisationScope>();
    if (!row) throw new LogisticsResourceError("No active organisation is available in the requested scope.", 404);
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
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different logistics command.");
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

type DeliveryRow = { id: string; organisation_id: string; status: string };

async function loadDeliveryForActor(db: D1Database, actor: UserContext, id: string): Promise<DeliveryRow> {
  const row = await db.prepare("SELECT id,organisation_id,status FROM logistics_deliveries WHERE id=?").bind(id).first<DeliveryRow>();
  if (!row) throw new LogisticsResourceError("Logistics delivery was not found.", 404);
  if (!isNationalScope(actor)) {
    const org = await db.prepare("SELECT id FROM organisations WHERE taxpayer_id=? AND id=?").bind(actor.taxpayerId ?? "__none__", row.organisation_id).first<{ id: string }>();
    if (!org) throw new AccessDeniedError("This delivery is outside your authorised organisation scope.");
  }
  return row;
}

/** CreateDelivery: always starts PENDING — dispatch/deliver/cancel are separate commands. */
export async function createLogisticsDelivery(payload: unknown, actor: UserContext, key: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateKey(key);
  const input = validateLogisticsDeliveryCreation(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(db, actor, requestedOrganisationId);

  const hash = await sha256Hex(stableStringify(input));
  const prior = await replay(db, actor.userId, "CREATE_LOGISTICS_DELIVERY", key, hash);
  if (prior) return db.prepare("SELECT * FROM logistics_deliveries WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const existing = await db.prepare("SELECT id FROM logistics_deliveries WHERE organisation_id=? AND delivery_number=?").bind(organisation.id, input.delivery_number).first<{ id: string }>();
  if (existing) throw new RepositoryConflictError(`A delivery numbered ${input.delivery_number} already exists as ${existing.id}.`);
  if (input.vehicle_asset_id) {
    const vehicle = await db.prepare("SELECT id FROM fixed_assets WHERE id=? AND organisation_id=? AND asset_class='MOVABLE'").bind(input.vehicle_asset_id, organisation.id).first<{ id: string }>();
    if (!vehicle) throw new LogisticsResourceError("vehicle_asset_id must be a movable asset registered in the authorised organisation.");
  }

  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO logistics_deliveries
        (id,organisation_id,delivery_number,reference_type,reference_id,origin,destination,vehicle_asset_id,status,notes,dispatched_at,delivered_at,cancelled_at,cancellation_reason,created_by,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
      id, organisation.id, input.delivery_number, input.reference_type, input.reference_id ?? null, input.origin, input.destination,
      input.vehicle_asset_id ?? null, "PENDING", input.notes ?? null, null, null, null, null, actor.userId, now, now,
    ),
    commandRecord(db, actor.userId, "CREATE_LOGISTICS_DELIVERY", key, hash, "LOGISTICS_DELIVERY", id, now),
    outbox(db, "LOGISTICS_DELIVERY", id, "LogisticsDeliveryCreated", organisation.id, { delivery_number: input.delivery_number, reference_type: input.reference_type, reference_id: input.reference_id, organisation_id: organisation.id, correlation_id: correlationId }, now),
    await appendAuditEvent(db, actor, "LOGISTICS_DELIVERY_CREATED", "LOGISTICS_DELIVERY", id, { deliveryNumber: input.delivery_number, referenceType: input.reference_type, organisationId: organisation.id, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM logistics_deliveries WHERE id=?").bind(id).first<Record<string, unknown>>();
}

const LOGISTICS_ACTION_EVENT_TYPE: Record<LogisticsDeliveryAction, string> = {
  DISPATCH: "LogisticsDeliveryDispatched",
  DELIVER: "LogisticsDeliveryDelivered",
  CANCEL: "LogisticsDeliveryCancelled",
};

async function transitionLogisticsDelivery(id: string, action: LogisticsDeliveryAction, actor: UserContext, key: string, correlationId: string, extraDetails: Record<string, unknown>, columnUpdates: string, columnValues: unknown[]) {
  validateKey(key);
  const db = await ensureDatabase();
  const delivery = await loadDeliveryForActor(db, actor, id);
  const target = assertLogisticsDeliveryTransition(action, delivery.status);

  const hash = await sha256Hex(stableStringify({ delivery_id: id, action, extraDetails }));
  const command = `${action}_LOGISTICS_DELIVERY`;
  const prior = await replay(db, actor.userId, command, key, hash);
  if (prior) return db.prepare("SELECT * FROM logistics_deliveries WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`UPDATE logistics_deliveries SET status=?, updated_at=?${columnUpdates} WHERE id=?`).bind(target, now, ...columnValues, id),
    commandRecord(db, actor.userId, command, key, hash, "LOGISTICS_DELIVERY", id, now),
    outbox(db, "LOGISTICS_DELIVERY", id, LOGISTICS_ACTION_EVENT_TYPE[action], delivery.organisation_id, { logistics_delivery_id: id, from_status: delivery.status, to_status: target, correlation_id: correlationId, ...extraDetails }, now),
    await appendAuditEvent(db, actor, `LOGISTICS_DELIVERY_${action}ED`, "LOGISTICS_DELIVERY", id, { fromStatus: delivery.status, toStatus: target, correlationId, ...extraDetails }, now),
  ]);
  return db.prepare("SELECT * FROM logistics_deliveries WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/** DispatchDelivery: PENDING -> IN_TRANSIT. */
export function dispatchLogisticsDelivery(id: string, actor: UserContext, key: string, correlationId: string) {
  const now = new Date().toISOString();
  return transitionLogisticsDelivery(id, "DISPATCH", actor, key, correlationId, {}, ", dispatched_at=?", [now]);
}

/** DeliverDelivery: IN_TRANSIT -> DELIVERED. */
export function deliverLogisticsDelivery(id: string, actor: UserContext, key: string, correlationId: string) {
  const now = new Date().toISOString();
  return transitionLogisticsDelivery(id, "DELIVER", actor, key, correlationId, {}, ", delivered_at=?", [now]);
}

/** CancelDelivery: PENDING or IN_TRANSIT -> CANCELLED. */
export async function cancelLogisticsDelivery(id: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  const input = validateLogisticsDeliveryCancellation(payload);
  const now = new Date().toISOString();
  return transitionLogisticsDelivery(id, "CANCEL", actor, key, correlationId, { reason: input.reason }, ", cancelled_at=?, cancellation_reason=?", [now, input.reason]);
}

/** GetLogisticsDelivery. */
export async function getLogisticsDelivery(id: string, actor: UserContext) {
  const db = await ensureDatabase();
  const delivery = await loadDeliveryForActor(db, actor, id);
  return db.prepare("SELECT * FROM logistics_deliveries WHERE id=?").bind(delivery.id).first<Record<string, unknown>>();
}

/** ListLogisticsDeliveries. */
export async function listLogisticsDeliveries(actor: UserContext, requestedOrganisationId?: string | null) {
  const db = await ensureDatabase();
  if (isNationalScope(actor)) {
    const result = await db.prepare("SELECT * FROM logistics_deliveries ORDER BY created_at DESC").all<Record<string, unknown>>();
    return result.results;
  }
  const organisation = await resolveOrganisation(db, actor, requestedOrganisationId);
  const result = await db.prepare("SELECT * FROM logistics_deliveries WHERE organisation_id=? ORDER BY created_at DESC").bind(organisation.id).all<Record<string, unknown>>();
  return result.results;
}
