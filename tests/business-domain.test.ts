import { describe, expect, it } from "vitest";
import {
  BusinessValidationError,
  evaluateExpenseDecision,
  evaluateQuotationLifecycle,
  normalizeAndValidateBusinessParty,
  normalizeAndValidateBusinessPartyDeactivation,
  normalizeAndValidateExpense,
  normalizeAndValidateExpenseDecision,
  normalizeAndValidateExpenseReceiptLink,
  normalizeAndValidateJournal,
  normalizeAndValidateProject,
  normalizeAndValidateQuotation,
  normalizeAndValidateQuotationConversion,
  normalizeAndValidateQuotationRejection,
  normalizeAndValidateStockMovement,
} from "@/lib/domain/business";
import { evaluateCounterpartyTrust, normalizeSyntheticCounterpartyVerification } from "@/lib/domain/counterparty-trust";

describe("business command validation", () => {
  it("normalizes a governed customer and supplier record", () => {
    expect(normalizeAndValidateBusinessParty({
      schema_version: "1.0.0",
      display_name: "  Synthetic Trade Partner  ",
      legal_name: "Synthetic Trade Partner (Pty) Ltd",
      vat_number: "vat-1000999",
      tin: "tin-1000999",
      company_registration_number: "cc/2026/00999",
      email: "ACCOUNTS@EXAMPLE.TEST",
      phone: "+264 61 000 999",
      relationships: ["supplier", "CUSTOMER", "SUPPLIER"],
    })).toMatchObject({
      display_name: "Synthetic Trade Partner",
      vat_number: "VAT-1000999",
      tin: "TIN-1000999",
      company_registration_number: "CC/2026/00999",
      email: "accounts@example.test",
      relationships: ["SUPPLIER", "CUSTOMER"],
    });
  });

  it("keeps synthetic counterparty matching explainable and non-authoritative", () => {
    const submission = normalizeSyntheticCounterpartyVerification({
      schema_version: "1.0.0",
      authority_record: {
        legal_name: "Synthetic Trade Partner (Pty) Ltd",
        vat_number: "vat-1000999",
        tin: "tin-1000999",
        company_registration_number: "cc/2026/00999",
        tax_registration_status: "ACTIVE",
      },
    });
    expect(evaluateCounterpartyTrust({
      legalName: "Synthetic Trade Partner (Pty) Ltd",
      vatNumber: "VAT-1000999",
      tin: "TIN-1000999",
      companyRegistrationNumber: "CC/2026/00999",
    }, submission.authority_record)).toMatchObject({
      trustStatus: "SYNTHETIC_VALID",
      confidenceBps: 10000,
      matchedFields: ["vat_number", "tin", "company_registration_number", "legal_name"],
    });
  });

  it("rejects conflicting and inactive tax evidence without confusing identity and tax status", () => {
    const mismatch = evaluateCounterpartyTrust({ legalName: "Partner", vatNumber: "VAT-1" }, {
      legal_name: "Partner", vat_number: "VAT-2", tax_registration_status: "ACTIVE",
    });
    expect(mismatch).toMatchObject({ trustStatus: "MISMATCH", conflictingFields: ["vat_number"], reasonCode: "COUNTERPARTY_AUTHORITY_MISMATCH" });
    const inactive = evaluateCounterpartyTrust({ legalName: "Partner", vatNumber: "VAT-1" }, {
      legal_name: "Partner", vat_number: "VAT-1", tax_registration_status: "SUSPENDED",
    });
    expect(inactive).toMatchObject({ trustStatus: "SYNTHETIC_VALID", taxRegistrationStatus: "SUSPENDED" });
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

  it("requires a supplier for tax-bearing expenses", () => {
    expect(() => normalizeAndValidateExpense({
      schema_version: "1.0.0", category_id: "expcat-0001", expense_number: "EXP-TRUST-1", expense_date: "2026-08-23",
      description: "Taxed synthetic expense", currency: "NAD", net_cents: 10_000, tax_cents: 1_500, total_cents: 11_500,
    })).toThrow(/validation/i);
  });

  it("normalizes expense decisions and disables emergency overrides", () => {
    expect(normalizeAndValidateExpenseDecision({ schema_version: "1.0.0", decision: "approve", reason: "Evidence and totals independently reviewed." })).toEqual({
      schema_version: "1.0.0",
      decision: "APPROVE",
      reason: "Evidence and totals independently reviewed.",
    });
    expect(() => normalizeAndValidateExpenseDecision({ schema_version: "1.0.0", decision: "REJECT", reason: "Evidence missing.", emergency_override: true })).toThrowError(BusinessValidationError);
  });

  it("normalizes an expense receipt link", () => {
    expect(normalizeAndValidateExpenseReceiptLink({ schema_version: "1.0.0", receipt_document_id: "doc-expense-1001" })).toEqual({
      schema_version: "1.0.0",
      receipt_document_id: "doc-expense-1001",
    });
    expect(() => normalizeAndValidateExpenseReceiptLink({ schema_version: "1.0.0", receipt_document_id: "?" })).toThrowError(BusinessValidationError);
  });

  it("enforces independent, receipt-gated draft expense decisions", () => {
    const cleanReceipt = { receiptRequired: true, receiptDocumentId: "doc-1", receiptScanStatus: "CLEAN", receiptStatus: "AVAILABLE" };
    expect(evaluateExpenseDecision({ status: "DRAFT", createdBy: "maker", actorId: "checker", decision: "APPROVE", ...cleanReceipt })).toMatchObject({ allowed: true, targetStatus: "APPROVED" });
    expect(evaluateExpenseDecision({ status: "DRAFT", createdBy: "maker", actorId: "maker", decision: "APPROVE", ...cleanReceipt }).allowed).toBe(false);
    expect(evaluateExpenseDecision({ status: "APPROVED", createdBy: "maker", actorId: "checker", decision: "REJECT", ...cleanReceipt }).allowed).toBe(false);
    expect(evaluateExpenseDecision({ status: "DRAFT", createdBy: "maker", actorId: "checker", decision: "APPROVE", receiptRequired: true, receiptDocumentId: null, receiptScanStatus: null, receiptStatus: null }).allowed).toBe(false);
    expect(evaluateExpenseDecision({ status: "DRAFT", createdBy: "maker", actorId: "checker", decision: "APPROVE", receiptRequired: true, receiptDocumentId: "doc-2", receiptScanStatus: "PENDING_EXTERNAL_SCANNER", receiptStatus: "QUARANTINED" }).allowed).toBe(false);
    expect(evaluateExpenseDecision({ status: "DRAFT", createdBy: "maker", actorId: "checker", decision: "REJECT", receiptRequired: true, receiptDocumentId: null, receiptScanStatus: null, receiptStatus: null }).allowed).toBe(true);
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
});
