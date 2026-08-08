# VAT-MSA Enterprise Architecture Blueprint

## National VAT Transaction, Reconciliation, Compliance and Audit Platform

**Version:** 1.0 architecture baseline  
**Status:** Proposed for discovery, legal validation and Architecture Review Board approval  
**Prepared:** 8 August 2026  
**Primary audience:** NamRA executives, VAT policy and operations, enterprise architecture, security, data governance, procurement, delivery partners and software engineering teams

> **Purpose.** This is a development blueprint, not another ideation prompt. It defines system boundaries, domain ownership, transaction integrity, contracts, controls, deployment evolution, implementation gates and the decisions that must be resolved before VAT-MSA can safely become a national platform.

## Document control

| Field | Value |
|---|---|
| Architecture scope | Electronic invoicing, VAT transaction processing, matching, sub-ledger, return support, compliance, audit, risk, integration, offline operation and national-scale platform services |
| Proposed business owner | Namibia Revenue Agency (to be formally assigned) |
| Proposed design authority | VAT-MSA Architecture Review Board |
| Legal authority | Value-Added Tax Act, 2000 and effective amendments, regulations, schedules, notices and approved NamRA policy |
| Related system | ITAS - authoritative boundary to be confirmed in Gate 0 |
| Review cycle | At every material law/rule change and at least quarterly during delivery |
| Approval state | Not yet approved; all requirements marked provisional until accepted by the responsible authority |

## How to use this blueprint

The blueprint is deliberately layered. Executives can use Chapters 1, 2 and 17 to make scope and investment decisions. Architects and product owners can use Chapters 3 to 16 to govern solution design. Engineering teams should implement against the machine-readable contracts and reference schema packaged with this document, not by copying diagrams into code. Legal, policy, security, records and data-governance teams must close the decisions in Chapter 18 before production.

The accompanying repository is part of the deliverable. It contains editable Mermaid diagrams, OpenAPI and JSON Schema contracts, an event catalogue, a PostgreSQL reference schema, a data dictionary, RBAC and security matrices, non-functional requirements, test gates, roadmap and architecture decision register.

## Contents

1. Executive architecture overview  
2. Business architecture and capability map  
3. Stakeholders, value streams and operating model  
4. System context and application architecture  
5. Domain architecture and module breakdown  
6. End-to-end VAT transaction architecture  
7. VAT sub-ledger and accounting control architecture  
8. Matching, reconciliation and exception architecture  
9. VAT return and statutory co-existence architecture  
10. NamRA compliance, audit, risk and refund architecture  
11. Integration, API, event and SaaS connector architecture  
12. Data, database, analytics and reporting architecture  
13. Security, IAM, privacy and evidence architecture  
14. Offline and desktop architecture  
15. Infrastructure, cloud, resilience and observability architecture  
16. DevSecOps, repository and testing architecture  
17. Implementation roadmap: MVP to production to national scale  
18. Governance, decisions and next actions  
Appendices: API catalogue, data model, RBAC, NFRs, test strategy, glossary and sources

[[PAGEBREAK]]

# 1. Executive Architecture Overview

## 1.1 Executive decision

VAT-MSA should be established as Namibia's controlled VAT transaction platform between taxpayer source systems and NamRA tax administration. It should certify electronic fiscal documents, create transaction-level VAT positions, reconcile seller output VAT with buyer input VAT, support return preparation and provide reliable evidence for compliance, audit, risk and refund decisions.

VAT-MSA should not become a national ERP. Accounting, inventory, point of sale and commercial workflows remain in taxpayer systems or optional portal modules. ITAS remains the presumptive authority for taxpayer accounts, filing and statutory account outcomes until NamRA formally approves a different boundary.

> **Architecture decision.** Build a modular platform with bounded business domains, explicit APIs and versioned events. Start with a small number of independently governed deployments and a transactional outbox. Extract services only where load, availability, security isolation or team ownership justifies the operational cost. "Not one large application" does not require dozens of premature microservices.

## 1.2 Mission outcomes

- Increase the integrity and timeliness of VAT transaction data.
- Give taxpayers rapid, deterministic validation and reusable compliance evidence.
- Link the seller and buyer consequences of the same certified document.
- Build reproducible VAT return drafts from a governed sub-ledger.
- Detect mismatches, duplicates and unusual claims early without silently changing taxpayer records.
- Equip NamRA officers with explainable evidence, controlled workflows and segregation of duties.
- Support large integrated taxpayers, SMEs, portal users and low-connectivity environments.
- Provide a scalable, secure and recoverable national service without coupling tax logic to a vendor or user interface.

## 1.3 System-of-record boundaries

| Information or outcome | Proposed authority | Boundary rule |
|---|---|---|
| Legal taxpayer account, registration and filing status | ITAS / NamRA master | VAT-MSA consumes effective-dated references and never silently changes the authoritative account. |
| Source commercial invoice | Seller source system | VAT-MSA stores the submitted canonical representation and source identity. |
| Certified fiscal document and certificate | VAT-MSA | Accepted records are immutable; later changes are linked corrections/reversals. |
| VAT transaction and VAT-MSA sub-ledger | VAT-MSA | Posting is atomic with acceptance/certification and remains reproducible. |
| Statutory VAT return submission and account outcome | ITAS, pending Gate 0 confirmation | VAT-MSA drafts/reconciles and submits through an approved interface. |
| Audit case and legal determination | NamRA audit systems/process, boundary to confirm | VAT-MSA provides case workspace and evidence; final authority follows approved operating policy. |
| ERP general ledger, receivables, payables and inventory | Taxpayer ERP/accounting system | VAT-MSA integrates but does not replace commercial accounting. |

## 1.4 Architecture principles

1. **Law and policy are versioned inputs.** No VAT rate, invoice particular, eligibility rule or return formula is scattered through application code.
2. **One certified document, one transaction identity.** Idempotency and duplicate controls prevent repeated business effects.
3. **Evidence is immutable.** Corrections add linked records; they never rewrite accepted history.
4. **Transaction and ledger commit together.** A certificate cannot exist without its controlled VAT posting.
5. **Events are durable but not magical.** Delivery is at least once, consumers are idempotent and completeness is reconciled.
6. **Identity is explicit.** People, organisations, systems, workloads and devices each have strong, scoped identities.
7. **Taxpayer data is protected by purpose.** Network location alone grants no trust; access is least privilege and audited.
8. **Risk supports decisions.** Models and scores do not impose unexplained adverse outcomes.
9. **National scale is measured.** Capacity, partitioning and service extraction follow evidence from volume and failure testing.
10. **Operations are designed with the software.** Monitoring, runbooks, backup, recovery, support and evidence are release requirements.

## 1.5 Current public context and assurance caveat

NamRA's public ITAS service already supports e-filing, taxpayer account access and VAT returns. NamRA also publicly described an e-invoicing initiative intended to enable real-time invoice generation/validation and previously announced an April 2026 rollout target. Publicly available material reviewed for this blueprint does not, by itself, establish the current production mandate, exact interface, rollout cohort or legal status of every electronic-invoice state. Gate 0 therefore treats those as confirmation items, not assumptions hidden in code.

The VAT Act and approved subordinate instruments remain controlling. The 15 percent standard rate is represented only as a configurable, effective-dated seed value in examples; legal validation and the authoritative rule pack are mandatory. A Data Protection Bill was introduced in Parliament in June 2026, so the platform should adopt privacy and sovereignty controls now while legal counsel tracks the final enacted obligations and commencement.

[[FIGURE:01-system-context.png|Figure 1. VAT-MSA system context and principal external relationships.]]

## 1.6 Target architecture at a glance

The taxpayer ecosystem connects through an API and integration gateway. The invoice core validates identity, schema, document rules and arithmetic, then commits the certified document, VAT transaction, balanced sub-ledger entries and outbox event in a controlled transaction. Matching, exception, risk, audit and analytical services consume authoritative events. ITAS, customs/import and payment/refund controls integrate through governed government interfaces. IAM, PKI/HSM, security operations, platform operations and data governance protect every layer.

The runtime is divided into seven logical layers:

1. Experience applications.
2. Edge and integration.
3. Domain services.
4. Transaction and event backbone.
5. Operational, ledger and evidence data.
6. Matching, risk and analytics.
7. Platform, security and operations.

[[FIGURE:02-platform-layers.png|Figure 2. Seven-layer logical platform architecture.]]

[[PAGEBREAK]]

# 2. Business Architecture and Capability Map

## 2.1 Business capability map

| Level 1 capability | Level 2 capabilities | Target maturity at national launch |
|---|---|---|
| Taxpayer and partner management | Registration reference, identity resolution, organisation access, API client onboarding, device enrolment, partner certification | Governed and largely automated |
| Electronic invoicing | Submission, schema/business validation, duplicate detection, tax calculation, certification, QR verification, correction chain | Real-time with offline recovery |
| VAT transaction control | Transaction creation, seller output position, buyer input candidate, imports, adjustments, reversals, period assignment | Transaction-level and auditable |
| VAT sub-ledger | Balanced posting, period balances, close/reopen, carry-forward, adjustment, return snapshot | Reproducible and reconciled |
| Matching and reconciliation | Exact/probabilistic matching, counterparty confirmation, invoice-to-ledger, ledger-to-return, return-to-ITAS, import matching | Continuous and exception-driven |
| VAT return support | Period aggregation, rules, draft, taxpayer review, approval, submission adapter, response and amendment | Co-exists safely with ITAS |
| Compliance and exception management | Queueing, evidence request, taxpayer response, resolution, escalation, service levels | Case-managed and measurable |
| Audit management | Selection, planning, electronic audit file, evidence chain, findings, review, decision and appeal support | Role-separated and evidential |
| Risk and refund assurance | Rules, features, alerts, explainability, model governance, refund screening and referral | Human-governed decision support |
| Data and reporting | Operational reporting, statutory/regulatory reports, warehouse, graph, quality, lineage and controlled research | Governed self-service by role |
| Platform and security | IAM, PKI/HSM, monitoring, SOC, DevSecOps, capacity, backup, DR, service management | National critical-service discipline |
| Architecture and change governance | Rules governance, schema/API lifecycle, ADRs, vendor governance, release approval, benefits measurement | Permanent design authority |

## 2.2 Scope by experience

### Seller experience

The seller application supports invoice submission/status, sales views, customers, certification receipts, correction documents, exceptions, integration health, VAT period summaries, reports and organisation settings. Optional accounting/inventory features belong in a separate taxpayer-service module and may not become dependencies of national certification.

### Buyer experience

The buyer application supports certified purchase visibility, supplier views, input VAT candidates, confirmation/dispute, exceptions, evidence, return support and reporting. Buyers with integrated accounting systems use APIs/connectors; the portal exists for organisations without integration.

### NamRA experience

