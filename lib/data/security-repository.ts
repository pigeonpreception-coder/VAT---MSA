import { ensureDatabase } from "@/db/runtime";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import { validateIncidentAction, validateIncidentClosure, validateIncidentCreate } from "@/lib/domain/security";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

export class SecurityResourceError extends Error {
  readonly status: number;
  constructor(message: string, status = 422) { super(message); this.name = "SecurityResourceError"; this.status = status; }
}

function validateIdempotencyKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new SecurityResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

type IdempotencyRow = { request_hash: string; resource_id: string };

async function priorCommand(db: D1Database, actorId: string, type: string, key: string, hash: string): Promise<string | null> {
  const prior = await db.prepare(`SELECT request_hash,resource_id FROM command_idempotency
    WHERE actor_id=? AND command_type=? AND idempotency_key=?`).bind(actorId, type, key).first<IdempotencyRow>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different command payload.");
  return prior.resource_id;
}

function commandRecord(db: D1Database, actorId: string, type: string, key: string, hash: string, resourceType: string, resourceId: string, now: string) {
  return db.prepare(`INSERT INTO command_idempotency
    (id,actor_id,command_type,idempotency_key,request_hash,resource_type,resource_id,created_at)
    VALUES (?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), actorId, type, key, hash, resourceType, resourceId, now);
}

async function auditRecord(db: D1Database, actor: UserContext, action: string, type: string, id: string, details: Record<string, unknown>, now: string) {
  const eventId = crypto.randomUUID();
  const prior = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const body = JSON.stringify(details);
  const hash = await sha256Hex(`${prior?.event_hash ?? "GENESIS"}|${eventId}|${actor.userId}|${body}|${now}`);
  return db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(eventId, actor.userId, actor.role, action, type, id, "SUCCESS", body, prior?.event_hash ?? null, hash, now);
}

function outbox(db: D1Database, type: string, id: string, event: string, partition: string, payload: Record<string, unknown>, now: string) {
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), type, id, event, 1, partition, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

/** Module 8 Phase B GetSOCQueue: the queue/incidents half of the existing getSecurityOperationsSnapshot, standalone and filterable. */
export async function getSOCQueue(filter: { status?: string; severity?: string }) {
  const db = await ensureDatabase();
  const conditions: string[] = [];
  const params: string[] = [];
  if (filter.status) { conditions.push("status=?"); params.push(filter.status); }
  if (filter.severity) { conditions.push("severity=?"); params.push(filter.severity); }
  const where = conditions.length ? `WHERE ${conditions.join(" AND ")}` : "";
  const rows = await db.prepare(`SELECT * FROM security_incidents ${where}
    ORDER BY CASE severity WHEN 'CRITICAL' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'MEDIUM' THEN 3 ELSE 4 END, opened_at DESC LIMIT 200`)
    .bind(...params).all<Record<string, unknown>>();
  return rows.results;
}

export async function getIncidentDetail(incidentId: string) {
  const db = await ensureDatabase();
  const incident = await db.prepare("SELECT * FROM security_incidents WHERE id=?").bind(incidentId).first<Record<string, unknown>>();
  if (!incident) throw new SecurityResourceError("Security incident was not found.", 404);
  const actions = await db.prepare("SELECT * FROM security_playbook_actions WHERE incident_id=? ORDER BY performed_at").bind(incidentId).all<Record<string, unknown>>();
  return { incident, actions: actions.results };
}

/** Module 8 Phase B CreateIncident: the manual counterpart to a rule-detected incident. */
export async function createIncident(actor: UserContext, payload: unknown, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const input = validateIncidentCreate(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ input }));
  const prior = await priorCommand(db, actor.userId, "CREATE_SECURITY_INCIDENT", idempotencyKey, requestHash);
  if (prior) return getIncidentDetail(prior);
  if (input.sourceEventId) {
    const event = await db.prepare("SELECT id FROM security_events WHERE id=?").bind(input.sourceEventId).first<{ id: string }>();
    if (!event) throw new SecurityResourceError("The referenced security event was not found.", 404);
  }
  if (input.subjectUserId) {
    const user = await db.prepare("SELECT id FROM app_users WHERE id=?").bind(input.subjectUserId).first<{ id: string }>();
    if (!user) throw new SecurityResourceError("The referenced subject user was not found.", 404);
  }
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO security_incidents
      (id,title,severity,status,source_event_id,automated_action,owner,detection_rule_id,group_key,subject_user_id,opened_at,updated_at,closed_at,closed_by,resolution_notes)
      VALUES (?,?,?,'OPEN',?,NULL,?,NULL,NULL,?,?,?,NULL,NULL,NULL)`).bind(id, input.title, input.severity, input.sourceEventId ?? null, actor.userId, input.subjectUserId ?? null, now, now),
    db.prepare(`INSERT INTO security_playbook_actions (id,incident_id,action_type,actor_id,automated,details,performed_at)
      VALUES (?,?,'OPENED',?,0,?,?)`).bind(crypto.randomUUID(), id, actor.userId, JSON.stringify({ details: input.details }), now),
    commandRecord(db, actor.userId, "CREATE_SECURITY_INCIDENT", idempotencyKey, requestHash, "SECURITY_INCIDENT", id, now),
    outbox(db, "SECURITY_INCIDENT", id, "SecurityIncidentOpened", id, { incident_id: id, severity: input.severity, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "SECURITY_INCIDENT_OPENED", "SECURITY_INCIDENT", id, { title: input.title, severity: input.severity, correlationId }, now),
  ]);
  return getIncidentDetail(id);
}

