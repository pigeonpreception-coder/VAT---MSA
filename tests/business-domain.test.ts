import { describe, expect, it } from "vitest";
import {
  BusinessValidationError,
  evaluateQuotationLifecycle,
  normalizeAndValidateAccount,
  normalizeAndValidateBusinessParty,
  normalizeAndValidateBusinessPartyDeactivation,
  normalizeAndValidateExpense,
  normalizeAndValidateJournal,
  normalizeAndValidateJournalReversal,
  normalizeAndValidatePeriodClose,
  normalizeAndValidateProject,
  normalizeAndValidateQuotation,
  normalizeAndValidateQuotationConversion,
  normalizeAndValidateQuotationRejection,
  normalizeAndValidateStockMovement,
} from "@/lib/domain/business";

describe("business command validation", () => {
  it("normalizes a governed customer and supplier record", () => {
    expect(normalizeAndValidateBusinessParty({
      schema_version: "1.0.0",
      display_name: "  Synthetic Trade Partner  ",
      legal_name: "Synthetic Trade Partner (Pty) Ltd",
      vat_number: "vat-1000999",
      tin: "tin-1000999",
      email: "ACCOUNTS@EXAMPLE.TEST",
      phone: "+264 61 000 999",
      relationships: ["supplier", "CUSTOMER", "SUPPLIER"],
    })).toMatchObject({
      display_name: "Synthetic Trade Partner",
      vat_number: "VAT-1000999",
      tin: "TIN-1000999",
      email: "accounts@example.test",
      relationships: ["SUPPLIER", "CUSTOMER"],
    });
  });

  it("rejects unsupported party relationships and short deactivation reasons", () => {
    expect(() => normalizeAndValidateBusinessParty({
      schema_version: "1.0.0",
      display_name: "Synthetic Partner",
      relationships: ["TAX_AUTHORITY"],
    })).toThrowError(BusinessValidationError);
    expect(() => normalizeAndValidateBusinessPartyDeactivation({ schema_version: "1.0.0", reason: "No" })).toThrowError(BusinessValidationError);
  });

  it("validates quotation conversion dates and invoice identifiers", () => {
    expect(normalizeAndValidateQuotationConversion({ schema_version: "1.0.0", invoice_number: "inv-2026-9001", issue_date: "2026-08-10", due_date: "2026-09-10" })).toEqual({ schema_version: "1.0.0", invoice_number: "INV-2026-9001", issue_date: "2026-08-10", due_date: "2026-09-10" });
    expect(() => normalizeAndValidateQuotationConversion({ schema_version: "1.0.0", invoice_number: "INV 1", issue_date: "2026-08-10", due_date: "2026-08-09" })).toThrow(BusinessValidationError);
  });

  it("enforces immutable quotation lifecycle boundaries", () => {
    expect(evaluateQuotationLifecycle({ status: "ISSUED", action: "EDIT", validUntil: "2026-09-01", today: "2026-08-14" }).allowed).toBe(true);
    expect(evaluateQuotationLifecycle({ status: "ISSUED", action: "EXPIRE", validUntil: "2026-08-13", today: "2026-08-14" })).toMatchObject({ allowed: true, targetStatus: "EXPIRED" });
    expect(evaluateQuotationLifecycle({ status: "ISSUED", action: "ACCEPT", validUntil: "2026-08-13", today: "2026-08-14" }).allowed).toBe(false);
    expect(evaluateQuotationLifecycle({ status: "ACCEPTED", action: "EDIT", validUntil: "2026-09-01", today: "2026-08-14" }).allowed).toBe(false);
    expect(evaluateQuotationLifecycle({ status: "ACCEPTED", action: "CONVERT", validUntil: "2026-09-01", today: "2026-08-14" }).allowed).toBe(true);
    expect(evaluateQuotationLifecycle({ status: "CONVERTED", action: "REJECT", validUntil: "2026-09-01", today: "2026-08-14" }).allowed).toBe(false);
  });

  it("requires a meaningful quotation rejection reason", () => {
    expect(normalizeAndValidateQuotationRejection({ schema_version: "1.0.0", reason: "Customer selected another proposal." })).toEqual({
      schema_version: "1.0.0",
      reason: "Customer selected another proposal.",
    });
    expect(() => normalizeAndValidateQuotationRejection({ schema_version: "1.0.0", reason: "No" })).toThrowError(BusinessValidationError);
  });
  it("derives quotation totals from integer quantity micros and cents", () => {
    const quotation = normalizeAndValidateQuotation({
      schema_version: "1.0.0",
      customer_party_id: "party-0001-customer",
      quotation_number: "quo-2026-1001",
      currency: "nad",
      issue_date: "2026-08-09",
      valid_until: "2026-09-09",
      lines: [{
        product_id: "prod-0001",
        description: "Implementation services",
        quantity_micros: 2_500_000,
        unit_code: "hour",
        unit_price_cents: 100_00,
        tax_category: "STANDARD",
        tax_rate_bps: 1500,
      }],
    });
    expect(quotation.lines[0].net_amount_cents).toBe(25_000);
    expect(quotation.tax_cents).toBe(3_750);
    expect(quotation.total_cents).toBe(28_750);
    expect(quotation.currency).toBe("NAD");
  });

  it("rejects a tax rate on a zero-rated quotation line", () => {
    expect(() => normalizeAndValidateQuotation({
      schema_version: "1.0.0",
      customer_party_id: "party-0001-customer",
      quotation_number: "QUO-1002",
      currency: "NAD",
      issue_date: "2026-08-09",
      valid_until: "2026-09-09",
      lines: [{ description: "Zero-rated goods", quantity_micros: 1_000_000, unit_code: "EA", unit_price_cents: 1000, tax_category: "ZERO_RATED", tax_rate_bps: 1500 }],
    })).toThrowError(BusinessValidationError);
  });

  it("accepts a balanced journal and rejects an unbalanced journal", () => {
    const balanced = {
      schema_version: "1.0.0",
      journal_number: "JRN-1001",
      journal_date: "2026-08-09",
      description: "Balanced entry",
      currency: "NAD",
      source_type: "MANUAL",
      lines: [
        { account_id: "acct-1000", description: "Debit", debit_cents: 50_000, credit_cents: 0 },
        { account_id: "acct-4000", description: "Credit", debit_cents: 0, credit_cents: 50_000 },
      ],
    } as const;
    expect(normalizeAndValidateJournal(balanced).lines).toHaveLength(2);
    expect(() => normalizeAndValidateJournal({ ...balanced, lines: [balanced.lines[0], { ...balanced.lines[1], credit_cents: 49_999 }] })).toThrowError(BusinessValidationError);
  });

  it("requires expense totals to reconcile", () => {
    expect(() => normalizeAndValidateExpense({
      schema_version: "1.0.0",
      category_id: "expcat-0001",
      expense_number: "EXP-1001",
      expense_date: "2026-08-09",
      description: "Travel",
      currency: "NAD",
      net_cents: 10_000,
      tax_cents: 1_500,
      total_cents: 11_499,
    })).toThrowError(BusinessValidationError);
  });

  it("normalizes outbound inventory as a signed movement", () => {
    const movement = normalizeAndValidateStockMovement({
      schema_version: "1.0.0",
      warehouse_id: "wh-0001",
      product_id: "prod-0001",
      movement_type: "ISSUE",
      quantity_micros: 2_000_000,
      unit_cost_cents: 290_000,
      reference_type: "ORDER",
      reference_id: "order-1001",
      reason: "Customer dispatch",
      occurred_at: "2026-08-09T12:00:00Z",
    });
    expect(movement.quantity_micros).toBe(-2_000_000);
  });

  it("rejects a project whose end date precedes its start date", () => {
    expect(() => normalizeAndValidateProject({
      schema_version: "1.0.0",
      code: "PROJECT-1",
      name: "Project one",
      currency: "NAD",
      start_date: "2026-09-01",
      end_date: "2026-08-01",
    })).toThrowError(BusinessValidationError);
  });

  it("normalizes a well-formed account and rejects an unsupported account_type", () => {
    const result = normalizeAndValidateAccount({ schema_version: "1.0.0", code: "6000", name: "Office supplies", account_type: "expense", currency: "nad", control_type: "expense" });
    expect(result).toEqual({ schema_version: "1.0.0", code: "6000", name: "Office supplies", account_type: "EXPENSE", currency: "NAD", control_type: "EXPENSE" });
    expect(() => normalizeAndValidateAccount({ schema_version: "1.0.0", code: "6001", name: "Office supplies", account_type: "CONTRA", currency: "NAD" })).toThrowError(BusinessValidationError);
  });

  it("rejects an account code containing unsupported characters", () => {
    expect(() => normalizeAndValidateAccount({ schema_version: "1.0.0", code: "6000 A", name: "Office supplies", account_type: "EXPENSE", currency: "NAD" })).toThrowError(BusinessValidationError);
  });

  it("requires a meaningful journal reversal reason", () => {
    expect(normalizeAndValidateJournalReversal({ schema_version: "1.0.0", reason: "Posted against the wrong account in error." })).toEqual({ schema_version: "1.0.0", reason: "Posted against the wrong account in error." });
    expect(() => normalizeAndValidateJournalReversal({ schema_version: "1.0.0", reason: "Oops" })).toThrowError(BusinessValidationError);
  });

  it("requires period_code to use YYYY-MM", () => {
    expect(normalizeAndValidatePeriodClose({ schema_version: "1.0.0", period_code: "2026-07" })).toEqual({ schema_version: "1.0.0", period_code: "2026-07" });
    expect(() => normalizeAndValidatePeriodClose({ schema_version: "1.0.0", period_code: "Q3-2026" })).toThrowError(BusinessValidationError);
  });
});
