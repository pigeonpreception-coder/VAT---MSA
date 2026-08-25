export type ComplianceValidationMessage = { code: string; path: string; message: string };

export class ComplianceValidationError extends Error {
  readonly messages: ComplianceValidationMessage[];

  constructor(messages: ComplianceValidationMessage[]) {
    super("Compliance command failed validation.");
    this.name = "ComplianceValidationError";
    this.messages = messages;
  }
}

export type CaseOpeningSubmission = {
  schema_version: "1.0.0";
  taxpayer_id: string;
  case_type: "DESK_REVIEW" | "VAT_AUDIT" | "REFUND_VERIFICATION" | "INVESTIGATION";
  title: string;
  opening_reason: string;
  risk_tier: "LOW" | "MEDIUM" | "HIGH" | "CRITICAL";
};

export type DisputeSubmission = {
  schema_version: "1.0.0";
  taxpayer_id?: string;
  audit_case_id?: string;
  disputed_resource_type: "AUDIT_FINDING" | "VAT_RETURN" | "REFUND_DECISION" | "OBLIGATION";
  disputed_resource_id: string;
  grounds: string;
  disputed_amount_cents: number;
  currency: string;
};

export type RefundRequestSubmission = {
  schema_version: "1.0.0";
  vat_return_version_id: string;
};

export type RefundReviewSubmission = {
  schema_version: "1.0.0";
  stage: "EVIDENCE" | "RISK" | "SUPERVISOR" | "PAYMENT_AUTHORISATION";
  decision: "APPROVE" | "REJECT" | "REQUEST_INFORMATION" | "HOLD";
  findings: string;
};

const ID_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/;
const CURRENCY_PATTERN = /^[A-Z]{3}$/;
const CASE_TYPES = new Set(["DESK_REVIEW", "VAT_AUDIT", "REFUND_VERIFICATION", "INVESTIGATION"]);

function object(payload: unknown) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new ComplianceValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be an object." }]);
  return payload as Record<string, unknown>;
}

function text(value: unknown) {
  return typeof value === "string" ? value.trim().replaceAll(/\s+/g, " ") : "";
}

function id(value: unknown, path: string, messages: ComplianceValidationMessage[], optional = false) {
  const normalized = text(value);
  if (!normalized && optional) return undefined;
  if (!ID_PATTERN.test(normalized)) messages.push({ code: "IDENTIFIER_INVALID", path, message: "Identifier is invalid." });
  return normalized;
}

