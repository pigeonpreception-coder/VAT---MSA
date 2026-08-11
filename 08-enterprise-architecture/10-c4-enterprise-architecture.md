# Deliverable 02 — C4 enterprise architecture

## Level 1: System context

### Globalisation extension

At Level 1, VAT-MSA serves organizations and authorities across explicitly onboarded jurisdictions. At Level 2 it adds Country Compliance Registry, Jurisdiction, Money/FX, Localization/Calendar and Regulatory Administration containers while country-specific government systems remain behind isolated adapters. At Level 3 the Tax runtime resolves and pins a signed pack/rule version before calculation. See `diagrams/global-country-context.mmd`, `diagrams/country-pack-components.mmd` and `diagrams/multi-country-deployment.mmd`.

### Workspace and organisation-control extension

Level 2 adds Organisation Administration, License/Entitlement, Workflow, Access Governance, Workspace/Navigation and Permission-aware Search containers behind the same gateway and policy plane. Level 3 adds policy projection, entitlement/usage reservation, typed workflow compiler/runtime, SoD evaluator, access-review orchestrator and navigation projection builder components.

These components use domain-owned relational records, transactional outbox, immutable audit/security evidence, bounded caches and rebuildable search projections. No browser or administrator writes protected policy, licence state, tax rules or audit records directly. See `diagrams/workspace-licensing-components.mmd`.

VAT-MSA sits between verified taxpayer organisations, authorised NamRA/platform users and approved external systems. Relationships and trust boundaries are shown in `diagrams/c4-level-1-context.mmd`.

- Humans: taxpayer authorised users, delegated accountants, NamRA officers, NamRA admins, Super Admin/SRE/SOC.
- Government: ITAS identity/taxpayer/period/authorized tax data; customs and regulated payment systems only where confirmed.
- Business integrations: POS, ERP, accounting, retail/financial SaaS and offline desktop clients.
- VAT-MSA: authoritative platform for its accepted invoice IDs, VAT transactions, ledgers, workflow/evidence and platform configuration; it does not replace NamRA statutory authority.

## Level 2: Containers

See `diagrams/c4-level-2-containers.mmd`.

| Container | Responsibility | Interfaces | Data | Trust boundary |
|---|---|---|---|---|
| Web/responsive portals | Buyer, Seller, NamRA, Admin and Developer task experiences | HTTPS to BFF/API | no authoritative browser state | public/client zone |
| Offline desktop | encrypted provisional business work and sync | signed sync API | encrypted local DB/event log | untrusted endpoint/device |
| Protected edge/API gateway | DNS/CDN/DDoS/WAF/bot, TLS, routing, quotas, API policy | HTTPS/mTLS/OAuth | policy/cache metadata | edge zone |
| Identity/policy plane | federation, subject links, MFA assurance, sessions, RBAC/ABAC/PAM | OIDC/SAML/token exchange/policy API | identity/access store | identity zone |
| Taxpayer/organisation application | taxpayer registry, organisations, users, branches, capabilities, consent | REST/events | operational relational | application/data zone |
| Commercial/business application | parties, quotations, invoices, accounting, inventory, expenses, projects | REST/events | operational relational + documents | application/data zone |
| VAT/compliance application | rules, VAT transactions/ledger/periods/returns, reconciliation, cases/risk/refunds | REST/events | high-integrity transaction/ledger | restricted tax zone |
| Integration/developer platform | ITAS/SaaS adapters, apps/clients, sandbox, sync | REST/events/webhooks/files | integration registry/checkpoints | integration zone |
| Event platform | outbox relay, durable topics, DLQ/replay | event protocols | replicated stream | data/integration zone |
| Document platform | encrypted objects, metadata, versions, scan/quarantine | opaque authorized API | object store + metadata | restricted data zone |
| Reporting/analytics | read models, warehouse/lakehouse, governed BI | async ingest/query | analytical store | analytics zone |
| Audit/security platform | immutable audit, SIEM, detections, incidents and response | telemetry/event APIs | WORM/security lake | security zone |
| Platform operations | configuration, feature/deploy/health/observability | management APIs | config/telemetry | management zone |

## Level 3: Components

See `diagrams/c4-level-3-fiscal-components.mmd` for the critical fiscal slice.

