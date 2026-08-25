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

export type QuotationLifecycleAction = "SEND" | "EDIT" | "ACCEPT" | "REJECT" | "EXPIRE" | "CONVERT";
export type QuotationLifecycleStatus = "DRAFT" | "ISSUED" | "ACCEPTED" | "REJECTED" | "EXPIRED" | "CONVERTED";

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

const PARTY_STATUSES = new Set(["ACTIVE", "INACTIVE"]);
const MAX_PARTY_SEARCH_LIMIT = 200;
const DEFAULT_PARTY_SEARCH_LIMIT = 50;

export type PartySearchQuery = {
  relationship: BusinessPartyRelationship | null;
  q: string | null;
  status: "ACTIVE" | "INACTIVE" | null;
  limit: number;
  offset: number;
};

/** Module 5 Phase A SearchCustomers/SearchSuppliers: one search over the shared business_parties model, filtered by relationship — mirrors Module 3 Phase B's normalizeWorkQueueQuery (bounded limit, explicit offset, designed in rather than retrofitted). */
export function normalizePartySearchQuery(params: URLSearchParams): PartySearchQuery {
  const messages: BusinessValidationMessage[] = [];

  const relationshipRaw = params.get("relationship");
  const relationship = relationshipRaw ? (relationshipRaw.trim().toUpperCase() as BusinessPartyRelationship) : null;
  if (relationship && !PARTY_RELATIONSHIPS.has(relationship)) messages.push({ code: "RELATIONSHIP_INVALID", path: "/relationship", message: "relationship must be CUSTOMER or SUPPLIER." });

  const statusRaw = params.get("status");
  const status = statusRaw ? (statusRaw.trim().toUpperCase() as PartySearchQuery["status"]) : null;
  if (status && !PARTY_STATUSES.has(status)) messages.push({ code: "STATUS_INVALID", path: "/status", message: "status must be ACTIVE or INACTIVE." });

  const qRaw = textValue(params.get("q"));
  if (qRaw.length > 200) messages.push({ code: "QUERY_TOO_LONG", path: "/q", message: "q must not exceed 200 characters." });
  const q = qRaw || null;

  const limitRaw = params.get("limit");
  let limit = DEFAULT_PARTY_SEARCH_LIMIT;
  if (limitRaw !== null) {
    const parsed = Number(limitRaw);
    if (!Number.isInteger(parsed) || parsed < 1 || parsed > MAX_PARTY_SEARCH_LIMIT) messages.push({ code: "LIMIT_INVALID", path: "/limit", message: `limit must be an integer between 1 and ${MAX_PARTY_SEARCH_LIMIT}.` });
    else limit = parsed;
  }

  const offsetRaw = params.get("offset");
  let offset = 0;
  if (offsetRaw !== null) {
    const parsed = Number(offsetRaw);
    if (!Number.isInteger(parsed) || parsed < 0) messages.push({ code: "OFFSET_INVALID", path: "/offset", message: "offset must be a non-negative integer." });
    else offset = parsed;
  }

  if (messages.length) throw new BusinessValidationError(messages);
  return { relationship, q, status, limit, offset };
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

/**
 * Module 5 Phase B added DRAFT/SEND: a quotation is now created as DRAFT
 * (previously it landed directly in ISSUED, with no draft→send transition
 * the playbook's own action list implies). Every other transition below is
 * unchanged from before this phase — ACCEPT/REJECT/EXPIRE/CONVERT still
 * require ISSUED, and the overdue-blocks-everything-but-EXPIRE rule still
 * applies once a quotation has actually been sent.
 */
export function evaluateQuotationLifecycle(input: {
  status: string;
  action: QuotationLifecycleAction;
  validUntil: string;
  today: string;
}): QuotationLifecycleEvaluation {
  if (input.action === "SEND") {
    return input.status === "DRAFT"
      ? { allowed: true, targetStatus: "ISSUED", reason: "A draft quotation may be sent to the customer." }
      : { allowed: false, targetStatus: "ISSUED", reason: `Only a draft quotation can be sent; current status is ${input.status}.` };
  }
  if (input.action === "CONVERT") {
    return input.status === "ACCEPTED"
      ? { allowed: true, targetStatus: "CONVERTED", reason: "Accepted quotation may be converted." }
      : { allowed: false, targetStatus: "CONVERTED", reason: `Only an accepted quotation can be converted; current status is ${input.status}.` };
  }
  if (input.action === "EDIT") {
    if (input.status !== "DRAFT" && input.status !== "ISSUED") {
      return { allowed: false, targetStatus: input.status as QuotationLifecycleStatus, reason: `A quotation can only be edited while draft or issued; current status is ${input.status}.` };
    }
    if (input.status === "ISSUED" && input.validUntil < input.today) {
      return { allowed: false, targetStatus: "ISSUED", reason: "The quotation validity period has ended; expire it instead." };
    }
    return { allowed: true, targetStatus: input.status as QuotationLifecycleStatus, reason: `The ${input.status.toLowerCase()} quotation may be edited.` };
  }
  const targetStatus: Record<"ACCEPT" | "REJECT" | "EXPIRE", QuotationLifecycleStatus> = {
    ACCEPT: "ACCEPTED",
    REJECT: "REJECTED",
    EXPIRE: "EXPIRED",
  };
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

const QUOTATION_STATUSES = new Set<QuotationLifecycleStatus>(["DRAFT", "ISSUED", "ACCEPTED", "REJECTED", "EXPIRED", "CONVERTED"]);
const MAX_QUOTATION_SEARCH_LIMIT = 200;
const DEFAULT_QUOTATION_SEARCH_LIMIT = 50;

export type QuotationSearchQuery = {
  status: QuotationLifecycleStatus | null;
  customerPartyId: string | null;
  q: string | null;
  limit: number;
  offset: number;
};

/** Module 5 Phase B SearchQuotes, the same bounded/paginated shape as Phase A's normalizePartySearchQuery. */
export function normalizeQuotationSearchQuery(params: URLSearchParams): QuotationSearchQuery {
  const messages: BusinessValidationMessage[] = [];

  const statusRaw = params.get("status");
  const status = statusRaw ? (statusRaw.trim().toUpperCase() as QuotationLifecycleStatus) : null;
  if (status && !QUOTATION_STATUSES.has(status)) messages.push({ code: "STATUS_INVALID", path: "/status", message: `status must be one of: ${[...QUOTATION_STATUSES].join(", ")}.` });

  const customerPartyId = idField(params.get("customer_party_id") ?? undefined, "/customer_party_id", "Customer party", messages, true) ?? null;

  const qRaw = textValue(params.get("q"));
  if (qRaw.length > 200) messages.push({ code: "QUERY_TOO_LONG", path: "/q", message: "q must not exceed 200 characters." });
  const q = qRaw || null;

  const limitRaw = params.get("limit");
  let limit = DEFAULT_QUOTATION_SEARCH_LIMIT;
  if (limitRaw !== null) {
    const parsed = Number(limitRaw);
    if (!Number.isInteger(parsed) || parsed < 1 || parsed > MAX_QUOTATION_SEARCH_LIMIT) messages.push({ code: "LIMIT_INVALID", path: "/limit", message: `limit must be an integer between 1 and ${MAX_QUOTATION_SEARCH_LIMIT}.` });
    else limit = parsed;
  }

  const offsetRaw = params.get("offset");
  let offset = 0;
  if (offsetRaw !== null) {
    const parsed = Number(offsetRaw);
    if (!Number.isInteger(parsed) || parsed < 0) messages.push({ code: "OFFSET_INVALID", path: "/offset", message: "offset must be a non-negative integer." });
    else offset = parsed;
  }

  if (messages.length) throw new BusinessValidationError(messages);
  return { status, customerPartyId, q, limit, offset };
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

export type ExpenseCategorySubmission = {
  schema_version: "1.0.0";
  code: string;
  name: string;
  default_tax_category: "STANDARD" | "ZERO_RATED" | "EXEMPT" | "OUT_OF_SCOPE";
  requires_receipt: boolean;
};

/** Module 5 Phase E CreateExpenseCategory. expense_categories was previously seed-only, like chart_of_accounts before Phase C's CreateAccount — same fix, same reasoning. */
export function normalizeAndValidateExpenseCategory(payload: unknown): ExpenseCategorySubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const code = textField(input.code, "/code", "Category code", 1, 20, messages).toUpperCase();
  if (code && !/^[A-Z0-9][A-Z0-9._-]{0,19}$/.test(code)) messages.push({ code: "CODE_INVALID", path: "/code", message: "Category code contains unsupported characters." });
  const name = textField(input.name, "/name", "Category name", 2, 200, messages);
  const defaultTaxCategory = textValue(input.default_tax_category).toUpperCase() as ExpenseCategorySubmission["default_tax_category"];
  if (!TAX_CATEGORIES.has(defaultTaxCategory)) messages.push({ code: "TAX_CATEGORY_INVALID", path: "/default_tax_category", message: "Select a supported default tax category." });
  const requiresReceipt = input.requires_receipt === undefined ? true : Boolean(input.requires_receipt);
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", code, name, default_tax_category: defaultTaxCategory, requires_receipt: requiresReceipt };
}

export type ExpenseRejectionSubmission = { schema_version: "1.0.0"; reason: string };

export function normalizeAndValidateExpenseRejection(payload: unknown): ExpenseRejectionSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const reason = textField(input.reason, "/reason", "Rejection reason", 5, 500, messages);
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", reason };
}

export type ProjectBudgetApprovalSubmission = { schema_version: "1.0.0"; approved_amount_cents: number; notes?: string };

/** Module 5 Phase E ApproveBudget. approved_amount_cents is deliberately independent of the originally proposed amount — an approver may approve less (or more, e.g. a pre-approved overrun) than what was proposed. */
export function normalizeAndValidateProjectBudgetApproval(payload: unknown): ProjectBudgetApprovalSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const approvedAmountCents = integerField(input.approved_amount_cents, "/approved_amount_cents", "Approved amount cents", messages);
  const notes = optionalText(input.notes, "/notes", "Notes", 1_000, messages);
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", approved_amount_cents: approvedAmountCents, ...(notes ? { notes } : {}) };
}

