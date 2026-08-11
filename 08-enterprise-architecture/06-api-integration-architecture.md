# I-J. API and integration architecture

## Global security API addendum

`35-data-application-api-network-cloud-security.md` governs API authentication, resource authorization, schema/resource budgets, idempotency/replay, outbound validation, egress and government trust. `api-catalog.yaml` now includes non-executable security-profile, PAM, SOC/incident, vulnerability, privacy, compliance-evidence and recovery-assurance contracts. Each remains architecture-proposed and cannot create production authority.

Government adapters remain disabled until official identity, mTLS/private-key, message, signature, receipt, retry, reconciliation, incident and sandbox contracts are approved. A public ITAS web flow is not an API contract.

## Global contract addendum

Fiscal commands and events carry resolved `countryCode`, `countryPackVersion`, ISO `currencyCode`, exact amount representation, rule references and correlation/provenance metadata. APIs never accept an unverified client-selected jurisdiction as authoritative. Country-pack, readiness, jurisdiction, currency-rate, tax-preview and template endpoints are catalogued in `api-catalog.yaml`; all activation and live government-adapter operations remain gated.

## Extension contract groups

The catalogue adds `/licensing`, organisation employee/administrator/structure resources, `/organisation-roles`, `/workflows`, `/access-governance`, `/navigation` and permission-aware `/search`. Every endpoint evaluates identity, organisation, role/permission, entitlement, security policy, workflow state and SoD independently. Upgrade and renewal endpoints initiate approved provider workflows; they do not accept a client-selected licence state.

List APIs use server filtering and keyset pagination. High-cardinality role, employee, workflow and search results are never loaded wholesale at login. Entitlement-changing commands emit invalidation events; consumption counters use atomic reservation or durable reservation/compensation rather than a dashboard read.

## API principles

All financial/tax commands are authenticated, authorized, tenant/capability-scoped, versioned, bounded, idempotent and correlated. Request IDs do not grant resource access. Machine integrations never authenticate as human users. API errors use `application/problem+json` with stable code, safe detail and correlation ID.

The complete route catalogue is `api-catalog.yaml`.

## Gateway flow

Protected edge → TLS/mTLS policy → client/human identity → route/version → schema and byte limits → actor/device/source/client/tenant/global quota → RBAC/ABAC → replay/idempotency → domain command → audit/security telemetry → response. The origin accepts only gateway/edge traffic in production.

OAuth/OIDC/mTLS/signing selections depend on the integration class. High-trust SaaS uses a registered application, machine identity, narrow scopes, certificate/private-key authentication, rotation, quota and conformance approval. Keys are secret-manager references; relational storage holds only key IDs, fingerprints, status and expiry.

## Contract lifecycle

- URI major version plus additive backward-compatible change policy.
- Canonical JSON schemas and examples; explicit decimal/money/date semantics.
- Deprecation notice, telemetry and migration window before retirement.
- Consumer-driven and provider contract tests in sandbox.
- Idempotency key bound to client/actor, operation and canonical payload hash.
- Signed webhook/events include event ID, type/version, time, tenant and correlation; delivery is at least once and consumers deduplicate.

## ITAS/NamRA integration

An anti-corruption adapter supports discovery of the verified protocol and contracts. Candidate capabilities are SSO/federation, taxpayer/VAT/TIN/company verification, registration status/period configuration, return submission/status, notices/cases and reference tax rules. Each attribute documents whether ITAS is authoritative, refresh/expiry, failure policy and reconciliation.

No protocol or write authority is assumed until ITAS confirms issuer, endpoints, schemas, assurance, service levels, environments, data residency, certificates, rate limits, outage/replay behavior and operational ownership. During outage, cached authoritative attributes may be used only within approved staleness; statutory submissions never receive invented success.

## SaaS/POS/ERP integration

Lifecycle: developer registers app → ownership/beneficial purpose verified → sandbox identity and scopes → schema/replay/error/throughput/security conformance → production approval → separate production credential → monitored use and periodic recertification → suspend/revoke. Test records carry isolated tenant/environment IDs and cannot enter production tax ledgers.

Connectors normalize provider-specific data into canonical commands, retain source document/device IDs and emit validation errors without silent correction. Bulk sync uses checkpoints, signed manifests, idempotent records, backoff, dead-letter and reconciliation reports.

## Offline synchronization

Registered device → encrypted/signed local event log → incremental sync cursor → server verifies device, reservation, hash chain, schema and idempotency → detects version/sequence conflict → auto-merges only non-authoritative safe fields → queues financial/statutory conflicts for human resolution → returns authoritative receipt/checkpoint. Manipulated chains quarantine the device/work batch.

## Future regulated integrations

Banking/payment/customs connectors are architecture placeholders only. They require applicable legal authority, regulated provider contracts, tokenized consent, strong customer/service authentication, no stored bank passwords, settlement reconciliation, dispute/chargeback semantics and separate security assessment.
