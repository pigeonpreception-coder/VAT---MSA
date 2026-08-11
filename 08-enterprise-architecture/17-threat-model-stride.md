# Deliverable 18 — Formal threat model (STRIDE, abuse cases and attack trees)

## Global security extension

`security-threat-register.csv` is the expanded authoritative initial threat register covering authentication, taxpayer registration, tenancy, invoices, tax rules, returns, accounting, payments/refunds, ITAS/NamRA, employees, administration, reporting, uploads, APIs, offline synchronization, country profiles, licensing, delivery, cloud, evidence, backup/recovery, privacy, insider risk, fraud convergence and conditional AI. The register maps prevention, detection, response, owner and readiness; this narrative retains the core attack-tree and abuse-case rationale.

Threat review occurs at least quarterly and on every material trust boundary, data purpose, country, integration, payment, AI, cryptographic, recovery or privilege change. A critical tenant, fiscal, audit, key or supply-chain threat without tested controls blocks the affected release.

## Method and scope

### Workspace, licensing and workflow abuse cases

| Threat | Abuse path | Required prevention/detection | Verification |
|---|---|---|---|
| Licence bypass | call hidden route or alter client entitlement | server entitlement decision, signed state transitions, audit | direct API and token/client manipulation |
| Quota race | concurrent writes exceed seat/transaction/API limit | atomic reservation or durable compensation, anomaly detection | concurrency and replay tests |
| Tenant escape | reference another organisation in employee, workflow, search or export | trusted tenant context, tenant FK/predicate/RLS defence | cross-tenant ID and search tests |
| Role escalation | custom role includes protected permission | grantable catalogue, policy ceilings, privileged approval | role payload fuzz and protected-grant tests |
| Self-approval | creator becomes approver through role/delegation | decision-time SoD and actor lineage | create/approve/execute combinations |
| Workflow injection | tenant condition executes code or bypasses transition | typed allowlisted expression compiler and domain validation | malicious expression and transition tests |
| History rewrite | administrator edits published version or approval | immutable versions/evidence and storage permissions | update/delete negative tests |
| Delegated-admin breakout | branch/workflow admin grants organisation-wide privilege | scoped admin policy and no authority amplification | delegated admin abuse suite |
| Navigation/search leakage | restricted item or record appears in search/menu | server projection, classification hiding and masked indexes | enumeration and inference tests |
| Offboarding race | user acts between disable and revocation | idempotent orchestrator, urgent invalidation, decision-time active checks | session/token/API race tests |

Initial architecture threat model across public/partner edge, identity, portals/APIs, offline client, workloads/eventing, operational/tax/document/analytics/evidence data, management/supply chain and DR. Ratings are qualitative pending environment-specific likelihood intelligence. Risk = likelihood × impact; Critical/High items require control evidence before pilot/production.

## STRIDE register

