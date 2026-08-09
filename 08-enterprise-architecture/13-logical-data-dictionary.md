# Deliverables 05-07 — Logical data dictionary, partitioning and multi-tenancy

This is a logical production schema, not a generated migration. Physical types, sharding, retention and provider-specific features require the Architecture Approval Gate.

## Standards

- Primary keys are globally unique opaque IDs (UUIDv7/ULID or approved equivalent); externally meaningful numbers are alternate keys, never row IDs.
- Mutable records include `created_at/by`, `updated_at/by`, `version`; effective-dated master/config records include `effective_from/to`; statutory/event/evidence records are append-only with `occurred_at`, actor, correlation and source.
- Every tenant-owned row carries `organisation_id` and/or `taxpayer_id`; policy repositories require that predicate. Cross-taxpayer NamRA access uses explicit region/portfolio/case entitlement.
- Monetary values use `amount_minor` + `currency`; rates use scaled integer basis points; timestamps are UTC with governing local calendar/period metadata.
- Secret values are absent. `APIKey` means credential metadata/fingerprint/secret-manager reference.

## Identity, taxpayer and access entities

| Entity | PK and FKs | Required fields | Optional fields | Unique/index constraints | Boundary/classification |
|---|---|---|---|---|---|
| Taxpayer | `taxpayer_id`; no parent | status, legal identity reference | suspension reason | unique canonical VAT identity; status/name indexes | national master; RESTRICTED_TAX |
| Organisation | `organisation_id`; FK taxpayer | legal name, status, currency, classification | trading name | unique active taxpayer FK; status+name | taxpayer tenant root; RESTRICTED_TAX |
| VATRegistration | `vat_registration_id`; FK taxpayer | VAT number, status, source, effective dates | cancellation reason | unique VAT number+jurisdiction; taxpayer+status | ITAS authoritative candidate; RESTRICTED_TAX |
| TIN | `tin_id`; FK taxpayer | TIN, status, source, effective dates | replaced_by | unique TIN+jurisdiction | ITAS authoritative candidate; RESTRICTED_TAX |
| CompanyRegistration | `company_registration_id`; FK taxpayer/org | number, jurisdiction, status, source | incorporation date | unique number+jurisdiction | authority to confirm; RESTRICTED_TAX |
| Branch | `branch_id`; FK organisation | code, name, region, status | address, tax attributes | unique organisation+code; org+status | organisation tenant; CONFIDENTIAL |
| OrganisationCapability | `capability_id`; FK organisation | capability BUYER/SELLER, status, source, effective_from | effective_to | unique org+capability+effective_from | organisation; RESTRICTED_TAX |
| User | `user_id` | lifecycle status, display/contact | locale | unique internal ID; normalized contact lookup non-authoritative | identity; RESTRICTED_IDENTITY |
| IdentityProvider | `provider_id` | code, type, protocol status, metadata version | issuer/discovery ref | unique provider code/issuer | IAM control; RESTRICTED_IDENTITY |
| IdentityLink | `identity_link_id`; FK user/provider | provider subject, assurance, status, verified_at | authoritative attributes ref | unique provider+subject; user+status | IAM; RESTRICTED_IDENTITY |
| Session | `session_id`; FK user/identity link | issued/expires, assurance, token family hash, status | device posture | user+status+expiry; never raw token | IAM; RESTRICTED_IDENTITY |
| Role | `role_id` | code, role family, status | description | unique code | policy master; INTERNAL |
| Permission | `permission_id` | resource, action, effect | conditions schema | unique resource+action | policy master; INTERNAL |
| UserRole | `user_role_id`; FK user/role | scope type/id, valid_from, status | valid_until, approval | unique user+role+scope+valid_from | entitlement; RESTRICTED_IDENTITY |
| OrganisationUser | `organisation_user_id`; FK org/user/role | department, data scope, status, valid_from | branch, region, valid_until | unique org+user+role+valid_from; user+status | tenant membership; RESTRICTED_IDENTITY |
| Consent | `consent_id`; FK organisation/user | grantee, scopes, purpose, status, start/expiry | revocation reason | org+grantee+status+expiry | organisation; RESTRICTED_IDENTITY |
| Delegation | `delegation_id`; FK org/grantor/grantee | permissions, taxpayer scope, status, start/expiry | branch/period | grantee+status+expiry | taxpayer; RESTRICTED_IDENTITY/TAX |
| RegistrationApplication | `application_id`; FK submitting identity | VAT/TIN/company, legal/representative, status, requested capabilities | mismatch details | identifiers+active status; submitted time | onboarding; RESTRICTED_IDENTITY/TAX |

## Parties, commercial, accounting and operations entities

