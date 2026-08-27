import { env } from "cloudflare:workers";

const SCHEMA_STATEMENTS = [
  `CREATE TABLE IF NOT EXISTS taxpayers (
    id TEXT PRIMARY KEY, vat_number TEXT NOT NULL UNIQUE, tin TEXT NOT NULL,
    legal_name TEXT NOT NULL, trading_name TEXT, taxpayer_type TEXT NOT NULL,
    vat_status TEXT NOT NULL, return_frequency TEXT NOT NULL, address TEXT NOT NULL,
    email TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`,
  `CREATE TABLE IF NOT EXISTS taxpayer_identifiers (
    id TEXT PRIMARY KEY, taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id),
    identifier_type TEXT NOT NULL, identifier_value TEXT NOT NULL,
    country TEXT NOT NULL DEFAULT 'NA', status TEXT NOT NULL, source TEXT NOT NULL,
    verified_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version INTEGER NOT NULL DEFAULT 1, effective_from TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    effective_to TEXT, previous_version_id TEXT REFERENCES taxpayer_identifiers(id),
    UNIQUE (identifier_type, identifier_value, country)
  )`,
  `CREATE TABLE IF NOT EXISTS invoices (
    id TEXT PRIMARY KEY, invoice_number TEXT NOT NULL, document_type TEXT NOT NULL,
    source_system TEXT NOT NULL, source_document_id TEXT NOT NULL,
    supplier_taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), supplier_name TEXT NOT NULL,
    supplier_vat_number TEXT NOT NULL, customer_taxpayer_id TEXT REFERENCES taxpayers(id),
    customer_name TEXT NOT NULL, customer_vat_number TEXT, issue_date TEXT NOT NULL,
    currency TEXT NOT NULL, line_net_cents INTEGER NOT NULL, tax_cents INTEGER NOT NULL,
    total_cents INTEGER NOT NULL, status TEXT NOT NULL, risk_level TEXT NOT NULL,
    payload_hash TEXT NOT NULL, transaction_id TEXT NOT NULL, certificate_id TEXT NOT NULL UNIQUE,
    verification_token TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL, certified_at TEXT NOT NULL,
    UNIQUE (supplier_taxpayer_id, source_system, source_document_id),
    UNIQUE (supplier_taxpayer_id, invoice_number)
  )`,
  `CREATE TABLE IF NOT EXISTS app_users (
    id TEXT PRIMARY KEY, external_user_id TEXT NOT NULL UNIQUE, email TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL, role TEXT NOT NULL, taxpayer_id TEXT REFERENCES taxpayers(id),
    status TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`,
  `CREATE TABLE IF NOT EXISTS identity_providers (
    id TEXT PRIMARY KEY, provider_key TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL,
    provider_type TEXT NOT NULL, authority_level TEXT NOT NULL, issuer TEXT,
    status TEXT NOT NULL, configuration_status TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`,
  `CREATE TABLE IF NOT EXISTS identity_links (
    id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES app_users(id),
    provider_id TEXT NOT NULL REFERENCES identity_providers(id), subject TEXT NOT NULL,
    email_at_link TEXT, assurance_level TEXT NOT NULL, status TEXT NOT NULL,
    linked_at TEXT NOT NULL, last_authenticated_at TEXT,
    UNIQUE (provider_id, subject)
  )`,
  // Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #2): a real,
  // server-verified TOTP (RFC 6238) credential and step-up event log,
  // replacing the previous client-asserted x-vat-msa-auth-assurance /
  // x-vat-msa-reauthenticated-at request headers, which requireStepUp
  // trusted verbatim from the caller with no server-side backing at all.
  `CREATE TABLE IF NOT EXISTS mfa_totp_credentials (
    user_id TEXT PRIMARY KEY REFERENCES app_users(id), secret_base32 TEXT NOT NULL,
    status TEXT NOT NULL, last_used_counter INTEGER, created_at TEXT NOT NULL, verified_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS step_up_events (
    id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES app_users(id),
    method TEXT NOT NULL, verified_at TEXT NOT NULL, expires_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS user_invitations (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    email TEXT NOT NULL, role_code TEXT NOT NULL REFERENCES access_roles(code),
    claim_token TEXT NOT NULL UNIQUE, status TEXT NOT NULL,
    invited_by TEXT NOT NULL REFERENCES app_users(id), invited_at TEXT NOT NULL,
    expires_at TEXT NOT NULL, claimed_at TEXT, claimed_by_user_id TEXT REFERENCES app_users(id)
  )`,
  `CREATE TABLE IF NOT EXISTS organisations (
    id TEXT PRIMARY KEY, taxpayer_id TEXT NOT NULL UNIQUE REFERENCES taxpayers(id),
    legal_name TEXT NOT NULL, trading_name TEXT, status TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`,
  `CREATE TABLE IF NOT EXISTS branches (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    code TEXT NOT NULL, name TEXT NOT NULL, address TEXT NOT NULL, status TEXT NOT NULL,
    is_head_office INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (organisation_id, code)
  )`,
  `CREATE TABLE IF NOT EXISTS organisation_capabilities (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    capability TEXT NOT NULL, status TEXT NOT NULL, effective_from TEXT NOT NULL,
    effective_to TEXT, approved_by TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (organisation_id, capability)
  )`,
  `CREATE TABLE IF NOT EXISTS access_roles (
    code TEXT PRIMARY KEY, name TEXT NOT NULL, audience TEXT NOT NULL,
    risk_tier TEXT NOT NULL, status TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`,
  `CREATE TABLE IF NOT EXISTS access_permissions (
    code TEXT PRIMARY KEY, resource TEXT NOT NULL, action TEXT NOT NULL,
    description TEXT NOT NULL, classification TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`,
  `CREATE TABLE IF NOT EXISTS role_permission_grants (
    id TEXT PRIMARY KEY, role_code TEXT NOT NULL REFERENCES access_roles(code),
    permission_code TEXT NOT NULL REFERENCES access_permissions(code),
    effect TEXT NOT NULL, conditions TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (role_code, permission_code)
  )`,
  `CREATE TABLE IF NOT EXISTS organisation_memberships (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    user_id TEXT NOT NULL REFERENCES app_users(id), role_code TEXT NOT NULL REFERENCES access_roles(code),
    branch_id TEXT REFERENCES branches(id), status TEXT NOT NULL, valid_from TEXT NOT NULL,
    valid_to TEXT, assigned_by TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`,
  `CREATE TABLE IF NOT EXISTS registration_applications (
    id TEXT PRIMARY KEY, idempotency_key TEXT NOT NULL, request_hash TEXT NOT NULL,
    vat_number TEXT NOT NULL, tin TEXT NOT NULL, company_registration_number TEXT,
    legal_name TEXT NOT NULL, trading_name TEXT, taxpayer_type TEXT NOT NULL,
    return_frequency TEXT NOT NULL, address TEXT NOT NULL, email TEXT NOT NULL,
    status TEXT NOT NULL, verification_source TEXT NOT NULL,
    submitted_by TEXT NOT NULL REFERENCES app_users(id), submitted_at TEXT NOT NULL,
    reviewed_at TEXT, review_reason TEXT,
    UNIQUE (submitted_by, idempotency_key)
  )`,
  `CREATE TABLE IF NOT EXISTS registration_verifications (
    id TEXT PRIMARY KEY,
    registration_application_id TEXT NOT NULL REFERENCES registration_applications(id),
    provider TEXT NOT NULL, request_reference TEXT NOT NULL, status TEXT NOT NULL,
    response_hash TEXT, verified_taxpayer_id TEXT REFERENCES taxpayers(id),
    checked_at TEXT NOT NULL, expires_at TEXT,
    UNIQUE (provider, request_reference)
  )`,
  `CREATE TABLE IF NOT EXISTS business_parties (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    display_name TEXT NOT NULL, legal_name TEXT, vat_number TEXT, tin TEXT,
    email TEXT, phone TEXT, address TEXT, source_system TEXT NOT NULL,
    source_party_id TEXT, status TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (organisation_id, source_system, source_party_id)
  )`,
  `CREATE TABLE IF NOT EXISTS party_relationships (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    party_id TEXT NOT NULL REFERENCES business_parties(id), relationship TEXT NOT NULL,
    status TEXT NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (organisation_id, party_id, relationship)
  )`,
  `CREATE TABLE IF NOT EXISTS party_verification_snapshots (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    party_id TEXT NOT NULL REFERENCES business_parties(id), vat_number TEXT NOT NULL,
    taxpayer_active INTEGER NOT NULL, organisation_active INTEGER NOT NULL,
    can_act_as_seller INTEGER NOT NULL, capabilities TEXT NOT NULL,
    verified_by TEXT NOT NULL REFERENCES app_users(id), verified_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS products (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    sku TEXT NOT NULL, name TEXT NOT NULL, description TEXT, unit_code TEXT NOT NULL,
    tax_category TEXT NOT NULL, tax_rate_bps INTEGER NOT NULL,
    sales_price_cents INTEGER NOT NULL, cost_price_cents INTEGER NOT NULL,
    status TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (organisation_id, sku)
  )`,
  `CREATE TABLE IF NOT EXISTS warehouses (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    branch_id TEXT REFERENCES branches(id), code TEXT NOT NULL, name TEXT NOT NULL,
    address TEXT NOT NULL, status TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (organisation_id, code)
  )`,
  `CREATE TABLE IF NOT EXISTS projects (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    code TEXT NOT NULL, name TEXT NOT NULL,
    customer_party_id TEXT REFERENCES business_parties(id),
    manager_user_id TEXT REFERENCES app_users(id), currency TEXT NOT NULL,
    start_date TEXT NOT NULL, end_date TEXT, status TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (organisation_id, code)
  )`,
  `CREATE TABLE IF NOT EXISTS expense_categories (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    code TEXT NOT NULL, name TEXT NOT NULL, default_tax_category TEXT NOT NULL,
    requires_receipt INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (organisation_id, code)
  )`,
  `CREATE TABLE IF NOT EXISTS quotations (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    branch_id TEXT REFERENCES branches(id),
    customer_party_id TEXT NOT NULL REFERENCES business_parties(id),
    quotation_number TEXT NOT NULL, currency TEXT NOT NULL, issue_date TEXT NOT NULL,
    valid_until TEXT NOT NULL, status TEXT NOT NULL, subtotal_cents INTEGER NOT NULL,
    tax_cents INTEGER NOT NULL, total_cents INTEGER NOT NULL, notes TEXT,
    created_by TEXT NOT NULL REFERENCES app_users(id), approved_by TEXT REFERENCES app_users(id),
    accepted_at TEXT, converted_invoice_id TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (organisation_id, quotation_number)
  )`,
  `CREATE TABLE IF NOT EXISTS quotation_lines (
    id TEXT PRIMARY KEY, quotation_id TEXT NOT NULL REFERENCES quotations(id), line_number INTEGER NOT NULL,
    product_id TEXT REFERENCES products(id), description TEXT NOT NULL,
    quantity_micros INTEGER NOT NULL, unit_code TEXT NOT NULL, unit_price_cents INTEGER NOT NULL,
    net_amount_cents INTEGER NOT NULL, tax_category TEXT NOT NULL, tax_rate_bps INTEGER NOT NULL,
    tax_amount_cents INTEGER NOT NULL, UNIQUE (quotation_id, line_number)
  )`,
  `CREATE TABLE IF NOT EXISTS quotation_revisions (
    id TEXT PRIMARY KEY, quotation_id TEXT NOT NULL REFERENCES quotations(id),
    organisation_id TEXT NOT NULL REFERENCES organisations(id), revision_number INTEGER NOT NULL,
    action TEXT NOT NULL, status TEXT NOT NULL, snapshot_hash TEXT NOT NULL, snapshot TEXT NOT NULL,
    created_by TEXT NOT NULL REFERENCES app_users(id), created_at TEXT NOT NULL,
    UNIQUE (quotation_id, revision_number)
  )`,
  `CREATE TABLE IF NOT EXISTS chart_of_accounts (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    code TEXT NOT NULL, name TEXT NOT NULL, account_type TEXT NOT NULL, currency TEXT NOT NULL,
    control_type TEXT, status TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (organisation_id, code)
  )`,
  `CREATE TABLE IF NOT EXISTS journal_entries (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    journal_number TEXT NOT NULL, journal_date TEXT NOT NULL, reference TEXT,
    description TEXT NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL,
    source_type TEXT NOT NULL, source_id TEXT, created_by TEXT NOT NULL REFERENCES app_users(id),
    posted_by TEXT REFERENCES app_users(id), created_at TEXT NOT NULL, posted_at TEXT,
    reverses_journal_entry_id TEXT REFERENCES journal_entries(id),
    UNIQUE (organisation_id, journal_number)
  )`,
  `CREATE TABLE IF NOT EXISTS accounting_periods (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    period_code TEXT NOT NULL, period_start TEXT NOT NULL, period_end TEXT NOT NULL,
    status TEXT NOT NULL, closed_by TEXT REFERENCES app_users(id), closed_at TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (organisation_id, period_code)
  )`,
  `CREATE TABLE IF NOT EXISTS journal_lines (
    id TEXT PRIMARY KEY, journal_entry_id TEXT NOT NULL REFERENCES journal_entries(id),
    line_number INTEGER NOT NULL, account_id TEXT NOT NULL REFERENCES chart_of_accounts(id),
    branch_id TEXT REFERENCES branches(id), project_id TEXT REFERENCES projects(id),
    description TEXT NOT NULL, debit_cents INTEGER NOT NULL DEFAULT 0,
    credit_cents INTEGER NOT NULL DEFAULT 0, tax_code TEXT,
    UNIQUE (journal_entry_id, line_number)
  )`,
  `CREATE TABLE IF NOT EXISTS expenses (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    branch_id TEXT REFERENCES branches(id), category_id TEXT NOT NULL REFERENCES expense_categories(id),
    supplier_party_id TEXT REFERENCES business_parties(id), project_id TEXT REFERENCES projects(id),
    expense_number TEXT NOT NULL, expense_date TEXT NOT NULL, description TEXT NOT NULL,
    currency TEXT NOT NULL, net_cents INTEGER NOT NULL, tax_cents INTEGER NOT NULL,
    total_cents INTEGER NOT NULL, status TEXT NOT NULL, receipt_document_id TEXT,
    created_by TEXT NOT NULL REFERENCES app_users(id), approved_by TEXT REFERENCES app_users(id),
    created_at TEXT NOT NULL, approved_at TEXT, rejection_reason TEXT, UNIQUE (organisation_id, expense_number)
  )`,
  `CREATE TABLE IF NOT EXISTS inventory_balances (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    warehouse_id TEXT NOT NULL REFERENCES warehouses(id), product_id TEXT NOT NULL REFERENCES products(id),
    quantity_micros INTEGER NOT NULL DEFAULT 0 CHECK (quantity_micros >= 0), average_cost_cents INTEGER NOT NULL DEFAULT 0,
    version INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL,
    UNIQUE (warehouse_id, product_id)
  )`,
  `CREATE TABLE IF NOT EXISTS stock_movements (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    warehouse_id TEXT NOT NULL REFERENCES warehouses(id), product_id TEXT NOT NULL REFERENCES products(id),
    movement_type TEXT NOT NULL, quantity_micros INTEGER NOT NULL, unit_cost_cents INTEGER NOT NULL,
    reference_type TEXT NOT NULL, reference_id TEXT NOT NULL, reason TEXT NOT NULL,
    occurred_at TEXT NOT NULL, actor_id TEXT NOT NULL REFERENCES app_users(id),
    UNIQUE (organisation_id, reference_type, reference_id)
  )`,
  `CREATE TABLE IF NOT EXISTS project_budgets (
    id TEXT PRIMARY KEY, project_id TEXT NOT NULL REFERENCES projects(id), category TEXT NOT NULL,
    amount_cents INTEGER NOT NULL, approved_amount_cents INTEGER NOT NULL, status TEXT NOT NULL,
    approved_by TEXT REFERENCES app_users(id), approved_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (project_id, category)
  )`,
  `CREATE TABLE IF NOT EXISTS project_costs (
    id TEXT PRIMARY KEY, project_id TEXT NOT NULL REFERENCES projects(id), cost_type TEXT NOT NULL,
    source_id TEXT NOT NULL, amount_cents INTEGER NOT NULL, currency TEXT NOT NULL,
    description TEXT, occurred_at TEXT NOT NULL, created_by TEXT REFERENCES app_users(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (project_id, cost_type, source_id)
  )`,
  `CREATE TABLE IF NOT EXISTS import_records (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    declaration_number TEXT NOT NULL, customs_office TEXT, supplier_name TEXT NOT NULL,
    country_of_origin TEXT NOT NULL, currency TEXT NOT NULL, customs_value_cents INTEGER NOT NULL,
    import_vat_cents INTEGER NOT NULL, declaration_date TEXT NOT NULL, evidence_document_id TEXT,
    status TEXT NOT NULL, created_by TEXT NOT NULL REFERENCES app_users(id), created_at TEXT NOT NULL,
    UNIQUE (organisation_id, declaration_number)
  )`,
  `CREATE TABLE IF NOT EXISTS document_metadata (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    owner_domain TEXT NOT NULL, owner_resource_id TEXT NOT NULL, object_key TEXT NOT NULL UNIQUE,
    file_name TEXT NOT NULL, content_type TEXT NOT NULL, size_bytes INTEGER NOT NULL,
    checksum_sha256 TEXT NOT NULL, classification TEXT NOT NULL, scan_status TEXT NOT NULL,
    status TEXT NOT NULL, uploaded_by TEXT NOT NULL REFERENCES app_users(id), uploaded_at TEXT NOT NULL,
    retained_until TEXT, legal_hold INTEGER NOT NULL DEFAULT 0,
    scanned_by TEXT REFERENCES app_users(id), scanned_at TEXT,
    supersedes_document_id TEXT REFERENCES document_metadata(id)
  )`,
  `CREATE TABLE IF NOT EXISTS command_idempotency (
    id TEXT PRIMARY KEY, actor_id TEXT NOT NULL REFERENCES app_users(id),
    command_type TEXT NOT NULL, idempotency_key TEXT NOT NULL, request_hash TEXT NOT NULL,
    resource_type TEXT NOT NULL, resource_id TEXT NOT NULL, created_at TEXT NOT NULL,
    UNIQUE (actor_id, command_type, idempotency_key)
  )`,
  `CREATE TABLE IF NOT EXISTS tax_rule_sets (
    id TEXT PRIMARY KEY, jurisdiction TEXT NOT NULL, version TEXT NOT NULL,
    effective_from TEXT NOT NULL, effective_to TEXT, standard_rate_bps INTEGER NOT NULL,
    legal_authority_reference TEXT, status TEXT NOT NULL,
    approved_by TEXT REFERENCES app_users(id), approved_at TEXT, created_at TEXT NOT NULL,
    UNIQUE (jurisdiction, version)
  )`,
  `CREATE TABLE IF NOT EXISTS tax_box_mappings (
    id TEXT PRIMARY KEY, tax_rule_set_id TEXT NOT NULL REFERENCES tax_rule_sets(id),
    box_code TEXT NOT NULL, label TEXT NOT NULL, source_entry_type TEXT NOT NULL,
    direction TEXT NOT NULL, formula TEXT NOT NULL, status TEXT NOT NULL,
    UNIQUE (tax_rule_set_id, box_code)
  )`,
  `CREATE TABLE IF NOT EXISTS vat_periods (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), period_code TEXT NOT NULL,
    period_start TEXT NOT NULL, period_end TEXT NOT NULL, due_date TEXT NOT NULL,
    status TEXT NOT NULL, lock_version INTEGER NOT NULL DEFAULT 0,
    close_requested_by TEXT REFERENCES app_users(id), close_requested_at TEXT,
    closed_by TEXT REFERENCES app_users(id), closed_at TEXT,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (taxpayer_id, period_code)
  )`,
  `CREATE TABLE IF NOT EXISTS vat_adjustments (
    id TEXT PRIMARY KEY, vat_period_id TEXT NOT NULL REFERENCES vat_periods(id),
    organisation_id TEXT NOT NULL REFERENCES organisations(id), taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id),
    adjustment_type TEXT NOT NULL, direction TEXT NOT NULL, amount_cents INTEGER NOT NULL,
    reason_code TEXT NOT NULL, explanation TEXT NOT NULL,
    evidence_document_id TEXT REFERENCES document_metadata(id), status TEXT NOT NULL,
    created_by TEXT NOT NULL REFERENCES app_users(id), approved_by TEXT REFERENCES app_users(id),
    created_at TEXT NOT NULL, approved_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS reconciliation_matches (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), vat_period_id TEXT REFERENCES vat_periods(id),
    invoice_id TEXT NOT NULL REFERENCES invoices(id), ledger_entry_id TEXT REFERENCES ledger_entries(id),
    match_type TEXT NOT NULL, confidence_bps INTEGER NOT NULL, status TEXT NOT NULL,
    evidence TEXT NOT NULL, reconciled_by TEXT REFERENCES app_users(id), reconciled_at TEXT,
    created_at TEXT NOT NULL, UNIQUE (invoice_id, taxpayer_id)
  )`,
  `CREATE TABLE IF NOT EXISTS vat_return_versions (
    id TEXT PRIMARY KEY, vat_period_id TEXT NOT NULL REFERENCES vat_periods(id),
    organisation_id TEXT NOT NULL REFERENCES organisations(id), taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id),
    version_number INTEGER NOT NULL, parent_version_id TEXT,
    tax_rule_set_id TEXT NOT NULL REFERENCES tax_rule_sets(id),
    output_tax_cents INTEGER NOT NULL, input_tax_cents INTEGER NOT NULL,
    adjustment_cents INTEGER NOT NULL, net_payable_cents INTEGER NOT NULL,
    status TEXT NOT NULL, ledger_snapshot_hash TEXT NOT NULL,
    generated_by TEXT NOT NULL REFERENCES app_users(id), generated_at TEXT NOT NULL,
    approved_by TEXT REFERENCES app_users(id), approved_at TEXT, superseded_at TEXT,
    UNIQUE (vat_period_id, version_number)
  )`,
  `CREATE TABLE IF NOT EXISTS vat_return_boxes (
    id TEXT PRIMARY KEY, vat_return_version_id TEXT NOT NULL REFERENCES vat_return_versions(id),
    box_code TEXT NOT NULL, label TEXT NOT NULL, amount_cents INTEGER NOT NULL,
    source_count INTEGER NOT NULL, calculation_trace TEXT NOT NULL,
    UNIQUE (vat_return_version_id, box_code)
  )`,
  `CREATE TABLE IF NOT EXISTS approval_tasks (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), domain TEXT NOT NULL,
    resource_type TEXT NOT NULL, resource_id TEXT NOT NULL, requested_action TEXT NOT NULL,
    risk_tier TEXT NOT NULL, status TEXT NOT NULL,
    requested_by TEXT NOT NULL REFERENCES app_users(id), assigned_role TEXT NOT NULL,
    decided_by TEXT REFERENCES app_users(id), requested_at TEXT NOT NULL,
    decided_at TEXT, decision_comment TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS vat_return_submissions (
    id TEXT PRIMARY KEY, vat_return_version_id TEXT NOT NULL REFERENCES vat_return_versions(id),
    provider TEXT NOT NULL, request_reference TEXT NOT NULL, status TEXT NOT NULL,
    request_hash TEXT NOT NULL, provider_reference TEXT, response_hash TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    requested_by TEXT NOT NULL REFERENCES app_users(id), requested_at TEXT NOT NULL,
    submitted_at TEXT, acknowledged_at TEXT, last_error TEXT,
    UNIQUE (provider, request_reference)
  )`,
  `CREATE TABLE IF NOT EXISTS consent_grants (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), granted_by TEXT NOT NULL REFERENCES app_users(id),
    grantee_type TEXT NOT NULL, grantee_id TEXT NOT NULL, purpose TEXT NOT NULL,
    data_categories TEXT NOT NULL, legal_basis TEXT NOT NULL, status TEXT NOT NULL,
    valid_from TEXT NOT NULL, valid_to TEXT, revoked_at TEXT, created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS delegations (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), delegator_user_id TEXT NOT NULL REFERENCES app_users(id),
    delegate_user_id TEXT NOT NULL REFERENCES app_users(id), scopes TEXT NOT NULL, status TEXT NOT NULL,
    valid_from TEXT NOT NULL, valid_to TEXT, approved_by TEXT REFERENCES app_users(id),
    approved_at TEXT, created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS tax_obligations (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), obligation_type TEXT NOT NULL,
    period_code TEXT NOT NULL, due_date TEXT NOT NULL, amount_cents INTEGER NOT NULL,
    currency TEXT NOT NULL, status TEXT NOT NULL, source_system TEXT NOT NULL,
    source_reference TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (taxpayer_id, obligation_type, period_code)
  )`,
  `CREATE TABLE IF NOT EXISTS communication_threads (
    id TEXT PRIMARY KEY, organisation_id TEXT REFERENCES organisations(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id),
    related_resource_type TEXT NOT NULL, related_resource_id TEXT NOT NULL,
    subject TEXT NOT NULL, classification TEXT NOT NULL, status TEXT NOT NULL,
    opened_by TEXT NOT NULL REFERENCES app_users(id), opened_at TEXT NOT NULL,
    closed_by TEXT REFERENCES app_users(id), closed_at TEXT, closure_reason TEXT,
    UNIQUE (related_resource_type, related_resource_id)
  )`,
  `CREATE TABLE IF NOT EXISTS communications (
    id TEXT PRIMARY KEY, organisation_id TEXT REFERENCES organisations(id),
    taxpayer_id TEXT REFERENCES taxpayers(id), thread_id TEXT REFERENCES communication_threads(id),
    channel TEXT NOT NULL, direction TEXT NOT NULL,
    subject TEXT NOT NULL, content_summary TEXT NOT NULL, classification TEXT NOT NULL,
    related_resource_type TEXT, related_resource_id TEXT, external_reference TEXT,
    status TEXT NOT NULL, actor_id TEXT NOT NULL REFERENCES app_users(id), occurred_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS notifications (
    id TEXT PRIMARY KEY, user_id TEXT REFERENCES app_users(id), taxpayer_id TEXT REFERENCES taxpayers(id),
    notification_type TEXT NOT NULL, title TEXT NOT NULL, message TEXT NOT NULL,
    severity TEXT NOT NULL, status TEXT NOT NULL, action_url TEXT,
    created_at TEXT NOT NULL, read_at TEXT,
    cancelled_by TEXT REFERENCES app_users(id), cancelled_at TEXT, cancellation_reason TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS notification_deliveries (
    id TEXT PRIMARY KEY, notification_id TEXT NOT NULL REFERENCES notifications(id),
    channel TEXT NOT NULL, status TEXT NOT NULL, attempted_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS notification_preferences (
    id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES app_users(id),
    channel TEXT NOT NULL, enabled INTEGER NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (user_id, channel)
  )`,
  `CREATE TABLE IF NOT EXISTS audit_cases (
    id TEXT PRIMARY KEY, case_number TEXT NOT NULL UNIQUE,
    organisation_id TEXT NOT NULL REFERENCES organisations(id), taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id),
    case_type TEXT NOT NULL, title TEXT NOT NULL, opening_reason TEXT NOT NULL,
    risk_tier TEXT NOT NULL, status TEXT NOT NULL,
    assigned_officer_id TEXT REFERENCES app_users(id), opened_by TEXT NOT NULL REFERENCES app_users(id),
    opened_at TEXT NOT NULL, updated_at TEXT NOT NULL, closed_at TEXT,
    suspended_from_status TEXT, appeal_reference TEXT, appeal_linked_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS audit_case_transitions (
    id TEXT PRIMARY KEY, audit_case_id TEXT NOT NULL REFERENCES audit_cases(id),
    action TEXT NOT NULL, from_status TEXT NOT NULL, to_status TEXT NOT NULL,
    actor_id TEXT NOT NULL REFERENCES app_users(id), reason TEXT NOT NULL, occurred_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS audit_evidence (
    id TEXT PRIMARY KEY, audit_case_id TEXT NOT NULL REFERENCES audit_cases(id),
    evidence_type TEXT NOT NULL, source_resource_type TEXT NOT NULL, source_resource_id TEXT NOT NULL,
    document_id TEXT REFERENCES document_metadata(id), checksum_sha256 TEXT NOT NULL,
    description TEXT NOT NULL, status TEXT NOT NULL,
    added_by TEXT NOT NULL REFERENCES app_users(id), added_at TEXT NOT NULL,
    previous_version_id TEXT REFERENCES audit_evidence(id), legal_hold INTEGER NOT NULL DEFAULT 0
  )`,
  `CREATE TABLE IF NOT EXISTS audit_evidence_custody_events (
    id TEXT PRIMARY KEY, audit_evidence_id TEXT NOT NULL REFERENCES audit_evidence(id),
    action TEXT NOT NULL, actor_id TEXT NOT NULL REFERENCES app_users(id), notes TEXT,
    integrity_verified INTEGER, occurred_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS audit_case_notes (
    id TEXT PRIMARY KEY, audit_case_id TEXT NOT NULL REFERENCES audit_cases(id),
    author_id TEXT NOT NULL REFERENCES app_users(id), body TEXT NOT NULL,
    supersedes_note_id TEXT REFERENCES audit_case_notes(id), created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS audit_findings (
    id TEXT PRIMARY KEY, audit_case_id TEXT NOT NULL REFERENCES audit_cases(id),
    finding_code TEXT NOT NULL, title TEXT NOT NULL, description TEXT NOT NULL,
    legal_reference TEXT, amount_cents INTEGER NOT NULL, currency TEXT NOT NULL,
    status TEXT NOT NULL, author_id TEXT NOT NULL REFERENCES app_users(id),
    created_at TEXT NOT NULL, resolved_at TEXT,
    UNIQUE (audit_case_id, finding_code)
  )`,
  `CREATE TABLE IF NOT EXISTS disputes (
    id TEXT PRIMARY KEY, dispute_number TEXT NOT NULL UNIQUE,
    organisation_id TEXT NOT NULL REFERENCES organisations(id), taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id),
    audit_case_id TEXT REFERENCES audit_cases(id), disputed_resource_type TEXT NOT NULL,
    disputed_resource_id TEXT NOT NULL, grounds TEXT NOT NULL,
    disputed_amount_cents INTEGER NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL,
    filed_by TEXT NOT NULL REFERENCES app_users(id), assigned_officer_id TEXT REFERENCES app_users(id),
    filed_at TEXT NOT NULL, decided_at TEXT, decision_summary TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS risk_indicators (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), subject_type TEXT NOT NULL,
    subject_id TEXT NOT NULL, indicator_code TEXT NOT NULL, score_bps INTEGER NOT NULL,
    severity TEXT NOT NULL, rationale TEXT NOT NULL, rule_version TEXT NOT NULL,
    decision_effect TEXT NOT NULL, status TEXT NOT NULL, detected_at TEXT NOT NULL,
    reviewed_by TEXT REFERENCES app_users(id), reviewed_at TEXT,
    assigned_officer_id TEXT REFERENCES app_users(id), escalated_case_id TEXT REFERENCES audit_cases(id),
    UNIQUE (subject_type, subject_id, indicator_code, rule_version)
  )`,
  `CREATE TABLE IF NOT EXISTS refund_claims (
    id TEXT PRIMARY KEY, claim_number TEXT NOT NULL UNIQUE,
    organisation_id TEXT NOT NULL REFERENCES organisations(id), taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id),
    vat_return_version_id TEXT NOT NULL UNIQUE REFERENCES vat_return_versions(id),
    amount_cents INTEGER NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL,
    evidence_status TEXT NOT NULL, risk_tier TEXT NOT NULL,
    requested_by TEXT NOT NULL REFERENCES app_users(id), requested_at TEXT NOT NULL,
    approved_by TEXT REFERENCES app_users(id), approved_at TEXT, payment_instruction_id TEXT,
    resume_status TEXT, offset_amount_cents INTEGER NOT NULL DEFAULT 0,
    net_payable_cents INTEGER, dispute_reason TEXT,
    claim_snapshot TEXT, claim_snapshot_hash TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS refund_claim_transitions (
    id TEXT PRIMARY KEY, refund_claim_id TEXT NOT NULL REFERENCES refund_claims(id),
    action TEXT NOT NULL, from_status TEXT NOT NULL, to_status TEXT NOT NULL,
    actor_id TEXT NOT NULL REFERENCES app_users(id), findings TEXT NOT NULL, occurred_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS refund_claim_checks (
    id TEXT PRIMARY KEY, refund_claim_id TEXT NOT NULL REFERENCES refund_claims(id),
    check_code TEXT NOT NULL, status TEXT NOT NULL, rationale TEXT NOT NULL, evaluated_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS integration_connections (
    id TEXT PRIMARY KEY, organisation_id TEXT REFERENCES organisations(id), provider_key TEXT NOT NULL,
    category TEXT NOT NULL, display_name TEXT NOT NULL, capabilities TEXT NOT NULL,
    endpoint_reference TEXT, credential_reference TEXT, configuration_status TEXT NOT NULL,
    operational_status TEXT NOT NULL, data_classification TEXT NOT NULL,
    last_health_check_at TEXT, last_health_outcome TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (provider_key, organisation_id)
  )`,
  `CREATE TABLE IF NOT EXISTS api_clients (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id), name TEXT NOT NULL,
    client_key TEXT NOT NULL UNIQUE, scopes TEXT NOT NULL, credential_reference TEXT NOT NULL,
    status TEXT NOT NULL, rate_limit_profile TEXT NOT NULL, last_rotated_at TEXT,
    expires_at TEXT, created_by TEXT NOT NULL REFERENCES app_users(id), created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS webhook_subscriptions (
    id TEXT PRIMARY KEY, api_client_id TEXT NOT NULL REFERENCES api_clients(id), event_types TEXT NOT NULL,
    endpoint_url TEXT NOT NULL, signing_key_reference TEXT NOT NULL, status TEXT NOT NULL,
    created_at TEXT NOT NULL, UNIQUE (api_client_id, endpoint_url)
  )`,
  `CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id TEXT PRIMARY KEY, webhook_subscription_id TEXT NOT NULL REFERENCES webhook_subscriptions(id),
    outbox_event_id TEXT NOT NULL REFERENCES outbox_events(id), status TEXT NOT NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0, response_status INTEGER,
    next_attempt_at TEXT, delivered_at TEXT, last_error TEXT, created_at TEXT NOT NULL,
    UNIQUE (webhook_subscription_id, outbox_event_id)
  )`,
  `CREATE TABLE IF NOT EXISTS sync_jobs (
    id TEXT PRIMARY KEY, integration_connection_id TEXT NOT NULL REFERENCES integration_connections(id),
    organisation_id TEXT REFERENCES organisations(id), job_type TEXT NOT NULL, direction TEXT NOT NULL,
    status TEXT NOT NULL, cursor TEXT, records_read INTEGER NOT NULL DEFAULT 0,
    records_written INTEGER NOT NULL DEFAULT 0, error_count INTEGER NOT NULL DEFAULT 0,
    requested_by TEXT NOT NULL REFERENCES app_users(id), requested_at TEXT NOT NULL,
    started_at TEXT, completed_at TEXT, last_error TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS bank_imports (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    integration_connection_id TEXT REFERENCES integration_connections(id), document_id TEXT REFERENCES document_metadata(id),
    bank_name TEXT NOT NULL, account_reference_masked TEXT NOT NULL,
    statement_from TEXT NOT NULL, statement_to TEXT NOT NULL, currency TEXT NOT NULL,
    transaction_count INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL,
    requested_by TEXT NOT NULL REFERENCES app_users(id), created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS payment_instructions (
    id TEXT PRIMARY KEY, refund_claim_id TEXT REFERENCES refund_claims(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), amount_cents INTEGER NOT NULL,
    currency TEXT NOT NULL, beneficiary_reference_masked TEXT NOT NULL, provider TEXT NOT NULL,
    status TEXT NOT NULL, provider_reference TEXT, idempotency_key TEXT NOT NULL,
    approved_by TEXT NOT NULL REFERENCES app_users(id), approved_at TEXT NOT NULL,
    submitted_at TEXT, settled_at TEXT, last_error TEXT, UNIQUE (provider, idempotency_key)
  )`,
  `CREATE TABLE IF NOT EXISTS offline_devices (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    branch_id TEXT REFERENCES branches(id), device_code TEXT NOT NULL, display_name TEXT NOT NULL,
    public_key_reference TEXT, certificate_fingerprint TEXT, status TEXT NOT NULL,
    enrolment_status TEXT NOT NULL, last_accepted_sequence INTEGER NOT NULL DEFAULT 0,
    last_batch_hash TEXT, last_seen_at TEXT, created_at TEXT NOT NULL,
    UNIQUE (organisation_id, device_code)
  )`,
  `CREATE TABLE IF NOT EXISTS offline_number_ranges (
    id TEXT PRIMARY KEY, offline_device_id TEXT NOT NULL REFERENCES offline_devices(id),
    document_type TEXT NOT NULL, prefix TEXT NOT NULL, range_start INTEGER NOT NULL,
    range_end INTEGER NOT NULL, next_number INTEGER NOT NULL, status TEXT NOT NULL,
    valid_from TEXT NOT NULL, valid_to TEXT NOT NULL,
    UNIQUE (offline_device_id, document_type, prefix)
  )`,
  `CREATE TABLE IF NOT EXISTS offline_sync_batches (
    id TEXT PRIMARY KEY, offline_device_id TEXT NOT NULL REFERENCES offline_devices(id),
    client_batch_id TEXT NOT NULL, sequence_from INTEGER NOT NULL, sequence_to INTEGER NOT NULL,
    previous_batch_hash TEXT, batch_hash TEXT NOT NULL, signature TEXT NOT NULL,
    document_count INTEGER NOT NULL, status TEXT NOT NULL, received_at TEXT NOT NULL,
    processed_at TEXT, rejection_reason TEXT,
    UNIQUE (offline_device_id, client_batch_id), UNIQUE (offline_device_id, sequence_from, sequence_to)
  )`,
  `CREATE TABLE IF NOT EXISTS offline_conflicts (
    id TEXT PRIMARY KEY, offline_sync_batch_id TEXT NOT NULL REFERENCES offline_sync_batches(id),
    conflict_type TEXT NOT NULL, source_document_id TEXT NOT NULL, existing_resource_id TEXT,
    status TEXT NOT NULL, resolution TEXT, resolved_by TEXT REFERENCES app_users(id),
    created_at TEXT NOT NULL, resolved_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS report_definitions (
    id TEXT PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, audience TEXT NOT NULL,
    description TEXT NOT NULL, classification TEXT NOT NULL, query_version TEXT NOT NULL,
    status TEXT NOT NULL, created_at TEXT NOT NULL,
    freshness_tier TEXT NOT NULL DEFAULT 'DAILY', guardrail TEXT NOT NULL DEFAULT ''
  )`,
  `CREATE TABLE IF NOT EXISTS report_runs (
    id TEXT PRIMARY KEY, report_definition_id TEXT NOT NULL REFERENCES report_definitions(id),
    organisation_id TEXT REFERENCES organisations(id), taxpayer_id TEXT REFERENCES taxpayers(id),
    parameters TEXT NOT NULL, status TEXT NOT NULL, row_count INTEGER, result_summary TEXT,
    output_document_id TEXT REFERENCES document_metadata(id),
    requested_by TEXT NOT NULL REFERENCES app_users(id), requested_at TEXT NOT NULL,
    completed_at TEXT, expires_at TEXT, error_code TEXT,
    scope_snapshot TEXT, published_by TEXT REFERENCES app_users(id), published_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS report_exports (
    id TEXT PRIMARY KEY, report_run_id TEXT NOT NULL REFERENCES report_runs(id),
    document_id TEXT NOT NULL REFERENCES document_metadata(id),
    status TEXT NOT NULL, requires_step_up INTEGER NOT NULL, watermark TEXT NOT NULL,
    requested_by TEXT NOT NULL REFERENCES app_users(id), requested_at TEXT NOT NULL,
    approved_by TEXT REFERENCES app_users(id), approved_at TEXT,
    cancelled_by TEXT REFERENCES app_users(id), cancelled_at TEXT, cancellation_reason TEXT,
    expires_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS data_products (
    id TEXT PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, description TEXT NOT NULL,
    source_report_definition_id TEXT NOT NULL REFERENCES report_definitions(id),
    status TEXT NOT NULL, created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS data_product_lineage (
    id TEXT PRIMARY KEY, data_product_id TEXT NOT NULL REFERENCES data_products(id),
    source_type TEXT NOT NULL, source_id TEXT NOT NULL, source_label TEXT NOT NULL, recorded_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS metrics (
    id TEXT PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
    data_product_id TEXT NOT NULL REFERENCES data_products(id),
    field TEXT NOT NULL, unit TEXT NOT NULL, status TEXT NOT NULL,
    anomaly_threshold_pct REAL NOT NULL DEFAULT 25, created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS analytics_model_runs (
    id TEXT PRIMARY KEY, data_product_id TEXT NOT NULL REFERENCES data_products(id),
    report_run_id TEXT NOT NULL REFERENCES report_runs(id),
    status TEXT NOT NULL, model_output TEXT NOT NULL,
    requested_by TEXT NOT NULL REFERENCES app_users(id), requested_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS data_product_snapshots (
    id TEXT PRIMARY KEY, data_product_id TEXT NOT NULL REFERENCES data_products(id),
    model_run_id TEXT NOT NULL REFERENCES analytics_model_runs(id),
    snapshot TEXT NOT NULL, previous_snapshot_id TEXT REFERENCES data_product_snapshots(id),
    published_by TEXT NOT NULL REFERENCES app_users(id), published_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS analytics_anomaly_candidates (
    id TEXT PRIMARY KEY, data_product_snapshot_id TEXT NOT NULL REFERENCES data_product_snapshots(id),
    metric_code TEXT NOT NULL, previous_value REAL NOT NULL, current_value REAL NOT NULL,
    pct_change REAL NOT NULL, threshold_pct REAL NOT NULL, detected_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS feature_flags (
    id TEXT PRIMARY KEY, key TEXT NOT NULL UNIQUE, name TEXT NOT NULL, description TEXT NOT NULL,
    rollout_scope TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL,
    updated_by TEXT REFERENCES app_users(id), updated_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS platform_config (
    id TEXT PRIMARY KEY, key TEXT NOT NULL UNIQUE, category TEXT NOT NULL, description TEXT NOT NULL,
    value TEXT NOT NULL, status TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL, updated_by TEXT REFERENCES app_users(id), updated_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS access_policies (
    id TEXT PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, policy_type TEXT NOT NULL,
    description TEXT NOT NULL, parameters TEXT NOT NULL, status TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL, updated_by TEXT REFERENCES app_users(id), updated_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS change_requests (
    id TEXT PRIMARY KEY, target_type TEXT NOT NULL, target_id TEXT NOT NULL,
    previous_value TEXT NOT NULL, proposed_value TEXT NOT NULL, reason TEXT NOT NULL,
    status TEXT NOT NULL, requested_by TEXT NOT NULL REFERENCES app_users(id), requested_at TEXT NOT NULL,
    decided_by TEXT REFERENCES app_users(id), decided_at TEXT, decision_notes TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS service_components (
    id TEXT PRIMARY KEY, component_key TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL,
    component_type TEXT NOT NULL, criticality TEXT NOT NULL,
    configuration_status TEXT NOT NULL, operational_status TEXT NOT NULL,
    dependency_summary TEXT NOT NULL, last_checked_at TEXT, status_detail TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS invoice_lines (
    id TEXT PRIMARY KEY, invoice_id TEXT NOT NULL REFERENCES invoices(id), line_number INTEGER NOT NULL,
    description TEXT NOT NULL, quantity TEXT NOT NULL, unit_code TEXT NOT NULL,
    unit_price_cents INTEGER NOT NULL, net_amount_cents INTEGER NOT NULL,
    tax_rate_bps INTEGER NOT NULL, tax_category TEXT NOT NULL, tax_amount_cents INTEGER NOT NULL,
    vat_rule_id TEXT REFERENCES vat_rules(id),
    UNIQUE (invoice_id, line_number)
  )`,
  `CREATE TABLE IF NOT EXISTS vat_rules (
    id TEXT PRIMARY KEY, tax_category TEXT NOT NULL, country TEXT NOT NULL DEFAULT 'NA',
    rate_bps INTEGER NOT NULL, status TEXT NOT NULL, version INTEGER NOT NULL,
    effective_from TEXT NOT NULL, effective_to TEXT,
    proposed_by TEXT NOT NULL, proposed_at TEXT NOT NULL,
    approved_by TEXT, approved_at TEXT, approval_reason TEXT, proposal_reason TEXT NOT NULL,
    superseded_by TEXT REFERENCES vat_rules(id),
    UNIQUE (tax_category, country, version)
  )`,
  `CREATE TABLE IF NOT EXISTS certificates (
    id TEXT PRIMARY KEY, invoice_id TEXT NOT NULL UNIQUE REFERENCES invoices(id),
    verification_token TEXT NOT NULL UNIQUE, invoice_hash TEXT NOT NULL,
    signature TEXT NOT NULL, signature_profile TEXT NOT NULL, status TEXT NOT NULL, issued_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS invoice_corrections (
    id TEXT PRIMARY KEY, original_invoice_id TEXT NOT NULL REFERENCES invoices(id),
    correction_invoice_id TEXT NOT NULL UNIQUE REFERENCES invoices(id),
    correction_type TEXT NOT NULL, reason_code TEXT, reason TEXT NOT NULL,
    status TEXT NOT NULL, created_by TEXT NOT NULL REFERENCES app_users(id), created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS ledger_entries (
    id TEXT PRIMARY KEY, transaction_id TEXT NOT NULL, invoice_id TEXT NOT NULL REFERENCES invoices(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), entry_type TEXT NOT NULL,
    direction TEXT NOT NULL, amount_cents INTEGER NOT NULL, period TEXT NOT NULL, created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS vat_transactions (
    id TEXT PRIMARY KEY, invoice_id TEXT NOT NULL REFERENCES invoices(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), transaction_type TEXT NOT NULL,
    reference_transaction_id TEXT REFERENCES vat_transactions(id), created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS reconciliation_exceptions (
    id TEXT PRIMARY KEY, invoice_id TEXT NOT NULL REFERENCES invoices(id),
    taxpayer_id TEXT REFERENCES taxpayers(id), exception_type TEXT NOT NULL,
    severity TEXT NOT NULL, status TEXT NOT NULL, summary TEXT NOT NULL,
    created_at TEXT NOT NULL, resolved_at TEXT,
    assigned_officer_id TEXT REFERENCES app_users(id), resolved_by TEXT REFERENCES app_users(id),
    resolution_notes TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS audit_events (
    id TEXT PRIMARY KEY, actor_id TEXT NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL,
    resource_type TEXT NOT NULL, resource_id TEXT NOT NULL, outcome TEXT NOT NULL,
    details TEXT NOT NULL, previous_hash TEXT, event_hash TEXT NOT NULL, occurred_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS audit_chain_verifications (
    id TEXT PRIMARY KEY, requested_by TEXT NOT NULL REFERENCES app_users(id), status TEXT NOT NULL,
    verified_count INTEGER NOT NULL, first_break_id TEXT, first_break_reason TEXT,
    started_at TEXT NOT NULL, completed_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS idempotency_records (
    id TEXT PRIMARY KEY, actor_id TEXT NOT NULL, idempotency_key TEXT NOT NULL,
    request_hash TEXT NOT NULL, response_invoice_id TEXT NOT NULL REFERENCES invoices(id),
    created_at TEXT NOT NULL, UNIQUE (actor_id, idempotency_key)
  )`,
  `CREATE TABLE IF NOT EXISTS rate_limit_windows (
    bucket_key TEXT NOT NULL, window_start INTEGER NOT NULL, request_count INTEGER NOT NULL,
    expires_at INTEGER NOT NULL, UNIQUE (bucket_key, window_start)
  )`,
  `CREATE TABLE IF NOT EXISTS security_events (
    id TEXT PRIMARY KEY, event_type TEXT NOT NULL, severity TEXT NOT NULL, actor_id TEXT,
    source_token TEXT NOT NULL, correlation_id TEXT NOT NULL, action TEXT NOT NULL,
    outcome TEXT NOT NULL, details TEXT NOT NULL, occurred_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS security_detection_rules (
    id TEXT PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, description TEXT NOT NULL,
    event_type TEXT NOT NULL, group_by TEXT NOT NULL, threshold_count INTEGER NOT NULL,
    window_minutes INTEGER NOT NULL, severity TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS security_incidents (
    id TEXT PRIMARY KEY, title TEXT NOT NULL, severity TEXT NOT NULL, status TEXT NOT NULL,
    source_event_id TEXT REFERENCES security_events(id), automated_action TEXT,
    owner TEXT, detection_rule_id TEXT REFERENCES security_detection_rules(id), group_key TEXT,
    subject_user_id TEXT REFERENCES app_users(id), opened_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    closed_at TEXT, closed_by TEXT REFERENCES app_users(id), resolution_notes TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS security_playbook_actions (
    id TEXT PRIMARY KEY, incident_id TEXT NOT NULL REFERENCES security_incidents(id),
    action_type TEXT NOT NULL, actor_id TEXT REFERENCES app_users(id), automated INTEGER NOT NULL DEFAULT 0,
    details TEXT NOT NULL, performed_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS outbox_events (
    id TEXT PRIMARY KEY, aggregate_type TEXT NOT NULL, aggregate_id TEXT NOT NULL,
    event_type TEXT NOT NULL, event_version INTEGER NOT NULL, partition_key TEXT NOT NULL,
    payload TEXT NOT NULL, status TEXT NOT NULL, publish_attempts INTEGER NOT NULL DEFAULT 0,
    occurred_at TEXT NOT NULL, available_at TEXT NOT NULL, published_at TEXT, last_error TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS license_plans (
    id TEXT PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL, version INTEGER NOT NULL,
    status TEXT NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT, created_at TEXT NOT NULL,
    UNIQUE (code, version)
  )`,
  `CREATE TABLE IF NOT EXISTS license_features (
    feature_key TEXT PRIMARY KEY, name TEXT NOT NULL, description TEXT NOT NULL,
    metric_key TEXT, protected INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS license_plan_entitlements (
    id TEXT PRIMARY KEY, license_plan_id TEXT NOT NULL REFERENCES license_plans(id),
    feature_key TEXT NOT NULL REFERENCES license_features(feature_key), enabled INTEGER NOT NULL DEFAULT 1,
    limit_value INTEGER, configuration TEXT NOT NULL DEFAULT '{}',
    UNIQUE (license_plan_id, feature_key)
  )`,
  `CREATE TABLE IF NOT EXISTS subscriptions (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    provider TEXT NOT NULL, provider_reference TEXT NOT NULL, status TEXT NOT NULL,
    activated_at TEXT, current_period_start TEXT NOT NULL, current_period_end TEXT NOT NULL,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (provider, provider_reference)
  )`,
  `CREATE TABLE IF NOT EXISTS organisation_licenses (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    subscription_id TEXT NOT NULL REFERENCES subscriptions(id), license_plan_id TEXT NOT NULL REFERENCES license_plans(id),
    state TEXT NOT NULL, state_version INTEGER NOT NULL DEFAULT 1, effective_from TEXT NOT NULL,
    effective_to TEXT, grace_ends_at TEXT, retention_policy TEXT NOT NULL, updated_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS license_usage (
    id TEXT PRIMARY KEY, organisation_license_id TEXT NOT NULL REFERENCES organisation_licenses(id),
    organisation_id TEXT NOT NULL REFERENCES organisations(id), metric_key TEXT NOT NULL,
    period_key TEXT NOT NULL, used_value INTEGER NOT NULL DEFAULT 0, reserved_value INTEGER NOT NULL DEFAULT 0,
    version INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL,
    UNIQUE (organisation_id, metric_key, period_key)
  )`,
  `CREATE TABLE IF NOT EXISTS license_events (
    id TEXT PRIMARY KEY, organisation_license_id TEXT NOT NULL REFERENCES organisation_licenses(id),
    organisation_id TEXT NOT NULL REFERENCES organisations(id), event_type TEXT NOT NULL,
    from_state TEXT, to_state TEXT NOT NULL, authority TEXT NOT NULL, reason TEXT NOT NULL, occurred_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS departments (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id), code TEXT NOT NULL,
    name TEXT NOT NULL, parent_department_id TEXT, status TEXT NOT NULL, created_at TEXT NOT NULL,
    UNIQUE (organisation_id, code)
  )`,
  `CREATE TABLE IF NOT EXISTS business_units (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id), code TEXT NOT NULL,
    name TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL,
    UNIQUE (organisation_id, code)
  )`,
  `CREATE TABLE IF NOT EXISTS job_titles (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id), code TEXT NOT NULL,
    name TEXT NOT NULL, description TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL,
    UNIQUE (organisation_id, code)
  )`,
  `CREATE TABLE IF NOT EXISTS positions (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    job_title_id TEXT NOT NULL REFERENCES job_titles(id), department_id TEXT REFERENCES departments(id),
    business_unit_id TEXT REFERENCES business_units(id), branch_id TEXT REFERENCES branches(id),
    code TEXT NOT NULL, name TEXT NOT NULL, status TEXT NOT NULL, UNIQUE (organisation_id, code)
  )`,
  `CREATE TABLE IF NOT EXISTS employees (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id), user_id TEXT REFERENCES app_users(id),
    employee_number TEXT NOT NULL, full_name TEXT NOT NULL, email TEXT NOT NULL,
    position_id TEXT REFERENCES positions(id), job_title_id TEXT REFERENCES job_titles(id),
    department_id TEXT REFERENCES departments(id), business_unit_id TEXT REFERENCES business_units(id),
    branch_id TEXT REFERENCES branches(id), manager_employee_id TEXT, status TEXT NOT NULL,
    invited_at TEXT, activated_at TEXT, terminated_at TEXT, last_activity_at TEXT,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (organisation_id, employee_number), UNIQUE (organisation_id, email)
  )`,
  `CREATE TABLE IF NOT EXISTS organisation_administrator_roles (
    code TEXT PRIMARY KEY, name TEXT NOT NULL, maximum_scope TEXT NOT NULL,
    protected INTEGER NOT NULL DEFAULT 1
  )`,
  `CREATE TABLE IF NOT EXISTS organisation_administrators (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    user_id TEXT NOT NULL REFERENCES app_users(id), employee_id TEXT REFERENCES employees(id),
    administrator_role_code TEXT NOT NULL REFERENCES organisation_administrator_roles(code),
    scope TEXT NOT NULL, is_primary INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL,
    effective_from TEXT NOT NULL, effective_to TEXT, appointed_by TEXT NOT NULL, approval_reference TEXT NOT NULL,
    UNIQUE (organisation_id, user_id, administrator_role_code)
  )`,
  `CREATE TABLE IF NOT EXISTS organisation_roles (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    name TEXT NOT NULL, description TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1,
    branch_scope TEXT NOT NULL DEFAULT '[]', approval_limit_cents INTEGER, status TEXT NOT NULL,
    created_by TEXT NOT NULL REFERENCES app_users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (organisation_id, name, version)
  )`,
  `CREATE TABLE IF NOT EXISTS organisation_role_permissions (
    id TEXT PRIMARY KEY, organisation_role_id TEXT NOT NULL REFERENCES organisation_roles(id),
    permission_code TEXT NOT NULL REFERENCES access_permissions(code), record_scope TEXT NOT NULL,
    effect TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE (organisation_role_id, permission_code)
  )`,
  `CREATE TABLE IF NOT EXISTS user_role_assignments (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    user_id TEXT NOT NULL REFERENCES app_users(id), employee_id TEXT REFERENCES employees(id),
    organisation_role_id TEXT NOT NULL REFERENCES organisation_roles(id), status TEXT NOT NULL,
    effective_from TEXT NOT NULL, effective_to TEXT, assigned_by TEXT NOT NULL REFERENCES app_users(id), created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS user_capability_assignments (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    user_id TEXT NOT NULL REFERENCES app_users(id), capability TEXT NOT NULL, status TEXT NOT NULL,
    effective_from TEXT NOT NULL, effective_to TEXT, assigned_by TEXT NOT NULL REFERENCES app_users(id),
    UNIQUE (organisation_id, user_id, capability)
  )`,
  `CREATE TABLE IF NOT EXISTS workflows (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    name TEXT NOT NULL, domain_action TEXT NOT NULL, status TEXT NOT NULL,
    created_by TEXT NOT NULL REFERENCES app_users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (organisation_id, name)
  )`,
  `CREATE TABLE IF NOT EXISTS workflow_versions (
    id TEXT PRIMARY KEY, workflow_id TEXT NOT NULL REFERENCES workflows(id),
    organisation_id TEXT NOT NULL REFERENCES organisations(id), version_number INTEGER NOT NULL,
    status TEXT NOT NULL, definition_hash TEXT NOT NULL, definition TEXT NOT NULL,
    effective_from TEXT, published_by TEXT REFERENCES app_users(id), approved_by TEXT REFERENCES app_users(id),
    published_at TEXT, retired_at TEXT, created_at TEXT NOT NULL, UNIQUE (workflow_id, version_number)
  )`,
  `CREATE TABLE IF NOT EXISTS workflow_nodes (
    id TEXT PRIMARY KEY, workflow_version_id TEXT NOT NULL REFERENCES workflow_versions(id),
    node_key TEXT NOT NULL, node_type TEXT NOT NULL, label TEXT NOT NULL,
    assignee_type TEXT, assignee_reference TEXT, sequence INTEGER NOT NULL,
    UNIQUE (workflow_version_id, node_key)
  )`,
  `CREATE TABLE IF NOT EXISTS workflow_transitions (
    id TEXT PRIMARY KEY, workflow_version_id TEXT NOT NULL REFERENCES workflow_versions(id),
    from_node_key TEXT NOT NULL, to_node_key TEXT NOT NULL, sequence INTEGER NOT NULL,
    UNIQUE (workflow_version_id, from_node_key, to_node_key)
  )`,
  `CREATE TABLE IF NOT EXISTS workflow_conditions (
    id TEXT PRIMARY KEY, workflow_transition_id TEXT NOT NULL REFERENCES workflow_transitions(id),
    field TEXT NOT NULL, operator TEXT NOT NULL, comparison_value TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS workflow_instances (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    workflow_version_id TEXT NOT NULL REFERENCES workflow_versions(id), resource_type TEXT NOT NULL,
    resource_id TEXT NOT NULL, initiated_by TEXT NOT NULL REFERENCES app_users(id), status TEXT NOT NULL,
    current_node_key TEXT NOT NULL, context_snapshot TEXT NOT NULL, started_at TEXT NOT NULL, completed_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS workflow_assignments (
    id TEXT PRIMARY KEY, workflow_instance_id TEXT NOT NULL REFERENCES workflow_instances(id),
    node_key TEXT NOT NULL, assigned_user_id TEXT REFERENCES app_users(id),
    assigned_role_id TEXT REFERENCES organisation_roles(id), status TEXT NOT NULL,
    due_at TEXT, assigned_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS workflow_approvals (
    id TEXT PRIMARY KEY, workflow_instance_id TEXT NOT NULL REFERENCES workflow_instances(id),
    workflow_assignment_id TEXT NOT NULL UNIQUE REFERENCES workflow_assignments(id),
    workflow_version_id TEXT NOT NULL REFERENCES workflow_versions(id), actor_id TEXT NOT NULL REFERENCES app_users(id),
    decision TEXT NOT NULL, reason TEXT NOT NULL, authority_snapshot TEXT NOT NULL, decided_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS workflow_delegations (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    delegator_user_id TEXT NOT NULL REFERENCES app_users(id), delegate_user_id TEXT NOT NULL REFERENCES app_users(id),
    workflow_id TEXT REFERENCES workflows(id), scope TEXT NOT NULL, status TEXT NOT NULL,
    effective_from TEXT NOT NULL, effective_to TEXT NOT NULL, approved_by TEXT NOT NULL REFERENCES app_users(id),
    reason TEXT NOT NULL DEFAULT '', revoked_reason TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS access_requests (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    requested_by TEXT NOT NULL REFERENCES app_users(id), subject_user_id TEXT NOT NULL REFERENCES app_users(id),
    organisation_role_id TEXT NOT NULL REFERENCES organisation_roles(id), justification TEXT NOT NULL,
    status TEXT NOT NULL, requested_at TEXT NOT NULL, completed_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS access_approvals (
    id TEXT PRIMARY KEY, access_request_id TEXT NOT NULL REFERENCES access_requests(id),
    reviewer_id TEXT NOT NULL REFERENCES app_users(id), reviewer_stage TEXT NOT NULL,
    decision TEXT NOT NULL, reason TEXT NOT NULL, decided_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS access_reviews (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    name TEXT NOT NULL, review_type TEXT NOT NULL, status TEXT NOT NULL,
    period_start TEXT NOT NULL, due_at TEXT NOT NULL, created_by TEXT NOT NULL REFERENCES app_users(id),
    created_at TEXT NOT NULL, completed_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS access_certifications (
    id TEXT PRIMARY KEY, access_review_id TEXT NOT NULL REFERENCES access_reviews(id),
    organisation_id TEXT NOT NULL REFERENCES organisations(id), subject_user_id TEXT NOT NULL REFERENCES app_users(id),
    reviewer_id TEXT NOT NULL REFERENCES app_users(id), snapshot TEXT NOT NULL,
    disposition TEXT NOT NULL, finding TEXT, certified_at TEXT NOT NULL,
    UNIQUE (access_review_id, subject_user_id)
  )`,
  `CREATE TABLE IF NOT EXISTS sod_rules (
    id TEXT PRIMARY KEY, organisation_id TEXT REFERENCES organisations(id), code TEXT NOT NULL,
    name TEXT NOT NULL, action_set TEXT NOT NULL, scope TEXT NOT NULL,
    mandatory INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL, effective_from TEXT NOT NULL,
    created_at TEXT NOT NULL, UNIQUE (code, organisation_id)
  )`,
  `CREATE TABLE IF NOT EXISTS sod_violations (
    id TEXT PRIMARY KEY, organisation_id TEXT NOT NULL REFERENCES organisations(id),
    sod_rule_id TEXT NOT NULL REFERENCES sod_rules(id), actor_id TEXT NOT NULL REFERENCES app_users(id),
    resource_type TEXT NOT NULL, resource_id TEXT NOT NULL, status TEXT NOT NULL,
    evidence TEXT NOT NULL, detected_at TEXT NOT NULL, resolved_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS navigation_workspaces (
    id TEXT PRIMARY KEY, workspace_key TEXT NOT NULL UNIQUE, label TEXT NOT NULL,
    description TEXT NOT NULL, sort_order INTEGER NOT NULL, status TEXT NOT NULL, classification TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS navigation_folders (
    id TEXT PRIMARY KEY, workspace_id TEXT NOT NULL REFERENCES navigation_workspaces(id),
    parent_folder_id TEXT, folder_key TEXT NOT NULL, label TEXT NOT NULL,
    sort_order INTEGER NOT NULL, status TEXT NOT NULL, UNIQUE (workspace_id, folder_key)
  )`,
  `CREATE TABLE IF NOT EXISTS navigation_items (
    id TEXT PRIMARY KEY, workspace_id TEXT NOT NULL REFERENCES navigation_workspaces(id),
    folder_id TEXT NOT NULL REFERENCES navigation_folders(id), item_key TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL, href TEXT NOT NULL, feature_key TEXT, capability TEXT,
    required_permission TEXT NOT NULL, sort_order INTEGER NOT NULL, status TEXT NOT NULL,
    classification TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS navigation_permissions (
    id TEXT PRIMARY KEY, navigation_item_id TEXT NOT NULL REFERENCES navigation_items(id),
    policy_key TEXT NOT NULL, effect TEXT NOT NULL, safe_restriction_reason TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS navigation_preferences (
    id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES app_users(id),
    organisation_id TEXT REFERENCES organisations(id), preference_type TEXT NOT NULL,
    value TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (user_id, organisation_id, preference_type)
  )`,
  `CREATE TABLE IF NOT EXISTS seed_state (key TEXT PRIMARY KEY, applied_at TEXT NOT NULL)`,
  `CREATE INDEX IF NOT EXISTS idx_invoices_status_issue_date ON invoices(status, issue_date)`,
  `CREATE INDEX IF NOT EXISTS idx_taxpayer_identifiers_taxpayer ON taxpayer_identifiers(taxpayer_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_invoices_supplier_issue_date ON invoices(supplier_taxpayer_id, issue_date)`,
  `CREATE INDEX IF NOT EXISTS idx_invoices_customer_issue_date ON invoices(customer_taxpayer_id, issue_date)`,
  `CREATE INDEX IF NOT EXISTS idx_ledger_taxpayer_period ON ledger_entries(taxpayer_id, period)`,
  `CREATE INDEX IF NOT EXISTS idx_ledger_transaction ON ledger_entries(transaction_id)`,
  `CREATE INDEX IF NOT EXISTS idx_exceptions_status_created ON reconciliation_exceptions(status, created_at)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_resource ON audit_events(resource_type, resource_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_occurred ON audit_events(occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_rate_limit_expiry ON rate_limit_windows(expires_at)`,
  `CREATE INDEX IF NOT EXISTS idx_security_events_severity_time ON security_events(severity, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_security_events_actor_time ON security_events(actor_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_security_incidents_status_severity ON security_incidents(status, severity)`,
  `CREATE INDEX IF NOT EXISTS idx_outbox_status_available ON outbox_events(status, available_at)`,
  `CREATE INDEX IF NOT EXISTS idx_outbox_aggregate ON outbox_events(aggregate_type, aggregate_id)`,
  `CREATE INDEX IF NOT EXISTS idx_identity_links_user_status ON identity_links(user_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_step_up_events_user_expires ON step_up_events(user_id, expires_at)`,
  `CREATE INDEX IF NOT EXISTS idx_branches_organisation_status ON branches(organisation_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_organisation_capabilities_status ON organisation_capabilities(status, capability)`,
  `CREATE INDEX IF NOT EXISTS idx_memberships_user_status ON organisation_memberships(user_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_memberships_organisation_status ON organisation_memberships(organisation_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_registration_status_submitted ON registration_applications(status, submitted_at)`,
  `CREATE INDEX IF NOT EXISTS idx_registration_identifiers ON registration_applications(vat_number, tin)`,
  `CREATE INDEX IF NOT EXISTS idx_registration_verification_application ON registration_verifications(registration_application_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_user_invitations_org_email_status ON user_invitations(organisation_id, email, status)`,
  `CREATE INDEX IF NOT EXISTS idx_vat_rules_lookup ON vat_rules(tax_category, country, status, effective_from)`,
  `CREATE INDEX IF NOT EXISTS idx_vat_transactions_invoice ON vat_transactions(invoice_id)`,
  `CREATE INDEX IF NOT EXISTS idx_reconciliation_exceptions_queue ON reconciliation_exceptions(status, severity, created_at)`,
  `CREATE INDEX IF NOT EXISTS idx_reconciliation_exceptions_officer ON reconciliation_exceptions(assigned_officer_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_case_transitions_case ON audit_case_transitions(audit_case_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_evidence_case ON audit_evidence(audit_case_id, added_at)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_evidence_custody_events_evidence ON audit_evidence_custody_events(audit_evidence_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_case_notes_case ON audit_case_notes(audit_case_id, created_at)`,
  // Partial unique index (not an inline table constraint): only one PRESERVED
  // evidence row may exist per (case, source resource) at a time — a
  // superseded historical row keeps its place in the table without blocking
  // its replacement. This is the immutable-versioning guarantee Module 4
  // Phase D requires: corrections supersede, never overwrite.
  `CREATE UNIQUE INDEX IF NOT EXISTS idx_audit_evidence_active_source ON audit_evidence(audit_case_id, source_resource_type, source_resource_id) WHERE status='PRESERVED'`,

  // Statutory VAT rate catalogue (Module 2 Phase A). Unlike the pilot demo
  // seed below, this is real reference data — the actual Namibian VAT
  // rates this system must enforce — so it runs unconditionally in every
  // environment, not just non-production. 'SYSTEM_BOOTSTRAP' marks these as
  // pre-approved at deployment rather than through the ProposeVatRule/
  // ApproveVatRule workflow (matches this codebase's existing pattern for
  // system-originated rows, e.g. organisation_administrators' 'SYSTEM_LICENSE_ACTIVATION').
  // OTHER is deliberately left with no approved rule: it is a catch-all with
  // no real statutory rate, so any invoice line categorized OTHER correctly
  // fails closed rather than silently falling back to some default.
  `INSERT OR IGNORE INTO vat_rules (id,tax_category,country,rate_bps,status,version,effective_from,effective_to,proposed_by,proposed_at,approved_by,approved_at,approval_reason,proposal_reason,superseded_by)
    VALUES ('vrule-standard-na','STANDARD','NA',1500,'APPROVED',1,'2026-01-01',NULL,'SYSTEM_BOOTSTRAP','2026-01-01T00:00:00Z','SYSTEM_BOOTSTRAP','2026-01-01T00:00:00Z','Deployment bootstrap of the current statutory rate.','Namibia standard VAT rate.',NULL)`,
  `INSERT OR IGNORE INTO vat_rules (id,tax_category,country,rate_bps,status,version,effective_from,effective_to,proposed_by,proposed_at,approved_by,approved_at,approval_reason,proposal_reason,superseded_by)
    VALUES ('vrule-zero_rated-na','ZERO_RATED','NA',0,'APPROVED',1,'2026-01-01',NULL,'SYSTEM_BOOTSTRAP','2026-01-01T00:00:00Z','SYSTEM_BOOTSTRAP','2026-01-01T00:00:00Z','Deployment bootstrap of the current statutory rate.','Zero-rated supplies.',NULL)`,
  `INSERT OR IGNORE INTO vat_rules (id,tax_category,country,rate_bps,status,version,effective_from,effective_to,proposed_by,proposed_at,approved_by,approved_at,approval_reason,proposal_reason,superseded_by)
    VALUES ('vrule-exempt-na','EXEMPT','NA',0,'APPROVED',1,'2026-01-01',NULL,'SYSTEM_BOOTSTRAP','2026-01-01T00:00:00Z','SYSTEM_BOOTSTRAP','2026-01-01T00:00:00Z','Deployment bootstrap of the current statutory rate.','Exempt supplies.',NULL)`,
  `INSERT OR IGNORE INTO vat_rules (id,tax_category,country,rate_bps,status,version,effective_from,effective_to,proposed_by,proposed_at,approved_by,approved_at,approval_reason,proposal_reason,superseded_by)
    VALUES ('vrule-outside_scope-na','OUTSIDE_SCOPE','NA',0,'APPROVED',1,'2026-01-01',NULL,'SYSTEM_BOOTSTRAP','2026-01-01T00:00:00Z','SYSTEM_BOOTSTRAP','2026-01-01T00:00:00Z','Deployment bootstrap of the current statutory rate.','Outside-scope (non-supply) transactions.',NULL)`,
  `INSERT OR IGNORE INTO vat_rules (id,tax_category,country,rate_bps,status,version,effective_from,effective_to,proposed_by,proposed_at,approved_by,approved_at,approval_reason,proposal_reason,superseded_by)
    VALUES ('vrule-reverse_charge-na','REVERSE_CHARGE','NA',1500,'APPROVED',1,'2026-01-01',NULL,'SYSTEM_BOOTSTRAP','2026-01-01T00:00:00Z','SYSTEM_BOOTSTRAP','2026-01-01T00:00:00Z','Deployment bootstrap of the current statutory rate.','Reverse-charge supplies (standard rate, liability shifted to the recipient).',NULL)`,
  `CREATE INDEX IF NOT EXISTS idx_business_parties_name ON business_parties(organisation_id, display_name)`,
  `CREATE INDEX IF NOT EXISTS idx_quotations_status_date ON quotations(organisation_id, status, issue_date)`,
  `CREATE INDEX IF NOT EXISTS idx_quotation_revisions_organisation ON quotation_revisions(organisation_id, quotation_id, created_at)`,
  `CREATE INDEX IF NOT EXISTS idx_journals_status_date ON journal_entries(organisation_id, status, journal_date)`,
  `CREATE INDEX IF NOT EXISTS idx_journal_lines_account ON journal_lines(account_id, journal_entry_id)`,
  `CREATE INDEX IF NOT EXISTS idx_accounting_periods_org_status ON accounting_periods(organisation_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_expenses_org_status ON expenses(organisation_id, status, expense_date)`,
  `CREATE INDEX IF NOT EXISTS idx_project_costs_project ON project_costs(project_id, cost_type)`,
  `CREATE INDEX IF NOT EXISTS idx_party_verification_snapshots_party ON party_verification_snapshots(party_id, verified_at)`,
  `CREATE INDEX IF NOT EXISTS idx_expenses_status_date ON expenses(organisation_id, status, expense_date)`,
  `CREATE INDEX IF NOT EXISTS idx_stock_movement_product_time ON stock_movements(warehouse_id, product_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_documents_owner ON document_metadata(organisation_id, owner_domain, owner_resource_id)`,
  `CREATE INDEX IF NOT EXISTS idx_invoice_correction_original ON invoice_corrections(original_invoice_id, created_at)`,
  `CREATE INDEX IF NOT EXISTS idx_vat_period_status_due ON vat_periods(status, due_date)`,
  `CREATE INDEX IF NOT EXISTS idx_vat_adjustments_period_status ON vat_adjustments(vat_period_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_reconciliation_period_status ON reconciliation_matches(vat_period_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_vat_return_status_generated ON vat_return_versions(status, generated_at)`,
  `CREATE INDEX IF NOT EXISTS idx_approval_queue ON approval_tasks(status, assigned_role, requested_at)`,
  `CREATE INDEX IF NOT EXISTS idx_vat_return_submission_status ON vat_return_submissions(status, requested_at)`,
  `CREATE INDEX IF NOT EXISTS idx_consent_taxpayer_status ON consent_grants(taxpayer_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_delegations_delegate_status ON delegations(delegate_user_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_communications_taxpayer_time ON communications(taxpayer_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_communications_thread ON communications(thread_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_communication_threads_taxpayer_status ON communication_threads(taxpayer_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_notifications_recipient_status ON notifications(user_id, taxpayer_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_cases_status_risk ON audit_cases(status, risk_tier, updated_at)`,
  `CREATE INDEX IF NOT EXISTS idx_disputes_status_filed ON disputes(status, filed_at)`,
  `CREATE INDEX IF NOT EXISTS idx_risk_taxpayer_status ON risk_indicators(taxpayer_id, status, severity)`,
  `CREATE INDEX IF NOT EXISTS idx_refund_claim_status_risk ON refund_claims(status, risk_tier, requested_at)`,
  `CREATE INDEX IF NOT EXISTS idx_refund_claim_transitions_claim ON refund_claim_transitions(refund_claim_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_refund_claim_checks_claim ON refund_claim_checks(refund_claim_id, evaluated_at)`,
  `CREATE INDEX IF NOT EXISTS idx_sync_jobs_status_requested ON sync_jobs(status, requested_at)`,
  `CREATE INDEX IF NOT EXISTS idx_bank_import_status_created ON bank_imports(organisation_id, status, created_at)`,
  `CREATE INDEX IF NOT EXISTS idx_offline_conflicts_status ON offline_conflicts(status, created_at)`,
  `CREATE INDEX IF NOT EXISTS idx_report_runs_status_requested ON report_runs(status, requested_at)`,
  `CREATE INDEX IF NOT EXISTS idx_report_exports_run_status ON report_exports(report_run_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_analytics_model_runs_product ON analytics_model_runs(data_product_id, requested_at)`,
  `CREATE INDEX IF NOT EXISTS idx_data_product_snapshots_product_published ON data_product_snapshots(data_product_id, published_at)`,
  `CREATE INDEX IF NOT EXISTS idx_metrics_product ON metrics(data_product_id)`,
  `CREATE INDEX IF NOT EXISTS idx_anomaly_candidates_snapshot ON analytics_anomaly_candidates(data_product_snapshot_id)`,
  `CREATE INDEX IF NOT EXISTS idx_change_requests_status ON change_requests(status, requested_at)`,
  `CREATE INDEX IF NOT EXISTS idx_change_requests_target ON change_requests(target_type, target_id)`,
  `CREATE INDEX IF NOT EXISTS idx_security_events_type_actor_occurred ON security_events(event_type, actor_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_security_events_type_source_occurred ON security_events(event_type, source_token, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_security_incidents_status_severity ON security_incidents(status, severity)`,
  `CREATE INDEX IF NOT EXISTS idx_security_incidents_rule_group ON security_incidents(detection_rule_id, group_key, status)`,
  `CREATE INDEX IF NOT EXISTS idx_security_playbook_actions_incident ON security_playbook_actions(incident_id, performed_at)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_events_resource ON audit_events(resource_type, resource_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_events_actor ON audit_events(actor_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_chain_verifications_started ON audit_chain_verifications(started_at)`,
  `CREATE INDEX IF NOT EXISTS idx_subscription_org_status ON subscriptions(organisation_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_organisation_license_effective ON organisation_licenses(organisation_id, state, effective_from)`,
  `CREATE INDEX IF NOT EXISTS idx_license_events_org_time ON license_events(organisation_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_departments_org_status ON departments(organisation_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_employees_org_status_name ON employees(organisation_id, status, full_name)`,
  `CREATE INDEX IF NOT EXISTS idx_org_admins_org_status ON organisation_administrators(organisation_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_org_roles_status ON organisation_roles(organisation_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_user_roles_subject_effective ON user_role_assignments(organisation_id, user_id, status, effective_from)`,
  `CREATE INDEX IF NOT EXISTS idx_workflows_org_status ON workflows(organisation_id, status)`,
  `CREATE INDEX IF NOT EXISTS idx_workflow_versions_effective ON workflow_versions(organisation_id, status, effective_from)`,
  `CREATE INDEX IF NOT EXISTS idx_workflow_instances_resource ON workflow_instances(organisation_id, resource_type, resource_id)`,
  `CREATE INDEX IF NOT EXISTS idx_workflow_assignments_queue ON workflow_assignments(assigned_user_id, status, due_at)`,
  `CREATE INDEX IF NOT EXISTS idx_workflow_delegations_effective ON workflow_delegations(organisation_id, delegate_user_id, status, effective_from)`,
  `CREATE INDEX IF NOT EXISTS idx_access_requests_org_status ON access_requests(organisation_id, status, requested_at)`,
  `CREATE INDEX IF NOT EXISTS idx_access_reviews_org_status ON access_reviews(organisation_id, status, due_at)`,
  `CREATE INDEX IF NOT EXISTS idx_sod_violations_org_status ON sod_violations(organisation_id, status, detected_at)`,
  `CREATE INDEX IF NOT EXISTS idx_navigation_folders_parent ON navigation_folders(workspace_id, parent_folder_id, sort_order)`,
  `CREATE INDEX IF NOT EXISTS idx_navigation_items_folder ON navigation_items(folder_id, sort_order)`,
  `CREATE TRIGGER IF NOT EXISTS prevent_quotation_revision_update
    BEFORE UPDATE ON quotation_revisions
    BEGIN
      SELECT RAISE(ABORT,'QUOTATION_REVISION_IMMUTABLE');
    END`,
  `CREATE TRIGGER IF NOT EXISTS prevent_quotation_revision_delete
    BEFORE DELETE ON quotation_revisions
    BEGIN
      SELECT RAISE(ABORT,'QUOTATION_REVISION_IMMUTABLE');
    END`,
  `CREATE TRIGGER IF NOT EXISTS enforce_employee_seat_limit_insert
    BEFORE INSERT ON employees WHEN NEW.status IN ('ACTIVE','INVITED')
    BEGIN
      SELECT CASE WHEN
        (SELECT COUNT(*) FROM employees e WHERE e.organisation_id=NEW.organisation_id AND e.status IN ('ACTIVE','INVITED')) >=
        COALESCE((SELECT pe.limit_value FROM organisation_licenses ol
          JOIN license_plan_entitlements pe ON pe.license_plan_id=ol.license_plan_id AND pe.feature_key='USER_SEATS' AND pe.enabled=1
          WHERE ol.organisation_id=NEW.organisation_id ORDER BY ol.effective_from DESC LIMIT 1),0)
        THEN RAISE(ABORT,'USER_SEAT_LIMIT_EXCEEDED') END;
    END`,
  `CREATE TRIGGER IF NOT EXISTS enforce_employee_seat_limit_update
    BEFORE UPDATE OF status,organisation_id ON employees
    WHEN NEW.status IN ('ACTIVE','INVITED') AND OLD.status NOT IN ('ACTIVE','INVITED')
    BEGIN
      SELECT CASE WHEN
        (SELECT COUNT(*) FROM employees e WHERE e.organisation_id=NEW.organisation_id AND e.status IN ('ACTIVE','INVITED')) >=
        COALESCE((SELECT pe.limit_value FROM organisation_licenses ol
          JOIN license_plan_entitlements pe ON pe.license_plan_id=ol.license_plan_id AND pe.feature_key='USER_SEATS' AND pe.enabled=1
          WHERE ol.organisation_id=NEW.organisation_id ORDER BY ol.effective_from DESC LIMIT 1),0)
        THEN RAISE(ABORT,'USER_SEAT_LIMIT_EXCEEDED') END;
    END`,
];

const SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO taxpayers VALUES ('tp-0001','VAT1000123','TIN-1000123','Namib Office Supplies (Pty) Ltd','Namib Office','PRIVATE_COMPANY','ACTIVE','BIMONTHLY','12 Independence Avenue, Windhoek','finance@namiboffice.example','2026-01-12T08:00:00Z')`,
  `INSERT OR IGNORE INTO taxpayers VALUES ('tp-0002','VAT1000456','TIN-1000456','Desert Logistics CC','Desert Logistics','CLOSE_CORPORATION','ACTIVE','MONTHLY','44 Sam Nujoma Drive, Walvis Bay','accounts@desertlogistics.example','2026-02-04T09:30:00Z')`,
  `INSERT OR IGNORE INTO taxpayers VALUES ('tp-0003','VAT1000789','TIN-1000789','Atlantic Retail Group (Pty) Ltd','Atlantic Retail','PRIVATE_COMPANY','ACTIVE','MONTHLY','8 Theo-Ben Gurirab Street, Swakopmund','tax@atlanticretail.example','2026-02-18T11:15:00Z')`,
  `INSERT OR IGNORE INTO taxpayers VALUES ('tp-0004','VAT1000987','TIN-1000987','Kalahari Consulting (Pty) Ltd','Kalahari Consulting','PRIVATE_COMPANY','ACTIVE','BIMONTHLY','19 Robert Mugabe Avenue, Windhoek','admin@kalahariconsulting.example','2026-03-01T07:45:00Z')`,
  `INSERT OR IGNORE INTO app_users VALUES ('usr-local-admin','local-demo-user','admin@vat-msa.local','Pilot Administrator','PILOT_ADMIN',NULL,'ACTIVE','2026-08-01T08:00:00Z')`,

  `INSERT OR IGNORE INTO invoices VALUES ('inv-0001','INV-2026-0182','TAX_INVOICE','ERP-NAMIB-01','ERP-182','tp-0001','Namib Office Supplies (Pty) Ltd','VAT1000123','tp-0003','Atlantic Retail Group (Pty) Ltd','VAT1000789','2026-08-08','NAD',11450000,1717500,13167500,'MATCHED','LOW','13a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','txn-0001','cert-0001','vfy_1a92c57e41f84b89a601d982be634a81','2026-08-08T08:12:44Z','2026-08-08T08:12:45Z')`,
  `INSERT OR IGNORE INTO invoices VALUES ('inv-0002','DL-8842','TAX_INVOICE','API-DL-01','DL-8842','tp-0002','Desert Logistics CC','VAT1000456','tp-0001','Namib Office Supplies (Pty) Ltd','VAT1000123','2026-08-07','NAD',5200000,780000,5980000,'MATCHED','LOW','23a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','txn-0002','cert-0002','vfy_2b13d68f52a94c90b712e093cf745b92','2026-08-07T14:21:19Z','2026-08-07T14:21:20Z')`,
  `INSERT OR IGNORE INTO invoices VALUES ('inv-0003','AR-7719','SIMPLIFIED_TAX_INVOICE','POS-ATL-22','POS-7719','tp-0003','Atlantic Retail Group (Pty) Ltd','VAT1000789',NULL,'Walk-in customer',NULL,'2026-08-07','NAD',850000,127500,977500,'CERTIFIED','LOW','33a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','txn-0003','cert-0003','vfy_3c24e79a63ba4da1c823f1a4d0856ca3','2026-08-07T12:04:03Z','2026-08-07T12:04:04Z')`,
  `INSERT OR IGNORE INTO invoices VALUES ('inv-0004','KC-1041','TAX_INVOICE','PORTAL','PORTAL-KC-1041','tp-0004','Kalahari Consulting (Pty) Ltd','VAT1000987','tp-0001','Namib Office Supplies (Pty) Ltd','VAT1000123','2026-08-06','NAD',120000000,18000000,138000000,'EXCEPTION','CRITICAL','43a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','txn-0004','cert-0004','vfy_4d35f80b74cb4eb2d93402b5e1967db4','2026-08-06T09:32:10Z','2026-08-06T09:32:11Z')`,

  `INSERT OR IGNORE INTO invoice_lines VALUES ('line-0001','inv-0001',1,'Office equipment and consumables','1','EA',11450000,11450000,1500,'STANDARD',1717500,'vrule-standard-na')`,
  `INSERT OR IGNORE INTO invoice_lines VALUES ('line-0002','inv-0002',1,'Regional freight services','1','EA',5200000,5200000,1500,'STANDARD',780000,'vrule-standard-na')`,
  `INSERT OR IGNORE INTO invoice_lines VALUES ('line-0003','inv-0003',1,'Retail merchandise','1','EA',850000,850000,1500,'STANDARD',127500,'vrule-standard-na')`,
  `INSERT OR IGNORE INTO invoice_lines VALUES ('line-0004','inv-0004',1,'Enterprise transformation advisory','1','EA',120000000,120000000,1500,'STANDARD',18000000,'vrule-standard-na')`,

  `INSERT OR IGNORE INTO certificates VALUES ('cert-0001','inv-0001','vfy_1a92c57e41f84b89a601d982be634a81','13a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','DEV.13a5e7b5d4c8f1a0','DEV-SHA256','VALID','2026-08-08T08:12:45Z')`,
  `INSERT OR IGNORE INTO certificates VALUES ('cert-0002','inv-0002','vfy_2b13d68f52a94c90b712e093cf745b92','23a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','DEV.23a5e7b5d4c8f1a0','DEV-SHA256','VALID','2026-08-07T14:21:20Z')`,
  `INSERT OR IGNORE INTO certificates VALUES ('cert-0003','inv-0003','vfy_3c24e79a63ba4da1c823f1a4d0856ca3','33a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','DEV.33a5e7b5d4c8f1a0','DEV-SHA256','VALID','2026-08-07T12:04:04Z')`,
  `INSERT OR IGNORE INTO certificates VALUES ('cert-0004','inv-0004','vfy_4d35f80b74cb4eb2d93402b5e1967db4','43a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','DEV.43a5e7b5d4c8f1a0','DEV-SHA256','VALID','2026-08-06T09:32:11Z')`,

  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0001a','txn-0001','inv-0001','tp-0001','OUTPUT_VAT','CREDIT',1717500,'2026-08','2026-08-08T08:12:45Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0001b','txn-0001','inv-0001','tp-0003','INPUT_VAT','DEBIT',1717500,'2026-08','2026-08-08T08:12:45Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0002a','txn-0002','inv-0002','tp-0002','OUTPUT_VAT','CREDIT',780000,'2026-08','2026-08-07T14:21:20Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0002b','txn-0002','inv-0002','tp-0001','INPUT_VAT','DEBIT',780000,'2026-08','2026-08-07T14:21:20Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0003a','txn-0003','inv-0003','tp-0003','OUTPUT_VAT','CREDIT',127500,'2026-08','2026-08-07T12:04:04Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0004a','txn-0004','inv-0004','tp-0004','OUTPUT_VAT','CREDIT',18000000,'2026-08','2026-08-06T09:32:11Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0004b','txn-0004','inv-0004','tp-0001','INPUT_VAT','DEBIT',18000000,'2026-08','2026-08-06T09:32:11Z')`,

  `INSERT OR IGNORE INTO reconciliation_exceptions VALUES ('exc-0001','inv-0004','tp-0004','HIGH_VALUE_TRANSACTION','CRITICAL','OPEN','Transaction value exceeds the pilot high-value threshold and requires officer review.','2026-08-06T09:32:11Z',NULL,NULL,NULL,NULL)`,
  `INSERT OR IGNORE INTO reconciliation_exceptions VALUES ('exc-0002','inv-0003','tp-0003','UNREGISTERED_BUYER','MEDIUM','OPEN','Buyer does not have a VAT registration in the pilot registry; input VAT was not posted.','2026-08-07T12:04:04Z',NULL,NULL,NULL,NULL)`,


  `INSERT OR IGNORE INTO audit_events VALUES ('aud-0001','system','SYSTEM','INVOICE_CERTIFIED','INVOICE','inv-0001','SUCCESS','{"invoice_number":"INV-2026-0182","transaction_id":"txn-0001"}',NULL,'a000000000000000000000000000000000000000000000000000000000000001','2026-08-08T08:12:45Z')`,
  `INSERT OR IGNORE INTO audit_events VALUES ('aud-0002','system','SYSTEM','RECONCILIATION_EXCEPTION_OPENED','EXCEPTION','exc-0001','SUCCESS','{"severity":"CRITICAL","invoice_id":"inv-0004"}','a000000000000000000000000000000000000000000000000000000000000001','a000000000000000000000000000000000000000000000000000000000000002','2026-08-06T09:32:11Z')`,
  `INSERT OR IGNORE INTO seed_state VALUES ('pilot-v1','2026-08-09T00:00:00Z')`,
];

