import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import {
  validateCaseOpening,
  validateDispute,
  validateObligationCreation,
  validateObligationSatisfaction,
  validateRefundRequest,
  validateRefundReview,
  type CaseOpeningSubmission,
  type DisputeSubmission,
  type ObligationCreation,
  type ObligationSatisfaction,
  type RefundRequestSubmission,
  type RefundReviewSubmission,
} from "@/lib/domain/compliance";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

type TaxpayerScope = { taxpayer_id: string; organisation_id: string; legal_name: string; vat_number: string };
type PriorCommand = { request_hash: string; resource_id: string };

export class ComplianceResourceError extends Error {
  readonly status: number;

  constructor(message: string, status = 422) {
    super(message);
    this.name = "ComplianceResourceError";
    this.status = status;
  }
}

function validateKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new ComplianceResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

async function replay(db: D1Database, actorId: string, command: string, key: string, hash: string) {
  const prior = await db.prepare("SELECT request_hash,resource_id FROM command_idempotency WHERE actor_id=? AND command_type=? AND idempotency_key=?").bind(actorId, command, key).first<PriorCommand>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different compliance command.");
  return prior.resource_id;
}

function commandRecord(db: D1Database, actorId: string, command: string, key: string, hash: string, resourceType: string, resourceId: string, now: string) {
  return db.prepare("INSERT INTO command_idempotency VALUES (?,?,?,?,?,?,?,?)").bind(crypto.randomUUID(), actorId, command, key, hash, resourceType, resourceId, now);
}

async function auditRecord(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>, now: string) {
  const id = crypto.randomUUID();
  const prior = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const body = JSON.stringify(details);
  const hash = await sha256Hex(`${prior?.event_hash ?? "GENESIS"}|${id}|${actor.userId}|${body}|${now}`);
  return db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(id, actor.userId, actor.role, action, resourceType, resourceId, "SUCCESS", body, prior?.event_hash ?? null, hash, now);
}

function outbox(db: D1Database, aggregateType: string, aggregateId: string, eventType: string, taxpayerId: string, payload: Record<string, unknown>, now: string) {
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), aggregateType, aggregateId, eventType, 1, taxpayerId, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

async function resolveTaxpayer(db: D1Database, actor: UserContext, requestedTaxpayerId?: string) {
  const taxpayerId = isNationalScope(actor) ? requestedTaxpayerId : actor.taxpayerId ?? undefined;
  if (!taxpayerId) throw new ComplianceResourceError("A taxpayer id is required for this command.");
  if (!isNationalScope(actor) && requestedTaxpayerId && requestedTaxpayerId !== actor.taxpayerId) throw new AccessDeniedError("The requested taxpayer is outside your authorised scope.");
  const scope = await db.prepare(`SELECT t.id AS taxpayer_id,o.id AS organisation_id,t.legal_name,t.vat_number
    FROM taxpayers t JOIN organisations o ON o.taxpayer_id=t.id AND o.status='ACTIVE' WHERE t.id=?`).bind(taxpayerId).first<TaxpayerScope>();
  if (!scope) throw new ComplianceResourceError("The taxpayer does not resolve to an active organisation.", 404);
  return scope;
}