NamRA applications include taxpayer and registration views, invoice/transaction search, returns/reconciliation, exception operations, risk alerts, audit cases, refund controls, integrations, import data, reports, analytics, system health and administration. Access follows portfolio, case, organisational unit and purpose, not a broad "officer can see everything" rule.

### System administration

System administration is segregated from tax administration. Platform operators manage deployments, configuration, health and backups but have no standing access to full tax-confidential payloads. Security administrators manage policies and keys through dual control. Tax rules require business/legal approval and cannot be activated by infrastructure administrators.

## 2.3 Capability ownership

Each capability has one accountable business owner and one technical owner. Cross-domain workflows may have several participants but cannot have shared, ambiguous data ownership. The proposed accountable owners are:

- VAT Policy: rule meaning, invoice particulars, tax point, eligibility and return mapping.
- Taxpayer Services/Data Stewardship: taxpayer master and identity-resolution outcomes.
- Domestic Taxes Operations: taxpayer workflows, exception service levels and adoption.
- Audit Directorate: audit case process and evidentiary requirements.
- Risk/Refund Governance: risk appetite, model/rule approval and intervention thresholds.
- Enterprise Architecture: platform boundaries, contracts and decision register.
- CISO/Security Operations: security policy, threat detection and incident response.
- Data Governance: classification, quality, lineage, access and analytical use.
- Platform/SRE: availability, capacity, observability, backup and recovery.

## 2.4 Benefits and measures

Benefits should be measured against a pre-pilot baseline:

| Outcome | Example measure | Guardrail |
|---|---|---|
| Better filing accuracy | Difference between transaction-derived draft and accepted return | Do not count taxpayer corrections as enforcement success without context |
| Faster issue resolution | Median and p90 exception age by type/cohort | Protect response/appeal time and avoid automatic adverse closure |
| Lower invoice fraud/duplication | Confirmed duplicates and prevented duplicate VAT effect | Measure false positives and reversals |
| Faster refund assurance | Time from complete refund case to decision | Maintain independent approval and taxpayer rights |
| Higher digital adoption | Active conformant taxpayers/partners and transaction coverage | Track small/low-connectivity taxpayer burden |
| Improved availability | Business-journey SLO and error-budget performance | Do not substitute host uptime for successful certification |
| Better audit targeting | Yield, cycle time and explainability by risk strategy | Monitor bias, appeal and non-risk-based quality samples |

[[PAGEBREAK]]

# 3. Stakeholders, Value Streams and Operating Model

## 3.1 Stakeholders

| Stakeholder | Need | Architecture response |
|---|---|---|
| VAT-registered sellers | Quick predictable certification; low integration cost; clear correction path | Versioned contracts, conformance sandbox, idempotency, actionable errors, adapters and portal |
| VAT-registered buyers | Trusted purchase evidence and fair mismatch workflow | Buyer input candidates, counterparty matching, dispute/evidence process and scoped views |
| Consumers/non-registered buyers | Verifiable invoice without unnecessary data exposure | Public QR verification with minimal fields |
| Accountants/tax practitioners | Delegated access across clients and reproducible period evidence | Explicit representation/delegation model, client scope, export controls and audit |
| POS/ERP/SaaS providers | Stable national contract and predictable change | Canonical schema, developer portal, test certificates, version policy and transition windows |
| NamRA compliance officers | Prioritised queues and complete context | Explainable exceptions, taxpayer history, evidence and supervised outcomes |
| NamRA auditors | Defensible transaction chain and case workflow | Electronic audit file, immutable evidence and role-separated decisions |
| Refund officers/approvers | Accurate claim support and fraud indicators | Ledger-to-return-to-invoice trace, risk reasons and dual control |
| Security and internal audit | Detectable misuse and provable control operation | Zero trust, append-only audit, SIEM and access certification |
| Leadership and policy | Reliable national insight and safe change | Governed warehouse, quality measures, rule versioning and architecture board |

## 3.2 Primary value stream: invoice to statutory outcome

1. A taxpayer or enrolled source system creates a fiscal document.
2. The gateway authenticates the organisation/system/device, checks scope and enforces resource limits.
3. VAT-MSA converts the payload to the canonical model and validates schema, taxpayer identity, document particulars, duplicates, arithmetic and effective VAT rules.
4. On acceptance, VAT-MSA commits the immutable invoice, VAT transaction, balanced ledger postings and durable outbox event.
5. The certification service signs the receipt and returns a verification token/QR.
6. Matching identifies or awaits the buyer-side record and records a versioned match decision.
7. Differences become controlled exceptions with evidence, response, resolution and escalation.
8. The VAT sub-ledger aggregates an approved period snapshot into a reproducible return draft.
9. Taxpayer review/approval and the ITAS adapter complete the statutory co-existence workflow.
10. Risk, compliance, audit and refund services consume explainable evidence under role and purpose controls.

## 3.3 Operating queues

The national operating model requires explicit queues rather than shared inboxes:

- Taxpayer identity ambiguity.
- Schema/partner conformance failure.
- Repeated certification failure or suspected abuse.
- Seller/buyer unmatched and amount/tax mismatch.
- Late/offline sequence gap or device-integrity failure.
- Import/customs mismatch.
- Closed-period adjustment and return difference.
- High/critical risk referral.
- Refund case preparation and independent approval.
- Security, privacy and data-quality incident.

Every queue has an owner, severity, service target, escalation path, allowed outcomes and evidence requirements. Queue actions are business events and audit events; comments alone are not an adequate system of record.

[[PAGEBREAK]]

## 3.4 Governance forums

| Forum | Cadence | Decisions |
|---|---|---|
| Executive Steering Committee | Monthly, then quarterly in steady state | Mandate, funding, policy escalation, national rollout and benefit realisation |
| Architecture Review Board | Fortnightly during build | ADRs, boundaries, standards, exceptions, service extraction and vendor designs |
| VAT Rules Board | On change and before each rule release | Legal interpretation, examples, effective dates, conformance pack and rollback |
| Data Governance Council | Monthly | Ownership, quality, lineage, access, retention and analytical use |
| Security and Privacy Review | Continuous gate plus monthly | Threats, DPIA, control exceptions, incidents and high-risk suppliers |
| Release Readiness Board | Per production release/wave | Evidence pack, defects, capacity, operations, support and rollback |
| Model Risk Committee | Per model and quarterly monitoring | Purpose, validation, thresholds, drift, bias, override and retirement |

[[PAGEBREAK]]

# 4. System Context and Application Architecture

## 4.1 External systems and trust boundaries

VAT-MSA accepts transactions from untrusted public networks and semi-trusted partner networks. No source is trusted merely because it is a large ERP vendor or government system. The gateway authenticates the caller, validates the authorised taxpayer and operation, enforces schemas and limits, and adds trace context. Government interfaces use separate network and identity profiles but the same explicit trust principle.

External relationships include:

- ITAS taxpayer master, registration, filing, account and submission outcomes.
- Customs/import platforms for import declaration and VAT evidence.
- Government identity, PKI/HSM, security monitoring and records services.
- Payment/refund controls and, if authorised, treasury/banking interfaces.
- Taxpayer POS, ERP, accounting SaaS and certified service providers.
- Seller/buyer portals and the enrolled offline desktop client.
- Notification services for email, SMS or other approved channels.
- Public certificate verification with privacy-minimised data.

## 4.2 Application portfolio

| Application | Users | Core functions | Deployability |
|---|---|---|---|
| Seller Portal | Seller users/agents | Invoice status, certificates, corrections, exceptions, summaries, integrations | Independent web front end; shared BFF/API |
| Buyer Portal | Buyer users/agents | Purchases, input candidates, confirm/dispute, evidence, return support | Independent web front end; shared BFF/API |
| NamRA Operations | Officers/supervisors | Search, exceptions, taxpayer context, return reconciliation, queues | Independent web app and role-specific APIs |
| Audit and Risk Workbench | Auditors, risk/refund roles | Cases, evidence, alerts, model/rule explanations, approvals | Isolated high-sensitivity app/API boundary |
| System Administration | Platform/security/integration admins | Configuration, clients, connector health, policy, monitoring | Separate privileged access path |
| Public Verification | Public | Validate certificate/QR and status | Internet edge service with minimal data |
| Offline Desktop | Enrolled taxpayers | Local issue/queue/status and secure sync | Signed managed desktop distribution |
| Developer Portal | SaaS/ERP/POS partners | Documentation, sandbox, keys, conformance, status | Separate portal; no production tax data |

## 4.3 Logical layers

### Experience layer

Role-specific applications use a design system, accessible components, constrained exports and back-end-for-front-end endpoints where aggregation is useful. User interfaces never duplicate tax calculations; they display server-provided rule evidence.

### Edge and integration layer

The edge provides DDoS protection, WAF, API gateway, authentication, authorisation hooks, schema and content controls, rate limiting, idempotency admission, routing and observable partner-specific policy. The integration layer adds canonical mapping, adapters, managed file transfer where APIs are unavailable and quarantine/replay operations.

### Domain services layer

Domain modules own business state and enforce invariants. Cross-domain data is accessed through APIs/events or purpose-built read models, not shared table writes.

### Transaction and event backbone

Workflow coordinates long-running processes; the rules service evaluates approved effective-dated rules; the transactional outbox makes committed events durable; the broker decouples matching, analytics, notifications and cases.

### Data and intelligence

Operational stores, VAT sub-ledger, evidence storage, search, warehouse and graph projections are separated by workload and control need. Analytical models consume governed features; they never update fiscal truth directly.

### Platform and security

IAM, PKI/HSM, secrets, workload identity, service mesh/network policy, containers, CI/CD, observability, SOC, backup and DR are shared platform products with owners and SLOs.

## 4.4 Deployment evolution

The logical architecture is more granular than the initial physical deployment. A sensible starting point is:

- One edge/integration deployment.
- One transaction-core deployment containing Taxpayer, Invoice, VAT Transaction, Certification and VAT Ledger modules with strict internal boundaries.
- One compliance deployment containing Matching, Exceptions, Return workflow and notifications.
- One high-sensitivity NamRA deployment for audit, risk and refund cases.
- Separate identity/platform services, broker, operational data, evidence store, search and analytics.

Extraction triggers include sustained independent load, different availability/RTO, high-sensitivity isolation, a stable high-volume contract, independent release ownership or a failure domain that cannot be controlled inside the current deployment. Each extraction preserves business identifiers and event semantics; it is not a rewrite excuse.

[[PAGEBREAK]]

# 5. Domain Architecture and Module Breakdown

## 5.1 Bounded contexts

