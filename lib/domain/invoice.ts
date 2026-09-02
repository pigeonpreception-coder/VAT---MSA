import type {
  CalculatedInvoice,
  InvoiceSubmission,
  RiskLevel,
} from "./types";

export type ValidationMessage = {
  code: string;
  path: string;
  message: string;
};

export type AppliedTaxRule = {
  id: string;
  jurisdiction: "NA";
  version: string;
  effectiveFrom: string;
  effectiveTo: string | null;
  standardRateBps: number;
  legalAuthorityReference: string;
};

export class InvoiceValidationError extends Error {
  readonly messages: ValidationMessage[];

  constructor(messages: ValidationMessage[]) {
    super("The invoice failed validation.");
    this.name = "InvoiceValidationError";
    this.messages = messages;
  }
}

export function decimalToScaled(value: string, scale: number): number {
  const match = /^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?$/.exec(value.trim());
  if (!match) throw new Error(`Invalid decimal value: ${value}`);

  const negative = match[1] === "-";
  const whole = BigInt(match[2]);
  const fraction = match[3] ?? "";
  const base = 10n ** BigInt(scale);
  const kept = fraction.slice(0, scale).padEnd(scale, "0");
  let result = whole * base + BigInt(kept || "0");
  if (fraction.length > scale && Number(fraction[scale]) >= 5) result += 1n;
  if (negative) result *= -1n;

  if (result > BigInt(Number.MAX_SAFE_INTEGER) || result < BigInt(Number.MIN_SAFE_INTEGER)) {
    throw new Error("Decimal value exceeds the supported range.");
  }
  return Number(result);
}

