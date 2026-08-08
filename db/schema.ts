import { sql } from "drizzle-orm";
import { index, integer, sqliteTable, text, uniqueIndex } from "drizzle-orm/sqlite-core";

export const taxpayers = sqliteTable(
  "taxpayers",
  {
    id: text("id").primaryKey(),
    vatNumber: text("vat_number").notNull(),
    tin: text("tin").notNull(),
    legalName: text("legal_name").notNull(),
    tradingName: text("trading_name"),
    taxpayerType: text("taxpayer_type").notNull(),
    vatStatus: text("vat_status").notNull(),
    returnFrequency: text("return_frequency").notNull(),
    address: text("address").notNull(),
    email: text("email").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_taxpayers_vat_number").on(table.vatNumber)],
);

export const appUsers = sqliteTable(
  "app_users",
  {
    id: text("id").primaryKey(),
    externalUserId: text("external_user_id").notNull(),
    email: text("email").notNull(),
    displayName: text("display_name").notNull(),
    role: text("role").notNull(),
    taxpayerId: text("taxpayer_id").references(() => taxpayers.id),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("ux_app_users_external_id").on(table.externalUserId),
    uniqueIndex("ux_app_users_email").on(table.email),
  ],
);

export const invoices = sqliteTable(
  "invoices",
  {
    id: text("id").primaryKey(),
    invoiceNumber: text("invoice_number").notNull(),
    documentType: text("document_type").notNull(),
    sourceSystem: text("source_system").notNull(),
    sourceDocumentId: text("source_document_id").notNull(),
    supplierTaxpayerId: text("supplier_taxpayer_id").notNull().references(() => taxpayers.id),
    supplierName: text("supplier_name").notNull(),
    supplierVatNumber: text("supplier_vat_number").notNull(),
    customerTaxpayerId: text("customer_taxpayer_id").references(() => taxpayers.id),
    customerName: text("customer_name").notNull(),
    customerVatNumber: text("customer_vat_number"),
    issueDate: text("issue_date").notNull(),
    currency: text("currency").notNull(),
    lineNetCents: integer("line_net_cents").notNull(),
    taxCents: integer("tax_cents").notNull(),
    totalCents: integer("total_cents").notNull(),
    status: text("status").notNull(),
    riskLevel: text("risk_level").notNull(),
    payloadHash: text("payload_hash").notNull(),
    transactionId: text("transaction_id").notNull(),
    certificateId: text("certificate_id").notNull(),
    verificationToken: text("verification_token").notNull(),
    createdAt: text("created_at").notNull(),
    certifiedAt: text("certified_at").notNull(),
  },
  (table) => [
    uniqueIndex("ux_invoices_source_document").on(table.supplierTaxpayerId, table.sourceSystem, table.sourceDocumentId),
    uniqueIndex("ux_invoices_certificate").on(table.certificateId),
    uniqueIndex("ux_invoices_verification_token").on(table.verificationToken),
    index("idx_invoices_status_issue_date").on(table.status, table.issueDate),
    index("idx_invoices_supplier_issue_date").on(table.supplierTaxpayerId, table.issueDate),
    index("idx_invoices_customer_issue_date").on(table.customerTaxpayerId, table.issueDate),
  ],
);

export const invoiceLines = sqliteTable(
  "invoice_lines",
  {
    id: text("id").primaryKey(),
    invoiceId: text("invoice_id").notNull().references(() => invoices.id),
    lineNumber: integer("line_number").notNull(),
    description: text("description").notNull(),
    quantity: text("quantity").notNull(),
    unitCode: text("unit_code").notNull(),
    unitPriceCents: integer("unit_price_cents").notNull(),
    netAmountCents: integer("net_amount_cents").notNull(),
    taxRateBps: integer("tax_rate_bps").notNull(),
    taxCategory: text("tax_category").notNull(),
    taxAmountCents: integer("tax_amount_cents").notNull(),
  },
  (table) => [uniqueIndex("ux_invoice_lines_number").on(table.invoiceId, table.lineNumber)],
);

export const certificates = sqliteTable(
  "certificates",
  {
    id: text("id").primaryKey(),
    invoiceId: text("invoice_id").notNull().references(() => invoices.id),
    verificationToken: text("verification_token").notNull(),
    invoiceHash: text("invoice_hash").notNull(),
    signature: text("signature").notNull(),
    signatureProfile: text("signature_profile").notNull(),
    status: text("status").notNull(),
    issuedAt: text("issued_at").notNull(),
  },
  (table) => [
    uniqueIndex("ux_certificates_invoice").on(table.invoiceId),
    uniqueIndex("ux_certificates_token").on(table.verificationToken),
  ],
);

export const ledgerEntries = sqliteTable(
  "ledger_entries",
  {
    id: text("id").primaryKey(),
    transactionId: text("transaction_id").notNull(),
    invoiceId: text("invoice_id").notNull().references(() => invoices.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    entryType: text("entry_type").notNull(),
    direction: text("direction").notNull(),
    amountCents: integer("amount_cents").notNull(),
    period: text("period").notNull(),
    createdAt: text("created_at").notNull(),
  },
  (table) => [
    index("idx_ledger_taxpayer_period").on(table.taxpayerId, table.period),
    index("idx_ledger_transaction").on(table.transactionId),
  ],
);

export const reconciliationExceptions = sqliteTable(
  "reconciliation_exceptions",
  {
    id: text("id").primaryKey(),
    invoiceId: text("invoice_id").notNull().references(() => invoices.id),
    taxpayerId: text("taxpayer_id").references(() => taxpayers.id),
    exceptionType: text("exception_type").notNull(),
    severity: text("severity").notNull(),
    status: text("status").notNull(),
    summary: text("summary").notNull(),
    createdAt: text("created_at").notNull(),
    resolvedAt: text("resolved_at"),
  },
  (table) => [index("idx_exceptions_status_created").on(table.status, table.createdAt)],
);

export const vatReturns = sqliteTable(
  "vat_returns",
  {
    id: text("id").primaryKey(),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    period: text("period").notNull(),
    outputTaxCents: integer("output_tax_cents").notNull(),
    inputTaxCents: integer("input_tax_cents").notNull(),
    netPayableCents: integer("net_payable_cents").notNull(),
    status: text("status").notNull(),
    lastCalculatedAt: text("last_calculated_at").notNull(),
  },
  (table) => [uniqueIndex("ux_returns_taxpayer_period").on(table.taxpayerId, table.period)],
);

export const auditEvents = sqliteTable(
  "audit_events",
  {
    id: text("id").primaryKey(),
    actorId: text("actor_id").notNull(),
    actorRole: text("actor_role").notNull(),
    action: text("action").notNull(),
    resourceType: text("resource_type").notNull(),
    resourceId: text("resource_id").notNull(),
    outcome: text("outcome").notNull(),
    details: text("details").notNull(),
    previousHash: text("previous_hash"),
    eventHash: text("event_hash").notNull(),
    occurredAt: text("occurred_at").notNull(),
  },
  (table) => [
    index("idx_audit_resource").on(table.resourceType, table.resourceId, table.occurredAt),
    index("idx_audit_occurred").on(table.occurredAt),
  ],
);

export const idempotencyRecords = sqliteTable(
  "idempotency_records",
  {
    id: text("id").primaryKey(),
    actorId: text("actor_id").notNull(),
    idempotencyKey: text("idempotency_key").notNull(),
    requestHash: text("request_hash").notNull(),
    responseInvoiceId: text("response_invoice_id").notNull().references(() => invoices.id),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_idempotency_actor_key").on(table.actorId, table.idempotencyKey)],
);

export const seedState = sqliteTable("seed_state", {
  key: text("key").primaryKey(),
  appliedAt: text("applied_at").notNull(),
});