| ID / STRIDE | Threat and abuse case | Likelihood | Impact | Risk | Prevent/limit | Detect | Respond/recover |
|---|---|---:|---:|---|---|---|---|
| TM-01 S | credential stuffing/account takeover | High | High | Critical | phishing-resistant MFA, bot/risk policy, breached-secret checks, device/session controls | failed-login velocity, new device/geo, token replay | challenge/revoke/lock, re-proof, investigate tax actions |
| TM-02 S/E | forged ITAS or machine token | Medium | Critical | Critical | issuer/audience/signature/nonce, mTLS/private-key client, key rotation | validation failures, unknown issuer/fingerprint | block/revoke, rotate trust, ITAS/provider incident |
| TM-03 E | user/official privilege escalation | Medium | Critical | Critical | deny-by-default RBAC/ABAC, JIT PAM, SoD, approval, server/resource policy | denied privilege attempts, role/policy changes, UEBA | revoke/JIT expiry, isolate, review all affected access |
| TM-04 I/E | cross-taxpayer IDOR/tenant escape | Medium | Critical | Critical | tenant predicates, RLS, opaque IDs, case/region scope, negative tests | cross-tenant denial/anomaly/canary records | suspend principal, evidence, notify/legal workflow, query review |
| TM-05 T | SQL/command/template injection | Medium | Critical | Critical | prepared queries, safe APIs/encoding, allowlisted commands, CSP/egress | WAF/app/database indicators, error anomalies | disable route, isolate workload, rotate secrets, clean deploy |
| TM-06 T/I | XSS/CSRF/session theft | Medium | High | High | output encoding, CSP, same-site/anti-CSRF, secure cookies, no token browser storage | CSP reports, abnormal session/device | revoke sessions, patch/canary, affected-user review |
| TM-07 T/R | fraudulent/duplicate/replayed invoice | High | Critical | Critical | seller identity/capability, signed source, numbering, idempotency, duplicate/business keys | duplicate/source/velocity/mismatch rules | quarantine client/transaction, reconcile, credit/reversal workflow |
| TM-08 T | tax amount/rule manipulation | Medium | Critical | Critical | effective approved rule version, server calculation, exact money, immutable receipt | client/server mismatch, rule drift, reconciliation | reject, suspend rule deployment, recompute evidence, incident/audit |
| TM-09 T | offline DB/event/sequence tampering | Medium | Critical | Critical | encrypted DB, device keys, signed chain/reservations, server revalidation | chain/reservation/clock/device anomalies | quarantine device/batch, revoke, controlled recovery/no certification |
| TM-10 S/T | compromised SaaS client or certificate | Medium | Critical | Critical | per-app env identity, narrow scope/tenant/quota, mTLS, conformance | traffic/transaction deviation, impossible tenant/source | throttle/quarantine/revoke, owner/SOC, verified replay |
| TM-11 I | insider bulk search/export/exfiltration | Medium | Critical | Critical | least privilege, purpose/case, DLP, export approval/manifest, masking | database/search/export UEBA, canary access | stop export/JIT, preserve evidence, legal/privacy response |
| TM-12 D | volumetric/network DDoS | High | High | Critical | upstream scrubbing, CDN/anycast, origin allowlist, connection limits | edge attack telemetry/origin saturation | provider mitigation, traffic policy, preserve Tier-0 |
| TM-13 D | application/API expensive-request flood | High | High | Critical | bot management, multi-level quotas, byte/page/time budgets, cache/backpressure | latency/SLO burn, route/client cost, DB/queue saturation | adaptive throttle, shed reports, scale within DB pressure limit |
| TM-14 T/I | database compromise/corruption | Medium | Critical | Critical | private endpoints, workload identity, least privilege, encryption, integrity/PITR | DAM/query/config/integrity signals | fence writers, revoke, promote/restore, transaction reconciliation |
| TM-15 R/T | audit log deletion/fabrication | Low | Critical | High | append-only remote WORM, chaining/signing, separate admin, retention lock | sequence/hash/ingestion gap | switch sink, preserve legal hold, reconstruct/correlate, incident |
| TM-16 T/E | supply-chain malicious dependency/build/image | Medium | Critical | Critical | pinned review, isolated build, SAST/SCA/SBOM/provenance/sign/admission | new CVE/signature/build drift/runtime behaviour | halt promotion, revoke signing, clean rebuild/rollback, hunt |
| TM-17 E/D | workload/cluster compromise and lateral movement | Medium | Critical | Critical | non-root immutable, network deny, workload identity/mTLS, admission/runtime | runtime/network/cloud audit anomalies | isolate namespace/node, replace, rotate, lateral hunt |
| TM-18 D/T | ransomware/destructive administrator | Medium | Critical | Critical | admin separation/JIT, EDR, segmentation, immutable offline backup | mass changes/encryption, vault/admin anomalies | revoke/fence, clean room, independently restore/reconcile |
| TM-19 I | sensitive data in logs/events/analytics | Medium | High | High | logging schema/redaction, payload minimization, classification/lineage | scanners/DLP/access review | purge where lawful, rotate secrets, exposure assessment/control fix |
| TM-20 D | region/provider/ITAS outage | Medium | Critical | Critical | multi-zone/warm DR, provider runbook, outbox, bounded cached attributes | synthetic probes, replication/ITAS error/SLO | fence/failover/degrade, queue/reconcile, controlled failback |
| TM-21 T | refund/audit workflow abuse or collusion | Medium | Critical | Critical | rule-bound state, SoD, dual approval, amount thresholds, immutable evidence | unusual approvals, self-approval, velocity/network analysis | pause case/payment, revoke roles, independent review/investigation |
| TM-22 R | repudiation of approval/submission | Medium | High | High | strong auth/assurance, reason/approval, signed receipts, immutable audit | missing context/evidence checks | halt workflow, re-auth/re-approve, evidence preservation |

## Attack tree: fraudulent input-VAT claim

Goal: obtain an illegitimate input-VAT claim. Branches: take over buyer account; compromise SaaS credential; invent seller; replay/duplicate invoice; alter offline record; exploit tenant authorization; manipulate tax rule; collude in approval; suppress reconciliation/audit. Controls require combined identity assurance, verified seller, unique numbering/source/idempotency, server rules, seller/buyer matching, immutable ledger, anomaly/risk review and controlled returns/refunds. No single control is sufficient.

## Abuse-case requirements

- A Super Admin cannot grant itself taxpayer ledger access through ordinary platform configuration.
- A taxpayer cannot switch the organisation/resource ID in a request to gain another tenant's record.
- A SaaS sandbox credential cannot reach production or another taxpayer.
- A user cannot reuse a valid invoice number/source/key with changed content.
- An offline client cannot claim certification before server receipt.
- A refund reviewer cannot create and finally approve the same high-value case.
- A risk indicator/model is not exposed through taxpayer reports or errors.

## Maintenance and validation

Review quarterly and at every new trust boundary, external integration, high-risk workflow or incident. Validation includes SAST/DAST/API fuzz, tenant authorization, credential abuse, duplicate/fraud/integrity, offline tamper, DLP/export, supply-chain, cloud/cluster, penetration, red/purple team, DDoS/load and DR exercises in authorized environments.
