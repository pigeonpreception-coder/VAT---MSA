import { describe, expect, it } from "vitest";
import {
  evaluateInvoiceMatch,
  normalizeExceptionAssignment,
  normalizeExceptionResolution,
  normalizeWorkQueueQuery,
  ReconciliationValidationError,
} from "@/lib/domain/reconciliation";

describe("invoice match evaluation (RunMatch)", () => {
  it("matches when the OUTPUT_VAT posting ties to the invoice and there is no buyer", () => {
    expect(evaluateInvoiceMatch({
      invoiceTaxCents: 1500, outputVatLedgerCents: 1500, hasIdentifiedBuyer: false,
      inputVatLedgerCents: null, isCancelled: false, cancellationOutputVatLedgerCents: null,
    })).toEqual({ status: "MATCHED", mismatches: [] });
  });

  it("matches when both OUTPUT_VAT and INPUT_VAT postings tie to an identified buyer's invoice", () => {
    expect(evaluateInvoiceMatch({
      invoiceTaxCents: 1500, outputVatLedgerCents: 1500, hasIdentifiedBuyer: true,
      inputVatLedgerCents: 1500, isCancelled: false, cancellationOutputVatLedgerCents: null,
    })).toEqual({ status: "MATCHED", mismatches: [] });
  });

  it("flags a missing OUTPUT_VAT posting", () => {
    const result = evaluateInvoiceMatch({
      invoiceTaxCents: 1500, outputVatLedgerCents: null, hasIdentifiedBuyer: false,
      inputVatLedgerCents: null, isCancelled: false, cancellationOutputVatLedgerCents: null,
    });
    expect(result.status).toBe("EXCEPTION");
    expect(result.mismatches).toHaveLength(1);
  });

  it("flags an OUTPUT_VAT posting that doesn't equal the declared tax amount", () => {
    const result = evaluateInvoiceMatch({
      invoiceTaxCents: 1500, outputVatLedgerCents: 1400, hasIdentifiedBuyer: false,
      inputVatLedgerCents: null, isCancelled: false, cancellationOutputVatLedgerCents: null,
    });
    expect(result.status).toBe("EXCEPTION");
    expect(result.mismatches[0]).toMatch(/does not equal/);
  });

  it("flags a missing INPUT_VAT posting for an identified buyer", () => {
    const result = evaluateInvoiceMatch({
      invoiceTaxCents: 1500, outputVatLedgerCents: 1500, hasIdentifiedBuyer: true,
      inputVatLedgerCents: null, isCancelled: false, cancellationOutputVatLedgerCents: null,
    });
    expect(result.status).toBe("EXCEPTION");
    expect(result.mismatches[0]).toMatch(/identified buyer/);
  });

  it("flags an INPUT_VAT posting that leaked through despite no identified buyer (unidentified-buyer guarantee)", () => {
    const result = evaluateInvoiceMatch({
      invoiceTaxCents: 1500, outputVatLedgerCents: 1500, hasIdentifiedBuyer: false,
      inputVatLedgerCents: 1500, isCancelled: false, cancellationOutputVatLedgerCents: null,
    });
    expect(result.status).toBe("EXCEPTION");
    expect(result.mismatches[0]).toMatch(/unidentified-buyer guarantee/);
  });

  it("flags a cancelled invoice missing its reversing OUTPUT_VAT entry", () => {
    const result = evaluateInvoiceMatch({
      invoiceTaxCents: 1500, outputVatLedgerCents: 1500, hasIdentifiedBuyer: false,
      inputVatLedgerCents: null, isCancelled: true, cancellationOutputVatLedgerCents: null,
    });
    expect(result.status).toBe("EXCEPTION");
    expect(result.mismatches[0]).toMatch(/CANCELLED/);
  });

  it("matches a cleanly cancelled invoice with a correct reversing entry", () => {
    expect(evaluateInvoiceMatch({
      invoiceTaxCents: 1500, outputVatLedgerCents: 1500, hasIdentifiedBuyer: false,
      inputVatLedgerCents: null, isCancelled: true, cancellationOutputVatLedgerCents: 1500,
    })).toEqual({ status: "MATCHED", mismatches: [] });
  });
});