export type ProjectCostSubmission =
  | { schema_version: "1.0.0"; cost_type: "EXPENSE"; source_id: string }
  | { schema_version: "1.0.0"; cost_type: "MANUAL"; source_id: string; amount_cents: number; currency: string; description: string; occurred_at: string };

const PROJECT_COST_TYPES = new Set(["EXPENSE", "MANUAL"]);

/**
 * Module 5 Phase E PostCost. project_costs.UNIQUE(project_id, cost_type,
 * source_id) is the schema's own hint at the intended design: EXPENSE costs
 * cite an approved expense already tagged to this project (amount/currency/
 * date derived from that expense, never re-entered — the repository layer
 * resolves and validates it), so the same expense can never be posted as a
 * cost twice. MANUAL costs are for expenditure this system has no other
 * record of (e.g. an external invoice), so the caller supplies everything.
 */
export function normalizeAndValidateProjectCost(payload: unknown): ProjectCostSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const costType = textValue(input.cost_type).toUpperCase();
  if (!PROJECT_COST_TYPES.has(costType)) messages.push({ code: "COST_TYPE_INVALID", path: "/cost_type", message: "cost_type must be EXPENSE or MANUAL." });
  if (costType === "MANUAL") {
    const sourceId = textField(input.source_id, "/source_id", "Source reference", 2, 100, messages);
    const amountCents = integerField(input.amount_cents, "/amount_cents", "Amount cents", messages, 1);
    const currency = currencyField(input.currency, messages);
    const description = textField(input.description, "/description", "Description", 2, 500, messages);
    const occurredAt = dateField(input.occurred_at ?? new Date().toISOString().slice(0, 10), "/occurred_at", "Occurred at", messages);
    if (messages.length) throw new BusinessValidationError(messages);
    return { schema_version: "1.0.0", cost_type: "MANUAL", source_id: sourceId, amount_cents: amountCents, currency, description, occurred_at: occurredAt };
  }
  const sourceId = idField(input.source_id, "/source_id", "Source expense", messages) ?? "";
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", cost_type: "EXPENSE", source_id: sourceId };
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

