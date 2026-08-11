# Deliverables 10-11 — ITAS and SaaS integration architecture

## ITAS capability map and confirmation boundary

### Country-adapter boundary

ITAS/NamRA integration is the disabled Namibia implementation of a generic Government Revenue Adapter contract, not a global-core dependency. The Namibia adapter cannot load until its official API, identity, sandbox, receipt, retry, support and security contracts are confirmed. Other countries receive separate adapters, credentials, quotas, data mappings and readiness decisions; no adapter inherits Namibia assumptions.

VAT-MSA treats ITAS/NamRA as the preferred authority only for interfaces explicitly confirmed by the ITAS technical/business owners.

| Candidate interface | VAT-MSA use | Required confirmation | Failure/reconciliation |
|---|---|---|---|
| Identity/SSO | authenticate taxpayer/NamRA user and assurance | OIDC/SAML/token exchange, issuer, claims, MFA, lifecycle, environments | no invented protocol; fail closed for new high-risk actions; link/re-proof cases |
| Taxpayer identity | VAT/TIN/company/legal name/status verification | identifiers, ownership, update frequency, historical versions, privacy | bounded stale cache only if approved; mismatch review; source/version evidence |
| VAT registration | active/suspended/cancelled status and effective dates | endpoint/schema/status semantics/SLA | reject or hold statutory command when freshness policy exceeded |
| Return period | from/to/close/due/frequency | authoritative calendar, amendments, timezone | do not infer; queue exception and surface dependency status |
| Tax rules/reference | approved rates/categories/effective dates if supplied | authority, approval/publication/version contract | last approved version only within policy; no silent fallback |
| Return submission/status | send audited return and obtain official receipt/state | workflow, idempotency, receipts, corrections/rejections | outbox, retry, reconciliation by submission ID; never claim acceptance early |
| Notices/cases | secure official communication if supported | scope, evidence, confidentiality and status | case/message reconciliation and escalation |

Interfaces are labeled `REQUIRES ITAS CONFIRMATION` until signed specifications, conformance environment, certificates, quotas, error/retry semantics, operational contacts and change/deprecation governance exist.

## ITAS security and synchronization

Private connectivity where available, mutually authenticated service identity, signed short-lived tokens/messages, audience/scope binding, encryption, egress allowlist, schema limits, correlation, replay/idempotency and SIEM telemetry. Secrets/keys stay in manager/HSM. Attribute sync records source, external version, retrieved/verified time and hash; conflicts never overwrite VAT-MSA statutory history automatically.

Push events/webhooks are signature/time/replay verified and followed by authoritative fetch when required. Pull sync uses cursor/checkpoint, rate-aware backoff and dead-letter. Daily/near-real-time reconciliation compares identifier/status/period/submission counts and creates owned exceptions.

## SaaS ecosystem

POS, ERP, accounting, retail and financial SaaS connect through the protected API gateway and integration layer; no provider writes domain databases.

Lifecycle: provider due diligence → application registration/ownership → isolated sandbox identity → API/event/file conformance → security/privacy review → production approval → separate production credential/certificate → quota/monitoring/recertification → suspend/revoke/exit.

### Supported patterns

- REST commands/queries for real-time validation and receipt.
- Versioned events/webhooks for outcome/status notifications.
- Bounded batch/file import with signed manifest, malware scan, row-level results and reconciliation.
- Incremental/offline sync with device/client identity, cursor, hash chain and idempotency.
- Event API/stream subscription only for approved tenants/scopes and minimized payloads.

## Machine identity

Each application/environment has its own API client, owner, purpose, scopes, tenant allowlist, auth mode, quota, expiry and incident contact. Preferred high-assurance options are mTLS plus OAuth private-key JWT/client credentials or signed requests, subject to gateway selection. API keys alone are restricted to lower-risk/sandbox cases and stored only as secret-manager references/fingerprints. Rotation overlaps safely and revocation propagates immediately.

## Versioning, quotas and operations

Major API versions have published contracts, additive compatibility policy, deprecation telemetry/window and conformance tests. Quotas exist per client, device, source, taxpayer and global route class. Invoice receipt has separate burst/backpressure policy from search/reports. Providers receive correlation, stable problem code, retryability and support reference, never internal stack detail.

Integration SLOs cover availability, p95/p99 latency, error/throttle rate, event delivery lag, retry/DLQ, sync freshness and reconciliation. Provider compromise playbook throttles/quarantines that client, revokes credentials, preserves evidence and replays only verified records.

## Trust diagram

See `diagrams/integration-trust-boundaries.mmd`.
