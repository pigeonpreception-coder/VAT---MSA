# VAT-MSA Enterprise Architecture Blueprint

## Part XXVI - Global core and country compliance packs

VAT-MSA is extended from a Namibia-shaped baseline into a global platform core with isolated, signed and effective-dated country compliance packs. The global core owns identity, tenancy, workflow, exact money, documents, audit, APIs/events and operational controls. A country pack supplies only jurisdiction-authorized tax, identifier, document, filing, calendar, retention, localization and government-adapter configuration. Jurisdiction is resolved authoritatively and pinned with the selected country-pack and rule versions on every fiscal determination.

Namibia is the first reference pack. Its presentation symbol is `N$` and its persisted/interchange currency code is `NAD`; a bare `$` is prohibited in fiscal interfaces. The reference pack remains non-executable and `UNDER REGULATORY REVIEW`. Tax rates, deadlines, invoice semantics, retention, ITAS integration and other statutory behavior require the explicit confirmations recorded in the pack and the formal country-readiness gate.

Authoritative detail: [globalisation and country-compliance architecture](31-globalisation-country-compliance-architecture.md), [Namibia reference pack](32-namibia-country-compliance-pack.md), [country readiness framework](country-readiness-framework.csv), [globalisation traceability](globalisation-requirements-traceability.csv), and ADRs [020](adr/ADR-020-global-core-country-pack-model.md) through [024](adr/ADR-024-regulatory-administration-separation.md).

## Part XXIV - Enterprise workspace, organisation administration and licensing

The taxpayer portal becomes one hierarchical, permission- and licence-aware workspace over the canonical organisation. Navigation is a server-built projection evaluated from identity, organisation, licence, capabilities, roles, permissions and security policy. Buyer and Seller remain organisation capabilities. Organisation Portal Administrator is the canonical organisation-admin role; it is separate from NamRA and platform administration.

A distinct License and Entitlement domain owns effective plans, subscriptions, feature grants, limits and usage. It can deny an unlicensed operation but can never override identity, tenant, tax, audit, security, SoD or retention controls. Expiry, suspension, cancellation and downgrade preserve taxpayer records and follow a separately approved statutory-continuity policy.

Authoritative detail: [workspace, organisation, licensing and workflow extension](30-workspace-organisation-licensing-workflow-architecture.md), [configuration/control matrix](configuration-system-control-matrix.csv), ADRs [016](adr/ADR-016-backend-enforced-dynamic-workspace.md), [017](adr/ADR-017-license-entitlement-authority.md) and [018](adr/ADR-018-organisation-configured-access.md).

## Part XXV - Versioned workflow and access governance

Organisation administrators configure workflows from a typed, allowlisted vocabulary. Publication creates an immutable version; each transaction pins its original version; decisions are append-only. Domain services retain authority for legal transitions. Mandatory SoD evaluates at design, assignment and decision time, preventing self-approval and create/approve/execute combinations where policy prohibits them.

Access request, certification and offboarding preserve immutable evidence, rapidly revoke sessions/tokens/credentials, reassign pending tasks under approved policy and never erase historical actor ownership.

Authoritative detail: [workflow extension](30-workspace-organisation-licensing-workflow-architecture.md), [workflow diagram](diagrams/workflow-versioning-sod.mmd), ADR [019](adr/ADR-019-versioned-workflow-and-sod.md), and [extension traceability](workspace-licensing-requirements-traceability.csv).

**Document state:** complete architecture-board review package; **not production authorization**. This consolidated blueprint is the controlled entry point to all 45 deliverables. Detailed specifications and machine-readable matrices are linked in each part.

## Part I — Executive Architecture

VAT-MSA is a national-scale, multi-tenant fiscal platform that gives one verified taxpayer organisation a consistent identity across taxpayer, NamRA and super-administration experiences. It records certified invoices, derives immutable input/output VAT transactions, reconciles evidence, prepares versioned returns, supports governed audit/refund processes and integrates with ITAS and approved SaaS providers.

The recommended architecture is a domain-modular core with explicit contracts, an API gateway, durable event backbone, relational fiscal system of record, encrypted object storage, permission-aware search and an independently governed analytics platform. Domains become independent services only when scale, availability, isolation or ownership evidence warrants it. Security, privacy, auditability, accessibility, offline continuity and recovery are cross-cutting controls.

Production coding remains gated by external authority and assurance. ITAS contracts, statutory tax/numbering/correction/refund rules, legal retention/privacy duties, offline legal effect, production SLO/capacity and tested DR are unresolved critical inputs.