| Domain | Owns | Principal commands | Principal events |
|---|---|---|---|
| Identity and Taxpayer | Canonical internal taxpayer identity, effective-dated external identifiers, resolution decisions | Synchronise taxpayer; resolve ambiguity; merge identity | TaxpayerUpdated; TaxpayerResolved |
| Partner and Device | API clients, source systems, partner conformance, offline devices | Enrol system/device; rotate credential; suspend | SourceActivated; DeviceRevoked |
| Invoice | Canonical fiscal document, lines, status and duplicate outcome | Submit; validate; reject; accept; correct reference | InvoiceReceived; InvoiceRejected; InvoiceAccepted |
| Rules | Approved rule sets, effective dates, expressions and tests | Draft; approve; activate; retire; evaluate | RuleSetActivated |
| Certification | Certificate, signature, QR token and revocation status | Certify; verify; revoke/reverse | InvoiceCertified; CertificateRevoked |
| VAT Transaction | Seller/buyer VAT relationship and tax point | Create transaction; reverse | VATTransactionCreated; VATTransactionReversed |
| VAT Ledger | Balanced entries, accounts, period assignment and cutoff | Post; reverse; assign period; close | VATPosted; VATPeriodClosed |
| Reconciliation | Match candidates, decisions, confidence and evidence | Match; rematch; supersede decision | MatchResolved; MatchConflict |
| Exception | Controlled discrepancy case and taxpayer interaction | Open; assign; respond; resolve; escalate | ExceptionOpened; ExceptionResolved |
| Return | Period snapshot, draft, review, approval and submission state | Build draft; approve; submit; amend | ReturnDrafted; ReturnSubmitted |
| Import | Customs/import evidence and import VAT matching | Ingest declaration; match import | ImportMatched; ImportExceptionOpened |
| Risk | Rules/models, features, alerts, explanations and monitoring | Score; alert; dismiss; refer | RiskAlerted; RiskAlertReferred |
| Audit | Audit case, evidence manifest, findings and review | Open case; collect evidence; decide; close | AuditCaseOpened; AuditDecisionRecorded |
| Refund | Refund case, controls, approvals and payment reference | Prepare; approve; reject; release | RefundApproved; RefundReferred |
| Reporting | Governed operational/read models and statutory outputs | Generate; schedule; export | ReportGenerated |
| Notification | Templates, preferences and delivery evidence | Send; retry; suppress | NotificationDelivered; NotificationFailed |

## 5.2 Aggregate and invariant examples

### Invoice aggregate

- The source identity and canonical hash cannot change after acceptance.
- Invoice number uniqueness is evaluated in supplier, date/document-type and source context under approved policy.
- Credit/debit notes reference an eligible original and carry a reason.
- Line/tax breakdown reconciles to totals under the approved rounding policy.
- Status transitions follow the lifecycle; administrators cannot bypass them with direct database updates.

### VAT transaction aggregate

- One accepted invoice produces one active VAT transaction identity.
- Seller and buyer consequences reference the same transaction.
- A non-registered/unknown buyer does not receive an eligible input VAT posting merely because a seller supplied an identifier.
- Reversal is explicit and cannot exceed the remaining unreversed amount.

### VAT ledger posting

- Debits equal credits per transaction and currency.
- Posted entries are append-only.
- Every entry references a business source and rule evidence.
- A period cutoff creates a reproducible snapshot; late events follow adjustment/amendment policy.

### Rule set

- Only approved rule sets become active.
- Effective intervals cannot overlap for the same rule purpose unless an explicit priority/selection rule exists.
- Every rule release carries an approved conformance pack and rollback plan.
- Historical evaluation uses the version stored with the transaction, not today's rule.

## 5.3 Command-query separation

Commands enforce business invariants in the owning domain. Queries use read models optimised for seller, buyer, NamRA and analytical views. A query/reporting model may duplicate data, but it is rebuildable and never becomes a hidden source of fiscal truth.

## 5.4 Workflow coordination

Short atomic actions remain inside a domain transaction. Cross-domain flows use events and process managers/sagas with visible state, deadlines and compensation. For example, certification and ledger posting remain inside the strong consistency boundary, while matching, notification, search and analytics are eventual. A failed notification never rolls back a certified invoice.

## 5.5 Module catalogue

The minimum implementation modules are:

- Authentication adapter and policy enforcement point.
- Taxpayer master synchronisation and resolution.
- Partner/API client and source-system registry.
- Canonical mapping and schema validation.
- Invoice lifecycle and duplicate control.
- VAT calculation/rules evaluation and evidence.
- Certification, signature and QR verification.
- VAT transaction and sub-ledger posting.
- Matching and exception workflow.
- Period, return draft and ITAS submission adapter.
- Import/customs reconciliation.
- Compliance, audit, risk and refund workspaces.
- Notifications and delivery evidence.
- Operational reporting, search and exports.
- Audit event, evidence object storage and legal hold.
- Offline device enrolment and synchronisation.
- Platform administration, observability and support tooling.

[[PAGEBREAK]]

# 6. End-to-End VAT Transaction Architecture

## 6.1 Fiscal document lifecycle

[[FIGURE:03-invoice-lifecycle.png|Figure 3. Controlled fiscal document lifecycle.]]

The gateway durably admits a submission only after identity, scope, payload size and basic media checks. The Invoice Domain records receipt, then validates canonical schema, supplier/customer identity, registration/effective status, required particulars, uniqueness, line arithmetic, tax classification and effective rules.

A rejection contains stable machine codes, a human message, the affected JSON Pointer and rule/source reference. A corrected resubmission is a new operation with preserved linkage to the rejected attempt. A successful acceptance creates the fiscal record, VAT transaction, balanced VAT postings and outbox event as one controlled commit; the certificate is then signed with an HSM-protected key. Production implementation may sign inside the transaction boundary or use a reservation/finalisation protocol, but it must never return "certified" before the certificate and postings are durable.

## 6.2 Synchronous transaction sequence

[[FIGURE:04-transaction-processing.png|Figure 4. Synchronous certification and asynchronous downstream processing.]]

The client sends a stable idempotency key. VAT-MSA hashes the canonical request and records the operation state. Exact retries return the original outcome. Reuse of the same key with a different request is rejected. Business duplicate detection still runs even when a different idempotency key is used.

The synchronous response contains the VAT-MSA invoice ID, VAT transaction ID, certificate ID, certification time, applied rule-set version, canonical hash, signature and verification URL/QR payload. Downstream matching, search, notification, risk and analytics consume committed events and do not extend the taxpayer's certification latency.

## 6.3 Validation stages

| Stage | Examples | Failure handling |
|---|---|---|
| Admission | Authentication, taxpayer scope, rate/payload limits, supported schema | Reject before durable business processing or return retryable overload |
| Structural | JSON Schema, types, formats, required fields, unknown fields | 422 with exact path and schema version |
| Identity | Supplier/customer identifiers, effective VAT status, source enrolment | Reject or route ambiguous buyer to controlled policy path |
| Fiscal document | Number, date, parties, currency, original reference, mandatory particulars | Reject with legal/rule reference |
| Arithmetic | Quantity x price, allowances/charges, taxable totals, VAT, payable amount, rounding | Reject beyond tolerance; record computed comparison |
| Tax rules | Rate/category, exemption/zero-rate, input/output treatment, tax point | Reject or warn according to approved rule severity |
| Duplicate/fraud controls | Source identity, fiscal key, canonical hash, sequence anomaly | Return original outcome, reject duplicate or open supervised exception |
| Certification | Hash, signing key, certificate, verification token | Fail closed; no certified status without durable proof |

## 6.4 Canonicalisation and hashing

JSON objects must be canonicalised using an approved deterministic scheme before hashing and signing. The hash covers the fiscal payload and selected certification metadata, not transport headers that may change. The canonicalisation version is stored with the certificate. Any display/PDF rendering is derived from the canonical record and cannot be the only signed evidence.

## 6.5 VAT transaction example

For an illustrative standard-rated invoice with taxable value N$100,000 and VAT N$15,000:

| Record | Taxpayer | VAT account | Debit | Credit |
|---|---|---|---:|---:|
| Seller VAT position | Seller | VAT control | N$15,000 | - |
| Seller output liability | Seller | Output VAT | - | N$15,000 |
| Buyer input candidate | Buyer | Input VAT | N$15,000 | - |
| Buyer VAT control | Buyer | VAT control | - | N$15,000 |

The buyer entries are eligibility candidates until buyer identity and input-tax rules are satisfied. The paired seller and buyer views reference the same VAT transaction ID. The general ledger treatment remains in each taxpayer's accounting system; this is the VAT-MSA sub-ledger control model.

## 6.6 Corrections, cancellations and reversals

- A credit note reduces the remaining eligible amount and references the original invoice.
- A debit note increases it under approved legal conditions.
- Cancellation/reversal is allowed only in defined states/reasons and produces linked reversing entries.
- A replacement invoice has its own identity and explicitly links the superseded document.
- The certificate verification endpoint shows valid, reversed or revoked status without deleting history.
- Closed-period consequences follow the approved amendment/adjustment policy; technical convenience may not rewrite a filed period.

## 6.7 Certification and QR verification

The certificate includes a canonical hash, signing algorithm, key ID, certification time, document/transaction IDs and status. Signing keys are non-exportable and protected by HSM quorum procedures. Rotation preserves verification of older certificates. Compromise activates revocation, incident and re-certification policy.

The public QR token is opaque and unguessable. The public response reveals only the fields approved for fraud verification, for example validity/status, supplier display name, masked invoice number, certification time, total and currency. Full parties, line items, identifiers and tax position require authenticated, authorised access.

[[PAGEBREAK]]

# 7. VAT Sub-Ledger and Accounting Control Architecture

## 7.1 Purpose and boundary

The VAT sub-ledger is a controlled tax ledger, independent of taxpayer general ledgers. It records VAT-MSA's transaction evidence for output VAT, eligible/input candidates, import VAT, adjustments, credit/debit notes, reversals, carry-forwards and refund control. It does not post cash, revenue, receivables, inventory or the rest of a taxpayer chart of accounts.

## 7.2 Account model

| Account | Purpose | Typical source |
|---|---|---|
| Output VAT | Seller VAT liability generated by taxable supplies | Certified invoice/debit note |
| Input VAT | Buyer input VAT candidate/eligible position under approved rules | Matched certified purchase/credit note |
| Import VAT | Import VAT evidence subject to customs matching and eligibility | Customs/import declaration and payment evidence |
| VAT control | Balancing control for taxpayer VAT position | VAT transaction posting |
| Adjustment | Approved period or statutory adjustments | Authorised adjustment workflow |
| Carry-forward | Approved credit/balance movement between periods | Period close/return outcome |
| Refund control | Approved/referral position pending statutory refund outcome | Return/refund workflow |

Each posting stores taxpayer, transaction, invoice, period, account, debit/credit, amount, currency, rate, source, rule version, status, timestamp and reversal reference. Production posting procedures enforce balance per transaction and currency.

## 7.3 Posting and period assignment

The tax point determines the candidate period using effective taxpayer frequency and the approved calendar. Assignment is deterministic and explainable. If master data arrives late or changes retrospectively, the system opens a controlled reassignment/adjustment workflow rather than directly moving posted history.

