# VAT-MSA Architecture Implementation Matrix

**Evidence date:** 2026-08-22
**Repository scope:** executable controlled pilot and production-oriented application foundation  
**Governing architecture:** `08-enterprise-architecture/` and its approval gate

This matrix is the truthful completion record for the approved architecture. It separates working repository capabilities from controls that require NamRA, ITAS, legal, security, provider or infrastructure authority. A disabled boundary is an implemented safety control, not a simulated external service.

## Status vocabulary

- **VERIFIED PILOT** — executable end-to-end behavior is implemented and has automated or runtime evidence.
- **CONTROLLED FOUNDATION** — the tenant-scoped model, policy, data and read/workflow surface exist, but some lifecycle commands remain phased.
- **DISABLED PENDING AUTHORITY** — an adapter or state machine exists and explicitly refuses legal/financial effect until an approved contract, credential or rule set is supplied.
- **ARCHITECTURE / PRODUCTION EVIDENCE** — design artifacts exist, but completion can only be demonstrated in the selected national production environment.

## Domain coverage

| # | Domain | Current implementation | Status | Required closure before national production |
|---:|---|---|---|---|
| 1 | Identity | Provider registry, immutable provider-subject links, provisioned-user checks, role/capability policy and development-only identity fallback | CONTROLLED FOUNDATION | Approved ITAS/enterprise IdP claims, MFA, recovery, lifecycle and assurance tests |
| 2 | Taxpayer | Canonical taxpayers, identifiers, VAT status, scoped queries and idempotent registration intake | CONTROLLED FOUNDATION | Authoritative ITAS verification, merge/deregistration rules and signed identifier precedence |
| 3 | Organisation | One taxpayer/one organisation constraint, branches, memberships and effective-dated buyer/seller capabilities | VERIFIED PILOT | NamRA lifecycle authority and enterprise policy enforcement evidence |
| 4 | User management | Users, memberships, roles, permissions, grants and separated privileged portal projections | CONTROLLED FOUNDATION | Enterprise provisioning, invitation/suspension workflows, PAM and periodic access certification |
| 5 | Buyer/Seller | Dynamic organisation capabilities and transaction-context roles without duplicate taxpayer identities | VERIFIED PILOT | Legal terminology and operating-policy approval |
| 6 | Customer | Tenant-scoped create/update and non-destructive deactivate lifecycle, duplicate identifier checks, active-relationship enforcement, audit/outbox evidence and immutable transaction snapshots | VERIFIED PILOT | Authoritative customer lookup/verification contract and conformance evidence |
| 7 | Supplier | Tenant-scoped create/update and non-destructive deactivate lifecycle, VAT/TIN snapshots, duplicate identifier checks and active-relationship enforcement for new expenses | VERIFIED PILOT | Authoritative supplier lookup/verification adapter and conformance evidence |
| 8 | Quotation | Server-calculated issue, hash-chained immutable edit revisions, rejection reasons, explicit overdue expiry, acceptance guards and recoverable idempotent conversion to a certified invoice | VERIFIED PILOT | Configurable approval-threshold contract and UAT |
| 9 | Tax invoice | Submission, validation, duplicate/idempotency controls, certification, public verification, credit/debit correction lineage and certificates | VERIFIED PILOT | Legal particulars, official numbering/reservations, cancellation policy and HSM signature profile |
| 10 | VAT | Exact integer calculation, tax categories/rates, eligibility behavior and versioned rule-model foundation | CONTROLLED FOUNDATION | NamRA-owned rule catalogue, effective dates, golden cases and approval/rollback workflow |
| 11 | VAT transaction | Atomic invoice/certificate/VAT ledger writes, signed correction effects, adjustments, audit and outbox | VERIFIED PILOT | Enterprise ledger reconciliation and target-load recovery evidence |
| 12 | Accounting | Chart of accounts, balanced immutable journal posting and account/balance views | VERIFIED PILOT | Reversal, period close, AR/AP and approved financial-statement rules |
| 13 | Inventory | Product/warehouse model, signed movements, non-negative balance invariant and inventory views | VERIFIED PILOT | Valuation policy, transfers, cycle counts and alert operations |
| 14 | Expense | Tenant-scoped draft capture, integer totals, project/branch links, independent approval/rejection, database-enforced no-self-approval, immutable decision evidence, audit and outbox | VERIFIED PILOT | Receipt-cleanliness policy, accounting posting/reversal policy and UAT |
| 15 | Project | Projects, optional proposed budgets, costs and operational profitability projections | VERIFIED PILOT | Budget approval and governed cost/revenue posting lifecycle |
| 16 | Payment | Bank-import and payment-instruction models with governed connector states | DISABLED PENDING AUTHORITY | Regulated connector contracts, settlement authority, allocation rules and finance segregation of duties |
| 17 | Reconciliation | Registered-buyer matching, exceptions, risk reasons and taxpayer/NamRA work queues | CONTROLLED FOUNDATION | Assignment/resolution commands, authoritative tolerances and operational sampling evidence |
| 18 | VAT return | Period snapshots, reproducible versioned returns, adjustments, maker-checker approval/rejection and immutable submission attempts | VERIFIED PILOT / DISABLED FILING | Official return formulas, ITAS payload/acknowledgement contract and amendment authority |
| 19 | Compliance | Tenant-scoped obligations, calendar/deadlines, status, communications, delegation and consent projections | CONTROLLED FOUNDATION | Authoritative obligation feeds and approved lifecycle commands/notifications |
| 20 | Audit | Case creation, scoped case register, evidence metadata, findings model and taxpayer disputes | CONTROLLED FOUNDATION | Officer authorities, assignment/finding/closure procedure and evidence-admissibility approval |
| 21 | Risk | Explainable invoice scoring, restricted reasons/events, exception escalation and NamRA-only projections | CONTROLLED FOUNDATION | Approved indicators/models, adverse-action review, appeal and model governance |
| 22 | Document | Private R2-backed upload, SHA-256 integrity, quarantine metadata, owner scope and no download before clean scan | VERIFIED QUARANTINE | Approved malware scanner, retention/legal hold, DLP and authorized-download path |
| 23 | Communication | Secure-message/notice records and scoped compliance inbox projection | CONTROLLED FOUNDATION | Send/respond/close commands, templates, service policy and records-retention approval |
| 24 | Notification | Notification, preference and delivery-attempt models with operational projection | CONTROLLED FOUNDATION | Approved email/SMS/push providers, consent rules, retries and delivery receipts |
| 25 | Integration | Provider/connection registry, webhook metadata/deliveries, sync jobs and health projection | VERIFIED FOUNDATION | Per-provider contracts, credentials, conformance sandbox, mTLS/signatures and DLP review |
| 26 | SaaS | Provider/application/environment ownership and conformance metadata | CONTROLLED FOUNDATION | Provider onboarding/approval commands and commercial/security operating model |
| 27 | Developer platform | API-client metadata, scopes, credential-reference posture, webhooks, quotas and developer portal | VERIFIED FOUNDATION | External secret manager, credential issuance/rotation/revocation and gateway enforcement |
| 28 | Reporting | Governed report definitions, scoped run requests, result references and report portal | VERIFIED PILOT | Export generation/custody, approval thresholds, watermark/DLP and large-data performance evidence |
| 29 | Analytics | Operational metrics and approved report projections; no autonomous adverse decisions | ARCHITECTURE / PRODUCTION EVIDENCE | Governed warehouse/lakehouse, lineage, certified metrics, privacy and model controls |
| 30 | Administration | Separate NamRA Admin and Super Admin policies, service-component health and finance-data exclusion from technical admin | VERIFIED FOUNDATION | Central configuration/change APIs, PAM/JIT controls and independent SoD testing |
| 31 | Security | Bounded ingestion, layered rate limits, correlated logs/events, incidents, append-only audit, secret scan and SBOM | VERIFIED FOUNDATION | Production IAM/PAM, KMS/HSM, WAF/SIEM/SOC, penetration test, continuous SCA and response exercises |