export function centsToDecimal(cents: number): string {
  const sign = cents < 0 ? "-" : "";
  const absolute = Math.abs(cents);
  return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, "0")}`;
}

function roundedDivide(numerator: number, denominator: number): number {
  const sign = numerator < 0 ? -1 : 1;
  return sign * Math.floor((Math.abs(numerator) + denominator / 2) / denominator);
}

function vatIdentifier(party: InvoiceSubmission["supplier"]): string | null {
  return party.identifiers?.find((identifier) => identifier.type === "VAT_NUMBER")?.value.trim() || null;
}

export function calculateAndValidateInvoice(payload: InvoiceSubmission, taxRule: AppliedTaxRule): CalculatedInvoice {
  const errors: ValidationMessage[] = [];

  if (taxRule.jurisdiction !== "NA" || !taxRule.version.trim() || !taxRule.legalAuthorityReference.trim()) {
    errors.push({ code: "TAX_RULE_INVALID", path: "/issue_date", message: "The approved Namibia tax rule is incomplete." });
  }

  if (payload.schema_version !== "1.0.0") {
    errors.push({ code: "SCHEMA_VERSION", path: "/schema_version", message: "Schema version 1.0.0 is required." });
  }
  if (!payload.invoice_number?.trim()) {
    errors.push({ code: "INVOICE_NUMBER_REQUIRED", path: "/invoice_number", message: "Invoice number is required." });
  } else if (payload.invoice_number.length > 100) {
    errors.push({ code: "INVOICE_NUMBER_TOO_LONG", path: "/invoice_number", message: "Invoice number must not exceed 100 characters." });
  }
  if (!payload.source?.system_id?.trim() || !payload.source?.document_id?.trim()) {
    errors.push({ code: "SOURCE_REQUIRED", path: "/source", message: "Source system and document identifiers are required." });
  } else {
    if (payload.source.system_id.length > 100) errors.push({ code: "SOURCE_SYSTEM_TOO_LONG", path: "/source/system_id", message: "Source system ID must not exceed 100 characters." });
    if (payload.source.document_id.length > 150) errors.push({ code: "SOURCE_DOCUMENT_TOO_LONG", path: "/source/document_id", message: "Source document ID must not exceed 150 characters." });
    if (!payload.source.submitted_at || Number.isNaN(Date.parse(payload.source.submitted_at))) errors.push({ code: "SUBMITTED_AT_INVALID", path: "/source/submitted_at", message: "Submitted timestamp must be a valid ISO date-time." });
  }
  if (!payload.supplier?.name?.trim() || !vatIdentifier(payload.supplier)) {
    errors.push({ code: "SUPPLIER_VAT_REQUIRED", path: "/supplier", message: "The supplier name and VAT number are required." });
  } else if (payload.supplier.name.length > 250) {
    errors.push({ code: "SUPPLIER_NAME_TOO_LONG", path: "/supplier/name", message: "Supplier name must not exceed 250 characters." });
  }
  if (!payload.customer?.name?.trim() || !payload.customer.identifiers?.length) {
    errors.push({ code: "CUSTOMER_REQUIRED", path: "/customer", message: "Customer name and at least one identifier are required." });
  } else if (payload.customer.name.length > 250) {
    errors.push({ code: "CUSTOMER_NAME_TOO_LONG", path: "/customer/name", message: "Customer name must not exceed 250 characters." });
  }
  for (const [partyName, party] of [["supplier", payload.supplier], ["customer", payload.customer]] as const) {
    if ((party?.identifiers?.length ?? 0) > 20) errors.push({ code: "TOO_MANY_IDENTIFIERS", path: `/${partyName}/identifiers`, message: "A party may have at most 20 identifiers." });
    party?.identifiers?.forEach((identifier, index) => {
      if (!identifier.value?.trim() || identifier.value.length > 100) errors.push({ code: "IDENTIFIER_INVALID", path: `/${partyName}/identifiers/${index}/value`, message: "Identifier values must contain 1 to 100 characters." });
    });
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(payload.issue_date ?? "")) {
    errors.push({ code: "ISSUE_DATE_INVALID", path: "/issue_date", message: "Issue date must use YYYY-MM-DD." });
  }
  if (!/^[A-Z]{3}$/.test(payload.currency ?? "")) {
    errors.push({ code: "CURRENCY_INVALID", path: "/currency", message: "Currency must be a three-letter ISO code." });
  } else if (payload.currency !== "NAD") {
    errors.push({ code: "CURRENCY_JURISDICTION_MISMATCH", path: "/currency", message: "Namibia VAT certification requires NAD currency." });
  }
  if (!Array.isArray(payload.lines) || payload.lines.length === 0) {
    errors.push({ code: "LINES_REQUIRED", path: "/lines", message: "At least one invoice line is required." });
  } else if (payload.lines.length > 10_000) {
    errors.push({ code: "TOO_MANY_LINES", path: "/lines", message: "An invoice may contain at most 10,000 lines." });
  }
  if (["CREDIT_NOTE", "DEBIT_NOTE"].includes(payload.document_type) && !payload.original_document_reference) {
    errors.push({ code: "ORIGINAL_DOCUMENT_REQUIRED", path: "/original_document_reference", message: "Credit and debit notes must reference the original document." });
  } else if (["CREDIT_NOTE", "DEBIT_NOTE"].includes(payload.document_type)) {
    if (!payload.original_document_reference?.reason_code?.trim()) errors.push({ code: "CORRECTION_REASON_CODE_REQUIRED", path: "/original_document_reference/reason_code", message: "A correction reason code is required." });
    if ((payload.original_document_reference?.reason?.trim().length ?? 0) < 5) errors.push({ code: "CORRECTION_REASON_REQUIRED", path: "/original_document_reference/reason", message: "A correction reason of at least 5 characters is required." });
  }

  const seenLineNumbers = new Set<number>();
  const calculatedLines = (payload.lines ?? []).slice(0, 10_000).map((line, index) => {
    const path = `/lines/${index}`;
    if (!line || typeof line !== "object") {
      errors.push({ code: "LINE_INVALID", path, message: "Each invoice line must be an object." });
      return {
        line_number: index + 1, description: "", quantity: "0", unit_code: "EA", unit_price: "0", net_amount: "0",
        tax: { category: "OTHER" as const, rate: "0", taxable_amount: "0", tax_amount: "0" },
        quantityMicros: 0, unitPriceCents: 0, netAmountCents: 0, taxRateBps: 0, taxAmountCents: 0,
      };
    }
    const tax = line?.tax ?? { category: "OTHER" as const, rate: "", taxable_amount: "", tax_amount: "" };
    let quantityMicros = 0;
    let unitPriceCents = 0;
    let suppliedNetCents = 0;
    let suppliedTaxCents = 0;
    let suppliedTaxableCents = 0;
    let taxRateBps = 0;

    try { quantityMicros = decimalToScaled(line.quantity, 6); }
    catch { errors.push({ code: "QUANTITY_INVALID", path: `${path}/quantity`, message: "Quantity must be a non-negative decimal." }); }
    try { unitPriceCents = decimalToScaled(line.unit_price, 2); }
    catch { errors.push({ code: "UNIT_PRICE_INVALID", path: `${path}/unit_price`, message: "Unit price must be a decimal amount." }); }
    try { suppliedNetCents = decimalToScaled(line.net_amount, 2); }
    catch { errors.push({ code: "NET_AMOUNT_INVALID", path: `${path}/net_amount`, message: "Net amount must be a decimal amount." }); }
    try { suppliedTaxableCents = decimalToScaled(tax.taxable_amount, 2); }
    catch { errors.push({ code: "TAXABLE_AMOUNT_INVALID", path: `${path}/tax/taxable_amount`, message: "Taxable amount must be a decimal amount." }); }
    try { suppliedTaxCents = decimalToScaled(tax.tax_amount, 2); }
    catch { errors.push({ code: "TAX_AMOUNT_INVALID", path: `${path}/tax/tax_amount`, message: "Tax amount must be a decimal amount." }); }
    try { taxRateBps = decimalToScaled(tax.rate, 2); }
    catch { errors.push({ code: "TAX_RATE_INVALID", path: `${path}/tax/rate`, message: "Tax rate must be a non-negative percentage." }); }

    if (!Number.isInteger(line.line_number) || line.line_number < 1) {
      errors.push({ code: "LINE_NUMBER_INVALID", path: `${path}/line_number`, message: "Line number must be a positive integer." });
    } else if (seenLineNumbers.has(line.line_number)) {
      errors.push({ code: "LINE_NUMBER_DUPLICATE", path: `${path}/line_number`, message: "Line numbers must be unique within an invoice." });
    } else {
      seenLineNumbers.add(line.line_number);
    }
    if (!line.description?.trim()) {
      errors.push({ code: "DESCRIPTION_REQUIRED", path: `${path}/description`, message: "Line description is required." });
    } else if (line.description.length > 1_000) {
      errors.push({ code: "DESCRIPTION_TOO_LONG", path: `${path}/description`, message: "Line description must not exceed 1,000 characters." });
    }
    if (quantityMicros <= 0) {
      errors.push({ code: "QUANTITY_NOT_POSITIVE", path: `${path}/quantity`, message: "Quantity must be greater than zero." });
    }
    if (line.unit_code?.length > 20) {
      errors.push({ code: "UNIT_CODE_TOO_LONG", path: `${path}/unit_code`, message: "Unit code must not exceed 20 characters." });
    }
    if (taxRateBps < 0 || taxRateBps > 10_000) {
      errors.push({ code: "TAX_RATE_OUT_OF_RANGE", path: `${path}/tax/rate`, message: "Tax rate must be between 0 and 100 percent." });
    }
    if (tax.category === "STANDARD" && taxRateBps !== taxRule.standardRateBps) {
      errors.push({ code: "STANDARD_RATE_NOT_APPROVED", path: `${path}/tax/rate`, message: `The approved ${taxRule.version} standard rate is ${(taxRule.standardRateBps / 100).toFixed(2)} percent.` });
    }
    if (["REVERSE_CHARGE", "OTHER"].includes(tax.category)) {
      errors.push({ code: "TAX_CATEGORY_NOT_APPROVED", path: `${path}/tax/category`, message: `${tax.category} is not mapped by the approved rule set.` });
    }
    if (!["CREDIT_NOTE"].includes(payload.document_type) && [unitPriceCents, suppliedNetCents, suppliedTaxableCents, suppliedTaxCents].some((value) => value < 0)) {
      errors.push({ code: "NEGATIVE_AMOUNT_NOT_ALLOWED", path, message: "Negative amounts require the approved credit-note workflow." });
    }
    if (payload.document_type === "CREDIT_NOTE" && [unitPriceCents, suppliedNetCents, suppliedTaxableCents, suppliedTaxCents].some((value) => value > 0)) {
      errors.push({ code: "CREDIT_NOTE_SIGN_INVALID", path, message: "Credit-note monetary values must be zero or negative." });
    }

    const computedNetCents = roundedDivide(quantityMicros * unitPriceCents, 1_000_000);
    const computedTaxCents = roundedDivide(computedNetCents * taxRateBps, 10_000);
    if (computedNetCents !== suppliedNetCents) {
      errors.push({ code: "LINE_NET_MISMATCH", path: `${path}/net_amount`, message: `Expected ${centsToDecimal(computedNetCents)} from quantity and unit price.` });
    }
    if (suppliedTaxableCents !== suppliedNetCents) {
      errors.push({ code: "TAXABLE_AMOUNT_MISMATCH", path: `${path}/tax/taxable_amount`, message: "Taxable amount must equal the line net amount for the pilot rule set." });
    }
    if (computedTaxCents !== suppliedTaxCents) {
      errors.push({ code: "LINE_TAX_MISMATCH", path: `${path}/tax/tax_amount`, message: `Expected ${centsToDecimal(computedTaxCents)} for the supplied rate.` });
    }
    if (["ZERO_RATED", "EXEMPT", "OUTSIDE_SCOPE"].includes(tax.category) && taxRateBps !== 0) {
      errors.push({ code: "ZERO_RATE_REQUIRED", path: `${path}/tax/rate`, message: `${tax.category} lines must use a zero rate.` });
    }

    return {
      ...line,
      tax,
      quantityMicros,
      unitPriceCents,
      netAmountCents: computedNetCents,
      taxRateBps,
      taxAmountCents: computedTaxCents,
    };
  });

  const lineNetCents = calculatedLines.reduce((sum, line) => sum + line.netAmountCents, 0);
  const taxCents = calculatedLines.reduce((sum, line) => sum + line.taxAmountCents, 0);
  const totalCents = lineNetCents + taxCents;
  if (payload.document_type === "CREDIT_NOTE" && totalCents >= 0) {
    errors.push({ code: "CREDIT_NOTE_TOTAL_INVALID", path: "/totals/payable_amount", message: "A credit note must reduce the original document with a negative payable total." });
  }
  if (payload.document_type === "DEBIT_NOTE" && totalCents <= 0) {
    errors.push({ code: "DEBIT_NOTE_TOTAL_INVALID", path: "/totals/payable_amount", message: "A debit note must increase the original document with a positive payable total." });
  }

  const totalChecks: Array<[keyof InvoiceSubmission["totals"], number, string]> = [
    ["line_net_amount", lineNetCents, "Line net total"],
    ["tax_exclusive_amount", lineNetCents, "Tax-exclusive total"],
    ["tax_amount", taxCents, "VAT total"],
    ["tax_inclusive_amount", totalCents, "Tax-inclusive total"],
    ["payable_amount", totalCents, "Payable total"],
  ];
  for (const [key, expected, label] of totalChecks) {
    try {
      const supplied = decimalToScaled(payload.totals?.[key] ?? "", 2);
      if (supplied !== expected) {
        errors.push({ code: "DOCUMENT_TOTAL_MISMATCH", path: `/totals/${key}`, message: `${label} must be ${centsToDecimal(expected)}.` });
      }
    } catch {
      errors.push({ code: "DOCUMENT_TOTAL_INVALID", path: `/totals/${key}`, message: `${label} must be a decimal amount.` });
    }
  }

  if (errors.length) throw new InvoiceValidationError(errors);
  return { lineNetCents, taxCents, totalCents, lines: calculatedLines };
}

export function scoreInvoice(payload: InvoiceSubmission, calculated: CalculatedInvoice, buyerRegistered: boolean): { level: RiskLevel; reasons: string[] } {
  const reasons: string[] = [];
  let points = 0;
  if (calculated.totalCents >= 100_000_000) { points += 80; reasons.push("Transaction value exceeds N$1,000,000."); }
  else if (calculated.totalCents >= 25_000_000) { points += 35; reasons.push("High-value transaction exceeds N$250,000."); }
  if (!buyerRegistered) { points += 15; reasons.push("Buyer VAT number is not present in the pilot registry."); }
  if (new Set(payload.lines.map((line) => line.tax.category)).size > 1) { points += 10; reasons.push("Invoice contains mixed VAT categories."); }
  if (payload.document_type === "CREDIT_NOTE") { points += 10; reasons.push("Credit note requires linked-document review."); }

  const level: RiskLevel = points >= 80 ? "CRITICAL" : points >= 45 ? "HIGH" : points >= 20 ? "MEDIUM" : "LOW";
  return { level, reasons };
}

export function stableStringify(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(stableStringify).join(",")}]`;
  if (value && typeof value === "object") {
    const record = value as Record<string, unknown>;
    return `{${Object.keys(record).sort().map((key) => `${JSON.stringify(key)}:${stableStringify(record[key])}`).join(",")}}`;
  }
  return JSON.stringify(value);
}

export async function sha256Hex(value: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value));
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

export function getVatNumber(party: InvoiceSubmission["supplier"]): string | null {
  return vatIdentifier(party);
}

export type InvoiceCancellation = { reason: string };

/**
 * Module 2 Phase B CancelInvoice. Deliberately officer-only (see
 * lib/data/repository.ts's cancelInvoice) and narrow: an invoice that
 * already has an active credit/debit note against it cannot be cancelled —
 * that lineage must be resolved through further corrections, not voided.
 * Distinct from a correction: a correction supersedes a real transaction's
 * value; a cancellation says the transaction never should have been
 * certified at all.
 */
export function normalizeInvoiceCancellation(input: unknown): InvoiceCancellation {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new InvoiceValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "A cancellation object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const reason = typeof source.reason === "string" ? source.reason.trim().replaceAll(/\s+/g, " ") : "";
  if (reason.length < 10 || reason.length > 240) {
    throw new InvoiceValidationError([{ code: "REASON_INVALID", path: "/reason", message: "Provide a 10 to 240 character cancellation reason." }]);
  }
  return { reason };
}
