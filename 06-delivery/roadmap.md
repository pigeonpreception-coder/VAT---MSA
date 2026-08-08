# VAT-MSA Delivery Roadmap

The roadmap uses outcome gates. Calendar estimates begin only after Gate 0 establishes mandate, legal rules, integration feasibility, volume, procurement and team capacity.

## Gate 0 - Mandate, legal design and discovery

**Outcome:** an approved programme charter and implementable legal/operating specification.

- Confirm e-invoicing mandate, cohorts, statutory invoice states and taxpayer rights.
- Confirm ITAS/customs/payment/refund integration and system-of-record boundaries.
- Establish taxpayer, invoice, event, security and records governance.
- Approve hosting/data-residency model, RTO/RPO, volume model and procurement route.
- Produce the official VAT rule conformance pack and return-form mapping.

**Exit:** Architecture Review Board, Legal, VAT Policy, Security, Data Governance, Operations and Product sign the foundation decisions.

## Phase 1 - Foundation

**Outcome:** secure platform capable of onboarding controlled pilot participants.

- IAM, organisation/role model, API clients and partner onboarding.
- Effective-dated taxpayer master replica and identity resolution.
- API gateway, observability, audit evidence, CI/CD, secrets/KMS and initial DR.
- Canonical schemas, conformance sandbox, developer portal and connector framework.

**Exit:** security architecture, operational acceptance, partner conformance and restore test pass.

## Phase 2 - Electronic invoicing pilot (MVP)

**Outcome:** selected sellers can submit, validate, certify and verify invoices.

- Invoice lifecycle, validation, duplicate controls and effective-dated rule engine.
- Signed certificate/QR, public privacy-minimised verification and correction chain.
- Taxpayer portal status/errors and integration operations dashboard.
- Pilot adapters for representative POS, ERP and accounting providers.

**Exit:** legal conformance pack passes; no critical defects; pilot transaction reconciliation is complete.

## Phase 3 - VAT transaction and sub-ledger

**Outcome:** every accepted fiscal document produces traceable VAT positions.

- Atomic VAT transaction and balanced sub-ledger posting.
- Seller output VAT, buyer input candidate, imports/adjustments foundation.
- Transactional outbox, event backbone and replay-safe consumers.
- Operational reconciliation across invoice, certificate, transaction, ledger and event streams.

**Exit:** ledger invariants, recovery and accounting/control reconciliation pass at target load.

## Phase 4 - Reconciliation and return co-existence

**Outcome:** taxpayer periods are built from transaction evidence and reconciled with current filing.

- Deterministic seller/buyer matching and exception workflow.
- Period close, return draft, adjustment/amendment handling and reproducible calculation evidence.
- Controlled ITAS submission/co-existence interface; no duplicate statutory authority.
- Taxpayer views for purchases, input candidates, exceptions and return support.

**Exit:** parallel run reconciles approved taxpayer samples to authoritative returns and finance controls.

## Phase 5 - NamRA audit, risk and refund controls

**Outcome:** NamRA officers work from explainable transaction evidence.

- Compliance, audit, risk and refund workspaces with segregation of duties.
- Electronic audit file, evidence chain, case management and taxpayer response.
- Explainable rule-based risk, model governance and refund decision support.
- Management information and operational performance reporting.

**Exit:** audit/refund operating procedures, appeals, evidence admissibility and supervisory controls pass acceptance.

## Phase 6 - Offline and broad taxpayer rollout

**Outcome:** low-connectivity and non-integrated taxpayers participate safely.

- Enrolled desktop client, encrypted local store, signed ordered queue and synchronisation.
- Approved provisional/offline policy, sequence and tamper controls, support and remote revocation.
- Phased adoption by risk/volume segment, partner certification scale-up and service desk readiness.

**Exit:** field pilot proves usability, sync integrity, device recovery, fraud controls and support capacity.

## Phase 7 - National scale and advanced analytics

**Outcome:** resilient national operation with governed analytical capability.

- Cell-based horizontal scale and proven multi-site DR.
- Warehouse/lakehouse, graph/network analysis and high-volume reporting.
- Model monitoring, data lineage, quality scorecards and controlled analytical workbenches.
- Continuous optimisation of partner onboarding, taxpayer experience and compliance interventions.

**Exit:** national capacity/DR exercise, independent security assessment and operational governance approval.

## Rollout principles

- Pilot before mandate; measure support burden and data quality, not only API success.
- Prefer representative cohorts over friendly-only pilots: large ERP, retail POS, SME portal and low-connectivity users.
- Maintain coexistence and rollback for every migration wave.
- Publish schema/rule changes with version, examples, conformance tests and transition period.
- Stop rollout when integrity, legal, capacity, support or security gates fail; do not trade statutory evidence for schedule.
