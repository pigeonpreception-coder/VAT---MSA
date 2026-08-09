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

export const taxpayerIdentifiers = sqliteTable(
  "taxpayer_identifiers",
  {
    id: text("id").primaryKey(),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    identifierType: text("identifier_type").notNull(),
    identifierValue: text("identifier_value").notNull(),
    country: text("country").notNull().default("NA"),
    status: text("status").notNull(),
    source: text("source").notNull(),
    verifiedAt: text("verified_at"),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("ux_taxpayer_identifier_authority").on(table.identifierType, table.identifierValue, table.country),
    index("idx_taxpayer_identifiers_taxpayer").on(table.taxpayerId, table.status),
  ],
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

export const identityProviders = sqliteTable(
  "identity_providers",
  {
    id: text("id").primaryKey(),
    providerKey: text("provider_key").notNull(),
    displayName: text("display_name").notNull(),
    providerType: text("provider_type").notNull(),
    authorityLevel: text("authority_level").notNull(),
    issuer: text("issuer"),
    status: text("status").notNull(),
    configurationStatus: text("configuration_status").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_identity_providers_key").on(table.providerKey)],
);

export const identityLinks = sqliteTable(
  "identity_links",
  {
    id: text("id").primaryKey(),
    userId: text("user_id").notNull().references(() => appUsers.id),
    providerId: text("provider_id").notNull().references(() => identityProviders.id),
    subject: text("subject").notNull(),
    emailAtLink: text("email_at_link"),
    assuranceLevel: text("assurance_level").notNull(),
    status: text("status").notNull(),
    linkedAt: text("linked_at").notNull(),
    lastAuthenticatedAt: text("last_authenticated_at"),
  },
  (table) => [
    uniqueIndex("ux_identity_links_provider_subject").on(table.providerId, table.subject),
    index("idx_identity_links_user_status").on(table.userId, table.status),
  ],
);

export const organisations = sqliteTable(
  "organisations",
  {
    id: text("id").primaryKey(),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    legalName: text("legal_name").notNull(),
    tradingName: text("trading_name"),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_organisations_taxpayer").on(table.taxpayerId)],
);

export const branches = sqliteTable(
  "branches",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    code: text("code").notNull(),
    name: text("name").notNull(),
    address: text("address").notNull(),
    status: text("status").notNull(),
    isHeadOffice: integer("is_head_office", { mode: "boolean" }).notNull().default(false),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("ux_branches_organisation_code").on(table.organisationId, table.code),
    index("idx_branches_organisation_status").on(table.organisationId, table.status),
  ],
);

export const organisationCapabilities = sqliteTable(
  "organisation_capabilities",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    capability: text("capability").notNull(),
    status: text("status").notNull(),
    effectiveFrom: text("effective_from").notNull(),
    effectiveTo: text("effective_to"),
    approvedBy: text("approved_by"),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("ux_organisation_capability").on(table.organisationId, table.capability),
    index("idx_organisation_capabilities_status").on(table.status, table.capability),
  ],
);

export const accessRoles = sqliteTable("access_roles", {
  code: text("code").primaryKey(),
  name: text("name").notNull(),
  audience: text("audience").notNull(),
  riskTier: text("risk_tier").notNull(),
  status: text("status").notNull(),
  createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const accessPermissions = sqliteTable("access_permissions", {
  code: text("code").primaryKey(),
  resource: text("resource").notNull(),
  action: text("action").notNull(),
  description: text("description").notNull(),
  classification: text("classification").notNull(),
  createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const rolePermissionGrants = sqliteTable(
  "role_permission_grants",
  {
    id: text("id").primaryKey(),
    roleCode: text("role_code").notNull().references(() => accessRoles.code),
    permissionCode: text("permission_code").notNull().references(() => accessPermissions.code),
    effect: text("effect").notNull(),
    conditions: text("conditions").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_role_permission_grant").on(table.roleCode, table.permissionCode)],
);

export const organisationMemberships = sqliteTable(
  "organisation_memberships",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    userId: text("user_id").notNull().references(() => appUsers.id),
    roleCode: text("role_code").notNull().references(() => accessRoles.code),
    branchId: text("branch_id").references(() => branches.id),
    status: text("status").notNull(),
    validFrom: text("valid_from").notNull(),
    validTo: text("valid_to"),
    assignedBy: text("assigned_by"),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    index("idx_memberships_user_status").on(table.userId, table.status),
    index("idx_memberships_organisation_status").on(table.organisationId, table.status),
  ],
);

export const registrationApplications = sqliteTable(
  "registration_applications",
  {
    id: text("id").primaryKey(),
    idempotencyKey: text("idempotency_key").notNull(),
    requestHash: text("request_hash").notNull(),
    vatNumber: text("vat_number").notNull(),
    tin: text("tin").notNull(),
    companyRegistrationNumber: text("company_registration_number"),
    legalName: text("legal_name").notNull(),
    tradingName: text("trading_name"),
    taxpayerType: text("taxpayer_type").notNull(),
    returnFrequency: text("return_frequency").notNull(),
    address: text("address").notNull(),
    email: text("email").notNull(),
    status: text("status").notNull(),
    verificationSource: text("verification_source").notNull(),
    submittedBy: text("submitted_by").notNull().references(() => appUsers.id),
    submittedAt: text("submitted_at").notNull(),
    reviewedAt: text("reviewed_at"),
    reviewReason: text("review_reason"),
  },
  (table) => [
    uniqueIndex("ux_registration_submitter_key").on(table.submittedBy, table.idempotencyKey),
    index("idx_registration_status_submitted").on(table.status, table.submittedAt),
    index("idx_registration_identifiers").on(table.vatNumber, table.tin),
  ],
);

export const registrationVerifications = sqliteTable(
  "registration_verifications",
  {
    id: text("id").primaryKey(),
    registrationApplicationId: text("registration_application_id").notNull().references(() => registrationApplications.id),
    provider: text("provider").notNull(),
    requestReference: text("request_reference").notNull(),
    status: text("status").notNull(),
    responseHash: text("response_hash"),
    verifiedTaxpayerId: text("verified_taxpayer_id").references(() => taxpayers.id),
    checkedAt: text("checked_at").notNull(),
    expiresAt: text("expires_at"),
  },
  (table) => [
    uniqueIndex("ux_registration_verification_reference").on(table.provider, table.requestReference),
    index("idx_registration_verification_application").on(table.registrationApplicationId, table.status),
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

export const rateLimitWindows = sqliteTable(
  "rate_limit_windows",
  {
    bucketKey: text("bucket_key").notNull(),
    windowStart: integer("window_start").notNull(),
    requestCount: integer("request_count").notNull(),
    expiresAt: integer("expires_at").notNull(),
  },
  (table) => [
    uniqueIndex("ux_rate_limit_bucket_window").on(table.bucketKey, table.windowStart),
    index("idx_rate_limit_expiry").on(table.expiresAt),
  ],
);

export const securityEvents = sqliteTable(
  "security_events",
  {
    id: text("id").primaryKey(),
    eventType: text("event_type").notNull(),
    severity: text("severity").notNull(),
    actorId: text("actor_id"),
    sourceToken: text("source_token").notNull(),
    correlationId: text("correlation_id").notNull(),
    action: text("action").notNull(),
    outcome: text("outcome").notNull(),
    details: text("details").notNull(),
    occurredAt: text("occurred_at").notNull(),
  },
  (table) => [
    index("idx_security_events_severity_time").on(table.severity, table.occurredAt),
    index("idx_security_events_actor_time").on(table.actorId, table.occurredAt),
  ],
);

export const securityIncidents = sqliteTable(
  "security_incidents",
  {
    id: text("id").primaryKey(),
    title: text("title").notNull(),
    severity: text("severity").notNull(),
    status: text("status").notNull(),
    sourceEventId: text("source_event_id").references(() => securityEvents.id),
    automatedAction: text("automated_action"),
    owner: text("owner"),
    openedAt: text("opened_at").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [index("idx_security_incidents_status_severity").on(table.status, table.severity)],
);

export const outboxEvents = sqliteTable(
  "outbox_events",
  {
    id: text("id").primaryKey(),
    aggregateType: text("aggregate_type").notNull(),
    aggregateId: text("aggregate_id").notNull(),
    eventType: text("event_type").notNull(),
    eventVersion: integer("event_version").notNull(),
    partitionKey: text("partition_key").notNull(),
    payload: text("payload").notNull(),
    status: text("status").notNull(),
    publishAttempts: integer("publish_attempts").notNull().default(0),
    occurredAt: text("occurred_at").notNull(),
    availableAt: text("available_at").notNull(),
    publishedAt: text("published_at"),
    lastError: text("last_error"),
  },
  (table) => [
    index("idx_outbox_status_available").on(table.status, table.availableAt),
    index("idx_outbox_aggregate").on(table.aggregateType, table.aggregateId),
  ],
);

export const seedState = sqliteTable("seed_state", {
  key: text("key").primaryKey(),
  appliedAt: text("applied_at").notNull(),
});