export type ProductSubmission = {
  schema_version: "1.0.0";
  sku: string;
  name: string;
  description?: string;
  unit_code: string;
  tax_category: "STANDARD" | "ZERO_RATED" | "EXEMPT" | "OUT_OF_SCOPE";
  tax_rate_bps: number;
  sales_price_cents: number;
  cost_price_cents: number;
};

/** Module 5 Phase D CreateProduct: unsticks the previously seed-only `products` table, mirroring Phase C's CreateAccount fix for chart_of_accounts. */
export function normalizeAndValidateProduct(payload: unknown): ProductSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const sku = textField(input.sku, "/sku", "SKU", 1, 40, messages).toUpperCase();
  if (sku && !CODE_PATTERN.test(sku)) messages.push({ code: "CODE_INVALID", path: "/sku", message: "SKU contains unsupported characters." });
  const name = textField(input.name, "/name", "Product name", 2, 200, messages);
  const description = optionalText(input.description, "/description", "Description", 2_000, messages);
  const unitCode = textField(input.unit_code, "/unit_code", "Unit code", 1, 12, messages).toUpperCase();
  const taxCategory = textValue(input.tax_category).toUpperCase() as ProductSubmission["tax_category"];
  if (!TAX_CATEGORIES.has(taxCategory)) messages.push({ code: "TAX_CATEGORY_INVALID", path: "/tax_category", message: "Select a supported tax category." });
  const taxRateBps = integerField(input.tax_rate_bps, "/tax_rate_bps", "Tax rate basis points", messages);
  if (taxRateBps > 10_000) messages.push({ code: "TAX_RATE_INVALID", path: "/tax_rate_bps", message: "Tax rate cannot exceed 10000 basis points." });
  if (taxCategory !== "STANDARD" && taxRateBps !== 0) messages.push({ code: "TAX_RATE_CATEGORY_MISMATCH", path: "/tax_rate_bps", message: "Only standard-rated products may have a non-zero tax rate." });
  const salesPriceCents = integerField(input.sales_price_cents, "/sales_price_cents", "Sales price cents", messages);
  const costPriceCents = integerField(input.cost_price_cents, "/cost_price_cents", "Cost price cents", messages);
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", sku, name, ...(description ? { description } : {}), unit_code: unitCode, tax_category: taxCategory, tax_rate_bps: taxRateBps, sales_price_cents: salesPriceCents, cost_price_cents: costPriceCents };
}

