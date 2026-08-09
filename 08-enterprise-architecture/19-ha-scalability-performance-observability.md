# High Availability, Scalability, Performance and Observability

**Status:** architecture-board draft. Every numerical target in this document is **PROPOSED — REQUIRES ARCHITECTURAL APPROVAL** and must be baselined through measured pilot traffic, legal filing deadlines, ITAS limits and funded infrastructure.

## Service objectives

| Capability tier | Availability target | Typical latency objective | Recovery objective | Examples |
|---|---:|---:|---:|---|
| National fiscal write path | 99.95% monthly | p95 < 2 s, p99 < 5 s | RTO 30 min; RPO <= 5 min | certify invoice, record VAT transaction, submit return |
| Identity and authorization | 99.95% monthly | p95 < 1 s | RTO 30 min; RPO <= 5 min | sign-in, token exchange, policy decision |
| Taxpayer operational path | 99.9% monthly | p95 < 2 s | RTO 2 h; RPO <= 15 min | quotation, accounting, inventory, documents |
| NAMRA investigation/reporting | 99.9% monthly | interactive p95 < 5 s | RTO 4 h; RPO <= 1 h | search, case work, dashboards |
| Asynchronous and bulk work | 99.5% monthly | 95% within 15 min | replay from durable log | reconciliation, exports, notifications |

Availability excludes approved maintenance only where an equivalent safe path remains. Error budgets govern release pace; a depleted budget freezes non-remediation releases.

## Capacity model

Capacity is modelled by registered taxpayers, active users, branches, certified invoices per second, peak-day VAT returns, document bytes, event throughput, query concurrency and retention growth. Initial sizing assumptions are not commitments: 1 million taxpayers, 5 million users, 100 million invoices/year, 5x month-end and 10x filing-deadline peaks, 7-year hot/warm fiscal retention plus archive. The architecture must be re-sized from telemetry before each scale gate.

| Resource | Driver | Scale method | Saturation signal | Protection |
|---|---|---|---|---|
| Web/API | concurrent sessions and requests | stateless horizontal replicas; CDN for public assets | CPU, queueing and p95 latency | quotas, rate limits, load shedding |
| Fiscal service | certification TPS | partition by tenant/hash; independent replica set | command queue age and DB commits | reserved capacity; bounded retries |
| Relational data | transactions and working set | read replicas; tenant/period partitioning; later bounded sharding | IOPS, lock waits, replication lag | query budgets; connection pools |
| Event backbone | events/second and replay volume | partitions keyed by aggregate/tenant | consumer lag and broker storage | backpressure; DLQ; replay controls |
| Object store | documents and exports | native elastic storage; lifecycle tiers | request throttles and capacity alerts | multipart upload, checksum, malware quarantine |
| Analytics | history and query concurrency | independent warehouse/lakehouse compute | query queue and scan bytes | workload groups and row-level security |

No single tenant may exhaust a shared pool. Per-tenant quotas, fairness queues and high-priority fiscal lanes isolate noisy neighbours. Hot partitions are detected by key cardinality and automatically rebalanced where ordering permits.

## High-availability design

- Minimum three failure domains for production stateless workloads, event brokers and database consensus members where the selected platform supports them.
- Active-active application instances behind health-aware load balancing; no local session state.
- Single-writer or platform-supported multi-writer relational topology chosen only after consistency testing; synchronous local redundancy and asynchronous regional replica.
- Durable event publication uses transactional outbox; consumers are idempotent and checkpointed.
- External integrations use circuit breakers, bulkheads, bounded exponential backoff, jitter, timeouts and durable queues.
- Graceful degradation preserves invoice capture/offline issuance and read-only history when ITAS, SaaS, analytics or notifications fail.
- Deployments use rolling or blue/green health gates; schema changes use expand/migrate/contract compatibility.

## Performance engineering

Budgets are allocated end-to-end: edge 100 ms, authentication/policy 150 ms, service logic 300 ms, primary data 300 ms, external dependency 500 ms, network/render remainder. These are proposed envelopes, not universal guarantees. Critical paths prohibit synchronous analytics, document scanning or non-essential SaaS calls. Caches store only safe, versioned reference data; fiscal authorization, consent and revocation checks use bounded-staleness policies.

Testing includes baseline, load, stress, spike, soak, failover, chaos, filing-deadline, large export, event replay and recovery-load tests. Promotion requires representative volumes, production-like topology, service objective evidence and no unbounded resource growth.

## Observability architecture

Every request carries a W3C-compatible correlation/trace identifier through gateway, services, events and integrations. Structured logs exclude secrets and minimize personal/tax data. Metrics use controlled labels to prevent cardinality explosions. Distributed traces sample adaptively and retain all errors and high-risk fiscal flows.

| Signal | Required dimensions | Examples |
|---|---|---|
| Golden signals | service, region, endpoint, tenant tier | latency, traffic, errors, saturation |
| Fiscal correctness | rule version, tax period, outcome | certification rejects, mismatches, duplicate suppression |
| Integration health | provider, operation, circuit state | ITAS latency, token failures, SaaS backlog |
| Security | identity, risk class, decision | denied policies, impossible travel, export attempts |
| Data health | store, partition, job | replication lag, event lag, warehouse freshness |
| Business service | journey and deadline | registrations completed, returns submitted, audit ageing |

Alerting is symptom-first and SLO-based. Each alert names impact, owner, runbook, severity and escalation. Dashboards provide executive service health, NOC operational health, SOC security posture, domain service objectives, integration status, data quality and business deadline readiness.

## Readiness gates

Before production, owners must approve targets; validate capacity at 2x forecast peak; demonstrate zone and regional failure; prove retry/idempotency; exercise queue backlog recovery; test database point-in-time recovery; validate observability coverage; and agree cost guardrails. Unmet national fiscal-path gates are **NOT READY**.

