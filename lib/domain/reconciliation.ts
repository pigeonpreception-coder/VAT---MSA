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

const EXCEPTION_STATUSES = ["OPEN", "ASSIGNED", "RESOLVED"] as const;
const EXCEPTION_SEVERITIES = ["LOW", "MEDIUM", "HIGH", "CRITICAL"] as const;
const MAX_WORK_QUEUE_LIMIT = 200;
const DEFAULT_WORK_QUEUE_LIMIT = 50;

export type WorkQueueQuery = {
  status: (typeof EXCEPTION_STATUSES)[number] | null;
  severity: (typeof EXCEPTION_SEVERITIES)[number] | null;
  assignedOfficerId: string | null;
  unassignedOnly: boolean;
  minAgeDays: number | null;
  maxAgeDays: number | null;
  limit: number;
  offset: number;
};

/**
 * Module 3 Phase B GetWorkQueue: the filter/status/officer/age predicates a
 * real reconciliation work queue needs — listExceptions previously took only
 * the caller for tenant scoping, with no filtering at all. Pagination is
 * designed in from the start (bounded limit, explicit offset) rather than
 * retrofitted after the first performance complaint, per this module's own
 * watch-out note.
 */
export function normalizeWorkQueueQuery(params: URLSearchParams): WorkQueueQuery {
  const messages: ReconciliationValidationMessage[] = [];

  const statusRaw = params.get("status");
  const status = statusRaw ? (statusRaw.trim().toUpperCase() as WorkQueueQuery["status"]) : null;
  if (status && !EXCEPTION_STATUSES.includes(status)) {
    messages.push({ code: "STATUS_INVALID", path: "/status", message: `status must be one of: ${EXCEPTION_STATUSES.join(", ")}.` });
  }

  const severityRaw = params.get("severity");
  const severity = severityRaw ? (severityRaw.trim().toUpperCase() as WorkQueueQuery["severity"]) : null;
  if (severity && !EXCEPTION_SEVERITIES.includes(severity)) {
    messages.push({ code: "SEVERITY_INVALID", path: "/severity", message: `severity must be one of: ${EXCEPTION_SEVERITIES.join(", ")}.` });
  }

  const assignedOfficerId = params.get("assigned_officer_id")?.trim() || null;
  const unassignedOnly = params.get("unassigned_only") === "true";
  if (assignedOfficerId && unassignedOnly) {
    messages.push({ code: "ASSIGNMENT_FILTER_CONFLICT", path: "/assigned_officer_id", message: "assigned_officer_id and unassigned_only=true cannot both be set." });
  }

  const minAgeDays = parseAgeDays(params.get("min_age_days"), "/min_age_days", messages);
  const maxAgeDays = parseAgeDays(params.get("max_age_days"), "/max_age_days", messages);
  if (minAgeDays !== null && maxAgeDays !== null && minAgeDays > maxAgeDays) {
    messages.push({ code: "AGE_RANGE_INVALID", path: "/min_age_days", message: "min_age_days must not exceed max_age_days." });
  }

  const limitRaw = params.get("limit");
  let limit = DEFAULT_WORK_QUEUE_LIMIT;
  if (limitRaw !== null) {
    const parsed = Number(limitRaw);
    if (!Number.isInteger(parsed) || parsed < 1 || parsed > MAX_WORK_QUEUE_LIMIT) {
      messages.push({ code: "LIMIT_INVALID", path: "/limit", message: `limit must be an integer between 1 and ${MAX_WORK_QUEUE_LIMIT}.` });
    } else {
      limit = parsed;
    }
  }

  const offsetRaw = params.get("offset");
  let offset = 0;
  if (offsetRaw !== null) {
    const parsed = Number(offsetRaw);
    if (!Number.isInteger(parsed) || parsed < 0) {
      messages.push({ code: "OFFSET_INVALID", path: "/offset", message: "offset must be a non-negative integer." });
    } else {
      offset = parsed;
    }
  }

  if (messages.length) throw new ReconciliationValidationError(messages);
  return { status, severity, assignedOfficerId, unassignedOnly, minAgeDays, maxAgeDays, limit, offset };
}

function parseAgeDays(raw: string | null, path: string, messages: ReconciliationValidationMessage[]): number | null {
  if (raw === null) return null;
  const parsed = Number(raw);
  if (!Number.isInteger(parsed) || parsed < 0) {
    messages.push({ code: "AGE_INVALID", path, message: `${path.slice(1)} must be a non-negative integer.` });
    return null;
  }
  return parsed;
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
