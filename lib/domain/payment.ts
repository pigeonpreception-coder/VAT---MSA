/**
 * Module 9 Phase D: pure validation for the Payment domain's two mutating
 * commands (RecordPayment, AllocatePayment against a refund claim already
 * at PAYMENT_PENDING). No DB access, no connector awareness — mirrors
 * lib/domain/compliance.ts's own local object/text/bounded helpers rather
 * than importing them, matching this codebase's convention of each domain
 * file owning its own tiny validation primitives.
 */

export type PaymentValidationMessage = { code: string; path: string; message: string };

export class PaymentValidationError extends Error {
  readonly messages: PaymentValidationMessage[];

  constructor(messages: PaymentValidationMessage[]) {
    super("Payment command failed validation.");
    this.name = "PaymentValidationError";
    this.messages = messages;
  }
}

function object(payload: unknown) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new PaymentValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be an object." }]);
  return payload as Record<string, unknown>;
}

function text(value: unknown) {
  return typeof value === "string" ? value.trim().replaceAll(/\s+/g, " ") : "";
}

function bounded(value: unknown, path: string, label: string, min: number, max: number, messages: PaymentValidationMessage[]) {
  const normalized = text(value);
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

function schema(input: Record<string, unknown>, messages: PaymentValidationMessage[]) {
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
}

export type RecordPaymentSubmission = { schema_version: "1.0.0"; beneficiary_reference: string; provider: string };

/** RecordPayment: no amount field — the amount is always the claim's own net_payable_cents, computed server-side at PAYMENT_AUTHORISATION, never taken from client input. */
export function validateRecordPaymentInput(payload: unknown): RecordPaymentSubmission {
  const input = object(payload);
  const messages: PaymentValidationMessage[] = [];
  schema(input, messages);
  const beneficiaryReference = bounded(input.beneficiary_reference, "/beneficiary_reference", "Beneficiary reference", 4, 100, messages);
  const provider = bounded(input.provider, "/provider", "Provider", 2, 60, messages);
  if (messages.length) throw new PaymentValidationError(messages);
  return { schema_version: "1.0.0", beneficiary_reference: beneficiaryReference, provider };
}

export type AllocatePaymentSubmission = { schema_version: "1.0.0"; settlement_reference: string; settled_amount_cents: number };

export function validateAllocatePaymentInput(payload: unknown): AllocatePaymentSubmission {
  const input = object(payload);
  const messages: PaymentValidationMessage[] = [];
  schema(input, messages);
  const settlementReference = bounded(input.settlement_reference, "/settlement_reference", "Settlement reference", 4, 100, messages);
  const settledAmount = Number(input.settled_amount_cents);
  if (!Number.isSafeInteger(settledAmount) || settledAmount <= 0) messages.push({ code: "AMOUNT_INVALID", path: "/settled_amount_cents", message: "settled_amount_cents must be a positive safe integer." });
  if (messages.length) throw new PaymentValidationError(messages);
  return { schema_version: "1.0.0", settlement_reference: settlementReference, settled_amount_cents: settledAmount };
}

/** Never persist a raw account/beneficiary reference — only the masked form (last 4 characters visible) ever reaches payment_instructions.beneficiary_reference_masked. */
export function maskBeneficiaryReference(raw: string): string {
  const trimmed = raw.trim();
  if (trimmed.length <= 4) return "*".repeat(trimmed.length);
  return `${"*".repeat(trimmed.length - 4)}${trimmed.slice(-4)}`;
}