| Entity | PK and FKs | Required fields | Optional fields | Unique/index constraints | Boundary/classification |
|---|---|---|---|---|---|
| Customer | `customer_id`; FK organisation; optional taxpayer | name, status | VAT/TIN/contact/address | unique org+external key; org+name/VAT | organisation; CONFIDENTIAL |
| Supplier | `supplier_id`; FK organisation; optional taxpayer | name, status, verification snapshot | VAT/TIN/contact/address | unique org+external key; org+name/VAT | organisation; CONFIDENTIAL |
| Product | `product_id`; FK organisation | SKU, name, type, status | tax category, unit | unique org+SKU; search name | organisation; CONFIDENTIAL |
| Warehouse | `warehouse_id`; FK organisation/branch | code, name, status | address | unique org+code; branch+status | organisation/branch; CONFIDENTIAL |
| Inventory | `inventory_id`; FK product/warehouse | quantity scaled, valuation method, version | reorder level | unique product+warehouse | organisation; CONFIDENTIAL |
| StockMovement | `movement_id`; FK inventory/source document | type, quantity, occurred_at | cost, reason | inventory+occurred; source ref | organisation; CONFIDENTIAL |
| Quotation | `quotation_id`; FK org/customer/branch | number, currency, dates, status, totals | acceptance, terms | unique org+branch+number; customer+status | organisation; CONFIDENTIAL |
| QuotationItem | `quotation_item_id`; FK quotation/product | line, description, quantity, price, tax proposal | discount | unique quote+line | inherited quotation |
| TaxInvoice | `invoice_id`; FK supplier org/customer/branch/rule set | invoice number, issue date, currency, totals, status, source, immutable transaction ID | due/payment reference, buyer ID | unique org+branch+series+number and source identity; party+date/status | statutory; RESTRICTED_TAX |
| InvoiceItem | `invoice_item_id`; FK invoice/product/rule | line, quantity, price, taxable, VAT, category | exemption reference | unique invoice+line | statutory; RESTRICTED_TAX |
| CreditNote | `credit_note_id`; FK original invoice/approval | number, reason, amounts, issue date, status | evidence | unique issuer+number; original+date | statutory append-only; RESTRICTED_TAX |
| DebitNote | `debit_note_id`; FK original invoice/approval | number, reason, amounts, issue date, status | evidence | unique issuer+number; original+date | statutory append-only; RESTRICTED_TAX |
| InvoiceSequence | `sequence_id`; FK org/branch | series, fiscal period, next value, version | prefix/suffix | unique org+branch+series+period | organisation restricted |
| SequenceReservation | `reservation_id`; FK sequence/device | range, issued/expiry, signature, status | sync checkpoint | no overlapping active ranges; device+status | offline security; RESTRICTED_IDENTITY/TAX |
| Account | `account_id`; FK organisation | code, name, type, status | parent | unique org+code; hierarchy | organisation; CONFIDENTIAL |
| Journal | `journal_id`; FK org/period | number, date, source, status | reversal_of | unique org+number; period+status | financial; RESTRICTED_TAX/CONFIDENTIAL |
| JournalLine | `journal_line_id`; FK journal/account | line, debit/credit minor, currency | party/project | unique journal+line; account+date read model | inherited journal |
| AccountingPeriod | `accounting_period_id`; FK org | from/to, status | close approval | unique org+from/to | organisation; CONFIDENTIAL |
| Payment | `payment_id`; FK org/customer/supplier | direction, amount, currency, date, status, source | bank token/reference | unique source+external ID; org+date/status | financial; RESTRICTED_TAX |
| ExpenseCategory | `expense_category_id`; FK organisation | code, name, status | VAT defaults | unique org+code | organisation; CONFIDENTIAL |
| Expense | `expense_id`; FK org/category/supplier/project | date, amount, currency, status, approval state | receipt document | org+date/status/category | organisation; CONFIDENTIAL |
| Project | `project_id`; FK organisation | code, name, status, dates, currency | customer, manager | unique org+code; status | organisation; CONFIDENTIAL |
| ProjectBudget | `project_budget_id`; FK project/version | version, amounts, status, effective_at | approval | unique project+version | organisation; CONFIDENTIAL |
| ProjectCost | `project_cost_id`; FK project/expense/journal | type, amount, currency, occurred_at | source detail | project+occurred | organisation; CONFIDENTIAL |

## VAT, compliance and governance entities

