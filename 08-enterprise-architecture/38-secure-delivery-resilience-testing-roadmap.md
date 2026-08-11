# VAT-MSA secure delivery, resilience, testing and implementation roadmap

## 1. Secure development lifecycle

The lifecycle aligns with NIST SP 800-218 SSDF 1.1 and uses OWASP ASVS 5.0.0 for verifiable application requirements.

| Stage | Mandatory security/privacy output | Gate |
|---|---|---|
| requirement | control IDs, data purpose/classification, abuse cases, legal/authority dependencies | no design without owner and acceptance condition |
| architecture | trust boundaries, threat model, policy/enforcement points, resilience and evidence design | CISO/Architecture/Privacy review by risk |
| design | schema/contract, authorization matrix, state invariants, error/telemetry and test plan | protected paths and negative cases explicit |
| build | reviewed code, pinned dependencies, generated secrets prohibited, secure defaults | branch/review/CODEOWNERS policy passes |
| verify | lint/type/unit/property/component/contract/integration/security/privacy tests | required evidence linked to artifact |
| package | reproducible build, SBOM, provenance, signature, scan and immutable artifact | unsigned/untrusted artifact rejected |
| release | same artifact promoted, approvals, migration/rollback rehearsal and change record | no rebuild; SoD and environment policy pass |
| operate | monitoring, SLO, vulnerability, incident and control evidence | progressive rollout with automatic safe rollback |
| retire | data/record/export, credential/key, dependency, endpoint and supplier closure | verified disposal/archival and consumer notice |

Architecture/design and disposable local spikes may continue. Production implementation begins only after the formal approval gate authorizes a bounded scope.

## 2. Delivery pipeline security

1. Developer uses managed identity/device, signed commit where required and local secret/dependency-boundary checks.
2. Pull request links requirement/control, ADR, threat/data impact and tests; protected areas require named CODEOWNERS.
3. CI uses ephemeral isolated workers and least-privileged credentials; untrusted pull requests cannot access release secrets.
4. Lint/type/unit/property, SAST, SCA/licence, secret, IaC, container, schema/contract and policy tests run.
5. Build emits immutable artifact, SBOM and verifiable provenance; approved key signs after gates.
6. Security environment runs DAST, API fuzz, upload/malware, authorization, tenant, resource-exhaustion and abuse tests.
7. Pre-production runs migration/rollback, reconciliation, accessibility, performance, chaos/failover, recovery and observability tests.
8. Independent release authority promotes the same digest through progressive deployment.
9. Runtime admission verifies signature/provenance/policy; telemetry links deployment to source, evidence and approval.

Production developers have no standing deployment or database access. Emergency changes retain peer approval, test evidence, time-bounded privilege, monitoring, rollback and retrospective review; emergency does not bypass SoD.

## 3. Software supply-chain security

- dependencies are minimized, pinned/locked, integrity checked, sourced through approved registries/proxies and assigned owners;
- new/high-risk dependencies receive provenance, maintenance, vulnerability, licence and transitive-risk review;
- SBOM covers applications, containers, infrastructure modules and material build tools and is retained per release;
- build workers are ephemeral, reproducible where feasible, network constrained and isolated from production;
- artifact signing keys are HSM/KMS protected, purpose/environment separated and revocable;
- provenance identifies source, builder, inputs, parameters, dependencies and outputs;
- admission rejects unsigned, invalid, revoked, unapproved-registry or critical-policy-failing artifacts;
- vulnerability intelligence maps affected components to deployed instances and remediation decisions;
- supplier access and code contributions follow identity, review, evidence and offboarding controls.

## 4. Vulnerability management

Asset and dependency inventories are continuously reconciled. Scanning covers source, dependencies, secrets, containers, hosts/workloads, IaC/cloud configuration, public attack surface, APIs and runtime. Findings have owner, asset/release, exposure, exploitability, business/data/fiscal criticality, remediation, compensating controls, due date and verification.

Default target SLOs are proposed in `security-slo-catalog.csv` and require CISO/operations approval. Active exploitation or secret compromise overrides ordinary cadence. A fix is closed only after rescan/test and deployment evidence, not ticket status.

## 5. Security test architecture

`security-test-catalog.csv` is the mandatory logical test catalogue. Test data is synthetic/hostile synthetic. Testing against staging/production requires explicit environment and rate authorization; this package provides none.

| Test layer | Coverage |
|---|---|
| unit/property | policy merge, money/tax invariants, roles/SoD, parsers, signatures, state machines and safe errors |
| component | authentication/session, authorization, input/upload, audit/outbox, secret/key client and resilience |
| contract | OpenAPI/AsyncAPI, federation, partner/government stubs, schema compatibility and replay/signature |
| integration | real database/object/queue/search/policy with isolation, transactions, failure and retention |
| journey | role-specific login, privileged change, invoice/return, export, privacy right, incident and recovery |
| SAST/SCA/IaC/container | insecure code/config/dependency/licence/secret/artifact patterns |
| DAST/API fuzz | injection, access control, SSRF, deserialization, exceptional conditions and resource budgets |
| abuse/fraud | account takeover, tenant escape, duplicate invoice, rule drift, collusion, export/exfiltration |
| performance/resilience | spike/soak, DDoS controls, backpressure, dependency/zone failure, recovery and evidence continuity |
| independent assurance | web/API/cloud/identity/PAM/tenant/business-logic penetration and red/purple exercises |