## Cross-cutting architecture evidence

| Concern | Repository evidence | Completion boundary |
|---|---|---|
| Tenant isolation | Organisation/taxpayer predicates in repositories, permission checks in page/API handlers, national-scope separation | Independent penetration and policy-bypass testing remains mandatory |
| Data integrity | Integer cents/quantity micros, uniqueness constraints, idempotency records, immutable correction lineage and hash-chained audit | Production database selection, migration rehearsal and independent reconciliation |
| Reliable integration | Transactional outbox, event catalogue, retry-safe commands, webhook/sync delivery state | Managed broker/relay, consumer replay tests and contracted providers |
| Offline safety | Device/range/batch/conflict models; untrusted submissions are rejected with explicit trust failure | Legal offline authority, enrolled device client, signed ranges and field pilot |
| Availability and scale | Health endpoints, rate/fairness controls, capacity/HA/DR manifests, SLO and playbooks | Funded topology, 2x-peak tests, failover and isolated restore exercises |
| Supply chain | Lockfile, lint/type/test/build gate, local secret heuristic and CycloneDX SBOM | Enterprise SAST/SCA, signed provenance, artifact signing and promotion controls |
| Sites data bindings | Structured state uses D1; private evidence blobs use R2 quarantine | Hosted environment binding, residency, backup and key-control approval |

## Verified release evidence

The canonical release gate passed on 2026-08-22:

- ESLint and TypeScript completed without errors.
- 63 unit/security/policy tests passed across ten test files.
- Heuristic secret scan passed and a CycloneDX SBOM was generated.
- The production build completed and exposed all application and API routes.
- Runtime proof converted one accepted quotation to one certified invoice, linked the source quotation, created seller/buyer VAT ledger entries and returned the same invoice on an identical retry.
- Earlier runtime proofs covered versioned return workflows, blocked unconfigured ITAS submission, refund controls, invoice correction lineage, R2 quarantine, offline trust rejection, reports and separated portals.

## Production decision

The **approved implementable pilot scope is built and verified**. The system is **not authorised for statutory or national production**. Production approval remains blocked by the signed decisions and objective evidence listed in `08-enterprise-architecture/29-architecture-approval-gate.md` and `07-security-resilience/10-production-acceptance-gates.md`. No code change can legitimately substitute for those authorities, external contracts or infrastructure exercises.
