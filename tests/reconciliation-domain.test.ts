import { describe, expect, it } from "vitest";
import {
  evaluateInvoiceMatch,
  normalizeExceptionAssignment,
  normalizeExceptionResolution,
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
