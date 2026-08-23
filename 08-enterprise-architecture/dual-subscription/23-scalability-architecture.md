# Scalability architecture

## Scale model

The authority-domain router and policy data are horizontally scalable and stateless, but decisions remain evidence-versioned. Tenant and jurisdiction keys partition operational stores, queues and caches without weakening global identity uniqueness. Hot authorities/organisations receive isolated partitions and rate budgets.

Commercial capacity enforcement is deliberately strongly consistent: seat-consuming commands route to the organisation's authoritative write partition and serialize on licence/capacity version. Unlimited mode avoids counts but not identity/authorization/audit checks. Tax authorization writes route to the authority/jurisdiction partition; reads may use short-lived signed decision caches bounded by authorization/evidence expiry and revocation propagation.

## Resilience and backpressure

- Idempotent command APIs and transactional outbox for events.
- Bounded retries with jitter and dead-letter review; no retry storms.
- Per-domain bulkheads so ITAS/authority failure cannot exhaust commercial services and vice versa.
- Cache keys include authority domain, tenant/taxpayer scope, feature, policy version and evidence version.
- Revocation and entitlement changes invalidate decisions; stale positive decisions expire quickly and fail closed for privileged actions.
- Search/export uses scoped asynchronous jobs and quotas; it cannot bypass licence/tax authorization because the worker re-evaluates authority.

“Millions of concurrent users” is a target hypothesis, not a proven claim. Production capacity requires workload models, representative multi-country data, approved maximums, independent load/soak/failover tests and signed evidence at each deployment size.
