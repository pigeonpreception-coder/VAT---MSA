# P-S. Event, deployment, DevSecOps and testing architecture

## Globalisation addendum

The event catalogue now includes country-pack, jurisdiction, currency-rate, tax-selection, document-template, business-calendar, residency and readiness events. CI validates schemas, official-source references, deterministic golden cases, locale/currency formatting, compatibility, signatures and downgrade protection. Promotion is by immutable pack digest and environment-scoped activation record; application deployment does not implicitly activate a country pack.

## Workspace, licence and workflow event extension

The event catalogue now covers licence purchase/activation/state/plan change; organisation administrator and employee lifecycle; role/permission/capability changes; workflow create/publish/retire/decision; access request/approval/certification; SoD violations; privileged actions; and navigation changes. Events use the standard versioned envelope, transactional outbox, tenant/aggregate partition and minimal classified payload.

Security testing must prove that manipulated clients cannot bypass licence, permission, workflow or tenant controls; concurrent quota commands cannot silently overrun limits; self-approval is denied; workflow/audit history cannot be rewritten; and suspension/offboarding revokes access within the approved bound. Performance tests cover thousands of employees/permissions and hundreds of workflows without loading the full graph into the browser.

## Event architecture

The detailed catalogue is `event-catalog.csv`. Domain writes insert an outbox record in the same transaction. A relay publishes to the durable bus; consumers deduplicate by event ID and aggregate/version, enforce tenant scope and record checkpoint/outcome. Events contain references and minimal necessary data, not secrets or unrestricted document payloads.

Ordering is guaranteed only within the chosen partition key (normally taxpayer/organisation or aggregate). Consumers tolerate duplicates and late events. Retries use backoff/jitter; poison records enter a restricted dead-letter workflow with reason, replay approval and audit. Replay uses original IDs/context and cannot create a second statutory transaction.

## Deployment architecture

Source → review → test/scan → reproducible build → SBOM/provenance → signed immutable artifact → development/integration → test → UAT → controlled pilot → production canary → progressive rollout. The exact same artifact digest is promoted. Environment configuration and secret references are external; production data/credentials never enter lower environments.

Schema uses expand-migrate-contract. Releases expose readiness/liveness and application/security/queue/database telemetry. Canary gates combine functional synthetic transactions, SLO burn, errors, authorization denials, data reconciliation and security signals. Automated rollback returns to the previous signed compatible artifact; irreversible data changes use tested forward recovery.

## DevSecOps gates

Protected branches and peer review; lint/type/unit; SAST; dependency/license; secret; generated SBOM and vulnerability monitoring; API schema/contract; IaC/config; container/runtime where applicable; DAST in authorized isolated environment; signature/provenance/admission; post-deploy synthetic and runtime monitoring. Critical findings block unless an accountable authority accepts an expiring residual risk with compensating controls.

The repository's `security:ci` is a local baseline, not a replacement for enterprise scanners, penetration testing, signed builds or managed admission.

## Testing strategy

| Layer | Required evidence |
|---|---|
| Unit/property | exact money/rate rounding, state transitions, rules, scope and canonical hashing |
| Integration | D1/production DB, outbox, idempotency/concurrency, provider adapters and failure handling |
| Contract/conformance | ITAS/SaaS schemas, auth/scopes, errors, replay, version/deprecation and sandbox isolation |
| Functional/UAT | registration, role/capability, quote-to-invoice, buyer/seller ledgers, period/return, cases and approvals |
| Security | SAST/SCA/DAST, API fuzz/abuse, tenant/IDOR, identity/session, privilege, data export, cloud and penetration |
| Performance | baseline, 2× high, peak, stress-to-knee, spike, soak and recovery with realistic cardinality |
| Resilience/DR | dependency/zone/region loss, queue backlog, database corruption, restore, failover/failback and ransomware |
| Integrity | accepted invoice ↔ transaction ↔ ledger ↔ return ↔ audit/outbox counts/hashes and reversal invariants |
| Accessibility/usability | WCAG 2.2 AA audit target, keyboard/screen reader/reflow, representative taxpayer tasks |

No test uses live taxpayer data or attacks an environment without explicit authorization. National deployment requires independent security, capacity, DR, legal, accessibility and operational acceptance.

## Release evidence and operations

Each release records requirement/architecture decision, threat/privacy impact, changes, test/scan reports, SBOM, artifact digest/signature, migrations/reconciliation, SLO/capacity effect, observability/runbooks, rollback/forward recovery, owners and approvals. Error-budget exhaustion freezes non-risk-reducing changes. Post-incident lessons update architecture, code, detection, tests and training.
