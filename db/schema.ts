import { sql } from "drizzle-orm";
import { check, index, integer, sqliteTable, text, uniqueIndex } from "drizzle-orm/sqlite-core";

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

export const businessParties = sqliteTable(
  "business_parties",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    displayName: text("display_name").notNull(),
    legalName: text("legal_name"),
    vatNumber: text("vat_number"),
    tin: text("tin"),
    email: text("email"),
    phone: text("phone"),
    address: text("address"),
    sourceSystem: text("source_system").notNull(),
    sourcePartyId: text("source_party_id"),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("ux_business_parties_source").on(table.organisationId, table.sourceSystem, table.sourcePartyId),
    index("idx_business_parties_name").on(table.organisationId, table.displayName),
  ],
);

export const partyRelationships = sqliteTable(
  "party_relationships",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    partyId: text("party_id").notNull().references(() => businessParties.id),
    relationship: text("relationship").notNull(),
    status: text("status").notNull(),
    effectiveFrom: text("effective_from").notNull(),
    effectiveTo: text("effective_to"),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_party_relationship").on(table.organisationId, table.partyId, table.relationship)],
);

export const products = sqliteTable(
  "products",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    sku: text("sku").notNull(),
    name: text("name").notNull(),
    description: text("description"),
    unitCode: text("unit_code").notNull(),
    taxCategory: text("tax_category").notNull(),
    taxRateBps: integer("tax_rate_bps").notNull(),
    salesPriceCents: integer("sales_price_cents").notNull(),
    costPriceCents: integer("cost_price_cents").notNull(),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_products_organisation_sku").on(table.organisationId, table.sku)],
);

export const warehouses = sqliteTable(
  "warehouses",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    branchId: text("branch_id").references(() => branches.id),
    code: text("code").notNull(),
    name: text("name").notNull(),
    address: text("address").notNull(),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_warehouses_organisation_code").on(table.organisationId, table.code)],
);

export const projects = sqliteTable(
  "projects",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    code: text("code").notNull(),
    name: text("name").notNull(),
    customerPartyId: text("customer_party_id").references(() => businessParties.id),
    managerUserId: text("manager_user_id").references(() => appUsers.id),
    currency: text("currency").notNull(),
    startDate: text("start_date").notNull(),
    endDate: text("end_date"),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_projects_organisation_code").on(table.organisationId, table.code)],
);

export const expenseCategories = sqliteTable(
  "expense_categories",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    code: text("code").notNull(),
    name: text("name").notNull(),
    defaultTaxCategory: text("default_tax_category").notNull(),
    requiresReceipt: integer("requires_receipt", { mode: "boolean" }).notNull().default(true),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_expense_categories_organisation_code").on(table.organisationId, table.code)],
);

export const quotations = sqliteTable(
  "quotations",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    branchId: text("branch_id").references(() => branches.id),
    customerPartyId: text("customer_party_id").notNull().references(() => businessParties.id),
    quotationNumber: text("quotation_number").notNull(),
    currency: text("currency").notNull(),
    issueDate: text("issue_date").notNull(),
    validUntil: text("valid_until").notNull(),
    status: text("status").notNull(),
    subtotalCents: integer("subtotal_cents").notNull(),
    taxCents: integer("tax_cents").notNull(),
    totalCents: integer("total_cents").notNull(),
    notes: text("notes"),
    createdBy: text("created_by").notNull().references(() => appUsers.id),
    approvedBy: text("approved_by").references(() => appUsers.id),
    acceptedAt: text("accepted_at"),
    convertedInvoiceId: text("converted_invoice_id"),
    createdAt: text("created_at").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [
    uniqueIndex("ux_quotations_number").on(table.organisationId, table.quotationNumber),
    index("idx_quotations_status_date").on(table.organisationId, table.status, table.issueDate),
  ],
);

export const quotationLines = sqliteTable(
  "quotation_lines",
  {
    id: text("id").primaryKey(),
    quotationId: text("quotation_id").notNull().references(() => quotations.id),
    lineNumber: integer("line_number").notNull(),
    productId: text("product_id").references(() => products.id),
    description: text("description").notNull(),
    quantityMicros: integer("quantity_micros").notNull(),
    unitCode: text("unit_code").notNull(),
    unitPriceCents: integer("unit_price_cents").notNull(),
    netAmountCents: integer("net_amount_cents").notNull(),
    taxCategory: text("tax_category").notNull(),
    taxRateBps: integer("tax_rate_bps").notNull(),
    taxAmountCents: integer("tax_amount_cents").notNull(),
  },
  (table) => [uniqueIndex("ux_quotation_lines_number").on(table.quotationId, table.lineNumber)],
);

export const chartOfAccounts = sqliteTable(
  "chart_of_accounts",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    code: text("code").notNull(),
    name: text("name").notNull(),
    accountType: text("account_type").notNull(),
    currency: text("currency").notNull(),
    controlType: text("control_type"),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_accounts_organisation_code").on(table.organisationId, table.code)],
);

export const journalEntries = sqliteTable(
  "journal_entries",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    journalNumber: text("journal_number").notNull(),
    journalDate: text("journal_date").notNull(),
    reference: text("reference"),
    description: text("description").notNull(),
    currency: text("currency").notNull(),
    status: text("status").notNull(),
    sourceType: text("source_type").notNull(),
    sourceId: text("source_id"),
    createdBy: text("created_by").notNull().references(() => appUsers.id),
    postedBy: text("posted_by").references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
    postedAt: text("posted_at"),
  },
  (table) => [
    uniqueIndex("ux_journal_number").on(table.organisationId, table.journalNumber),
    index("idx_journals_status_date").on(table.organisationId, table.status, table.journalDate),
  ],
);

export const journalLines = sqliteTable(
  "journal_lines",
  {
    id: text("id").primaryKey(),
    journalEntryId: text("journal_entry_id").notNull().references(() => journalEntries.id),
    lineNumber: integer("line_number").notNull(),
    accountId: text("account_id").notNull().references(() => chartOfAccounts.id),
    branchId: text("branch_id").references(() => branches.id),
    projectId: text("project_id").references(() => projects.id),
    description: text("description").notNull(),
    debitCents: integer("debit_cents").notNull().default(0),
    creditCents: integer("credit_cents").notNull().default(0),
    taxCode: text("tax_code"),
  },
  (table) => [
    uniqueIndex("ux_journal_lines_number").on(table.journalEntryId, table.lineNumber),
    index("idx_journal_lines_account").on(table.accountId, table.journalEntryId),
  ],
);

export const expenses = sqliteTable(
  "expenses",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    branchId: text("branch_id").references(() => branches.id),
    categoryId: text("category_id").notNull().references(() => expenseCategories.id),
    supplierPartyId: text("supplier_party_id").references(() => businessParties.id),
    projectId: text("project_id").references(() => projects.id),
    expenseNumber: text("expense_number").notNull(),
    expenseDate: text("expense_date").notNull(),
    description: text("description").notNull(),
    currency: text("currency").notNull(),
    netCents: integer("net_cents").notNull(),
    taxCents: integer("tax_cents").notNull(),
    totalCents: integer("total_cents").notNull(),
    status: text("status").notNull(),
    receiptDocumentId: text("receipt_document_id"),
    createdBy: text("created_by").notNull().references(() => appUsers.id),
    approvedBy: text("approved_by").references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
    approvedAt: text("approved_at"),
  },
  (table) => [
    uniqueIndex("ux_expenses_number").on(table.organisationId, table.expenseNumber),
    index("idx_expenses_status_date").on(table.organisationId, table.status, table.expenseDate),
  ],
);

