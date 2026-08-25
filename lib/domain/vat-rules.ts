import type { TaxCategory } from "./types";

/**
 * Module 2 Phase A: the VAT rule engine. Previously the tax rate applied to
 * an invoice line was entirely client-supplied (lib/domain/invoice.ts only
 * checked internal arithmetic consistency: net*rate=tax, 0-100% range) — no
 * server-owned rate table existed anywhere. lib/data/vat-rule-repository.ts
 * resolves the applicable approved rule for a category+date and rejects any
 * submission whose supplied rate doesn't match it, or whose category has no
 * approved rule at all (fails closed, per the playbook's explicit
 * requirement — see the deliberately-unseeded OTHER category).
 */

const TAX_CATEGORIES: readonly TaxCategory[] = ["STANDARD", "ZERO_RATED", "EXEMPT", "OUTSIDE_SCOPE", "REVERSE_CHARGE", "OTHER"];

export type VatRuleValidationMessage = { code: string; path: string; message: string };

export class VatRuleValidationError extends Error {
  readonly messages: VatRuleValidationMessage[];

  constructor(messages: VatRuleValidationMessage[]) {
    super("The VAT rule request failed validation.");
    this.name = "VatRuleValidationError";
    this.messages = messages;
  }
}

function textValue(value: unknown): string {
  return typeof value === "string" ? value.trim() : "";
}

export type VatRuleProposal = { taxCategory: TaxCategory; rateBps: number; effectiveFrom: string; reason: string };

/**
 * ProposeVatRule: a NamRA/pilot-admin officer drafts a new rate for a tax
 * category. Does not take effect until a *different* officer approves it
 * via ApproveVatRule (lib/data/vat-rule-repository.ts denies self-approval,
 * the same pattern used for registration decisions and access requests).
 */
export function normalizeVatRuleProposal(input: unknown): VatRuleProposal {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new VatRuleValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "A VAT rule proposal object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const messages: VatRuleValidationMessage[] = [];

  const taxCategory = textValue(source.tax_category).toUpperCase() as TaxCategory;
  if (!TAX_CATEGORIES.includes(taxCategory)) {
    messages.push({ code: "TAX_CATEGORY_INVALID", path: "/tax_category", message: `tax_category must be one of: ${TAX_CATEGORIES.join(", ")}.` });
  }
  const rateBps = Number(source.rate_bps);
  if (!Number.isInteger(rateBps) || rateBps < 0 || rateBps > 10_000) {
    messages.push({ code: "RATE_INVALID", path: "/rate_bps", message: "rate_bps must be an integer between 0 and 10000 (basis points; 1500 = 15%)." });
  }
  const effectiveFrom = textValue(source.effective_from);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(effectiveFrom)) {
    messages.push({ code: "EFFECTIVE_FROM_INVALID", path: "/effective_from", message: "effective_from must use YYYY-MM-DD." });
  }
  const reason = textValue(source.reason).replaceAll(/\s+/g, " ");
  if (reason.length < 10 || reason.length > 400) {
    messages.push({ code: "REASON_INVALID", path: "/reason", message: "Provide a 10 to 400 character statutory basis for this rate." });
  }
  if (messages.length) throw new VatRuleValidationError(messages);
  return { taxCategory, rateBps, effectiveFrom, reason };
}

export type VatRuleApproval = { reason: string };

export function normalizeVatRuleApproval(input: unknown): VatRuleApproval {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new VatRuleValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "A VAT rule approval object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const reason = textValue(source.reason).replaceAll(/\s+/g, " ");
  if (reason.length < 5 || reason.length > 240) {
    throw new VatRuleValidationError([{ code: "REASON_INVALID", path: "/reason", message: "Provide a 5 to 240 character approval reason." }]);
  }
  return { reason };
}

export type VatRuleEvaluationQuery = { taxCategory: TaxCategory; effectiveDate: string };

/** EvaluateVAT's input shape: which category, as of which date. */
export function normalizeVatRuleEvaluationQuery(taxCategoryInput: unknown, dateInput: unknown): VatRuleEvaluationQuery {
  const messages: VatRuleValidationMessage[] = [];
  const taxCategory = textValue(taxCategoryInput).toUpperCase() as TaxCategory;
  if (!TAX_CATEGORIES.includes(taxCategory)) {
    messages.push({ code: "TAX_CATEGORY_INVALID", path: "/tax_category", message: `tax_category must be one of: ${TAX_CATEGORIES.join(", ")}.` });
  }
  const effectiveDate = textValue(dateInput);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(effectiveDate)) {
    messages.push({ code: "DATE_INVALID", path: "/date", message: "date must use YYYY-MM-DD." });
  }
  if (messages.length) throw new VatRuleValidationError(messages);
  return { taxCategory, effectiveDate };
}
