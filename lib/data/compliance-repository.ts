import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, hasPermission, isNationalScope } from "@/lib/auth";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import {
  assertCaseTransition,
  normalizeInboxQuery,
  normalizeNotificationQuery,
  normalizeRiskIndicatorQuery,
  validateCaseNoteAddition,
  validateCaseOpening,
  validateCaseTransition,
  validateConversationClosure,
  validateConversationResponse,
  validateDispute,
  validateEvidenceAddition,
  validateEvidenceCustodyEvent,
  validateFindingIssuance,
  validateNotice,
  validateNotificationCancellation,
  validateNotificationPreference,
  validateNotificationQueue,
  validateObligationCreation,
  validateObligationSatisfaction,
  validateRefundRequest,
  validateRefundReview,
  validateRiskActionApproval,
  validateRiskEvaluationRequest,
  validateRiskReviewAssignment,
  type AuditCaseStatus,
  type CaseOpeningSubmission,
  type CaseReferenceType,
  type DisputeSubmission,
  type EvidenceSourceType,
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

/**
 * Module 6 Phase D: the shared notification-creation path. A 2026-08-26
 * code audit found five separate call sites (openAuditCase, fileDispute,
 * createObligation, approveRiskAction's case escalation, and this phase's
 * own Phase C sendNotice/respondToConversation) each hand-rolling a nearly
 * identical INSERT INTO notifications — two of them as bare positional
 * VALUES with no column list at all, which is exactly the kind of
 * statement a later column addition silently breaks (this phase added
 * three: cancelled_by/cancelled_at/cancellation_reason). All five now
 * route through this one function instead — the "consolidating the
 * scattered notification-creation side effects" the playbook asks for.
 * Always writes one IN_APP notification_deliveries row alongside the
 * notification itself: the in-app notification centre is not a channel a
 * preference can disable, since the notifications table row *is* that
 * channel's delivery. Additional channels (EMAIL/SMS/PORTAL) are only ever
 * attempted by the standalone queueNotification command below, which can
 * check notification_preferences first — these five call sites all target
 * a taxpayer broadly (user_id is always NULL), not a specific user, so
 * there is no single user's preference to check here.
 */
function notificationRecord(db: D1Database, input: { userId?: string | null; taxpayerId: string; notificationType: string; title: string; message: string; severity: string; actionUrl?: string | null }, now: string): D1PreparedStatement[] {
  const id = crypto.randomUUID();
  return [
    db.prepare(`INSERT INTO notifications
      (id,user_id,taxpayer_id,notification_type,title,message,severity,status,action_url,created_at,read_at,cancelled_by,cancelled_at,cancellation_reason)
      VALUES (?,?,?,?,?,?,?,'UNREAD',?,?,NULL,NULL,NULL,NULL)`).bind(id, input.userId ?? null, input.taxpayerId, input.notificationType, input.title, input.message, input.severity, input.actionUrl ?? null, now),
    db.prepare("INSERT INTO notification_deliveries (id,notification_id,channel,status,attempted_at) VALUES (?,?,'IN_APP','QUEUED',?)").bind(crypto.randomUUID(), id, now),
  ];
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
    ...notificationRecord(db, { taxpayerId: scope.taxpayer_id, notificationType: "AUDIT_CASE_OPENED", title: `Audit case ${caseNumber} opened`, message: input.title, severity: "HIGH", actionUrl: `/cases/${id}` }, now),
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
    ...notificationRecord(db, { taxpayerId: scope.taxpayer_id, notificationType: "DISPUTE_FILED", title: `Dispute ${disputeNumber} filed`, message: "The dispute is awaiting independent assignment and review.", severity: "MEDIUM", actionUrl: "/compliance" }, now),
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
    ...notificationRecord(db, { taxpayerId: scope.taxpayer_id, notificationType: "OBLIGATION_CREATED", title: `New ${input.obligation_type} obligation for ${input.period_code}`, message: `Due ${input.due_date}.`, severity: "MEDIUM", actionUrl: "/compliance" }, now),
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

type AuditCaseRow = { id: string; taxpayer_id: string; status: AuditCaseStatus; suspended_from_status: AuditCaseStatus | null; opened_by: string };

/**
 * Module 4 Phase E: segregation of duties. The officer who opened a case
 * (audit_cases.opened_by — set the same way whether the case came from a
 * manual OpenAuditCase or a risk-driven ApproveAction escalation, see that
 * function) may not also close it or issue a finding on it. Returns
 * whether an exceptional override was applied, so the caller can log a
 * distinct, clearly-findable audit event for it — never a silent bypass.
 */
function enforceSegregationOfDuties(actor: UserContext, openedBy: string, overrideReason: string | undefined, actionDescription: string): boolean {
  if (openedBy !== actor.userId) return false;
  if (!overrideReason) {
    throw new AccessDeniedError(`Segregation of duties: the officer who opened this case cannot also ${actionDescription}. An authorised supervisor may override with cases:override-sod and a recorded reason.`);
  }
  if (!hasPermission(actor, "cases:override-sod")) {
    throw new AccessDeniedError(`Overriding this segregation-of-duties control to ${actionDescription} requires cases:override-sod.`);
  }
  return true;
}

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
  const auditCase = await db.prepare("SELECT id,taxpayer_id,status,suspended_from_status,opened_by FROM audit_cases WHERE id=?").bind(caseId).first<AuditCaseRow>();
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
  let sodOverrideApplied = false;
  if (input.action === "CLOSE") {
    const findingCount = await db.prepare("SELECT COUNT(*) AS n FROM audit_findings WHERE audit_case_id=?").bind(caseId).first<{ n: number }>();
    if (!findingCount?.n) throw new RepositoryConflictError("A case cannot be closed with no findings on record.");
    sodOverrideApplied = enforceSegregationOfDuties(actor, auditCase.opened_by, input.overrideReason, "close it");
  }

  const now = new Date().toISOString();
  const statements: D1PreparedStatement[] = [
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
      case_id: caseId, action: input.action, from_status: auditCase.status, to_status: targetStatus, correlation_id: correlationId, sod_override: sodOverrideApplied,
    }, now),
    // A single audit_events row per command, not two: auditRecord reads
    // "the latest hash" from the DB at call time, so calling it twice while
    // building one batch (neither insert committed yet) would give both
    // rows the same previous_hash and break the chain's linearity. The
    // override is instead a distinctly-named action plus full detail on
    // this one row — still a genuinely logged, clearly findable exception,
    // just one row rather than a fabricated second link in the chain.
    await auditRecord(db, actor, sodOverrideApplied ? `AUDIT_CASE_${input.action}_SOD_OVERRIDE` : `AUDIT_CASE_${input.action}`, "AUDIT_CASE", caseId, {
      action: input.action, fromStatus: auditCase.status, toStatus: targetStatus, reason: input.reason, correlationId,
      ...(sodOverrideApplied ? { sodOverride: true, openedBy: auditCase.opened_by, overriddenBy: actor.userId, overrideReason: input.overrideReason } : {}),
    }, now),
  ];
  await db.batch(statements);
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
  const auditCase = await db.prepare("SELECT id,taxpayer_id,status,opened_by FROM audit_cases WHERE id=?").bind(caseId).first<{ id: string; taxpayer_id: string; status: AuditCaseStatus; opened_by: string }>();
  if (!auditCase) throw new ComplianceResourceError("Audit case was not found.", 404);
  if (!["ANALYSIS", "TAXPAYER_RESPONSE", "FINDINGS_REVIEW"].includes(auditCase.status)) {
    throw new RepositoryConflictError(`Findings cannot be issued while the case is ${auditCase.status}.`);
  }
  const sodOverrideApplied = enforceSegregationOfDuties(actor, auditCase.opened_by, input.overrideReason, "issue a finding on it");
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
    outbox(db, "AUDIT_FINDING", id, "AuditFindingIssued", auditCase.taxpayer_id, { finding_id: id, audit_case_id: caseId, correlation_id: correlationId, sod_override: sodOverrideApplied }, now),
    await auditRecord(db, actor, sodOverrideApplied ? "AUDIT_FINDING_ISSUED_SOD_OVERRIDE" : "AUDIT_FINDING_ISSUED", "AUDIT_FINDING", id, {
      auditCaseId: caseId, findingCode: input.finding_code, correlationId,
      ...(sodOverrideApplied ? { sodOverride: true, openedBy: auditCase.opened_by, overriddenBy: actor.userId, overrideReason: input.overrideReason } : {}),
    }, now),
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
    ...notificationRecord(db, { taxpayerId: indicator.taxpayer_id, notificationType: "AUDIT_CASE_OPENED", title: `Audit case ${caseNumber} opened`, message: input.caseTitle, severity: "HIGH", actionUrl: `/cases/${caseId}` }, now),
    commandRecord(db, actor.userId, "APPROVE_RISK_ACTION", key, hash, "RISK_INDICATOR", indicatorId, now),
    outbox(db, "RISK_INDICATOR", indicatorId, "RiskEscalatedToCase", indicator.taxpayer_id, { indicator_id: indicatorId, case_id: caseId, case_number: caseNumber, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "RISK_ACTION_ESCALATED", "RISK_INDICATOR", indicatorId, { rationale: input.rationale, caseId, caseNumber, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM risk_indicators WHERE id=?").bind(indicatorId).first<Record<string, unknown>>();
}

/**
 * Module 4 Phase A: a small, fixed, code-versioned rule catalogue — see
 * lib/domain/compliance.ts's comment on why this is not a governed DB
 * table at pilot scale. Every rule reuses evidence this codebase has
 * already computed elsewhere rather than re-deriving it independently:
 * HIGH_VALUE_INVOICE_PATTERN reuses Module 2's own per-invoice
 * scoreInvoice risk_level, RECONCILIATION_EXCEPTION_BACKLOG reuses Module
 * 3's reconciliation_exceptions queue, OBLIGATION_OVERDUE reuses Module 3
 * Phase D's tax_obligations. EvaluateRisk raises a NamRA-restricted,
 * taxpayer-level signal from patterns across that evidence — a coarser
 * granularity and a different purpose (case authorisation) than either
 * source, not a duplicate of either.
 */
const RISK_RULE_VERSION = "RISK-PILOT-2026.2";

type RiskRuleResult = {
  indicatorCode: string;
  fired: boolean;
  scoreBps: number;
  severity: "LOW" | "MEDIUM" | "HIGH" | "CRITICAL";
  rationale: string;
};

async function evaluateHighValueInvoicePattern(db: D1Database, taxpayerId: string): Promise<RiskRuleResult> {
  const row = await db.prepare(`SELECT COUNT(*) AS n FROM invoices WHERE supplier_taxpayer_id=? AND status!='CANCELLED' AND risk_level IN ('HIGH','CRITICAL')`).bind(taxpayerId).first<{ n: number }>();
  const count = row?.n ?? 0;
  const fired = count >= 2;
  return {
    indicatorCode: "HIGH_VALUE_INVOICE_PATTERN",
    fired,
    scoreBps: fired ? Math.min(9_000, 4_000 + count * 1_000) : 0,
    severity: count >= 5 ? "CRITICAL" : "HIGH",
    rationale: `${count} active invoice(s) independently scored HIGH or CRITICAL risk at submission time (Module 2's own per-invoice check).`,
  };
}

async function evaluateReconciliationExceptionBacklog(db: D1Database, taxpayerId: string): Promise<RiskRuleResult> {
  const row = await db.prepare(`SELECT COUNT(*) AS n FROM reconciliation_exceptions WHERE taxpayer_id=? AND status IN ('OPEN','ASSIGNED')`).bind(taxpayerId).first<{ n: number }>();
  const count = row?.n ?? 0;
  const fired = count >= 3;
  return {
    indicatorCode: "RECONCILIATION_EXCEPTION_BACKLOG",
    fired,
    scoreBps: fired ? Math.min(9_000, 3_500 + count * 800) : 0,
    severity: count >= 8 ? "CRITICAL" : "HIGH",
    rationale: `${count} reconciliation exception(s) remain unresolved (OPEN or ASSIGNED) for this taxpayer.`,
  };
}

async function evaluateObligationOverdue(db: D1Database, taxpayerId: string): Promise<RiskRuleResult> {
  const row = await db.prepare(`SELECT COUNT(*) AS n FROM tax_obligations WHERE taxpayer_id=? AND status='PENDING' AND due_date < date('now')`).bind(taxpayerId).first<{ n: number }>();
  const count = row?.n ?? 0;
  const fired = count >= 1;
  return {
    indicatorCode: "OBLIGATION_OVERDUE",
    fired,
    scoreBps: fired ? Math.min(9_500, 5_000 + count * 1_500) : 0,
    severity: count >= 3 ? "CRITICAL" : "HIGH",
    rationale: `${count} statutory obligation(s) remain PENDING past their due date.`,
  };
}

/**
 * Module 4 Phase A EvaluateRisk. Unlike every other command in this file,
 * a replay match does NOT short-circuit to stale stored data: this
 * command's contract is "current risk given current evidence," and
 * factors are computed live rather than persisted, so a retried key still
 * re-runs the same deterministic rules against whatever evidence exists
 * now. The replay check's only job here is to (a) surface a genuine
 * conflict if the key was reused for a different taxpayer, and (b) skip
 * writing a second, redundant audit/outbox event for what is really the
 * same logical request. The indicator writes themselves are already
 * naturally idempotent at the row level — same rule+subject+version
 * always resolves to the same risk_indicators row, refreshed not
 * duplicated — regardless of the idempotency key at all.
 */
export async function evaluateRisk(taxpayerId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national risk role may evaluate risk for a taxpayer.");
  validateRiskEvaluationRequest(payload);
  const db = await ensureDatabase();
  const scope = await db.prepare(`SELECT t.id AS taxpayer_id, o.id AS organisation_id
    FROM taxpayers t JOIN organisations o ON o.taxpayer_id=t.id AND o.status='ACTIVE' WHERE t.id=?`).bind(taxpayerId).first<{ taxpayer_id: string; organisation_id: string }>();
  if (!scope) throw new ComplianceResourceError("The taxpayer does not resolve to an active organisation.", 404);

  const hash = await sha256Hex(stableStringify({ taxpayer_id: taxpayerId }));
  const prior = await replay(db, actor.userId, "EVALUATE_RISK", key, hash);

  const results = await Promise.all([
    evaluateHighValueInvoicePattern(db, scope.taxpayer_id),
    evaluateReconciliationExceptionBacklog(db, scope.taxpayer_id),
    evaluateObligationOverdue(db, scope.taxpayer_id),
  ]);

  const now = new Date().toISOString();
  const statements: D1PreparedStatement[] = [];
  const touchedIndicatorIds: string[] = [];
  for (const result of results) {
    if (!result.fired) continue;
    const existing = await db.prepare(`SELECT id,status FROM risk_indicators WHERE subject_type='TAXPAYER' AND subject_id=? AND indicator_code=? AND rule_version=?`)
      .bind(scope.taxpayer_id, result.indicatorCode, RISK_RULE_VERSION).first<{ id: string; status: string }>();
    if (existing && existing.status !== "OPEN") {
      touchedIndicatorIds.push(existing.id);
      continue;
    }
    if (existing) {
      statements.push(db.prepare("UPDATE risk_indicators SET score_bps=?,severity=?,rationale=?,detected_at=? WHERE id=?").bind(result.scoreBps, result.severity, result.rationale, now, existing.id));
      touchedIndicatorIds.push(existing.id);
    } else {
      const id = crypto.randomUUID();
      statements.push(db.prepare(`INSERT INTO risk_indicators
        (id,organisation_id,taxpayer_id,subject_type,subject_id,indicator_code,score_bps,severity,rationale,rule_version,decision_effect,status,detected_at,reviewed_by,reviewed_at,assigned_officer_id,escalated_case_id)
        VALUES (?,?,?,'TAXPAYER',?,?,?,?,?,?,'ADVISORY_ONLY','OPEN',?,NULL,NULL,NULL,NULL)`)
        .bind(id, scope.organisation_id, scope.taxpayer_id, scope.taxpayer_id, result.indicatorCode, result.scoreBps, result.severity, result.rationale, RISK_RULE_VERSION, now));
      statements.push(outbox(db, "RISK_INDICATOR", id, "RiskIndicatorRaised", scope.taxpayer_id, { indicator_id: id, indicator_code: result.indicatorCode, correlation_id: correlationId }, now));
      touchedIndicatorIds.push(id);
    }
  }
  if (!prior) {
    statements.push(commandRecord(db, actor.userId, "EVALUATE_RISK", key, hash, "TAXPAYER", taxpayerId, now));
    statements.push(await auditRecord(db, actor, "RISK_EVALUATED", "TAXPAYER", taxpayerId, { factors: results.map((r) => ({ code: r.indicatorCode, fired: r.fired })), correlationId }, now));
  }
  if (statements.length) await db.batch(statements);

  const indicators = touchedIndicatorIds.length
    ? (await db.prepare(`SELECT * FROM risk_indicators WHERE id IN (${touchedIndicatorIds.map(() => "?").join(",")})`).bind(...touchedIndicatorIds).all<Record<string, unknown>>()).results
    : [];

  return {
    taxpayer_id: taxpayerId,
    evaluated_at: now,
    rule_version: RISK_RULE_VERSION,
    factors: results.map((r) => ({ indicator_code: r.indicatorCode, fired: r.fired, score_bps: r.scoreBps, severity: r.severity, rationale: r.rationale })),
    indicators,
  };
}

/**
 * Module 4 Phase A GetRestrictedRisk. Deliberately NOT taxpayer-visible at
 * all — unlike CaseTimeline (Phase C), which a taxpayer may read for their
 * own case, risk indicators carry the RESTRICTED_RISK classification in
 * the data dictionary and the NamRA portal's own copy already states
 * "internal indicators appear only for authorised NamRA roles." Gated on
 * risk:read (broader than risk:review — e.g. NAMRA_REFUND_OFFICER can
 * read risk context without being able to action it).
 */
export async function getRestrictedRisk(actor: UserContext, params: URLSearchParams) {
  if (!isNationalScope(actor)) throw new AccessDeniedError("Risk indicators are restricted to authorised national risk roles.");
  const query = normalizeRiskIndicatorQuery(params);
  const db = await ensureDatabase();

  const conditions: string[] = [];
  const values: unknown[] = [];
  if (query.taxpayerId) {
    conditions.push("r.taxpayer_id = ?");
    values.push(query.taxpayerId);
  }
  if (query.status) {
    conditions.push("r.status = ?");
    values.push(query.status);
  }
  if (query.severity) {
    conditions.push("r.severity = ?");
    values.push(query.severity);
  }
  const whereClause = conditions.length ? `WHERE ${conditions.join(" AND ")}` : "";

  const [items, count] = await Promise.all([
    db.prepare(`SELECT r.*, t.legal_name, t.vat_number FROM risk_indicators r JOIN taxpayers t ON t.id=r.taxpayer_id ${whereClause}
      ORDER BY CASE r.severity WHEN 'CRITICAL' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'MEDIUM' THEN 3 ELSE 4 END, r.detected_at DESC
      LIMIT ? OFFSET ?`).bind(...values, query.limit, query.offset).all<Record<string, unknown>>(),
    db.prepare(`SELECT COUNT(*) AS n FROM risk_indicators r ${whereClause}`).bind(...values).first<{ n: number }>(),
  ]);

  return { items: items.results, totalCount: count?.n ?? 0, limit: query.limit, offset: query.offset };
}

/**
 * Module 4 Phase D: resolves the current, authoritative hash for a cited
 * canonical record — the single place both AddEvidence (at insertion time)
 * and RecordEvidenceCustodyEvent's VERIFY action (re-checked later) derive
 * it from, so the two can never silently disagree about what "the hash"
 * means for a given source type.
 */
async function resolveEvidenceChecksum(db: D1Database, sourceResourceType: EvidenceSourceType, sourceResourceId: string): Promise<{ checksum: string; evidenceType: string; documentId: string | null } | null> {
  if (sourceResourceType === "INVOICE") {
    const row = await db.prepare("SELECT payload_hash FROM invoices WHERE id=?").bind(sourceResourceId).first<{ payload_hash: string }>();
    return row ? { checksum: row.payload_hash, evidenceType: "CERTIFIED_RECORD", documentId: null } : null;
  }
  if (sourceResourceType === "VAT_RETURN") {
    const row = await db.prepare("SELECT ledger_snapshot_hash FROM vat_return_versions WHERE id=?").bind(sourceResourceId).first<{ ledger_snapshot_hash: string }>();
    return row ? { checksum: row.ledger_snapshot_hash, evidenceType: "CERTIFIED_RECORD", documentId: null } : null;
  }
  if (sourceResourceType === "DOCUMENT") {
    const row = await db.prepare("SELECT checksum_sha256, scan_status FROM document_metadata WHERE id=?").bind(sourceResourceId).first<{ checksum_sha256: string; scan_status: string }>();
    if (!row) return null;
    return { checksum: row.checksum_sha256, evidenceType: "UPLOADED_DOCUMENT", documentId: sourceResourceId };
  }
  return null;
}

type EvidenceRow = { id: string; audit_case_id: string; source_resource_type: EvidenceSourceType; source_resource_id: string; document_id: string | null; checksum_sha256: string; status: string; case_taxpayer_id: string };

/**
 * Module 4 Phase D AddEvidence. A document citation must already be
 * clean-scanned (Module 22's quarantine pipeline) — evidence integrity
 * can't rest on a file that hasn't finished its malware scan. A
 * supersedes_evidence_id flips the prior row to SUPERSEDED in the same
 * batch as the new row's insert; the partial unique index in db/runtime.ts
 * (WHERE status='PRESERVED') is what actually enforces that only one
 * PRESERVED row can exist per (case, source) at a time.
 */
export async function addEvidence(caseId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may add case evidence.");
  const input = validateEvidenceAddition(payload);
  const db = await ensureDatabase();
  const auditCase = await db.prepare("SELECT id,taxpayer_id,status FROM audit_cases WHERE id=?").bind(caseId).first<{ id: string; taxpayer_id: string; status: string }>();
  if (!auditCase) throw new ComplianceResourceError("Audit case was not found.", 404);
  if (auditCase.status === "CANCELLED") throw new RepositoryConflictError("Evidence cannot be added to a cancelled case.");

  const hash = await sha256Hex(stableStringify({ case_id: caseId, input }));
  const prior = await replay(db, actor.userId, "ADD_EVIDENCE", key, hash);
  if (prior) return db.prepare("SELECT * FROM audit_evidence WHERE id=?").bind(prior).first<Record<string, unknown>>();

  let checksum: string;
  let evidenceType: string;
  let documentId: string | null = null;
  if (input.sourceResourceType === "OTHER") {
    checksum = input.checksumSha256 as string;
    evidenceType = "EXTERNAL_RECORD";
  } else {
    const resolved = await resolveEvidenceChecksum(db, input.sourceResourceType, input.sourceResourceId);
    if (!resolved) throw new ComplianceResourceError(`The cited ${input.sourceResourceType.toLowerCase()} was not found.`, 404);
    if (input.sourceResourceType === "DOCUMENT") {
      const doc = await db.prepare("SELECT scan_status FROM document_metadata WHERE id=?").bind(input.sourceResourceId).first<{ scan_status: string }>();
      if (doc?.scan_status !== "CLEAN") throw new RepositoryConflictError("Only a clean-scanned document may be cited as evidence.");
    }
    checksum = resolved.checksum;
    evidenceType = resolved.evidenceType;
    documentId = resolved.documentId;
  }

  if (input.supersedesEvidenceId) {
    const superseded = await db.prepare("SELECT id,status FROM audit_evidence WHERE id=? AND audit_case_id=?").bind(input.supersedesEvidenceId, caseId).first<{ id: string; status: string }>();
    if (!superseded) throw new ComplianceResourceError("The evidence being superseded was not found on this case.", 404);
    if (superseded.status !== "PRESERVED") throw new RepositoryConflictError("Only currently preserved evidence can be superseded.");
  } else {
    const activeCitation = await db.prepare("SELECT id FROM audit_evidence WHERE audit_case_id=? AND source_resource_type=? AND source_resource_id=? AND status='PRESERVED'")
      .bind(caseId, input.sourceResourceType, input.sourceResourceId).first<{ id: string }>();
    if (activeCitation) throw new RepositoryConflictError(`This source is already cited as active evidence (${activeCitation.id}) — supersede it instead of adding a duplicate.`);
  }

  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  // The old row must flip to SUPERSEDED *before* the new PRESERVED row is
  // inserted: the partial unique index (WHERE status='PRESERVED') checks
  // each statement immediately as db.batch() executes it, not at the end
  // of the batch — inserting the new row first would collide with the old
  // one that's still (momentarily) PRESERVED.
  const statements: D1PreparedStatement[] = [];
  if (input.supersedesEvidenceId) {
    statements.push(
      db.prepare("UPDATE audit_evidence SET status='SUPERSEDED' WHERE id=?").bind(input.supersedesEvidenceId),
      db.prepare(`INSERT INTO audit_evidence_custody_events (id,audit_evidence_id,action,actor_id,notes,integrity_verified,occurred_at) VALUES (?,?,'SUPERSEDED',?,?,NULL,?)`)
        .bind(crypto.randomUUID(), input.supersedesEvidenceId, actor.userId, `Superseded by evidence ${id}.`, now),
    );
  }
  statements.push(
    db.prepare(`INSERT INTO audit_evidence
      (id,audit_case_id,evidence_type,source_resource_type,source_resource_id,document_id,checksum_sha256,description,status,added_by,added_at,previous_version_id,legal_hold)
      VALUES (?,?,?,?,?,?,?,?,'PRESERVED',?,?,?,0)`)
      .bind(id, caseId, evidenceType, input.sourceResourceType, input.sourceResourceId, documentId, checksum, input.description, actor.userId, now, input.supersedesEvidenceId ?? null),
    db.prepare(`INSERT INTO audit_evidence_custody_events (id,audit_evidence_id,action,actor_id,notes,integrity_verified,occurred_at) VALUES (?,?,'ADDED',?,?,NULL,?)`)
      .bind(crypto.randomUUID(), id, actor.userId, input.description, now),
  );
  statements.push(
    commandRecord(db, actor.userId, "ADD_EVIDENCE", key, hash, "AUDIT_EVIDENCE", id, now),
    outbox(db, "AUDIT_EVIDENCE", id, "AuditEvidenceAdded", auditCase.taxpayer_id, { evidence_id: id, audit_case_id: caseId, source_resource_type: input.sourceResourceType, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "AUDIT_EVIDENCE_ADDED", "AUDIT_EVIDENCE", id, { auditCaseId: caseId, sourceResourceType: input.sourceResourceType, sourceResourceId: input.sourceResourceId, correlationId }, now),
  );
  try {
    await db.batch(statements);
  } catch (error) {
    // Defense in depth against a genuine concurrent race between the
    // pre-check above and this insert: the partial unique index in
    // db/runtime.ts (WHERE status='PRESERVED') is the actual guarantee,
    // this just recovers its violation into the same clean 409 rather than
    // letting it leak out as an unhandled 500 — mirrors lib/data/repository.ts's
    // submitInvoice recovery for the same class of race.
    const message = error instanceof Error ? error.message : String(error);
    if (!/unique constraint failed/i.test(message)) throw error;
    throw new RepositoryConflictError("This source is already cited as active evidence — supersede it instead of adding a duplicate.");
  }
  return db.prepare("SELECT * FROM audit_evidence WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/**
 * Module 4 Phase D RecordEvidenceCustodyEvent. VERIFY re-derives the
 * cited record's CURRENT hash and compares it against the hash stored at
 * addition time — a genuine tamper/drift detector, not a rubber stamp.
 * A mismatch is recorded, not thrown: this is an audit trail feeding
 * human judgement, the same advisory-only posture Module 4's risk
 * indicators already take, not an automated adverse action. Externally
 * supplied (OTHER) evidence has nothing this system can re-derive, so its
 * integrity_verified always stays NULL rather than a false CLAIM either way.
 * SET_LEGAL_HOLD/RELEASE_LEGAL_HOLD cascade to the underlying
 * document_metadata row when the evidence cites an uploaded document, so
 * Module 22's retention/deletion path and this case's hold stay in sync.
 */
export async function recordEvidenceCustodyEvent(evidenceId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may record an evidence custody event.");
  const input = validateEvidenceCustodyEvent(payload);
  const db = await ensureDatabase();
  const evidence = await db.prepare(`SELECT e.id,e.audit_case_id,e.source_resource_type,e.source_resource_id,e.document_id,e.checksum_sha256,e.status,ac.taxpayer_id AS case_taxpayer_id
    FROM audit_evidence e JOIN audit_cases ac ON ac.id=e.audit_case_id WHERE e.id=?`).bind(evidenceId).first<EvidenceRow>();
  if (!evidence) throw new ComplianceResourceError("Evidence record was not found.", 404);

  const hash = await sha256Hex(stableStringify({ evidence_id: evidenceId, input }));
  const prior = await replay(db, actor.userId, "RECORD_EVIDENCE_CUSTODY_EVENT", key, hash);
  if (prior) return db.prepare("SELECT * FROM audit_evidence WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const now = new Date().toISOString();
  const statements: D1PreparedStatement[] = [];
  let integrityVerified: number | null = null;

  if (input.action === "VERIFY") {
    if (evidence.source_resource_type !== "OTHER") {
      const current = await resolveEvidenceChecksum(db, evidence.source_resource_type, evidence.source_resource_id);
      integrityVerified = current && current.checksum === evidence.checksum_sha256 ? 1 : 0;
    }
  } else if (input.action === "SET_LEGAL_HOLD") {
    statements.push(db.prepare("UPDATE audit_evidence SET legal_hold=1 WHERE id=?").bind(evidenceId));
    if (evidence.document_id) statements.push(db.prepare("UPDATE document_metadata SET legal_hold=1 WHERE id=?").bind(evidence.document_id));
  } else {
    statements.push(db.prepare("UPDATE audit_evidence SET legal_hold=0 WHERE id=?").bind(evidenceId));
    if (evidence.document_id) statements.push(db.prepare("UPDATE document_metadata SET legal_hold=0 WHERE id=?").bind(evidence.document_id));
  }

  statements.push(
    db.prepare(`INSERT INTO audit_evidence_custody_events (id,audit_evidence_id,action,actor_id,notes,integrity_verified,occurred_at) VALUES (?,?,?,?,?,?,?)`)
      .bind(crypto.randomUUID(), evidenceId, input.action, actor.userId, input.notes ?? null, integrityVerified, now),
    commandRecord(db, actor.userId, "RECORD_EVIDENCE_CUSTODY_EVENT", key, hash, "AUDIT_EVIDENCE", evidenceId, now),
    outbox(db, "AUDIT_EVIDENCE", evidenceId, `AuditEvidence${input.action.charAt(0)}${input.action.slice(1).toLowerCase().replaceAll("_", "")}`, evidence.case_taxpayer_id, { evidence_id: evidenceId, action: input.action, integrity_verified: integrityVerified, correlation_id: correlationId }, now),
    await auditRecord(db, actor, `AUDIT_EVIDENCE_${input.action}`, "AUDIT_EVIDENCE", evidenceId, { action: input.action, integrityVerified, notes: input.notes, correlationId }, now),
  );
  await db.batch(statements);
  return db.prepare("SELECT * FROM audit_evidence WHERE id=?").bind(evidenceId).first<Record<string, unknown>>();
}

/** Module 4 Phase D GetCaseEvidence. Tenant-scoped exactly like CaseTimeline: national-scope or the case's own taxpayer. */
export async function getCaseEvidence(caseId: string, actor: UserContext) {
  const db = await ensureDatabase();
  const auditCase = await db.prepare("SELECT id,taxpayer_id FROM audit_cases WHERE id=?").bind(caseId).first<{ id: string; taxpayer_id: string }>();
  if (!auditCase) return null;
  if (!isNationalScope(actor) && actor.taxpayerId !== auditCase.taxpayer_id) throw new AccessDeniedError("The audit case is outside your authorised taxpayer scope.");
  const evidence = await db.prepare("SELECT * FROM audit_evidence WHERE audit_case_id=? ORDER BY added_at").bind(caseId).all<Record<string, unknown>>();
  const evidenceIds = evidence.results.map((row) => row.id as string);
  const custodyEvents = evidenceIds.length
    ? (await db.prepare(`SELECT * FROM audit_evidence_custody_events WHERE audit_evidence_id IN (${evidenceIds.map(() => "?").join(",")}) ORDER BY occurred_at`).bind(...evidenceIds).all<Record<string, unknown>>()).results
    : [];
  return { case: auditCase, evidence: evidence.results, custodyEvents };
}

/**
 * Module 4 Phase D AddCaseNote. Notes are only ever INSERTed in this file
 * — there is no UPDATE path anywhere for audit_case_notes. A correction is
 * a fresh note carrying supersedes_note_id; the note it corrects remains
 * exactly as originally written.
 */
export async function addCaseNote(caseId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may add a case note.");
  const input = validateCaseNoteAddition(payload);
  const db = await ensureDatabase();
  const auditCase = await db.prepare("SELECT id,taxpayer_id FROM audit_cases WHERE id=?").bind(caseId).first<{ id: string; taxpayer_id: string }>();
  if (!auditCase) throw new ComplianceResourceError("Audit case was not found.", 404);

  const hash = await sha256Hex(stableStringify({ case_id: caseId, input }));
  const prior = await replay(db, actor.userId, "ADD_CASE_NOTE", key, hash);
  if (prior) return db.prepare("SELECT * FROM audit_case_notes WHERE id=?").bind(prior).first<Record<string, unknown>>();

  if (input.supersedesNoteId) {
    const supersededNote = await db.prepare("SELECT id FROM audit_case_notes WHERE id=? AND audit_case_id=?").bind(input.supersedesNoteId, caseId).first<{ id: string }>();
    if (!supersededNote) throw new ComplianceResourceError("The note being corrected was not found on this case.", 404);
  }

  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("INSERT INTO audit_case_notes (id,audit_case_id,author_id,body,supersedes_note_id,created_at) VALUES (?,?,?,?,?,?)")
      .bind(id, caseId, actor.userId, input.body, input.supersedesNoteId ?? null, now),
    commandRecord(db, actor.userId, "ADD_CASE_NOTE", key, hash, "AUDIT_CASE_NOTE", id, now),
    outbox(db, "AUDIT_CASE_NOTE", id, "AuditCaseNoteAdded", auditCase.taxpayer_id, { note_id: id, audit_case_id: caseId, supersedes_note_id: input.supersedesNoteId ?? null, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "AUDIT_CASE_NOTE_ADDED", "AUDIT_CASE_NOTE", id, { auditCaseId: caseId, supersedesNoteId: input.supersedesNoteId ?? null, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM audit_case_notes WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/** Module 4 Phase D GetCaseNotes. Tenant-scoped exactly like CaseTimeline/GetCaseEvidence. */
export async function getCaseNotes(caseId: string, actor: UserContext) {
  const db = await ensureDatabase();
  const auditCase = await db.prepare("SELECT id,taxpayer_id FROM audit_cases WHERE id=?").bind(caseId).first<{ id: string; taxpayer_id: string }>();
  if (!auditCase) return null;
  if (!isNationalScope(actor) && actor.taxpayerId !== auditCase.taxpayer_id) throw new AccessDeniedError("The audit case is outside your authorised taxpayer scope.");
  const notes = await db.prepare("SELECT * FROM audit_case_notes WHERE audit_case_id=? ORDER BY created_at").bind(caseId).all<Record<string, unknown>>();
  return { case: auditCase, notes: notes.results };
}

type CommunicationThreadRow = {
  id: string; organisation_id: string | null; taxpayer_id: string;
  related_resource_type: string; related_resource_id: string;
  subject: string; classification: string; status: string;
  opened_by: string; opened_at: string; closed_by: string | null; closed_at: string | null; closure_reason: string | null;
};

async function resolveCaseReference(db: D1Database, type: CaseReferenceType, resourceId: string): Promise<{ taxpayer_id: string; organisation_id: string | null }> {
  const query = type === "AUDIT_CASE"
    ? "SELECT taxpayer_id,organisation_id FROM audit_cases WHERE id=?"
    : type === "REFUND_CLAIM"
    ? "SELECT taxpayer_id,organisation_id FROM refund_claims WHERE id=?"
    : "SELECT taxpayer_id,NULL AS organisation_id FROM reconciliation_exceptions WHERE id=?";
  const row = await db.prepare(query).bind(resourceId).first<{ taxpayer_id: string | null; organisation_id: string | null }>();
  if (!row || !row.taxpayer_id) throw new ComplianceResourceError(`The referenced ${type.replaceAll("_", " ").toLowerCase()} was not found.`, 404);
  return { taxpayer_id: row.taxpayer_id, organisation_id: row.organisation_id };
}

/**
 * Module 6 Phase C SendNotice: opens a new correspondence thread for a
 * case reference. Officer-only, mirroring openAuditCase's own
 * restriction. The taxpayer_id and organisation_id are derived from the
 * case reference itself (audit_cases/refund_claims/reconciliation_exceptions
 * each already carry their own taxpayer_id), never caller-supplied, so a
 * notice can never be misdirected to a taxpayer that doesn't match the
 * case it's actually about. Refuses to open a second thread for a
 * reference that already has one — communication_threads' own
 * UNIQUE(related_resource_type, related_resource_id) makes "the
 * conversation about case X" unambiguous; a follow-up message belongs in
 * Respond, not a second thread.
 */
export async function sendNotice(payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may send a notice.");
  const input = validateNotice(payload);
  const db = await ensureDatabase();
  const reference = await resolveCaseReference(db, input.related_resource_type, input.related_resource_id);
  const hash = await sha256Hex(stableStringify(input));
  const prior = await replay(db, actor.userId, "SEND_NOTICE", key, hash);
  if (prior) return db.prepare("SELECT * FROM communication_threads WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const existingThread = await db.prepare("SELECT id FROM communication_threads WHERE related_resource_type=? AND related_resource_id=?")
    .bind(input.related_resource_type, input.related_resource_id).first<{ id: string }>();
  if (existingThread) throw new RepositoryConflictError("A correspondence thread already exists for this case reference; use Respond instead.");

  const threadId = crypto.randomUUID();
  const messageId = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO communication_threads
      (id,organisation_id,taxpayer_id,related_resource_type,related_resource_id,subject,classification,status,opened_by,opened_at,closed_by,closed_at,closure_reason)
      VALUES (?,?,?,?,?,?,?,'OPEN',?,?,NULL,NULL,NULL)`).bind(threadId, reference.organisation_id, reference.taxpayer_id, input.related_resource_type, input.related_resource_id, input.subject, input.classification, actor.userId, now),
    db.prepare(`INSERT INTO communications
      (id,organisation_id,taxpayer_id,thread_id,channel,direction,subject,content_summary,classification,related_resource_type,related_resource_id,external_reference,status,actor_id,occurred_at)
      VALUES (?,?,?,?,?,'OUTBOUND',?,?,?,?,?,NULL,'DELIVERED',?,?)`).bind(messageId, reference.organisation_id, reference.taxpayer_id, threadId, input.channel, input.subject, input.content_summary, input.classification, input.related_resource_type, input.related_resource_id, actor.userId, now),
    ...notificationRecord(db, { taxpayerId: reference.taxpayer_id, notificationType: "NOTICE_RECEIVED", title: input.subject, message: input.content_summary, severity: "MEDIUM", actionUrl: `/communications/${threadId}` }, now),
    commandRecord(db, actor.userId, "SEND_NOTICE", key, hash, "COMMUNICATION_THREAD", threadId, now),
    outbox(db, "COMMUNICATION_THREAD", threadId, "NoticeSent", reference.taxpayer_id, { thread_id: threadId, message_id: messageId, related_resource_type: input.related_resource_type, related_resource_id: input.related_resource_id, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "NOTICE_SENT", "COMMUNICATION_THREAD", threadId, { taxpayerId: reference.taxpayer_id, relatedResourceType: input.related_resource_type, relatedResourceId: input.related_resource_id, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM communication_threads WHERE id=?").bind(threadId).first<Record<string, unknown>>();
}

/**
 * Module 6 Phase C Respond: a reply within an existing thread. Reachable by
 * either the NamRA side (communications:manage) or the taxpayer side
 * (communications:respond, scoped to their own taxpayer) — the route-level
 * gate is deliberately broad (compliance:read) with the real rule enforced
 * here, the same layered-permission pattern already used throughout this
 * file (e.g. RECORD_EVIDENCE_CUSTODY_EVENT's route gate is cases:manage,
 * but recordEvidenceCustodyEvent itself does the finer isNationalScope
 * check). Direction is derived from the actor, never caller-supplied.
 */
export async function respondToConversation(threadId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!hasPermission(actor, "communications:manage") && !hasPermission(actor, "communications:respond")) {
    throw new AccessDeniedError("You do not have permission to respond to this correspondence.");
  }
  const input = validateConversationResponse(payload);
  const db = await ensureDatabase();
  const thread = await db.prepare("SELECT * FROM communication_threads WHERE id=?").bind(threadId).first<CommunicationThreadRow>();
  if (!thread) throw new ComplianceResourceError("Correspondence thread was not found.", 404);
  if (!isNationalScope(actor) && actor.taxpayerId !== thread.taxpayer_id) throw new AccessDeniedError("The correspondence thread is outside your authorised taxpayer scope.");

  const hash = await sha256Hex(stableStringify({ thread_id: threadId, input }));
  const prior = await replay(db, actor.userId, "RESPOND_TO_CONVERSATION", key, hash);
  if (prior) return db.prepare("SELECT * FROM communications WHERE id=?").bind(prior).first<Record<string, unknown>>();

  if (thread.status !== "OPEN") throw new RepositoryConflictError("This correspondence thread is closed and cannot accept a new reply.");

  const direction = isNationalScope(actor) ? "OUTBOUND" : "INBOUND";
  const messageId = crypto.randomUUID();
  const now = new Date().toISOString();
  const statements: D1PreparedStatement[] = [
    db.prepare(`INSERT INTO communications
      (id,organisation_id,taxpayer_id,thread_id,channel,direction,subject,content_summary,classification,related_resource_type,related_resource_id,external_reference,status,actor_id,occurred_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,'DELIVERED',?,?)`).bind(messageId, thread.organisation_id, thread.taxpayer_id, threadId, input.channel, direction, thread.subject, input.content_summary, thread.classification, thread.related_resource_type, thread.related_resource_id, actor.userId, now),
    commandRecord(db, actor.userId, "RESPOND_TO_CONVERSATION", key, hash, "COMMUNICATION", messageId, now),
    outbox(db, "COMMUNICATION_THREAD", threadId, "ConversationResponded", thread.taxpayer_id, { thread_id: threadId, message_id: messageId, direction, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "CONVERSATION_RESPONDED", "COMMUNICATION_THREAD", threadId, { taxpayerId: thread.taxpayer_id, direction, correlationId }, now),
  ];
  if (direction === "OUTBOUND") {
    statements.push(...notificationRecord(db, { taxpayerId: thread.taxpayer_id, notificationType: "NOTICE_RECEIVED", title: `Reply on: ${thread.subject}`, message: input.content_summary, severity: "MEDIUM", actionUrl: `/communications/${threadId}` }, now));
  }
  await db.batch(statements);
  return db.prepare("SELECT * FROM communications WHERE id=?").bind(messageId).first<Record<string, unknown>>();
}

/** Module 6 Phase C CloseConversation. Officer-only, mirroring SendNotice's own restriction. */
export async function closeConversation(threadId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may close a correspondence thread.");
  const input = validateConversationClosure(payload);
  const db = await ensureDatabase();
  const thread = await db.prepare("SELECT * FROM communication_threads WHERE id=?").bind(threadId).first<CommunicationThreadRow>();
  if (!thread) throw new ComplianceResourceError("Correspondence thread was not found.", 404);

  const hash = await sha256Hex(stableStringify({ thread_id: threadId, input }));
  const prior = await replay(db, actor.userId, "CLOSE_CONVERSATION", key, hash);
  if (prior) return db.prepare("SELECT * FROM communication_threads WHERE id=?").bind(prior).first<Record<string, unknown>>();

  if (thread.status !== "OPEN") throw new RepositoryConflictError("This correspondence thread is already closed.");

  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE communication_threads SET status='CLOSED',closed_by=?,closed_at=?,closure_reason=? WHERE id=? AND status='OPEN'").bind(actor.userId, now, input.reason, threadId),
    commandRecord(db, actor.userId, "CLOSE_CONVERSATION", key, hash, "COMMUNICATION_THREAD", threadId, now),
    outbox(db, "COMMUNICATION_THREAD", threadId, "ConversationClosed", thread.taxpayer_id, { thread_id: threadId, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "CONVERSATION_CLOSED", "COMMUNICATION_THREAD", threadId, { taxpayerId: thread.taxpayer_id, reason: input.reason, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM communication_threads WHERE id=?").bind(threadId).first<Record<string, unknown>>();
}

/**
 * Module 6 Phase C GetInbox: lists correspondence threads — not raw
 * messages — each with its latest message preview and message count. A
 * real, filterable, paginated search with a genuine total_count,
 * distinct from getComplianceSnapshot's own flat, unfiltered
 * "communications" projection (still used as-is by existing dashboard
 * reads).
 */
export async function getInbox(actor: UserContext, params: URLSearchParams) {
  const db = await ensureDatabase();
  const query = normalizeInboxQuery(params);
  const conditions: string[] = [];
  const values: unknown[] = [];
  if (!isNationalScope(actor)) {
    conditions.push("t.taxpayer_id = ?");
    values.push(actor.taxpayerId ?? "__none__");
  } else if (query.taxpayerId) {
    conditions.push("t.taxpayer_id = ?");
    values.push(query.taxpayerId);
  }
  if (query.status) {
    conditions.push("t.status = ?");
    values.push(query.status);
  }
  if (query.relatedResourceType) {
    conditions.push("t.related_resource_type = ?");
    values.push(query.relatedResourceType);
  }
  const whereClause = conditions.length ? `WHERE ${conditions.join(" AND ")}` : "";

  const [threads, count] = await Promise.all([
    db.prepare(`SELECT t.*,
      (SELECT content_summary FROM communications c WHERE c.thread_id=t.id ORDER BY c.occurred_at DESC LIMIT 1) AS latest_message,
      (SELECT occurred_at FROM communications c WHERE c.thread_id=t.id ORDER BY c.occurred_at DESC LIMIT 1) AS latest_message_at,
      (SELECT COUNT(*) FROM communications c WHERE c.thread_id=t.id) AS message_count
      FROM communication_threads t ${whereClause}
      ORDER BY latest_message_at DESC
      LIMIT ? OFFSET ?`).bind(...values, query.limit, query.offset).all<Record<string, string | number | null>>(),
    db.prepare(`SELECT COUNT(*) AS n FROM communication_threads t ${whereClause}`).bind(...values).first<{ n: number }>(),
  ]);
  return { threads: threads.results, total_count: count?.n ?? 0, limit: query.limit, offset: query.offset };
}

/** Module 6 Phase C: reads one full correspondence thread, oldest message first. Same tenant-visibility rule as CaseTimeline. */
export async function getConversation(threadId: string, actor: UserContext) {
  const db = await ensureDatabase();
  const thread = await db.prepare("SELECT * FROM communication_threads WHERE id=?").bind(threadId).first<CommunicationThreadRow>();
  if (!thread) return null;
  if (!isNationalScope(actor) && actor.taxpayerId !== thread.taxpayer_id) throw new AccessDeniedError("The correspondence thread is outside your authorised taxpayer scope.");
  const messages = await db.prepare("SELECT * FROM communications WHERE thread_id=? ORDER BY occurred_at").bind(threadId).all<Record<string, unknown>>();
  return { thread, messages: messages.results };
}

/**
 * Module 6 Phase D Queue: the standalone command a caller can reach
 * directly, distinct from the five existing commands that queue a
 * notification only as their own side effect (via notificationRecord,
 * above). Officer-only, matching every existing trigger of a notification
 * in this codebase today. Only meaningfully personalizable when addressed
 * to a specific user_id: notification_preferences is keyed by user, so a
 * taxpayer-wide notification (no user_id) has no single user's preference
 * to check and always attempts every requested channel. IN_APP is never
 * filtered by preference, for the same reason notificationRecord's own
 * comment gives: the notifications row itself *is* that channel.
 */
export async function queueNotification(payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national compliance role may queue a notification directly.");
  const input = validateNotificationQueue(payload);
  const db = await ensureDatabase();
  if (input.user_id) {
    const user = await db.prepare("SELECT id FROM app_users WHERE id=? AND status='ACTIVE'").bind(input.user_id).first<{ id: string }>();
    if (!user) throw new ComplianceResourceError("The target user was not found or is not active.", 404);
  }
  if (input.taxpayer_id) {
    const taxpayer = await db.prepare("SELECT id FROM taxpayers WHERE id=?").bind(input.taxpayer_id).first<{ id: string }>();
    if (!taxpayer) throw new ComplianceResourceError("The target taxpayer was not found.", 404);
  }
  const hash = await sha256Hex(stableStringify(input));
  const prior = await replay(db, actor.userId, "QUEUE_NOTIFICATION", key, hash);
  if (prior) return db.prepare("SELECT * FROM notifications WHERE id=?").bind(prior).first<Record<string, unknown>>();

  let channelsToAttempt = input.channels;
  if (input.user_id) {
    const disabled = await db.prepare("SELECT channel FROM notification_preferences WHERE user_id=? AND enabled=0").bind(input.user_id).all<{ channel: string }>();
    const disabledSet = new Set(disabled.results.map((row) => row.channel));
    channelsToAttempt = input.channels.filter((channel) => channel === "IN_APP" || !disabledSet.has(channel));
  }

  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const statements: D1PreparedStatement[] = [
    db.prepare(`INSERT INTO notifications
      (id,user_id,taxpayer_id,notification_type,title,message,severity,status,action_url,created_at,read_at,cancelled_by,cancelled_at,cancellation_reason)
      VALUES (?,?,?,?,?,?,?,'UNREAD',?,?,NULL,NULL,NULL,NULL)`).bind(id, input.user_id ?? null, input.taxpayer_id ?? null, input.notification_type, input.title, input.message, input.severity, input.action_url ?? null, now),
  ];
  for (const channel of channelsToAttempt) {
    statements.push(db.prepare("INSERT INTO notification_deliveries (id,notification_id,channel,status,attempted_at) VALUES (?,?,?,'QUEUED',?)").bind(crypto.randomUUID(), id, channel, now));
  }
  statements.push(
    commandRecord(db, actor.userId, "QUEUE_NOTIFICATION", key, hash, "NOTIFICATION", id, now),
    outbox(db, "NOTIFICATION", id, "NotificationQueued", input.taxpayer_id ?? "SYSTEM", { notification_id: id, channels: channelsToAttempt, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "NOTIFICATION_QUEUED", "NOTIFICATION", id, { userId: input.user_id, taxpayerId: input.taxpayer_id, channels: channelsToAttempt, correlationId }, now),
  );
  await db.batch(statements);
  return db.prepare("SELECT * FROM notifications WHERE id=?").bind(id).first<Record<string, unknown>>();
}

type NotificationRow = { id: string; user_id: string | null; taxpayer_id: string | null; status: string };

function requireNotificationScope(actor: UserContext, notification: NotificationRow) {
  if (isNationalScope(actor)) return;
  if (actor.userId === notification.user_id) return;
  if (notification.taxpayer_id && actor.taxpayerId === notification.taxpayer_id) return;
  throw new AccessDeniedError("The notification is outside your authorised scope.");
}

/** Module 6 Phase D CancelNotification: withdraws a still-UNREAD notification. Reachable by the actor who could see it in the first place — see requireNotificationScope. */
export async function cancelNotification(notificationId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateNotificationCancellation(payload);
  const db = await ensureDatabase();
  const notification = await db.prepare("SELECT * FROM notifications WHERE id=?").bind(notificationId).first<NotificationRow>();
  if (!notification) throw new ComplianceResourceError("Notification was not found.", 404);
  requireNotificationScope(actor, notification);

  const hash = await sha256Hex(stableStringify({ notification_id: notificationId, input }));
  const prior = await replay(db, actor.userId, "CANCEL_NOTIFICATION", key, hash);
  if (prior) return db.prepare("SELECT * FROM notifications WHERE id=?").bind(prior).first<Record<string, unknown>>();

  if (notification.status !== "UNREAD") throw new RepositoryConflictError("Only an unread notification can be cancelled.");
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE notifications SET status='CANCELLED',cancelled_by=?,cancelled_at=?,cancellation_reason=? WHERE id=? AND status='UNREAD'").bind(actor.userId, now, input.reason, notificationId),
    commandRecord(db, actor.userId, "CANCEL_NOTIFICATION", key, hash, "NOTIFICATION", notificationId, now),
    outbox(db, "NOTIFICATION", notificationId, "NotificationCancelled", notification.taxpayer_id ?? "SYSTEM", { notification_id: notificationId, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "NOTIFICATION_CANCELLED", "NOTIFICATION", notificationId, { reason: input.reason, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM notifications WHERE id=?").bind(notificationId).first<Record<string, unknown>>();
}

/**
 * Module 6 Phase D: marks a notification read. Same tenant-visibility rule
 * as CancelNotification. read_at was previously never written by anything
 * anywhere in this codebase. Re-marking an already-read notification is a
 * harmless no-op rather than a conflict — the same "idempotent under a
 * fresh key too" posture Module 3's MarkSatisfied already established for
 * an already-satisfied obligation.
 */
export async function markNotificationRead(notificationId: string, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const db = await ensureDatabase();
  const notification = await db.prepare("SELECT * FROM notifications WHERE id=?").bind(notificationId).first<NotificationRow>();
  if (!notification) throw new ComplianceResourceError("Notification was not found.", 404);
  requireNotificationScope(actor, notification);

  const hash = await sha256Hex(stableStringify({ notification_id: notificationId }));
  const prior = await replay(db, actor.userId, "MARK_NOTIFICATION_READ", key, hash);
  if (prior) return db.prepare("SELECT * FROM notifications WHERE id=?").bind(prior).first<Record<string, unknown>>();

  if (notification.status === "CANCELLED") throw new RepositoryConflictError("A cancelled notification cannot be marked read.");
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE notifications SET status='READ',read_at=COALESCE(read_at,?) WHERE id=? AND status='UNREAD'").bind(now, notificationId),
    commandRecord(db, actor.userId, "MARK_NOTIFICATION_READ", key, hash, "NOTIFICATION", notificationId, now),
    await auditRecord(db, actor, "NOTIFICATION_READ", "NOTIFICATION", notificationId, { correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM notifications WHERE id=?").bind(notificationId).first<Record<string, unknown>>();
}

/** Module 6 Phase D UpdatePreference: upserts a user's own channel preference. Self-service — every actor manages only their own row (keyed by actor.userId, never a caller-supplied user id). */
export async function updateNotificationPreference(payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateNotificationPreference(payload);
  const db = await ensureDatabase();
  const hash = await sha256Hex(stableStringify({ user_id: actor.userId, input }));
  const prior = await replay(db, actor.userId, "UPDATE_NOTIFICATION_PREFERENCE", key, hash);
  if (prior) return db.prepare("SELECT * FROM notification_preferences WHERE user_id=? AND channel=?").bind(actor.userId, input.channel).first<Record<string, unknown>>();

  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO notification_preferences (id,user_id,channel,enabled,updated_at) VALUES (?,?,?,?,?)
      ON CONFLICT(user_id,channel) DO UPDATE SET enabled=excluded.enabled,updated_at=excluded.updated_at`).bind(crypto.randomUUID(), actor.userId, input.channel, input.enabled ? 1 : 0, now),
    commandRecord(db, actor.userId, "UPDATE_NOTIFICATION_PREFERENCE", key, hash, "NOTIFICATION_PREFERENCE", actor.userId, now),
    await auditRecord(db, actor, "NOTIFICATION_PREFERENCE_UPDATED", "NOTIFICATION_PREFERENCE", actor.userId, { channel: input.channel, enabled: input.enabled, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM notification_preferences WHERE user_id=? AND channel=?").bind(actor.userId, input.channel).first<Record<string, unknown>>();
}

/** Module 6 Phase D GetNotifications: a dedicated, filterable, paginated read — previously only bundled inside getComplianceSnapshot's own fixed, unfiltered 100-row projection. */
export async function getNotifications(actor: UserContext, params: URLSearchParams) {
  const db = await ensureDatabase();
  const query = normalizeNotificationQuery(params);
  const conditions: string[] = [];
  const values: unknown[] = [];
  if (!isNationalScope(actor)) {
    conditions.push("(user_id = ? OR taxpayer_id = ?)");
    values.push(actor.userId, actor.taxpayerId ?? "__none__");
  }
  if (query.status) {
    conditions.push("status = ?");
    values.push(query.status);
  }
  if (query.severity) {
    conditions.push("severity = ?");
    values.push(query.severity);
  }
  const whereClause = conditions.length ? `WHERE ${conditions.join(" AND ")}` : "";
  const [notifications, count] = await Promise.all([
    db.prepare(`SELECT * FROM notifications ${whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?`).bind(...values, query.limit, query.offset).all<Record<string, unknown>>(),
    db.prepare(`SELECT COUNT(*) AS n FROM notifications ${whereClause}`).bind(...values).first<{ n: number }>(),
  ]);
  return { notifications: notifications.results, total_count: count?.n ?? 0, limit: query.limit, offset: query.offset };
}
