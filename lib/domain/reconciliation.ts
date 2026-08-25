/**
 * Module 3 Phase A: the reconciliation matching engine. A 2026-08-25 code
 * assessment found no real matching existed — "MATCHED"/"EXCEPTION" was
 * just an inline risk score set at invoice submission time, and the
 * reconciliation_matches table was seed-only, never written by application
 * code. This is a genuinely independent verification pass: it re-derives
 * what the ledger *should* contain for an invoice from the invoice's own
 * declared figures and status, and compares that against what was actually
 * posted — catching drift a bug or manual tampering could introduce, not
 * just re-trusting the same write path that already ran once.
 */

export type ReconciliationValidationMessage = { code: string; path: string; message: string };

export class ReconciliationValidationError extends Error {
  readonly messages: ReconciliationValidationMessage[];

  constructor(messages: ReconciliationValidationMessage[]) {
    super("The reconciliation request failed validation.");
    this.name = "ReconciliationValidationError";
    this.messages = messages;
  }
}

function textValue(value: unknown): string {
  return typeof value === "string" ? value.trim() : "";
}

export type ExceptionAssignment = { officerId: string };

export function normalizeExceptionAssignment(input: unknown): ExceptionAssignment {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new ReconciliationValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "An assignment object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const officerId = textValue(source.officer_id);
  if (!officerId) {
    throw new ReconciliationValidationError([{ code: "OFFICER_ID_REQUIRED", path: "/officer_id", message: "officer_id is required." }]);
  }
  return { officerId };
}

export type ExceptionResolution = { notes: string };

export function normalizeExceptionResolution(input: unknown): ExceptionResolution {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new ReconciliationValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "A resolution object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const notes = textValue(source.notes).replaceAll(/\s+/g, " ");
  if (notes.length < 10 || notes.length > 400) {
    throw new ReconciliationValidationError([{ code: "NOTES_INVALID", path: "/notes", message: "Provide 10 to 400 characters describing how this exception was resolved." }]);
  }
  return { notes };
}

export type InvoiceMatchCheckInput = {
  invoiceTaxCents: number;
  outputVatLedgerCents: number | null;
  hasIdentifiedBuyer: boolean;
  inputVatLedgerCents: number | null;
  isCancelled: boolean;
  cancellationOutputVatLedgerCents: number | null;
};

export type InvoiceMatchResult = {
  status: "MATCHED" | "EXCEPTION";
  mismatches: string[];
};

/**
 * RunMatch's decision logic: given what the invoice declares and what was
 * actually posted, does everything tie out? Checks, independently of one
 * another so every discrepancy is reported, not just the first:
 *  1. The invoice's own OUTPUT_VAT posting equals its declared tax amount.
 *  2. If it has an identified buyer, an equal INPUT_VAT posting exists —
 *     and if it does NOT, that no INPUT_VAT posting leaked through anyway
 *     (re-verifying Module 2's unidentified-buyer guarantee as an ongoing
 *     control, not just a one-time code review).
 *  3. If the invoice is CANCELLED, an equal reversing OUTPUT_VAT posting
 *     exists.
 */
export function evaluateInvoiceMatch(input: InvoiceMatchCheckInput): InvoiceMatchResult {
  const mismatches: string[] = [];
  const expected = Math.abs(input.invoiceTaxCents);

  if (input.outputVatLedgerCents === null) {
    mismatches.push("No OUTPUT_VAT ledger entry was found for this invoice's certification transaction.");
  } else if (input.outputVatLedgerCents !== expected) {
    mismatches.push(`The OUTPUT_VAT ledger entry (${input.outputVatLedgerCents}) does not equal the invoice's declared tax amount (${expected}).`);
  }

  if (input.hasIdentifiedBuyer) {
    if (input.inputVatLedgerCents === null) {
      mismatches.push("The invoice has an identified buyer but no INPUT_VAT ledger entry was found.");
    } else if (input.inputVatLedgerCents !== expected) {
      mismatches.push(`The INPUT_VAT ledger entry (${input.inputVatLedgerCents}) does not equal the invoice's declared tax amount (${expected}).`);
    }
  } else if (input.inputVatLedgerCents !== null) {
    mismatches.push("An INPUT_VAT ledger entry exists despite the invoice having no identified buyer, violating the unidentified-buyer guarantee.");
  }

  if (input.isCancelled) {
    if (input.cancellationOutputVatLedgerCents === null) {
      mismatches.push("The invoice is CANCELLED but no reversing OUTPUT_VAT ledger entry was found.");
    } else if (input.cancellationOutputVatLedgerCents !== expected) {
      mismatches.push(`The cancellation's reversing OUTPUT_VAT entry (${input.cancellationOutputVatLedgerCents}) does not equal the invoice's declared tax amount (${expected}).`);
    }
  }

  return { status: mismatches.length ? "EXCEPTION" : "MATCHED", mismatches };
}