export async function getComplianceSnapshot(actor: UserContext) {
  const db = await ensureDatabase();
  const scoped = !isNationalScope(actor);
  const taxpayerId = actor.taxpayerId ?? "__none__";
  const [obligations, cases, findings, disputes, risks, refunds, reviews, communicationsResult, notificationsResult, consents, delegationsResult] = await Promise.all([
    scoped ? db.prepare("SELECT * FROM tax_obligations WHERE taxpayer_id=? ORDER BY due_date DESC").bind(taxpayerId).all<Record<string, string | number | null>>() : db.prepare("SELECT o.*,t.legal_name FROM tax_obligations o JOIN taxpayers t ON t.id=o.taxpayer_id ORDER BY o.due_date DESC LIMIT 200").all<Record<string, string | number | null>>(),
    scoped ? db.prepare("SELECT * FROM audit_cases WHERE taxpayer_id=? ORDER BY updated_at DESC").bind(taxpayerId).all<Record<string, string | null>>() : db.prepare("SELECT c.*,t.legal_name,t.vat_number FROM audit_cases c JOIN taxpayers t ON t.id=c.taxpayer_id ORDER BY c.updated_at DESC LIMIT 200").all<Record<string, string | null>>(),
    scoped ? db.prepare("SELECT f.* FROM audit_findings f JOIN audit_cases c ON c.id=f.audit_case_id WHERE c.taxpayer_id=? ORDER BY f.created_at DESC").bind(taxpayerId).all<Record<string, string | number | null>>() : db.prepare("SELECT f.*,c.case_number,t.legal_name FROM audit_findings f JOIN audit_cases c ON c.id=f.audit_case_id JOIN taxpayers t ON t.id=c.taxpayer_id ORDER BY f.created_at DESC LIMIT 200").all<Record<string, string | number | null>>(),
    scoped ? db.prepare("SELECT * FROM disputes WHERE taxpayer_id=? ORDER BY filed_at DESC").bind(taxpayerId).all<Record<string, string | number | null>>() : db.prepare("SELECT d.*,t.legal_name FROM disputes d JOIN taxpayers t ON t.id=d.taxpayer_id ORDER BY d.filed_at DESC LIMIT 200").all<Record<string, string | number | null>>(),
    scoped ? db.prepare("SELECT * FROM risk_indicators WHERE taxpayer_id=? ORDER BY detected_at DESC").bind(taxpayerId).all<Record<string, string | number | null>>() : db.prepare("SELECT r.*,t.legal_name FROM risk_indicators r JOIN taxpayers t ON t.id=r.taxpayer_id ORDER BY r.detected_at DESC LIMIT 200").all<Record<string, string | number | null>>(),
    scoped ? db.prepare("SELECT r.*,v.version_number,p.period_code FROM refund_claims r JOIN vat_return_versions v ON v.id=r.vat_return_version_id JOIN vat_periods p ON p.id=v.vat_period_id WHERE r.taxpayer_id=? ORDER BY r.requested_at DESC").bind(taxpayerId).all<Record<string, string | number | null>>() : db.prepare("SELECT r.*,v.version_number,p.period_code,t.legal_name FROM refund_claims r JOIN vat_return_versions v ON v.id=r.vat_return_version_id JOIN vat_periods p ON p.id=v.vat_period_id JOIN taxpayers t ON t.id=r.taxpayer_id ORDER BY r.requested_at DESC LIMIT 200").all<Record<string, string | number | null>>(),
    scoped ? db.prepare("SELECT rr.* FROM refund_reviews rr JOIN refund_claims r ON r.id=rr.refund_claim_id WHERE r.taxpayer_id=? ORDER BY rr.reviewed_at DESC").bind(taxpayerId).all<Record<string, string | null>>() : db.prepare("SELECT * FROM refund_reviews ORDER BY reviewed_at DESC LIMIT 200").all<Record<string, string | null>>(),
    scoped ? db.prepare("SELECT * FROM communications WHERE taxpayer_id=? ORDER BY occurred_at DESC LIMIT 100").bind(taxpayerId).all<Record<string, string | null>>() : db.prepare("SELECT c.*,t.legal_name FROM communications c LEFT JOIN taxpayers t ON t.id=c.taxpayer_id ORDER BY c.occurred_at DESC LIMIT 200").all<Record<string, string | null>>(),
    db.prepare(`SELECT * FROM notifications WHERE (user_id=? OR ${scoped ? "taxpayer_id=?" : "1=1"}) ORDER BY created_at DESC LIMIT 100`).bind(...(scoped ? [actor.userId, taxpayerId] : [actor.userId])).all<Record<string, string | null>>(),
    scoped ? db.prepare("SELECT * FROM consent_grants WHERE taxpayer_id=? ORDER BY created_at DESC").bind(taxpayerId).all<Record<string, string | null>>() : db.prepare("SELECT * FROM consent_grants ORDER BY created_at DESC LIMIT 200").all<Record<string, string | null>>(),
    scoped ? db.prepare("SELECT * FROM delegations WHERE taxpayer_id=? ORDER BY created_at DESC").bind(taxpayerId).all<Record<string, string | null>>() : db.prepare("SELECT * FROM delegations ORDER BY created_at DESC LIMIT 200").all<Record<string, string | null>>(),
  ]);
  return { obligations: obligations.results, cases: cases.results, findings: findings.results, disputes: disputes.results, risks: risks.results, refunds: refunds.results, reviews: reviews.results, communications: communicationsResult.results, notifications: notificationsResult.results, consents: consents.results, delegations: delegationsResult.results };
}

