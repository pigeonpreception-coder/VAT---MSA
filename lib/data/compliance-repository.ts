import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import {
  assertCaseTransition,
  validateCaseOpening,
  validateCaseTransition,
  validateDispute,
  validateFindingIssuance,
  validateObligationCreation,
  validateObligationSatisfaction,
  validateRefundRequest,
  validateRefundReview,
  validateRiskActionApproval,
  validateRiskReviewAssignment,
  type AuditCaseStatus,
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

/**
 * Module 4 Phase C: opening a case now creates it in PROPOSED — the first
 * state of the real lifecycle in lib/domain/compliance.ts's CASE_TRANSITIONS
 * — rather than a permanent, un-transitionable 'OPEN'. It also no longer
 * auto-assigns the opener as assigned_officer_id: assignment is now
 * transitionCase's ASSIGN action's job, a deliberate separation between
 * "who opened this" and "who owns it," matching the assessment's earlier
 * finding that self-auto-assignment was a design smell with no real Assign
 * command to correct it.
 */
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
      VALUES (?,?,?,?,?,?,?,?,'PROPOSED',NULL,?,?,?,NULL)`).bind(id, caseNumber, scope.organisation_id, scope.taxpayer_id, input.case_type, input.title, input.opening_reason, input.risk_tier, actor.userId, now, now),
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

type AuditCaseRow = { id: string; taxpayer_id: string; status: AuditCaseStatus; suspended_from_status: AuditCaseStatus | null };

/**
 * Module 4 Phase C: the single code path that can ever change an audit
 * case's status. Every action (AUTHORIZE/ASSIGN/ADVANCE/SUSPEND/RESUME/
 * CANCEL/REOPEN/CLOSE/LINK_APPEAL) flows through here — see
 * lib/domain/compliance.ts's CASE_TRANSITIONS for why this is one shared
 * function rather than eight bespoke ones. Every transition writes a row to
 * audit_case_transitions (actor, reason, prior/new state, time — exactly
 * what CaseTimeline reads back), never just flips the status column in
 * place.
 */
export async function transitionCase(caseId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may transition an audit case.");
  const input = validateCaseTransition(payload);
  const db = await ensureDatabase();
  const auditCase = await db.prepare("SELECT id,taxpayer_id,status,suspended_from_status FROM audit_cases WHERE id=?").bind(caseId).first<AuditCaseRow>();
  if (!auditCase) throw new ComplianceResourceError("Audit case was not found.", 404);
  const hash = await sha256Hex(stableStringify({ case_id: caseId, input }));
  const prior = await replay(db, actor.userId, "TRANSITION_CASE", key, hash);
  if (prior) return db.prepare("SELECT * FROM audit_cases WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const staticTarget = assertCaseTransition(input.action, auditCase.status);
  let targetStatus: AuditCaseStatus;
  if (input.action === "RESUME") {
    if (!auditCase.suspended_from_status) throw new ComplianceResourceError("This case has no recorded state to resume into.", 409);
    targetStatus = auditCase.suspended_from_status;
  } else {
    targetStatus = staticTarget as AuditCaseStatus;
  }
  const nextSuspendedFrom = input.action === "SUSPEND" ? auditCase.status : null;

  if (input.action === "ASSIGN") {
    const officer = await db.prepare("SELECT id,status FROM app_users WHERE id=?").bind(input.officerId).first<{ id: string; status: string }>();
    if (!officer) throw new ComplianceResourceError("The assigned officer does not exist.", 404);
    if (officer.status !== "ACTIVE") throw new ComplianceResourceError("The assigned officer is not active.", 409);
  }
  if (input.action === "CLOSE") {
    const findingCount = await db.prepare("SELECT COUNT(*) AS n FROM audit_findings WHERE audit_case_id=?").bind(caseId).first<{ n: number }>();
    if (!findingCount?.n) throw new RepositoryConflictError("A case cannot be closed with no findings on record.");
  }

  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`UPDATE audit_cases SET
        status=?, updated_at=?, suspended_from_status=?,
        assigned_officer_id=COALESCE(?, assigned_officer_id),
        closed_at=COALESCE(?, closed_at),
        appeal_reference=COALESCE(?, appeal_reference),
        appeal_linked_at=COALESCE(?, appeal_linked_at)
      WHERE id=?`).bind(
      targetStatus, now, nextSuspendedFrom,
      input.action === "ASSIGN" ? input.officerId : null,
      input.action === "CLOSE" ? now : null,
      input.action === "LINK_APPEAL" ? input.appealReference : null,
      input.action === "LINK_APPEAL" ? now : null,
      caseId,
    ),
    db.prepare(`INSERT INTO audit_case_transitions (id,audit_case_id,action,from_status,to_status,actor_id,reason,occurred_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(crypto.randomUUID(), caseId, input.action, auditCase.status, targetStatus, actor.userId, input.reason, now),
    commandRecord(db, actor.userId, "TRANSITION_CASE", key, hash, "AUDIT_CASE", caseId, now),
    outbox(db, "AUDIT_CASE", caseId, `AuditCase${input.action.charAt(0)}${input.action.slice(1).toLowerCase().replaceAll("_", "")}`, auditCase.taxpayer_id, {
      case_id: caseId, action: input.action, from_status: auditCase.status, to_status: targetStatus, correlation_id: correlationId,
    }, now),
    await auditRecord(db, actor, `AUDIT_CASE_${input.action}`, "AUDIT_CASE", caseId, { action: input.action, fromStatus: auditCase.status, toStatus: targetStatus, reason: input.reason, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM audit_cases WHERE id=?").bind(caseId).first<Record<string, unknown>>();
}

/**
 * Module 4 Phase C IssueFinding. Restricted to the case's analytical/
 * reporting stages (ANALYSIS, TAXPAYER_RESPONSE, FINDINGS_REVIEW) — findings
 * can't be issued before the case has real work underway, nor after it's
 * moved to DECISION/CLOSED/SUSPENDED/CANCELLED.
 */
export async function issueFinding(caseId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may issue an audit finding.");
  const input = validateFindingIssuance(payload);
  const db = await ensureDatabase();
  const auditCase = await db.prepare("SELECT id,taxpayer_id,status FROM audit_cases WHERE id=?").bind(caseId).first<{ id: string; taxpayer_id: string; status: AuditCaseStatus }>();
  if (!auditCase) throw new ComplianceResourceError("Audit case was not found.", 404);
  if (!["ANALYSIS", "TAXPAYER_RESPONSE", "FINDINGS_REVIEW"].includes(auditCase.status)) {
    throw new RepositoryConflictError(`Findings cannot be issued while the case is ${auditCase.status}.`);
  }
  const hash = await sha256Hex(stableStringify({ case_id: caseId, input }));
  const prior = await replay(db, actor.userId, "ISSUE_FINDING", key, hash);
  if (prior) return db.prepare("SELECT * FROM audit_findings WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const existing = await db.prepare("SELECT id FROM audit_findings WHERE audit_case_id=? AND finding_code=?").bind(caseId, input.finding_code).first<{ id: string }>();
  if (existing) throw new RepositoryConflictError(`A finding with this code already exists as ${existing.id}.`);
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO audit_findings (id,audit_case_id,finding_code,title,description,legal_reference,amount_cents,currency,status,author_id,created_at,resolved_at)
      VALUES (?,?,?,?,?,?,?,?,'PRELIMINARY',?,?,NULL)`).bind(id, caseId, input.finding_code, input.title, input.description, input.legal_reference ?? null, input.amount_cents, input.currency, actor.userId, now),
    commandRecord(db, actor.userId, "ISSUE_FINDING", key, hash, "AUDIT_FINDING", id, now),
    outbox(db, "AUDIT_FINDING", id, "AuditFindingIssued", auditCase.taxpayer_id, { finding_id: id, audit_case_id: caseId, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "AUDIT_FINDING_ISSUED", "AUDIT_FINDING", id, { auditCaseId: caseId, findingCode: input.finding_code, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM audit_findings WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/**
 * Module 4 Phase C CaseTimeline: the complete, chronological transition
 * history for one case — exactly what audit_case_transitions exists to
 * answer. Tenant-scoped: a taxpayer may read their own case's timeline
 * (transparency), but only national-scope actors can read any case.
 */
export async function getCaseTimeline(caseId: string, actor: UserContext) {
  const db = await ensureDatabase();
  const auditCase = await db.prepare("SELECT id,taxpayer_id,case_number,status FROM audit_cases WHERE id=?").bind(caseId).first<{ id: string; taxpayer_id: string; case_number: string; status: string }>();
  if (!auditCase) return null;
  if (!isNationalScope(actor) && actor.taxpayerId !== auditCase.taxpayer_id) throw new AccessDeniedError("The audit case is outside your authorised taxpayer scope.");
  const transitions = await db.prepare("SELECT action,from_status,to_status,actor_id,reason,occurred_at FROM audit_case_transitions WHERE audit_case_id=? ORDER BY occurred_at")
    .bind(caseId).all<Record<string, unknown>>();
  return { case: auditCase, transitions: transitions.results };
}

type RiskIndicatorRow = { id: string; organisation_id: string; taxpayer_id: string; severity: string; status: string };

/**
 * Module 4 Phase B AssignReview: the first half of the human-authorisation
 * gate between a risk indicator and an audit case. A risk indicator must be
 * explicitly assigned to an active officer before any decision can be
 * recorded against it — there is no path that skips straight from OPEN to
 * a decision.
 */
export async function assignRiskReview(indicatorId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national risk role may assign a risk indicator for review.");
  const input = validateRiskReviewAssignment(payload);
  const db = await ensureDatabase();
  const indicator = await db.prepare("SELECT id,organisation_id,taxpayer_id,severity,status FROM risk_indicators WHERE id=?").bind(indicatorId).first<RiskIndicatorRow>();
  if (!indicator) throw new ComplianceResourceError("Risk indicator was not found.", 404);
  const hash = await sha256Hex(stableStringify({ indicator_id: indicatorId, input }));
  const prior = await replay(db, actor.userId, "ASSIGN_RISK_REVIEW", key, hash);
  if (prior) return db.prepare("SELECT * FROM risk_indicators WHERE id=?").bind(prior).first<Record<string, unknown>>();
  if (indicator.status !== "OPEN") throw new RepositoryConflictError(`A review can only be assigned while the indicator is OPEN (currently ${indicator.status}).`);
  const officer = await db.prepare("SELECT id,status FROM app_users WHERE id=?").bind(input.officerId).first<{ id: string; status: string }>();
  if (!officer) throw new ComplianceResourceError("The assigned officer does not exist.", 404);
  if (officer.status !== "ACTIVE") throw new ComplianceResourceError("The assigned officer is not active.", 409);
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE risk_indicators SET status='UNDER_REVIEW', assigned_officer_id=? WHERE id=?").bind(input.officerId, indicatorId),
    commandRecord(db, actor.userId, "ASSIGN_RISK_REVIEW", key, hash, "RISK_INDICATOR", indicatorId, now),
    outbox(db, "RISK_INDICATOR", indicatorId, "RiskReviewAssigned", indicator.taxpayer_id, { indicator_id: indicatorId, officer_id: input.officerId, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "RISK_REVIEW_ASSIGNED", "RISK_INDICATOR", indicatorId, { officerId: input.officerId, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM risk_indicators WHERE id=?").bind(indicatorId).first<Record<string, unknown>>();
}

/**
 * Module 4 Phase B ApproveAction: the second half of the gate, and the ONLY
 * path in this codebase that may turn a risk signal into an AuditCase. The
 * new audit_cases row is written directly here (rather than delegating to
 * openAuditCase) so the case creation and the indicator's own status update
 * commit in one atomic batch — a risk indicator can never end up pointing
 * at a case that didn't actually get created, or vice versa. The case's
 * risk_tier is taken from the indicator's own severity, and opening_reason
 * from this decision's rationale — never independently supplied — so every
 * escalated case stays traceable to the exact evidence and human judgement
 * that raised it.
 */
export async function approveRiskAction(indicatorId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national risk role may record a risk action decision.");
  const input = validateRiskActionApproval(payload);
  const db = await ensureDatabase();
  const indicator = await db.prepare("SELECT id,organisation_id,taxpayer_id,severity,status FROM risk_indicators WHERE id=?").bind(indicatorId).first<RiskIndicatorRow>();
  if (!indicator) throw new ComplianceResourceError("Risk indicator was not found.", 404);
  const hash = await sha256Hex(stableStringify({ indicator_id: indicatorId, input }));
  const prior = await replay(db, actor.userId, "APPROVE_RISK_ACTION", key, hash);
  if (prior) return db.prepare("SELECT * FROM risk_indicators WHERE id=?").bind(prior).first<Record<string, unknown>>();
  if (indicator.status !== "UNDER_REVIEW") throw new RepositoryConflictError(`A decision can only be recorded once a review has been assigned (currently ${indicator.status}).`);
  const now = new Date().toISOString();

  if (input.decision === "DISMISS") {
    await db.batch([
      db.prepare("UPDATE risk_indicators SET status='DISMISSED', reviewed_by=?, reviewed_at=? WHERE id=?").bind(actor.userId, now, indicatorId),
      commandRecord(db, actor.userId, "APPROVE_RISK_ACTION", key, hash, "RISK_INDICATOR", indicatorId, now),
      outbox(db, "RISK_INDICATOR", indicatorId, "RiskActionDismissed", indicator.taxpayer_id, { indicator_id: indicatorId, correlation_id: correlationId }, now),
      await auditRecord(db, actor, "RISK_ACTION_DISMISSED", "RISK_INDICATOR", indicatorId, { rationale: input.rationale, correlationId }, now),
    ]);
    return db.prepare("SELECT * FROM risk_indicators WHERE id=?").bind(indicatorId).first<Record<string, unknown>>();
  }

  const caseId = crypto.randomUUID();
  const caseNumber = `CASE-${new Date().getUTCFullYear()}-${caseId.slice(0, 8).toUpperCase()}`;
  await db.batch([
    db.prepare(`INSERT INTO audit_cases
      (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,assigned_officer_id,opened_by,opened_at,updated_at,closed_at)
      VALUES (?,?,?,?,?,?,?,?,'PROPOSED',NULL,?,?,?,NULL)`)
      .bind(caseId, caseNumber, indicator.organisation_id, indicator.taxpayer_id, input.caseType, input.caseTitle, input.rationale, indicator.severity, actor.userId, now, now),
    db.prepare("UPDATE risk_indicators SET status='ESCALATED_TO_CASE', escalated_case_id=?, reviewed_by=?, reviewed_at=? WHERE id=?").bind(caseId, actor.userId, now, indicatorId),
    db.prepare(`INSERT INTO notifications
      (id,user_id,taxpayer_id,notification_type,title,message,severity,status,action_url,created_at,read_at)
      VALUES (?,NULL,?,'AUDIT_CASE_OPENED',?,?,'HIGH','UNREAD',?, ?,NULL)`)
      .bind(crypto.randomUUID(), indicator.taxpayer_id, `Audit case ${caseNumber} opened`, input.caseTitle, `/cases/${caseId}`, now),
    commandRecord(db, actor.userId, "APPROVE_RISK_ACTION", key, hash, "RISK_INDICATOR", indicatorId, now),
    outbox(db, "RISK_INDICATOR", indicatorId, "RiskEscalatedToCase", indicator.taxpayer_id, { indicator_id: indicatorId, case_id: caseId, case_number: caseNumber, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "RISK_ACTION_ESCALATED", "RISK_INDICATOR", indicatorId, { rationale: input.rationale, caseId, caseNumber, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM risk_indicators WHERE id=?").bind(indicatorId).first<Record<string, unknown>>();
}
