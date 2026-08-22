export type BusinessValidationMessage = { code: string; path: string; message: string };

export class BusinessValidationError extends Error {
  readonly messages: BusinessValidationMessage[];

  constructor(messages: BusinessValidationMessage[]) {
    super("Business command failed validation.");
    this.name = "BusinessValidationError";
    this.messages = messages;
  }
}

export type BusinessPartyRelationship = "CUSTOMER" | "SUPPLIER";

export type BusinessPartySubmission = {
  schema_version: "1.0.0";
  display_name: string;
  legal_name?: string;
  vat_number?: string;
  tin?: string;
  email?: string;
  phone?: string;
  address?: string;
  relationships: BusinessPartyRelationship[];
};

export type BusinessPartyDeactivationSubmission = {
  schema_version: "1.0.0";
  reason: string;
};

export type QuotationLineInput = {
  product_id?: string;
  description: string;
  quantity_micros: number;
  unit_code: string;
  unit_price_cents: number;
  tax_category: "STANDARD" | "ZERO_RATED" | "EXEMPT" | "OUT_OF_SCOPE";
  tax_rate_bps: number;
};

export type QuotationSubmission = {
  schema_version: "1.0.0";
  customer_party_id: string;
  branch_id?: string;
  quotation_number: string;
  currency: string;
  issue_date: string;
  valid_until: string;
  notes?: string;
  lines: QuotationLineInput[];
};

export type NormalizedQuotation = Omit<QuotationSubmission, "lines"> & {
  lines: Array<QuotationLineInput & { line_number: number; net_amount_cents: number; tax_amount_cents: number }>;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
};

export type JournalSubmission = {
  schema_version: "1.0.0";
  journal_number: string;
  journal_date: string;
  reference?: string;
  description: string;
  currency: string;
  source_type: "MANUAL" | "EXPENSE" | "INVOICE" | "IMPORT" | "ADJUSTMENT";
  source_id?: string;
  lines: Array<{
    account_id: string;
    branch_id?: string;
    project_id?: string;
    description: string;
    debit_cents: number;
    credit_cents: number;
    tax_code?: string;
  }>;
};

export type ExpenseSubmission = {
  schema_version: "1.0.0";
  category_id: string;
  supplier_party_id?: string;
  project_id?: string;
  branch_id?: string;
  expense_number: string;
  expense_date: string;
  description: string;
  currency: string;
  net_cents: number;
  tax_cents: number;
  total_cents: number;
};

export type ExpenseDecisionSubmission = {
  schema_version: "1.0.0";
  decision: "APPROVE" | "REJECT";
  reason: string;
};

export type ExpenseReceiptLinkSubmission = {
  schema_version: "1.0.0";
  receipt_document_id: string;
};

export type ExpenseDecisionEvaluation = {
  allowed: boolean;
  targetStatus: "APPROVED" | "REJECTED";
  reason: string;
};

export type StockMovementSubmission = {
  schema_version: "1.0.0";
  warehouse_id: string;
  product_id: string;
  movement_type: "RECEIPT" | "ISSUE" | "TRANSFER_IN" | "TRANSFER_OUT" | "ADJUSTMENT_IN" | "ADJUSTMENT_OUT";
  quantity_micros: number;
  unit_cost_cents: number;
  reference_type: string;
  reference_id: string;
  reason: string;
  occurred_at?: string;
};

export type ProjectSubmission = {
  schema_version: "1.0.0";
  code: string;
  name: string;
  customer_party_id?: string;
  currency: string;
  start_date: string;
  end_date?: string;
  budget_cents?: number;
};

export type QuotationConversionSubmission = {
  schema_version: "1.0.0";
  invoice_number: string;
  issue_date: string;
  due_date?: string;
};

export type QuotationRejectionSubmission = {
  schema_version: "1.0.0";
  reason: string;
};

export type QuotationLifecycleAction = "EDIT" | "ACCEPT" | "REJECT" | "EXPIRE" | "CONVERT";
export type QuotationLifecycleStatus = "ISSUED" | "ACCEPTED" | "REJECTED" | "EXPIRED" | "CONVERTED";

