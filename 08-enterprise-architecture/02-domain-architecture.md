# C. Domain architecture

## Added bounded contexts

| Domain | Authoritative responsibilities | Publishes | Does not own |
|---|---|---|---|
| Licensing and Entitlements | plans, subscriptions, organisation licences, feature grants, limits, usage reservations and licence events | LicenseActivated, LicenseStateChanged, EntitlementChanged | taxpayer/VAT truth, payment-card data or security override |
| Organisation Administration | employees, job titles, positions, departments, reporting lines and administrator appointments | EmployeeChanged, OrganisationAdminChanged | authentication, NamRA staff or licence state |
| Organisation Authorization | organisation roles, permission sets, assignments, capabilities, scopes and financial authority | RoleChanged, PermissionGranted, PermissionRevoked | protected platform/NamRA permissions or policy ceilings |
| Workflow | typed definitions, immutable versions, instances, decisions, delegation and escalation | WorkflowPublished, WorkflowDecisionRecorded, SoDViolationDetected | source transaction authority or historical mutation |
| Access Governance | access requests, approvals, reviews, certifications, dormancy and offboarding orchestration | AccessRequested, AccessCertified, AccessRevoked | historical ownership deletion |
| Workspace and Navigation | versioned hierarchy, policy-filtered projections and user preferences | NavigationConfigurationChanged | endpoint authorization or protected search records |

Dependency order is `Identity/Taxpayer/Organisation -> Licensing -> Organisation Authorization -> Policy -> Workspace/Workflow/Domain action`. A denial from identity, tenant, security, tax or SoD cannot be overridden downstream.

## Bounded contexts and ownership

See `diagrams/domain-map.mmd`.

| Domain | Authoritative responsibilities | Publishes | Does not own |
|---|---|---|---|
| Identity & Access | provider links, subjects, sessions, assurance, users, roles, policies | IdentityLinked, AccessChanged, SessionRevoked | legal taxpayer truth or tax rules |
| Taxpayer & Organisation | canonical taxpayer, organisation, VAT/TIN/company IDs, branches, capabilities, memberships | TaxpayerVerified, OrganisationActivated, CapabilityChanged | authentication credentials |
| Parties | organisation-scoped customers/suppliers and contacts | PartyChanged | canonical NamRA taxpayer registry |
| Commercial | quotations, acceptance, sales/purchases, invoices, adjustments and numbering | QuotationAccepted, InvoiceCreated, CreditNoteCreated | statutory return decisions |
| Accounting | chart, journals, receivables/payables, cash book and statements | JournalPosted, PeriodClosed | statutory VAT ledger |
| Inventory | products, locations, movements, valuation and stock alerts | StockMoved, GoodsSold | invoice certification |
| Expense | capture, category, cost centre, budget and approval | ExpenseApproved | VAT eligibility authority |
| Project | project budgets, costs, revenue, invoicing and profitability | ProjectCostPosted | statutory tax case |
| VAT & Tax Rules | effective tax rules, calculation, transaction classification, VAT period and immutable VAT ledger | VATTransactionPosted, VATPeriodClosed | refund authorization |
| Reconciliation | seller/buyer/invoice/ledger/return matching and exception lifecycle | MatchCompleted, ExceptionOpened | source invoice mutation |
| Returns & Compliance | return assembly, validation, submission state, deadlines and taxpayer compliance actions | VATReturnPrepared, VATReturnSubmitted | NamRA decision outside approved workflow |
| NamRA Risk | restricted indicators, models and review workflow | RiskCaseRaised | taxpayer-facing score details |
| Audit & Dispute | audit cases, requests, evidence, findings, response, decision and closure | AuditCaseOpened, FindingIssued | platform security incidents |
| Communication & Notification | secure notices, conversations, reminders and delivery state | NoticeDelivered, ResponseReceived | authoritative case/tax state |
| Documents | object metadata, versions, classification, retention, scan and access | DocumentAccepted, DocumentQuarantined | public predictable object URLs |
| Consent & Delegation | accountant/practitioner grants, scopes, expiry and revocation | DelegationGranted, DelegationRevoked | password sharing |
| Integration & Developer | SaaS apps, API clients, credentials metadata, conformance, sync and connectors | IntegrationApproved, SyncFailed | production secrets in relational records |
| Reporting & Analytics | read models, aggregates, trends and governed extracts | ReportCompleted | operational transaction authority |
| Security Operations | security events, detections, incidents and controlled response | SecurityIncidentOpened | taxpayer compliance adjudication |
| Platform Operations | configuration, feature flags, deployment and health | FeatureChanged, ServiceDegraded | taxpayer financial read scope by default |

## Service decomposition

The 25 requested services map to these contexts: Identity; Taxpayer; Organisation; User; Role/Permission; Invoice; Quotation; VAT; Transaction; Accounting; Inventory; Expense; Project; Reconciliation; Audit; Risk; Document; Notification; Reporting; Analytics; Integration; API Management; Developer; Compliance; Communication. Separate code ownership and contracts exist even when multiple modules initially share a deployment.

## Shared kernel

Only stable primitives are shared: immutable identifiers, money/currency, date/period, organisation/taxpayer references, correlation/event metadata, actor/purpose and classification. Domains never share mutable ORM entities. Contract changes are versioned and backward-compatible.

## Aggregate and transaction boundaries

- Taxpayer aggregate: identifiers, registration state and authoritative sources.
- Organisation aggregate: taxpayer link, branches, memberships and buyer/seller capabilities.
- Fiscal document aggregate: invoice identity, lines, totals, certification and adjustment lineage.
- VAT transaction aggregate: classification, rule version, period and immutable postings.
- VAT return aggregate: taxpayer/period snapshot, adjustments, validation and submission state.
- Case aggregate: assignment, requests, evidence, findings, review and closure.
- Integration aggregate: owner, environment, scopes, credentials metadata, quota and conformance.

Only invariants inside one aggregate are committed atomically. Cross-aggregate outcomes are reconciled using outbox events and idempotent handlers. Ledger and audit append-only records are never updated to hide history.

## Critical state machines

- Registration: DRAFT → SUBMITTED → IDENTITY_PENDING → TAXPAYER_VERIFIED → MFA_REQUIRED → APPROVED → ACTIVE; mismatch/suspension transitions to REVIEW or REJECTED.
- Invoice: DRAFT → APPROVED → RECEIVED → VALIDATED → CERTIFIED → MATCHED; later CANCELLED/REVERSED only through linked controlled actions.
- Input VAT: RECORDED → MATCHED → VALIDATED → ELIGIBLE → CLAIMED, or REJECTED/UNDER_REVIEW.
- VAT period: OPEN → CLOSING → RECONCILING → READY → SUBMITTED → ACCEPTED/REJECTED → CLOSED.
- Integration: REGISTERED → SANDBOX → CONFORMANCE_REVIEW → APPROVED → ACTIVE → SUSPENDED/REVOKED.

## Anti-corruption layers

ITAS, customs, banks, ERP/POS and future providers connect through adapters that translate external schemas/identifiers into canonical commands/events. An external field never silently becomes authoritative; ownership, verification time, source and confidence are recorded. Provider outages queue or reject work according to the criticality policy without inventing successful statutory state.