Authoritative detail: [Executive summary](00-executive-architecture-summary.md), [enterprise solution architecture](01-enterprise-solution-architecture.md), [deliverable register](00-deliverable-register.md).

### Architectural principles

1. One legal taxpayer identity maps to one active organisation; separate entities remain separate.
2. Buyer and seller are dynamic transaction roles.
3. Prefer ITAS federation when confirmed; controlled standalone identity links to the same user.
4. Default-deny zero trust uses RBAC, ABAC, purpose and tenant scope.
5. Certified fiscal records and audit evidence are append-only; correction is explicit.
6. Tax behavior is typed, versioned, effective-dated, signed and testable configuration.
7. APIs are contract-first; events decouple consequences where immediate consistency is unnecessary.
8. Each domain owns its model; external systems have explicit authoritative boundaries.
9. Privacy minimisation, security, accessibility, operations and recovery are designed in.
10. Stateless horizontal scale, idempotency, backpressure, partitioning and measured SLOs govern national readiness.

## Part II — C4 Architecture

At Level 1, taxpayers, practitioners, NamRA officers, administrators, developers/SaaS systems, ITAS, banks/payments and notification providers interact with the VAT-MSA trust boundary. Level 2 separates edge/portals, identity/policy, domain runtime, integrations, data/event stores, analytics and security/operations. Level 3 decomposes the fiscal core into party, quotation, numbering, invoice, VAT transaction, reconciliation, return, rule, document and audit components. Level 4 defines package dependency direction: interface -> application -> domain, with infrastructure adapters implementing domain ports.

Trust crosses only authenticated, authorized and observable gateways; no UI or partner accesses a database directly. Synchronous calls serve bounded command/query needs; the outbox publishes signed/versioned consequences.

Authoritative detail: [C4 enterprise architecture](10-c4-enterprise-architecture.md) and diagrams [L1](diagrams/c4-level-1-context.mmd), [L2](diagrams/c4-level-2-containers.mmd), [L3](diagrams/c4-level-3-fiscal-components.mmd).

## Part III — Business and Domain Architecture

The bounded contexts are: Identity; Taxpayer; Organisation; User/Membership; Authorization; Consent/Delegation; Party; Quotation; Invoice; Numbering/Certification; VAT Transaction; Tax Rules; Period/Calendar; Reconciliation; Return; Accounting; Expense; Inventory; Project; Import; Currency/Reference; Document; Notification/Communication; Audit Case; Dispute/Objection; Compliance/Risk; Refund; Integration; Developer/Sandbox; Reporting/Analytics; and Platform Administration/Operations. Each owns commands, queries, events, data and business invariants. Accounting and tax ledgers remain separate but reconcile through explicit control accounts.

Buyer/seller behavior is contextual; delegation never transfers legal identity; a risk score recommends but does not make opaque final adverse decisions.

Authoritative detail: [domain architecture](02-domain-architecture.md), [domain capability catalog](11-domain-capability-catalog.md), [domain map](diagrams/domain-map.mmd).

## Part IV — Application and Service Architecture

The initial target is a modular core plus independently scalable edge, integration, document processing, event consumers and analytics workloads. Extraction criteria are measured scale, different availability class, security/data isolation, autonomous team ownership or materially different change rate. Contracts exist before extraction; cross-domain table reads and distributed fiscal transactions are prohibited.

Interfaces comprise taxpayer/NamRA/super-admin portals, desktop/offline client, developer portal and public/partner APIs. Application services orchestrate use cases. Pure domain policies enforce invariants. Adapters isolate database, broker, object store, ITAS and SaaS technologies.

Authoritative detail: [architecture style assessment](12-architecture-style-assessment.md), [API architecture](06-api-integration-architecture.md), [development architecture](25-development-environment-architecture.md).

## Part V — Data Architecture and Complete ERD

The authoritative operational store is strongly consistent relational data with explicit organisation/tenant keys, PK/FK/unique/check constraints, optimistic versions and append-only fiscal/ledger records. Documents use encrypted object storage plus relational metadata. Events use a durable log; search and analytics are rebuildable projections; cache contains safe bounded-staleness data only.

Core entity families include identity/user/session/role/policy; taxpayer/organisation/branch/membership/consent; party/quotation/invoice/line/number series; VAT transaction/rule/period/reconciliation/return/adjustment; account/journal/expense/warehouse/stock/project/import/currency; document/notification; audit/dispute/risk/refund; integration/webhook/idempotency/outbox/inbox/audit event. Every fiscal result retains source provenance and applicable rule version.