export type QuotationLifecycleEvaluation = {
  allowed: boolean;
  targetStatus: QuotationLifecycleStatus;
  reason: string;
};

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const ISO_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/;
const CODE_PATTERN = /^[A-Z0-9][A-Z0-9._/-]{1,39}$/;
const CURRENCY_PATTERN = /^[A-Z]{3}$/;
const ID_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/;
const TAX_CATEGORIES = new Set(["STANDARD", "ZERO_RATED", "EXEMPT", "OUT_OF_SCOPE"]);
const STOCK_MOVEMENT_TYPES = new Set(["RECEIPT", "ISSUE", "TRANSFER_IN", "TRANSFER_OUT", "ADJUSTMENT_IN", "ADJUSTMENT_OUT"]);
const PARTY_RELATIONSHIPS = new Set<BusinessPartyRelationship>(["CUSTOMER", "SUPPLIER"]);

function record(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    throw new BusinessValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be an object." }]);
  }
  return value as Record<string, unknown>;
}

function textValue(value: unknown): string {
  return typeof value === "string" ? value.trim().replaceAll(/\s+/g, " ") : "";
}

function textField(value: unknown, path: string, label: string, min: number, max: number, messages: BusinessValidationMessage[]): string {
  const normalized = textValue(value);
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

function optionalText(value: unknown, path: string, label: string, max: number, messages: BusinessValidationMessage[]): string | undefined {
  const normalized = textValue(value);
  if (!normalized) return undefined;
  if (normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must not exceed ${max} characters.` });
  return normalized;
}

function idField(value: unknown, path: string, label: string, messages: BusinessValidationMessage[], optional = false): string | undefined {
  const normalized = textValue(value);
  if (!normalized && optional) return undefined;
  if (!ID_PATTERN.test(normalized)) messages.push({ code: "IDENTIFIER_INVALID", path, message: `${label} is invalid.` });
  return normalized;
}

function integerField(value: unknown, path: string, label: string, messages: BusinessValidationMessage[], min = 0): number {
  if (!Number.isSafeInteger(value) || Number(value) < min) {
    messages.push({ code: "INTEGER_INVALID", path, message: `${label} must be a safe integer greater than or equal to ${min}.` });
    return 0;
  }
  return Number(value);
}

function dateField(value: unknown, path: string, label: string, messages: BusinessValidationMessage[]): string {
  const normalized = textValue(value);
  if (!DATE_PATTERN.test(normalized) || Number.isNaN(Date.parse(`${normalized}T00:00:00Z`))) messages.push({ code: "DATE_INVALID", path, message: `${label} must be a valid ISO date.` });
  return normalized;
}

function currencyField(value: unknown, messages: BusinessValidationMessage[]): string {
  const currency = textValue(value).toUpperCase();
  if (!CURRENCY_PATTERN.test(currency)) messages.push({ code: "CURRENCY_INVALID", path: "/currency", message: "Currency must be a three-letter ISO 4217 code." });
  return currency;
}

function schemaVersion(input: Record<string, unknown>, messages: BusinessValidationMessage[]) {
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
}

export function normalizeAndValidateBusinessParty(payload: unknown): BusinessPartySubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const displayName = textField(input.display_name, "/display_name", "Display name", 2, 200, messages);
  const legalName = optionalText(input.legal_name, "/legal_name", "Legal name", 200, messages);
  const vatNumber = optionalText(input.vat_number, "/vat_number", "VAT number", 40, messages)?.toUpperCase();
  const tin = optionalText(input.tin, "/tin", "TIN", 40, messages)?.toUpperCase();
  const email = optionalText(input.email, "/email", "Email", 254, messages)?.toLowerCase();
  const phone = optionalText(input.phone, "/phone", "Phone", 40, messages);
  const address = optionalText(input.address, "/address", "Address", 1_000, messages);

  if (vatNumber && !/^[A-Z0-9][A-Z0-9 ._/-]{1,39}$/.test(vatNumber)) {
    messages.push({ code: "VAT_NUMBER_INVALID", path: "/vat_number", message: "VAT number contains unsupported characters." });
  }
  if (tin && !/^[A-Z0-9][A-Z0-9 ._/-]{1,39}$/.test(tin)) {
    messages.push({ code: "TIN_INVALID", path: "/tin", message: "TIN contains unsupported characters." });
  }
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    messages.push({ code: "EMAIL_INVALID", path: "/email", message: "Email must be a valid address." });
  }
  if (phone && !/^\+?[0-9][0-9 ()-]{5,39}$/.test(phone)) {
    messages.push({ code: "PHONE_INVALID", path: "/phone", message: "Phone contains unsupported characters." });
  }

  const rawRelationships = Array.isArray(input.relationships) ? input.relationships : [];
  const relationships = [...new Set(rawRelationships.map((value) => textValue(value).toUpperCase()))];
  if (relationships.length < 1) {
    messages.push({ code: "RELATIONSHIP_REQUIRED", path: "/relationships", message: "Select at least one customer or supplier relationship." });
  }
  for (const relationship of relationships) {
    if (!PARTY_RELATIONSHIPS.has(relationship as BusinessPartyRelationship)) {
      messages.push({ code: "RELATIONSHIP_INVALID", path: "/relationships", message: `${relationship || "Empty relationship"} is not supported.` });
    }
  }

  if (messages.length) throw new BusinessValidationError(messages);
  return {
    schema_version: "1.0.0",
    display_name: displayName,
    ...(legalName ? { legal_name: legalName } : {}),
    ...(vatNumber ? { vat_number: vatNumber } : {}),
    ...(tin ? { tin } : {}),
    ...(email ? { email } : {}),
    ...(phone ? { phone } : {}),
    ...(address ? { address } : {}),
    relationships: relationships as BusinessPartyRelationship[],
  };
}

export function normalizeAndValidateBusinessPartyDeactivation(payload: unknown): BusinessPartyDeactivationSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const reason = textField(input.reason, "/reason", "Deactivation reason", 5, 500, messages);
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", reason };
}

export function normalizeAndValidateQuotation(payload: unknown): NormalizedQuotation {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const customerPartyId = idField(input.customer_party_id, "/customer_party_id", "Customer party", messages) ?? "";
  const branchId = idField(input.branch_id, "/branch_id", "Branch", messages, true);
  const quotationNumber = textField(input.quotation_number, "/quotation_number", "Quotation number", 2, 40, messages).toUpperCase();
  if (quotationNumber && !CODE_PATTERN.test(quotationNumber)) messages.push({ code: "CODE_INVALID", path: "/quotation_number", message: "Quotation number contains unsupported characters." });
  const currency = currencyField(input.currency, messages);
  const issueDate = dateField(input.issue_date, "/issue_date", "Issue date", messages);
  const validUntil = dateField(input.valid_until, "/valid_until", "Valid-until date", messages);
  if (issueDate && validUntil && validUntil < issueDate) messages.push({ code: "DATE_ORDER_INVALID", path: "/valid_until", message: "Valid-until date cannot be earlier than issue date." });
  const notes = optionalText(input.notes, "/notes", "Notes", 2_000, messages);
  const rawLines = Array.isArray(input.lines) ? input.lines : [];
  if (rawLines.length < 1 || rawLines.length > 200) messages.push({ code: "LINE_COUNT_INVALID", path: "/lines", message: "A quotation must contain 1 to 200 lines." });
  let subtotal = 0;
  let taxTotal = 0;
  const lines = rawLines.slice(0, 200).map((rawLine, index) => {
    const line = rawLine && typeof rawLine === "object" && !Array.isArray(rawLine) ? rawLine as Record<string, unknown> : {};
    const path = `/lines/${index}`;
    const productId = idField(line.product_id, `${path}/product_id`, "Product", messages, true);
    const description = textField(line.description, `${path}/description`, "Description", 2, 500, messages);
    const quantityMicros = integerField(line.quantity_micros, `${path}/quantity_micros`, "Quantity micros", messages, 1);
    const unitCode = textField(line.unit_code, `${path}/unit_code`, "Unit code", 1, 12, messages).toUpperCase();
    const unitPriceCents = integerField(line.unit_price_cents, `${path}/unit_price_cents`, "Unit price cents", messages);
    const taxCategory = textValue(line.tax_category).toUpperCase() as QuotationLineInput["tax_category"];
    if (!TAX_CATEGORIES.has(taxCategory)) messages.push({ code: "TAX_CATEGORY_INVALID", path: `${path}/tax_category`, message: "Select a supported tax category." });
    const taxRateBps = integerField(line.tax_rate_bps, `${path}/tax_rate_bps`, "Tax rate basis points", messages);
    if (taxRateBps > 10_000) messages.push({ code: "TAX_RATE_INVALID", path: `${path}/tax_rate_bps`, message: "Tax rate cannot exceed 10000 basis points." });
    if (taxCategory !== "STANDARD" && taxRateBps !== 0) messages.push({ code: "TAX_RATE_CATEGORY_MISMATCH", path: `${path}/tax_rate_bps`, message: "Only standard-rated lines may have a non-zero tax rate." });
    const netAmountCents = Math.round((quantityMicros * unitPriceCents) / 1_000_000);
    const taxAmountCents = Math.round((netAmountCents * taxRateBps) / 10_000);
    if (!Number.isSafeInteger(netAmountCents) || !Number.isSafeInteger(taxAmountCents)) messages.push({ code: "AMOUNT_OVERFLOW", path, message: "Calculated line amounts exceed the supported integer range." });
    subtotal += netAmountCents;
    taxTotal += taxAmountCents;
    return { ...(productId ? { product_id: productId } : {}), description, quantity_micros: quantityMicros, unit_code: unitCode, unit_price_cents: unitPriceCents, tax_category: taxCategory, tax_rate_bps: taxRateBps, line_number: index + 1, net_amount_cents: netAmountCents, tax_amount_cents: taxAmountCents };
  });
  if (!Number.isSafeInteger(subtotal + taxTotal)) messages.push({ code: "AMOUNT_OVERFLOW", path: "/lines", message: "Quotation totals exceed the supported integer range." });
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", customer_party_id: customerPartyId, ...(branchId ? { branch_id: branchId } : {}), quotation_number: quotationNumber, currency, issue_date: issueDate, valid_until: validUntil, ...(notes ? { notes } : {}), lines, subtotal_cents: subtotal, tax_cents: taxTotal, total_cents: subtotal + taxTotal };
}

export function normalizeAndValidateQuotationConversion(payload: unknown): QuotationConversionSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const invoiceNumber = textField(input.invoice_number, "/invoice_number", "Invoice number", 2, 100, messages).toUpperCase();
  if (invoiceNumber && !/^[A-Z0-9][A-Z0-9._/-]{1,99}$/.test(invoiceNumber)) messages.push({ code: "CODE_INVALID", path: "/invoice_number", message: "Invoice number contains unsupported characters." });
  const issueDate = dateField(input.issue_date, "/issue_date", "Issue date", messages);
  const dueDate = textValue(input.due_date) ? dateField(input.due_date, "/due_date", "Due date", messages) : undefined;
  if (dueDate && dueDate < issueDate) messages.push({ code: "DATE_ORDER_INVALID", path: "/due_date", message: "Due date cannot be earlier than issue date." });
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", invoice_number: invoiceNumber, issue_date: issueDate, ...(dueDate ? { due_date: dueDate } : {}) };
}

export function normalizeAndValidateQuotationRejection(payload: unknown): QuotationRejectionSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const reason = textField(input.reason, "/reason", "Rejection reason", 5, 500, messages);
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", reason };
}

export function evaluateQuotationLifecycle(input: {
  status: string;
  action: QuotationLifecycleAction;
  validUntil: string;
  today: string;
}): QuotationLifecycleEvaluation {
  const targetStatus: Record<QuotationLifecycleAction, QuotationLifecycleStatus> = {
    EDIT: "ISSUED",
    ACCEPT: "ACCEPTED",
    REJECT: "REJECTED",
    EXPIRE: "EXPIRED",
    CONVERT: "CONVERTED",
  };
  if (input.action === "CONVERT") {
    return input.status === "ACCEPTED"
      ? { allowed: true, targetStatus: "CONVERTED", reason: "Accepted quotation may be converted." }
      : { allowed: false, targetStatus: "CONVERTED", reason: `Only an accepted quotation can be converted; current status is ${input.status}.` };
  }
  if (input.status !== "ISSUED") {
    return { allowed: false, targetStatus: targetStatus[input.action], reason: `Only an issued quotation can be ${input.action.toLowerCase()}ed; current status is ${input.status}.` };
  }
  const overdue = input.validUntil < input.today;
  if (input.action === "EXPIRE") {
    return overdue
      ? { allowed: true, targetStatus: "EXPIRED", reason: "The issued quotation is overdue and may be explicitly expired." }
      : { allowed: false, targetStatus: "EXPIRED", reason: "A quotation cannot be expired before its valid-until date has passed." };
  }
  if (overdue) {
    return { allowed: false, targetStatus: targetStatus[input.action], reason: "The quotation validity period has ended; expire it instead." };
  }
  return { allowed: true, targetStatus: targetStatus[input.action], reason: `The issued quotation may be ${input.action.toLowerCase()}ed.` };
}

export function normalizeAndValidateJournal(payload: unknown): JournalSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const journalNumber = textField(input.journal_number, "/journal_number", "Journal number", 2, 40, messages).toUpperCase();
  if (journalNumber && !CODE_PATTERN.test(journalNumber)) messages.push({ code: "CODE_INVALID", path: "/journal_number", message: "Journal number contains unsupported characters." });
  const journalDate = dateField(input.journal_date, "/journal_date", "Journal date", messages);
  const reference = optionalText(input.reference, "/reference", "Reference", 100, messages);
  const description = textField(input.description, "/description", "Description", 2, 500, messages);
  const currency = currencyField(input.currency, messages);
  const sourceType = textValue(input.source_type).toUpperCase() as JournalSubmission["source_type"];
  if (!new Set(["MANUAL", "EXPENSE", "INVOICE", "IMPORT", "ADJUSTMENT"]).has(sourceType)) messages.push({ code: "SOURCE_TYPE_INVALID", path: "/source_type", message: "Select a supported journal source type." });
  const sourceId = idField(input.source_id, "/source_id", "Source", messages, true);
  const rawLines = Array.isArray(input.lines) ? input.lines : [];
  if (rawLines.length < 2 || rawLines.length > 500) messages.push({ code: "LINE_COUNT_INVALID", path: "/lines", message: "A journal must contain 2 to 500 lines." });
  let debitTotal = 0;
  let creditTotal = 0;
  const lines = rawLines.slice(0, 500).map((rawLine, index) => {
    const line = rawLine && typeof rawLine === "object" && !Array.isArray(rawLine) ? rawLine as Record<string, unknown> : {};
    const path = `/lines/${index}`;
    const accountId = idField(line.account_id, `${path}/account_id`, "Account", messages) ?? "";
    const branchId = idField(line.branch_id, `${path}/branch_id`, "Branch", messages, true);
    const projectId = idField(line.project_id, `${path}/project_id`, "Project", messages, true);
    const lineDescription = textField(line.description, `${path}/description`, "Description", 2, 500, messages);
    const debitCents = integerField(line.debit_cents, `${path}/debit_cents`, "Debit cents", messages);
    const creditCents = integerField(line.credit_cents, `${path}/credit_cents`, "Credit cents", messages);
    if ((debitCents === 0) === (creditCents === 0)) messages.push({ code: "JOURNAL_SIDE_INVALID", path, message: "Each journal line must contain a positive debit or credit, but not both." });
    debitTotal += debitCents;
    creditTotal += creditCents;
    const taxCode = optionalText(line.tax_code, `${path}/tax_code`, "Tax code", 40, messages);
    return { account_id: accountId, ...(branchId ? { branch_id: branchId } : {}), ...(projectId ? { project_id: projectId } : {}), description: lineDescription, debit_cents: debitCents, credit_cents: creditCents, ...(taxCode ? { tax_code: taxCode } : {}) };
  });
  if (debitTotal <= 0 || debitTotal !== creditTotal) messages.push({ code: "JOURNAL_UNBALANCED", path: "/lines", message: "Total debits must equal total credits and be greater than zero." });
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", journal_number: journalNumber, journal_date: journalDate, ...(reference ? { reference } : {}), description, currency, source_type: sourceType, ...(sourceId ? { source_id: sourceId } : {}), lines };
}

export function normalizeAndValidateExpense(payload: unknown): ExpenseSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const categoryId = idField(input.category_id, "/category_id", "Expense category", messages) ?? "";
  const supplierPartyId = idField(input.supplier_party_id, "/supplier_party_id", "Supplier party", messages, true);
  const projectId = idField(input.project_id, "/project_id", "Project", messages, true);
  const branchId = idField(input.branch_id, "/branch_id", "Branch", messages, true);
  const expenseNumber = textField(input.expense_number, "/expense_number", "Expense number", 2, 40, messages).toUpperCase();
  const expenseDate = dateField(input.expense_date, "/expense_date", "Expense date", messages);
  const description = textField(input.description, "/description", "Description", 2, 500, messages);
  const currency = currencyField(input.currency, messages);
  const netCents = integerField(input.net_cents, "/net_cents", "Net cents", messages);
  const taxCents = integerField(input.tax_cents, "/tax_cents", "Tax cents", messages);
  const totalCents = integerField(input.total_cents, "/total_cents", "Total cents", messages);
  if (netCents + taxCents !== totalCents) messages.push({ code: "TOTAL_MISMATCH", path: "/total_cents", message: "Total cents must equal net cents plus tax cents." });
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", category_id: categoryId, ...(supplierPartyId ? { supplier_party_id: supplierPartyId } : {}), ...(projectId ? { project_id: projectId } : {}), ...(branchId ? { branch_id: branchId } : {}), expense_number: expenseNumber, expense_date: expenseDate, description, currency, net_cents: netCents, tax_cents: taxCents, total_cents: totalCents };
}

export function normalizeAndValidateExpenseDecision(payload: unknown): ExpenseDecisionSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const decision = textValue(input.decision).toUpperCase() as ExpenseDecisionSubmission["decision"];
  if (!new Set(["APPROVE", "REJECT"]).has(decision)) messages.push({ code: "DECISION_INVALID", path: "/decision", message: "Decision must be APPROVE or REJECT." });
  const reason = textField(input.reason, "/reason", "Decision reason", 5, 500, messages);
  if (input.emergency_override === true) messages.push({ code: "EMERGENCY_OVERRIDE_DISABLED", path: "/emergency_override", message: "Emergency segregation-of-duties override is disabled." });
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", decision, reason };
}

export function normalizeAndValidateExpenseReceiptLink(payload: unknown): ExpenseReceiptLinkSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const receiptDocumentId = idField(input.receipt_document_id, "/receipt_document_id", "Receipt document", messages) ?? "";
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", receipt_document_id: receiptDocumentId };
}

export function evaluateExpenseDecision(input: {
  status: string;
  createdBy: string;
  actorId: string;
  decision: "APPROVE" | "REJECT";
  receiptRequired: boolean;
  receiptDocumentId: string | null;
  receiptScanStatus: string | null;
  receiptStatus: string | null;
}): ExpenseDecisionEvaluation {
  const targetStatus = input.decision === "APPROVE" ? "APPROVED" : "REJECTED";
  if (input.status !== "DRAFT") return { allowed: false, targetStatus, reason: `Only a draft expense may be decided; current status is ${input.status}.` };
  if (input.createdBy === input.actorId) return { allowed: false, targetStatus, reason: "The expense creator cannot approve or reject their own expense." };
  if (input.decision === "APPROVE" && input.receiptRequired && !input.receiptDocumentId) {
    return { allowed: false, targetStatus, reason: "A clean receipt is required before this expense can be approved." };
  }
  if (input.decision === "APPROVE" && input.receiptDocumentId && (input.receiptScanStatus !== "CLEAN" || input.receiptStatus !== "AVAILABLE")) {
    return { allowed: false, targetStatus, reason: "The linked receipt must have CLEAN scan status and be AVAILABLE before approval." };
  }
  return { allowed: true, targetStatus, reason: `The independent reviewer may ${input.decision.toLowerCase()} this draft expense.` };
}

export function normalizeAndValidateStockMovement(payload: unknown): StockMovementSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const warehouseId = idField(input.warehouse_id, "/warehouse_id", "Warehouse", messages) ?? "";
  const productId = idField(input.product_id, "/product_id", "Product", messages) ?? "";
  const movementType = textValue(input.movement_type).toUpperCase() as StockMovementSubmission["movement_type"];
  if (!STOCK_MOVEMENT_TYPES.has(movementType)) messages.push({ code: "MOVEMENT_TYPE_INVALID", path: "/movement_type", message: "Select a supported stock movement type." });
  const rawQuantity = integerField(input.quantity_micros, "/quantity_micros", "Quantity micros", messages, 1);
  const quantityMicros = movementType.endsWith("OUT") || movementType === "ISSUE" ? -rawQuantity : rawQuantity;
  const unitCostCents = integerField(input.unit_cost_cents, "/unit_cost_cents", "Unit cost cents", messages);
  const referenceType = textField(input.reference_type, "/reference_type", "Reference type", 2, 40, messages).toUpperCase();
  const referenceId = idField(input.reference_id, "/reference_id", "Reference", messages) ?? "";
  const reason = textField(input.reason, "/reason", "Reason", 2, 500, messages);
  const occurredAt = textValue(input.occurred_at) || new Date().toISOString();
  if (!ISO_PATTERN.test(occurredAt) || Number.isNaN(Date.parse(occurredAt))) messages.push({ code: "TIMESTAMP_INVALID", path: "/occurred_at", message: "occurred_at must be an ISO UTC timestamp." });
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", warehouse_id: warehouseId, product_id: productId, movement_type: movementType, quantity_micros: quantityMicros, unit_cost_cents: unitCostCents, reference_type: referenceType, reference_id: referenceId, reason, occurred_at: occurredAt };
}

export function normalizeAndValidateProject(payload: unknown): ProjectSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const code = textField(input.code, "/code", "Project code", 2, 40, messages).toUpperCase();
  if (code && !CODE_PATTERN.test(code)) messages.push({ code: "CODE_INVALID", path: "/code", message: "Project code contains unsupported characters." });
  const name = textField(input.name, "/name", "Project name", 2, 200, messages);
  const customerPartyId = idField(input.customer_party_id, "/customer_party_id", "Customer party", messages, true);
  const currency = currencyField(input.currency, messages);
  const startDate = dateField(input.start_date, "/start_date", "Start date", messages);
  const endDate = textValue(input.end_date) ? dateField(input.end_date, "/end_date", "End date", messages) : undefined;
  if (endDate && endDate < startDate) messages.push({ code: "DATE_ORDER_INVALID", path: "/end_date", message: "End date cannot be earlier than start date." });
  const budgetCents = input.budget_cents === undefined ? undefined : integerField(input.budget_cents, "/budget_cents", "Budget cents", messages);
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", code, name, ...(customerPartyId ? { customer_party_id: customerPartyId } : {}), currency, start_date: startDate, ...(endDate ? { end_date: endDate } : {}), ...(budgetCents !== undefined ? { budget_cents: budgetCents } : {}) };
}