export type WarehouseSubmission = {
  schema_version: "1.0.0";
  branch_id?: string;
  code: string;
  name: string;
  address: string;
};

/** Module 5 Phase D CreateWarehouse: unsticks the previously seed-only `warehouses` table. */
export function normalizeAndValidateWarehouse(payload: unknown): WarehouseSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const branchId = idField(input.branch_id, "/branch_id", "Branch", messages, true);
  const code = textField(input.code, "/code", "Warehouse code", 1, 20, messages).toUpperCase();
  if (code && !/^[A-Z0-9][A-Z0-9._-]{0,19}$/.test(code)) messages.push({ code: "CODE_INVALID", path: "/code", message: "Warehouse code contains unsupported characters." });
  const name = textField(input.name, "/name", "Warehouse name", 2, 200, messages);
  const address = textField(input.address, "/address", "Address", 2, 1_000, messages);
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", ...(branchId ? { branch_id: branchId } : {}), code, name, address };
}

export type StockTransferSubmission = {
  schema_version: "1.0.0";
  from_warehouse_id: string;
  to_warehouse_id: string;
  product_id: string;
  quantity_micros: number;
  reason: string;
  occurred_at: string;
};

/**
 * Module 5 Phase D TransferStock. Unlike RecordStockMovement, this does not
 * take a caller-supplied unit_cost_cents: a transfer moves the same
 * physical stock between warehouses, so the repository derives cost from
 * the source warehouse's own current average cost rather than letting the
 * caller fabricate a new one.
 */
