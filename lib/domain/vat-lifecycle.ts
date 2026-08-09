export type VatLifecycleValidationMessage = { code: string; path: string; message: string };

export class VatLifecycleValidationError extends Error {
  readonly messages: VatLifecycleValidationMessage[];

  constructor(messages: VatLifecycleValidationMessage[]) {
    super("VAT lifecycle command failed validation.");
    this.name = "VatLifecycleValidationError";
    this.messages = messages;
  }
}

export type VatAdjustmentSubmission = {
  schema_version: "1.0.0";
  adjustment_type: "OUTPUT_TAX" | "INPUT_TAX" | "NET_PAYABLE";
  direction: "INCREASE" | "DECREASE";
  amount_cents: number;
  reason_code: string;
  explanation: string;
  evidence_document_id?: string;
};

export type ReturnCalculationInput = {
  outputEntries: Array<{ id: string; amount_cents: number }>;
  inputEntries: Array<{ id: string; amount_cents: number }>;
  adjustments: Array<{ id: string; adjustment_type: string; direction: string; amount_cents: number }>;
};

export type ReturnPosition = {
  outputTaxCents: number;
  inputTaxCents: number;
  adjustmentCents: number;
  netPayableCents: number;
  outputSourceCount: number;
  inputSourceCount: number;
  adjustmentSourceCount: number;
};

const ID_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/;
const REASON_PATTERN = /^[A-Z0-9][A-Z0-9_-]{1,39}$/;
const ADJUSTMENT_TYPES = new Set(["OUTPUT_TAX", "INPUT_TAX", "NET_PAYABLE"]);
const DIRECTIONS = new Set(["INCREASE", "DECREASE"]);

function text(value: unknown): string {
  return typeof value === "string" ? value.trim().replaceAll(/\s+/g, " ") : "";
}

export function normalizeAndValidateAdjustment(payload: unknown): VatAdjustmentSubmission {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new VatLifecycleValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be an adjustment object." }]);
  }
  const input = payload as Record<string, unknown>;
  const messages: VatLifecycleValidationMessage[] = [];
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
  const adjustmentType = text(input.adjustment_type).toUpperCase() as VatAdjustmentSubmission["adjustment_type"];
  if (!ADJUSTMENT_TYPES.has(adjustmentType)) messages.push({ code: "ADJUSTMENT_TYPE_INVALID", path: "/adjustment_type", message: "Select a supported VAT adjustment type." });
  const direction = text(input.direction).toUpperCase() as VatAdjustmentSubmission["direction"];
  if (!DIRECTIONS.has(direction)) messages.push({ code: "DIRECTION_INVALID", path: "/direction", message: "Direction must be INCREASE or DECREASE." });
  const amountCents = Number(input.amount_cents);
  if (!Number.isSafeInteger(amountCents) || amountCents <= 0) messages.push({ code: "AMOUNT_INVALID", path: "/amount_cents", message: "Amount cents must be a positive safe integer." });
  const reasonCode = text(input.reason_code).toUpperCase();
  if (!REASON_PATTERN.test(reasonCode)) messages.push({ code: "REASON_CODE_INVALID", path: "/reason_code", message: "Reason code must contain 2 to 40 uppercase letters, numbers, underscores or hyphens." });
  const explanation = text(input.explanation);
  if (explanation.length < 10 || explanation.length > 2_000) messages.push({ code: "EXPLANATION_INVALID", path: "/explanation", message: "Explanation must contain 10 to 2000 characters." });
  const evidenceDocumentId = text(input.evidence_document_id) || undefined;
  if (evidenceDocumentId && !ID_PATTERN.test(evidenceDocumentId)) messages.push({ code: "EVIDENCE_ID_INVALID", path: "/evidence_document_id", message: "Evidence document id is invalid." });
  if (messages.length) throw new VatLifecycleValidationError(messages);
  return { schema_version: "1.0.0", adjustment_type: adjustmentType, direction, amount_cents: amountCents, reason_code: reasonCode, explanation, ...(evidenceDocumentId ? { evidence_document_id: evidenceDocumentId } : {}) };
}

function checkedSum(values: number[], path: string) {
  const sum = values.reduce((total, amount) => total + amount, 0);
  if (!values.every((amount) => Number.isSafeInteger(amount) && amount >= 0) || !Number.isSafeInteger(sum)) {
    throw new VatLifecycleValidationError([{ code: "LEDGER_AMOUNT_INVALID", path, message: "Ledger calculation encountered an invalid or overflowing integer amount." }]);
  }
  return sum;
}

export function calculateReturnPosition(input: ReturnCalculationInput): ReturnPosition {
  let outputTaxCents = checkedSum(input.outputEntries.map((entry) => entry.amount_cents), "/output_entries");
  let inputTaxCents = checkedSum(input.inputEntries.map((entry) => entry.amount_cents), "/input_entries");
  let directNetAdjustment = 0;
  for (const adjustment of input.adjustments) {
    if (!ADJUSTMENT_TYPES.has(adjustment.adjustment_type) || !DIRECTIONS.has(adjustment.direction) || !Number.isSafeInteger(adjustment.amount_cents) || adjustment.amount_cents <= 0) {
      throw new VatLifecycleValidationError([{ code: "ADJUSTMENT_STATE_INVALID", path: `/adjustments/${adjustment.id}`, message: "An approved adjustment has invalid calculation state." }]);
    }
    const signed = adjustment.direction === "INCREASE" ? adjustment.amount_cents : -adjustment.amount_cents;
    if (adjustment.adjustment_type === "OUTPUT_TAX") outputTaxCents += signed;
    else if (adjustment.adjustment_type === "INPUT_TAX") inputTaxCents += signed;
    else directNetAdjustment += signed;
  }
  if (outputTaxCents < 0 || inputTaxCents < 0) {
    throw new VatLifecycleValidationError([{ code: "ADJUSTMENT_UNDERFLOW", path: "/adjustments", message: "Approved adjustments cannot reduce output or input VAT below zero." }]);
  }
  const baseOutput = checkedSum(input.outputEntries.map((entry) => entry.amount_cents), "/output_entries");
  const baseInput = checkedSum(input.inputEntries.map((entry) => entry.amount_cents), "/input_entries");
  const adjustmentCents = (outputTaxCents - baseOutput) - (inputTaxCents - baseInput) + directNetAdjustment;
  const netPayableCents = outputTaxCents - inputTaxCents + directNetAdjustment;
  if (![outputTaxCents, inputTaxCents, adjustmentCents, netPayableCents].every(Number.isSafeInteger)) {
    throw new VatLifecycleValidationError([{ code: "CALCULATION_OVERFLOW", path: "/", message: "VAT return amounts exceed the supported integer range." }]);
  }
  return { outputTaxCents, inputTaxCents, adjustmentCents, netPayableCents, outputSourceCount: input.outputEntries.length, inputSourceCount: input.inputEntries.length, adjustmentSourceCount: input.adjustments.length };
}

export function validateDecisionComment(value: unknown): string {
  const comment = text(value);
  if (comment.length < 5 || comment.length > 1_000) throw new VatLifecycleValidationError([{ code: "DECISION_COMMENT_INVALID", path: "/comment", message: "Decision comment must contain 5 to 1000 characters." }]);
  return comment;
}