Authoritative detail: [data architecture and ERD](05-data-architecture-and-erd.md), [logical data dictionary](13-logical-data-dictionary.md), [enterprise ERD](diagrams/enterprise-erd.mmd), [classification/retention matrix](data-classification-retention.csv).

## Part VI — Identity, IAM, RBAC and ABAC

Federated and standalone authentication resolve to the same immutable internal user. Authorization is centrally evaluated from role, tenant, branch/entity, assignment, consent, purpose, resource state, assurance, device/risk and time. The client cannot choose a trusted tenant. Privileged access is JIT, step-up, segregated and session-audited. Revocation invalidates sessions and downstream entitlement caches within an approved bound.

Authoritative detail: [identity and access architecture](04-identity-rbac-abac.md), [RBAC/ABAC matrix](rbac-abac-matrix.csv), ADRs [001](adr/ADR-001-one-taxpayer-one-organisation.md), [003](adr/ADR-003-ITAS-identity-provider.md), [004](adr/ADR-004-standalone-authentication.md), [008](adr/ADR-008-multi-tenancy.md).

## Part VII — ITAS Integration Architecture

An anti-corruption adapter brokers federation, taxpayer verification/status, return submission/status/acknowledgement and other confirmed functions. Requests are schema-validated, idempotent, correlated and auditable. Timeouts create an unknown outcome and status query; circuits and durable queues prevent cascades. Source authority and cached snapshot age are visible.

Protocol, claims, payloads, error semantics, rate limits, SLAs, legal authority and sandbox are **REQUIRES ITAS CONFIRMATION**.

Authoritative detail: [ITAS and SaaS integration](14-itas-saas-integration.md), [trust boundaries](diagrams/integration-trust-boundaries.mmd).

## Part VIII — SaaS and API Integration Architecture

Partner onboarding uses registered clients, least-privilege scopes, taxpayer consent, sandbox conformance, key rotation, quotas and production approval. Connectors normalize data with source/version/hash provenance. Webhooks are signed, timestamped, replay-protected and retried through durable delivery records. Per-provider bulkheads/circuits and reconciliation isolate compromise/outage.

The API standard defines resource naming, versioning, pagination, filtering, idempotency, optimistic concurrency, safe problem details, asynchronous jobs, rate limits, deprecation and OpenAPI/AsyncAPI ownership.

Authoritative detail: [API contract catalog](15-api-contract-catalog.md), [API catalog](api-catalog.yaml), [integration architecture](14-itas-saas-integration.md).

## Part IX — Event Architecture

Canonical events use a versioned envelope with event/aggregate IDs, aggregate version, tenant, type/schema, occurred/published times, actor/correlation/causation, classification and payload. Publication uses a transactional outbox; consumption uses inbox/idempotency, checkpoints, DLQ and controlled replay. Ordering is per aggregate/partition, never global.

The required lifecycle includes taxpayer/user, quotation/invoice, VAT transaction/period/return, audit/refund, document and security events.

Authoritative detail: [event architecture](08-event-deployment-devsecops-testing.md), [event catalog](event-catalog.csv), ADR [007](adr/ADR-007-event-driven-integration.md).

## Part X — VAT Transaction and Return Architecture

Invoice certification validates authoritative parties, numbering and a pinned tax-rule bundle inside one fiscal command boundary; it persists invoice, append-only VAT transaction and outbox atomically. Seller output VAT and buyer input candidate share the certified transaction but retain distinct eligibility/status. Correction creates linked compensating records.

Period close snapshots data/rules, reconciliation classifies exceptions, return generation traces every box to transactions/adjustments and maker-checker submission stores immutable payload plus external acknowledgement. External timeout is reconciled before retry.

Authoritative detail: [VAT transaction/return/offline](16-vat-transaction-return-offline.md), [transaction sequence](diagrams/vat-transaction-sequence.mmd).

## Part XI — Security and Cybersecurity Architecture

Security zones separate edge, identity, application, integration, data, analytics, management, security and recovery. Workload identity/mTLS, gateway/WAF, centralized authorization, encryption/key custody, secrets management, document quarantine, DLP, EDR/CWPP, PAM, SIEM/SOAR, tamper evidence and zero-trust segmentation apply.