export const inventoryBalances = sqliteTable(
  "inventory_balances",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    warehouseId: text("warehouse_id").notNull().references(() => warehouses.id),
    productId: text("product_id").notNull().references(() => products.id),
    quantityMicros: integer("quantity_micros").notNull().default(0),
    averageCostCents: integer("average_cost_cents").notNull().default(0),
    version: integer("version").notNull().default(0),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [
    uniqueIndex("ux_inventory_balance").on(table.warehouseId, table.productId),
    check("ck_inventory_quantity_nonnegative", sql`${table.quantityMicros} >= 0`),
  ],
);

export const stockMovements = sqliteTable(
  "stock_movements",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    warehouseId: text("warehouse_id").notNull().references(() => warehouses.id),
    productId: text("product_id").notNull().references(() => products.id),
    movementType: text("movement_type").notNull(),
    quantityMicros: integer("quantity_micros").notNull(),
    unitCostCents: integer("unit_cost_cents").notNull(),
    referenceType: text("reference_type").notNull(),
    referenceId: text("reference_id").notNull(),
    reason: text("reason").notNull(),
    occurredAt: text("occurred_at").notNull(),
    actorId: text("actor_id").notNull().references(() => appUsers.id),
  },
  (table) => [
    uniqueIndex("ux_stock_movement_reference").on(table.organisationId, table.referenceType, table.referenceId),
    index("idx_stock_movement_product_time").on(table.warehouseId, table.productId, table.occurredAt),
  ],
);

export const projectBudgets = sqliteTable(
  "project_budgets",
  {
    id: text("id").primaryKey(),
    projectId: text("project_id").notNull().references(() => projects.id),
    category: text("category").notNull(),
    amountCents: integer("amount_cents").notNull(),
    approvedAmountCents: integer("approved_amount_cents").notNull(),
    status: text("status").notNull(),
    approvedBy: text("approved_by").references(() => appUsers.id),
    approvedAt: text("approved_at"),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_project_budget_category").on(table.projectId, table.category)],
);

export const projectCosts = sqliteTable(
  "project_costs",
  {
    id: text("id").primaryKey(),
    projectId: text("project_id").notNull().references(() => projects.id),
    costType: text("cost_type").notNull(),
    sourceId: text("source_id").notNull(),
    amountCents: integer("amount_cents").notNull(),
    currency: text("currency").notNull(),
    occurredAt: text("occurred_at").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("ux_project_cost_source").on(table.projectId, table.costType, table.sourceId)],
);

export const importRecords = sqliteTable(
  "import_records",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    declarationNumber: text("declaration_number").notNull(),
    customsOffice: text("customs_office"),
    supplierName: text("supplier_name").notNull(),
    countryOfOrigin: text("country_of_origin").notNull(),
    currency: text("currency").notNull(),
    customsValueCents: integer("customs_value_cents").notNull(),
    importVatCents: integer("import_vat_cents").notNull(),
    declarationDate: text("declaration_date").notNull(),
    evidenceDocumentId: text("evidence_document_id"),
    status: text("status").notNull(),
    createdBy: text("created_by").notNull().references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_import_declaration").on(table.organisationId, table.declarationNumber)],
);

export const documentMetadata = sqliteTable(
  "document_metadata",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    ownerDomain: text("owner_domain").notNull(),
    ownerResourceId: text("owner_resource_id").notNull(),
    objectKey: text("object_key").notNull(),
    fileName: text("file_name").notNull(),
    contentType: text("content_type").notNull(),
    sizeBytes: integer("size_bytes").notNull(),
    checksumSha256: text("checksum_sha256").notNull(),
    classification: text("classification").notNull(),
    scanStatus: text("scan_status").notNull(),
    status: text("status").notNull(),
    uploadedBy: text("uploaded_by").notNull().references(() => appUsers.id),
    uploadedAt: text("uploaded_at").notNull(),
    retainedUntil: text("retained_until"),
    legalHold: integer("legal_hold", { mode: "boolean" }).notNull().default(false),
  },
  (table) => [
    uniqueIndex("ux_document_object_key").on(table.objectKey),
    index("idx_documents_owner").on(table.organisationId, table.ownerDomain, table.ownerResourceId),
  ],
);

