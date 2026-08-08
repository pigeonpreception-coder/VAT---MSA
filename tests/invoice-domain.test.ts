import { describe, expect, it } from "vitest";
import { calculateAndValidateInvoice, decimalToScaled, InvoiceValidationError, scoreInvoice, stableStringify } from "@/lib/domain/invoice";
import type { InvoiceSubmission } from "@/lib/domain/types";

function invoice(overrides: Partial<InvoiceSubmission> = {}): InvoiceSubmission {
  return {
    schema_version: "1.0.0",
    document_type: "TAX_INVOICE",
    source: { system_id: "TEST", document_id: "DOC-1", submitted_at: "2026-08-09T08:00:00Z" },
    supplier: { name: "Seller", identifiers: [{ type: "VAT_NUMBER", value: "VAT1000123" }] },
    customer: { name: "Buyer", identifiers: [{ type: "VAT_NUMBER", value: "VAT1000789" }] },
    invoice_number: "INV-1",
    issue_date: "2026-08-09",
    currency: "NAD",
    lines: [{ line_number: 1, description: "Service", quantity: "2", unit_code: "EA", unit_price: "100.00", net_amount: "200.00", tax: { category: "STANDARD", rate: "15.00", taxable_amount: "200.00", tax_amount: "30.00" } }],
    totals: { line_net_amount: "200.00", tax_exclusive_amount: "200.00", tax_amount: "30.00", tax_inclusive_amount: "230.00", payable_amount: "230.00" },
    ...overrides,
  };
}

describe("VAT invoice rules", () => {
  it("calculates exact integer-cent totals", () => {
    const result = calculateAndValidateInvoice(invoice());
    expect(result).toMatchObject({ lineNetCents: 20_000, taxCents: 3_000, totalCents: 23_000 });
  });

  it("rounds decimal inputs without binary floating-point drift", () => {
    expect(decimalToScaled("100.005", 2)).toBe(10_001);
    expect(decimalToScaled("-10.995", 2)).toBe(-1_100);
  });

  it("rejects a client-supplied VAT mismatch", () => {
    const payload = invoice();
    payload.lines[0].tax.tax_amount = "29.99";
    expect(() => calculateAndValidateInvoice(payload)).toThrow(InvoiceValidationError);
  });

  it("requires linked evidence for credit notes", () => {
    expect(() => calculateAndValidateInvoice(invoice({ document_type: "CREDIT_NOTE" }))).toThrow(/failed validation/i);
  });

  it("classifies million-dollar transactions as critical", () => {
    const payload = invoice({
      lines: [{ line_number: 1, description: "Large supply", quantity: "1", unit_code: "EA", unit_price: "1000000.00", net_amount: "1000000.00", tax: { category: "STANDARD", rate: "15.00", taxable_amount: "1000000.00", tax_amount: "150000.00" } }],
      totals: { line_net_amount: "1000000.00", tax_exclusive_amount: "1000000.00", tax_amount: "150000.00", tax_inclusive_amount: "1150000.00", payable_amount: "1150000.00" },
    });
    const calculated = calculateAndValidateInvoice(payload);
    expect(scoreInvoice(payload, calculated, true).level).toBe("CRITICAL");
  });

  it("produces stable canonical JSON independent of key order", () => {
    expect(stableStringify({ b: 2, a: 1 })).toBe(stableStringify({ a: 1, b: 2 }));
  });
});