The STRIDE model covers credential/token abuse, tenant escape, injection, invoice/refund fraud, offline tamper, third-party compromise, DDoS, database/audit/supply-chain compromise, ransomware and insider threat. Prevent/detect/respond controls and abuse tests are assigned.

Authoritative detail: [security architecture](07-security-scale-recovery.md), [STRIDE threat model](17-threat-model-stride.md), [security operations](18-security-operations-topology.md), [security zones](diagrams/security-zones.mmd), ADR [010](adr/ADR-010-zero-trust-security.md).

## Part XII — Infrastructure and Cloud Architecture

Public edge routes to private multi-zone application pools; data endpoints have no public path. A dedicated integration zone brokers external access. Management uses JIT bastion/control planes; security telemetry is independent; immutable backups and clean-room recovery are isolated. Platform choice must satisfy sovereignty, portability, encryption, HA, observability, cost and skills criteria.

Authoritative detail: [security/operations topology](18-security-operations-topology.md), [infrastructure diagram](diagrams/infrastructure-topology.mmd), ADRs [006](adr/ADR-006-database-strategy.md) and [008](adr/ADR-008-multi-tenancy.md).

## Part XIII — Scalability, Performance and High Availability

Stateless horizontal replicas, stable partition keys, tenant fairness, bulkheads, reserved fiscal capacity, read models and asynchronous bulk work absorb deadline peaks. Proposed service objectives range from 99.95% for identity/fiscal write paths to 99.5% for bulk work; they require measured baseline and approval. Multi-zone failover, backpressure, load shedding and compatible progressive delivery are mandatory.

Authoritative detail: [HA/scalability/performance/observability](19-ha-scalability-performance-observability.md), ADR [011](adr/ADR-011-scalability-and-availability.md), [HA/DR diagram](diagrams/ha-dr-topology.mmd).

## Part XIV — Disaster Recovery and Business Continuity

Tier 1 identity/fiscal recovery proposes RTO 30 minutes and RPO <= 5 minutes, subject to approval. Recovery restores control plane, identity/keys, databases, events, fiscal services, edge, integrations, documents and analytics in order, then reconciles hashes, ledger totals, events and external outcomes. ITAS/network/cyber/region/overload continuity modes are explicit. Monthly through annual restore/failover/cyber exercises produce evidence; failback is separately approved.

Authoritative detail: [DR and continuity](20-dr-business-continuity.md), [HA/DR diagram](diagrams/ha-dr-topology.mmd).

## Part XV — UX and Portal Architecture

The Taxpayer Portal emphasizes invoices, purchases/sales VAT, reconciliation, draft returns, obligations, documents and delegated teamwork. The NamRA Portal emphasizes authorized search, compliance, risk, audit/refund cases and national operations. Super Admin emphasizes tightly controlled identity/policy/integration/rule/platform configuration. Responsive and low-bandwidth experiences meet WCAG 2.2 AA target; dangerous actions state impact, require step-up/approval and provide traceable receipts.

Authoritative detail: [portal UX and design system](03-portal-ux-design-system.md).

## Part XVI — End-to-End Process Architecture

Thirty-four controlled flows cover registration, authentication/access, consent, branch/quotation/invoice/correction/verification, input VAT/offline/sync, accounting operations, period/reconciliation/return/refund, calendar/audit/objection/risk, document/integration/API/communication/export, incident and tax-rule change. Each defines trigger, logical flow, exceptions, controls and completion evidence.

Authoritative detail: [business process catalog](23-business-process-catalog.md), [offline sync diagram](diagrams/offline-sync.mmd).

## Part XVII — Observability and Operations

Trace/correlation context crosses HTTP and events. Low-cardinality metrics, minimized structured logs and adaptively sampled traces cover golden signals, fiscal correctness, integrations, security, data quality and business deadlines. Symptom/SLO alerts link owner, severity and runbook. NOC, SOC, domain and executive dashboards use purpose-scoped views. Errors use stable safe problem details; resilience uses explicit deadlines, bounded retries, circuits, bulkheads, backpressure and reconciliation.

Authoritative detail: [observability](19-ha-scalability-performance-observability.md), [error and API resilience](24-error-api-resilience.md), [security operations](18-security-operations-topology.md).

## Part XVIII — DevSecOps and Testing

An approved monorepo separates apps, services/modules, contracts, data, platform, tests and architecture. Isolated local/integration/security/performance/sandbox/UAT/pre-production/production/recovery environments use synthetic data by default. CI creates signed reproducible artifacts, SBOM and provenance; promotion runs functional, contract, security, privacy, accessibility, performance, failover and DR gates. The same artifact is progressively promoted with observable rollback.