Period states are OPEN, CLOSING, CLOSED, FILED and REOPENED. Closing performs:

1. Invoice-to-transaction-to-ledger completeness checks.
2. Unbalanced, reversed and unassigned entry checks.
3. Matching/exception ageing and materiality assessment.
4. Import evidence reconciliation.
5. Cutoff capture and reproducible balance snapshot.
6. Authorised closure with audit evidence.

## 7.4 Ledger integrity controls

- Posting is performed only through the owning domain service/procedure.
- Direct UPDATE/DELETE is denied to application and administrator roles.
- Reversal creates equal and opposite entries referencing the original.
- A transaction-level balance control blocks partial postings.
- Sequence, timestamp and hash controls detect missing or altered evidence.
- Daily control totals reconcile document count/tax amount to transactions, entries, certificates and published events.
- The return snapshot records the ledger cutoff, rule version, adjustment set and calculation hash.

## 7.5 Relationship to taxpayer accounting

Connectors may return a certificate ID, VAT transaction ID and posting metadata to the source ERP. Taxpayers can reconcile their general-ledger VAT accounts to VAT-MSA, but VAT-MSA never assumes that a commercial journal is correct merely because it balanced. Differences are reported, not silently overwritten in either system.

[[PAGEBREAK]]

# 8. Matching, Reconciliation and Exception Architecture

## 8.1 Two-sided transaction graph

[[FIGURE:05-reconciliation-graph.png|Figure 5. Seller/buyer transaction graph and exception path.]]

Matching is a versioned business decision over seller, buyer, document and VAT evidence. The seller invoice is not copied into a buyer's claim as if the two roles were identical. VAT-MSA creates a buyer input candidate, applies identity and eligibility rules, and records whether the buyer confirmed, disputed or supplied a corresponding record.

## 8.2 Matching hierarchy

| Level | Relationship | Primary evidence | Outcome |
|---|---|---|---|
| 1 | Invoice to seller | Authenticated source, supplier identity, number and certificate | Seller attribution |
| 2 | Invoice to buyer | Buyer identifier, registration/effective status, buyer confirmation/source record | Buyer attribution/input candidate |
| 3 | Seller output to buyer input | Shared transaction/document IDs, totals, tax categories/rates, date/currency | Matched, partial, conflict or not applicable |
| 4 | VAT ledger to return draft | Period cutoff, ledger accounts, adjustments and rule version | Reconciled return line evidence |
| 5 | Return draft/submission to ITAS | Submission ID, payload hash, response and account outcome | Submitted/accepted/rejected difference |
| 6 | Imports to import VAT | Customs declaration, importer identity, goods/payment evidence, date/amount | Matched import or exception |

## 8.3 Match strategies

The engine applies ordered strategies and stores the strategy/version and evidence:

1. Exact VAT-MSA transaction or certificate reference.
2. Exact supplier, buyer, invoice number, issue date, currency and amounts.
3. Exact canonical content hash or approved source cross-reference.
4. Tolerant match for approved rounding/date-window cases.
5. Candidate ranking for legacy/imported data using explainable features.
6. Human resolution where evidence is ambiguous.

Probabilistic similarity never converts an unmatched document into a legal match without an approved threshold and review policy. A superseding match decision preserves the prior result.

## 8.4 Exception types

- Missing seller or buyer record.
- Unknown/ambiguous taxpayer identity.
- Taxable amount or VAT amount mismatch.
- Rate/category or currency mismatch.
- Duplicate invoice or reused fiscal number.
- Invalid correction/original reference.
- Closed-period or late submission.
- Customs/import amount or identity mismatch.
- Offline device sequence, signature or timing failure.
- Return line differs from its ledger basis.
- ITAS submission/response differs from the approved draft.

## 8.5 Exception lifecycle

Exceptions move through OPEN, UNDER REVIEW, RESPONDED, RESOLVED, REJECTED, ESCALATED and CLOSED. Each transition requires a permitted role, reason and timestamp. High/critical resolutions require supervisory approval. The taxpayer can see only appropriate case data and has a clear response/evidence channel. Materiality rules prioritise work but do not erase low-value discrepancies.

An exception record includes type, severity, taxpayers, transaction/invoice, seller and buyer values, rule/match evidence, owner, due date, communications, attachments/evidence manifest, decisions, approvals and links to audit/refund cases.

## 8.6 Continuous reconciliation controls

Reconciliation runs both event-driven and scheduled:

- Event-driven matching reacts to certification, buyer confirmation, corrections and customs data.
- Near-real-time controls reconcile committed outbox age and consumer progress.
- Daily controls compare counts, sums, hashes and status distributions across authoritative stores.
- Period-close controls prove the exact population supporting the return snapshot.
- Warehouse controls compare source totals, late-arrival watermarks and replay completeness.
- A control failure creates an operational incident, not a hidden dashboard warning.

[[PAGEBREAK]]

# 9. VAT Return and Statutory Co-existence Architecture

## 9.1 Return service pipeline

The Return Domain transforms controlled evidence through the following stages:

1. Select taxpayer and open/closing tax period.
2. Freeze an authorised ledger cutoff snapshot.
3. Aggregate output, input, import, adjustment and carry-forward accounts.
4. Apply the approved, effective-dated return mapping and tax rules.
5. Attach reconciliation status and unresolved exception materiality.
6. Generate a draft with transaction-to-line drill-down.
7. Present taxpayer/preparer review and authorised approval.
8. Submit through the approved ITAS interface or export an approved filing artefact.
9. Record ITAS receipt, status, differences and account outcome.
10. Amend through a versioned workflow; never overwrite a filed version.

## 9.2 Calculation evidence

Each return line stores formula/mapping code, source ledger accounts, transaction population reference, rule-set version, cutoff, adjustments, rounding and hash. The same inputs and version must reproduce the same result. The Excel template and official return forms are inputs to an approved mapping specification; they are not executed as opaque production logic.

## 9.3 Co-existence with ITAS

Until NamRA approves a changed boundary:

- ITAS remains the statutory taxpayer-account and filing authority.
- VAT-MSA produces a controlled draft and evidence package.
- The taxpayer/authorised agent approves the return under the accepted legal process.
- VAT-MSA sends a versioned payload or approved file and records the exact hash.
- ITAS returns receipt, processing status, accepted/rejected outcome and any calculated account differences.
- Rejections/differences become workflow items; VAT-MSA does not assume submission equals acceptance.

If ITAS has no suitable API, a managed file exchange can be used temporarily with encryption, signing, acknowledgement, replay controls, schema validation and reconciliation. Screen scraping is not an enterprise integration strategy.

## 9.4 Adjustments and amendments

An adjustment states legal reason, source evidence, affected transaction/period, amount, rule, preparer, approver and effective period. A filed return is immutable; an amended version references the prior version and explains the difference. Late certified documents follow the approved legal rule for the original or a later period. The system makes this policy explicit and testable.

## 9.5 Refund interface

A refundable return position creates a refund candidate, not an automatic payment instruction. The refund workflow combines return evidence, matched purchases, import evidence, exceptions, taxpayer history and risk reasons. Preparation, approval and release are segregated; thresholds determine dual approval. The final outcome and ITAS/payment references reconcile back to the return.

[[PAGEBREAK]]

# 10. NamRA Compliance, Audit, Risk and Refund Architecture

## 10.1 NamRA operational application

The NamRA experience is organised by work, not by raw tables:

- National and portfolio dashboards.
- Taxpayer 360 with purpose-limited access.
- Invoice, transaction and certificate search.
- VAT periods, return reconciliation and ITAS outcomes.
- Exception queues and service-level monitoring.
- Risk alerts with reason codes and model/rule version.
- Audit case management and electronic audit file.
- Refund case preparation, approval and release status.
- Partner/import integration health and data-quality queues.
- Operational reports, analytics and controlled export.
- System health links appropriate to business operators.

## 10.2 Electronic VAT audit file

An audit-file snapshot contains:

- Taxpayer identity and effective registration history.
- Period definition and filed/amended return versions.
- Sales, purchases, imports, output/input VAT and adjustments.
- Supporting invoices, certificate status and correction chains.
- Ledger entries and calculation/rule evidence.
- Matching decisions and unresolved/resolved exceptions.
- Risk indicators and their explanation, without treating them as findings.
- Taxpayer communications and evidence manifest.
- Officer actions, approvals, exports and complete audit trail.

The file is a manifest of immutable references plus approved rendered/export forms. Evidence hashes and access logs support integrity. Legal/records authorities define admissibility, signatures, retention and disclosure.

## 10.3 Audit case workflow

Cases move through OPEN, PLANNING, EVIDENCE COLLECTION, TAXPAYER RESPONSE, DECISION, APPEAL and CLOSED. The lead auditor cannot approve the same final determination. Reassignment, scope changes and extensions require reasons. Evidence is never attached only to email; it is ingested, scanned, hashed, classified and linked to the case.

## 10.4 Risk architecture

VAT calculation answers the amount under approved rules. Risk analytics asks whether an item needs attention. Risk signals include duplicate patterns, counterparty mismatches, unusual input/refund ratios, spikes, newly active suppliers, transaction-network features, historical variance, offline anomalies and repeated correction behaviour.

The initial release should favour explainable rules and simple validated models. Each alert includes score/band, reason codes, contributing facts, model/rule version, data timestamp and permitted use. Risk bands LOW, MEDIUM, HIGH and CRITICAL route work; they do not automatically create liability or deny a refund.

## 10.5 Model governance

- Document purpose, target outcome and prohibited uses.
- Approve training/validation data, lineage and representativeness.
- Validate accuracy, calibration, stability, bias and business cost.
- Define threshold, human review and override/appeal process.
- Monitor drift, false positives, cohort effects and officer overrides.
- Preserve model/version and features used for each alert.
- Retire or roll back safely; never silently replace a model in a live case.

## 10.6 Refund controls

The Refund Officer prepares and investigates. The Refund Approver is independent and works within a configured limit. High-value release uses dual control. Platform or risk administrators cannot approve refunds. Payment instruction status is reconciled with ITAS/treasury responses. Override and urgency paths are time-bounded, visible and retrospectively reviewed.

[[PAGEBREAK]]

# 11. Integration, API, Event and SaaS Connector Architecture

## 11.1 API gateway responsibilities

All external calls enter through a controlled gateway that provides:

- TLS, client authentication and certificate validation.
- OAuth/OIDC token validation and policy decision integration.
- Taxpayer/client scope and object-level authorisation context.
- Schema/content-type/payload validation.
- Rate, concurrency, batch and line limits.
- Idempotency-key admission and correlation/trace propagation.
- Version routing, deprecation notices and partner-specific policy.
- Structured security/operational logging without sensitive payload leakage.
- WAF, DDoS and abuse controls.

The gateway is not where tax calculations live. It enforces edge policy and routes to the owning domain.

## 11.2 Contract standards