describe("exception assignment validation (Assign)", () => {
  it("accepts a well-formed assignment", () => {
    expect(normalizeExceptionAssignment({ officer_id: "usr-officer-1" })).toEqual({ officerId: "usr-officer-1" });
  });

  it("rejects a missing officer_id", () => {
    expect(() => normalizeExceptionAssignment({})).toThrow(ReconciliationValidationError);
  });
});

describe("exception resolution validation (ResolveException)", () => {
  it("accepts well-formed resolution notes", () => {
    expect(normalizeExceptionResolution({ notes: "Confirmed with the taxpayer; ledger entry was a timing delay." })).toEqual({
      notes: "Confirmed with the taxpayer; ledger entry was a timing delay.",
    });
  });

  it("rejects notes outside the 10 to 400 character bound", () => {
    expect(() => normalizeExceptionResolution({ notes: "too short" })).toThrow(ReconciliationValidationError);
    expect(() => normalizeExceptionResolution({ notes: "x".repeat(401) })).toThrow(ReconciliationValidationError);
  });
});

describe("work queue query validation (GetWorkQueue)", () => {
  it("defaults to no filters and the default page size", () => {
    expect(normalizeWorkQueueQuery(new URLSearchParams())).toEqual({
      status: null, severity: null, assignedOfficerId: null, unassignedOnly: false,
      minAgeDays: null, maxAgeDays: null, limit: 50, offset: 0,
    });
  });

  it("normalizes a well-formed filter set, uppercasing status/severity", () => {
    expect(normalizeWorkQueueQuery(new URLSearchParams("status=open&severity=high&min_age_days=3&max_age_days=30&limit=10&offset=20"))).toEqual({
      status: "OPEN", severity: "HIGH", assignedOfficerId: null, unassignedOnly: false,
      minAgeDays: 3, maxAgeDays: 30, limit: 10, offset: 20,
    });
  });

  it("accepts unassigned_only and assigned_officer_id independently", () => {
    expect(normalizeWorkQueueQuery(new URLSearchParams("unassigned_only=true")).unassignedOnly).toBe(true);
    expect(normalizeWorkQueueQuery(new URLSearchParams("assigned_officer_id=usr-officer-1")).assignedOfficerId).toBe("usr-officer-1");
  });

  it("rejects setting both assigned_officer_id and unassigned_only", () => {
    expect(() => normalizeWorkQueueQuery(new URLSearchParams("assigned_officer_id=usr-officer-1&unassigned_only=true"))).toThrow(ReconciliationValidationError);
  });

  it("rejects an unsupported status or severity", () => {
    expect(() => normalizeWorkQueueQuery(new URLSearchParams("status=CLOSED"))).toThrow(ReconciliationValidationError);
    expect(() => normalizeWorkQueueQuery(new URLSearchParams("severity=EXTREME"))).toThrow(ReconciliationValidationError);
  });

  it("rejects min_age_days greater than max_age_days", () => {
    expect(() => normalizeWorkQueueQuery(new URLSearchParams("min_age_days=30&max_age_days=5"))).toThrow(ReconciliationValidationError);
  });

  it("rejects a limit outside 1 to 200, and a negative offset", () => {
    expect(() => normalizeWorkQueueQuery(new URLSearchParams("limit=0"))).toThrow(ReconciliationValidationError);
    expect(() => normalizeWorkQueueQuery(new URLSearchParams("limit=201"))).toThrow(ReconciliationValidationError);
    expect(() => normalizeWorkQueueQuery(new URLSearchParams("offset=-1"))).toThrow(ReconciliationValidationError);
  });
});