/** Module 8 Phase B Contain: a triage bookkeeping step — OPEN to CONTAINED, no technical side effect of its own (see revokeIncidentAccess for the real access-cutting action). */
export async function containIncident(incidentId: string, actor: UserContext, payload: unknown, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const input = validateIncidentAction(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ incident_id: incidentId, input }));
  const prior = await priorCommand(db, actor.userId, "CONTAIN_SECURITY_INCIDENT", idempotencyKey, requestHash);
  if (prior) return getIncidentDetail(incidentId);
  const incident = await db.prepare("SELECT id,status FROM security_incidents WHERE id=?").bind(incidentId).first<{ id: string; status: string }>();
  if (!incident) throw new SecurityResourceError("Security incident was not found.", 404);
  if (incident.status !== "OPEN") throw new RepositoryConflictError("Only an open incident can be contained.");
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE security_incidents SET status='CONTAINED',owner=COALESCE(owner,?),updated_at=? WHERE id=? AND status='OPEN'").bind(actor.userId, now, incidentId),
    db.prepare(`INSERT INTO security_playbook_actions (id,incident_id,action_type,actor_id,automated,details,performed_at)
      VALUES (?,?,'CONTAIN',?,0,?,?)`).bind(crypto.randomUUID(), incidentId, actor.userId, JSON.stringify({ notes: input.notes }), now),
    commandRecord(db, actor.userId, "CONTAIN_SECURITY_INCIDENT", idempotencyKey, requestHash, "SECURITY_INCIDENT", incidentId, now),
    outbox(db, "SECURITY_INCIDENT", incidentId, "SecurityIncidentContained", incidentId, { incident_id: incidentId, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "SECURITY_INCIDENT_CONTAINED", "SECURITY_INCIDENT", incidentId, { notes: input.notes, correlationId }, now),
  ]);
  return getIncidentDetail(incidentId);
}

/**
 * Module 8 Phase B Revoke: the real technical containment action — revokes
 * every ACTIVE identity_links row for the incident's subject_user_id
 * (Module 1's own session-revocation mechanism, reused rather than
 * duplicated). Independently callable on an OPEN or CONTAINED incident
 * (not just after Contain), and itself advances OPEN to CONTAINED if the
 * incident hadn't been triaged yet — revoking access is itself a
 * containment action.
 */