- OpenAPI 3.1 for synchronous HTTP APIs.
- JSON Schema 2020-12 for canonical payload validation.
- CloudEvents 1.0-compatible envelopes for business events.
- RFC 9457-compatible problem details for API errors.
- OAuth/OIDC and approved machine-to-machine profiles.
- Semantic versioning, additive compatibility rules and published deprecation periods.

The starter `openapi.yaml`, canonical invoice schema and event catalogue in this package are architecture baselines. NamRA must own the production namespace, examples, error catalogue and conformance tests.

## 11.3 Core API catalogue

| API group | Example operations | Consumers | Data class |
|---|---|---|---|
| Fiscal documents | Submit, status, retrieve authorised document, credit/debit note, certificate | POS/ERP/SaaS/portals | Tax Confidential |
| Public verification | Verify opaque QR token and certificate status | Public | Public/minimised |
| Taxpayer reference | Resolve/validate approved identifiers and registration status | Certified partners/internal domains | Tax Confidential |
| Buyer confirmation | Confirm, dispute or reference buyer-side purchase | Buyer systems/portal | Tax Confidential |
| Exceptions | List, view, respond, upload evidence, status | Taxpayers/NamRA | Tax Confidential |
| Returns | Period summary, draft, drill-down, approve, submit/status | Taxpayer/NamRA/ITAS adapter | Tax Confidential |
| Offline | Enrol/renew device, submit batch, batch/document outcomes | Desktop/sync service | Tax Confidential |
| Partner operations | Schema versions, conformance, client/credential lifecycle, health | Integration partners/admins | Internal/Confidential |
| NamRA cases | Audit/risk/refund workflows and evidence | Authorised NamRA roles | Highly Restricted |
| Reporting | Governed reports and asynchronous exports | Taxpayer/NamRA roles | Scope dependent |

## 11.4 Event-driven architecture

The platform publishes business facts only after commit through a transactional outbox. Broker delivery is at least once. Consumers store the event ID and a business-effect key so replay is safe. Ordering is guaranteed only within a declared partition such as taxpayer, transaction, case or device. Global order is neither promised nor required.

Events carry type/version, ID, source, subject, time, schema reference, trace context and data-classification metadata. Sensitive payloads use restricted topics and encryption/access policy. Events are not an unrestricted data replication mechanism.

## 11.5 Integration gateway and adapters

Each partner adapter maps the external source to the canonical model and never injects vendor-specific fields into the VAT core. Adapters handle authentication profile, mapping, source identifiers, retry policy, batch/file protocol, error translation and partner telemetry. Shared conformance tests prove mapping and idempotency.

Connector lifecycle:

1. Register partner and legal/operating owner.
2. Approve security and data exchange.
3. Implement mapping against a declared schema version.
4. Pass automated conformance and negative/security tests.
5. Issue sandbox then production credentials.
6. Monitor data quality, errors, latency and abuse.
7. Re-certify on material schema/security change.
8. Suspend/revoke safely and retain evidence.

## 11.6 ITAS and government integration

Government interfaces are catalogued with owner, authority, direction, identifiers, schema, security, availability, RTO, reconciliation and support escalation. Data received from ITAS/customs is effective-dated and does not silently replace conflicting VAT-MSA evidence; discrepancies enter steward/operations workflows.

## 11.7 Import integration

The Import Domain ingests customs declarations, importer identity, tax base, VAT/payment evidence, goods/reference data and status. It links imports to the taxpayer/period and identifies unmatched, duplicated or ineligible claims. The exact interface and legal eligibility evidence require Customs and VAT Policy approval.

[[PAGEBREAK]]

# 12. Data, Database, Analytics and Reporting Architecture

## 12.1 Data environments

| Environment | Workload | Technology role | Design rule |
|---|---|---|---|
| Operational store | Certification, posting, periods, cases and control state | PostgreSQL HA | Strong consistency and domain ownership |
| Audit/event evidence | Business/security events, hashes, manifests and legal holds | Append-only store plus immutable object storage | No ordinary mutation; independently monitored |
| Search | Authorised operational discovery | OpenSearch-equivalent | Rebuildable projection; field-level controls |
| Cache | Short-lived reference/session/rate data | Redis-equivalent | Never authoritative fiscal state |
| Warehouse/lakehouse | BI, statutory analysis, quality, risk features and graph projections | Governed analytical platform | Separate compute/access; source reconciliation |
| Backup/archive | Recovery and approved records preservation | Encrypted immutable storage | Separate credentials, retention and restore tests |

## 12.2 Core logical data model

The reference schema defines Taxpayer and effective identifiers, Source System, Invoice/Line/Tax Summary, VAT Transaction, Tax Period, VAT Ledger Entry, Certificate, Match Result, Exception, Rule Set/Rule, VAT Return, Risk Alert, Audit Case, Offline Device/Batch, Idempotency Record, Outbox Event and Audit Event.

Key relationships are:

- Taxpayer 1-to-many effective identifiers and source systems.
- Supplier and optional buyer taxpayer link to invoice.
- Invoice 1-to-many lines/tax summaries and 1-to-1 active VAT transaction/certificate.
- VAT transaction 1-to-many balanced ledger entries.
- Transaction 1-to-many versioned match results; current result is a projection.
- Match/transaction/taxpayer may open exceptions, risk alerts and audit/refund cases.
- Taxpayer/period 1-to-many versioned return drafts/submissions.
- Every command may produce outbox and audit events in the same transaction/evidence flow.

The database ERD is implemented as a starting PostgreSQL schema in `04-data/core-schema.sql`. It is not permission to share one schema among independently owned services; physical separation follows deployment evolution while identifiers/contracts remain stable.

## 12.3 Taxpayer master registry

VAT-MSA uses an immutable internal taxpayer ID and effective-dated VAT number, TIN, company number and other identifiers. Equality lookups use protected tokens; user interfaces show masked values unless purpose permits full access. Duplicate/merge decisions retain aliases, source, steward, reason and reversal capability.

Taxpayer synchronisation records source version and freshness. Certification policy defines how stale/ambiguous status is handled. A temporary ITAS outage must not cause uncontrolled identity acceptance; cached status has an approved maximum age and risk treatment.

## 12.4 Data classification

| Class | Examples | Minimum treatment |
|---|---|---|
| Public | Published API documentation, public certificate status subset | Integrity, availability and change control |
| Internal | Non-sensitive architecture, operational procedures | Workforce authentication and controlled sharing |
| Confidential | Partner contacts, configuration, non-public performance data | Need-to-know access and encryption |
| Tax Confidential | Invoices, parties, VAT positions, returns, taxpayer correspondence | Strong encryption, purpose/tenant controls, audited access and export restrictions |
| Highly Restricted | Full identifiers, risk features/models, audit evidence, signing keys, privileged/security data | Isolated access paths, PAM/dual control where relevant, enhanced monitoring |

## 12.5 Data quality and lineage

Data quality dimensions include completeness, validity, uniqueness, consistency, timeliness and accuracy. Each critical element has an owner, rule, threshold, exception queue and trend. Warehouse lineage links reports/features to source event/schema/version and transformation. A model or report cannot be approved when its critical data lineage is unknown.

## 12.6 Warehouse and graph analytics

Operational data is streamed or captured through governed change/event pipelines. Curated facts include fiscal documents, VAT positions, matches, exceptions, returns, audit/refund outcomes and operational metrics. Dimensions are effective-dated for taxpayer, source, period, geography/industry where legally approved and rule version.

A graph projection may model taxpayer, invoice, transaction, director/agent or other legally authorised relationships. It is an analytical derivative, not the operational source. Graph access is highly restricted; network features used in risk alerts are explainable and monitored for inappropriate inference.

## 12.7 Reporting architecture

Reports are grouped into taxpayer, operations, management, statutory and analytical classes. Every regulated/management report has owner, purpose, grain, filters, source/lineage, refresh, access, retention, reconciliation and certification status. Large exports are asynchronous, encrypted, time-limited, watermarked where appropriate and fully audited.

[[PAGEBREAK]]

# 13. Security, IAM, Privacy and Evidence Architecture

## 13.1 Zero-trust position

VAT-MSA protects resources rather than trusting network location. Every user, organisation, system, workload and device is authenticated; every request is authorised for the action, resource, taxpayer/case scope and purpose; policy is re-evaluated as context changes. This aligns with NIST SP 800-207 while the broader programme uses NIST Cybersecurity Framework 2.0 for governance and risk outcomes.

[[FIGURE:06-security-trust-zones.png|Figure 6. Security trust zones and control points.]]

## 13.2 Identity types and protocols

| Identity | Authentication | Authorisation context | Lifecycle |
|---|---|---|---|
| Taxpayer/NamRA user | Central OIDC, MFA and conditional access | Role, organisation, taxpayer representation, portfolio/case and purpose | Join/move/leave, periodic certification |
| Tax practitioner/representative | OIDC plus explicit delegation | Client taxpayer, permitted function and delegation dates | Taxpayer/NamRA approval and revocation |
| API client | mTLS plus client credentials/private-key assertion | Client, taxpayer(s), scopes, environment and limits | Partner onboarding, rotation, suspension and expiry |
| Workload/service | Short-lived workload identity and mTLS | Service, namespace, method/topic/data policy | Automated issuance/rotation; no shared secrets |
| Offline device | Enrolment certificate and device-bound key | Taxpayer, source, device status, sequence policy | Activate, renew, suspend, lost/revoke and wipe policy |
| Privileged administrator | Workforce identity plus phishing-resistant MFA and PAM | Just-in-time task/environment privilege | Dual approval, recording, expiry and review |

OAuth/OIDC profiles, MFA methods, certificate authorities and session lifetimes are selected through the approved government IAM design. Password-only privileged access is prohibited.

## 13.3 Authorisation model

RBAC supplies understandable job roles. Attribute-based conditions constrain them by taxpayer, organisation, case assignment, operating unit, approval limit, environment, device trust and purpose. Object-level checks are mandatory at the domain boundary, not only in the UI or gateway.

Segregation of duties includes:

- Tax rule proposer cannot activate their own change.
- Auditor cannot approve their own final determination.
- Refund preparer cannot approve/release the same case.
- Platform administrator cannot grant themselves taxpayer-data access.
- Security key operation uses quorum/dual control.
- Risk analyst cannot independently deploy/approve the model used for intervention.

The complete starter matrix is provided in `05-security/rbac-matrix.csv`.

## 13.4 Cryptography and key management

- Use approved TLS and cipher configurations; prefer TLS 1.3 with controlled 1.2 compatibility where required.
- Encrypt databases, object stores, queues, logs, search, backups and desktop local stores.
- Separate keys by environment and data domain; prevent production key use in lower environments.
- Store fiscal signing keys in HSMs as non-exportable keys; use formal ceremonies, quorum, rotation, revocation and backup/recovery.
- Use envelope encryption and central KMS for application data; record key ID/version without exposing keys.
- Tokenise sensitive identifiers for lookup and mask them for display.
- Maintain a cryptographic inventory and algorithm-agility plan.

