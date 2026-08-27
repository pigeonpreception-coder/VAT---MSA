import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import {
  calculateReturnPosition,
  normalizeAndValidateAdjustment,
  validateDecisionComment,
  type VatAdjustmentSubmission,
} from "@/lib/domain/vat-lifecycle";
import type { UserContext } from "@/lib/domain/types";
import { getItasIdentityPort, ItasIntegrationUnavailableError } from "@/lib/integrations/itas";
import { RepositoryConflictError } from "./repository";

type PeriodContext = {
  id: string;
  organisation_id: string;
  taxpayer_id: string;
  period_code: string;
  period_start: string;
  period_end: string;
  due_date: string;
  status: string;
  lock_version: number;
  legal_name: string;
  vat_number: string;
};

type ReturnVersionContext = {
  id: string;
  vat_period_id: string;
  organisation_id: string;
  taxpayer_id: string;
  version_number: number;
  status: string;
  ledger_snapshot_hash: string;
  tax_rule_set_id: string;
  period_code: string;
  vat_number: string;
};

type PriorCommand = { request_hash: string; resource_id: string };

export class VatLifecycleResourceError extends Error {
  readonly status: number;

  constructor(message: string, status = 422) {
    super(message);
    this.name = "VatLifecycleResourceError";
    this.status = status;
  }
}

function validateIdempotencyKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new VatLifecycleResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

async function commandReplay(db: D1Database, actorId: string, command: string, key: string, hash: string) {
  const prior = await db.prepare(`SELECT request_hash,resource_id FROM command_idempotency
    WHERE actor_id=? AND command_type=? AND idempotency_key=?`).bind(actorId, command, key).first<PriorCommand>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different VAT lifecycle command.");
  return prior.resource_id;
}