| Entity | PK and FKs | Required fields | Optional fields | Unique/index constraints | Boundary/classification |
|---|---|---|---|---|---|
| VATRule | `vat_rule_id`; FK approval/change | code, version, category, expression/config, effective dates, status | supersedes | unique code+version; effective status | NamRA master; RESTRICTED_TAX |
| VATTransaction | `vat_transaction_id`; FK invoice/taxpayer/period/rule | type, source, amounts, state, immutable ID | buyer taxpayer | unique source business identity; taxpayer+period/type | statutory aggregate; RESTRICTED_TAX |
| VATLedger | `vat_ledger_id`; FK transaction/taxpayer/period | entry type, direction, amount, currency, occurred | reversal link | transaction+taxpayer+entry unique; taxpayer+period | append-only; RESTRICTED_TAX |
| VATAdjustment | `vat_adjustment_id`; FK transaction/period/approval | adjustment type, reason, amount, status | evidence | source adjustment ID; taxpayer+period | statutory append-only |
| VATPeriod | `vat_period_id`; FK taxpayer/authoritative config | from/to, close/due dates, status, version | official reference | unique taxpayer+from/to; due/status | statutory; RESTRICTED_TAX |
| VATReturn | `vat_return_id`; FK taxpayer/period/rule set | calculation version, totals, status, snapshot hash | submission receipt/refund candidate | unique taxpayer+period+version; status | statutory snapshot; RESTRICTED_TAX |
| ReconciliationMatch | `match_id`; FK seller/buyer invoice/transaction | match type, score/result, version | explanation | invoice/transaction+version; status | statutory; RESTRICTED_TAX |
| ReconciliationException | `exception_id`; FK match/resource/taxpayer | type, severity, status, summary | assignee/resolution | taxpayer+status+severity+created | statutory workflow |
| ComplianceObligation | `obligation_id`; FK taxpayer/period | type, due date, status | satisfaction ref | taxpayer+status+due | RESTRICTED_TAX |
| AuditCase | `audit_case_id`; FK taxpayer/period/assignment | case number, type, status, classification | scope/closure | unique case number; assignee/status | NamRA case; RESTRICTED_TAX |
| AuditFinding | `finding_id`; FK case/evidence | code, severity, finding, status | taxpayer response/resolution | case+status+severity | NamRA case; RESTRICTED_TAX |
| RiskIndicator | `risk_indicator_id`; FK taxpayer/resource/model | type, score band, evidence ref, status | restricted explanation | taxpayer+status+created | RESTRICTED_RISK |
| RefundReview | `refund_review_id`; FK return/case | candidate amount, state, rule version | approval/payment | return+version unique; status | NamRA workflow; RESTRICTED_TAX |

## Document, integration, operations and evidence entities

| Entity | PK and FKs | Required fields | Optional fields | Unique/index constraints | Boundary/classification |
|---|---|---|---|---|---|
| Document | `document_id`; FK owner org/domain resource | object key, class, type, status, current version | retention hold | opaque unique object key; owner/resource | classification inherited |
| DocumentVersion | `document_version_id`; FK document | version, hash, size, media type, scan status | signature | unique document+version; scan status | inherited document |
| Notification | `notification_id`; FK user/org/source | type, template version, status, created | read/delivery dates | recipient+status+created | CONFIDENTIAL |
| Communication | `communication_id`; FK conversation/org/case | sender, channel, body object ref, occurred | response_to | conversation+occurred | RESTRICTED_TAX |
| Integration | `integration_id`; FK owner organisation/provider | type, environment, scopes, status | endpoint metadata | unique owner+type+environment | integration; RESTRICTED_IDENTITY |
| SaaSApplication | `saas_application_id`; FK integration/provider | name, owner, environment, approval state | callback metadata | unique provider+name+environment | CONFIDENTIAL |
| APIClient | `api_client_id`; FK application | client ID, auth mode, scopes, quota, status | certificate fingerprint | unique client ID; status | RESTRICTED_IDENTITY |
| APIKey | `api_key_id`; FK client | secret reference, fingerprint, issued/expiry, status | revoked reason | unique fingerprint; expiry/status | SECRET value external; metadata restricted |
| SyncRecord | `sync_record_id`; FK integration/org/device | source ID, cursor, hash, status, attempt | error code | unique integration+source ID; status+retry | RESTRICTED_TAX |
| EventOutbox | `event_id`; FK aggregate reference | type/version, partition, payload ref, status, occurred/available | published/error | aggregate+version/type; status+available | classification minimized |
| AuditLog | `audit_log_id`; FK actor/org/resource | action, before/after hashes, reason, outcome, correlation, occurred | approval | resource+occurred; actor+occurred | immutable RESTRICTED_TAX/IDENTITY |
| SecurityEvent | `security_event_id`; FK actor/incident optional | type, severity, source token, action/outcome, correlation, occurred | evidence ref | severity+occurred; actor+occurred | RESTRICTED_SECURITY |

## Multi-tenancy decision

Use a shared national platform with shared logical schemas partitioned by `taxpayer_id/organisation_id` for ordinary tenants, backed by mandatory policy-derived predicates and production database row-level security where supported. High-volume tables shard by stable taxpayer partition plus period. Separately administer immutable audit/security, identity secrets and analytics. Database-per-tenant is not the default because millions of tenants would create severe operational/schema/backup complexity; it remains an exception for a legally mandated isolation class after cost/DR analysis.

Defence against cross-tenant access: trusted identity resolution → entitlement/capability policy → scoped repository/API → database RLS/partition key → field masking/classification → audit/UEBA → negative isolation tests. Client-supplied organisation IDs are matched against entitlements and never trusted. National queries require a separate NamRA role plus region/portfolio/case/purpose and are audited.

## Partitioning and crypto

Fiscal partitions use taxpayer shard + statutory period/date; events use taxpayer/aggregate partition; cases use region/case read indexes but retain taxpayer ownership. Avoid predictable shard leakage in public identifiers. Encryption uses managed keys by environment/data class; selected restricted fields use envelope encryption/tokenization. Searchable ciphertext design requires threat/performance review. Backups preserve key-version metadata and are independently encrypted/immutable.
