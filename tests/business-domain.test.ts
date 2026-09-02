import { describe, expect, it } from "vitest";
import {
  BusinessValidationError,
  evaluateExpenseDecision,
  evaluateQuotationLifecycle,
  normalizeAndValidateAccount,
  normalizeAndValidateBusinessParty,
  normalizeAndValidateBusinessPartyDeactivation,
  normalizeAndValidateExpense,
  normalizeAndValidateExpenseCategory,
  normalizeAndValidateExpenseRejection,
  normalizeAndValidateJournal,
  normalizeAndValidateJournalReversal,
  normalizeAndValidatePeriodClose,
  normalizeAndValidateProduct,
  normalizeAndValidateProject,
  normalizeAndValidateProjectBudgetApproval,
  normalizeAndValidateProjectCost,
  normalizeAndValidateQuotation,
  normalizeAndValidateQuotationConversion,
  normalizeAndValidateQuotationRejection,
  normalizeAndValidateStockMovement,
  normalizeAndValidateStockTransfer,
  normalizeAndValidateWarehouse,
  normalizePartySearchQuery,
  normalizeQuotationSearchQuery,
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

  it("allows sending a draft quotation and blocks sending a non-draft one", () => {
    expect(evaluateQuotationLifecycle({ status: "DRAFT", action: "SEND", validUntil: "2026-09-01", today: "2026-08-14" })).toMatchObject({ allowed: true, targetStatus: "ISSUED" });
    expect(evaluateQuotationLifecycle({ status: "ISSUED", action: "SEND", validUntil: "2026-09-01", today: "2026-08-14" }).allowed).toBe(false);
    expect(evaluateQuotationLifecycle({ status: "ACCEPTED", action: "SEND", validUntil: "2026-09-01", today: "2026-08-14" }).allowed).toBe(false);
  });

  it("allows editing a draft or issued quotation but blocks editing any other status", () => {
    expect(evaluateQuotationLifecycle({ status: "DRAFT", action: "EDIT", validUntil: "2026-09-01", today: "2026-08-14" })).toMatchObject({ allowed: true, targetStatus: "DRAFT" });
    expect(evaluateQuotationLifecycle({ status: "ISSUED", action: "EDIT", validUntil: "2026-09-01", today: "2026-08-14" })).toMatchObject({ allowed: true, targetStatus: "ISSUED" });
    expect(evaluateQuotationLifecycle({ status: "REJECTED", action: "EDIT", validUntil: "2026-09-01", today: "2026-08-14" }).allowed).toBe(false);
    expect(evaluateQuotationLifecycle({ status: "EXPIRED", action: "EDIT", validUntil: "2026-09-01", today: "2026-08-14" }).allowed).toBe(false);
    expect(evaluateQuotationLifecycle({ status: "CONVERTED", action: "EDIT", validUntil: "2026-09-01", today: "2026-08-14" }).allowed).toBe(false);
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

  it("normalizes a well-formed expense category, defaulting requires_receipt to true", () => {
    const result = normalizeAndValidateExpenseCategory({ schema_version: "1.0.0", code: "travel", name: "Travel", default_tax_category: "standard" });
    expect(result).toEqual({ schema_version: "1.0.0", code: "TRAVEL", name: "Travel", default_tax_category: "STANDARD", requires_receipt: true });
  });

  it("honors an explicit requires_receipt=false on an expense category", () => {
    const result = normalizeAndValidateExpenseCategory({ schema_version: "1.0.0", code: "BANK-FEES", name: "Bank fees", default_tax_category: "EXEMPT", requires_receipt: false });
    expect(result.requires_receipt).toBe(false);
  });

  it("rejects an expense category with an unsupported default_tax_category", () => {
    expect(() => normalizeAndValidateExpenseCategory({ schema_version: "1.0.0", code: "TRAVEL", name: "Travel", default_tax_category: "LUXURY" })).toThrowError(BusinessValidationError);
  });

  it("requires a meaningful expense rejection reason", () => {
    expect(normalizeAndValidateExpenseRejection({ schema_version: "1.0.0", reason: "Receipt does not match the claimed amount." })).toEqual({ schema_version: "1.0.0", reason: "Receipt does not match the claimed amount." });
    expect(() => normalizeAndValidateExpenseRejection({ schema_version: "1.0.0", reason: "No" })).toThrowError(BusinessValidationError);
  });

  it("normalizes a project budget approval with an independent approved amount", () => {
    const result = normalizeAndValidateProjectBudgetApproval({ schema_version: "1.0.0", approved_amount_cents: 750_000, notes: "Approved at a reduced amount pending phase 2 scoping." });
    expect(result.approved_amount_cents).toBe(750_000);
    expect(result.notes).toContain("reduced amount");
  });

  it("rejects a negative approved_amount_cents", () => {
    expect(() => normalizeAndValidateProjectBudgetApproval({ schema_version: "1.0.0", approved_amount_cents: -1 })).toThrowError(BusinessValidationError);
  });

  it("normalizes an EXPENSE-type project cost citing only a source_id", () => {
    const result = normalizeAndValidateProjectCost({ schema_version: "1.0.0", cost_type: "expense", source_id: "expense-0001" });
    expect(result).toEqual({ schema_version: "1.0.0", cost_type: "EXPENSE", source_id: "expense-0001" });
  });

  it("normalizes a MANUAL-type project cost requiring amount/currency/description", () => {
    const result = normalizeAndValidateProjectCost({ schema_version: "1.0.0", cost_type: "MANUAL", source_id: "ext-invoice-001", amount_cents: 45_000, currency: "nad", description: "External contractor invoice not yet in the system.", occurred_at: "2026-07-15" });
    expect(result).toMatchObject({ cost_type: "MANUAL", amount_cents: 45_000, currency: "NAD", occurred_at: "2026-07-15" });
  });

  it("rejects a MANUAL project cost missing amount_cents", () => {
    expect(() => normalizeAndValidateProjectCost({ schema_version: "1.0.0", cost_type: "MANUAL", source_id: "ext-invoice-002", currency: "NAD", description: "Missing amount." })).toThrowError(BusinessValidationError);
  });

  it("rejects an unsupported project cost_type", () => {
    expect(() => normalizeAndValidateProjectCost({ schema_version: "1.0.0", cost_type: "AUTOMATIC", source_id: "x" })).toThrowError(BusinessValidationError);
  });
});

describe("party search query normalization", () => {
  it("applies defaults when the query is empty", () => {
    expect(normalizePartySearchQuery(new URLSearchParams())).toEqual({ relationship: null, q: null, status: null, limit: 50, offset: 0 });
  });

  it("normalizes relationship and status, uppercasing both", () => {
    const result = normalizePartySearchQuery(new URLSearchParams({ relationship: "supplier", status: "active", q: "acme" }));
    expect(result).toEqual({ relationship: "SUPPLIER", status: "ACTIVE", q: "acme", limit: 50, offset: 0 });
  });

  it("rejects an unsupported relationship or status", () => {
    expect(() => normalizePartySearchQuery(new URLSearchParams({ relationship: "TAX_AUTHORITY" }))).toThrowError(BusinessValidationError);
    expect(() => normalizePartySearchQuery(new URLSearchParams({ status: "PENDING" }))).toThrowError(BusinessValidationError);
  });

  it("rejects a limit outside 1 to 200 and a negative offset", () => {
    expect(() => normalizePartySearchQuery(new URLSearchParams({ limit: "0" }))).toThrowError(BusinessValidationError);
    expect(() => normalizePartySearchQuery(new URLSearchParams({ limit: "500" }))).toThrowError(BusinessValidationError);
    expect(() => normalizePartySearchQuery(new URLSearchParams({ offset: "-5" }))).toThrowError(BusinessValidationError);
  });

  it("accepts an explicit limit and offset within bounds", () => {
    const result = normalizePartySearchQuery(new URLSearchParams({ limit: "10", offset: "20" }));
    expect(result.limit).toBe(10);
    expect(result.offset).toBe(20);
  });

  it("defaults an empty quotation search query", () => {
    expect(normalizeQuotationSearchQuery(new URLSearchParams())).toEqual({ status: null, customerPartyId: null, q: null, limit: 50, offset: 0 });
  });

  it("normalizes quotation status and passes through customer_party_id and q", () => {
    const result = normalizeQuotationSearchQuery(new URLSearchParams({ status: "issued", customer_party_id: "party-001", q: "Q-2026" }));
    expect(result).toEqual({ status: "ISSUED", customerPartyId: "party-001", q: "Q-2026", limit: 50, offset: 0 });
  });

  it("rejects an unsupported quotation status", () => {
    expect(() => normalizeQuotationSearchQuery(new URLSearchParams({ status: "PENDING" }))).toThrowError(BusinessValidationError);
  });

  it("rejects a quotation search limit outside 1 to 200 and a negative offset", () => {
    expect(() => normalizeQuotationSearchQuery(new URLSearchParams({ limit: "0" }))).toThrowError(BusinessValidationError);
    expect(() => normalizeQuotationSearchQuery(new URLSearchParams({ limit: "500" }))).toThrowError(BusinessValidationError);
    expect(() => normalizeQuotationSearchQuery(new URLSearchParams({ offset: "-5" }))).toThrowError(BusinessValidationError);
  });

  it("normalizes a governed product record", () => {
    expect(normalizeAndValidateProduct({
      schema_version: "1.0.0",
      sku: "  desk-lamp  ",
      name: "Desk Lamp",
      description: "LED desk lamp",
      unit_code: "ea",
      tax_category: "standard",
      tax_rate_bps: 1_500,
      sales_price_cents: 9_900,
      cost_price_cents: 5_000,
    })).toMatchObject({ sku: "DESK-LAMP", unit_code: "EA", tax_category: "STANDARD" });
  });

  it("rejects a product with an unsupported tax category or a non-zero rate on a non-standard category", () => {
    expect(() => normalizeAndValidateProduct({ schema_version: "1.0.0", sku: "X1", name: "Item", unit_code: "EA", tax_category: "MADE_UP", tax_rate_bps: 0, sales_price_cents: 100, cost_price_cents: 50 })).toThrowError(BusinessValidationError);
    expect(() => normalizeAndValidateProduct({ schema_version: "1.0.0", sku: "X2", name: "Item", unit_code: "EA", tax_category: "ZERO_RATED", tax_rate_bps: 1_500, sales_price_cents: 100, cost_price_cents: 50 })).toThrowError(BusinessValidationError);
  });

  it("normalizes a governed warehouse record", () => {
    expect(normalizeAndValidateWarehouse({
      schema_version: "1.0.0",
      code: "wh-north",
      name: "Northern Warehouse",
      address: "1 Storage Road",
    })).toMatchObject({ code: "WH-NORTH", name: "Northern Warehouse" });
  });

  it("rejects a warehouse with a too-short address or an invalid code", () => {
    expect(() => normalizeAndValidateWarehouse({ schema_version: "1.0.0", code: "WH-1", name: "Warehouse", address: "X" })).toThrowError(BusinessValidationError);
    expect(() => normalizeAndValidateWarehouse({ schema_version: "1.0.0", code: "wh 1!", name: "Warehouse", address: "1 Storage Road" })).toThrowError(BusinessValidationError);
  });

  it("normalizes a stock transfer and derives no unit cost from the payload", () => {
    const transfer = normalizeAndValidateStockTransfer({
      schema_version: "1.0.0",
      from_warehouse_id: "wh-0001",
      to_warehouse_id: "wh-0002",
      product_id: "prod-0001",
      quantity_micros: 5_000_000,
      reason: "Rebalancing stock between sites",
    });
    expect(transfer).toMatchObject({ from_warehouse_id: "wh-0001", to_warehouse_id: "wh-0002", quantity_micros: 5_000_000 });
    expect(transfer).not.toHaveProperty("unit_cost_cents");
  });

  it("rejects a stock transfer between the same warehouse or a non-positive quantity", () => {
    expect(() => normalizeAndValidateStockTransfer({ schema_version: "1.0.0", from_warehouse_id: "wh-0001", to_warehouse_id: "wh-0001", product_id: "prod-0001", quantity_micros: 1_000_000, reason: "Same warehouse" })).toThrowError(BusinessValidationError);
    expect(() => normalizeAndValidateStockTransfer({ schema_version: "1.0.0", from_warehouse_id: "wh-0001", to_warehouse_id: "wh-0002", product_id: "prod-0001", quantity_micros: 0, reason: "Zero quantity" })).toThrowError(BusinessValidationError);
  });
});