Critical findings are resolved before production. High findings require remediation before production unless the formal risk authority approves a lawful, time-limited exception with effective compensating control and retest date.

## 6. Penetration and adversarial testing

Before production and after material change, independent testing covers external and internal paths; web, API and mobile/offline clients where present; cloud and network; identity/federation/recovery; PAM/privilege escalation; tenant isolation; business/fiscal logic; integrations; supply chain; data export; and evidence integrity.

Red/purple exercises validate detection and response for account takeover, insider, integration compromise, malicious release, ransomware, exfiltration and profile/rule tampering. Test scope, methods, concurrency, data and destructive limits require explicit written authorization. No real customers, personal/financial data, payments, email/SMS or irreversible actions are used.

## 7. Availability, continuity and recovery security

Detailed BCP/DR remains in `20-dr-business-continuity.md`; this document adds security acceptance.

- multi-zone primary and approved regional recovery eliminate single-zone dependency for critical services;
- stateless capacity, queues/backpressure, partitions and bulkheads prevent a security control from becoming a single bottleneck;
- identity/policy/key/telemetry dependencies have defined degraded behavior; protected writes never silently fail open;
- backups are encrypted, immutable, separately administered, geographically placed only where lawful, and include offline/isolated recovery capability;
- clean-room recovery uses independent identity/keys, signed artifacts, known-safe tooling and threat eradication;
- restore order includes trust/control plane, authoritative data, evidence, event replay and integrations;
- fiscal/tenant/audit reconciliation and business/security authorization precede reopening writes;
- failback is a separately approved change.

RTO/RPO and availability are service-tier decisions, not marketing promises. Proposed values remain hypotheses until funded topology and repeatable exercises prove them.

## 8. Security service objectives

The machine-readable catalogue is `security-slo-catalog.csv`. Mandatory measurement includes authentication/policy availability, decision latency, telemetry coverage/freshness, alert acknowledgement/containment, critical vulnerability remediation, key/certificate rotation, revocation, access review, backup success, restore/RTO/RPO, audit availability/integrity, supplier review and security-test pass/freshness.

Each SLO states scope, indicator, target, window, owner, evidence and breach action. Missing telemetry is not a pass. Critical-control SLO breaches open risk/incident review and can block release/expansion.

## 9. Security implementation roadmap

| Increment | Scope | Exit evidence | Production authority |
|---|---|---|---|
| S0 governance | approve ADR-025–029, standards register, owners, applicability and risk method | signed decisions and staffed ownership | none |
| S1 foundations | identity separation, central policy contract, classification, audit schema, secret/key and tenant test harness | design and synthetic conformance evidence | bounded non-production only |
| S2 secure delivery | protected pipeline, scans, SBOM/provenance/signing/admission, vulnerability workflow | clean signed build and gate evidence | no live integration/payment |
| S3 runtime controls | edge/API/app/data/cloud enforcement, PAM/JIT, DLP/export and profile validation | penetration, tenant, abuse and rollback evidence | approved pilot scope only |
| S4 SOC/privacy | telemetry/SIEM, detections, incident/forensics, PIMS/rights/retention and supplier operations | exercises, DPIA/applicability and independent review | country/privacy gate required |
| S5 resilience | immutable backup, clean room, zone/region failover and fiscal reconciliation | repeated RTO/RPO/security exercises | service-tier authority required |
| S6 country activation | signed security profile, local legal/regulatory controls, government contracts and readiness | country readiness `APPROVED` | explicit country release decision |
| S7 continuous assurance | control monitoring, quarterly reviews, recurring penetration/red team, audits and improvement | evidence freshness and remediation closure | ongoing; certification separately governed |

No increment activates real payment/card handling, live ITAS, unapproved statutory logic or AI. Those require dedicated approved architecture and scope decisions.

## 10. Compliance evidence pipeline

`control/test/deployment/runtime source -> authenticated collector -> normalized evidence object -> content hash/signature -> classified immutable store -> automated freshness/coverage evaluation -> independent reviewer -> finding/remediation -> management review`

Evidence collection uses least privilege and minimizes sensitive content. The catalogue in `compliance-evidence-catalog.csv` defines owner, source, cadence, verification and retention class. An auditor dashboard is read-only and cannot mutate either the source control or evidence.

## 11. Release and production acceptance

A production release requires approved scope; no `NOT READY` dependency; current threat/privacy assessment; passing mandatory control tests; no unresolved critical vulnerability; acceptable high-risk decisions; signed/provenanced artifact; migration/rollback and DR readiness proportional to the change; telemetry/detection/runbook coverage; SoD approvals; country/profile readiness; and explicit release authority.

Certification or attestation readiness additionally requires an approved audit scope, licensed normative standards, completed applicability/Statement of Applicability, sustained operating evidence and independent assessor. Architecture completion alone is insufficient.
