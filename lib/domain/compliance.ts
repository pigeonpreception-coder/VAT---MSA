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

function schema(input: Record<string, unknown>, messages: ComplianceValidationMessage[]) {
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
}

export function validateCaseOpening(payload: unknown): CaseOpeningSubmission {
  const input = object(payload);
  const messages: ComplianceValidationMessage[] = [];
  schema(input, messages);
  const taxpayerId = id(input.taxpayer_id, "/taxpayer_id", messages) ?? "";
  const caseType = text(input.case_type).toUpperCase() as CaseOpeningSubmission["case_type"];
  if (!new Set(["DESK_REVIEW", "VAT_AUDIT", "REFUND_VERIFICATION", "INVESTIGATION"]).has(caseType)) messages.push({ code: "CASE_TYPE_INVALID", path: "/case_type", message: "Select a supported case type." });
  const title = bounded(input.title, "/title", "Title", 5, 200, messages);
  const openingReason = bounded(input.opening_reason, "/opening_reason", "Opening reason", 20, 2_000, messages);
  const riskTier = text(input.risk_tier).toUpperCase() as CaseOpeningSubmission["risk_tier"];
  if (!new Set(["LOW", "MEDIUM", "HIGH", "CRITICAL"]).has(riskTier)) messages.push({ code: "RISK_TIER_INVALID", path: "/risk_tier", message: "Select a supported risk tier." });
  if (messages.length) throw new ComplianceValidationError(messages);
  return { schema_version: "1.0.0", taxpayer_id: taxpayerId, case_type: caseType, title, opening_reason: openingReason, risk_tier: riskTier };
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
