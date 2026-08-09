# Threat model, catalogue and attack surface

## Protected assets and trust boundaries

Assets: taxpayer identity and registration, invoices and VAT ledgers, returns, credentials and keys, audit/security evidence, availability of receipt services, software supply chain and backups. Boundaries exist at the public edge, identity provider, API gateway, workload mesh, queue, operational database, audit store, analytics export, management plane and DR replication path.

## Threat catalogue

| ID | Threat/problem and risk | Required prevention/detection | Containment/recovery/test |
|---|---|---|---|
| T01 | Credential stuffing/account takeover; fraudulent filings and disclosure | phishing-resistant MFA, bot/risk policy, breached-secret checks; login velocity and device changes | revoke token family, suspend identity, re-proof; credential-abuse simulation |
| T02 | Broken object/tenant authorization; cross-taxpayer disclosure | server-side tenant ABAC on every repository operation; denied-access telemetry | isolate principal and inspect access; negative multi-tenant tests |
| T03 | Injection/XSS/SSRF/deserialization; code or data compromise | parameterized queries, output encoding, CSP, egress allowlist, strict schemas | disable route, rotate secrets, redeploy clean image; SAST/DAST/API fuzzing |
| T04 | API replay/duplicate submission; ledger inconsistency | bounded idempotency, nonce/signature for partners, time windows | quarantine integration and reconcile ledger; replay and concurrency tests |
| T05 | Volumetric/application DDoS; national service outage | upstream scrubbing, WAF, multi-level quotas, cache, queue/backpressure | shed analytics, prioritize receipt, add capacity; controlled load/DDoS exercise |
| T06 | Expensive payload/query abuse; resource exhaustion | byte/line/page/time limits and query budgets | circuit-break dependency; workload stress/fuzz tests |
| T07 | Insider misuse/bulk export; taxpayer data loss | least privilege, purpose binding, dual approval, DLP, immutable audit | suspend JIT role, stop export, incident/legal workflow; UEBA and canary-data test |
| T08 | Compromised SaaS/machine key; automated fraud | per-integration identity, mTLS, scopes, quota, signing | revoke certificate, quarantine integration, rotate key; stolen-key tabletop |
| T09 | Supply-chain compromise; malicious release | pinned dependencies, SBOM, provenance/signature, isolated build, review | block promotion, revoke signing material, rebuild clean; tamper drill |
| T10 | Workload/cluster compromise; lateral movement | non-root immutable containers, admission policy, network deny, short credentials | isolate namespace/node, collect evidence, replace not repair; runtime exercise |
| T11 | Database compromise/corruption; integrity/availability loss | private endpoint, least privilege, encryption, PITR, integrity controls | stop writers, promote verified replica or restore; corruption game day |
| T12 | Audit tampering; loss of accountability | append-only remote evidence, chained hashes/signing, restricted retention | switch evidence sink, preserve legal hold; deletion/tamper verification |
| T13 | Ransomware/management compromise; production and backup loss | admin separation, immutable offline backups, EDR and segmentation | clean-room rebuild, independent credentials, restore; quarterly restoration |
| T14 | Region/provider outage; prolonged unavailability | multi-zone primary, warm secondary, replicated critical state | DNS/traffic failover and controlled failback; semiannual regional exercise |
| T15 | Zero-day/emerging threat; unknown compromise | defence in depth, behaviour baselines, egress controls, rapid kill switches | isolate affected surface, threat hunt, clean rebuild; purple-team exercise |

## Attack surface map

| Surface | Entry points | Sensitive operations | Mandatory controls |
|---|---|---|---|
| Public web | browser pages, verification route, static assets | login initiation, verification | CSP/TLS, minimal disclosure, WAF/bot, safe cache policy |
| API | invoice and future return endpoints | create/read taxpayer transactions | identity, tenant scope, validation, idempotency, quotas, correlation |
| Integration | SaaS clients, certificates and webhooks | high-volume submissions | registered machine identity, mTLS/signing, replay protection, owner/quota |
| Admin/SOC | officer and security views | search, incident action, export | PAM/JIT, MFA, purpose, approval, enhanced audit |
| Data plane | databases, cache, queue, object storage | read/write/replicate/export | private identity, encryption, partitioning, backups, DLP |
| Management plane | CI/CD, cloud, Kubernetes, KMS | deploy, scale, keys, recovery | separate identities, signed policy/image, approval, immutable audit |
| Supply chain | source, packages, build images, registry | build and promotion | protected branches, scan gates, SBOM, provenance and signing |
| Recovery | backup vault, replication, clean room | restore/failover | independent credentials, immutability, reconciliation and drills |

## Model maintenance

Threat modelling is required for new trust boundaries, sensitive data flows, public endpoints and privileged workflows. Owners review threats at least quarterly and after material incidents. Residual risks require an accountable owner, expiry date, treatment and documented acceptance; “framework compliance” is not a substitute for Namibian legal review.