export const commandIdempotency = sqliteTable(
  "command_idempotency",
  {
    id: text("id").primaryKey(),
    actorId: text("actor_id").notNull().references(() => appUsers.id),
    commandType: text("command_type").notNull(),
    idempotencyKey: text("idempotency_key").notNull(),
    requestHash: text("request_hash").notNull(),
    resourceType: text("resource_type").notNull(),
    resourceId: text("resource_id").notNull(),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_command_idempotency").on(table.actorId, table.commandType, table.idempotencyKey)],
);

export const taxRuleSets = sqliteTable(
  "tax_rule_sets",
  {
    id: text("id").primaryKey(),
    jurisdiction: text("jurisdiction").notNull(),
    version: text("version").notNull(),
    effectiveFrom: text("effective_from").notNull(),
    effectiveTo: text("effective_to"),
    standardRateBps: integer("standard_rate_bps").notNull(),
    legalAuthorityReference: text("legal_authority_reference"),
    status: text("status").notNull(),
    approvedBy: text("approved_by").references(() => appUsers.id),
    approvedAt: text("approved_at"),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_tax_rule_version").on(table.jurisdiction, table.version)],
);

export const taxBoxMappings = sqliteTable(
  "tax_box_mappings",
  {
    id: text("id").primaryKey(),
    taxRuleSetId: text("tax_rule_set_id").notNull().references(() => taxRuleSets.id),
    boxCode: text("box_code").notNull(),
    label: text("label").notNull(),
    sourceEntryType: text("source_entry_type").notNull(),
    direction: text("direction").notNull(),
    formula: text("formula").notNull(),
    status: text("status").notNull(),
  },
  (table) => [uniqueIndex("ux_tax_box_mapping").on(table.taxRuleSetId, table.boxCode)],
);

export const vatPeriods = sqliteTable(
  "vat_periods",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    periodCode: text("period_code").notNull(),
    periodStart: text("period_start").notNull(),
    periodEnd: text("period_end").notNull(),
    dueDate: text("due_date").notNull(),
    status: text("status").notNull(),
    lockVersion: integer("lock_version").notNull().default(0),
    closeRequestedBy: text("close_requested_by").references(() => appUsers.id),
    closeRequestedAt: text("close_requested_at"),
    closedBy: text("closed_by").references(() => appUsers.id),
    closedAt: text("closed_at"),
    createdAt: text("created_at").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [
    uniqueIndex("ux_vat_period_code").on(table.taxpayerId, table.periodCode),
    index("idx_vat_period_status_due").on(table.status, table.dueDate),
  ],
);

export const vatAdjustments = sqliteTable(
  "vat_adjustments",
  {
    id: text("id").primaryKey(),
    vatPeriodId: text("vat_period_id").notNull().references(() => vatPeriods.id),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    adjustmentType: text("adjustment_type").notNull(),
    direction: text("direction").notNull(),
    amountCents: integer("amount_cents").notNull(),
    reasonCode: text("reason_code").notNull(),
    explanation: text("explanation").notNull(),
    evidenceDocumentId: text("evidence_document_id").references(() => documentMetadata.id),
    status: text("status").notNull(),
    createdBy: text("created_by").notNull().references(() => appUsers.id),
    approvedBy: text("approved_by").references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
    approvedAt: text("approved_at"),
  },
  (table) => [index("idx_vat_adjustments_period_status").on(table.vatPeriodId, table.status)],
);

export const vatReturnVersions = sqliteTable(
  "vat_return_versions",
  {
    id: text("id").primaryKey(),
    vatPeriodId: text("vat_period_id").notNull().references(() => vatPeriods.id),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    versionNumber: integer("version_number").notNull(),
    parentVersionId: text("parent_version_id"),
    taxRuleSetId: text("tax_rule_set_id").notNull().references(() => taxRuleSets.id),
    outputTaxCents: integer("output_tax_cents").notNull(),
    inputTaxCents: integer("input_tax_cents").notNull(),
    adjustmentCents: integer("adjustment_cents").notNull(),
    netPayableCents: integer("net_payable_cents").notNull(),
    status: text("status").notNull(),
    ledgerSnapshotHash: text("ledger_snapshot_hash").notNull(),
    generatedBy: text("generated_by").notNull().references(() => appUsers.id),
    generatedAt: text("generated_at").notNull(),
    approvedBy: text("approved_by").references(() => appUsers.id),
    approvedAt: text("approved_at"),
    supersededAt: text("superseded_at"),
  },
  (table) => [
    uniqueIndex("ux_vat_return_version").on(table.vatPeriodId, table.versionNumber),
    index("idx_vat_return_status_generated").on(table.status, table.generatedAt),
  ],
);

export const vatReturnBoxes = sqliteTable(
  "vat_return_boxes",
  {
    id: text("id").primaryKey(),
    vatReturnVersionId: text("vat_return_version_id").notNull().references(() => vatReturnVersions.id),
    boxCode: text("box_code").notNull(),
    label: text("label").notNull(),
    amountCents: integer("amount_cents").notNull(),
    sourceCount: integer("source_count").notNull(),
    calculationTrace: text("calculation_trace").notNull(),
  },
  (table) => [uniqueIndex("ux_vat_return_box").on(table.vatReturnVersionId, table.boxCode)],
);

export const approvalTasks = sqliteTable(
  "approval_tasks",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    domain: text("domain").notNull(),
    resourceType: text("resource_type").notNull(),
    resourceId: text("resource_id").notNull(),
    requestedAction: text("requested_action").notNull(),
    riskTier: text("risk_tier").notNull(),
    status: text("status").notNull(),
    requestedBy: text("requested_by").notNull().references(() => appUsers.id),
    assignedRole: text("assigned_role").notNull(),
    decidedBy: text("decided_by").references(() => appUsers.id),
    requestedAt: text("requested_at").notNull(),
    decidedAt: text("decided_at"),
    decisionComment: text("decision_comment"),
  },
  (table) => [index("idx_approval_queue").on(table.status, table.assignedRole, table.requestedAt)],
);

export const vatReturnSubmissions = sqliteTable(
  "vat_return_submissions",
  {
    id: text("id").primaryKey(),
    vatReturnVersionId: text("vat_return_version_id").notNull().references(() => vatReturnVersions.id),
    provider: text("provider").notNull(),
    requestReference: text("request_reference").notNull(),
    status: text("status").notNull(),
    requestHash: text("request_hash").notNull(),
    providerReference: text("provider_reference"),
    responseHash: text("response_hash"),
    attemptCount: integer("attempt_count").notNull().default(0),
    requestedBy: text("requested_by").notNull().references(() => appUsers.id),
    requestedAt: text("requested_at").notNull(),
    submittedAt: text("submitted_at"),
    acknowledgedAt: text("acknowledged_at"),
    lastError: text("last_error"),
  },
  (table) => [
    uniqueIndex("ux_vat_return_submission_reference").on(table.provider, table.requestReference),
    index("idx_vat_return_submission_status").on(table.status, table.requestedAt),
  ],
);

export const consentGrants = sqliteTable(
  "consent_grants",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    grantedBy: text("granted_by").notNull().references(() => appUsers.id),
    granteeType: text("grantee_type").notNull(),
    granteeId: text("grantee_id").notNull(),
    purpose: text("purpose").notNull(),
    dataCategories: text("data_categories").notNull(),
    legalBasis: text("legal_basis").notNull(),
    status: text("status").notNull(),
    validFrom: text("valid_from").notNull(),
    validTo: text("valid_to"),
    revokedAt: text("revoked_at"),
    createdAt: text("created_at").notNull(),
  },
  (table) => [index("idx_consent_taxpayer_status").on(table.taxpayerId, table.status)],
);

export const delegations = sqliteTable(
  "delegations",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    delegatorUserId: text("delegator_user_id").notNull().references(() => appUsers.id),
    delegateUserId: text("delegate_user_id").notNull().references(() => appUsers.id),
    scopes: text("scopes").notNull(),
    status: text("status").notNull(),
    validFrom: text("valid_from").notNull(),
    validTo: text("valid_to"),
    approvedBy: text("approved_by").references(() => appUsers.id),
    approvedAt: text("approved_at"),
    createdAt: text("created_at").notNull(),
  },
  (table) => [index("idx_delegations_delegate_status").on(table.delegateUserId, table.status)],
);

export const taxObligations = sqliteTable(
  "tax_obligations",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    obligationType: text("obligation_type").notNull(),
    periodCode: text("period_code").notNull(),
    dueDate: text("due_date").notNull(),
    amountCents: integer("amount_cents").notNull(),
    currency: text("currency").notNull(),
    status: text("status").notNull(),
    sourceSystem: text("source_system").notNull(),
    sourceReference: text("source_reference"),
    createdAt: text("created_at").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [uniqueIndex("ux_tax_obligation").on(table.taxpayerId, table.obligationType, table.periodCode)],
);

export const communications = sqliteTable(
  "communications",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").references(() => organisations.id),
    taxpayerId: text("taxpayer_id").references(() => taxpayers.id),
    channel: text("channel").notNull(),
    direction: text("direction").notNull(),
    subject: text("subject").notNull(),
    contentSummary: text("content_summary").notNull(),
    classification: text("classification").notNull(),
    relatedResourceType: text("related_resource_type"),
    relatedResourceId: text("related_resource_id"),
    externalReference: text("external_reference"),
    status: text("status").notNull(),
    actorId: text("actor_id").notNull().references(() => appUsers.id),
    occurredAt: text("occurred_at").notNull(),
  },
  (table) => [index("idx_communications_taxpayer_time").on(table.taxpayerId, table.occurredAt)],
);

export const notifications = sqliteTable(
  "notifications",
  {
    id: text("id").primaryKey(),
    userId: text("user_id").references(() => appUsers.id),
    taxpayerId: text("taxpayer_id").references(() => taxpayers.id),
    notificationType: text("notification_type").notNull(),
    title: text("title").notNull(),
    message: text("message").notNull(),
    severity: text("severity").notNull(),
    status: text("status").notNull(),
    actionUrl: text("action_url"),
    createdAt: text("created_at").notNull(),
    readAt: text("read_at"),
  },
  (table) => [index("idx_notifications_recipient_status").on(table.userId, table.taxpayerId, table.status)],
);

