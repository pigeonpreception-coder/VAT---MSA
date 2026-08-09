# Deliverables 17, 19-20 — Security, SOC and infrastructure topology

## Defence-in-depth zones

See `diagrams/security-zones.mmd` and `diagrams/infrastructure-topology.mmd`.

| Zone | Components | Inbound/outbound policy | Security controls |
|---|---|---|---|
| Public/Edge | protected DNS, CDN, DDoS, WAF, bot, global routing, gateway | internet only to edge; origin only from edge | modern TLS, managed rules, reputation/anomaly, quotas, cert automation |
| Identity | federation, MFA, token/session, policy, PAM | only gateway/workloads/admin identity flows | HSM/KMS, short tokens, mTLS, JIT, revocation, enhanced audit |
| Application | portal BFFs, domain APIs, worker pools | gateway/service mesh only; deny-by-default east-west/egress | workload identity, mTLS, network policy, non-root/immutable, runtime detection |
| Integration | ITAS/SaaS adapters, webhook/file gateway, sandbox | allowlisted partner paths and domain APIs | separate env identities, schema/replay/file scan, egress proxy, conformance |
| Data | operational DB, tax ledger, cache, event bus, objects | named workloads only; no public endpoints | encryption, least-privilege accounts, RLS/partition, DAM, backups, integrity |
| Analytics | ingest/read models/warehouse/BI | approved event/CDC ingress; governed query/export | minimization, masking, lineage, DLP, workload isolation |
| Management | CI/CD, registry, orchestration/cloud, configuration, KMS | separate admin path; JIT only | MFA/PAM, signed artifacts/policy, approval, cloud audit |
| Security | collectors, SIEM/lake, SOAR, incident/evidence | telemetry in; restricted SOC management | separate administration, WORM, detection-as-code, evidence custody |
| Recovery | backup vault, DR control plane, clean room | independent credentials/connectivity | immutability, offline copy, dual control, restore/failover exercises |

## Security architecture controls

Identity/IAM/PAM/MFA and RBAC/ABAC are specified in `04-identity-rbac-abac.md`. Application/API protection includes parameterization, encoding/CSP/CSRF/session controls, schema/byte/query limits, SSRF egress allowlist, resource authorization, idempotency/replay and safe problems. Data protection includes TLS/mTLS, storage/backup encryption, field/token protection as justified, KMS/HSM, secret manager, private endpoints, DLP and immutable evidence.

Infrastructure uses approved hardened images, non-root/least privilege, read-only filesystems where applicable, admission/signature verification, namespace/account/project segmentation, resource limits, patch/vulnerability SLAs and runtime/EDR detection. WAF/IDS/IPS/signatures complement behavior/anomaly detections; none is a sole control.

## Security event pipeline and SOC

Application/identity/edge/API/database/cloud/endpoint/KMS/CI telemetry → redundant collectors with redaction/durable bounded buffering → isolated security lake/SIEM → correlation/behavior/threat intelligence → incident record → controlled SOAR/human playbook → evidence/containment/recovery → lessons/detection/control update.

Severity:

- Critical: active broad compromise, confirmed exfiltration, tax-integrity loss, destructive/ransomware or Tier-0 national outage; 24×7 immediate paging/incident command.
- High: confirmed account/integration compromise, privilege escalation, cross-tenant attempt with evidence, material fraud or control failure; rapid SOC response.
- Medium: credible suspicious pattern/policy violation requiring investigation.
- Low/Informational: retained event/weak signal for correlation and tuning.

Low-blast reversible actions (throttle, challenge, short revoke, isolate one client) may automate above approved confidence. Account/tenant broad suspension, endpoint isolation, mass block, statutory action and regional failover require human approval except defined emergencies. Every action stores evidence, confidence, policy version, approver, expiry and rollback.

## Evidence preservation

Incidents use synchronized clocks, correlation/trace/event IDs, immutable timelines and legal-hold procedures. Forensic collection is read-only/minimal, hash-verified, access logged and chain-of-custody maintained. Production restoration uses clean signed artifacts and verified data; compromised nodes are replaced.

## Vulnerability and secure SDLC

Asset/SBOM inventory; threat modelling; SAST/SCA/license/secret/IaC/container/API/DAST gates; patch SLAs by exposure/exploitability; responsible vulnerability reporting; independent penetration/red-team; coordinated zero-day containment/virtual patching. Critical releases do not proceed without remediation or accountable expiring acceptance.