function commandRecord(db: D1Database, actorId: string, command: string, key: string, hash: string, resourceType: string, resourceId: string, now: string) {
  return db.prepare(`INSERT INTO command_idempotency
    (id,actor_id,command_type,idempotency_key,request_hash,resource_type,resource_id,created_at)
    VALUES (?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), actorId, command, key, hash, resourceType, resourceId, now);
}

/** Module 8 Phase D: delegates to the single shared hash-chain writer — see lib/data/audit-repository.ts's appendAuditEvent. */
async function auditEnvelope(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>, now: string) {
  return appendAuditEvent(db, actor, action, resourceType, resourceId, details, now);
}

function outbox(db: D1Database, aggregateType: string, aggregateId: string, eventType: string, partitionKey: string, payload: Record<string, unknown>, now: string) {
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), aggregateType, aggregateId, eventType, 1, partitionKey, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

async function getPeriodForActor(db: D1Database, periodId: string, actor: UserContext): Promise<PeriodContext> {
  const period = await db.prepare(`SELECT p.*,t.legal_name,t.vat_number FROM vat_periods p
    JOIN taxpayers t ON t.id=p.taxpayer_id WHERE p.id=?`).bind(periodId).first<PeriodContext>();
  if (!period) throw new VatLifecycleResourceError("VAT period was not found.", 404);
  if (!isNationalScope(actor) && actor.taxpayerId !== period.taxpayer_id) throw new AccessDeniedError("The VAT period is outside your authorised taxpayer scope.");
  return period;
}

async function getVersionForActor(db: D1Database, versionId: string, actor: UserContext): Promise<ReturnVersionContext> {
  const version = await db.prepare(`SELECT v.*,p.period_code,t.vat_number FROM vat_return_versions v
    JOIN vat_periods p ON p.id=v.vat_period_id JOIN taxpayers t ON t.id=v.taxpayer_id WHERE v.id=?`).bind(versionId).first<ReturnVersionContext>();
  if (!version) throw new VatLifecycleResourceError("VAT return version was not found.", 404);
  if (!isNationalScope(actor) && actor.taxpayerId !== version.taxpayer_id) throw new AccessDeniedError("The VAT return is outside your authorised taxpayer scope.");
  return version;
}

export async function getVatLifecycleSnapshot(actor: UserContext) {
  const db = await ensureDatabase();
  const scoped = !isNationalScope(actor);
  const taxpayerId = actor.taxpayerId ?? "__none__";
  const periodWhere = scoped ? "WHERE p.taxpayer_id=?" : "";
  const periodStatement = db.prepare(`SELECT p.*,t.legal_name,t.vat_number,
    (SELECT COUNT(*) FROM reconciliation_matches m WHERE m.vat_period_id=p.id AND m.status='MATCHED') AS matched_count,
    (SELECT COUNT(*) FROM reconciliation_matches m WHERE m.vat_period_id=p.id AND m.status<>'MATCHED') AS unmatched_count,
    (SELECT COUNT(*) FROM vat_adjustments a WHERE a.vat_period_id=p.id AND a.status='PENDING_APPROVAL') AS pending_adjustments,
    (SELECT v.id FROM vat_return_versions v WHERE v.vat_period_id=p.id ORDER BY v.version_number DESC LIMIT 1) AS latest_return_id,
    (SELECT v.version_number FROM vat_return_versions v WHERE v.vat_period_id=p.id ORDER BY v.version_number DESC LIMIT 1) AS latest_version,
    (SELECT v.status FROM vat_return_versions v WHERE v.vat_period_id=p.id ORDER BY v.version_number DESC LIMIT 1) AS return_status,
    (SELECT v.output_tax_cents FROM vat_return_versions v WHERE v.vat_period_id=p.id ORDER BY v.version_number DESC LIMIT 1) AS output_tax_cents,
    (SELECT v.input_tax_cents FROM vat_return_versions v WHERE v.vat_period_id=p.id ORDER BY v.version_number DESC LIMIT 1) AS input_tax_cents,
    (SELECT v.net_payable_cents FROM vat_return_versions v WHERE v.vat_period_id=p.id ORDER BY v.version_number DESC LIMIT 1) AS net_payable_cents
    FROM vat_periods p JOIN taxpayers t ON t.id=p.taxpayer_id ${periodWhere}
    ORDER BY p.period_end DESC,t.legal_name`);
  const [periods, approvals, submissions, rules, reconciliation] = await Promise.all([
    scoped ? periodStatement.bind(taxpayerId).all<Record<string, string | number | null>>() : periodStatement.all<Record<string, string | number | null>>(),
    scoped
      ? db.prepare(`SELECT a.* FROM approval_tasks a WHERE a.taxpayer_id=? AND a.domain='VAT_RETURN' ORDER BY a.requested_at DESC LIMIT 100`).bind(taxpayerId).all<Record<string, string | null>>()
      : db.prepare(`SELECT a.*,t.legal_name FROM approval_tasks a JOIN taxpayers t ON t.id=a.taxpayer_id WHERE a.domain='VAT_RETURN' ORDER BY a.requested_at DESC LIMIT 100`).all<Record<string, string | null>>(),
    scoped
      ? db.prepare(`SELECT s.*,v.version_number,p.period_code FROM vat_return_submissions s JOIN vat_return_versions v ON v.id=s.vat_return_version_id JOIN vat_periods p ON p.id=v.vat_period_id WHERE v.taxpayer_id=? ORDER BY s.requested_at DESC LIMIT 100`).bind(taxpayerId).all<Record<string, string | number | null>>()
      : db.prepare(`SELECT s.*,v.version_number,p.period_code,t.legal_name FROM vat_return_submissions s JOIN vat_return_versions v ON v.id=s.vat_return_version_id JOIN vat_periods p ON p.id=v.vat_period_id JOIN taxpayers t ON t.id=v.taxpayer_id ORDER BY s.requested_at DESC LIMIT 100`).all<Record<string, string | number | null>>(),
    db.prepare("SELECT * FROM tax_rule_sets ORDER BY effective_from DESC").all<Record<string, string | number | null>>(),
    scoped
      ? db.prepare(`SELECT m.*,i.invoice_number,i.status AS invoice_status FROM reconciliation_matches m JOIN invoices i ON i.id=m.invoice_id WHERE m.taxpayer_id=? ORDER BY m.created_at DESC LIMIT 100`).bind(taxpayerId).all<Record<string, string | number | null>>()
      : db.prepare(`SELECT m.*,i.invoice_number,i.status AS invoice_status,t.legal_name FROM reconciliation_matches m JOIN invoices i ON i.id=m.invoice_id JOIN taxpayers t ON t.id=m.taxpayer_id ORDER BY m.created_at DESC LIMIT 100`).all<Record<string, string | number | null>>(),
  ]);
  const provider = await getItasIdentityPort(db).status();
  return { periods: periods.results, approvals: approvals.results, submissions: submissions.results, rules: rules.results, reconciliation: reconciliation.results, provider };
}

export async function getVatReturnDetail(versionId: string, actor: UserContext) {
  const db = await ensureDatabase();
  const version = await getVersionForActor(db, versionId, actor);
  const [boxes, adjustments, approvals, submissions] = await Promise.all([
    db.prepare("SELECT * FROM vat_return_boxes WHERE vat_return_version_id=? ORDER BY box_code").bind(versionId).all<Record<string, string | number>>(),
    db.prepare("SELECT * FROM vat_adjustments WHERE vat_period_id=? ORDER BY created_at").bind(version.vat_period_id).all<Record<string, string | number | null>>(),
    db.prepare("SELECT * FROM approval_tasks WHERE resource_type='VAT_RETURN_VERSION' AND resource_id=? ORDER BY requested_at").bind(versionId).all<Record<string, string | null>>(),
    db.prepare("SELECT * FROM vat_return_submissions WHERE vat_return_version_id=? ORDER BY requested_at").bind(versionId).all<Record<string, string | number | null>>(),
  ]);
  return { version, boxes: boxes.results, adjustments: adjustments.results, approvals: approvals.results, submissions: submissions.results };
}

export async function createVatAdjustment(periodId: string, payload: VatAdjustmentSubmission, actor: UserContext, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const adjustment = normalizeAndValidateAdjustment(payload);
  const db = await ensureDatabase();
  const period = await getPeriodForActor(db, periodId, actor);
  if (period.status !== "OPEN") throw new RepositoryConflictError(`Adjustments require an open VAT period; current status is ${period.status}.`);
  if (adjustment.evidence_document_id) {
    const evidence = await db.prepare("SELECT id FROM document_metadata WHERE id=? AND organisation_id=? AND status='AVAILABLE'").bind(adjustment.evidence_document_id, period.organisation_id).first<{ id: string }>();
    if (!evidence) throw new VatLifecycleResourceError("Evidence document is not available in the authorised organisation.");
  }
  const hash = await sha256Hex(stableStringify({ period_id: period.id, adjustment }));
  const replay = await commandReplay(db, actor.userId, "CREATE_VAT_ADJUSTMENT", idempotencyKey, hash);
  if (replay) return db.prepare("SELECT * FROM vat_adjustments WHERE id=?").bind(replay).first<Record<string, unknown>>();
  const id = crypto.randomUUID();
  const taskId = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO vat_adjustments
      (id,vat_period_id,organisation_id,taxpayer_id,adjustment_type,direction,amount_cents,reason_code,explanation,evidence_document_id,status,created_by,approved_by,created_at,approved_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,'PENDING_APPROVAL',?,NULL,?,NULL)`).bind(id, period.id, period.organisation_id, period.taxpayer_id, adjustment.adjustment_type, adjustment.direction, adjustment.amount_cents, adjustment.reason_code, adjustment.explanation, adjustment.evidence_document_id ?? null, actor.userId, now),
    db.prepare(`INSERT INTO approval_tasks
      (id,organisation_id,taxpayer_id,domain,resource_type,resource_id,requested_action,risk_tier,status,requested_by,assigned_role,decided_by,requested_at,decided_at,decision_comment)
      VALUES (?,?,?,'VAT_RETURN','VAT_ADJUSTMENT',?,'APPROVE_ADJUSTMENT','HIGH','PENDING',?,'TAXPAYER_OWNER',NULL,?,NULL,NULL)`).bind(taskId, period.organisation_id, period.taxpayer_id, id, actor.userId, now),
    commandRecord(db, actor.userId, "CREATE_VAT_ADJUSTMENT", idempotencyKey, hash, "VAT_ADJUSTMENT", id, now),
    outbox(db, "VAT_ADJUSTMENT", id, "VatAdjustmentApprovalRequested", period.taxpayer_id, { adjustment_id: id, period_id: period.id, task_id: taskId, correlation_id: correlationId }, now),
    await auditEnvelope(db, actor, "VAT_ADJUSTMENT_SUBMITTED", "VAT_ADJUSTMENT", id, { periodId: period.id, taskId, amountCents: adjustment.amount_cents, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM vat_adjustments WHERE id=?").bind(id).first<Record<string, unknown>>();
}

export async function generateVatReturn(periodId: string, actor: UserContext, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const db = await ensureDatabase();
  const period = await getPeriodForActor(db, periodId, actor);
  if (period.status !== "OPEN") throw new RepositoryConflictError(`Return generation requires an open VAT period; current status is ${period.status}.`);
  const blocking = await db.prepare("SELECT id,status FROM vat_return_versions WHERE vat_period_id=? AND status IN ('PENDING_APPROVAL','APPROVED','AWAITING_PROVIDER','FILED') LIMIT 1").bind(period.id).first<{ id: string; status: string }>();
  if (blocking) throw new RepositoryConflictError(`Return version ${blocking.id} is already in controlled status ${blocking.status}.`);
  const rule = await db.prepare(`SELECT * FROM tax_rule_sets WHERE jurisdiction='NA' AND status IN ('PILOT_CONTROLLED','AUTHORITY_APPROVED')
    AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC LIMIT 1`).bind(period.period_end, period.period_start).first<{ id: string; version: string; status: string }>();
  if (!rule) throw new VatLifecycleResourceError("No controlled tax rule set covers this VAT period.");
  const [output, input, adjustments, priorVersion] = await Promise.all([
    db.prepare(`SELECT l.id,CASE WHEN l.direction='CREDIT' THEN l.amount_cents ELSE -l.amount_cents END AS amount_cents FROM ledger_entries l JOIN invoices i ON i.id=l.invoice_id JOIN certificates c ON c.invoice_id=i.id AND c.status='VALID'
      WHERE l.taxpayer_id=? AND l.period=? AND l.entry_type='OUTPUT_VAT' AND i.status IN ('CERTIFIED','MATCHED','EXCEPTION') ORDER BY l.id`).bind(period.taxpayer_id, period.period_code).all<{ id: string; amount_cents: number }>(),
    db.prepare(`SELECT l.id,CASE WHEN l.direction='DEBIT' THEN l.amount_cents ELSE -l.amount_cents END AS amount_cents FROM ledger_entries l JOIN invoices i ON i.id=l.invoice_id JOIN certificates c ON c.invoice_id=i.id AND c.status='VALID'
      WHERE l.taxpayer_id=? AND l.period=? AND l.entry_type='INPUT_VAT' AND i.status='MATCHED' ORDER BY l.id`).bind(period.taxpayer_id, period.period_code).all<{ id: string; amount_cents: number }>(),
    db.prepare(`SELECT id,adjustment_type,direction,amount_cents FROM vat_adjustments
      WHERE vat_period_id=? AND status='APPROVED' ORDER BY id`).bind(period.id).all<{ id: string; adjustment_type: string; direction: string; amount_cents: number }>(),
    db.prepare("SELECT id,version_number FROM vat_return_versions WHERE vat_period_id=? ORDER BY version_number DESC LIMIT 1").bind(period.id).first<{ id: string; version_number: number }>(),
  ]);
  const position = calculateReturnPosition({ outputEntries: output.results, inputEntries: input.results, adjustments: adjustments.results });
  const snapshot = { period: period.period_code, taxRule: rule.version, output: output.results, input: input.results, adjustments: adjustments.results, position };
  const snapshotHash = await sha256Hex(stableStringify(snapshot));
  const requestHash = await sha256Hex(stableStringify({ period_id: period.id, snapshot_hash: snapshotHash }));
  const replay = await commandReplay(db, actor.userId, "GENERATE_VAT_RETURN", idempotencyKey, requestHash);
  if (replay) return db.prepare("SELECT * FROM vat_return_versions WHERE id=?").bind(replay).first<Record<string, unknown>>();
  const id = crypto.randomUUID();
  const versionNumber = (priorVersion?.version_number ?? 0) + 1;
  const now = new Date().toISOString();
  const statements: D1PreparedStatement[] = [];
  if (priorVersion) statements.push(db.prepare("UPDATE vat_return_versions SET status='SUPERSEDED',superseded_at=? WHERE id=? AND status IN ('DRAFT','REJECTED')").bind(now, priorVersion.id));
  statements.push(db.prepare(`INSERT INTO vat_return_versions
    (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,'DRAFT',?,?,?,NULL,NULL,NULL)`).bind(id, period.id, period.organisation_id, period.taxpayer_id, versionNumber, priorVersion?.id ?? null, rule.id, position.outputTaxCents, position.inputTaxCents, position.adjustmentCents, position.netPayableCents, snapshotHash, actor.userId, now));
  const boxes = [
    { code: "BOX_OUTPUT", label: "Output VAT", amount: position.outputTaxCents, count: position.outputSourceCount, trace: { entry_type: "OUTPUT_VAT", status: ["CERTIFIED", "MATCHED", "EXCEPTION"] } },
    { code: "BOX_INPUT", label: "Eligible input VAT", amount: position.inputTaxCents, count: position.inputSourceCount, trace: { entry_type: "INPUT_VAT", invoice_status: "MATCHED" } },
    { code: "BOX_ADJUST", label: "Approved net adjustments", amount: position.adjustmentCents, count: position.adjustmentSourceCount, trace: { adjustment_status: "APPROVED" } },
    { code: "BOX_NET", label: "Net VAT payable or refundable", amount: position.netPayableCents, count: output.results.length + input.results.length + adjustments.results.length, trace: { formula: "OUTPUT - INPUT + NET_ADJUSTMENTS" } },
  ];
  for (const box of boxes) statements.push(db.prepare(`INSERT INTO vat_return_boxes
    (id,vat_return_version_id,box_code,label,amount_cents,source_count,calculation_trace) VALUES (?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), id, box.code, box.label, box.amount, box.count, JSON.stringify(box.trace)));
  statements.push(commandRecord(db, actor.userId, "GENERATE_VAT_RETURN", idempotencyKey, requestHash, "VAT_RETURN_VERSION", id, now));
  statements.push(outbox(db, "VAT_RETURN_VERSION", id, "VatReturnDrafted", period.taxpayer_id, { return_version_id: id, period_id: period.id, version: versionNumber, snapshot_hash: snapshotHash, correlation_id: correlationId }, now));
  statements.push(await auditEnvelope(db, actor, "VAT_RETURN_GENERATED", "VAT_RETURN_VERSION", id, { periodId: period.id, versionNumber, snapshotHash, correlationId }, now));
  await db.batch(statements);
  return db.prepare("SELECT * FROM vat_return_versions WHERE id=?").bind(id).first<Record<string, unknown>>();
}

export async function requestReturnApproval(versionId: string, actor: UserContext, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const db = await ensureDatabase();
  const version = await getVersionForActor(db, versionId, actor);
  if (version.status !== "DRAFT") throw new RepositoryConflictError(`Only a draft return can enter approval; current status is ${version.status}.`);
  const requestHash = await sha256Hex(stableStringify({ version_id: version.id, snapshot_hash: version.ledger_snapshot_hash, action: "REQUEST_APPROVAL" }));
  const replay = await commandReplay(db, actor.userId, "REQUEST_RETURN_APPROVAL", idempotencyKey, requestHash);
  if (replay) return db.prepare("SELECT * FROM approval_tasks WHERE id=?").bind(replay).first<Record<string, unknown>>();
  const taskId = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE vat_return_versions SET status='PENDING_APPROVAL' WHERE id=? AND status='DRAFT'").bind(version.id),
    db.prepare(`INSERT INTO approval_tasks
      (id,organisation_id,taxpayer_id,domain,resource_type,resource_id,requested_action,risk_tier,status,requested_by,assigned_role,decided_by,requested_at,decided_at,decision_comment)
      VALUES (?,?,?,'VAT_RETURN','VAT_RETURN_VERSION',?,'APPROVE_RETURN','CRITICAL','PENDING',?,'TAXPAYER_OWNER',NULL,?,NULL,NULL)`).bind(taskId, version.organisation_id, version.taxpayer_id, version.id, actor.userId, now),
    commandRecord(db, actor.userId, "REQUEST_RETURN_APPROVAL", idempotencyKey, requestHash, "APPROVAL_TASK", taskId, now),
    outbox(db, "VAT_RETURN_VERSION", version.id, "VatReturnApprovalRequested", version.taxpayer_id, { return_version_id: version.id, task_id: taskId, correlation_id: correlationId }, now),
    await auditEnvelope(db, actor, "VAT_RETURN_APPROVAL_REQUESTED", "VAT_RETURN_VERSION", version.id, { taskId, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM approval_tasks WHERE id=?").bind(taskId).first<Record<string, unknown>>();
}

export async function decideVatApproval(taskId: string, decisionInput: unknown, actor: UserContext, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const input = decisionInput && typeof decisionInput === "object" ? decisionInput as Record<string, unknown> : {};
  const decision = typeof input.decision === "string" ? input.decision.trim().toUpperCase() : "";
  if (!new Set(["APPROVE", "REJECT"]).has(decision)) throw new VatLifecycleResourceError("Decision must be APPROVE or REJECT.");
  const comment = validateDecisionComment(input.comment);
  const db = await ensureDatabase();
  const task = await db.prepare("SELECT * FROM approval_tasks WHERE id=? AND domain='VAT_RETURN'").bind(taskId).first<{
    id: string; organisation_id: string; taxpayer_id: string; resource_type: string; resource_id: string; status: string; requested_by: string;
  }>();
  if (!task) throw new VatLifecycleResourceError("Approval task was not found.", 404);
  if (!isNationalScope(actor) && actor.taxpayerId !== task.taxpayer_id) throw new AccessDeniedError("The approval task is outside your authorised taxpayer scope.");
  if (task.status !== "PENDING") throw new RepositoryConflictError(`Approval task is already ${task.status}.`);
  if (task.requested_by === actor.userId) throw new AccessDeniedError("Maker-checker separation prevents approving or rejecting your own request.");
  const requestHash = await sha256Hex(stableStringify({ task_id: task.id, decision, comment }));
  const replay = await commandReplay(db, actor.userId, "DECIDE_VAT_APPROVAL", idempotencyKey, requestHash);
  if (replay) return db.prepare("SELECT * FROM approval_tasks WHERE id=?").bind(replay).first<Record<string, unknown>>();
  const now = new Date().toISOString();
  const nextTaskStatus = decision === "APPROVE" ? "APPROVED" : "REJECTED";
  const statements: D1PreparedStatement[] = [
    db.prepare("UPDATE approval_tasks SET status=?,decided_by=?,decided_at=?,decision_comment=? WHERE id=? AND status='PENDING'").bind(nextTaskStatus, actor.userId, now, comment, task.id),
  ];
  if (task.resource_type === "VAT_RETURN_VERSION") {
    const version = await getVersionForActor(db, task.resource_id, actor);
    if (version.status !== "PENDING_APPROVAL") throw new RepositoryConflictError(`Return approval state is ${version.status}, not PENDING_APPROVAL.`);
    statements.push(db.prepare("UPDATE vat_return_versions SET status=?,approved_by=?,approved_at=? WHERE id=? AND status='PENDING_APPROVAL'").bind(decision === "APPROVE" ? "APPROVED" : "REJECTED", decision === "APPROVE" ? actor.userId : null, decision === "APPROVE" ? now : null, version.id));
    statements.push(db.prepare("UPDATE vat_periods SET status=?,lock_version=lock_version+1,updated_at=? WHERE id=?").bind(decision === "APPROVE" ? "LOCKED" : "OPEN", now, version.vat_period_id));
  } else if (task.resource_type === "VAT_ADJUSTMENT") {
    statements.push(db.prepare("UPDATE vat_adjustments SET status=?,approved_by=?,approved_at=? WHERE id=? AND status='PENDING_APPROVAL'").bind(decision === "APPROVE" ? "APPROVED" : "REJECTED", decision === "APPROVE" ? actor.userId : null, decision === "APPROVE" ? now : null, task.resource_id));
  } else throw new VatLifecycleResourceError("Approval task resource type is unsupported.");
  statements.push(commandRecord(db, actor.userId, "DECIDE_VAT_APPROVAL", idempotencyKey, requestHash, "APPROVAL_TASK", task.id, now));
  statements.push(outbox(db, task.resource_type, task.resource_id, decision === "APPROVE" ? "VatControlApproved" : "VatControlRejected", task.taxpayer_id, { task_id: task.id, resource_id: task.resource_id, decision, correlation_id: correlationId }, now));
  statements.push(await auditEnvelope(db, actor, `VAT_${task.resource_type}_${nextTaskStatus}`, task.resource_type, task.resource_id, { taskId: task.id, decision, comment, correlationId }, now));
  await db.batch(statements);
  return db.prepare("SELECT * FROM approval_tasks WHERE id=?").bind(task.id).first<Record<string, unknown>>();
}

/**
 * Module 10 Phase B: previously this only ever checked
 * getItasIdentityPort().status() and branched on `.configured` — the port's
 * own submitVatReturn method was dead code, never called by anything
 * anywhere in this codebase. That meant swapping in a working adapter
 * would still never actually submit anything; the calling code itself
 * bypassed the anti-corruption layer's real submission path. Now genuinely
 * calls itas.submitVatReturn() once the local AUTHORITY_APPROVED gate
 * passes, with the same try/catch-ItasIntegrationUnavailableError
 * fail-closed shape lib/data/identity-repository.ts's
 * verifyTaxpayerIdentifiers already established. The tax-rule-authority
 * gate stays a separate, purely local check ahead of the provider call —
 * no reason to even attempt ITAS if the rule set itself isn't approved.
 *
 * Also fixes a genuine "unhandled error" this same audit found:
 * vat_return_submissions has UNIQUE(provider, request_reference), and
 * request_reference is deterministic per return version — so a taxpayer
 * retrying a BLOCKED_CONFIGURATION submission under a fresh idempotency
 * key (a legitimate "try again now that ITAS might be configured" action,
 * distinct from replaying the exact same key) used to hit a raw UNIQUE
 * constraint violation and 500. attempt_count already existed in the
 * schema for exactly this case; a still-open prior attempt is now UPDATEd
 * in place (attempt_count incremented) instead of INSERTed again, and an
 * already-ACKNOWLEDGED submission is refused outright rather than
 * re-attempted.
 */
export async function submitVatReturn(versionId: string, actor: UserContext, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const db = await ensureDatabase();
  const version = await getVersionForActor(db, versionId, actor);
  if (version.status !== "APPROVED") throw new RepositoryConflictError(`Only an approved return can be submitted; current status is ${version.status}.`);
  const boxesResult = await db.prepare("SELECT box_code,amount_cents FROM vat_return_boxes WHERE vat_return_version_id=? ORDER BY box_code").bind(version.id).all<{ box_code: string; amount_cents: number }>();
  const rule = await db.prepare("SELECT status,version FROM tax_rule_sets WHERE id=?").bind(version.tax_rule_set_id).first<{ status: string; version: string }>();
  if (!rule) throw new VatLifecycleResourceError("The return's tax rule set is unavailable.");
  const requestReference = `vat-return:${version.id}:v${version.version_number}`;
  const requestHash = await sha256Hex(stableStringify({ requestReference, vatNumber: version.vat_number, period: version.period_code, version: version.version_number, snapshot: version.ledger_snapshot_hash, boxes: boxesResult.results }));
  const replay = await commandReplay(db, actor.userId, "SUBMIT_VAT_RETURN", idempotencyKey, requestHash);
  if (replay) return db.prepare("SELECT * FROM vat_return_submissions WHERE id=?").bind(replay).first<Record<string, unknown>>();

  const priorAttempt = await db.prepare("SELECT id,status,attempt_count FROM vat_return_submissions WHERE provider='ITAS' AND request_reference=?").bind(requestReference).first<{ id: string; status: string; attempt_count: number }>();
  if (priorAttempt?.status === "ACKNOWLEDGED") throw new RepositoryConflictError("This return has already been submitted and acknowledged by ITAS.");

  const id = priorAttempt?.id ?? crypto.randomUUID();
  const attemptCount = (priorAttempt?.attempt_count ?? 0) + 1;
  const now = new Date().toISOString();
  let status: string;
  let providerReference: string | null = null;
  let responseHash: string | null = null;
  let submittedAt: string | null = null;
  let acknowledgedAt: string | null = null;
  let blocker: string | null = null;
  let eventType: string;

  if (rule.status !== "AUTHORITY_APPROVED") {
    status = "BLOCKED_CONFIGURATION";
    blocker = "Tax rule set lacks authority approval.";
    eventType = "VatReturnSubmissionBlocked";
  } else {
    try {
      const result = await getItasIdentityPort(db).submitVatReturn({
        requestReference, taxpayerVatNumber: version.vat_number, periodCode: version.period_code,
        returnVersion: version.version_number, payloadHash: version.ledger_snapshot_hash,
        boxes: boxesResult.results.map((box) => ({ code: box.box_code, amountCents: box.amount_cents })),
        correlationId,
      });
      status = result.status === "ACCEPTED" ? "ACKNOWLEDGED" : "REJECTED_BY_PROVIDER";
      providerReference = result.providerReference;
      responseHash = result.responseHash;
      submittedAt = result.submittedAt;
      acknowledgedAt = result.status === "ACCEPTED" ? result.submittedAt : null;
      if (result.status === "REJECTED") blocker = "ITAS rejected the submission.";
      eventType = result.status === "ACCEPTED" ? "VATReturnSubmitted" : "VatReturnSubmissionBlocked";
    } catch (error) {
      if (!(error instanceof ItasIntegrationUnavailableError)) throw error;
      status = "BLOCKED_CONFIGURATION";
      blocker = "ITAS technical contract and credentials are not configured.";
      eventType = "VatReturnSubmissionBlocked";
    }
  }

  const submissionStatement = priorAttempt
    ? db.prepare(`UPDATE vat_return_submissions SET
        status=?, request_hash=?, provider_reference=?, response_hash=?, attempt_count=?, requested_by=?, requested_at=?, submitted_at=?, acknowledged_at=?, last_error=?
        WHERE id=?`).bind(status, requestHash, providerReference, responseHash, attemptCount, actor.userId, now, submittedAt, acknowledgedAt, blocker, id)
    : db.prepare(`INSERT INTO vat_return_submissions
        (id,vat_return_version_id,provider,request_reference,status,request_hash,provider_reference,response_hash,attempt_count,requested_by,requested_at,submitted_at,acknowledged_at,last_error)
        VALUES (?,?,'ITAS',?,?,?,?,?,?,?,?,?,?,?)`).bind(id, version.id, requestReference, status, requestHash, providerReference, responseHash, attemptCount, actor.userId, now, submittedAt, acknowledgedAt, blocker);

  await db.batch([
    submissionStatement,
    commandRecord(db, actor.userId, "SUBMIT_VAT_RETURN", idempotencyKey, requestHash, "VAT_RETURN_SUBMISSION", id, now),
    // event-catalog.csv's VATReturnSubmitted fires only on a genuine ITAS ACCEPTED outcome — every other
    // path (local rule-authority gate, ITAS unavailable, provider REJECTED) is honestly VatReturnSubmissionBlocked.
    outbox(db, "VAT_RETURN_VERSION", version.id, eventType, version.taxpayer_id, { vatReturnId: version.id, submissionId: id, payloadHash: version.ledger_snapshot_hash, submittedAt, status, blocker, correlationId }, now),
    await auditEnvelope(db, actor, status === "ACKNOWLEDGED" ? "VAT_RETURN_ACKNOWLEDGED" : "VAT_RETURN_SUBMISSION_BLOCKED", "VAT_RETURN_VERSION", version.id, { submissionId: id, status, blocker, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM vat_return_submissions WHERE id=?").bind(id).first<Record<string, unknown>>();
}
