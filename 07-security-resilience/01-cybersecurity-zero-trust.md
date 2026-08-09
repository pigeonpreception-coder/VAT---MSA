# Cybersecurity, zero-trust, IAM and API architecture

## Trust zones and request decision

Internet traffic terminates at protected DNS/CDN/DDoS infrastructure, passes managed WAF and bot/API controls, and reaches only the API gateway. Workloads are private, mutually authenticated and default-denied. Data services accept only workload identities. Administrative access uses a separate identity plane and just-in-time privileged access.

Each request executes: establish correlation ID → authenticate human or machine identity → evaluate device/integration posture → authorize scope and tenant → validate bounded schema → evaluate abuse/risk policy → execute least-privilege operation → record audit/security telemetry.

See `diagrams/zero-trust.mmd`. The pilot implements correlation IDs, role/scope checks, taxpayer isolation, bounded JSON ingestion, idempotency, actor/device/source/tenant/global rate limits and security events. Production adds managed MFA/OIDC, mTLS machine identities, WAF/bot intelligence and distributed policy enforcement.

## IAM architecture

| Identity class | Authentication | Authorization | Lifecycle |
|---|---|---|---|
| Taxpayer user | OIDC passwordless or phishing-resistant MFA | RBAC plus taxpayer ABAC | joiner/mover/leaver; session and device revocation |
| NAMRA officer | OIDC phishing-resistant MFA | job role, case assignment, purpose and region | JIT elevation; quarterly recertification |
| Privileged administrator | separate PAM identity, hardware-backed MFA | time-bound approved role; no standing production access | recorded session; dual approval; emergency review |
| SaaS integration | OAuth client credentials plus mTLS/private-key JWT | named API scopes, tenant and quota | certificate rotation; owner attestation; rapid revocation |
| Workload | short-lived workload identity over mTLS | service-to-service policy | automatic issuance and rotation |

Tokens are short-lived, audience-bound and never accepted from query parameters. Refresh tokens rotate and replay revokes the family. Authentication, policy and revocation dependencies are redundant; cached signed policy may be used briefly in fail-safe mode, but privileged actions fail closed.

## API security contract

- Versioned routes; strict media type, schema and 1 MiB invoice payload limit.
- Idempotency key bound to actor and canonical payload hash; conflicting reuse returns 409.
- Tenant ownership checked server-side; request identifiers never grant access.
- Separate interactive and machine authentication; high-trust integrations use mTLS.
- Actor, device, source, API-client, tenant and global quotas; production policy is adaptive.
- Timeouts, request budgets, pagination ceilings and query allowlists prevent resource exhaustion.
- Security-safe errors include correlation ID, not internals or secrets.
- Public verification is deliberately minimal and cacheable; taxpayer data is `no-store`.

## Defence layers and failure posture

| Layer | Primary controls | Detection | Failure/recovery |
|---|---|---|---|
| Edge | authoritative DNS protection, CDN, upstream DDoS, WAF, bot scoring, TLS | floods, signatures, reputation, anomalous routes | multi-provider DNS runbook; origin only accepts edge egress |
| Network | private subnets, default-deny policy, microsegmentation, controlled egress | flow/DNS logs, east-west anomalies | isolate namespace/service; rotate workload credentials |
| Application | encoding, parameterized queries, CSRF/session controls, schema bounds, resource authorization | 4xx/5xx, denied scope, injection indicators | quarantine credential; canary rollback; route-level kill switch |
| Identity | MFA, PAM/JIT, device and risk policy, least privilege | impossible travel, credential stuffing, privilege change | revoke sessions/tokens; suspend identity; re-proof owner |
| Data | encryption, KMS/HSM, row/tenant policy, export approval, immutable audit | bulk reads, unusual exports, key access | block export, rotate key, restore verified copy |
| Operations | SIEM, EDR/runtime detection, configuration drift, integrity checks | correlated incidents and SLO burn | tested playbook, clean-room recovery, post-incident controls |

## Controlled automation

Low-blast-radius controls (throttle, challenge, short session revocation) may execute at high confidence. Account suspension, tenant quarantine or broad blocking requires human approval unless an approved emergency threshold is met. Every action stores policy version, evidence, actor, expiry and rollback path. False-positive rate and taxpayer impact are release metrics.