Authoritative detail: [development/environment/DevSecOps/testing](25-development-environment-architecture.md), [deployment/testing architecture](08-event-deployment-devsecops-testing.md).

## Part XIX — Architectural Decision Records

ADRs 001–012 cover taxpayer identity, dynamic buyer/seller, ITAS identity, standalone access, service boundaries, database strategy, event integration, multi-tenancy, offline capability, zero trust, scalability/availability and tax rules. All are proposals until their named approval gates are signed; this prevents design intent from being represented as policy authority.

Authoritative detail: [ADR directory](adr/).

The extension record is ADR-016 through ADR-024: workspace/licensing/workflow, global core and country packs, exact money, jurisdiction binding, pack signing/readiness, and regulatory-administration separation. These ADRs remain proposed until the approval gate records their acceptance.

## Part XX — Requirements Traceability Matrix

Every master requirement section 1–112 maps to architecture component, service/module, data store, API/event, security control, business process and verification method. Requirement 101 and 112 enforce the no-production-coding approval gate.

Authoritative detail: [requirements traceability matrix](26-requirements-traceability-matrix.csv).

## Part XXI — Risk, Gap and Maturity Analysis

The leading risks are incorrect legal/tax assumptions, unconfirmed ITAS authority, identity/tenant breach, fiscal/refund fraud, national outage, cyber/supply-chain compromise and insufficient delivery capability. Critical gaps are ITAS contracts, authoritative tax/legal controls, production security/privacy approval and funded/tested HA/DR. Overall maturity is **Developing**, with a target of Defined/Advanced by capability before national production.

Authoritative detail: [risk, gap and maturity](27-risk-gap-maturity.md).

## Part XXII — Implementation Roadmap

Phases 0–10 proceed from authority/governance; platform assurance; canonical identity; invoice/VAT core; operational accounting; return; NamRA audit/refund; ecosystem; offline/inclusive; analytics/operations; to national rollout. Every phase has dependencies, risk and evidence-based exit gates. Regulatory, security/privacy, data, operations, UX/change and migration are continuous workstreams.

Authoritative detail: [detailed roadmap](28-detailed-roadmap.md).

## Part XXIII — Final Architecture Approval Gate

The package status is `REQUIRES DECISION`. Architecture/design may continue; production feature coding is authorized only for a bounded phase whose applicable decisions are `APPROVED` or `APPROVED WITH CONDITIONS`. `NOT READY` blocks that component. Current `NOT READY` items include offline fiscal capability, production SLO/capacity/HA evidence and DR/BCP evidence.

Sign-off requires Executive Sponsor, Architecture Board, NamRA Tax Policy, ITAS, Security, Privacy, Legal, Data, Operations/DR and Programme/Product authorities. Material changes reopen affected decisions.

Authoritative detail: [formal approval gate](29-architecture-approval-gate.md).

## Blueprint completeness index

Globalisation coverage adds the global core, country-pack lifecycle, multi-currency, Namibia reference pack and country-readiness framework in Part XXVI and artifacts 31-32.

| Required blueprint content | Location |
|---:|---|
| 1 Executive Summary; 2 Principles | Parts I and [executive summary](00-executive-architecture-summary.md) |
| 3 C4; 4 Domain; 5 Service | Parts II–IV |
| 6 Data; 7 ERD | Part V and [logical dictionary](13-logical-data-dictionary.md) |
| 8 Identity; 9 ITAS; 10 SaaS | Parts VI–VIII |
| 11 API Catalogue; 12 Events | Parts VIII–IX |
| 13 Security; 14 Threat Model; 15 Infrastructure | Parts XI–XII |
| 16 Scalability; 17 HA/DR | Parts XIII–XIV |
| 18 UX; 19 Business Process; 20 RBAC/ABAC | Parts XV–XVI and Part VI |
| 21 Data Governance; 22 Tax Rules | [governance and tax rules](21-data-governance-master-tax-rules.md) |
| 23 Audit; 24 Reporting | [audit/refund/reporting](22-audit-refund-reporting.md) |
| 25 DevSecOps; 26 Testing | Part XVIII |
| 27 ADRs; 28 Requirements Traceability | Parts XIX–XX |
| 29 Risk; 30 Gap; 31 Roadmap | Parts XXI–XXII |