function bounded(value: unknown, path: string, label: string, min: number, max: number, messages: ComplianceValidationMessage[]) {
  const normalized = text(value);
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

/** Like bounded(), but absent entirely is fine — only a present-and-too-short/too-long value is rejected. */
function optionalBounded(value: unknown, path: string, label: string, min: number, max: number, messages: ComplianceValidationMessage[]) {
  const normalized = text(value);
  if (!normalized) return undefined;
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

function schema(input: Record<string, unknown>, messages: ComplianceValidationMessage[]) {
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
}

export function validateCaseOpening(payload: unknown): CaseOpeningSubmission {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const taxpayerId = id(input.taxpayer_id, "/taxpayer_id", messages) ?? "";
  const caseType = text(input.case_type).toUpperCase() as CaseOpeningSubmission["case_type"];
  if (!CASE_TYPES.has(caseType)) messages.push({ code: "CASE_TYPE_INVALID", path: "/case_type", message: "Select a supported case type." });
  const title = bounded(input.title, "/title", "Title", 5, 200, messages);
  const openingReason = bounded(input.opening_reason, "/opening_reason", "Opening reason", 20, 2_000, messages);
  const riskTier = text(input.risk_tier).toUpperCase() as CaseOpeningSubmission["risk_tier"];
  if (!new Set(["LOW", "MEDIUM", "HIGH", "CRITICAL"]).has(riskTier)) messages.push({ code: "RISK_TIER_INVALID", path: "/risk_tier", message: "Select a supported risk tier." });
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", taxpayer_id: taxpayerId, case_type: caseType, title, opening_reason: openingReason, risk_tier: riskTier };
}

/**
 * Module 4 Phase C: the audit case lifecycle state machine. A 2026-08-25
 * code assessment found audit_cases.status was a bare string that was never
 * updated after creation — every case was permanently stuck at 'OPEN', with
 * no Assign/IssueFinding/CloseCase command anywhere. This is a real
 * adjacency-list state machine (mirrors the LICENSE_STATE_TRANSITIONS
 * pattern already used for licence lifecycle in lib/domain/control-plane.ts),
 * not scattered status-string checks: every legal (status, action) pair is
 * enumerated once, here, and lib/data/compliance-repository.ts's
 * transitionCase is the single code path that can ever change a case's
 * status.
 *
 * The playbook names Create/Assign/IssueFinding/CloseCase plus
 * Suspended/Reopened/AppealLinked/Cancelled as "controlled side-transitions"
 * — rather than building eight nearly-identical bespoke commands, all state
 * changes go through this one shared transition table and one shared
 * transitionCase function, which is arguably a more genuine state machine
 * than eight separate ones would be. IssueFinding and CaseTimeline remain
 * their own distinct operations (issuing a finding doesn't change the
 * case's own status; reading the timeline changes nothing at all).
 *
 * Deliberately NOT included here: any segregation-of-duties check (can the
 * same actor who opened/referred a case also close it or issue its
 * finding). That is Module 4 Phase E, a distinct, separately-scoped piece
 * of work with its own "logged exceptional-oversight override path"
 * requirement — adding a partial version of it here would preempt that.
 */
export type AuditCaseStatus =
  | "PROPOSED" | "AUTHORIZED" | "ASSIGNED" | "PLANNING" | "EVIDENCE_COLLECTION"
  | "ANALYSIS" | "TAXPAYER_RESPONSE" | "FINDINGS_REVIEW" | "DECISION" | "CLOSED"
  | "SUSPENDED" | "CANCELLED";

export type AuditCaseAction = "AUTHORIZE" | "ASSIGN" | "ADVANCE" | "SUSPEND" | "RESUME" | "CANCEL" | "REOPEN" | "CLOSE" | "LINK_APPEAL";

const CASE_ACTIONS: readonly AuditCaseAction[] = ["AUTHORIZE", "ASSIGN", "ADVANCE", "SUSPEND", "RESUME", "CANCEL", "REOPEN", "CLOSE", "LINK_APPEAL"];

/**
 * `to: null` marks RESUME as dynamic — its real target is whatever status
 * the case was suspended *from*, which only the repository layer (reading
 * the case row) can resolve. Every other action has one fixed, statically
 * known target.
 */
const CASE_TRANSITIONS: Record<AuditCaseStatus, Partial<Record<AuditCaseAction, AuditCaseStatus | null>>> = {
  PROPOSED: { AUTHORIZE: "AUTHORIZED", CANCEL: "CANCELLED" },
  AUTHORIZED: { ASSIGN: "ASSIGNED", CANCEL: "CANCELLED" },
  ASSIGNED: { ADVANCE: "PLANNING", SUSPEND: "SUSPENDED" },
  PLANNING: { ADVANCE: "EVIDENCE_COLLECTION", SUSPEND: "SUSPENDED" },
  EVIDENCE_COLLECTION: { ADVANCE: "ANALYSIS", SUSPEND: "SUSPENDED" },
  ANALYSIS: { ADVANCE: "TAXPAYER_RESPONSE", SUSPEND: "SUSPENDED" },
  TAXPAYER_RESPONSE: { ADVANCE: "FINDINGS_REVIEW", SUSPEND: "SUSPENDED" },
  FINDINGS_REVIEW: { ADVANCE: "DECISION", SUSPEND: "SUSPENDED" },
  DECISION: { CLOSE: "CLOSED", SUSPEND: "SUSPENDED" },
  SUSPENDED: { RESUME: null },
  CLOSED: { REOPEN: "FINDINGS_REVIEW", LINK_APPEAL: "CLOSED" },
  CANCELLED: {},
};

/** Validates the (status, action) pair is legal and returns the static target status, or null if the target is dynamic (RESUME only — see CASE_TRANSITIONS). */
export function assertCaseTransition(action: AuditCaseAction, currentStatus: AuditCaseStatus): AuditCaseStatus | null {
  const rule = CASE_TRANSITIONS[currentStatus];
  if (!rule || !(action in rule)) {
    throw new ComplianceValidationError([{ code: "CASE_TRANSITION_INVALID", path: "/action", message: `Cannot ${action.replaceAll("_", " ").toLowerCase()} a case currently ${currentStatus}.` }]);
  }
  return rule[action] ?? null;
}

export type CaseTransitionInput = { schema_version: "1.0.0"; action: AuditCaseAction; reason: string; officerId?: string; appealReference?: string; overrideReason?: string };

/**
 * override_reason is always optional at this layer — whether it's actually
 * *required* depends on whether the acting officer is also the case's own
 * opener, which only the repository (which has the case row) can know. See
 * lib/data/compliance-repository.ts's enforceSegregationOfDuties (Module 4
 * Phase E): CLOSE is the one transition action it gates.
 */
export function validateCaseTransition(payload: unknown): CaseTransitionInput {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const action = text(input.action).toUpperCase() as AuditCaseAction;
  if (!CASE_ACTIONS.includes(action)) messages.push({ code: "ACTION_INVALID", path: "/action", message: `action must be one of: ${CASE_ACTIONS.join(", ")}.` });
  const reason = bounded(input.reason, "/reason", "Reason", 10, 2_000, messages);
  const officerId = action === "ASSIGN" ? id(input.officer_id, "/officer_id", messages) : undefined;
  const appealReference = action === "LINK_APPEAL" ? bounded(input.appeal_reference, "/appeal_reference", "Appeal reference", 3, 100, messages) : undefined;
  const overrideReason = optionalBounded(input.override_reason, "/override_reason", "Override reason", 10, 2_000, messages);
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", action, reason, ...(officerId ? { officerId } : {}), ...(appealReference ? { appealReference } : {}), ...(overrideReason ? { overrideReason } : {}) };
}

export type FindingIssuance = {
  schema_version: "1.0.0";
  finding_code: string;
  title: string;
  description: string;
  legal_reference?: string;
  amount_cents: number;
  currency: string;
  overrideReason?: string;
};

/**
 * Module 4 Phase C IssueFinding — a sub-resource creation, not a case-status
 * transition, so it's validated and committed separately from
 * validateCaseTransition above. override_reason is the same Module 4 Phase E
 * segregation-of-duties override as CaseTransitionInput's, optional here for
 * the same reason: only the repository knows whether it's actually required.
 */
export function validateFindingIssuance(payload: unknown): FindingIssuance {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const findingCode = id(input.finding_code, "/finding_code", messages) ?? "";
  const title = bounded(input.title, "/title", "Title", 5, 200, messages);
  const description = bounded(input.description, "/description", "Description", 20, 4_000, messages);
  const legalReference = text(input.legal_reference) || undefined;
  const amount = Number(input.amount_cents);
  if (!Number.isSafeInteger(amount) || amount < 0) messages.push({ code: "AMOUNT_INVALID", path: "/amount_cents", message: "amount_cents must be a non-negative safe integer." });
  const currency = text(input.currency).toUpperCase();
  if (!CURRENCY_PATTERN.test(currency)) messages.push({ code: "CURRENCY_INVALID", path: "/currency", message: "Currency must be a three-letter ISO 4217 code." });
  const overrideReason = optionalBounded(input.override_reason, "/override_reason", "Override reason", 10, 2_000, messages);
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", finding_code: findingCode, title, description, ...(legalReference ? { legal_reference: legalReference } : {}), amount_cents: amount, currency, ...(overrideReason ? { overrideReason } : {}) };
}

export function validateDispute(payload: unknown): DisputeSubmission {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const taxpayerId = id(input.taxpayer_id, "/taxpayer_id", messages, true);
  const auditCaseId = id(input.audit_case_id, "/audit_case_id", messages, true);
  const resourceType = text(input.disputed_resource_type).toUpperCase() as DisputeSubmission["disputed_resource_type"];
  if (!new Set(["AUDIT_FINDING", "VAT_RETURN", "REFUND_DECISION", "OBLIGATION"]).has(resourceType)) messages.push({ code: "RESOURCE_TYPE_INVALID", path: "/disputed_resource_type", message: "Select a supported disputed resource type." });
  const resourceId = id(input.disputed_resource_id, "/disputed_resource_id", messages) ?? "";
  const grounds = bounded(input.grounds, "/grounds", "Grounds", 20, 4_000, messages);
  const amount = Number(input.disputed_amount_cents);
  if (!Number.isSafeInteger(amount) || amount < 0) messages.push({ code: "AMOUNT_INVALID", path: "/disputed_amount_cents", message: "Disputed amount cents must be a non-negative safe integer." });
  const currency = text(input.currency).toUpperCase();
  if (!CURRENCY_PATTERN.test(currency)) messages.push({ code: "CURRENCY_INVALID", path: "/currency", message: "Currency must be a three-letter ISO 4217 code." });
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", ...(taxpayerId ? { taxpayer_id: taxpayerId } : {}), ...(auditCaseId ? { audit_case_id: auditCaseId } : {}), disputed_resource_type: resourceType, disputed_resource_id: resourceId, grounds, disputed_amount_cents: amount, currency };
}

export function validateRefundRequest(payload: unknown): RefundRequestSubmission {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const versionId = id(input.vat_return_version_id, "/vat_return_version_id", messages) ?? "";
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", vat_return_version_id: versionId };
}

export type ObligationCreation = {
  schema_version: "1.0.0";
  taxpayer_id: string;
  obligation_type: string;
  period_code: string;
  due_date: string;
  amount_cents: number;
  currency: string;
};

export type ObligationSatisfaction = {
  schema_version: "1.0.0";
  notes: string;
};

const OBLIGATION_TYPE_PATTERN = /^[A-Z][A-Z0-9_]{1,49}$/;
const PERIOD_CODE_PATTERN = /^\d{4}-\d{2}$/;
const DUE_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Module 3 Phase D CreateObligation. Statutory obligations are imposed by
 * NamRA, not self-declared, so taxpayer_id is required (unlike Dispute's
 * optional taxpayer_id, which taxpayers can self-file) and the repository
 * layer restricts this to national-scope actors only.
 */
export function validateObligationCreation(payload: unknown): ObligationCreation {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const taxpayerId = id(input.taxpayer_id, "/taxpayer_id", messages) ?? "";
  const obligationType = text(input.obligation_type).toUpperCase();
  if (!OBLIGATION_TYPE_PATTERN.test(obligationType)) messages.push({ code: "OBLIGATION_TYPE_INVALID", path: "/obligation_type", message: "obligation_type must contain 2 to 50 uppercase letters, numbers or underscores." });
  const periodCode = text(input.period_code);
  if (!PERIOD_CODE_PATTERN.test(periodCode)) messages.push({ code: "PERIOD_CODE_INVALID", path: "/period_code", message: "period_code must use YYYY-MM." });
  const dueDate = text(input.due_date);
  if (!DUE_DATE_PATTERN.test(dueDate)) messages.push({ code: "DUE_DATE_INVALID", path: "/due_date", message: "due_date must use YYYY-MM-DD." });
  const amount = Number(input.amount_cents);
  if (!Number.isSafeInteger(amount) || amount < 0) messages.push({ code: "AMOUNT_INVALID", path: "/amount_cents", message: "amount_cents must be a non-negative safe integer." });
  const currency = text(input.currency).toUpperCase();
  if (!CURRENCY_PATTERN.test(currency)) messages.push({ code: "CURRENCY_INVALID", path: "/currency", message: "Currency must be a three-letter ISO 4217 code." });
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", taxpayer_id: taxpayerId, obligation_type: obligationType, period_code: periodCode, due_date: dueDate, amount_cents: amount, currency };
}

/** Module 3 Phase D MarkSatisfied: { notes }. Restricted to national-scope actors — a taxpayer cannot self-declare their own obligation satisfied. */
export function validateObligationSatisfaction(payload: unknown): ObligationSatisfaction {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const notes = bounded(input.notes, "/notes", "Notes", 10, 2_000, messages);
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", notes };
}

export function validateRefundReview(payload: unknown): RefundReviewSubmission {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const stage = text(input.stage).toUpperCase() as RefundReviewSubmission["stage"];
  if (!new Set(["EVIDENCE", "RISK", "SUPERVISOR", "PAYMENT_AUTHORISATION"]).has(stage)) messages.push({ code: "REVIEW_STAGE_INVALID", path: "/stage", message: "Select a supported review stage." });
  const decision = text(input.decision).toUpperCase() as RefundReviewSubmission["decision"];
  if (!new Set(["APPROVE", "REJECT", "REQUEST_INFORMATION", "HOLD"]).has(decision)) messages.push({ code: "REVIEW_DECISION_INVALID", path: "/decision", message: "Select a supported review decision." });
  const findings = bounded(input.findings, "/findings", "Findings", 10, 2_000, messages);
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", stage, decision, findings };
}

/**
 * Module 4 Phase B: the human-authorisation gate between a risk indicator
 * and an audit case. risk_indicators already existed in the schema (a
 * Phase A data-model anchor), but no application code had ever written to
 * it — EvaluateRisk, the rule engine that would raise new indicators, is
 * still Module 4 Phase A and deliberately out of scope here. This phase
 * builds only the two commands the playbook names for the gate itself:
 * AssignReview and ApproveAction. ApproveAction is the ONLY path in this
 * codebase that may turn a risk signal into an AuditCase — nothing here
 * or anywhere else auto-creates one as a side effect of evaluation.
 */
export type RiskIndicatorStatus = "OPEN" | "UNDER_REVIEW" | "ESCALATED_TO_CASE" | "DISMISSED";

export type RiskReviewAssignment = { schema_version: "1.0.0"; officerId: string };

export function validateRiskReviewAssignment(payload: unknown): RiskReviewAssignment {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const officerId = id(input.officer_id, "/officer_id", messages) ?? "";
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", officerId };
}

export type RiskActionApproval =
  | { schema_version: "1.0.0"; decision: "DISMISS"; rationale: string }
  | { schema_version: "1.0.0"; decision: "ESCALATE_TO_CASE"; rationale: string; caseType: CaseOpeningSubmission["case_type"]; caseTitle: string };

/**
 * ApproveAction. DISMISS only needs a recorded rationale. ESCALATE_TO_CASE
 * additionally needs case_type/case_title — the resulting case's risk_tier
 * and opening_reason are deliberately NOT taken from this payload: the
 * repository derives risk_tier from the indicator's own severity and
 * opening_reason from this decision's rationale, so every escalated case
 * stays traceable to the exact evidence and human judgement that raised it.
 */
export function validateRiskActionApproval(payload: unknown): RiskActionApproval {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const decision = text(input.decision).toUpperCase();
  if (!new Set(["ESCALATE_TO_CASE", "DISMISS"]).has(decision)) messages.push({ code: "DECISION_INVALID", path: "/decision", message: "decision must be ESCALATE_TO_CASE or DISMISS." });
  const rationale = bounded(input.rationale, "/rationale", "Rationale", 20, 2_000, messages);
  if (decision === "ESCALATE_TO_CASE") {
    const caseType = text(input.case_type).toUpperCase() as CaseOpeningSubmission["case_type"];
    if (!CASE_TYPES.has(caseType)) messages.push({ code: "CASE_TYPE_INVALID", path: "/case_type", message: "Select a supported case type." });
    const caseTitle = bounded(input.case_title, "/case_title", "Case title", 5, 200, messages);
    if (messages.length) throw new ComplianceValidationError(messages);
    return { schema_version: "1.0.0", decision: "ESCALATE_TO_CASE", rationale, caseType, caseTitle };
  }
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", decision: "DISMISS", rationale };
}

/**
 * Module 4 Phase A: EvaluateRisk and GetRestrictedRisk. RiskIndicator
 * itself and its OPEN/UNDER_REVIEW/ESCALATED_TO_CASE/DISMISSED lifecycle
 * already exist (Phase B built the human gate that consumes it); this
 * phase builds the engine that raises new indicators and the restricted
 * query that reads them back.
 *
 * Two deliberate scope decisions, both because the domain catalog names
 * concepts the data dictionary never gives distinct fields to:
 *  - "ModelVersion" is NOT a separate governed database table here. At
 *    pilot scale the rule catalogue is a small, fixed, code-versioned set
 *    (see rule_version on every raised indicator) — the same way a git
 *    commit versions any other deployed logic. Module 2's vat_rules
 *    earned a real maker-checker propose/approve workflow because VAT
 *    rate changes are a live regulatory event officers must action; risk
 *    thresholds at this stage are not. Revisit if/when NamRA needs
 *    officer-editable risk-rule proposals the same way.
 *  - "RiskCase" is NOT a second aggregate alongside RiskIndicator. A risk
 *    indicator's own review lifecycle (Phase B) already IS the reviewable
 *    "case" for a risk signal, and escalation produces a real AuditCase —
 *    a parallel RiskCase table would duplicate that with no distinct
 *    fields to justify it.
 */
export type RiskEvaluationRequest = { schema_version: "1.0.0" };

export function validateRiskEvaluationRequest(payload: unknown): RiskEvaluationRequest {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0" };
}

const RISK_INDICATOR_STATUSES = ["OPEN", "UNDER_REVIEW", "ESCALATED_TO_CASE", "DISMISSED"] as const;
const RISK_SEVERITIES = ["LOW", "MEDIUM", "HIGH", "CRITICAL"] as const;
const MAX_RISK_QUERY_LIMIT = 200;
const DEFAULT_RISK_QUERY_LIMIT = 50;

export type RiskIndicatorQuery = {
  taxpayerId: string | null;
  status: (typeof RISK_INDICATOR_STATUSES)[number] | null;
  severity: (typeof RISK_SEVERITIES)[number] | null;
  limit: number;
  offset: number;
};

/** GetRestrictedRisk's filter/pagination predicates, mirroring Module 3 Phase B's normalizeWorkQueueQuery. */
export function normalizeRiskIndicatorQuery(params: URLSearchParams): RiskIndicatorQuery {
  const messages: ComplianceValidationMessage[] = [];

  const taxpayerId = params.get("taxpayer_id")?.trim() || null;

  const statusRaw = params.get("status");
  const status = statusRaw ? (statusRaw.trim().toUpperCase() as RiskIndicatorQuery["status"]) : null;
  if (status && !RISK_INDICATOR_STATUSES.includes(status)) messages.push({ code: "STATUS_INVALID", path: "/status", message: `status must be one of: ${RISK_INDICATOR_STATUSES.join(", ")}.` });

  const severityRaw = params.get("severity");
  const severity = severityRaw ? (severityRaw.trim().toUpperCase() as RiskIndicatorQuery["severity"]) : null;
  if (severity && !RISK_SEVERITIES.includes(severity)) messages.push({ code: "SEVERITY_INVALID", path: "/severity", message: `severity must be one of: ${RISK_SEVERITIES.join(", ")}.` });

  const limitRaw = params.get("limit");
  let limit = DEFAULT_RISK_QUERY_LIMIT;
  if (limitRaw !== null) {
    const parsed = Number(limitRaw);
    if (!Number.isInteger(parsed) || parsed < 1 || parsed > MAX_RISK_QUERY_LIMIT) messages.push({ code: "LIMIT_INVALID", path: "/limit", message: `limit must be an integer between 1 and ${MAX_RISK_QUERY_LIMIT}.` });
    else limit = parsed;
  }

  const offsetRaw = params.get("offset");
  let offset = 0;
  if (offsetRaw !== null) {
    const parsed = Number(offsetRaw);
    if (!Number.isInteger(parsed) || parsed < 0) messages.push({ code: "OFFSET_INVALID", path: "/offset", message: "offset must be a non-negative integer." });
    else offset = parsed;
  }

  if (messages.length) throw new ComplianceValidationError(messages);
  return { taxpayerId, status, severity, limit, offset };
}

/**
 * Module 4 Phase D: the evidence sub-model. audit_evidence already existed
 * in the schema (a Phase A-adjacent anchor, like risk_indicators before
 * Phase A) but no application code had ever written to it. This phase
 * builds AddEvidence, evidence custody events (verify / legal hold), and
 * append-only case notes — the three concrete things the playbook names.
 *
 * Deliberately NOT reinvented here: file storage, hashing, quarantine
 * scanning and classification. Module 22 (Document)'s uploadDocument
 * already does all of that — audit_evidence.document_id already existed
 * as a foreign key into document_metadata, and 'AUDIT_CASE' was already an
 * accepted owner_domain there. This phase's AddEvidence either cites an
 * already-uploaded, clean-scanned document, or cites another canonical
 * system record this codebase already computes a real hash for (an
 * invoice's payload_hash, a VAT return version's ledger_snapshot_hash) —
 * never a second, parallel file-hashing implementation.
 *
 * "Immutable versioning" means exactly what it says: an evidence row is
 * never UPDATEd once inserted (only its status/legal_hold flip via
 * dedicated, audited actions). A correction adds a NEW row that supersedes
 * the old one (previous_version_id), and only one PRESERVED row may exist
 * per (case, source resource) at a time — enforced by a partial unique
 * index in db/runtime.ts, not just application-level discipline.
 */
export type EvidenceSourceType = "INVOICE" | "VAT_RETURN" | "DOCUMENT" | "OTHER";

const EVIDENCE_SOURCE_TYPES: readonly EvidenceSourceType[] = ["INVOICE", "VAT_RETURN", "DOCUMENT", "OTHER"];
const SHA256_PATTERN = /^[a-f0-9]{64}$/i;

export type EvidenceAddition = {
  schema_version: "1.0.0";
  sourceResourceType: EvidenceSourceType;
  sourceResourceId: string;
  description: string;
  checksumSha256?: string;
  supersedesEvidenceId?: string;
};

/**
 * checksum_sha256 is only accepted (and required) for source_resource_type
 * OTHER — an officer-supplied hash of external material this system has no
 * canonical record for (e.g. a bank statement, a witness account). For
 * every other source type the repository derives the hash authoritatively
 * from the cited record itself; a caller-supplied value would just be an
 * unverified claim, so it is never accepted there.
 */
export function validateEvidenceAddition(payload: unknown): EvidenceAddition {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const sourceResourceType = text(input.source_resource_type).toUpperCase() as EvidenceSourceType;
  if (!EVIDENCE_SOURCE_TYPES.includes(sourceResourceType)) messages.push({ code: "SOURCE_TYPE_INVALID", path: "/source_resource_type", message: `source_resource_type must be one of: ${EVIDENCE_SOURCE_TYPES.join(", ")}.` });
  const sourceResourceId = id(input.source_resource_id, "/source_resource_id", messages) ?? "";
  const description = bounded(input.description, "/description", "Description", 10, 2_000, messages);
  const supersedesEvidenceId = id(input.supersedes_evidence_id, "/supersedes_evidence_id", messages, true);
  let checksumSha256: string | undefined;
  if (sourceResourceType === "OTHER") {
    const raw = text(input.checksum_sha256).toLowerCase();
    if (!SHA256_PATTERN.test(raw)) messages.push({ code: "CHECKSUM_INVALID", path: "/checksum_sha256", message: "checksum_sha256 is required and must be a 64-character hex SHA-256 digest for externally supplied evidence." });
    else checksumSha256 = raw;
  }
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", sourceResourceType, sourceResourceId, description, ...(checksumSha256 ? { checksumSha256 } : {}), ...(supersedesEvidenceId ? { supersedesEvidenceId } : {}) };
}

export type EvidenceCustodyAction = "VERIFY" | "SET_LEGAL_HOLD" | "RELEASE_LEGAL_HOLD";

const EVIDENCE_CUSTODY_ACTIONS: readonly EvidenceCustodyAction[] = ["VERIFY", "SET_LEGAL_HOLD", "RELEASE_LEGAL_HOLD"];

export type EvidenceCustodyEventInput = { schema_version: "1.0.0"; action: EvidenceCustodyAction; notes?: string };

/** SET_LEGAL_HOLD/RELEASE_LEGAL_HOLD require a recorded justification; VERIFY's notes are optional context on a routine integrity check. */
export function validateEvidenceCustodyEvent(payload: unknown): EvidenceCustodyEventInput {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const action = text(input.action).toUpperCase() as EvidenceCustodyAction;
  if (!EVIDENCE_CUSTODY_ACTIONS.includes(action)) messages.push({ code: "ACTION_INVALID", path: "/action", message: `action must be one of: ${EVIDENCE_CUSTODY_ACTIONS.join(", ")}.` });
  const requiresNotes = action === "SET_LEGAL_HOLD" || action === "RELEASE_LEGAL_HOLD";
  const notes = requiresNotes ? bounded(input.notes, "/notes", "Notes", 10, 2_000, messages) : (text(input.notes) || undefined);
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", action, ...(notes ? { notes } : {}) };
}

export type CaseNoteAddition = { schema_version: "1.0.0"; body: string; supersedesNoteId?: string };

/** Append-only case notes: a correction is a new note with supersedes_note_id pointing at the prior one — the prior note is never edited or deleted. */
export function validateCaseNoteAddition(payload: unknown): CaseNoteAddition {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const body = bounded(input.body, "/body", "Note body", 5, 4_000, messages);
  const supersedesNoteId = id(input.supersedes_note_id, "/supersedes_note_id", messages, true);
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", body, ...(supersedesNoteId ? { supersedesNoteId } : {}) };
}