const SECURITY_SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO security_events VALUES ('sec-0001','API_RATE_ANOMALY','MEDIUM','usr-local-admin','src:pilot','a1000000-0000-4000-8000-000000000001','INVOICE_SUBMISSION','THROTTLED','{"bucket":"actor","threshold":120}','2026-08-09T06:45:00Z')`,
  `INSERT OR IGNORE INTO security_events VALUES ('sec-0002','AUTHORISATION_DENIED','HIGH','unknown','src:external','a1000000-0000-4000-8000-000000000002','INVOICE_READ','DENIED','{"reason":"taxpayer_scope_mismatch"}','2026-08-09T07:10:00Z')`,
  `INSERT OR IGNORE INTO security_events VALUES ('sec-0003','PAYLOAD_REJECTED','LOW','usr-local-admin','src:pilot','a1000000-0000-4000-8000-000000000003','INVOICE_SUBMISSION','REJECTED','{"reason":"payload_limit"}','2026-08-09T07:18:00Z')`,
  `INSERT OR IGNORE INTO security_detection_rules
    (id,code,name,description,event_type,group_by,threshold_count,window_minutes,severity,status,created_at)
    VALUES ('secrule-repeated-denials','REPEATED_AUTHORISATION_DENIALS','Repeated authorisation denials','Opens an incident when the same actor accumulates repeated access-denied events in a short window.','AUTHORISATION_DENIED','actor_id',5,15,'HIGH','ACTIVE','2026-08-09T08:00:00Z')`,
  `INSERT OR IGNORE INTO security_detection_rules
    (id,code,name,description,event_type,group_by,threshold_count,window_minutes,severity,status,created_at)
    VALUES ('secrule-rate-limit-abuse','RATE_LIMIT_ABUSE','Rate limit abuse','Opens an incident when the same source repeatedly trips a rate limit in a short window.','RATE_LIMIT_EXCEEDED','source_token',10,10,'MEDIUM','ACTIVE','2026-08-09T08:00:00Z')`,
  `INSERT OR IGNORE INTO security_detection_rules
    (id,code,name,description,event_type,group_by,threshold_count,window_minutes,severity,status,created_at)
    VALUES ('secrule-audit-chain-breach','AUDIT_CHAIN_INTEGRITY_BREACH','Audit chain integrity breach','Opens a CRITICAL incident the moment a chain-verification run finds a broken or tampered audit_events hash chain.','AUDIT_CHAIN_BREAK','actor_id',1,1440,'CRITICAL','ACTIVE','2026-08-09T08:00:00Z')`,
  `INSERT OR IGNORE INTO security_incidents
    (id,title,severity,status,source_event_id,automated_action,owner,detection_rule_id,group_key,subject_user_id,opened_at,updated_at,closed_at,closed_by,resolution_notes)
    VALUES ('inc-0001','Repeated cross-taxpayer access attempts','HIGH','CONTAINED','sec-0002','SESSION_CHALLENGE','SOC Tier 2',NULL,NULL,NULL,'2026-08-09T07:11:00Z','2026-08-09T07:20:00Z',NULL,NULL,NULL)`,
  `INSERT OR IGNORE INTO outbox_events VALUES ('out-0001','INVOICE','inv-0001','InvoiceCertified',1,'tp-0001','{"invoice_id":"inv-0001","transaction_id":"txn-0001"}','PUBLISHED',1,'2026-08-08T08:12:45Z','2026-08-08T08:12:45Z','2026-08-08T08:12:46Z',NULL)`,
  `INSERT OR IGNORE INTO seed_state VALUES ('security-v1','2026-08-09T08:00:00Z')`,
];

const IDENTITY_SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO taxpayer_identifiers
    (id,taxpayer_id,identifier_type,identifier_value,country,status,source,verified_at,created_at)
    SELECT 'tid-vat-' || id,id,'VAT_NUMBER',vat_number,'NA','ACTIVE','PILOT_MIGRATION',created_at,'2026-08-09T09:00:00Z' FROM taxpayers`,
  `INSERT OR IGNORE INTO taxpayer_identifiers
    (id,taxpayer_id,identifier_type,identifier_value,country,status,source,verified_at,created_at)
    SELECT 'tid-tin-' || id,id,'TIN',tin,'NA','ACTIVE','PILOT_MIGRATION',created_at,'2026-08-09T09:00:00Z' FROM taxpayers`,
  `INSERT OR IGNORE INTO identity_providers
    (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
    VALUES ('idp-sites-workspace','SITES_WORKSPACE','Workspace authenticated identity','PLATFORM','AUTHENTICATION',NULL,'ACTIVE','CONFIGURED','2026-08-09T09:00:00Z','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO identity_providers
    (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
    VALUES ('idp-itas','ITAS','ITAS identity provider','GOVERNMENT','PREFERRED_AUTHORITATIVE',NULL,'PENDING','REQUIRES_ITAS_CONFIRMATION','2026-08-09T09:00:00Z','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO identity_providers
    (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
    VALUES ('idp-standalone','VAT_MSA_STANDALONE','VAT-MSA standalone identity','MANAGED_EXTERNAL','CONTINUITY',NULL,'PENDING','REQUIRES_SECURITY_DECISION','2026-08-09T09:00:00Z','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO app_users
    (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at)
    VALUES ('usr-tp1-owner','demo-tp1-owner','owner@namiboffice.example','Namib Office Owner','TAXPAYER_OWNER','tp-0001','ACTIVE','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO identity_links
    (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
    SELECT 'ilink-migrated-' || id,id,'idp-sites-workspace',external_user_id,email,'PILOT_MIGRATED','ACTIVE','2026-08-09T09:00:00Z',NULL
    FROM app_users WHERE external_user_id IS NOT NULL AND external_user_id <> ''`,
  `INSERT OR IGNORE INTO identity_links
    (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
    VALUES ('ilink-local-admin','usr-local-admin','idp-sites-workspace','local-demo-user','admin@vat-msa.local','DEVELOPMENT','ACTIVE','2026-08-09T09:00:00Z','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO identity_links
    (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
    VALUES ('ilink-tp1-owner','usr-tp1-owner','idp-sites-workspace','demo-tp1-owner','owner@namiboffice.example','PILOT','ACTIVE','2026-08-09T09:00:00Z',NULL)`,

  `INSERT OR IGNORE INTO access_roles VALUES ('PILOT_ADMIN','Pilot Administrator','PLATFORM','CRITICAL','ACTIVE','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('TAXPAYER_OWNER','Taxpayer Owner','TAXPAYER','HIGH','ACTIVE','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('TAXPAYER_ADMIN','Taxpayer Administrator','TAXPAYER','HIGH','ACTIVE','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('TAXPAYER_ACCOUNTANT','Taxpayer Accountant','TAXPAYER','MEDIUM','ACTIVE','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('TAXPAYER_STAFF','Taxpayer Staff','TAXPAYER','MEDIUM','ACTIVE','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('TAXPAYER_VIEWER','Taxpayer Viewer','TAXPAYER','LOW','ACTIVE','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('NAMRA_COMPLIANCE_OFFICER','NamRA Compliance Officer','NAMRA','HIGH','ACTIVE','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('NAMRA_AUDITOR','NamRA Auditor','NAMRA','HIGH','ACTIVE','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('INTERNAL_AUDITOR','Internal Auditor','ASSURANCE','HIGH','ACTIVE','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('SECURITY_ANALYST','Security Analyst','SECURITY','HIGH','ACTIVE','2026-08-09T09:00:00Z')`,

  `INSERT OR IGNORE INTO access_permissions VALUES ('identity:read','IDENTITY','READ','Read the identity foundation posture','RESTRICTED','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('taxpayers:read','TAXPAYER','READ','Read authorised taxpayer records','RESTRICTED','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('registrations:read','REGISTRATION','READ','Read authorised registration applications','RESTRICTED','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('registrations:submit','REGISTRATION','SUBMIT','Submit a registration application','RESTRICTED','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('organisations:manage','ORGANISATION','MANAGE','Manage organisation membership and branches','RESTRICTED','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('invoices:read','INVOICE','READ','Read authorised invoices','RESTRICTED','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('invoices:submit','INVOICE','SUBMIT','Submit invoices for certification','RESTRICTED','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('returns:read','VAT_RETURN','READ','Read authorised VAT returns','RESTRICTED','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('audit:read','AUDIT','READ','Read authorised audit evidence','RESTRICTED','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('security:read','SECURITY','READ','Read security operations posture','SECURITY','2026-08-09T09:00:00Z')`,

  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-pa-id','PILOT_ADMIN','identity:read','ALLOW','{"scope":"national-pilot"}','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-pa-tr','PILOT_ADMIN','taxpayers:read','ALLOW','{"scope":"national-pilot"}','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-pa-rr','PILOT_ADMIN','registrations:read','ALLOW','{"scope":"national-pilot"}','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-pa-rs','PILOT_ADMIN','registrations:submit','ALLOW','{"scope":"authenticated"}','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-pa-om','PILOT_ADMIN','organisations:manage','ALLOW','{"scope":"national-pilot"}','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-id','TAXPAYER_OWNER','identity:read','ALLOW','{"scope":"own-organisation"}','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-rs','TAXPAYER_OWNER','registrations:submit','ALLOW','{"scope":"own-application"}','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-om','TAXPAYER_OWNER','organisations:manage','ALLOW','{"scope":"own-organisation"}','2026-08-09T09:00:00Z')`,

  `INSERT OR IGNORE INTO organisations
    (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at)
    SELECT 'org-0001',id,legal_name,trading_name,'ACTIVE','2026-08-09T09:00:00Z','2026-08-09T09:00:00Z' FROM taxpayers WHERE id='tp-0001'`,
  `INSERT OR IGNORE INTO organisations
    (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at)
    SELECT 'org-0002',id,legal_name,trading_name,'ACTIVE','2026-08-09T09:00:00Z','2026-08-09T09:00:00Z' FROM taxpayers WHERE id='tp-0002'`,
  `INSERT OR IGNORE INTO organisations
    (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at)
    SELECT 'org-0003',id,legal_name,trading_name,'ACTIVE','2026-08-09T09:00:00Z','2026-08-09T09:00:00Z' FROM taxpayers WHERE id='tp-0003'`,
  `INSERT OR IGNORE INTO organisations
    (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at)
    SELECT 'org-0004',id,legal_name,trading_name,'ACTIVE','2026-08-09T09:00:00Z','2026-08-09T09:00:00Z' FROM taxpayers WHERE id='tp-0004'`,
  `INSERT OR IGNORE INTO branches VALUES ('br-0001','org-0001','HEAD','Windhoek Head Office','12 Independence Avenue, Windhoek','ACTIVE',1,'2026-08-09T09:00:00Z','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO branches VALUES ('br-0002','org-0002','HEAD','Walvis Bay Head Office','44 Sam Nujoma Drive, Walvis Bay','ACTIVE',1,'2026-08-09T09:00:00Z','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO branches VALUES ('br-0003','org-0003','HEAD','Swakopmund Head Office','8 Theo-Ben Gurirab Street, Swakopmund','ACTIVE',1,'2026-08-09T09:00:00Z','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO branches VALUES ('br-0004','org-0004','HEAD','Windhoek Head Office','19 Robert Mugabe Avenue, Windhoek','ACTIVE',1,'2026-08-09T09:00:00Z','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_memberships
    (id,organisation_id,user_id,role_code,branch_id,status,valid_from,valid_to,assigned_by,created_at)
    VALUES ('mem-0001','org-0001','usr-tp1-owner','TAXPAYER_OWNER',NULL,'ACTIVE','2026-08-09T09:00:00Z',NULL,'usr-local-admin','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_capabilities VALUES ('cap-0001-b','org-0001','BUYER','ACTIVE','2026-08-09T09:00:00Z',NULL,'system-migration','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_capabilities VALUES ('cap-0001-s','org-0001','SELLER','ACTIVE','2026-08-09T09:00:00Z',NULL,'system-migration','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_capabilities VALUES ('cap-0002-b','org-0002','BUYER','ACTIVE','2026-08-09T09:00:00Z',NULL,'system-migration','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_capabilities VALUES ('cap-0002-s','org-0002','SELLER','ACTIVE','2026-08-09T09:00:00Z',NULL,'system-migration','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_capabilities VALUES ('cap-0003-b','org-0003','BUYER','ACTIVE','2026-08-09T09:00:00Z',NULL,'system-migration','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_capabilities VALUES ('cap-0003-s','org-0003','SELLER','ACTIVE','2026-08-09T09:00:00Z',NULL,'system-migration','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_capabilities VALUES ('cap-0004-b','org-0004','BUYER','ACTIVE','2026-08-09T09:00:00Z',NULL,'system-migration','2026-08-09T09:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_capabilities VALUES ('cap-0004-s','org-0004','SELLER','ACTIVE','2026-08-09T09:00:00Z',NULL,'system-migration','2026-08-09T09:00:00Z')`,

  `INSERT OR IGNORE INTO registration_applications
    (id,idempotency_key,request_hash,vat_number,tin,company_registration_number,legal_name,trading_name,taxpayer_type,return_frequency,address,email,status,verification_source,submitted_by,submitted_at,reviewed_at,review_reason)
    VALUES ('reg-0001','seed-registration-0001','seed-hash-0001','VAT-PENDING-001','TIN-PENDING-001','BIPA-PENDING-001','Omatako Digital Services (Pty) Ltd','Omatako Digital','PRIVATE_COMPANY','BIMONTHLY','17 Mandume Ndemufayo Avenue, Windhoek','finance@omatako.example','PENDING_VERIFICATION','ITAS','usr-local-admin','2026-08-09T09:15:00Z',NULL,NULL)`,
  `INSERT OR IGNORE INTO registration_verifications
    (id,registration_application_id,provider,request_reference,status,response_hash,verified_taxpayer_id,checked_at,expires_at)
    VALUES ('regv-0001','reg-0001','ITAS','ITAS-CONTRACT-PENDING-reg-0001','AWAITING_PROVIDER_CONTRACT',NULL,NULL,'2026-08-09T09:15:00Z',NULL)`,
  `INSERT OR IGNORE INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES ('out-reg-0001','REGISTRATION','reg-0001','TaxpayerRegistrationSubmitted',1,'VAT-PENDING-001','{"registration_id":"reg-0001","status":"PENDING_VERIFICATION"}','PENDING',0,'2026-08-09T09:15:00Z','2026-08-09T09:15:00Z',NULL,NULL)`,
  `INSERT OR IGNORE INTO seed_state VALUES ('identity-v1','2026-08-09T09:30:00Z')`,
];

