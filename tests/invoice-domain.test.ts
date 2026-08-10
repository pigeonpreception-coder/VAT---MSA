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

  it("requires a credit note to carry negative correction amounts", () => {
    expect(() => calculateAndValidateInvoice(invoice({
      document_type: "CREDIT_NOTE",
      original_document_reference: { source_document_id: "ORIGINAL-1", reason_code: "PRICE_CORRECTION", reason: "Agreed price correction." },
    }))).toThrow(InvoiceValidationError);
    const result = calculateAndValidateInvoice(invoice({
      document_type: "CREDIT_NOTE",
      original_document_reference: { source_document_id: "ORIGINAL-1", reason_code: "PRICE_CORRECTION", reason: "Agreed price correction." },
      lines: [{ line_number: 1, description: "Price correction", quantity: "1", unit_code: "EA", unit_price: "-100.00", net_amount: "-100.00", tax: { category: "STANDARD", rate: "15.00", taxable_amount: "-100.00", tax_amount: "-15.00" } }],
      totals: { line_net_amount: "-100.00", tax_exclusive_amount: "-100.00", tax_amount: "-15.00", tax_inclusive_amount: "-115.00", payable_amount: "-115.00" },
    }));
    expect(result).toMatchObject({ lineNetCents: -10_000, taxCents: -1_500, totalCents: -11_500 });
  });

  it("requires a debit note to carry a positive correction total", () => {
    const negative = invoice({
      document_type: "DEBIT_NOTE",
      original_document_reference: { source_document_id: "ORIGINAL-1", reason_code: "PRICE_CORRECTION", reason: "Invalid debit correction." },
      lines: [{ line_number: 1, description: "Invalid debit", quantity: "1", unit_code: "EA", unit_price: "-10.00", net_amount: "-10.00", tax: { category: "STANDARD", rate: "15.00", taxable_amount: "-10.00", tax_amount: "-1.50" } }],
      totals: { line_net_amount: "-10.00", tax_exclusive_amount: "-10.00", tax_amount: "-1.50", tax_inclusive_amount: "-11.50", payable_amount: "-11.50" },
    });
    expect(() => calculateAndValidateInvoice(negative)).toThrow(InvoiceValidationError);
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

  it("rejects duplicate line numbers", () => {
    const firstLine = invoice().lines[0];
    const payload = invoice({ lines: [firstLine, { ...firstLine }] });
    expect(() => calculateAndValidateInvoice(payload)).toThrow(InvoiceValidationError);
  });

  it("rejects non-positive quantities", () => {
    const payload = invoice();
    payload.lines[0].quantity = "0";
    payload.lines[0].net_amount = "0.00";
    payload.lines[0].tax.taxable_amount = "0.00";
    payload.lines[0].tax.tax_amount = "0.00";
    expect(() => calculateAndValidateInvoice(payload)).toThrow(InvoiceValidationError);
  });

  it("rejects invoice identifiers above the bounded length", () => {
    expect(() => calculateAndValidateInvoice(invoice({ invoice_number: "X".repeat(101) }))).toThrow(InvoiceValidationError);
  });

  it("rejects more than 10,000 lines before unbounded processing", () => {
    const line = invoice().lines[0];
    const payload = invoice({ lines: Array.from({ length: 10_001 }, (_, index) => ({ ...line, line_number: index + 1 })) });
    expect(() => calculateAndValidateInvoice(payload)).toThrow(InvoiceValidationError);
  });
});