## 13.5 Audit and tamper evidence

Audit events record who/what/when/where, action, resource, taxpayer scope, correlation, outcome, reason, before/after hash and evidence reference. The audit stream is append-only and independently monitored. Fiscal/business audit, security telemetry and application diagnostic logs are distinct but correlated.

Ordinary users cannot alter audit records. Administrators cannot disable audit without a detected control event. Time is synchronised and monitored. Evidence object ingestion calculates hash, malware status, classification and manifest; legal holds prevent disposal.

## 13.6 Privacy and sovereignty

VAT-MSA applies purpose limitation, minimisation, accuracy, retention control, access transparency and privacy-by-design regardless of the final timing of Namibia's Data Protection legislation. The programme maintains a processing/authority register and conducts privacy impact assessments for taxpayer identity, public verification, partner exchange, graph analytics, risk models, exports and cross-border/vendor support.

Data-residency and sovereign-data requirements are Gate 0 decisions. Contracts identify hosting locations, subprocessors, support access, encryption-key control, breach obligations, return/export formats and verified destruction. No production dataset is moved to a test, vendor or AI/analytics service by convenience.

## 13.7 Threat model priorities

| Threat | Primary controls |
|---|---|
| Credential theft and account takeover | MFA, conditional access, short sessions, anomaly detection, credential rotation |
| Broken object/function authorisation | Central policy, domain checks, negative tests, tenant/case scope and denied-event monitoring |
| Fraudulent/duplicate invoices | Strong client/device identity, idempotency, fiscal uniqueness, canonical hash, sequence and network analysis |
| API resource exhaustion | WAF/DDoS, quotas, per-tenant concurrency, payload limits, backpressure and queue isolation |
| Insider bulk access/export | Purpose controls, PAM, masking, export workflow, watermarks, behaviour alerts and review |
| Supply-chain compromise | Trusted registries, locked dependencies, SBOM, signed provenance, isolated builds and promotion by digest |
| Data tampering or event loss | Transactional outbox, append-only evidence, hashes, reconciliation and restore tests |
| HSM/signing key compromise | Non-exportable keys, quorum, monitoring, revocation and incident/re-certification plan |
| Offline device tampering/replay | Device-bound key, signed hash chain, monotonic sequence, secure storage, revocation and sync windows |
| Analytical/model misuse | Purpose governance, minimised data, validation, explainability, human decision and appeal |

## 13.8 Security control baseline

The security matrix in this package maps governance, IAM, APIs, data, cryptography, software supply chain, logging, resilience, privacy and model governance to implementation and evidence. Security acceptance is based on testable controls and operating evidence, not a policy document alone.

[[PAGEBREAK]]

# 14. Offline and Desktop Architecture

## 14.1 Principle

The desktop client never writes the central database. It writes an encrypted local store, appends fiscal documents to a signed ordered queue and synchronises through the API gateway when connectivity is available. Central processing applies the same canonical validation, duplicate, rule and posting controls as online submissions.

## 14.2 Certification modes

### Default: pending central certification

The desktop assigns a globally unique source document ID and issues a clearly labelled PENDING receipt. On synchronisation, VAT-MSA certifies or rejects it and returns the central certificate. This is the safest legal/technical default because the client cannot truthfully claim central certification while disconnected.

### Optional: pre-authorised offline tokens

If law and NamRA policy require immediate offline fiscal validity, an enrolled device may receive a small, expiring, taxpayer-bound pool of signed authorisation tokens/number ranges. The client consumes them in order and synchronises within a defined grace period. This mode adds fraud and operational complexity and requires legal approval, HSM trust, inventory reconciliation, remote revocation and explicit consequences for lost/late tokens.

## 14.3 Local security

- Signed application packages, trusted update channel and rollback protection.
- Device-bound private key in secure hardware where available.
- Encrypted database with keys protected by OS/device credentials.
- Least local data; no long-lived central credentials.
- Local user authentication, role and inactivity lock.
- Tamper and clock anomaly detection.
- Remote suspension/revocation and lost-device workflow.
- Support bundle redaction so diagnostics do not leak invoices or secrets.

## 14.4 Queue and synchronisation protocol

1. Enrol the device and source system; issue device certificate/policy.
2. Create a document with a UUIDv7/source ID and monotonic local sequence.
3. Canonicalise and hash the document; link it to the previous item/batch hash.
4. Store encrypted document, state and evidence atomically.
5. Create a signed batch with sequence range and previous-batch hash.
6. Submit using a stable idempotency key and mutual authentication.
7. Central service validates device status, signature, chain, gaps/replays and each document independently.
8. Return durable batch receipt, per-document outcomes and certificate/status references.
9. Mark local items only after acknowledged outcome; retain approved evidence and purge under policy.

## 14.5 Conflict policy

| Conflict | Handling |
|---|---|
| Exact batch/document retry | Return original outcome idempotently |
| Same ID, different content | Reject and raise security/integrity exception |
| Sequence gap | Quarantine later range until gap is resolved or formally waived |
| Device revoked/lost | Reject new sync; preserve evidence; activate investigation policy |
| Rule changed while offline | Apply rule at legally defined tax point with stored effective version; explain differences |
| Invoice already submitted online | Detect business duplicate and return/link original outcome under policy |
| Central rejection after pending issue | Inform taxpayer, retain original pending evidence and require correction workflow |

## 14.6 Offline operations

Support monitors enrolled devices, last sync, queued age, sequence gaps, certificate expiry, version compliance and error rates. Rollout begins with field trials covering unreliable networks, power interruption, clock drift, large backlog, update failure, device replacement and user recovery. Offline capability is not production-ready until its legal status and support model are proven.

[[PAGEBREAK]]

# 15. Infrastructure, Cloud, Resilience and Observability Architecture

## 15.1 Hosting model

The architecture supports approved sovereign cloud, government data centre or hybrid deployment. Procurement chooses a platform only after data residency, connectivity, skills, support, accreditation, total cost and exit requirements are approved. Containers and infrastructure as code provide repeatability; they do not eliminate the need for platform operations.

## 15.2 Production topology

[[FIGURE:07-deployment-and-dr.png|Figure 7. Primary and disaster-recovery topology.]]

The primary site uses redundant ingress, horizontally scaled stateless workloads, a highly available PostgreSQL cluster, durable message broker, replicated evidence object storage, central IAM/KMS/HSM and observability. Critical dependencies span failure zones. The secondary site has replicated data and warm application capacity with tested traffic failover and controlled failback.

For later national scale, application cells partition taxpayer traffic deterministically. Each cell contains transaction workers and an operational data partition; global services retain identity, configuration, certificate trust and routing. A cell failure affects a bounded cohort and does not require a single ever-growing database. Cell architecture is introduced only after measured scale and operational maturity justify it.

## 15.3 Environments

| Environment | Purpose | Data policy | Promotion rule |
|---|---|---|---|
| Developer | Fast local/unit development | Synthetic only | No production trust or credentials |
| Integration | Shared domain and dependency integration | Synthetic/approved reference | Automated deployment from branch/main policy |
| Conformance sandbox | Partner contract certification | Synthetic partner-visible | Versioned stable endpoints and published resets |
| Security/performance | Attack, load and resilience | Synthetic at production shape | Isolated and production-like infrastructure |
| Pre-production | Release/operational acceptance | Synthetic or exceptionally approved masked data | Same artifact/digest as production candidate |
| Production | Legal service | Real tax-confidential data | Approved evidence pack and change window |
| DR | Recovery | Replicated encrypted production data | Access-separated; activated by controlled procedure |

## 15.4 Availability and graceful degradation

Critical synchronous certification remains independent from search, analytics, notifications and non-critical reports. When downstream consumers fail, the outbox/broker retains work and alerts on lag. When an authoritative dependency such as taxpayer status is unavailable, the business-approved cache/grace policy determines whether to reject, queue or continue; the system does not invent a permissive fallback.

Circuit breakers, timeouts, bulkheads, bounded retries with jitter, dead-letter/quarantine workflow and backpressure prevent cascades. Retry always preserves the original idempotency key. Queues have age and capacity SLOs, not only depth graphs.

## 15.5 Recovery

The provisional objectives are Tier 0 certification/posting/verification RTO <= 60 minutes and RPO <= 5 minutes; Tier 1 portals/cases/returns RTO <= 4 hours and RPO <= 15 minutes; Tier 2 analytics RTO <= 24 hours and RPO <= 4 hours. Business-impact analysis must approve them.

Backups are encrypted, immutable, credential-separated and restored quarterly. DR exercises prove traffic, IAM, secrets/keys, database, broker, evidence, integrations, business reconciliation and failback. A database that starts is not a completed recovery; certified invoice and ledger invariants must reconcile.

## 15.6 Observability and SRE

Observability includes metrics, structured logs, distributed traces, business events, synthetic transactions and data-quality controls. Telemetry excludes secrets and minimises tax data. The common correlation ID connects gateway, domain transaction, outbox, broker, consumer, case and audit event.

Key service-level indicators include:

- Certification success/latency by channel and cohort.
- Rejection rate and reason distribution.
- Idempotency conflict and duplicate outcomes.
- Ledger posting failures/unbalanced controls.
- Outbox age, broker lag, dead-letter/quarantine age.
- Match rate and exception age by type.
- ITAS/customs/payment integration freshness.
- Offline queued age, gaps and device failures.
- Privileged/data export anomalies.
- Backup age, restore result, replication lag and RTO/RPO exercise result.

Alerts identify business impact and link a safe runbook. Error budgets govern release pace for SLO-bound services.

## 15.7 Capacity baseline

Until the Gate 0 model replaces it, engineering should prove 2,000 fiscal documents/second sustained and 10,000/second for a 15-minute burst, with controlled recovery and no lost/duplicate effect. Tests include period-end traffic, large invoices, offline backlog recovery, partner retry storms, analytical replay and a dependency degradation at the same time.

[[PAGEBREAK]]

# 16. DevSecOps, Repository and Testing Architecture

## 16.1 Technology stack recommendation