const BUSINESS_SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO access_permissions VALUES ('commercial:read','COMMERCIAL','READ','Read customer, supplier, product and quotation records','RESTRICTED','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('parties:manage','BUSINESS_PARTY','MANAGE','Create update and non-destructively deactivate customer and supplier records','CONFIDENTIAL','2026-08-14T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('quotations:manage','QUOTATION','MANAGE','Create and transition authorised quotations','RESTRICTED','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('accounting:read','ACCOUNTING','READ','Read the authorised chart and journals','CONFIDENTIAL','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('accounting:post','ACCOUNTING','POST','Post balanced journals','CONFIDENTIAL','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('expenses:read','EXPENSE','READ','Read authorised expenses','CONFIDENTIAL','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('expenses:manage','EXPENSE','MANAGE','Record and transition authorised expenses','CONFIDENTIAL','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('inventory:read','INVENTORY','READ','Read authorised inventory','RESTRICTED','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('inventory:manage','INVENTORY','MANAGE','Record authorised stock movements','RESTRICTED','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('projects:read','PROJECT','READ','Read authorised projects','RESTRICTED','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('projects:manage','PROJECT','MANAGE','Create and manage authorised projects','RESTRICTED','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('imports:read','IMPORT','READ','Read authorised import declarations','CONFIDENTIAL','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('imports:manage','IMPORT','MANAGE','Record authorised import declarations','CONFIDENTIAL','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('documents:read','DOCUMENT','READ','Read authorised document metadata','CONFIDENTIAL','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('documents:upload','DOCUMENT','UPLOAD','Upload governed evidence into quarantine','CONFIDENTIAL','2026-08-09T10:00:00Z')`,

  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-cr','TAXPAYER_OWNER','commercial:read','ALLOW','{"scope":"own-organisation"}','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-party','TAXPAYER_OWNER','parties:manage','ALLOW','{"scope":"own-organisation"}','2026-08-14T09:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-qm','TAXPAYER_OWNER','quotations:manage','ALLOW','{"scope":"own-organisation"}','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-ar','TAXPAYER_OWNER','accounting:read','ALLOW','{"scope":"own-organisation"}','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-ap','TAXPAYER_OWNER','accounting:post','ALLOW','{"scope":"own-organisation"}','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-em','TAXPAYER_OWNER','expenses:manage','ALLOW','{"scope":"own-organisation"}','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-im','TAXPAYER_OWNER','inventory:manage','ALLOW','{"scope":"own-organisation"}','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-pm','TAXPAYER_OWNER','projects:manage','ALLOW','{"scope":"own-organisation"}','2026-08-09T10:00:00Z')`,

  `INSERT OR IGNORE INTO business_parties
    (id,organisation_id,display_name,legal_name,vat_number,tin,email,phone,address,source_system,source_party_id,status,created_at,updated_at)
    VALUES ('party-0001-customer','org-0001','Atlantic Retail','Atlantic Retail Group (Pty) Ltd','VAT1000789','TIN-1000789','tax@atlanticretail.example','+264 64 000 100','8 Theo-Ben Gurirab Street, Swakopmund','CRM','ATL-001','ACTIVE','2026-08-09T10:00:00Z','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO business_parties
    (id,organisation_id,display_name,legal_name,vat_number,tin,email,phone,address,source_system,source_party_id,status,created_at,updated_at)
    VALUES ('party-0001-supplier','org-0001','Desert Logistics','Desert Logistics CC','VAT1000456','TIN-1000456','accounts@desertlogistics.example','+264 64 000 200','44 Sam Nujoma Drive, Walvis Bay','ERP','DES-001','ACTIVE','2026-08-09T10:00:00Z','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO party_relationships VALUES ('rel-0001-customer','org-0001','party-0001-customer','CUSTOMER','ACTIVE','2026-08-09T10:00:00Z',NULL,'2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO party_relationships VALUES ('rel-0001-supplier','org-0001','party-0001-supplier','SUPPLIER','ACTIVE','2026-08-09T10:00:00Z',NULL,'2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO products
    (id,organisation_id,sku,name,description,unit_code,tax_category,tax_rate_bps,sales_price_cents,cost_price_cents,status,created_at,updated_at)
    VALUES ('prod-0001','org-0001','OFFICE-CHAIR','Ergonomic office chair','Adjustable office chair','EA','STANDARD',1500,459900,290000,'ACTIVE','2026-08-09T10:00:00Z','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO products
    (id,organisation_id,sku,name,description,unit_code,tax_category,tax_rate_bps,sales_price_cents,cost_price_cents,status,created_at,updated_at)
    VALUES ('prod-0002','org-0001','PAPER-A4','A4 paper carton','Five reams per carton','CT','STANDARD',1500,49900,31000,'ACTIVE','2026-08-09T10:00:00Z','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO warehouses
    (id,organisation_id,branch_id,code,name,address,status,created_at)
    VALUES ('wh-0001','org-0001','br-0001','WH-WHK','Windhoek Main Warehouse','12 Independence Avenue, Windhoek','ACTIVE','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO expense_categories
    (id,organisation_id,code,name,default_tax_category,requires_receipt,status,created_at)
    VALUES ('expcat-0001','org-0001','TRAVEL','Travel and accommodation','STANDARD',1,'ACTIVE','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO expense_categories
    (id,organisation_id,code,name,default_tax_category,requires_receipt,status,created_at)
    VALUES ('expcat-0002','org-0001','UTILITIES','Utilities','STANDARD',1,'ACTIVE','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO projects
    (id,organisation_id,code,name,customer_party_id,manager_user_id,currency,start_date,end_date,status,created_at,updated_at)
    VALUES ('prj-0001','org-0001','ATL-FITOUT','Atlantic Retail Fit-out','party-0001-customer','usr-local-admin','NAD','2026-08-01','2026-11-30','ACTIVE','2026-08-09T10:00:00Z','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO project_budgets
    (id,project_id,category,amount_cents,approved_amount_cents,status,approved_by,approved_at,created_at)
    VALUES ('budget-0001','prj-0001','TOTAL',25000000,25000000,'APPROVED','usr-local-admin','2026-08-09T10:00:00Z','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO quotations
    (id,organisation_id,branch_id,customer_party_id,quotation_number,currency,issue_date,valid_until,status,subtotal_cents,tax_cents,total_cents,notes,created_by,approved_by,accepted_at,converted_invoice_id,created_at,updated_at)
    VALUES ('quote-0001','org-0001','br-0001','party-0001-customer','QUO-2026-0001','NAD','2026-08-08','2026-09-07','ISSUED',969700,145455,1115155,'Pilot commercial quotation','usr-local-admin','usr-local-admin',NULL,NULL,'2026-08-09T10:00:00Z','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO quotation_lines
    (id,quotation_id,line_number,product_id,description,quantity_micros,unit_code,unit_price_cents,net_amount_cents,tax_category,tax_rate_bps,tax_amount_cents)
    VALUES ('quote-line-0001','quote-0001',1,'prod-0001','Ergonomic office chair',2000000,'EA',459900,919800,'STANDARD',1500,137970)` ,
  `INSERT OR IGNORE INTO quotation_lines
    (id,quotation_id,line_number,product_id,description,quantity_micros,unit_code,unit_price_cents,net_amount_cents,tax_category,tax_rate_bps,tax_amount_cents)
    VALUES ('quote-line-0002','quote-0001',2,'prod-0002','A4 paper carton',1000000,'CT',49900,49900,'STANDARD',1500,7485)` ,
  `INSERT OR IGNORE INTO chart_of_accounts VALUES ('acct-1000','org-0001','1000','Bank','ASSET','NAD','BANK','ACTIVE','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO chart_of_accounts VALUES ('acct-2000','org-0001','2000','Accounts payable','LIABILITY','NAD','PAYABLE','ACTIVE','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO chart_of_accounts VALUES ('acct-4000','org-0001','4000','Sales revenue','REVENUE','NAD','REVENUE','ACTIVE','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO chart_of_accounts VALUES ('acct-5000','org-0001','5000','Cost of sales','EXPENSE','NAD','COST_OF_SALES','ACTIVE','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO chart_of_accounts VALUES ('acct-5100','org-0001','5100','Travel expense','EXPENSE','NAD','EXPENSE','ACTIVE','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO journal_entries
    (id,organisation_id,journal_number,journal_date,reference,description,currency,status,source_type,source_id,created_by,posted_by,created_at,posted_at)
    VALUES ('journal-0001','org-0001','JRN-2026-0001','2026-08-08','OPENING-001','Pilot opening bank balance','NAD','POSTED','MANUAL',NULL,'usr-local-admin','usr-local-admin','2026-08-09T10:00:00Z','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO journal_lines VALUES ('journal-line-0001','journal-0001',1,'acct-1000','br-0001',NULL,'Opening bank balance',5000000,0,NULL)`,
  `INSERT OR IGNORE INTO journal_lines VALUES ('journal-line-0002','journal-0001',2,'acct-4000','br-0001',NULL,'Opening balance offset',0,5000000,NULL)`,
  `INSERT OR IGNORE INTO expenses
    (id,organisation_id,branch_id,category_id,supplier_party_id,project_id,expense_number,expense_date,description,currency,net_cents,tax_cents,total_cents,status,receipt_document_id,created_by,approved_by,created_at,approved_at)
    VALUES ('expense-0001','org-0001','br-0001','expcat-0001','party-0001-supplier','prj-0001','EXP-2026-0001','2026-08-07','Project delivery transport','NAD',200000,30000,230000,'APPROVED',NULL,'usr-local-admin','usr-local-admin','2026-08-09T10:00:00Z','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO project_costs (id,project_id,cost_type,source_id,amount_cents,currency,occurred_at,created_at)
    VALUES ('project-cost-0001','prj-0001','EXPENSE','expense-0001',230000,'NAD','2026-08-07T12:00:00Z','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO inventory_balances
    (id,organisation_id,warehouse_id,product_id,quantity_micros,average_cost_cents,version,updated_at)
    VALUES ('balance-0001','org-0001','wh-0001','prod-0001',12000000,290000,1,'2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO inventory_balances
    (id,organisation_id,warehouse_id,product_id,quantity_micros,average_cost_cents,version,updated_at)
    VALUES ('balance-0002','org-0001','wh-0001','prod-0002',45000000,31000,1,'2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO stock_movements
    (id,organisation_id,warehouse_id,product_id,movement_type,quantity_micros,unit_cost_cents,reference_type,reference_id,reason,occurred_at,actor_id)
    VALUES ('stock-0001','org-0001','wh-0001','prod-0001','RECEIPT',12000000,290000,'GOODS_RECEIPT','GRN-2026-0001','Opening pilot receipt','2026-08-08T08:00:00Z','usr-local-admin')`,
  `INSERT OR IGNORE INTO stock_movements
    (id,organisation_id,warehouse_id,product_id,movement_type,quantity_micros,unit_cost_cents,reference_type,reference_id,reason,occurred_at,actor_id)
    VALUES ('stock-0002','org-0001','wh-0001','prod-0002','RECEIPT',45000000,31000,'GOODS_RECEIPT','GRN-2026-0002','Opening pilot receipt','2026-08-08T08:05:00Z','usr-local-admin')`,
  `INSERT OR IGNORE INTO import_records
    (id,organisation_id,declaration_number,customs_office,supplier_name,country_of_origin,currency,customs_value_cents,import_vat_cents,declaration_date,evidence_document_id,status,created_by,created_at)
    VALUES ('import-0001','org-0001','NAMCUS-2026-0001','Walvis Bay','Cape Office Manufacturing','ZA','NAD',7500000,1125000,'2026-08-05',NULL,'EVIDENCE_REQUIRED','usr-local-admin','2026-08-09T10:00:00Z')`,
  `INSERT OR IGNORE INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES ('out-quote-0001','QUOTATION','quote-0001','QuotationIssued',1,'org-0001','{"quotation_id":"quote-0001","organisation_id":"org-0001"}','PENDING',0,'2026-08-09T10:00:00Z','2026-08-09T10:00:00Z',NULL,NULL)`,
  `INSERT OR IGNORE INTO seed_state VALUES ('business-v1','2026-08-09T10:00:00Z')`,
];

const VAT_LIFECYCLE_SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO access_permissions VALUES ('returns:generate','VAT_RETURN','GENERATE','Generate a reproducible return version from controlled ledger evidence','CONFIDENTIAL','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('returns:approve','VAT_RETURN','APPROVE','Approve a return version subject to maker-checker separation','CONFIDENTIAL','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('returns:submit','VAT_RETURN','SUBMIT','Request submission of an approved return to the statutory provider','CONFIDENTIAL','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('vat-adjustments:manage','VAT_ADJUSTMENT','MANAGE','Submit governed VAT adjustments for independent approval','CONFIDENTIAL','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('reconciliation:manage','RECONCILIATION','MANAGE','Review and resolve controlled reconciliation evidence','CONFIDENTIAL','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-rg','TAXPAYER_OWNER','returns:generate','ALLOW','{"scope":"own-organisation"}','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-ra','TAXPAYER_OWNER','returns:approve','ALLOW','{"scope":"own-organisation","separation":"maker-checker"}','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-rsb','TAXPAYER_OWNER','returns:submit','ALLOW','{"scope":"own-organisation","requires":"approved"}','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-vam','TAXPAYER_OWNER','vat-adjustments:manage','ALLOW','{"scope":"own-organisation"}','2026-08-09T11:00:00Z')`,

  `INSERT OR IGNORE INTO tax_rule_sets
    (id,jurisdiction,version,effective_from,effective_to,standard_rate_bps,legal_authority_reference,status,approved_by,approved_at,created_at)
    VALUES ('taxrule-na-pilot-2026-1','NA','NA-VAT-PILOT-2026.1','2026-01-01',NULL,1500,NULL,'PILOT_CONTROLLED',NULL,NULL,'2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO tax_box_mappings VALUES ('boxmap-output','taxrule-na-pilot-2026-1','BOX_OUTPUT','Output VAT','OUTPUT_VAT','CREDIT','SUM(eligible output VAT ledger entries)','ACTIVE')`,
  `INSERT OR IGNORE INTO tax_box_mappings VALUES ('boxmap-input','taxrule-na-pilot-2026-1','BOX_INPUT','Eligible input VAT','INPUT_VAT','DEBIT','SUM(matched eligible input VAT ledger entries)','ACTIVE')`,
  `INSERT OR IGNORE INTO tax_box_mappings VALUES ('boxmap-adjust','taxrule-na-pilot-2026-1','BOX_ADJUST','Approved net adjustments','ADJUSTMENT','SIGNED','SUM(approved adjustment effects)','ACTIVE')`,
  `INSERT OR IGNORE INTO tax_box_mappings VALUES ('boxmap-net','taxrule-na-pilot-2026-1','BOX_NET','Net VAT payable or refundable','CALCULATED','SIGNED','BOX_OUTPUT - BOX_INPUT + BOX_ADJUST','ACTIVE')`,

  `INSERT OR IGNORE INTO vat_periods
    (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
    VALUES ('period-0001','org-0001','tp-0001','2026-08','2026-08-01','2026-08-31','2026-09-25','OPEN',0,NULL,NULL,NULL,NULL,'2026-08-09T11:00:00Z','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO vat_periods
    (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
    VALUES ('period-0002','org-0002','tp-0002','2026-08','2026-08-01','2026-08-31','2026-09-25','OPEN',0,NULL,NULL,NULL,NULL,'2026-08-09T11:00:00Z','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO vat_periods
    (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
    VALUES ('period-0003','org-0003','tp-0003','2026-08','2026-08-01','2026-08-31','2026-09-25','OPEN',0,NULL,NULL,NULL,NULL,'2026-08-09T11:00:00Z','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO vat_periods
    (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
    VALUES ('period-0004','org-0004','tp-0004','2026-08','2026-08-01','2026-08-31','2026-09-25','OPEN',0,NULL,NULL,NULL,NULL,'2026-08-09T11:00:00Z','2026-08-09T11:00:00Z')`,

  `INSERT OR IGNORE INTO reconciliation_matches
    (id,organisation_id,taxpayer_id,vat_period_id,invoice_id,ledger_entry_id,match_type,confidence_bps,status,evidence,reconciled_by,reconciled_at,created_at)
    VALUES ('match-0001','org-0001','tp-0001','period-0001','inv-0001','led-0001a','CERTIFIED_OUTPUT',10000,'MATCHED','{"certificate":"cert-0001","ledger_entry":"led-0001a"}','usr-local-admin','2026-08-09T11:00:00Z','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO reconciliation_matches
    (id,organisation_id,taxpayer_id,vat_period_id,invoice_id,ledger_entry_id,match_type,confidence_bps,status,evidence,reconciled_by,reconciled_at,created_at)
    VALUES ('match-0002','org-0001','tp-0001','period-0001','inv-0002','led-0002b','COUNTERPART_MATCH',10000,'MATCHED','{"certificate":"cert-0002","supplier":"tp-0002"}','usr-local-admin','2026-08-09T11:00:00Z','2026-08-09T11:00:00Z')`,
  `INSERT OR IGNORE INTO reconciliation_matches
    (id,organisation_id,taxpayer_id,vat_period_id,invoice_id,ledger_entry_id,match_type,confidence_bps,status,evidence,reconciled_by,reconciled_at,created_at)
    VALUES ('match-0003','org-0001','tp-0001','period-0001','inv-0004','led-0004b','RISK_EXCEPTION',0,'BLOCKED_EXCEPTION','{"exception":"exc-0001","reason":"HIGH_VALUE_TRANSACTION"}',NULL,NULL,'2026-08-09T11:00:00Z')`,

  `INSERT OR IGNORE INTO vat_return_versions
    (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
    VALUES ('returnv-0001','period-0001','org-0001','tp-0001',1,NULL,'taxrule-na-pilot-2026-1',1717500,780000,0,937500,'PENDING_APPROVAL','b100000000000000000000000000000000000000000000000000000000000001','usr-tp1-owner','2026-08-09T11:10:00Z',NULL,NULL,NULL)`,
  `INSERT OR IGNORE INTO vat_return_versions
    (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
    VALUES ('returnv-0002','period-0002','org-0002','tp-0002',1,NULL,'taxrule-na-pilot-2026-1',780000,0,0,780000,'DRAFT','b200000000000000000000000000000000000000000000000000000000000002','usr-local-admin','2026-08-09T11:10:00Z',NULL,NULL,NULL)`,
  `INSERT OR IGNORE INTO vat_return_versions
    (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
    VALUES ('returnv-0003','period-0003','org-0003','tp-0003',1,NULL,'taxrule-na-pilot-2026-1',127500,1717500,0,-1590000,'DRAFT','b300000000000000000000000000000000000000000000000000000000000003','usr-local-admin','2026-08-09T11:10:00Z',NULL,NULL,NULL)`,
  `INSERT OR IGNORE INTO vat_return_versions
    (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
    VALUES ('returnv-0004','period-0004','org-0004','tp-0004',1,NULL,'taxrule-na-pilot-2026-1',18000000,0,0,18000000,'DRAFT','b400000000000000000000000000000000000000000000000000000000000004','usr-local-admin','2026-08-09T11:10:00Z',NULL,NULL,NULL)`,

  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0001-o','returnv-0001','BOX_OUTPUT','Output VAT',1717500,1,'{"entry_type":"OUTPUT_VAT"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0001-i','returnv-0001','BOX_INPUT','Eligible input VAT',780000,1,'{"entry_type":"INPUT_VAT","invoice_status":"MATCHED"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0001-a','returnv-0001','BOX_ADJUST','Approved net adjustments',0,0,'{"adjustment_status":"APPROVED"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0001-n','returnv-0001','BOX_NET','Net VAT payable or refundable',937500,2,'{"formula":"OUTPUT - INPUT + NET_ADJUSTMENTS"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0002-o','returnv-0002','BOX_OUTPUT','Output VAT',780000,1,'{"entry_type":"OUTPUT_VAT"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0002-i','returnv-0002','BOX_INPUT','Eligible input VAT',0,0,'{"entry_type":"INPUT_VAT","invoice_status":"MATCHED"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0002-a','returnv-0002','BOX_ADJUST','Approved net adjustments',0,0,'{"adjustment_status":"APPROVED"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0002-n','returnv-0002','BOX_NET','Net VAT payable or refundable',780000,1,'{"formula":"OUTPUT - INPUT + NET_ADJUSTMENTS"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0003-o','returnv-0003','BOX_OUTPUT','Output VAT',127500,1,'{"entry_type":"OUTPUT_VAT"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0003-i','returnv-0003','BOX_INPUT','Eligible input VAT',1717500,1,'{"entry_type":"INPUT_VAT","invoice_status":"MATCHED"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0003-a','returnv-0003','BOX_ADJUST','Approved net adjustments',0,0,'{"adjustment_status":"APPROVED"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0003-n','returnv-0003','BOX_NET','Net VAT payable or refundable',-1590000,2,'{"formula":"OUTPUT - INPUT + NET_ADJUSTMENTS"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0004-o','returnv-0004','BOX_OUTPUT','Output VAT',18000000,1,'{"entry_type":"OUTPUT_VAT"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0004-i','returnv-0004','BOX_INPUT','Eligible input VAT',0,0,'{"entry_type":"INPUT_VAT","invoice_status":"MATCHED"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0004-a','returnv-0004','BOX_ADJUST','Approved net adjustments',0,0,'{"adjustment_status":"APPROVED"}')`,
  `INSERT OR IGNORE INTO vat_return_boxes VALUES ('returnbox-0004-n','returnv-0004','BOX_NET','Net VAT payable or refundable',18000000,1,'{"formula":"OUTPUT - INPUT + NET_ADJUSTMENTS"}')`,
  `INSERT OR IGNORE INTO approval_tasks
    (id,organisation_id,taxpayer_id,domain,resource_type,resource_id,requested_action,risk_tier,status,requested_by,assigned_role,decided_by,requested_at,decided_at,decision_comment)
    VALUES ('approval-0001','org-0001','tp-0001','VAT_RETURN','VAT_RETURN_VERSION','returnv-0001','APPROVE_RETURN','CRITICAL','PENDING','usr-tp1-owner','TAXPAYER_OWNER',NULL,'2026-08-09T11:12:00Z',NULL,NULL)`,
  `INSERT OR IGNORE INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES ('out-returnv-0001','VAT_RETURN_VERSION','returnv-0001','VatReturnApprovalRequested',1,'tp-0001','{"return_version_id":"returnv-0001","task_id":"approval-0001"}','PENDING',0,'2026-08-09T11:12:00Z','2026-08-09T11:12:00Z',NULL,NULL)`,
  `INSERT OR IGNORE INTO seed_state VALUES ('vat-lifecycle-v1','2026-08-09T11:30:00Z')`,
];

const COMPLIANCE_SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO access_roles VALUES ('NAMRA_REFUND_OFFICER','NamRA Refund Officer','NAMRA','HIGH','ACTIVE','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('NAMRA_SUPERVISOR','NamRA Supervisor','NAMRA','CRITICAL','ACTIVE','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('compliance:read','COMPLIANCE','READ','Read authorised obligations, communications and compliance posture','CONFIDENTIAL','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('cases:manage','AUDIT_CASE','MANAGE','Open and manage controlled compliance cases','CONFIDENTIAL','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('disputes:manage','DISPUTE','MANAGE','File and manage taxpayer disputes','CONFIDENTIAL','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('refunds:read','REFUND','READ','Read authorised refund workflow records','CONFIDENTIAL','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('refunds:request','REFUND','REQUEST','Request refund eligibility review','CONFIDENTIAL','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('refunds:review','REFUND','REVIEW','Perform staged refund review','CONFIDENTIAL','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('risk:read','RISK','READ','Read explainable advisory risk indicators','CONFIDENTIAL','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('risk:review','RISK','REVIEW','Review advisory risk indicators without automated adverse action','CONFIDENTIAL','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('communications:manage','COMMUNICATION','MANAGE','Record controlled taxpayer communications','CONFIDENTIAL','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('communications:respond','COMMUNICATION','RESPOND','Respond within an existing NamRA correspondence thread','CONFIDENTIAL','2026-08-26T00:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('notifications:manage','NOTIFICATION','MANAGE','Queue a notification directly','CONFIDENTIAL','2026-08-26T00:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('reports:executive','REPORT','EXECUTIVE','Run executive-tier aggregate reports','CONFIDENTIAL','2026-08-26T00:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('consents:manage','CONSENT','MANAGE','Manage taxpayer consents and delegations','CONFIDENTIAL','2026-08-10T07:00:00Z')`,

  `INSERT OR IGNORE INTO tax_obligations
    (id,organisation_id,taxpayer_id,obligation_type,period_code,due_date,amount_cents,currency,status,source_system,source_reference,created_at,updated_at)
    VALUES ('obligation-0001','org-0001','tp-0001','VAT_RETURN','2026-08','2026-09-25',937500,'NAD','PENDING_STATUTORY_FILING','VAT_MSA','returnv-0001','2026-08-10T07:00:00Z','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO tax_obligations
    (id,organisation_id,taxpayer_id,obligation_type,period_code,due_date,amount_cents,currency,status,source_system,source_reference,created_at,updated_at)
    VALUES ('obligation-0002','org-0002','tp-0002','VAT_RETURN','2026-08','2026-09-25',780000,'NAD','DRAFT','VAT_MSA','returnv-0002','2026-08-10T07:00:00Z','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO consent_grants
    (id,organisation_id,taxpayer_id,granted_by,grantee_type,grantee_id,purpose,data_categories,legal_basis,status,valid_from,valid_to,revoked_at,created_at)
    VALUES ('consent-0001','org-0001','tp-0001','usr-tp1-owner','ROLE','TAXPAYER_ACCOUNTANT','VAT return preparation','["INVOICES","VAT_LEDGER","RETURNS"]','TAXPAYER_INSTRUCTION','ACTIVE','2026-08-01T00:00:00Z','2026-12-31T23:59:59Z',NULL,'2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO delegations
    (id,organisation_id,taxpayer_id,delegator_user_id,delegate_user_id,scopes,status,valid_from,valid_to,approved_by,approved_at,created_at)
    VALUES ('delegation-0001','org-0001','tp-0001','usr-tp1-owner','usr-local-admin','["returns:read","audit:read"]','ACTIVE','2026-08-01T00:00:00Z','2026-08-31T23:59:59Z','usr-tp1-owner','2026-08-01T00:00:00Z','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO communications
    (id,organisation_id,taxpayer_id,channel,direction,subject,content_summary,classification,related_resource_type,related_resource_id,external_reference,status,actor_id,occurred_at)
    VALUES ('communication-0001','org-0004','tp-0004','PORTAL','OUTBOUND','High-value transaction review opened','Secure notice advising that invoice KC-1041 requires compliance review.','TAX_CONFIDENTIAL','INVOICE','inv-0004',NULL,'DELIVERED','usr-local-admin','2026-08-10T07:05:00Z')`,
  `INSERT OR IGNORE INTO notifications
    (id,user_id,taxpayer_id,notification_type,title,message,severity,status,action_url,created_at,read_at)
    VALUES ('notification-0001',NULL,'tp-0004','CASE_UPDATE','Compliance review opened','A high-value transaction is under controlled human review.','HIGH','UNREAD','/cases','2026-08-10T07:05:00Z',NULL)`,
  `INSERT OR IGNORE INTO audit_cases
    (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,assigned_officer_id,opened_by,opened_at,updated_at,closed_at)
    VALUES ('case-0001','CASE-2026-0001','org-0004','tp-0004','DESK_REVIEW','High-value advisory transaction review','Invoice KC-1041 exceeds the controlled high-value pilot threshold and requires evidence-led officer review.','CRITICAL','PROPOSED','usr-local-admin','usr-local-admin','2026-08-10T07:00:00Z','2026-08-10T07:00:00Z',NULL)`,
  `INSERT OR IGNORE INTO audit_evidence
    (id,audit_case_id,evidence_type,source_resource_type,source_resource_id,document_id,checksum_sha256,description,status,added_by,added_at)
    VALUES ('case-evidence-0001','case-0001','CERTIFIED_RECORD','INVOICE','inv-0004',NULL,'43a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','Canonical invoice, certificate and VAT ledger references.','PRESERVED','usr-local-admin','2026-08-10T07:00:00Z')`,
  `INSERT OR IGNORE INTO audit_findings
    (id,audit_case_id,finding_code,title,description,legal_reference,amount_cents,currency,status,author_id,created_at,resolved_at)
    VALUES ('finding-0001','case-0001','EVIDENCE-REQUEST','Supporting evidence required','The transaction remains under review pending independently supplied engagement and delivery evidence.',NULL,18000000,'NAD','PRELIMINARY','usr-local-admin','2026-08-10T07:10:00Z',NULL)`,
  `INSERT OR IGNORE INTO disputes
    (id,dispute_number,organisation_id,taxpayer_id,audit_case_id,disputed_resource_type,disputed_resource_id,grounds,disputed_amount_cents,currency,status,filed_by,assigned_officer_id,filed_at,decided_at,decision_summary)
    VALUES ('dispute-0001','DSP-2026-0001','org-0004','tp-0004','case-0001','AUDIT_FINDING','finding-0001','The taxpayer requests clarification of the evidence scope before responding to the preliminary finding.',18000000,'NAD','FILED','usr-local-admin',NULL,'2026-08-10T07:20:00Z',NULL,NULL)`,
  `INSERT OR IGNORE INTO risk_indicators
    (id,organisation_id,taxpayer_id,subject_type,subject_id,indicator_code,score_bps,severity,rationale,rule_version,decision_effect,status,detected_at,reviewed_by,reviewed_at)
    VALUES ('risk-0001','org-0004','tp-0004','INVOICE','inv-0004','HIGH_VALUE_TRANSACTION',9200,'CRITICAL','Gross value exceeds the controlled pilot threshold; the indicator cannot impose an adverse decision.','RISK-PILOT-2026.1','ADVISORY_ONLY','OPEN','2026-08-06T09:32:11Z',NULL,NULL)`,
  `INSERT OR IGNORE INTO refund_claims
    (id,claim_number,organisation_id,taxpayer_id,vat_return_version_id,amount_cents,currency,status,evidence_status,risk_tier,requested_by,requested_at,approved_by,approved_at,payment_instruction_id)
    VALUES ('refund-0001','RFD-2026-0001','org-0003','tp-0003','returnv-0003',1590000,'NAD','BLOCKED_RETURN_NOT_FILED','AWAITING_ITAS_ACKNOWLEDGEMENT','HIGH','usr-local-admin','2026-08-10T07:30:00Z',NULL,NULL,NULL)`,
  `INSERT OR IGNORE INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES ('out-case-0001','AUDIT_CASE','case-0001','AuditCaseOpened',1,'tp-0004','{"case_id":"case-0001","case_number":"CASE-2026-0001"}','PENDING',0,'2026-08-10T07:00:00Z','2026-08-10T07:00:00Z',NULL,NULL)`,
  `INSERT OR IGNORE INTO seed_state VALUES ('compliance-v1','2026-08-10T08:00:00Z')`,
];

const PLATFORM_SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO access_permissions VALUES ('integrations:read','INTEGRATION','READ','Read integration capability and health posture','RESTRICTED','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('integrations:manage','INTEGRATION','MANAGE','Manage governed integration configuration references','RESTRICTED','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('developer:read','DEVELOPER','READ','Read API client and webhook posture','RESTRICTED','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('developer:manage','DEVELOPER','MANAGE','Manage API clients without exposing credentials','RESTRICTED','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('offline:read','OFFLINE','READ','Read offline device, range, batch and conflict posture','RESTRICTED','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('offline:sync','OFFLINE','SYNC','Submit signed ordered offline batches','RESTRICTED','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('reports:read','REPORT','READ','Read governed report definitions and runs','CONFIDENTIAL','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('reports:run','REPORT','RUN','Run tenant-scoped governed reports','CONFIDENTIAL','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('platform:read','PLATFORM','READ','Read service component and queue posture','SECURITY','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('payments:read','PAYMENT','READ','Read governed payment instruction posture','CONFIDENTIAL','2026-08-10T08:30:00Z')`,

  `INSERT OR IGNORE INTO integration_connections
    (id,organisation_id,provider_key,category,display_name,capabilities,endpoint_reference,credential_reference,configuration_status,operational_status,data_classification,last_health_check_at,last_health_outcome,created_at,updated_at)
    VALUES ('integration-itas',NULL,'ITAS','GOVERNMENT','ITAS statutory services','["IDENTITY_FEDERATION","TAXPAYER_VERIFICATION","RETURN_SUBMISSION"]',NULL,NULL,'REQUIRES_AUTHORITY_CONTRACT','DISABLED','TAX_CONFIDENTIAL',NULL,NULL,'2026-08-10T08:30:00Z','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO integration_connections
    (id,organisation_id,provider_key,category,display_name,capabilities,endpoint_reference,credential_reference,configuration_status,operational_status,data_classification,last_health_check_at,last_health_outcome,created_at,updated_at)
    VALUES ('integration-bipa',NULL,'BIPA','GOVERNMENT','BIPA company verification','["COMPANY_VERIFICATION"]',NULL,NULL,'REQUIRES_AUTHORITY_CONTRACT','DISABLED','RESTRICTED',NULL,NULL,'2026-08-10T08:30:00Z','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO integration_connections
    (id,organisation_id,provider_key,category,display_name,capabilities,endpoint_reference,credential_reference,configuration_status,operational_status,data_classification,last_health_check_at,last_health_outcome,created_at,updated_at)
    VALUES ('integration-bank-org1','org-0001','BANK_FILE_OR_API','BANKING','Bank statement intake','["STATEMENT_IMPORT","PAYMENT_RECONCILIATION"]',NULL,NULL,'REQUIRES_BANK_CONTRACT','DISABLED','CONFIDENTIAL',NULL,NULL,'2026-08-10T08:30:00Z','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO integration_connections
    (id,organisation_id,provider_key,category,display_name,capabilities,endpoint_reference,credential_reference,configuration_status,operational_status,data_classification,last_health_check_at,last_health_outcome,created_at,updated_at)
    VALUES ('integration-treasury',NULL,'TREASURY_PAYMENT','PAYMENT','Treasury refund payment','["REFUND_PAYMENT"]',NULL,NULL,'REQUIRES_TREASURY_CONTRACT','DISABLED','TAX_CONFIDENTIAL',NULL,NULL,'2026-08-10T08:30:00Z','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO api_clients
    (id,organisation_id,name,client_key,scopes,credential_reference,status,rate_limit_profile,last_rotated_at,expires_at,created_by,created_at)
    VALUES ('api-client-0001','org-0001','Namib Office ERP','erp_namib_office_01','["invoices.write","invoices.read"]','secret-manager://pending/api-client-0001','PENDING_CREDENTIAL_PROVISIONING','PILOT_STANDARD',NULL,NULL,'usr-local-admin','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO webhook_subscriptions
    (id,api_client_id,event_types,endpoint_url,signing_key_reference,status,created_at)
    VALUES ('webhook-0001','api-client-0001','["na.vatmsa.invoice.certified.v1"]','https://erp.namiboffice.example/webhooks/vat-msa','secret-manager://pending/webhook-0001','DISABLED_PENDING_VERIFICATION','2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO sync_jobs
    (id,integration_connection_id,organisation_id,job_type,direction,status,cursor,records_read,records_written,error_count,requested_by,requested_at,started_at,completed_at,last_error)
    VALUES ('sync-0001','integration-itas',NULL,'RETURN_SUBMISSION','OUTBOUND','BLOCKED_CONFIGURATION',NULL,0,0,0,'usr-local-admin','2026-08-10T08:35:00Z',NULL,NULL,'ITAS authority contract is not configured.')`,
  `INSERT OR IGNORE INTO bank_imports
    (id,organisation_id,integration_connection_id,document_id,bank_name,account_reference_masked,statement_from,statement_to,currency,transaction_count,status,requested_by,created_at)
    VALUES ('bank-import-0001','org-0001','integration-bank-org1',NULL,'Unconfigured bank','****0001','2026-08-01','2026-08-09','NAD',0,'EVIDENCE_REQUIRED','usr-local-admin','2026-08-10T08:35:00Z')`,
  `INSERT OR IGNORE INTO offline_devices
    (id,organisation_id,branch_id,device_code,display_name,public_key_reference,certificate_fingerprint,status,enrolment_status,last_accepted_sequence,last_batch_hash,last_seen_at,created_at)
    VALUES ('offline-device-0001','org-0001','br-0001','POS-WHK-01','Windhoek POS 01',NULL,NULL,'PENDING','AWAITING_TRUST_BOOTSTRAP',0,NULL,NULL,'2026-08-10T08:30:00Z')`,
  `INSERT OR IGNORE INTO offline_number_ranges
    (id,offline_device_id,document_type,prefix,range_start,range_end,next_number,status,valid_from,valid_to)
    VALUES ('offline-range-0001','offline-device-0001','TAX_INVOICE','WHK26',1,1000,1,'HELD_PENDING_ENROLMENT','2026-08-10T00:00:00Z','2026-12-31T23:59:59Z')`,
  `INSERT OR IGNORE INTO report_definitions
    (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
    VALUES ('report-def-vat','VAT_POSITION','VAT position summary','TAXPAYER','Latest controlled VAT positions and net aggregate.','TAX_CONFIDENTIAL','1.0.0','ACTIVE','2026-08-10T08:30:00Z','NEAR_REAL_TIME','own organisation; delegated scope only')`,
  `INSERT OR IGNORE INTO report_definitions
    (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
    VALUES ('report-def-cases','COMPLIANCE_CASELOAD','Compliance caseload','NAMRA_OPERATIONS','Open and total compliance case counts.','TAX_CONFIDENTIAL','1.0.0','ACTIVE','2026-08-10T08:30:00Z','MINUTES_TO_DAILY','office/purpose policy; sensitive field masking')`,
  `INSERT OR IGNORE INTO report_definitions
    (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
    VALUES ('report-def-sales','SALES_VAT_SUMMARY','Sales and VAT summary','TAXPAYER','Invoice count, gross value and VAT aggregate.','CONFIDENTIAL','1.0.0','ACTIVE','2026-08-10T08:30:00Z','NEAR_REAL_TIME','own organisation; delegated scope only')`,
  `INSERT OR IGNORE INTO report_definitions
    (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
    VALUES ('report-def-portfolio','PORTFOLIO_EXCEPTIONS','Portfolio exceptions and deadlines','PRACTITIONER','Reconciliation exceptions across every taxpayer the requesting practitioner is actively delegated for.','TAX_CONFIDENTIAL','1.0.0','ACTIVE','2026-08-26T08:30:00Z','MINUTES','consent/mandate and client-level isolation')`,
  `INSERT OR IGNORE INTO report_definitions
    (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
    VALUES ('report-def-executive','REVENUE_COMPLIANCE_TRENDS','Revenue and compliance trends','EXECUTIVE','National aggregate invoice revenue and case-load trend, no taxpayer-level breakdown.','CONFIDENTIAL','1.0.0','ACTIVE','2026-08-26T08:30:00Z','DAILY','aggregation, disclosure controls')`,
  `INSERT OR IGNORE INTO report_definitions
    (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
    VALUES ('report-def-evidence','CASE_EVIDENCE_SUMMARY','Case evidence summary','AUDITOR_LEGAL','Point-in-time evidence and custody-event counts for one audit case.','RESTRICTED','1.0.0','ACTIVE','2026-08-26T08:30:00Z','POINT_IN_TIME','case authority, custody and watermark')`,
  `INSERT OR IGNORE INTO report_definitions
    (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
    VALUES ('report-def-opendata','NATIONAL_VAT_AGGREGATE','National VAT aggregate','OPEN_DATA','Approved national invoice-count and value aggregate, minimum-cell suppressed.','INTERNAL','1.0.0','ACTIVE','2026-08-26T08:30:00Z','SCHEDULED','privacy review, minimum-cell suppression, no re-identification')`,
  `INSERT OR IGNORE INTO report_runs
    (id,report_definition_id,organisation_id,taxpayer_id,parameters,status,row_count,result_summary,output_document_id,requested_by,requested_at,completed_at,expires_at,error_code)
    VALUES ('report-run-0001','report-def-vat','org-0001','tp-0001','{}','COMPLETED_INLINE',2,'{"periods":2,"net_cents":937500}',NULL,'usr-local-admin','2026-08-10T08:40:00Z','2026-08-10T08:40:00Z','2026-08-11T08:40:00Z',NULL)`,
  `INSERT OR IGNORE INTO report_runs
    (id,report_definition_id,organisation_id,taxpayer_id,parameters,status,row_count,result_summary,output_document_id,requested_by,requested_at,completed_at,expires_at,error_code,scope_snapshot,published_by,published_at)
    VALUES ('report-run-0002','report-def-executive',NULL,NULL,'{}','PUBLISHED',4,'{"invoices":4,"total_cents":137630000,"cases":1,"open_cases":1}',NULL,'usr-local-admin','2026-08-26T09:00:00Z','2026-08-26T09:00:00Z',NULL,NULL,'{"organisationId":null,"taxpayerId":null}','usr-local-admin','2026-08-26T09:05:00Z')`,
  `INSERT OR IGNORE INTO data_products
    (id,code,name,description,source_report_definition_id,status,created_at)
    VALUES ('dp-vat-trends','VAT_COMPLIANCE_TRENDS','VAT and compliance trends','Governed enterprise KPI data product for national revenue and compliance caseload trends, published only from an already-reconciled report run.','report-def-executive','ACTIVE','2026-08-26T09:00:00Z')`,
  `INSERT OR IGNORE INTO data_product_lineage
    (id,data_product_id,source_type,source_id,source_label,recorded_at)
    VALUES ('lineage-vat-trends-0001','dp-vat-trends','REPORT_DEFINITION','report-def-executive','REVENUE_COMPLIANCE_TRENDS','2026-08-26T09:00:00Z')`,
  `INSERT OR IGNORE INTO metrics
    (id,code,name,data_product_id,field,unit,status,anomaly_threshold_pct,created_at)
    VALUES ('metric-national-revenue','NATIONAL_REVENUE_CENTS','National invoice revenue','dp-vat-trends','total_cents','CENTS','CERTIFIED',25,'2026-08-26T09:00:00Z')`,
  `INSERT OR IGNORE INTO metrics
    (id,code,name,data_product_id,field,unit,status,anomaly_threshold_pct,created_at)
    VALUES ('metric-open-cases','OPEN_COMPLIANCE_CASES','Open compliance cases','dp-vat-trends','open_cases','COUNT','CERTIFIED',25,'2026-08-26T09:00:00Z')`,
  `INSERT OR IGNORE INTO feature_flags
    (id,key,name,description,rollout_scope,enabled,status,version,created_at)
    VALUES ('flag-itas-integration','ITAS_INTEGRATION','ITAS statutory integration','Enables the ITAS anti-corruption layer for statutory filing and taxpayer verification.','NATIONAL_ONLY',0,'ACTIVE',1,'2026-08-26T09:30:00Z')`,
  `INSERT OR IGNORE INTO feature_flags
    (id,key,name,description,rollout_scope,enabled,status,version,created_at)
    VALUES ('flag-open-data-reports','OPEN_DATA_PUBLIC_ACCESS','Open data public access','Serves OPEN_DATA-tier reports through an unauthenticated public route, rather than the standard authenticated reports:run path.','NATIONAL_ONLY',0,'ACTIVE',1,'2026-08-26T09:30:00Z')`,
  `INSERT OR IGNORE INTO feature_flags
    (id,key,name,description,rollout_scope,enabled,status,version,created_at)
    VALUES ('flag-offline-sync','OFFLINE_SYNC','Offline device sync','Allows offline invoice batches to be enrolled and accepted.','ALL',1,'ACTIVE',1,'2026-08-26T09:30:00Z')`,
  `INSERT OR IGNORE INTO platform_config
    (id,key,category,description,value,status,version,created_at)
    VALUES ('cfg-step-up-window','STEP_UP_WINDOW_MINUTES','SECURITY','Maximum age of a fresh MFA step-up before a privileged change requires re-authentication.','5','ACTIVE',1,'2026-08-26T09:30:00Z')`,
  `INSERT OR IGNORE INTO platform_config
    (id,key,category,description,value,status,version,created_at)
    VALUES ('cfg-export-size-limit','EXPORT_SIZE_LIMIT_KB','REPORTING','Maximum size of a generated report export before it is refused.','200','ACTIVE',1,'2026-08-26T09:30:00Z')`,
  `INSERT OR IGNORE INTO platform_config
    (id,key,category,description,value,status,version,created_at)
    VALUES ('cfg-min-cell-suppression','MIN_CELL_SUPPRESSION_THRESHOLD','REPORTING','Minimum row count below which an OPEN_DATA aggregate is suppressed rather than published.','10','ACTIVE',1,'2026-08-26T09:30:00Z')`,
  `INSERT OR IGNORE INTO access_policies
    (id,code,name,policy_type,description,parameters,status,version,created_at)
    VALUES ('policy-mfa-step-up','MFA_STEP_UP_POLICY','MFA step-up policy','MFA','Freshness window and assurance level required for a privileged change.','{"max_age_minutes":5,"required_assurance":"MFA_STEP_UP"}','ACTIVE',1,'2026-08-26T09:30:00Z')`,
  `INSERT OR IGNORE INTO access_policies
    (id,code,name,policy_type,description,parameters,status,version,created_at)
    VALUES ('policy-default-rate-limit','DEFAULT_RATE_LIMIT_POLICY','Default rate limit policy','RATE_LIMIT','Default per-actor request budget applied where a route does not declare its own.','{"window_seconds":60,"limit":120}','ACTIVE',1,'2026-08-26T09:30:00Z')`,
  `INSERT OR IGNORE INTO service_components VALUES ('component-web','WEB_APP','VAT-MSA web application','APPLICATION','HIGH','CONFIGURED','OPERATIONAL','Cloudflare Worker/Vinext runtime','2026-08-10T08:45:00Z','Release gate and readiness checks passed.')`,
  `INSERT OR IGNORE INTO service_components VALUES ('component-d1','D1','Structured transactional state','DATABASE','CRITICAL','CONFIGURED','OPERATIONAL','Cloudflare D1 binding DB','2026-08-10T08:45:00Z','Schema initialisation and prepared-query probe passed.')`,
  `INSERT OR IGNORE INTO service_components VALUES ('component-r2','R2_DOCUMENTS','Private document quarantine','OBJECT_STORAGE','HIGH','CONFIGURED','QUARANTINE_ONLY','Cloudflare R2 binding DOCUMENTS','2026-08-10T08:45:00Z','Uploads remain quarantined pending an external malware scanner.')`,
  `INSERT OR IGNORE INTO service_components VALUES ('component-itas','ITAS','ITAS statutory integration','EXTERNAL','CRITICAL','REQUIRES_AUTHORITY_CONTRACT','DISABLED','NamRA/ITAS contract, credentials and approved mappings','2026-08-10T08:45:00Z','No legal filing or taxpayer verification is claimed.')`,
  `INSERT OR IGNORE INTO service_components VALUES ('component-hsm','SIGNING_HSM','Production certificate signing','SECURITY','CRITICAL','REQUIRES_SECURITY_CONTRACT','DISABLED','HSM/KMS keys and approved signature profile','2026-08-10T08:45:00Z','Development signatures are not production legal signatures.')`,
  `INSERT OR IGNORE INTO service_components VALUES ('component-events','OUTBOX','Durable event outbox','MESSAGING','HIGH','CONFIGURED','PENDING_CONSUMER','D1 outbox table and external publisher','2026-08-10T08:45:00Z','Events are durable; external broker publisher is not configured.')`,
  `INSERT OR IGNORE INTO seed_state VALUES ('platform-v1','2026-08-10T09:00:00Z')`,
];

const PORTAL_SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO access_permissions VALUES ('administration:read','ADMINISTRATION','READ','Read authorised access administration posture','RESTRICTED','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('administration:manage','ADMINISTRATION','MANAGE','Manage governed identity and access administration','SECURITY','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('platform:manage','PLATFORM','MANAGE','Manage governed technical platform configuration references','SECURITY','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('NAMRA_SYSTEM_ADMIN','NamRA System Administrator','NAMRA_ADMIN','CRITICAL','ACTIVE','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('SUPER_ADMIN','Super Administrator','PLATFORM','CRITICAL','ACTIVE','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('INFRASTRUCTURE_ADMIN','Infrastructure Administrator','PLATFORM','CRITICAL','ACTIVE','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO access_roles VALUES ('DEVELOPER_PARTNER','Developer Partner','PARTNER','HIGH','ACTIVE','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-nsa-id','NAMRA_SYSTEM_ADMIN','identity:read','ALLOW','{"scope":"administrative-authority","excludes":"transaction-access"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-nsa-tr','NAMRA_SYSTEM_ADMIN','taxpayers:read','ALLOW','{"scope":"administrative-metadata"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-nsa-rr','NAMRA_SYSTEM_ADMIN','registrations:read','ALLOW','{"scope":"administrative-authority"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-nsa-om','NAMRA_SYSTEM_ADMIN','organisations:manage','ALLOW','{"scope":"administrative-authority","requires":"approval"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-nsa-ar','NAMRA_SYSTEM_ADMIN','administration:read','ALLOW','{"scope":"administrative-authority"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-nsa-am','NAMRA_SYSTEM_ADMIN','administration:manage','ALLOW','{"scope":"administrative-authority","requires":"jit-mfa-approval"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-sa-pr','SUPER_ADMIN','platform:read','ALLOW','{"scope":"technical-only","excludes":"tax-data"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-sa-pm','SUPER_ADMIN','platform:manage','ALLOW','{"scope":"technical-only","requires":"jit-mfa-change-approval"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-ia-pr','INFRASTRUCTURE_ADMIN','platform:read','ALLOW','{"scope":"infrastructure-only"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-ia-pm','INFRASTRUCTURE_ADMIN','platform:manage','ALLOW','{"scope":"infrastructure-only","requires":"jit-mfa-change-approval"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-dp-dr','DEVELOPER_PARTNER','developer:read','ALLOW','{"scope":"own-client"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-dp-dm','DEVELOPER_PARTNER','developer:manage','ALLOW','{"scope":"own-client","requires":"conformance-approval"}','2026-08-10T09:30:00Z')`,
  `INSERT OR IGNORE INTO seed_state VALUES ('portal-separation-v1','2026-08-10T09:30:00Z')`,
];

const CONTROL_PLANE_SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO access_permissions VALUES ('workspace:read','WORKSPACE','READ','Read the effective hierarchical workspace','RESTRICTED','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('search:read','SEARCH','READ','Use permission-filtered workspace and record search','RESTRICTED','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('licensing:read','LICENSING','READ','Read organisation licence entitlements and usage','COMMERCIAL','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('licensing:request','LICENSING','REQUEST','Request an approved licence change without changing state','COMMERCIAL','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('employees:read','EMPLOYEE','READ','Read authorised organisation employees','CONFIDENTIAL','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('employees:manage','EMPLOYEE','MANAGE','Invite suspend and offboard authorised employees','RESTRICTED','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('roles:read','ORGANISATION_ROLE','READ','Read organisation-specific roles','RESTRICTED','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('roles:manage','ORGANISATION_ROLE','MANAGE','Create roles from the protected permission catalogue','SECURITY','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('workflows:read','WORKFLOW','READ','Read versioned organisation workflows','RESTRICTED','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('workflows:manage','WORKFLOW','MANAGE','Create test and request publication of typed workflows','SECURITY','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('workflows:decide','WORKFLOW','DECIDE','Decide only assigned workflow tasks under segregation of duties','RESTRICTED','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('access-governance:read','ACCESS_GOVERNANCE','READ','Read access requests reviews and certifications','RESTRICTED','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO access_permissions VALUES ('access-governance:manage','ACCESS_GOVERNANCE','MANAGE','Decide access requests and certify or revoke access','SECURITY','2026-08-10T10:00:00Z')`,

  `INSERT OR IGNORE INTO license_plans (id,code,name,version,status,effective_from,effective_to,created_at)
    VALUES ('plan-pilot-professional-v1','PILOT_PROFESSIONAL','Professional Pilot',1,'ACTIVE','2026-08-01T00:00:00Z',NULL,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_features VALUES ('CORE_VAT','Core VAT management','Controlled invoice VAT reconciliation and return workspaces',NULL,1,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_features VALUES ('ADMINISTRATION','Organisation administration','Employees roles access governance and security posture','USER_SEATS',1,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_features VALUES ('USER_SEATS','User seats','Active organisation users','USER_SEATS',0,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_features VALUES ('BRANCHES','Branches','Active operating branches','BRANCHES',0,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_features VALUES ('ADVANCED_WORKFLOW','Advanced workflow','Versioned conditional workflow and access governance','WORKFLOWS',1,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_features VALUES ('ACCOUNTING','Accounting','General ledger and financial controls',NULL,0,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_features VALUES ('INVENTORY','Inventory','Inventory and warehouse controls',NULL,0,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_features VALUES ('PROJECTS','Projects','Project costing budgets and reports',NULL,0,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_features VALUES ('ANALYTICS','Analytics','Advanced governed reports and analytics','REPORT_RUNS',0,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_features VALUES ('API_ACCESS','API access','Scoped API clients webhooks and usage','API_REQUESTS',1,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_plan_entitlements VALUES ('ent-core','plan-pilot-professional-v1','CORE_VAT',1,NULL,'{}')`,
  `INSERT OR IGNORE INTO license_plan_entitlements VALUES ('ent-admin','plan-pilot-professional-v1','ADMINISTRATION',1,NULL,'{}')`,
  `INSERT OR IGNORE INTO license_plan_entitlements VALUES ('ent-seats','plan-pilot-professional-v1','USER_SEATS',1,25,'{}')`,
  `INSERT OR IGNORE INTO license_plan_entitlements VALUES ('ent-branches','plan-pilot-professional-v1','BRANCHES',1,5,'{}')`,
  `INSERT OR IGNORE INTO license_plan_entitlements VALUES ('ent-workflow','plan-pilot-professional-v1','ADVANCED_WORKFLOW',1,20,'{"max_nodes":30}')`,
  `INSERT OR IGNORE INTO license_plan_entitlements VALUES ('ent-accounting','plan-pilot-professional-v1','ACCOUNTING',1,NULL,'{}')`,
  `INSERT OR IGNORE INTO license_plan_entitlements VALUES ('ent-inventory','plan-pilot-professional-v1','INVENTORY',1,NULL,'{}')`,
  `INSERT OR IGNORE INTO license_plan_entitlements VALUES ('ent-projects','plan-pilot-professional-v1','PROJECTS',1,NULL,'{}')`,
  `INSERT OR IGNORE INTO license_plan_entitlements VALUES ('ent-analytics','plan-pilot-professional-v1','ANALYTICS',1,1000,'{}')`,
  `INSERT OR IGNORE INTO license_plan_entitlements VALUES ('ent-api','plan-pilot-professional-v1','API_ACCESS',1,100000,'{}')`,
  `INSERT OR IGNORE INTO subscriptions
    (id,organisation_id,provider,provider_reference,status,activated_at,current_period_start,current_period_end,created_at,updated_at)
    VALUES ('sub-org1-pilot','org-0001','LOCAL_SYNTHETIC','synthetic-subscription-org1','ACTIVE','2026-08-01T00:00:00Z','2026-08-01','2026-10-31','2026-08-10T10:00:00Z','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_licenses
    (id,organisation_id,subscription_id,license_plan_id,state,state_version,effective_from,effective_to,grace_ends_at,retention_policy,updated_at)
    VALUES ('olic-org1','org-0001','sub-org1-pilot','plan-pilot-professional-v1','ACTIVE',1,'2026-08-01T00:00:00Z',NULL,NULL,'NON_DESTRUCTIVE_TAX_RETENTION','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_usage VALUES ('usage-seats-org1','olic-org1','org-0001','USER_SEATS','2026-Q3',3,0,1,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_usage VALUES ('usage-branches-org1','olic-org1','org-0001','BRANCHES','2026-Q3',1,0,1,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_usage VALUES ('usage-workflows-org1','olic-org1','org-0001','WORKFLOWS','2026-Q3',1,0,1,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_usage VALUES ('usage-api-org1','olic-org1','org-0001','API_REQUESTS','2026-08',142,0,1,'2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO license_events VALUES ('levent-org1-active','olic-org1','org-0001','LicenseActivated',NULL,'ACTIVE','LOCAL_SYNTHETIC_APPROVAL','Architecture-approved local staging activation','2026-08-01T00:00:00Z')`,

  `INSERT OR IGNORE INTO departments VALUES ('dept-finance','org-0001','FIN','Finance','', 'ACTIVE','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO departments VALUES ('dept-procurement','org-0001','PROC','Procurement','', 'ACTIVE','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO departments VALUES ('dept-sales','org-0001','SALES','Sales','', 'ACTIVE','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO business_units VALUES ('bu-core','org-0001','CORE','Core Operations','ACTIVE','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO job_titles VALUES ('job-director','org-0001','DIR','Managing Director','Employment title only; access is assigned separately.','ACTIVE','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO job_titles VALUES ('job-finance-manager','org-0001','FIN-MGR','Finance Manager','Employment title only; access is assigned separately.','ACTIVE','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO job_titles VALUES ('job-procurement','org-0001','PROC-OFF','Procurement Officer','Employment title only; access is assigned separately.','ACTIVE','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO positions VALUES ('pos-director','org-0001','job-director','dept-finance','bu-core','br-0001','POS-DIR','Managing Director','ACTIVE')`,
  `INSERT OR IGNORE INTO positions VALUES ('pos-finance','org-0001','job-finance-manager','dept-finance','bu-core','br-0001','POS-FIN','Finance Manager','ACTIVE')`,
  `INSERT OR IGNORE INTO positions VALUES ('pos-procurement','org-0001','job-procurement','dept-procurement','bu-core','br-0001','POS-PROC','Procurement Officer','ACTIVE')`,
  `INSERT OR IGNORE INTO app_users VALUES ('usr-tp1-finance','demo-tp1-finance','finance.manager@namiboffice.example','Ester Amutenya','TAXPAYER_ACCOUNTANT','tp-0001','ACTIVE','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO app_users VALUES ('usr-tp1-procurement','demo-tp1-procurement','procurement@namiboffice.example','Petrus Shikongo','TAXPAYER_STAFF','tp-0001','ACTIVE','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO employees
    (id,organisation_id,user_id,employee_number,full_name,email,position_id,job_title_id,department_id,business_unit_id,branch_id,manager_employee_id,status,invited_at,activated_at,terminated_at,last_activity_at,created_at,updated_at)
    VALUES ('emp-owner','org-0001','usr-tp1-owner','EMP-001','Namib Office Owner','owner@namiboffice.example','pos-director','job-director','dept-finance','bu-core','br-0001',NULL,'ACTIVE','2026-08-01T00:00:00Z','2026-08-01T00:00:00Z',NULL,'2026-08-10T09:30:00Z','2026-08-10T10:00:00Z','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO employees
    (id,organisation_id,user_id,employee_number,full_name,email,position_id,job_title_id,department_id,business_unit_id,branch_id,manager_employee_id,status,invited_at,activated_at,terminated_at,last_activity_at,created_at,updated_at)
    VALUES ('emp-finance','org-0001','usr-tp1-finance','EMP-002','Ester Amutenya','finance.manager@namiboffice.example','pos-finance','job-finance-manager','dept-finance','bu-core','br-0001','emp-owner','ACTIVE','2026-08-02T00:00:00Z','2026-08-02T00:00:00Z',NULL,'2026-08-09T16:20:00Z','2026-08-10T10:00:00Z','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO employees
    (id,organisation_id,user_id,employee_number,full_name,email,position_id,job_title_id,department_id,business_unit_id,branch_id,manager_employee_id,status,invited_at,activated_at,terminated_at,last_activity_at,created_at,updated_at)
    VALUES ('emp-procurement','org-0001','usr-tp1-procurement','EMP-003','Petrus Shikongo','procurement@namiboffice.example','pos-procurement','job-procurement','dept-procurement','bu-core','br-0001','emp-owner','ACTIVE','2026-08-03T00:00:00Z','2026-08-03T00:00:00Z',NULL,'2026-05-01T08:00:00Z','2026-08-10T10:00:00Z','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_memberships
    (id,organisation_id,user_id,role_code,branch_id,status,valid_from,valid_to,assigned_by,created_at)
    VALUES ('mem-finance','org-0001','usr-tp1-finance','TAXPAYER_ACCOUNTANT','br-0001','ACTIVE','2026-08-02T00:00:00Z',NULL,'usr-tp1-owner','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_memberships
    (id,organisation_id,user_id,role_code,branch_id,status,valid_from,valid_to,assigned_by,created_at)
    VALUES ('mem-procurement','org-0001','usr-tp1-procurement','TAXPAYER_STAFF','br-0001','ACTIVE','2026-08-03T00:00:00Z',NULL,'usr-tp1-owner','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_administrator_roles VALUES ('PRIMARY','Primary Organisation Administrator','ORGANISATION',1)`,
  `INSERT OR IGNORE INTO organisation_administrator_roles VALUES ('FINANCE','Finance Administrator','FINANCE_SCOPE',1)`,
  `INSERT OR IGNORE INTO organisation_administrator_roles VALUES ('USER_ACCESS','User and Access Administrator','IDENTITY_SCOPE',1)`,
  `INSERT OR IGNORE INTO organisation_administrator_roles VALUES ('BRANCH','Branch Administrator','BRANCH_SCOPE',1)`,
  `INSERT OR IGNORE INTO organisation_administrator_roles VALUES ('WORKFLOW','Workflow Administrator','WORKFLOW_SCOPE',1)`,
  `INSERT OR IGNORE INTO organisation_administrator_roles VALUES ('INTEGRATION','Integration Administrator','INTEGRATION_SCOPE',1)`,
  `INSERT OR IGNORE INTO organisation_administrators
    (id,organisation_id,user_id,employee_id,administrator_role_code,scope,is_primary,status,effective_from,effective_to,appointed_by,approval_reference)
    VALUES ('oadmin-primary-org1','org-0001','usr-tp1-owner','emp-owner','PRIMARY','{"organisation_id":"org-0001"}',1,'ACTIVE','2026-08-01T00:00:00Z',NULL,'SYSTEM_LICENSE_ACTIVATION','synthetic-license-activation')`,

  `INSERT OR IGNORE INTO organisation_roles
    (id,organisation_id,name,description,version,branch_scope,approval_limit_cents,status,created_by,created_at,updated_at)
    VALUES ('orole-finance','org-0001','Financial Controller','Accounting VAT reporting and staged approval authority.',1,'["br-0001"]',10000000,'ACTIVE','usr-tp1-owner','2026-08-10T10:00:00Z','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_roles
    (id,organisation_id,name,description,version,branch_scope,approval_limit_cents,status,created_by,created_at,updated_at)
    VALUES ('orole-procurement','org-0001','Senior Procurement Officer','Procurement work with a controlled approval threshold.',1,'["br-0001"]',1000000,'ACTIVE','usr-tp1-owner','2026-08-10T10:00:00Z','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_role_permissions VALUES ('orp-fin-read','orole-finance','accounting:read','ORGANISATION','ALLOW','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_role_permissions VALUES ('orp-fin-post','orole-finance','accounting:post','ORGANISATION','ALLOW','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_role_permissions VALUES ('orp-fin-returns','orole-finance','returns:read','ORGANISATION','ALLOW','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_role_permissions VALUES ('orp-fin-workflow','orole-finance','workflows:decide','ORGANISATION','ALLOW','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO organisation_role_permissions VALUES ('orp-proc-exp','orole-procurement','expenses:manage','BRANCH','ALLOW','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO user_role_assignments VALUES ('ura-finance','org-0001','usr-tp1-finance','emp-finance','orole-finance','ACTIVE','2026-08-02T00:00:00Z',NULL,'usr-tp1-owner','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO user_role_assignments VALUES ('ura-procurement','org-0001','usr-tp1-procurement','emp-procurement','orole-procurement','ACTIVE','2026-08-03T00:00:00Z',NULL,'usr-tp1-owner','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO user_capability_assignments VALUES ('uca-owner-buyer','org-0001','usr-tp1-owner','BUYER','ACTIVE','2026-08-01T00:00:00Z',NULL,'usr-local-admin')`,
  `INSERT OR IGNORE INTO user_capability_assignments VALUES ('uca-owner-seller','org-0001','usr-tp1-owner','SELLER','ACTIVE','2026-08-01T00:00:00Z',NULL,'usr-local-admin')`,
  `INSERT OR IGNORE INTO user_capability_assignments VALUES ('uca-finance-buyer','org-0001','usr-tp1-finance','BUYER','ACTIVE','2026-08-02T00:00:00Z',NULL,'usr-tp1-owner')`,
  `INSERT OR IGNORE INTO user_capability_assignments VALUES ('uca-finance-seller','org-0001','usr-tp1-finance','SELLER','ACTIVE','2026-08-02T00:00:00Z',NULL,'usr-tp1-owner')`,
  `INSERT OR IGNORE INTO user_capability_assignments VALUES ('uca-procurement-buyer','org-0001','usr-tp1-procurement','BUYER','ACTIVE','2026-08-03T00:00:00Z',NULL,'usr-tp1-owner')`,

  `INSERT OR IGNORE INTO workflows VALUES ('workflow-purchase-org1','org-0001','Purchase request approval','PURCHASE_REQUEST','ACTIVE','usr-tp1-owner','2026-08-10T10:00:00Z','2026-08-10T10:00:00Z')`,
  `INSERT OR IGNORE INTO workflow_versions
    (id,workflow_id,organisation_id,version_number,status,definition_hash,definition,effective_from,published_by,approved_by,published_at,retired_at,created_at)
    VALUES ('workflow-purchase-v1','workflow-purchase-org1','org-0001',1,'PUBLISHED','sha256:synthetic-purchase-workflow-v1','{"name":"Purchase request approval","domainAction":"PURCHASE_REQUEST","nodes":[{"id":"start","type":"START","label":"Submitted"},{"id":"finance","type":"APPROVAL","assigneeType":"ROLE","assigneeRef":"orole-finance","label":"Finance review"},{"id":"end","type":"END","label":"Complete"}],"transitions":[{"from":"start","to":"finance"},{"from":"finance","to":"end"}]}','2026-08-01T00:00:00Z','usr-tp1-owner','usr-tp1-finance','2026-08-01T00:00:00Z',NULL,'2026-08-01T00:00:00Z')`,
  `INSERT OR IGNORE INTO workflow_nodes VALUES ('wn-start','workflow-purchase-v1','start','START','Submitted',NULL,NULL,1)`,
  `INSERT OR IGNORE INTO workflow_nodes VALUES ('wn-finance','workflow-purchase-v1','finance','APPROVAL','Finance review','ROLE','orole-finance',2)`,
  `INSERT OR IGNORE INTO workflow_nodes VALUES ('wn-end','workflow-purchase-v1','end','END','Complete',NULL,NULL,3)`,
  `INSERT OR IGNORE INTO workflow_transitions VALUES ('wt-start-fin','workflow-purchase-v1','start','finance',1)`,
  `INSERT OR IGNORE INTO workflow_transitions VALUES ('wt-fin-end','workflow-purchase-v1','finance','end',2)`,
  `INSERT OR IGNORE INTO workflow_instances VALUES ('wfi-pr-001','org-0001','workflow-purchase-v1','PURCHASE_REQUEST','PR-2026-001','usr-tp1-procurement','IN_PROGRESS','finance','{"amount_cents":750000,"branch_id":"br-0001"}','2026-08-10T09:00:00Z',NULL)`,
  `INSERT OR IGNORE INTO workflow_assignments VALUES ('wfa-pr-001','wfi-pr-001','finance','usr-tp1-finance','orole-finance','PENDING','2026-08-11T12:00:00Z','2026-08-10T09:00:00Z')`,
  `INSERT OR IGNORE INTO access_requests VALUES ('areq-fin-workflow','org-0001','usr-tp1-finance','usr-tp1-finance','orole-finance','Quarterly confirmation of finance control duties.','PENDING_MANAGER','2026-08-10T09:10:00Z',NULL)`,
  `INSERT OR IGNORE INTO access_reviews VALUES ('areview-q3-org1','org-0001','Q3 privileged and dormant access review','QUARTERLY','OPEN','2026-07-01','2026-09-30T23:59:59Z','usr-tp1-owner','2026-08-10T09:15:00Z',NULL)`,
  `INSERT OR IGNORE INTO access_certifications VALUES ('acert-owner-q3','areview-q3-org1','org-0001','usr-tp1-owner','usr-tp1-finance','{"roles":["TAXPAYER_OWNER"],"administrator":"PRIMARY"}','RETAIN',NULL,'2026-08-10T09:20:00Z')`,
  `INSERT OR IGNORE INTO sod_rules VALUES ('sod-no-self-approval','org-0001','NO_SELF_APPROVAL','No self approval','["CREATE","APPROVE"]','ALL_PROTECTED_WORKFLOWS',1,'ACTIVE','2026-08-01T00:00:00Z','2026-08-01T00:00:00Z')`,
  `INSERT OR IGNORE INTO sod_rules VALUES ('sod-no-create-approve-execute','org-0001','NO_CREATE_APPROVE_EXECUTE','Separate create approve and execute','["CREATE","APPROVE","EXECUTE"]','PAYMENT_AND_TAX_SENSITIVE',1,'ACTIVE','2026-08-01T00:00:00Z','2026-08-01T00:00:00Z')`,

  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-home','home','Home / Command Centre','Executive operational VAT and task posture',10,'ACTIVE','INTERNAL')`,
  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-sales','sales','Sales & Revenue','Customers quotations invoices and output VAT',20,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-procurement','procurement','Procurement & Purchases','Suppliers expenses purchases and input VAT',30,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-vat','vat','VAT & Tax Management','VAT reconciliation returns and compliance',40,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-accounting','accounting','Accounting & Finance','General ledger and financial control',50,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-inventory','inventory','Inventory & Operations','Inventory expenses and operating controls',60,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-projects','projects','Project Management','Project cost revenue and budget control',70,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-documents','documents','Documents & Records','Evidence documents and immutable records',80,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-reporting','reporting','Reporting & Analytics','Governed reports and performance analysis',90,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-integrations','integrations','Integrations','ITAS SaaS API and developer controls',100,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-administration','administration','Administration','Organisation people access workflow and security',110,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_workspaces VALUES ('nav-licensing','licensing','Licensing & Subscription','Licence entitlements usage and renewal posture',120,'ACTIVE','COMMERCIAL')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-home-dashboard','nav-home',NULL,'dashboard','Dashboard',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-sales-main','nav-sales',NULL,'sales','Sales',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-proc-main','nav-procurement',NULL,'procurement','Procurement',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-vat-main','nav-vat',NULL,'vat-management','VAT Management',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-accounting-main','nav-accounting',NULL,'accounting','Accounting',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-inventory-main','nav-inventory',NULL,'inventory','Inventory & Operations',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-projects-main','nav-projects',NULL,'projects','Projects',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-documents-main','nav-documents',NULL,'documents','Documents & Records',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-reporting-main','nav-reporting',NULL,'reports','Reports & Analytics',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-integrations-main','nav-integrations',NULL,'integrations','Integrations & Developer',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-administration-main','nav-administration',NULL,'organisation-admin','Organisation Administration',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_folders VALUES ('folder-licensing-main','nav-licensing',NULL,'subscription','Subscription',10,'ACTIVE')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-dashboard','nav-home','folder-home-dashboard','dashboard','Operations dashboard','/','CORE_VAT',NULL,'dashboard:read',10,'ACTIVE','INTERNAL')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-portals','nav-home','folder-home-dashboard','portals','Portal switchboard','/portals','CORE_VAT',NULL,'dashboard:read',20,'ACTIVE','INTERNAL')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-search','nav-home','folder-home-dashboard','search','Workspace search','/workspace-search','ADMINISTRATION',NULL,'search:read',30,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-commercial','nav-sales','folder-sales-main','commercial','Customers & quotations','/commercial','CORE_VAT','SELLER','commercial:read',10,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-parties','nav-sales','folder-sales-main','parties','Customers & suppliers','/commercial/parties','CORE_VAT',NULL,'parties:manage',15,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-invoices','nav-sales','folder-sales-main','invoices','Tax invoices','/invoices','CORE_VAT','SELLER','invoices:read',20,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-new-invoice','nav-sales','folder-sales-main','new-invoice','Submit tax invoice','/invoices/new','CORE_VAT','SELLER','invoices:submit',30,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-operations','nav-procurement','folder-proc-main','operations','Purchases & expenses','/operations','CORE_VAT','BUYER','expenses:read',10,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-reconciliation','nav-vat','folder-vat-main','reconciliation','VAT reconciliation','/reconciliation','CORE_VAT',NULL,'exceptions:read',10,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-returns','nav-vat','folder-vat-main','returns','VAT returns','/returns','CORE_VAT',NULL,'returns:read',20,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-compliance','nav-vat','folder-vat-main','compliance','Compliance & disputes','/compliance','CORE_VAT',NULL,'compliance:read',30,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-cases','nav-vat','folder-vat-main','cases','Audit cases & risk','/cases','CORE_VAT',NULL,'cases:manage',40,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-refunds','nav-vat','folder-vat-main','refunds','Refund control','/refunds','CORE_VAT',NULL,'refunds:read',50,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-accounting','nav-accounting','folder-accounting-main','accounting','General ledger','/accounting','ACCOUNTING',NULL,'accounting:read',10,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-inventory','nav-inventory','folder-inventory-main','inventory','Inventory operations','/operations','INVENTORY',NULL,'inventory:read',10,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-projects','nav-projects','folder-projects-main','projects','Projects','/operations','PROJECTS',NULL,'projects:read',10,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-documents','nav-documents','folder-documents-main','documents','Evidence documents','/documents','CORE_VAT',NULL,'documents:read',10,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-audit','nav-documents','folder-documents-main','audit','Audit evidence','/audit','CORE_VAT',NULL,'audit:read',20,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-offline','nav-documents','folder-documents-main','offline','Offline continuity','/offline','CORE_VAT',NULL,'offline:read',30,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-reports','nav-reporting','folder-reporting-main','reports','Reports & analytics','/reports','ANALYTICS',NULL,'reports:read',10,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-integrations','nav-integrations','folder-integrations-main','integrations','Integration health','/integrations','API_ACCESS',NULL,'integrations:read',10,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-developer','nav-integrations','folder-integrations-main','developer','Developer & webhooks','/developer','API_ACCESS',NULL,'developer:read',20,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-administration','nav-administration','folder-administration-main','administration','Administration command centre','/administration','ADMINISTRATION',NULL,'administration:read',10,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-organisations','nav-administration','folder-administration-main','organisations','Organisation identity','/organisations','ADMINISTRATION',NULL,'identity:read',20,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-taxpayers','nav-administration','folder-administration-main','taxpayers','Taxpayer registry','/taxpayers','ADMINISTRATION',NULL,'taxpayers:read',25,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-registrations','nav-administration','folder-administration-main','registrations','Registration intake','/registrations','ADMINISTRATION',NULL,'registrations:read',27,'ACTIVE','RESTRICTED')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-security','nav-administration','folder-administration-main','security','Security posture','/security','ADMINISTRATION',NULL,'security:read',30,'ACTIVE','SECURITY')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-licensing','nav-licensing','folder-licensing-main','licensing','Current licence & usage','/administration#licensing','ADMINISTRATION',NULL,'licensing:read',10,'ACTIVE','COMMERCIAL')`,
  `INSERT OR IGNORE INTO navigation_permissions VALUES ('navperm-admin','nitem-administration','organisation-admin-access','ALLOW','Organisation administration permission is required.')`,
  `INSERT OR IGNORE INTO navigation_permissions VALUES ('navperm-licence','nitem-licensing','licence-admin-access','ALLOW','Licence administrator permission is required.')`,
  `INSERT OR IGNORE INTO seed_state VALUES ('control-plane-v1','2026-08-10T10:00:00Z')`,
];

const PARTY_LIFECYCLE_SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO access_permissions VALUES ('parties:manage','BUSINESS_PARTY','MANAGE','Create update and non-destructively deactivate customer and supplier records','CONFIDENTIAL','2026-08-14T09:00:00Z')`,
  `INSERT OR IGNORE INTO role_permission_grants VALUES ('rpg-owner-party','TAXPAYER_OWNER','parties:manage','ALLOW','{"scope":"own-organisation"}','2026-08-14T09:00:00Z')`,
  `INSERT OR IGNORE INTO navigation_items VALUES ('nitem-parties','nav-sales','folder-sales-main','parties','Customers & suppliers','/commercial/parties','CORE_VAT',NULL,'parties:manage',15,'ACTIVE','CONFIDENTIAL')`,
  `INSERT OR IGNORE INTO seed_state VALUES ('business-party-lifecycle-v1','2026-08-14T09:00:00Z')`,
];

let initialization: Promise<void> | null = null;

export function getD1(): D1Database {
  if (!env.DB) throw new Error("VAT-MSA database binding DB is unavailable.");
  return env.DB;
}

export async function ensureDatabase(): Promise<D1Database> {
  const db = getD1();
  initialization ??= initialize(db).catch((error) => {
    initialization = null;
    throw error;
  });
  await initialization;
  return db;
}

async function initialize(db: D1Database): Promise<void> {
  await db.batch(SCHEMA_STATEMENTS.map((statement) => db.prepare(statement)));
  if (process.env.NODE_ENV !== "production") {
    const existing = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("pilot-v1").first();
    if (!existing) await db.batch(SEED_STATEMENTS.map((statement) => db.prepare(statement)));
    const securitySeed = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("security-v1").first();
    if (!securitySeed) await db.batch(SECURITY_SEED_STATEMENTS.map((statement) => db.prepare(statement)));
    const identitySeed = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("identity-v1").first();
    if (!identitySeed) await db.batch(IDENTITY_SEED_STATEMENTS.map((statement) => db.prepare(statement)));
    const businessSeed = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("business-v1").first();
    if (!businessSeed) await db.batch(BUSINESS_SEED_STATEMENTS.map((statement) => db.prepare(statement)));
    const vatLifecycleSeed = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("vat-lifecycle-v1").first();
    if (!vatLifecycleSeed) await db.batch(VAT_LIFECYCLE_SEED_STATEMENTS.map((statement) => db.prepare(statement)));
    const complianceSeed = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("compliance-v1").first();
    if (!complianceSeed) await db.batch(COMPLIANCE_SEED_STATEMENTS.map((statement) => db.prepare(statement)));
    const platformSeed = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("platform-v1").first();
    if (!platformSeed) await db.batch(PLATFORM_SEED_STATEMENTS.map((statement) => db.prepare(statement)));
    const portalSeed = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("portal-separation-v1").first();
    if (!portalSeed) await db.batch(PORTAL_SEED_STATEMENTS.map((statement) => db.prepare(statement)));
    const controlPlaneSeed = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("control-plane-v1").first();
    if (!controlPlaneSeed) await db.batch(CONTROL_PLANE_SEED_STATEMENTS.map((statement) => db.prepare(statement)));
    const partyLifecycleSeed = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("business-party-lifecycle-v1").first();
    if (!partyLifecycleSeed) await db.batch(PARTY_LIFECYCLE_SEED_STATEMENTS.map((statement) => db.prepare(statement)));
  }
  await db.prepare("PRAGMA optimize").run();
}