export async function openAuditCase(payload: CaseOpeningSubmission, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may open an audit case.");
  const input = validateCaseOpening(payload);
  const db = await ensureDatabase();
  const scope = await resolveTaxpayer(db, actor, input.taxpayer_id);
  const hash = await sha256Hex(stableStringify(input));
  const prior = await replay(db, actor.userId, "OPEN_AUDIT_CASE", key, hash);
  if (prior) return db.prepare("SELECT * FROM audit_cases WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const id = crypto.randomUUID();
  const caseNumber = `CASE-${new Date().getUTCFullYear()}-${id.slice(0, 8).toUpperCase()}`;
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO audit_cases
      (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,assigned_officer_id,opened_by,opened_at,updated_at,closed_at)
      VALUES (?,?,?,?,?,?,?,?,'OPEN',?,?,?, ?,NULL)`).bind(id, caseNumber, scope.organisation_id, scope.taxpayer_id, input.case_type, input.title, input.opening_reason, input.risk_tier, actor.userId, actor.userId, now, now),
    db.prepare(`INSERT INTO notifications
      (id,user_id,taxpayer_id,notification_type,title,message,severity,status,action_url,created_at,read_at)
      VALUES (?,NULL,?,'AUDIT_CASE_OPENED',?,?,'HIGH','UNREAD',?, ?,NULL)`).bind(crypto.randomUUID(), scope.taxpayer_id, `Audit case ${caseNumber} opened`, input.title, `/cases/${id}`, now),
    commandRecord(db, actor.userId, "OPEN_AUDIT_CASE", key, hash, "AUDIT_CASE", id, now),
    outbox(db, "AUDIT_CASE", id, "AuditCaseOpened", scope.taxpayer_id, { case_id: id, case_number: caseNumber, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "AUDIT_CASE_OPENED", "AUDIT_CASE", id, { caseNumber, taxpayerId: scope.taxpayer_id, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM audit_cases WHERE id=?").bind(id).first<Record<string, unknown>>();
}

export async function fileDispute(payload: DisputeSubmission, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateDispute(payload);
  const db = await ensureDatabase();
  const scope = await resolveTaxpayer(db, actor, input.taxpayer_id);
  if (input.audit_case_id) {
    const auditCase = await db.prepare("SELECT id FROM audit_cases WHERE id=? AND taxpayer_id=?").bind(input.audit_case_id, scope.taxpayer_id).first<{ id: string }>();
    if (!auditCase) throw new ComplianceResourceError("Audit case is not in the authorised taxpayer scope.");
  }
  const hash = await sha256Hex(stableStringify({ taxpayer_id: scope.taxpayer_id, input }));
  const prior = await replay(db, actor.userId, "FILE_DISPUTE", key, hash);
  if (prior) return db.prepare("SELECT * FROM disputes WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const id = crypto.randomUUID();
  const disputeNumber = `DSP-${new Date().getUTCFullYear()}-${id.slice(0, 8).toUpperCase()}`;
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO disputes
      (id,dispute_number,organisation_id,taxpayer_id,audit_case_id,disputed_resource_type,disputed_resource_id,grounds,disputed_amount_cents,currency,status,filed_by,assigned_officer_id,filed_at,decided_at,decision_summary)
      VALUES (?,?,?,?,?,?,?,?,?,?,'FILED',?,NULL,?,NULL,NULL)`).bind(id, disputeNumber, scope.organisation_id, scope.taxpayer_id, input.audit_case_id ?? null, input.disputed_resource_type, input.disputed_resource_id, input.grounds, input.disputed_amount_cents, input.currency, actor.userId, now),
    db.prepare(`INSERT INTO notifications VALUES (?,NULL,?,'DISPUTE_FILED',?,?,'MEDIUM','UNREAD',?, ?,NULL)`).bind(crypto.randomUUID(), scope.taxpayer_id, `Dispute ${disputeNumber} filed`, "The dispute is awaiting independent assignment and review.", "/compliance", now),
    commandRecord(db, actor.userId, "FILE_DISPUTE", key, hash, "DISPUTE", id, now),
    outbox(db, "DISPUTE", id, "DisputeFiled", scope.taxpayer_id, { dispute_id: id, dispute_number: disputeNumber, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "DISPUTE_FILED", "DISPUTE", id, { disputeNumber, taxpayerId: scope.taxpayer_id, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM disputes WHERE id=?").bind(id).first<Record<string, unknown>>();
}

export async function requestRefund(payload: RefundRequestSubmission, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateRefundRequest(payload);
  const db = await ensureDatabase();
  const version = await db.prepare(`SELECT v.*,p.period_code FROM vat_return_versions v JOIN vat_periods p ON p.id=v.vat_period_id WHERE v.id=?`).bind(input.vat_return_version_id).first<{
    id: string; organisation_id: string; taxpayer_id: string; net_payable_cents: number; status: string; period_code: string;
  }>();
  if (!version) throw new ComplianceResourceError("VAT return version was not found.", 404);
  if (!isNationalScope(actor) && actor.taxpayerId !== version.taxpayer_id) throw new AccessDeniedError("The return is outside your authorised taxpayer scope.");
  if (version.net_payable_cents >= 0) throw new RepositoryConflictError("A refund request requires a negative net VAT position.");
  const hash = await sha256Hex(stableStringify(input));
  const prior = await replay(db, actor.userId, "REQUEST_REFUND", key, hash);
  if (prior) return db.prepare("SELECT * FROM refund_claims WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const existing = await db.prepare("SELECT id FROM refund_claims WHERE vat_return_version_id=?").bind(version.id).first<{ id: string }>();
  if (existing) throw new RepositoryConflictError(`A refund request already exists as ${existing.id}.`);
  const id = crypto.randomUUID();
  const claimNumber = `RFD-${new Date().getUTCFullYear()}-${id.slice(0, 8).toUpperCase()}`;
  const amount = Math.abs(version.net_payable_cents);
  const filed = version.status === "FILED";
  const status = filed ? "EVIDENCE_REVIEW" : "BLOCKED_RETURN_NOT_FILED";
  const riskTier = amount >= 5_000_000 ? "CRITICAL" : amount >= 1_000_000 ? "HIGH" : "MEDIUM";
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO refund_claims
      (id,claim_number,organisation_id,taxpayer_id,vat_return_version_id,amount_cents,currency,status,evidence_status,risk_tier,requested_by,requested_at,approved_by,approved_at,payment_instruction_id)
      VALUES (?,?,?,?,?,?,'NAD',?,? ,?,?,?,NULL,NULL,NULL)`).bind(id, claimNumber, version.organisation_id, version.taxpayer_id, version.id, amount, status, filed ? "PENDING_REVIEW" : "AWAITING_ITAS_ACKNOWLEDGEMENT", riskTier, actor.userId, now),
    commandRecord(db, actor.userId, "REQUEST_REFUND", key, hash, "REFUND_CLAIM", id, now),
    outbox(db, "REFUND_CLAIM", id, filed ? "RefundRequested" : "RefundRequestBlocked", version.taxpayer_id, { refund_claim_id: id, status, return_version_id: version.id, correlation_id: correlationId }, now),
    await auditRecord(db, actor, filed ? "REFUND_REQUESTED" : "REFUND_REQUEST_BLOCKED", "REFUND_CLAIM", id, { claimNumber, status, amountCents: amount, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM refund_claims WHERE id=?").bind(id).first<Record<string, unknown>>();
}

export async function reviewRefund(claimId: string, payload: RefundReviewSubmission, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national refund role may review a refund.");
  const input = validateRefundReview(payload);
  const db = await ensureDatabase();
  const claim = await db.prepare("SELECT * FROM refund_claims WHERE id=?").bind(claimId).first<{ id: string; taxpayer_id: string; status: string; requested_by: string }>();
  if (!claim) throw new ComplianceResourceError("Refund claim was not found.", 404);
  if (claim.requested_by === actor.userId) throw new AccessDeniedError("Maker-checker separation prevents reviewing your own refund request.");
  if (claim.status.startsWith("BLOCKED_")) throw new RepositoryConflictError("The refund cannot enter review until the underlying statutory filing is acknowledged.");
  const hash = await sha256Hex(stableStringify({ claim_id: claim.id, input }));
  const prior = await replay(db, actor.userId, "REVIEW_REFUND", key, hash);
  if (prior) return db.prepare("SELECT * FROM refund_reviews WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const nextStatus = input.decision === "REJECT" ? "REJECTED" : input.decision === "REQUEST_INFORMATION" ? "INFORMATION_REQUIRED" : input.stage === "PAYMENT_AUTHORISATION" && input.decision === "APPROVE" ? "APPROVED_FOR_PAYMENT" : "UNDER_REVIEW";
  await db.batch([
    db.prepare("INSERT INTO refund_reviews VALUES (?,?,?,?,?,?,?)").bind(id, claim.id, input.stage, input.decision, input.findings, actor.userId, now),
    db.prepare("UPDATE refund_claims SET status=?,approved_by=?,approved_at=? WHERE id=?").bind(nextStatus, nextStatus === "APPROVED_FOR_PAYMENT" ? actor.userId : null, nextStatus === "APPROVED_FOR_PAYMENT" ? now : null, claim.id),
    commandRecord(db, actor.userId, "REVIEW_REFUND", key, hash, "REFUND_REVIEW", id, now),
    outbox(db, "REFUND_CLAIM", claim.id, "RefundReviewed", claim.taxpayer_id, { refund_claim_id: claim.id, stage: input.stage, decision: input.decision, status: nextStatus, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "REFUND_REVIEWED", "REFUND_CLAIM", claim.id, { stage: input.stage, decision: input.decision, status: nextStatus, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM refund_reviews WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/**
 * Module 3 Phase D CreateObligation. tax_obligations previously only ever
 * held seed data — no application code wrote to it. Statutory obligations
 * are imposed by NamRA, so this mirrors openAuditCase's national-scope-only
 * restriction rather than compliance:read's broader taxpayer-visible access.
 */
export async function createObligation(payload: ObligationCreation, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may create a tax obligation.");
  const input = validateObligationCreation(payload);
  const db = await ensureDatabase();
  const scope = await resolveTaxpayer(db, actor, input.taxpayer_id);
  const hash = await sha256Hex(stableStringify(input));
  const prior = await replay(db, actor.userId, "CREATE_OBLIGATION", key, hash);
  if (prior) return db.prepare("SELECT * FROM tax_obligations WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const existing = await db.prepare("SELECT id FROM tax_obligations WHERE taxpayer_id=? AND obligation_type=? AND period_code=?")
    .bind(scope.taxpayer_id, input.obligation_type, input.period_code).first<{ id: string }>();
  if (existing) throw new RepositoryConflictError(`An obligation for this taxpayer, type and period already exists as ${existing.id}.`);
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO tax_obligations
      (id,organisation_id,taxpayer_id,obligation_type,period_code,due_date,amount_cents,currency,status,source_system,source_reference,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,'PENDING','VAT_MSA',NULL,?,?)`)
      .bind(id, scope.organisation_id, scope.taxpayer_id, input.obligation_type, input.period_code, input.due_date, input.amount_cents, input.currency, now, now),
    db.prepare(`INSERT INTO notifications VALUES (?,NULL,?,'OBLIGATION_CREATED',?,?,'MEDIUM','UNREAD',?, ?,NULL)`)
      .bind(crypto.randomUUID(), scope.taxpayer_id, `New ${input.obligation_type} obligation for ${input.period_code}`, `Due ${input.due_date}.`, "/compliance", now),
    commandRecord(db, actor.userId, "CREATE_OBLIGATION", key, hash, "TAX_OBLIGATION", id, now),
    outbox(db, "TAX_OBLIGATION", id, "ObligationCreated", scope.taxpayer_id, { obligation_id: id, obligation_type: input.obligation_type, period_code: input.period_code, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "OBLIGATION_CREATED", "TAX_OBLIGATION", id, { taxpayerId: scope.taxpayer_id, obligationType: input.obligation_type, periodCode: input.period_code, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM tax_obligations WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/** Module 3 Phase D MarkSatisfied. Idempotent on an already-satisfied obligation. */
export async function markObligationSatisfied(obligationId: string, payload: ObligationSatisfaction, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may mark a tax obligation satisfied.");
  const input = validateObligationSatisfaction(payload);
  const db = await ensureDatabase();
  const obligation = await db.prepare("SELECT id,taxpayer_id,status FROM tax_obligations WHERE id=?").bind(obligationId).first<{ id: string; taxpayer_id: string; status: string }>();
  if (!obligation) throw new ComplianceResourceError("Tax obligation was not found.", 404);
  const hash = await sha256Hex(stableStringify({ obligation_id: obligation.id, input }));
  const prior = await replay(db, actor.userId, "MARK_OBLIGATION_SATISFIED", key, hash);
  if (prior) return db.prepare("SELECT * FROM tax_obligations WHERE id=?").bind(prior).first<Record<string, unknown>>();
  if (obligation.status === "SATISFIED") return db.prepare("SELECT * FROM tax_obligations WHERE id=?").bind(obligation.id).first<Record<string, unknown>>();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE tax_obligations SET status='SATISFIED',updated_at=? WHERE id=?").bind(now, obligation.id),
    commandRecord(db, actor.userId, "MARK_OBLIGATION_SATISFIED", key, hash, "TAX_OBLIGATION", obligation.id, now),
    outbox(db, "TAX_OBLIGATION", obligation.id, "ObligationSatisfied", obligation.taxpayer_id, { obligation_id: obligation.id, notes: input.notes, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "OBLIGATION_SATISFIED", "TAX_OBLIGATION", obligation.id, { notes: input.notes, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM tax_obligations WHERE id=?").bind(obligation.id).first<Record<string, unknown>>();
}