| Service boundary | Components | Responsibilities/interfaces/events | Owned data | Primary dependencies/security boundary |
|---|---|---|---|---|
| Identity | Federation Adapter, Token Validator, Identity Resolver, Session/Revocation, MFA/Assurance, Provisioning, Policy Decision Point | authenticate/resolve/evaluate; IdentityLinked, AccessChanged | providers, subjects, sessions, policy metadata | external IdP; isolated identity keys; no tax authority |
| Taxpayer/Organisation | Registration Workflow, Identifier Verification, Organisation, Branch, Membership, Capability, Consent/Delegation | registration/verification/membership APIs; TaxpayerVerified, CapabilityChanged | canonical taxpayer/org and access scope | ITAS adapter; restricted identity/tax data |
| Invoice | Numbering/Reservation, Draft/Approval, Validation, Certification, Adjustment Lineage, Receipt Query | invoice/credit/debit APIs; InvoiceCreated/Certified/Corrected | fiscal documents, lines, sequence, certificates | Tax Rules, parties, HSM; transactional authority |
| VAT/Transaction | Rule Resolver, Calculator, Classifier, VAT Transaction, Ledger Poster, Period Allocator | calculate/post/query; VATTransactionPosted | rule versions, transactions, append-only ledger | Invoice, taxpayer/period; restricted statutory boundary |
| Reconciliation/Returns | Matcher, Exception Workflow, Return Assembler, Validation, Submission Adapter, Period Close | exceptions/returns APIs; MatchCompleted, VATReturnSubmitted | matches, exceptions, return snapshots, submission receipts | VAT, ITAS; authorized workflow/approval |
| Accounting/Business | Quotation, Parties, Accounting, Inventory, Expense, Project | business APIs/events feeding invoice/tax | business records and journals | organisation membership; business/statutory separation |
| Audit/Compliance/Risk | Case, Evidence, Finding, Communication, Taxpayer Compliance, Restricted Risk, Refund Review | case/risk/refund APIs; AuditCaseOpened | cases/findings/risk/refund workflow | NamRA scope/clearance; human decision |
| Integration | App Registry, Credential Metadata, Conformance, ITAS/SaaS Adapters, Sync, Webhook/File Gateway | integration APIs/events | apps, clients, scopes, sync checkpoints | machine identity; sandbox/prod separation |
| Document | Metadata, Object Authorization, Malware Scanner, Version/Retention | upload/download APIs; DocumentAccepted/Quarantined | metadata and object references | object/KMS/scanner; opaque URLs |
| Notification/Communication | Template, Preference, Delivery, Secure Conversation | notice/message APIs; NoticeDelivered | communication/delivery state | approved channels; restricted case content |
| Security/Operations | Telemetry Collectors, Detection, Incident/SOAR, Health/SLO, Feature/Deploy Control | telemetry/management APIs | security and operational evidence | separate administration/no automatic tax scope |

## Level 4: Code architecture (no production code)

Recommended logical structure:

```text
apps/portals/{taxpayer,namra,administration,developer}
apps/offline-desktop
services/{identity,taxpayer,commercial,vat,reconciliation,returns,audit,integration,documents,notifications}
packages/{contracts,domain-kernel,policy-client,observability,security,testing}
platform/{gateway,eventing,data,security,observability}
integrations/{itas,saas,customs,payments}
architecture/{adrs,diagrams,catalogues,traceability}
tests/{unit,contract,integration,e2e,security,performance,resilience,dr}
```

Within a service: `domain` (entities/value objects/policies/events) → `application` (commands/queries/workflows/ports) → `adapters` (API/event/data/provider) → `bootstrap`. Domain imports nothing from frameworks/adapters. Application depends on domain and ports. Adapters implement ports. Cross-domain access uses versioned APIs/events, never another domain's tables.

Shared libraries contain only stable primitives, contract tooling, policy/telemetry clients and test fixtures. They never become a shared business-model database. Security libraries implement safe defaults; authorization remains explicit in each use case/query.

Data access uses prepared/parameterized statements or reviewed repositories, bounded pagination and tenant predicates. Commands own transactions; queries may use governed read models. Domain events are collected during the transaction and written to outbox before commit.