export async function revokeIncidentAccess(incidentId: string, actor: UserContext, payload: unknown, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const input = validateIncidentAction(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ incident_id: incidentId, input }));
  const prior = await priorCommand(db, actor.userId, "REVOKE_SECURITY_INCIDENT_ACCESS", idempotencyKey, requestHash);
  if (prior) return getIncidentDetail(incidentId);
  const incident = await db.prepare("SELECT id,status,subject_user_id FROM security_incidents WHERE id=?").bind(incidentId).first<{ id: string; status: string; subject_user_id: string | null }>();
  if (!incident) throw new SecurityResourceError("Security incident was not found.", 404);
  if (incident.status === "CLOSED") throw new RepositoryConflictError("A closed incident cannot have access revoked.");
  if (!incident.subject_user_id) throw new SecurityResourceError("This incident has no associated subject user to revoke access for.");
  const links = await db.prepare("SELECT id FROM identity_links WHERE user_id=? AND status='ACTIVE'").bind(incident.subject_user_id).all<{ id: string }>();
  const now = new Date().toISOString();
  await db.batch([
    ...links.results.map((link) => db.prepare("UPDATE identity_links SET status='REVOKED' WHERE id=? AND status='ACTIVE'").bind(link.id)),
    db.prepare("UPDATE security_incidents SET status=CASE WHEN status='OPEN' THEN 'CONTAINED' ELSE status END,owner=COALESCE(owner,?),updated_at=? WHERE id=?").bind(actor.userId, now, incidentId),
    db.prepare(`INSERT INTO security_playbook_actions (id,incident_id,action_type,actor_id,automated,details,performed_at)
      VALUES (?,?,'REVOKE',?,0,?,?)`).bind(crypto.randomUUID(), incidentId, actor.userId, JSON.stringify({ notes: input.notes, revokedIdentityLinks: links.results.length, subjectUserId: incident.subject_user_id }), now),
    commandRecord(db, actor.userId, "REVOKE_SECURITY_INCIDENT_ACCESS", idempotencyKey, requestHash, "SECURITY_INCIDENT", incidentId, now),
    outbox(db, "SECURITY_INCIDENT", incidentId, "SecurityIncidentAccessRevoked", incidentId, { incident_id: incidentId, subject_user_id: incident.subject_user_id, revoked_identity_links: links.results.length, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "SECURITY_INCIDENT_ACCESS_REVOKED", "SECURITY_INCIDENT", incidentId, { notes: input.notes, subjectUserId: incident.subject_user_id, revokedIdentityLinks: links.results.length, correlationId }, now),
  ]);
  return getIncidentDetail(incidentId);
}

/** Module 8 Phase B Close: terminal — reachable directly from OPEN (a false positive) or from CONTAINED. */
export async function closeIncident(incidentId: string, actor: UserContext, payload: unknown, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const input = validateIncidentClosure(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ incident_id: incidentId, input }));
  const prior = await priorCommand(db, actor.userId, "CLOSE_SECURITY_INCIDENT", idempotencyKey, requestHash);
  if (prior) return getIncidentDetail(incidentId);
  const incident = await db.prepare("SELECT id,status FROM security_incidents WHERE id=?").bind(incidentId).first<{ id: string; status: string }>();
  if (!incident) throw new SecurityResourceError("Security incident was not found.", 404);
  if (incident.status === "CLOSED") throw new RepositoryConflictError("This incident is already closed.");
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE security_incidents SET status='CLOSED',closed_by=?,closed_at=?,resolution_notes=?,updated_at=? WHERE id=?").bind(actor.userId, now, input.resolutionNotes, now, incidentId),
    db.prepare(`INSERT INTO security_playbook_actions (id,incident_id,action_type,actor_id,automated,details,performed_at)
      VALUES (?,?,'CLOSE',?,0,?,?)`).bind(crypto.randomUUID(), incidentId, actor.userId, JSON.stringify({ resolutionNotes: input.resolutionNotes }), now),
    commandRecord(db, actor.userId, "CLOSE_SECURITY_INCIDENT", idempotencyKey, requestHash, "SECURITY_INCIDENT", incidentId, now),
    outbox(db, "SECURITY_INCIDENT", incidentId, "SecurityIncidentClosed", incidentId, { incident_id: incidentId, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "SECURITY_INCIDENT_CLOSED", "SECURITY_INCIDENT", incidentId, { resolutionNotes: input.resolutionNotes, correlationId }, now),
  ]);
  return getIncidentDetail(incidentId);
}