export const auditCases = sqliteTable(
  "audit_cases",
  {
    id: text("id").primaryKey(),
    caseNumber: text("case_number").notNull(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    caseType: text("case_type").notNull(),
    title: text("title").notNull(),
    openingReason: text("opening_reason").notNull(),
    riskTier: text("risk_tier").notNull(),
    status: text("status").notNull(),
    assignedOfficerId: text("assigned_officer_id").references(() => appUsers.id),
    openedBy: text("opened_by").notNull().references(() => appUsers.id),
    openedAt: text("opened_at").notNull(),
    updatedAt: text("updated_at").notNull(),
    closedAt: text("closed_at"),
  },
  (table) => [
    uniqueIndex("ux_audit_case_number").on(table.caseNumber),
    index("idx_audit_cases_status_risk").on(table.status, table.riskTier, table.updatedAt),
  ],
);

export const auditEvidence = sqliteTable(
  "audit_evidence",
  {
    id: text("id").primaryKey(),
    auditCaseId: text("audit_case_id").notNull().references(() => auditCases.id),
    evidenceType: text("evidence_type").notNull(),
    sourceResourceType: text("source_resource_type").notNull(),
    sourceResourceId: text("source_resource_id").notNull(),
    documentId: text("document_id").references(() => documentMetadata.id),
    checksumSha256: text("checksum_sha256").notNull(),
    description: text("description").notNull(),
    status: text("status").notNull(),
    addedBy: text("added_by").notNull().references(() => appUsers.id),
    addedAt: text("added_at").notNull(),
  },
  (table) => [uniqueIndex("ux_audit_evidence_source").on(table.auditCaseId, table.sourceResourceType, table.sourceResourceId)],
);

export const auditFindings = sqliteTable(
  "audit_findings",
  {
    id: text("id").primaryKey(),
    auditCaseId: text("audit_case_id").notNull().references(() => auditCases.id),
    findingCode: text("finding_code").notNull(),
    title: text("title").notNull(),
    description: text("description").notNull(),
    legalReference: text("legal_reference"),
    amountCents: integer("amount_cents").notNull(),
    currency: text("currency").notNull(),
    status: text("status").notNull(),
    authorId: text("author_id").notNull().references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
    resolvedAt: text("resolved_at"),
  },
  (table) => [uniqueIndex("ux_audit_finding_code").on(table.auditCaseId, table.findingCode)],
);

export const disputes = sqliteTable(
  "disputes",
  {
    id: text("id").primaryKey(),
    disputeNumber: text("dispute_number").notNull(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    auditCaseId: text("audit_case_id").references(() => auditCases.id),
    disputedResourceType: text("disputed_resource_type").notNull(),
    disputedResourceId: text("disputed_resource_id").notNull(),
    grounds: text("grounds").notNull(),
    disputedAmountCents: integer("disputed_amount_cents").notNull(),
    currency: text("currency").notNull(),
    status: text("status").notNull(),
    filedBy: text("filed_by").notNull().references(() => appUsers.id),
    assignedOfficerId: text("assigned_officer_id").references(() => appUsers.id),
    filedAt: text("filed_at").notNull(),
    decidedAt: text("decided_at"),
    decisionSummary: text("decision_summary"),
  },
  (table) => [
    uniqueIndex("ux_dispute_number").on(table.disputeNumber),
    index("idx_disputes_status_filed").on(table.status, table.filedAt),
  ],
);

export const riskIndicators = sqliteTable(
  "risk_indicators",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    subjectType: text("subject_type").notNull(),
    subjectId: text("subject_id").notNull(),
    indicatorCode: text("indicator_code").notNull(),
    scoreBps: integer("score_bps").notNull(),
    severity: text("severity").notNull(),
    rationale: text("rationale").notNull(),
    ruleVersion: text("rule_version").notNull(),
    decisionEffect: text("decision_effect").notNull(),
    status: text("status").notNull(),
    detectedAt: text("detected_at").notNull(),
    reviewedBy: text("reviewed_by").references(() => appUsers.id),
    reviewedAt: text("reviewed_at"),
  },
  (table) => [
    uniqueIndex("ux_risk_indicator_subject").on(table.subjectType, table.subjectId, table.indicatorCode, table.ruleVersion),
    index("idx_risk_taxpayer_status").on(table.taxpayerId, table.status, table.severity),
  ],
);

export const refundClaims = sqliteTable(
  "refund_claims",
  {
    id: text("id").primaryKey(),
    claimNumber: text("claim_number").notNull(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    vatReturnVersionId: text("vat_return_version_id").notNull().references(() => vatReturnVersions.id),
    amountCents: integer("amount_cents").notNull(),
    currency: text("currency").notNull(),
    status: text("status").notNull(),
    evidenceStatus: text("evidence_status").notNull(),
    riskTier: text("risk_tier").notNull(),
    requestedBy: text("requested_by").notNull().references(() => appUsers.id),
    requestedAt: text("requested_at").notNull(),
    approvedBy: text("approved_by").references(() => appUsers.id),
    approvedAt: text("approved_at"),
    paymentInstructionId: text("payment_instruction_id"),
  },
  (table) => [
    uniqueIndex("ux_refund_claim_number").on(table.claimNumber),
    uniqueIndex("ux_refund_return_version").on(table.vatReturnVersionId),
    index("idx_refund_claim_status_risk").on(table.status, table.riskTier, table.requestedAt),
  ],
);

export const refundReviews = sqliteTable(
  "refund_reviews",
  {
    id: text("id").primaryKey(),
    refundClaimId: text("refund_claim_id").notNull().references(() => refundClaims.id),
    stage: text("stage").notNull(),
    decision: text("decision").notNull(),
    findings: text("findings").notNull(),
    reviewerId: text("reviewer_id").notNull().references(() => appUsers.id),
    reviewedAt: text("reviewed_at").notNull(),
  },
  (table) => [uniqueIndex("ux_refund_review_stage").on(table.refundClaimId, table.stage)],
);

export const integrationConnections = sqliteTable(
  "integration_connections",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").references(() => organisations.id),
    providerKey: text("provider_key").notNull(),
    category: text("category").notNull(),
    displayName: text("display_name").notNull(),
    capabilities: text("capabilities").notNull(),
    endpointReference: text("endpoint_reference"),
    credentialReference: text("credential_reference"),
    configurationStatus: text("configuration_status").notNull(),
    operationalStatus: text("operational_status").notNull(),
    dataClassification: text("data_classification").notNull(),
    lastHealthCheckAt: text("last_health_check_at"),
    lastHealthOutcome: text("last_health_outcome"),
    createdAt: text("created_at").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [uniqueIndex("ux_integration_provider_org").on(table.providerKey, table.organisationId)],
);

export const apiClients = sqliteTable(
  "api_clients",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    name: text("name").notNull(),
    clientKey: text("client_key").notNull(),
    scopes: text("scopes").notNull(),
    credentialReference: text("credential_reference").notNull(),
    status: text("status").notNull(),
    rateLimitProfile: text("rate_limit_profile").notNull(),
    lastRotatedAt: text("last_rotated_at"),
    expiresAt: text("expires_at"),
    createdBy: text("created_by").notNull().references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_api_client_key").on(table.clientKey)],
);

export const webhookSubscriptions = sqliteTable(
  "webhook_subscriptions",
  {
    id: text("id").primaryKey(),
    apiClientId: text("api_client_id").notNull().references(() => apiClients.id),
    eventTypes: text("event_types").notNull(),
    endpointUrl: text("endpoint_url").notNull(),
    signingKeyReference: text("signing_key_reference").notNull(),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_webhook_endpoint_client").on(table.apiClientId, table.endpointUrl)],
);

export const webhookDeliveries = sqliteTable(
  "webhook_deliveries",
  {
    id: text("id").primaryKey(),
    webhookSubscriptionId: text("webhook_subscription_id").notNull().references(() => webhookSubscriptions.id),
    outboxEventId: text("outbox_event_id").notNull().references(() => outboxEvents.id),
    status: text("status").notNull(),
    attemptCount: integer("attempt_count").notNull().default(0),
    responseStatus: integer("response_status"),
    nextAttemptAt: text("next_attempt_at"),
    deliveredAt: text("delivered_at"),
    lastError: text("last_error"),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_webhook_delivery_event").on(table.webhookSubscriptionId, table.outboxEventId)],
);

export const syncJobs = sqliteTable(
  "sync_jobs",
  {
    id: text("id").primaryKey(),
    integrationConnectionId: text("integration_connection_id").notNull().references(() => integrationConnections.id),
    organisationId: text("organisation_id").references(() => organisations.id),
    jobType: text("job_type").notNull(),
    direction: text("direction").notNull(),
    status: text("status").notNull(),
    cursor: text("cursor"),
    recordsRead: integer("records_read").notNull().default(0),
    recordsWritten: integer("records_written").notNull().default(0),
    errorCount: integer("error_count").notNull().default(0),
    requestedBy: text("requested_by").notNull().references(() => appUsers.id),
    requestedAt: text("requested_at").notNull(),
    startedAt: text("started_at"),
    completedAt: text("completed_at"),
    lastError: text("last_error"),
  },
  (table) => [index("idx_sync_jobs_status_requested").on(table.status, table.requestedAt)],
);

export const bankImports = sqliteTable(
  "bank_imports",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    integrationConnectionId: text("integration_connection_id").references(() => integrationConnections.id),
    documentId: text("document_id").references(() => documentMetadata.id),
    bankName: text("bank_name").notNull(),
    accountReferenceMasked: text("account_reference_masked").notNull(),
    statementFrom: text("statement_from").notNull(),
    statementTo: text("statement_to").notNull(),
    currency: text("currency").notNull(),
    transactionCount: integer("transaction_count").notNull().default(0),
    status: text("status").notNull(),
    requestedBy: text("requested_by").notNull().references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
  },
  (table) => [index("idx_bank_import_status_created").on(table.organisationId, table.status, table.createdAt)],
);

export const paymentInstructions = sqliteTable(
  "payment_instructions",
  {
    id: text("id").primaryKey(),
    refundClaimId: text("refund_claim_id").references(() => refundClaims.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    amountCents: integer("amount_cents").notNull(),
    currency: text("currency").notNull(),
    beneficiaryReferenceMasked: text("beneficiary_reference_masked").notNull(),
    provider: text("provider").notNull(),
    status: text("status").notNull(),
    providerReference: text("provider_reference"),
    idempotencyKey: text("idempotency_key").notNull(),
    approvedBy: text("approved_by").notNull().references(() => appUsers.id),
    approvedAt: text("approved_at").notNull(),
    submittedAt: text("submitted_at"),
    settledAt: text("settled_at"),
    lastError: text("last_error"),
  },
  (table) => [uniqueIndex("ux_payment_instruction_key").on(table.provider, table.idempotencyKey)],
);

export const offlineDevices = sqliteTable(
  "offline_devices",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    branchId: text("branch_id").references(() => branches.id),
    deviceCode: text("device_code").notNull(),
    displayName: text("display_name").notNull(),
    publicKeyReference: text("public_key_reference"),
    certificateFingerprint: text("certificate_fingerprint"),
    status: text("status").notNull(),
    enrolmentStatus: text("enrolment_status").notNull(),
    lastAcceptedSequence: integer("last_accepted_sequence").notNull().default(0),
    lastBatchHash: text("last_batch_hash"),
    lastSeenAt: text("last_seen_at"),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_offline_device_code").on(table.organisationId, table.deviceCode)],
);

export const offlineNumberRanges = sqliteTable(
  "offline_number_ranges",
  {
    id: text("id").primaryKey(),
    offlineDeviceId: text("offline_device_id").notNull().references(() => offlineDevices.id),
    documentType: text("document_type").notNull(),
    prefix: text("prefix").notNull(),
    rangeStart: integer("range_start").notNull(),
    rangeEnd: integer("range_end").notNull(),
    nextNumber: integer("next_number").notNull(),
    status: text("status").notNull(),
    validFrom: text("valid_from").notNull(),
    validTo: text("valid_to").notNull(),
  },
  (table) => [uniqueIndex("ux_offline_number_range").on(table.offlineDeviceId, table.documentType, table.prefix)],
);

export const offlineSyncBatches = sqliteTable(
  "offline_sync_batches",
  {
    id: text("id").primaryKey(),
    offlineDeviceId: text("offline_device_id").notNull().references(() => offlineDevices.id),
    clientBatchId: text("client_batch_id").notNull(),
    sequenceFrom: integer("sequence_from").notNull(),
    sequenceTo: integer("sequence_to").notNull(),
    previousBatchHash: text("previous_batch_hash"),
    batchHash: text("batch_hash").notNull(),
    signature: text("signature").notNull(),
    documentCount: integer("document_count").notNull(),
    status: text("status").notNull(),
    receivedAt: text("received_at").notNull(),
    processedAt: text("processed_at"),
    rejectionReason: text("rejection_reason"),
  },
  (table) => [
    uniqueIndex("ux_offline_client_batch").on(table.offlineDeviceId, table.clientBatchId),
    uniqueIndex("ux_offline_batch_sequence").on(table.offlineDeviceId, table.sequenceFrom, table.sequenceTo),
  ],
);

export const offlineConflicts = sqliteTable(
  "offline_conflicts",
  {
    id: text("id").primaryKey(),
    offlineSyncBatchId: text("offline_sync_batch_id").notNull().references(() => offlineSyncBatches.id),
    conflictType: text("conflict_type").notNull(),
    sourceDocumentId: text("source_document_id").notNull(),
    existingResourceId: text("existing_resource_id"),
    status: text("status").notNull(),
    resolution: text("resolution"),
    resolvedBy: text("resolved_by").references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
    resolvedAt: text("resolved_at"),
  },
  (table) => [index("idx_offline_conflicts_status").on(table.status, table.createdAt)],
);

export const reportDefinitions = sqliteTable(
  "report_definitions",
  {
    id: text("id").primaryKey(),
    code: text("code").notNull(),
    name: text("name").notNull(),
    audience: text("audience").notNull(),
    description: text("description").notNull(),
    classification: text("classification").notNull(),
    queryVersion: text("query_version").notNull(),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_report_definition_code").on(table.code)],
);

export const reportRuns = sqliteTable(
  "report_runs",
  {
    id: text("id").primaryKey(),
    reportDefinitionId: text("report_definition_id").notNull().references(() => reportDefinitions.id),
    organisationId: text("organisation_id").references(() => organisations.id),
    taxpayerId: text("taxpayer_id").references(() => taxpayers.id),
    parameters: text("parameters").notNull(),
    status: text("status").notNull(),
    rowCount: integer("row_count"),
    resultSummary: text("result_summary"),
    outputDocumentId: text("output_document_id").references(() => documentMetadata.id),
    requestedBy: text("requested_by").notNull().references(() => appUsers.id),
    requestedAt: text("requested_at").notNull(),
    completedAt: text("completed_at"),
    expiresAt: text("expires_at"),
    errorCode: text("error_code"),
  },
  (table) => [index("idx_report_runs_status_requested").on(table.status, table.requestedAt)],
);

export const serviceComponents = sqliteTable(
  "service_components",
  {
    id: text("id").primaryKey(),
    componentKey: text("component_key").notNull(),
    displayName: text("display_name").notNull(),
    componentType: text("component_type").notNull(),
    criticality: text("criticality").notNull(),
    configurationStatus: text("configuration_status").notNull(),
    operationalStatus: text("operational_status").notNull(),
    dependencySummary: text("dependency_summary").notNull(),
    lastCheckedAt: text("last_checked_at"),
    statusDetail: text("status_detail").notNull(),
  },
  (table) => [uniqueIndex("ux_service_component_key").on(table.componentKey)],
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

export const invoiceCorrections = sqliteTable(
  "invoice_corrections",
  {
    id: text("id").primaryKey(),
    originalInvoiceId: text("original_invoice_id").notNull().references(() => invoices.id),
    correctionInvoiceId: text("correction_invoice_id").notNull().references(() => invoices.id),
    correctionType: text("correction_type").notNull(),
    reasonCode: text("reason_code"),
    reason: text("reason").notNull(),
    status: text("status").notNull(),
    createdBy: text("created_by").notNull().references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
  },
  (table) => [
    uniqueIndex("ux_invoice_correction_document").on(table.correctionInvoiceId),
    index("idx_invoice_correction_original").on(table.originalInvoiceId, table.createdAt),
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

export const reconciliationMatches = sqliteTable(
  "reconciliation_matches",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    taxpayerId: text("taxpayer_id").notNull().references(() => taxpayers.id),
    vatPeriodId: text("vat_period_id").references(() => vatPeriods.id),
    invoiceId: text("invoice_id").notNull().references(() => invoices.id),
    ledgerEntryId: text("ledger_entry_id").references(() => ledgerEntries.id),
    matchType: text("match_type").notNull(),
    confidenceBps: integer("confidence_bps").notNull(),
    status: text("status").notNull(),
    evidence: text("evidence").notNull(),
    reconciledBy: text("reconciled_by").references(() => appUsers.id),
    reconciledAt: text("reconciled_at"),
    createdAt: text("created_at").notNull(),
  },
  (table) => [
    uniqueIndex("ux_reconciliation_invoice_taxpayer").on(table.invoiceId, table.taxpayerId),
    index("idx_reconciliation_period_status").on(table.vatPeriodId, table.status),
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

export const licensePlans = sqliteTable(
  "license_plans",
  {
    id: text("id").primaryKey(),
    code: text("code").notNull(),
    name: text("name").notNull(),
    version: integer("version").notNull(),
    status: text("status").notNull(),
    effectiveFrom: text("effective_from").notNull(),
    effectiveTo: text("effective_to"),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_license_plan_code_version").on(table.code, table.version)],
);

export const licenseFeatures = sqliteTable("license_features", {
  key: text("feature_key").primaryKey(),
  name: text("name").notNull(),
  description: text("description").notNull(),
  metricKey: text("metric_key"),
  protected: integer("protected").notNull().default(0),
  createdAt: text("created_at").notNull(),
});

export const licensePlanEntitlements = sqliteTable(
  "license_plan_entitlements",
  {
    id: text("id").primaryKey(),
    licensePlanId: text("license_plan_id").notNull().references(() => licensePlans.id),
    featureKey: text("feature_key").notNull().references(() => licenseFeatures.key),
    enabled: integer("enabled").notNull().default(1),
    limitValue: integer("limit_value"),
    configuration: text("configuration").notNull().default("{}"),
  },
  (table) => [uniqueIndex("ux_plan_entitlement_feature").on(table.licensePlanId, table.featureKey)],
);

export const subscriptions = sqliteTable(
  "subscriptions",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    provider: text("provider").notNull(),
    providerReference: text("provider_reference").notNull(),
    status: text("status").notNull(),
    activatedAt: text("activated_at"),
    currentPeriodStart: text("current_period_start").notNull(),
    currentPeriodEnd: text("current_period_end").notNull(),
    createdAt: text("created_at").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [uniqueIndex("ux_subscription_provider_ref").on(table.provider, table.providerReference), index("idx_subscription_org_status").on(table.organisationId, table.status)],
);

export const organisationLicenses = sqliteTable(
  "organisation_licenses",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    subscriptionId: text("subscription_id").notNull().references(() => subscriptions.id),
    licensePlanId: text("license_plan_id").notNull().references(() => licensePlans.id),
    state: text("state").notNull(),
    stateVersion: integer("state_version").notNull().default(1),
    effectiveFrom: text("effective_from").notNull(),
    effectiveTo: text("effective_to"),
    graceEndsAt: text("grace_ends_at"),
    retentionPolicy: text("retention_policy").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [index("idx_organisation_license_effective").on(table.organisationId, table.state, table.effectiveFrom)],
);

export const licenseUsage = sqliteTable(
  "license_usage",
  {
    id: text("id").primaryKey(),
    organisationLicenseId: text("organisation_license_id").notNull().references(() => organisationLicenses.id),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    metricKey: text("metric_key").notNull(),
    periodKey: text("period_key").notNull(),
    usedValue: integer("used_value").notNull().default(0),
    reservedValue: integer("reserved_value").notNull().default(0),
    version: integer("version").notNull().default(0),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [uniqueIndex("ux_license_usage_metric_period").on(table.organisationId, table.metricKey, table.periodKey)],
);

export const licenseEvents = sqliteTable(
  "license_events",
  {
    id: text("id").primaryKey(),
    organisationLicenseId: text("organisation_license_id").notNull().references(() => organisationLicenses.id),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    eventType: text("event_type").notNull(),
    fromState: text("from_state"),
    toState: text("to_state").notNull(),
    authority: text("authority").notNull(),
    reason: text("reason").notNull(),
    occurredAt: text("occurred_at").notNull(),
  },
  (table) => [index("idx_license_events_org_time").on(table.organisationId, table.occurredAt)],
);

export const departments = sqliteTable(
  "departments",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    code: text("code").notNull(),
    name: text("name").notNull(),
    parentDepartmentId: text("parent_department_id"),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_departments_org_code").on(table.organisationId, table.code), index("idx_departments_org_status").on(table.organisationId, table.status)],
);

export const businessUnits = sqliteTable(
  "business_units",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    code: text("code").notNull(),
    name: text("name").notNull(),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_business_units_org_code").on(table.organisationId, table.code)],
);

export const jobTitles = sqliteTable(
  "job_titles",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    code: text("code").notNull(),
    name: text("name").notNull(),
    description: text("description").notNull(),
    status: text("status").notNull(),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_job_titles_org_code").on(table.organisationId, table.code)],
);

export const positions = sqliteTable(
  "positions",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    jobTitleId: text("job_title_id").notNull().references(() => jobTitles.id),
    departmentId: text("department_id").references(() => departments.id),
    businessUnitId: text("business_unit_id").references(() => businessUnits.id),
    branchId: text("branch_id").references(() => branches.id),
    code: text("code").notNull(),
    name: text("name").notNull(),
    status: text("status").notNull(),
  },
  (table) => [uniqueIndex("ux_positions_org_code").on(table.organisationId, table.code)],
);

export const employees = sqliteTable(
  "employees",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    userId: text("user_id").references(() => appUsers.id),
    employeeNumber: text("employee_number").notNull(),
    fullName: text("full_name").notNull(),
    email: text("email").notNull(),
    positionId: text("position_id").references(() => positions.id),
    jobTitleId: text("job_title_id").references(() => jobTitles.id),
    departmentId: text("department_id").references(() => departments.id),
    businessUnitId: text("business_unit_id").references(() => businessUnits.id),
    branchId: text("branch_id").references(() => branches.id),
    managerEmployeeId: text("manager_employee_id"),
    status: text("status").notNull(),
    invitedAt: text("invited_at"),
    activatedAt: text("activated_at"),
    terminatedAt: text("terminated_at"),
    lastActivityAt: text("last_activity_at"),
    createdAt: text("created_at").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [uniqueIndex("ux_employees_org_number").on(table.organisationId, table.employeeNumber), uniqueIndex("ux_employees_org_email").on(table.organisationId, table.email), index("idx_employees_org_status_name").on(table.organisationId, table.status, table.fullName)],
);

export const organisationAdministratorRoles = sqliteTable("organisation_administrator_roles", {
  code: text("code").primaryKey(),
  name: text("name").notNull(),
  maximumScope: text("maximum_scope").notNull(),
  protected: integer("protected").notNull().default(1),
});

export const organisationAdministrators = sqliteTable(
  "organisation_administrators",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    userId: text("user_id").notNull().references(() => appUsers.id),
    employeeId: text("employee_id").references(() => employees.id),
    administratorRoleCode: text("administrator_role_code").notNull().references(() => organisationAdministratorRoles.code),
    scope: text("scope").notNull(),
    isPrimary: integer("is_primary").notNull().default(0),
    status: text("status").notNull(),
    effectiveFrom: text("effective_from").notNull(),
    effectiveTo: text("effective_to"),
    appointedBy: text("appointed_by").notNull(),
    approvalReference: text("approval_reference").notNull(),
  },
  (table) => [index("idx_org_admins_org_status").on(table.organisationId, table.status), uniqueIndex("ux_org_admin_user_role").on(table.organisationId, table.userId, table.administratorRoleCode)],
);

export const organisationRoles = sqliteTable(
  "organisation_roles",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    name: text("name").notNull(),
    description: text("description").notNull(),
    version: integer("version").notNull().default(1),
    branchScope: text("branch_scope").notNull().default("[]"),
    approvalLimitCents: integer("approval_limit_cents"),
    status: text("status").notNull(),
    createdBy: text("created_by").notNull().references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [uniqueIndex("ux_org_roles_name_version").on(table.organisationId, table.name, table.version), index("idx_org_roles_status").on(table.organisationId, table.status)],
);

export const organisationRolePermissions = sqliteTable(
  "organisation_role_permissions",
  {
    id: text("id").primaryKey(),
    organisationRoleId: text("organisation_role_id").notNull().references(() => organisationRoles.id),
    permissionCode: text("permission_code").notNull().references(() => accessPermissions.code),
    recordScope: text("record_scope").notNull(),
    effect: text("effect").notNull(),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_org_role_permission").on(table.organisationRoleId, table.permissionCode)],
);

export const userRoleAssignments = sqliteTable(
  "user_role_assignments",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    userId: text("user_id").notNull().references(() => appUsers.id),
    employeeId: text("employee_id").references(() => employees.id),
    organisationRoleId: text("organisation_role_id").notNull().references(() => organisationRoles.id),
    status: text("status").notNull(),
    effectiveFrom: text("effective_from").notNull(),
    effectiveTo: text("effective_to"),
    assignedBy: text("assigned_by").notNull().references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
  },
  (table) => [index("idx_user_roles_subject_effective").on(table.organisationId, table.userId, table.status, table.effectiveFrom)],
);

export const userCapabilityAssignments = sqliteTable(
  "user_capability_assignments",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    userId: text("user_id").notNull().references(() => appUsers.id),
    capability: text("capability").notNull(),
    status: text("status").notNull(),
    effectiveFrom: text("effective_from").notNull(),
    effectiveTo: text("effective_to"),
    assignedBy: text("assigned_by").notNull().references(() => appUsers.id),
  },
  (table) => [uniqueIndex("ux_user_capability").on(table.organisationId, table.userId, table.capability)],
);

export const workflows = sqliteTable(
  "workflows",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    name: text("name").notNull(),
    domainAction: text("domain_action").notNull(),
    status: text("status").notNull(),
    createdBy: text("created_by").notNull().references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [uniqueIndex("ux_workflows_org_name").on(table.organisationId, table.name), index("idx_workflows_org_status").on(table.organisationId, table.status)],
);

export const workflowVersions = sqliteTable(
  "workflow_versions",
  {
    id: text("id").primaryKey(),
    workflowId: text("workflow_id").notNull().references(() => workflows.id),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    versionNumber: integer("version_number").notNull(),
    status: text("status").notNull(),
    definitionHash: text("definition_hash").notNull(),
    definition: text("definition").notNull(),
    effectiveFrom: text("effective_from"),
    publishedBy: text("published_by").references(() => appUsers.id),
    approvedBy: text("approved_by").references(() => appUsers.id),
    publishedAt: text("published_at"),
    retiredAt: text("retired_at"),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_workflow_versions_number").on(table.workflowId, table.versionNumber), index("idx_workflow_versions_effective").on(table.organisationId, table.status, table.effectiveFrom)],
);

export const workflowNodes = sqliteTable(
  "workflow_nodes",
  {
    id: text("id").primaryKey(),
    workflowVersionId: text("workflow_version_id").notNull().references(() => workflowVersions.id),
    nodeKey: text("node_key").notNull(),
    nodeType: text("node_type").notNull(),
    label: text("label").notNull(),
    assigneeType: text("assignee_type"),
    assigneeReference: text("assignee_reference"),
    sequence: integer("sequence").notNull(),
  },
  (table) => [uniqueIndex("ux_workflow_node_key").on(table.workflowVersionId, table.nodeKey)],
);

export const workflowTransitions = sqliteTable(
  "workflow_transitions",
  {
    id: text("id").primaryKey(),
    workflowVersionId: text("workflow_version_id").notNull().references(() => workflowVersions.id),
    fromNodeKey: text("from_node_key").notNull(),
    toNodeKey: text("to_node_key").notNull(),
    sequence: integer("sequence").notNull(),
  },
  (table) => [uniqueIndex("ux_workflow_transition").on(table.workflowVersionId, table.fromNodeKey, table.toNodeKey)],
);

export const workflowConditions = sqliteTable("workflow_conditions", {
  id: text("id").primaryKey(),
  workflowTransitionId: text("workflow_transition_id").notNull().references(() => workflowTransitions.id),
  field: text("field").notNull(),
  operator: text("operator").notNull(),
  comparisonValue: text("comparison_value").notNull(),
});

export const workflowInstances = sqliteTable(
  "workflow_instances",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    workflowVersionId: text("workflow_version_id").notNull().references(() => workflowVersions.id),
    resourceType: text("resource_type").notNull(),
    resourceId: text("resource_id").notNull(),
    initiatedBy: text("initiated_by").notNull().references(() => appUsers.id),
    status: text("status").notNull(),
    currentNodeKey: text("current_node_key").notNull(),
    contextSnapshot: text("context_snapshot").notNull(),
    startedAt: text("started_at").notNull(),
    completedAt: text("completed_at"),
  },
  (table) => [index("idx_workflow_instances_resource").on(table.organisationId, table.resourceType, table.resourceId)],
);

export const workflowAssignments = sqliteTable(
  "workflow_assignments",
  {
    id: text("id").primaryKey(),
    workflowInstanceId: text("workflow_instance_id").notNull().references(() => workflowInstances.id),
    nodeKey: text("node_key").notNull(),
    assignedUserId: text("assigned_user_id").references(() => appUsers.id),
    assignedRoleId: text("assigned_role_id").references(() => organisationRoles.id),
    status: text("status").notNull(),
    dueAt: text("due_at"),
    assignedAt: text("assigned_at").notNull(),
  },
  (table) => [index("idx_workflow_assignments_queue").on(table.assignedUserId, table.status, table.dueAt)],
);

export const workflowApprovals = sqliteTable(
  "workflow_approvals",
  {
    id: text("id").primaryKey(),
    workflowInstanceId: text("workflow_instance_id").notNull().references(() => workflowInstances.id),
    workflowAssignmentId: text("workflow_assignment_id").notNull().references(() => workflowAssignments.id),
    workflowVersionId: text("workflow_version_id").notNull().references(() => workflowVersions.id),
    actorId: text("actor_id").notNull().references(() => appUsers.id),
    decision: text("decision").notNull(),
    reason: text("reason").notNull(),
    authoritySnapshot: text("authority_snapshot").notNull(),
    decidedAt: text("decided_at").notNull(),
  },
  (table) => [uniqueIndex("ux_workflow_approval_assignment").on(table.workflowAssignmentId)],
);

export const workflowDelegations = sqliteTable(
  "workflow_delegations",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    delegatorUserId: text("delegator_user_id").notNull().references(() => appUsers.id),
    delegateUserId: text("delegate_user_id").notNull().references(() => appUsers.id),
    workflowId: text("workflow_id").references(() => workflows.id),
    scope: text("scope").notNull(),
    status: text("status").notNull(),
    effectiveFrom: text("effective_from").notNull(),
    effectiveTo: text("effective_to").notNull(),
    approvedBy: text("approved_by").notNull().references(() => appUsers.id),
  },
  (table) => [index("idx_workflow_delegations_effective").on(table.organisationId, table.delegateUserId, table.status, table.effectiveFrom)],
);

export const accessRequests = sqliteTable(
  "access_requests",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    requestedBy: text("requested_by").notNull().references(() => appUsers.id),
    subjectUserId: text("subject_user_id").notNull().references(() => appUsers.id),
    organisationRoleId: text("organisation_role_id").notNull().references(() => organisationRoles.id),
    justification: text("justification").notNull(),
    status: text("status").notNull(),
    requestedAt: text("requested_at").notNull(),
    completedAt: text("completed_at"),
  },
  (table) => [index("idx_access_requests_org_status").on(table.organisationId, table.status, table.requestedAt)],
);

export const accessApprovals = sqliteTable("access_approvals", {
  id: text("id").primaryKey(),
  accessRequestId: text("access_request_id").notNull().references(() => accessRequests.id),
  reviewerId: text("reviewer_id").notNull().references(() => appUsers.id),
  reviewerStage: text("reviewer_stage").notNull(),
  decision: text("decision").notNull(),
  reason: text("reason").notNull(),
  decidedAt: text("decided_at").notNull(),
});

export const accessReviews = sqliteTable(
  "access_reviews",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    name: text("name").notNull(),
    reviewType: text("review_type").notNull(),
    status: text("status").notNull(),
    periodStart: text("period_start").notNull(),
    dueAt: text("due_at").notNull(),
    createdBy: text("created_by").notNull().references(() => appUsers.id),
    createdAt: text("created_at").notNull(),
    completedAt: text("completed_at"),
  },
  (table) => [index("idx_access_reviews_org_status").on(table.organisationId, table.status, table.dueAt)],
);

export const accessCertifications = sqliteTable(
  "access_certifications",
  {
    id: text("id").primaryKey(),
    accessReviewId: text("access_review_id").notNull().references(() => accessReviews.id),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    subjectUserId: text("subject_user_id").notNull().references(() => appUsers.id),
    reviewerId: text("reviewer_id").notNull().references(() => appUsers.id),
    snapshot: text("snapshot").notNull(),
    disposition: text("disposition").notNull(),
    finding: text("finding"),
    certifiedAt: text("certified_at").notNull(),
  },
  (table) => [uniqueIndex("ux_access_certification_subject").on(table.accessReviewId, table.subjectUserId)],
);

export const sodRules = sqliteTable(
  "sod_rules",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").references(() => organisations.id),
    code: text("code").notNull(),
    name: text("name").notNull(),
    actionSet: text("action_set").notNull(),
    scope: text("scope").notNull(),
    mandatory: integer("mandatory").notNull().default(1),
    status: text("status").notNull(),
    effectiveFrom: text("effective_from").notNull(),
    createdAt: text("created_at").notNull(),
  },
  (table) => [uniqueIndex("ux_sod_rule_code_org").on(table.code, table.organisationId)],
);

export const sodViolations = sqliteTable(
  "sod_violations",
  {
    id: text("id").primaryKey(),
    organisationId: text("organisation_id").notNull().references(() => organisations.id),
    sodRuleId: text("sod_rule_id").notNull().references(() => sodRules.id),
    actorId: text("actor_id").notNull().references(() => appUsers.id),
    resourceType: text("resource_type").notNull(),
    resourceId: text("resource_id").notNull(),
    status: text("status").notNull(),
    evidence: text("evidence").notNull(),
    detectedAt: text("detected_at").notNull(),
    resolvedAt: text("resolved_at"),
  },
  (table) => [index("idx_sod_violations_org_status").on(table.organisationId, table.status, table.detectedAt)],
);

export const navigationWorkspaces = sqliteTable(
  "navigation_workspaces",
  {
    id: text("id").primaryKey(),
    key: text("workspace_key").notNull(),
    label: text("label").notNull(),
    description: text("description").notNull(),
    sortOrder: integer("sort_order").notNull(),
    status: text("status").notNull(),
    classification: text("classification").notNull(),
  },
  (table) => [uniqueIndex("ux_navigation_workspace_key").on(table.key)],
);

export const navigationFolders = sqliteTable(
  "navigation_folders",
  {
    id: text("id").primaryKey(),
    workspaceId: text("workspace_id").notNull().references(() => navigationWorkspaces.id),
    parentFolderId: text("parent_folder_id"),
    key: text("folder_key").notNull(),
    label: text("label").notNull(),
    sortOrder: integer("sort_order").notNull(),
    status: text("status").notNull(),
  },
  (table) => [uniqueIndex("ux_navigation_folder_key").on(table.workspaceId, table.key), index("idx_navigation_folders_parent").on(table.workspaceId, table.parentFolderId, table.sortOrder)],
);

export const navigationItems = sqliteTable(
  "navigation_items",
  {
    id: text("id").primaryKey(),
    workspaceId: text("workspace_id").notNull().references(() => navigationWorkspaces.id),
    folderId: text("folder_id").notNull().references(() => navigationFolders.id),
    key: text("item_key").notNull(),
    label: text("label").notNull(),
    href: text("href").notNull(),
    featureKey: text("feature_key"),
    capability: text("capability"),
    requiredPermission: text("required_permission").notNull(),
    sortOrder: integer("sort_order").notNull(),
    status: text("status").notNull(),
    classification: text("classification").notNull(),
  },
  (table) => [uniqueIndex("ux_navigation_item_key").on(table.key), index("idx_navigation_items_folder").on(table.folderId, table.sortOrder)],
);

export const navigationPermissions = sqliteTable("navigation_permissions", {
  id: text("id").primaryKey(),
  navigationItemId: text("navigation_item_id").notNull().references(() => navigationItems.id),
  policyKey: text("policy_key").notNull(),
  effect: text("effect").notNull(),
  safeRestrictionReason: text("safe_restriction_reason").notNull(),
});

export const navigationPreferences = sqliteTable(
  "navigation_preferences",
  {
    id: text("id").primaryKey(),
    userId: text("user_id").notNull().references(() => appUsers.id),
    organisationId: text("organisation_id").references(() => organisations.id),
    preferenceType: text("preference_type").notNull(),
    value: text("value").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (table) => [uniqueIndex("ux_navigation_preference").on(table.userId, table.organisationId, table.preferenceType)],
);

export const seedState = sqliteTable("seed_state", {
  key: text("key").primaryKey(),
  appliedAt: text("applied_at").notNull(),
});