| Concern | Baseline | Decision notes |
|---|---|---|
| Backend | PHP 8.x with Laravel or another organisation-approved typed enterprise framework | Select by NamRA skills/support; enforce domain boundaries and static analysis |
| Frontend | React/Next.js or approved equivalent | Separate role-specific apps, shared design system and accessibility |
| Desktop | Tauri preferred where security/footprint skills exist; Electron acceptable with hardening | Field/support capability is more important than fashion |
| Operational database | PostgreSQL 16+ | Strong transactional integrity, partitioning and mature operations |
| Cache/rate state | Redis-compatible | Non-authoritative only |
| Messaging | Kafka for high-volume durable streams or RabbitMQ for queue-centric operations | Benchmark and operate one primary pattern; do not introduce both without need |
| Identity | Government/enterprise OIDC provider; Keycloak is a viable self-hosted option | Must support federation, MFA, machine identity and audited administration |
| Search | OpenSearch-compatible | Purpose-limited index; rebuildable |
| Object evidence | S3-compatible immutable/versioned storage | Legal hold, retention and hash manifest |
| Containers/orchestration | OCI containers and Kubernetes or approved managed equivalent | Requires platform engineering/SRE maturity |
| Infrastructure | Terraform/OpenTofu-compatible IaC plus configuration policy | Provider exit and sovereign-hosting support |
| Observability | OpenTelemetry, Prometheus-compatible metrics, Grafana and approved log/SIEM stack | Common correlation and data-minimisation policy |
| Contracts | OpenAPI 3.1, JSON Schema 2020-12, CloudEvents 1.0 | Schemas and conformance tests are versioned products |

The stack is a baseline, not a procurement conclusion. The final decision must include skills, sovereign hosting, support, security accreditation, licence, portability, performance and total cost.

## 16.2 Repository architecture

```text
vat-msa/
  apps/
    seller-portal/
    buyer-portal/
    namra-operations/
    audit-risk-workbench/
    system-administration/
    public-verification/
    offline-desktop/
  services/
    edge-integration/
    transaction-core/
    compliance-returns/
    audit-risk-refunds/
  modules/
    taxpayer/
    partner-device/
    invoice/
    rules/
    certification/
    vat-transaction/
    vat-ledger/
    reconciliation/
    exceptions/
    returns/
    imports/
    audit/
    risk/
    refunds/
    reporting/
    notifications/
  contracts/
    openapi/
    json-schema/
    events/
    error-catalogue/
    conformance-tests/
  platform/
    identity-policy/
    messaging/
    observability/
    evidence-storage/
    database/
  infrastructure/
    environments/
    modules/
    policies/
    disaster-recovery/
  data/
    warehouse/
    quality/
    lineage/
    models/
  tests/
    conformance/
    integration/
    end-to-end/
    security/
    performance/
    resilience/
    dr/
  docs/
    architecture/
    adr/
    operations/
    security-privacy/
    partner-guides/
```

A monorepo is suitable while teams/contracts are evolving if builds and ownership remain scoped. Independent release repositories may emerge later. The architecture is defined by ownership and contracts, not the number of Git repositories.

## 16.3 Delivery pipeline

1. Developer pre-commit formatting, lint, secret and schema checks.
2. Pull-request unit/property, component, migration, SAST, SCA and contract tests.
3. Reproducible build in isolated runner; generate SBOM and provenance.
4. Sign immutable artifact/image by digest and push to trusted registry.
5. Deploy automatically to integration; run integration and data controls.
6. Promote the same digest to conformance/security/performance/pre-production.
7. Assemble release evidence and obtain required business/security/operations approval.
8. Progressive production deployment with health/business checks and automated stop criteria.
9. Reconcile transactions/events/data after release and preserve rollback/forward plan.

Database change uses expand/migrate/contract for backward compatibility. Tax-rule activation is a business configuration release with its own conformance pack, approvals, effective time and rollback; it is not an unreviewed administrator toggle.

## 16.4 Test architecture

Quality layers include unit/property, rule pack, component, API/event contract, real integration, end-to-end, data/reconciliation, security, performance, resilience, DR, accessibility and operational acceptance. Golden scenarios cover B2B, consumer/non-registered buyer, zero/exempt/mixed invoices, retries/duplicates, credit notes, exceptions, offline sequences, rule effective dates, late/closed periods and refund segregation.

Release is blocked by a failed fiscal invariant, source-to-ledger reconciliation, audit-chain check or restore test. The full strategy and evidence pack are in `06-delivery/testing-strategy.md`.

## 16.5 Environments and test data

Development and shared test environments use synthetic data by default. Production data requires explicit approval, minimisation/masking, isolated access and disposal evidence. A jointly owned fiscal conformance pack contains approved legal examples and boundary cases. Partner certification includes negative, security, idempotency, retry and volume cases, not only happy-path sample invoices.

[[PAGEBREAK]]

# 17. Implementation Roadmap: MVP to Production to National Scale

[[FIGURE:08-delivery-roadmap.png|Figure 8. Outcome-gated implementation roadmap.]]

## 17.1 Gate 0 - mandate, legal design and discovery

Before development, confirm the e-invoicing mandate/status, statutory document states, cohorts/exemptions, invoice/QR/signature/retention requirements, offline policy, taxpayer rights, ITAS/customs/refund boundaries, authoritative VAT rule pack and return mapping. Complete volume, hosting/residency, threat/privacy, operating-model and procurement decisions.

**Exit:** executive, legal, VAT policy, architecture, security, data, records, operations and product approvals.

## 17.2 Phase 1 - foundation

Build IAM and organisation access, taxpayer master replica, partner/API client/device registry, gateway, canonical contracts, audit evidence, platform, CI/CD, observability, secrets/KMS and initial DR. Establish the conformance sandbox and developer portal.

**Exit:** security/operational acceptance, partner conformance and restore pass.

## 17.3 Phase 2 - electronic invoicing pilot (MVP)

Build invoice submission/lifecycle, validation, duplicate controls, effective-dated rules, certification/signature/QR, corrections, seller status/error experience and representative POS/ERP/accounting adapters.

**Exit:** legal conformance pack passes and pilot transactions reconcile without critical defects.

## 17.4 Phase 3 - VAT transaction and sub-ledger

Add atomic VAT transactions, balanced ledger, output/input candidates, adjustments/import foundation, transactional outbox/events and operational completeness controls.

**Exit:** invariants, performance, recovery and finance/control reconciliation pass.

## 17.5 Phase 4 - reconciliation and return co-existence

Add matching, exceptions, period close, return draft/evidence, taxpayer review and ITAS submission/co-existence. Run in parallel with authoritative filing until approved.

**Exit:** approved taxpayer samples reconcile to ITAS/authoritative returns and material differences are understood.

## 17.6 Phase 5 - NamRA audit, risk and refund controls

Add officer dashboards, electronic audit file, case management, explainable risk, model governance and refund preparation/approval controls.

**Exit:** legal evidence, appeal, operating procedure and segregation tests pass.

## 17.7 Phase 6 - offline and broad rollout

Add enrolled desktop, encrypted queue, signed sync, approved offline validity mode, sequence/tamper controls and field support. Expand in measured taxpayer cohorts and scale partner certification/service desk.

**Exit:** field pilot proves integrity, usability, device recovery, fraud controls and support capacity.

## 17.8 Phase 7 - national scale and advanced analytics

Introduce cell scaling when justified, mature multi-site DR, warehouse/lakehouse, graph/network analysis, controlled model workbench, data-quality/lineage scorecards and national operational optimisation.

**Exit:** national load/DR exercise, independent security assessment and steady-state governance approval.

## 17.9 Rollout controls

- Pilot representative cohorts before legal mandate or broad enforcement.
- Maintain coexistence, rollback and taxpayer communications for each wave.
- Publish schema/rule versions, examples, conformance tests and transition windows.
- Measure rejection/false positive/support burden by taxpayer segment.
- Pause rollout when legal, integrity, security, capacity, support or recovery gates fail.

[[PAGEBREAK]]

# 18. Governance, Decisions and Next Actions

## 18.1 Architecture governance

The Architecture Review Board maintains the capability map, domain ownership, system context, ADR register, standards, exception register and technology roadmap. An ADR is required for changes to system-of-record boundaries, consistency, fiscal identity, rule evaluation, event semantics, data location, authentication, ledger posting, service extraction, analytical model use or RTO/RPO.

Vendors implement approved contracts and decisions; they do not become the design authority. Proprietary choices require licence, support, portability, data export, continuity and exit assessment. Source code, contracts, schemas, infrastructure, pipeline, SBOM, runbooks and evidence are handover deliverables.

## 18.2 Rule and schema governance

The VAT Rules Board owns interpretation, examples, effective dates and conformance. A rule release is drafted, legally reviewed, tested against approved examples, approved by a separate authority, scheduled, monitored and capable of rollback. The transaction stores the exact rule version/evidence.

The API/schema owner publishes versions, change classification, compatibility, deprecation, sample data and conformance tests. Breaking change requires a new major version and transition plan. Partner exceptions are temporary, owned and expiring; they do not fork the national model indefinitely.

## 18.3 Non-functional governance

Availability, latency, capacity, integrity, security, privacy, accessibility, maintainability and recovery are requirements with owners and evidence. The provisional targets in this blueprint become contractual only after Gate 0 business-impact/volume validation. SLO breach consumes an error budget and triggers corrective prioritisation.

## 18.4 Critical decisions to close

1. Current NamRA e-invoicing operational status, legal mandate, rollout cohorts and transition.
2. Legal status of certified, rejected, provisional/offline, corrected and reversed documents.
3. Official canonical invoice particulars, numbering, QR and digital-signature requirements.
4. ITAS system-of-record and integration boundaries for taxpayer, return, account and refund.
5. Treatment of consumers, non-registered buyers, government entities, imports/exports, self-billing and foreign currency.
6. Official rule pack, rounding, tax point, input eligibility and return-form mapping.
7. Hosting/residency, government network, IAM/PKI/HSM and DR requirements.
8. Retention, legal holds, evidence/admissibility, taxpayer access/correction and disclosure.
9. Offline validity and grace policy.
10. Volume, peak, maximum invoice/batch, availability, RTO and RPO.
11. Risk/refund decision governance, appeal and human approval thresholds.
12. Procurement/delivery model, accountable owners, team capacity and operating budget.

## 18.5 Immediate 90-day architecture work plan

### Days 1-30: confirm authority and evidence

- Appoint executive sponsor, business owner, chief architect and domain owners.
- Establish Architecture Review Board, VAT Rules Board and Data/Security/Privacy governance.
- Obtain authoritative law/policy interpretation, return templates, invoice samples and ITAS/customs interface evidence.
- Confirm e-invoicing programme status, in-flight procurements/solutions and reusable government services.
- Run business-impact, volume, data classification/flow and initial threat/privacy assessments.

### Days 31-60: prove contracts and boundaries

- Approve system-of-record boundaries and first ADR set.
- Workshop canonical invoice, error catalogue, certificate/QR and correction lifecycle.
- Produce taxpayer identity, VAT rule and return-mapping conformance packs.
- Prototype API/idempotency, atomic invoice-ledger-outbox, HSM signing and public verification.
- Validate ITAS/customs integration options and partner sandbox plan.
- Define target operating queues, roles, service levels and support model.

### Days 61-90: approve build readiness

- Load/security/resilience-test the transaction-core proof using the provisional capacity model.
- Complete hosting/residency, DR, records and cryptographic architecture.
- Select pilot cohorts/partners and execute conformance onboarding rehearsal.
- Finalise MVP backlog, acceptance criteria, release evidence and rollback/co-existence.
- Obtain formal Gate 0 approval or record blockers with accountable closure dates.