export function normalizeAndValidateStockTransfer(payload: unknown): StockTransferSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const fromWarehouseId = idField(input.from_warehouse_id, "/from_warehouse_id", "Source warehouse", messages) ?? "";
  const toWarehouseId = idField(input.to_warehouse_id, "/to_warehouse_id", "Destination warehouse", messages) ?? "";
  if (fromWarehouseId && toWarehouseId && fromWarehouseId === toWarehouseId) {
    messages.push({ code: "TRANSFER_SAME_WAREHOUSE", path: "/to_warehouse_id", message: "Source and destination warehouse must be different." });
  }
  const productId = idField(input.product_id, "/product_id", "Product", messages) ?? "";
  const quantityMicros = integerField(input.quantity_micros, "/quantity_micros", "Quantity micros", messages, 1);
  const reason = textField(input.reason, "/reason", "Reason", 2, 500, messages);
  const occurredAt = textValue(input.occurred_at) || new Date().toISOString();
  if (!ISO_PATTERN.test(occurredAt) || Number.isNaN(Date.parse(occurredAt))) messages.push({ code: "TIMESTAMP_INVALID", path: "/occurred_at", message: "occurred_at must be an ISO UTC timestamp." });
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", from_warehouse_id: fromWarehouseId, to_warehouse_id: toWarehouseId, product_id: productId, quantity_micros: quantityMicros, reason, occurred_at: occurredAt };
}

export type AccountType = "ASSET" | "LIABILITY" | "EQUITY" | "REVENUE" | "EXPENSE";

export type AccountSubmission = {
  schema_version: "1.0.0";
  code: string;
  name: string;
  account_type: AccountType;
  currency: string;
  control_type?: string;
};

const ACCOUNT_TYPES = new Set<AccountType>(["ASSET", "LIABILITY", "EQUITY", "REVENUE", "EXPENSE"]);

/** Module 5 Phase C CreateAccount. control_type (e.g. BANK, PAYABLE, COST_OF_SALES — see the seeded chart of accounts) is a free-text sub-classification, not a fixed enum: chart_of_accounts.control_type carries no CHECK constraint, so this stays as open as the schema it feeds. */
export function normalizeAndValidateAccount(payload: unknown): AccountSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const code = textField(input.code, "/code", "Account code", 1, 20, messages).toUpperCase();
  if (code && !/^[A-Z0-9][A-Z0-9._-]{0,19}$/.test(code)) messages.push({ code: "CODE_INVALID", path: "/code", message: "Account code contains unsupported characters." });
  const name = textField(input.name, "/name", "Account name", 2, 200, messages);
  const accountType = textValue(input.account_type).toUpperCase() as AccountType;
  if (!ACCOUNT_TYPES.has(accountType)) messages.push({ code: "ACCOUNT_TYPE_INVALID", path: "/account_type", message: `account_type must be one of: ${[...ACCOUNT_TYPES].join(", ")}.` });
  const currency = currencyField(input.currency, messages);
  const controlType = optionalText(input.control_type, "/control_type", "Control type", 40, messages)?.toUpperCase();
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", code, name, account_type: accountType, currency, ...(controlType ? { control_type: controlType } : {}) };
}

export type JournalReversalSubmission = { schema_version: "1.0.0"; reason: string };

export function normalizeAndValidateJournalReversal(payload: unknown): JournalReversalSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const reason = textField(input.reason, "/reason", "Reversal reason", 10, 500, messages);
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", reason };
}

export type PeriodCloseSubmission = { schema_version: "1.0.0"; period_code: string };

const PERIOD_CODE_PATTERN = /^\d{4}-\d{2}$/;

export function normalizeAndValidatePeriodClose(payload: unknown): PeriodCloseSubmission {
  const input = record(payload);
  const messages: BusinessValidationMessage[] = [];
  schemaVersion(input, messages);
  const periodCode = textValue(input.period_code);
  if (!PERIOD_CODE_PATTERN.test(periodCode)) messages.push({ code: "PERIOD_CODE_INVALID", path: "/period_code", message: "period_code must use YYYY-MM." });
  if (messages.length) throw new BusinessValidationError(messages);
  return { schema_version: "1.0.0", period_code: periodCode };
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
