# G-H, T. Data architecture, database schema, ERD and governance

## Workspace, licensing and workflow model extension

The enterprise ERD now includes the licence/plan/subscription/feature/entitlement/usage/event family; organisation administrator/employee/job/position/department/business-unit family; organisation role/permission/user-role/capability family; workflow definition/version/node/transition/condition/assignment/approval/delegation family; access request/approval/review/certification family; SoD rule/violation family; and navigation workspace/folder/item/permission family.

All tenant-owned records carry `organisation_id`. Published workflow versions, completed workflow approvals, licence events and certification history are append-only. Usage reservations use organisation+metric+period+idempotency uniqueness. Navigation conditions reference typed policy and entitlement IDs, never executable expressions. Exact entities and invariants are in `13-logical-data-dictionary.md` and the extension document.

## Data platforms and ownership

| Store | Workload | Integrity/availability posture |
|---|---|---|
| Operational relational | identity links, taxpayer/organisation, fiscal documents, VAT transactions, workflow | ACID, private, partitioned, replicated, PITR; authoritative domain ownership |
| Immutable evidence | audit/security and signed transaction manifests | append-only/WORM, independent administration, integrity verification and legal hold |
| Object storage | invoices, returns, evidence and import documents | encrypted, opaque IDs, malware scan/quarantine, version/retention policy |
| Durable event bus | versioned domain/integration events | partitioned, replicated, idempotent consumers, dead-letter and controlled replay |
| Distributed cache | public verification, reference data and configuration | never source of truth; tenant/policy keying and invalidation |
| Analytics platform | governed read models and historical BI | isolated from operational writes; lineage, minimisation and approved extracts |
| Security lake/SIEM | identity, edge, API, data and infrastructure events | restricted SOC access, retention lock and correlation |

The D1 pilot represents a controlled operational slice, not the claim that one SQLite database should host national operational, document, analytical and security workloads.

## Canonical identity and legal-entity rules

- `taxpayer.vat_number`, `taxpayer.tin` and `organisation.company_registration_number` are independently unique after authoritative verification.
- One active organisation references one legal taxpayer. Separate legal entities never share a taxpayer row.
- Buyer and Seller are effective-dated capabilities, not identities.
- External identity subjects are unique within a provider and link to internal users; email is display/contact data.
- Branch invoice sequence uniqueness includes organisation, branch, series and fiscal period/reservation.

## Enterprise entity model

See `diagrams/enterprise-erd.mmd`. The ERD includes every required entity and refines the model with IdentityProvider/Link, OrganisationMembership, OrganisationCapability, VATRule/Period, InvoiceSequence/Reservation, EventOutbox and RegistrationApplication.

### Identity/taxpayer

Taxpayer, Organisation, VATRegistration, TIN, CompanyRegistration, Branch, User, IdentityProvider, IdentityLink, Role, Permission, RolePermission, OrganisationMembership, BuyerRole/OrganisationCapability, SellerRole/OrganisationCapability, Consent and Delegation.

### Business and statutory transaction

Customer, Supplier, Quotation, QuotationItem, TaxInvoice, InvoiceItem, CreditNote, DebitNote, VATRule, VATTransaction, VATLedger, VATPeriod, VATReturn, Payment, Expense, Product, Inventory/StockMovement, Project and ProjectCost.

### Governance/integration

Document, Notification, Communication, AuditCase, AuditFinding, RiskIndicator, Integration, SaaSApplication, APIClient, APIKeyMetadata, SyncRecord, EventOutbox, AuditLog and SecurityEvent.

## Partitioning and indexing

National production partitions high-volume invoice/transaction/ledger/event data by stable taxpayer shard plus period/date, preserving globally unique IDs and efficient taxpayer-period reads. Cross-taxpayer NamRA search uses separately governed indexes/read models. Indexes follow real predicates: VAT/TIN/company identifiers; organisation/user memberships; supplier/customer+date; taxpayer+period; status+created/available time; provider+subject; event partition+sequence. Every index has write/storage cost and is verified with representative query plans.

## Monetary, time and history rules

Money uses integer minor units plus ISO currency; rates use scaled integers. Exchange-rate source, rate and timestamp are retained for reporting-currency conversion. Timestamps are UTC; statutory periods and due dates retain the governing timezone/calendar semantics. Completed records are never overwritten: status transitions, versions, adjustments and reversal lineage preserve the prior state and actor/reason/approval.

## Tax invoice numbering

Online sequences are allocated transactionally per organisation+branch+document series+period. Offline clients receive signed, bounded, expiring sequence reservations tied to a registered device. The server validates reservation, uniqueness and sequence use during sync; gaps, reuse, expired reservations and conflicts create exceptions. Reversal never reuses a number. Final format and legal fields require NamRA approval.

## Data classification and governance

The detailed register is `data-classification-retention.csv`. Classification drives encryption, ABAC, masking, logging, export, residency, retention and deletion. Retention values remain policy placeholders until Namibian tax/privacy/records authority approves them.

Each critical field has business owner, system of record, authoritative source, lineage, quality rule and correction mechanism. Authoritative attribute changes create versioned evidence and downstream events. Bulk export is purpose-bound, bounded, approved where required, watermarked/manifested and monitored. Non-production uses synthetic or irreversibly de-identified data.

## Recovery and migration

Schema changes use backward-compatible expand → migrate/backfill with reconciliation → switch readers/writers → contract. Migration evidence includes counts, hashes, rejects, rollback/forward recovery and owner. Backups are encrypted, immutable and restored/tested; recovery proves referential, ledger, outbox and audit integrity—not only database readability.