> **Build-readiness rule.** Do not begin production feature delivery merely because a framework and database are available. Begin when legal states, identifiers, rules, boundaries, evidence, NFRs and operational ownership are approved enough to make implementation testable.

# Appendix A. Detailed API Catalogue

| API | Operation | Idempotent? | Authorisation | Notes |
|---|---|---|---|---|
| `/v1/invoices` | POST submit fiscal document | Yes, required key | Client scope + supplier taxpayer | Returns 201 certificate or 202 processing; 422 validation evidence |
| `/v1/invoices/{id}` | GET document status | Safe | Owning supplier/buyer or assigned NamRA purpose | Buyer field visibility follows relationship/purpose |
| `/v1/invoices/{id}/corrections` | POST correction/credit/debit request | Yes | Supplier/approved role | Original reference and reason required |
| `/v1/certificates/{id}` | GET signed receipt | Safe | Authorised party | Full certificate; not public payload |
| `/v1/verify/{token}` | GET privacy-minimised verification | Safe | Public | Opaque token; strict rate/abuse control |
| `/v1/buyer-confirmations` | POST confirm/dispute purchase | Yes | Buyer taxpayer | Opens/resolves matching workflow under rules |
| `/v1/exceptions` | GET scoped queue | Safe | Taxpayer or NamRA case/portfolio scope | Cursor pagination and export restrictions |
| `/v1/exceptions/{id}/responses` | POST response/evidence manifest | Yes | Eligible taxpayer/assigned officer | Attachments use pre-signed controlled upload flow |
| `/v1/tax-periods/{id}/return-draft` | GET draft/evidence | Safe | Taxpayer/agent/NamRA role | Exact ledger cutoff and rule version |
| `/v1/vat-returns/{id}/approve` | POST approval | Yes | Approved taxpayer/agent | Strong re-authentication/signature policy |
| `/v1/vat-returns/{id}/submit` | POST ITAS submission | Yes | Approved taxpayer/agent/service | Records payload hash and external receipt |
| `/v1/offline/devices` | POST enrol/renew device | Yes | Taxpayer admin + partner/device policy | Certificate issuance and attestation |
| `/v1/offline/batches` | POST signed ordered batch | Yes | Active device/taxpayer | Per-document outcomes; gap/replay controls |
| `/v1/partners/conformance-runs` | POST run/record tests | Yes | Partner/integration admin | No production taxpayer data |
| `/internal/v1/taxpayers/synchronise` | POST master delta | Yes | Government service identity | Effective dates and source version |
| `/internal/v1/customs/declarations` | POST/import records | Yes | Customs service identity | Contract/reconciliation owned jointly |
| `/internal/v1/itas/returns` | POST approved return | Yes | VAT-MSA service identity | Final authority and response contract to confirm |

API production requirements include stable error codes, correlation IDs, request/response schema examples, pagination/filter policy, maximums, timeout/retry rules, rate tiers, version/deprecation policy, audit requirements and conformance tests.

# Appendix B. Core Database/ERD Architecture

The core entity groups are:

1. **Master/reference:** Taxpayer, TaxpayerIdentifier, SourceSystem, OfflineDevice, TaxPeriod.
2. **Fiscal transaction:** Invoice, InvoiceLine, InvoiceTaxSummary, VATTransaction, Certificate.
3. **VAT control:** VATLedgerEntry, MatchResult, ExceptionCase, VATReturn.
4. **Compliance:** RiskAlert, AuditCase and refund workflow extensions.
5. **Rules/configuration:** RuleSet and TaxRule with approvals/effective dates.
6. **Reliability/evidence:** IdempotencyRecord, OutboxEvent, OfflineBatch and AuditEvent.

The packaged PostgreSQL schema specifies identifiers, relationships, status constraints, unique keys and critical indexes. Production design adds partitioning by tax point/tenant cell, row-level/policy grants, encryption/tokenisation interfaces, posting procedures, retention/legal holds and migration automation.

# Appendix C. RBAC and Permissions Summary

| Role family | Typical roles | Scope | Prohibited combination/action |
|---|---|---|---|
| Taxpayer seller | Seller Admin, Operator, Viewer | Own represented taxpayer | Cannot grant NamRA/platform roles or alter accepted history |
| Taxpayer buyer | Buyer Admin, User | Own represented taxpayer | Cannot override tax eligibility/rules |
| Compliance | Compliance Officer, Supervisor | Portfolio/organisational unit | High/critical resolution requires supervisor |
| Audit | Auditor, Audit Supervisor | Assigned cases/unit | Lead cannot approve own final decision |
| Refund | Refund Officer, Refund Approver | Assigned cases/approval limit | Preparer cannot approve/release same case |
| Risk | Risk Analyst, Risk Governor | Approved portfolio/national rule/model | Analyst cannot activate own rule/model |
| Data | Data Steward, Analyst | Assigned queue/approved dataset | Identity merge and re-identification tightly controlled |
| Integration | Integration Admin | Assigned partners | No taxpayer payload access by default |
| Platform/security | Platform Operator, System Admin, Security Admin/Analyst | Environment/control domain | No standing taxpayer-data access; key operations dual controlled |
| Assurance | Internal Auditor | National read-only evidence | Cannot administer reviewed identities/controls |

The row-level permission catalogue is supplied as `05-security/rbac-matrix.csv`.

# Appendix D. Non-Functional Requirements Summary

- Core certification and verification availability: provisional 99.95 percent monthly.
- Synchronous invoices up to 100 lines: p95 <= 2 seconds and p99 <= 5 seconds.
- Large invoices up to 10,000 lines use asynchronous processing; p95 <= 2 minutes under reference load.
- Provisional capacity proof: 2,000 documents/second sustained and 10,000/second burst.
- Tier 0 RTO <= 60 minutes and RPO <= 5 minutes, subject to business-impact approval.
- Every certificate has exactly one committed canonical invoice, VAT transaction and balanced posting.
- Event propagation: 99 percent of committed outbox events published within 10 seconds.
- Web apps target WCAG 2.2 AA and core journeys support constrained networks.
- Production release requires signed artifact/provenance, SBOM, reconciliation, security, performance, observability and recovery evidence.

The complete measurable catalogue is in `06-delivery/non-functional-requirements.md`.

# Appendix E. Testing Strategy Summary

The test pyramid is supplemented by fiscal conformance, property/invariant, contract, data reconciliation, security, performance, resilience and DR testing. Critical golden scenarios include standard/mixed/zero/exempt invoices, non-registered buyer, retries/duplicates, correction chains, matching exceptions, offline gaps/replay, effective-date rules, closed periods and segregated refunds.

No release may carry an open critical security, statutory calculation, ledger-integrity or data-loss defect. Failed source-to-ledger reconciliation, audit-chain check or restore blocks promotion. The complete strategy and evidence requirements are in `06-delivery/testing-strategy.md`.

# Appendix F. Architecture Deliverable Register

This blueprint and repository directly provide or seed:

- Executive overview, business capability map, system context and application architecture.
- Domain/module, transaction, matching, VAT ledger, return and NamRA architectures.
- Integration/API/event/SaaS, database/data/analytics and reporting architectures.
- Security/IAM/privacy, offline, infrastructure/cloud/DR and DevSecOps architectures.
- Technology stack, repository model, API catalogue, canonical invoice schema and event catalogue.
- PostgreSQL reference schema/data dictionary, RBAC and security control matrices.
- NFRs, testing strategy, implementation phases and MVP-to-national-scale roadmap.
- Architecture decisions, assumptions, open decisions and governance workflow.

Follow-on formal artefacts should include the approved legal/fiscal rules specification, detailed ITAS/customs interface control documents, infrastructure low-level design, DPIA/threat model, records schedule, operational runbook set, partner certification guide, procurement requirements and costed programme plan.

# Appendix G. Glossary

| Term | Meaning in this blueprint |
|---|---|
| Canonical invoice | The national, versioned VAT-MSA representation into which partner documents are mapped. |
| Certificate | Signed VAT-MSA receipt attesting to the canonical document and status at a point in time. |
| VAT transaction | Controlled relationship joining invoice, seller, buyer, VAT positions, period and evidence. |
| VAT sub-ledger | Append-only VAT-specific posting record independent of taxpayer general ledgers. |
| Input candidate | Buyer-side VAT amount requiring identity and eligibility rules before claim treatment. |
| Match result | Versioned decision linking seller and buyer evidence with method, confidence and differences. |
| Exception | Controlled discrepancy requiring owner, evidence, service target and disposition. |
| ITAS | NamRA's Integrated Tax Administration System and taxpayer e-filing/account service. |
| Transactional outbox | Events committed in the same database transaction as business state, then published reliably. |
| Idempotency | Repeating the same authorised operation does not create a duplicate business effect. |
| RPO | Maximum tolerable data point to which recovery may fall back. |
| RTO | Target time to restore an acceptable business service. |
| HSM | Hardware Security Module used to protect high-value cryptographic keys. |
| ADR | Architecture Decision Record documenting a consequential decision and trade-offs. |

# Appendix H. Source and Standards Baseline

Public context and standards reviewed for this baseline:

1. Namibia Revenue Agency, ITAS overview and taxpayer e-filing/VAT return functions: https://www.itas.namra.org.na/about
2. Namibia Revenue Agency, Value Added Tax Brochure (Act 10 of 2000, standard-rate and tax-invoice overview): https://www.itas.namra.org.na/assets/documents/other-forms/Value_Added_Tax_Brochure.pdf
3. Namibia Revenue Agency, Annual Report FY2024/5, e-invoicing project statement: https://www.namra.org.na/documents/cms/uploaded/namra-annual-report--20245-1ff718d775.pdf
4. Namibia Ministry of Finance and Public Enterprises, Fiscal Strategy 2026/27, VAT modernisation/e-invoicing policy direction: https://mfpe.gov.na/documents/76368/7622296/Fiscal%2BStrategy%2B2026-2027%2B%28New%29.pdf
5. Value-Added Tax Act, 2000 (Act 10 of 2000), consolidated public legal text: https://namiblii.org/akn/na/act/2000/10/eng%402024-10-01
6. Parliament of Namibia, 25 June 2026 Order Paper, Data Protection Bill introduction: https://www.parliament.na/wp-content/uploads/2026/06/Order-Paper-23-June26.pdf
7. NIST Cybersecurity Framework 2.0: https://www.nist.gov/cyberframework
8. NIST SP 800-207, Zero Trust Architecture: https://csrc.nist.gov/pubs/sp/800/207/final
9. OWASP API Security Top 10: https://owasp.org/API-Security/
10. OpenAPI Specification: https://spec.openapis.org/oas/
11. CloudEvents specification project: https://cloudevents.io/

This source list supports architecture context and controls. It is not a substitute for formal NamRA legal interpretation, approved policies, current Gazette review or authoritative technical interface specifications.
