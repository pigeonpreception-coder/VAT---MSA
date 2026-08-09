# Capacity, traffic, scaling and performance architecture

## Workload model and initial test hypotheses

The figures below are validation targets, not achieved claims. Business forecasting must replace assumptions before procurement.

| Dimension | Initial planning hypothesis | Proof method |
|---|---:|---|
| Registered identities | 5,000,000 | identity/storage forecast |
| Peak active users | 250,000 | arrival/session model |
| Peak API requests | 100,000 requests/s at edge; 20,000/s dynamic origin | distributed peak and spike tests |
| Invoice acceptance | 10,000/s sustained; 25,000/s 5-minute burst | k6 arrival-rate test with unique idempotency keys |
| Asynchronous processing | 15,000 messages/s sustained | queue/worker saturation test |
| Availability reserve | N+1 zone plus 30% regional headroom | capacity-loss test |
| Annual invoice growth | 2.5 billion records/year hypothesis | business forecast and partition-cost model |

Traffic classes are modelled separately: public cached verification, interactive portal reads, invoice writes, integrations, returns, reports and privileged operations. Peak tax deadlines use a burst factor derived from observed production telemetry. Performance results must report latency histograms, errors, saturation, queue age, data contention and recovery time—not average latency alone.

## Runtime and load balancing

Protected global traffic management routes to healthy regions; regional load balancers distribute across zones; service load balancing selects stateless instances. Origin accepts only protected-edge traffic. Sessions and rate policy are shared or token-based, so instances can be replaced without user affinity. See `diagrams/hyperscale-runtime.mmd`.

Autoscaling uses CPU/memory as a baseline and production custom metrics for request concurrency, p95 latency and queue age. Scale-out is fast; scale-in is conservative and respects disruption budgets. Capacity is reserved for one-zone loss and scale-up delay. Database pressure can stop application scale-out from amplifying failure.

## Data, cache and queue design

- Partition invoice and ledger data by period plus stable taxpayer shard; keep globally unique transaction IDs.
- Use connection pooling, indexed access paths and bounded pagination; direct database access is private.
- Separate operational writes, immutable audit evidence and analytical workloads. Read replicas serve suitable non-authoritative reads.
- Cache only public verification and explicitly approved reference data. Keys include tenant/policy version; confidential pages are `no-store`; purge follows source changes.
- Accept critical writes transactionally with an outbox. Relays publish to a partitioned durable queue; consumers are idempotent, retry with jitter, dead-letter after policy threshold and expose oldest-message age.
- Backpressure rejects or defers low-priority work before the database fails. Invoice receipt, identity and security telemetry retain capacity; historical reports and analytics degrade first.

## Circuit breakers and graceful degradation

Dependencies have timeouts, retry budgets and circuit breakers. Retries are permitted only when safe/idempotent and never multiply unboundedly across layers. When analytics, search or notifications fail, invoice acceptance continues and outbox events remain pending. If authoritative storage cannot safely commit, the API returns a retryable failure rather than claiming receipt.

## Performance test plan

Run baseline, high-load (2×), expected peak, stress-to-knee, sudden spike, 8–24 hour soak, dependency degradation and post-overload recovery. Include one-zone capacity loss, slow database, queue backlog, cache miss storm and active WAF/rate policy. Use production-like data cardinality and payload distribution with synthetic identities only.

Exit evidence: achieved RPS/TPS; p50/p95/p99; error and throttle rate; resource and connection saturation; queue age; database latency; scale reaction time; data correctness; security-control overhead; recovery time. A target is accepted only after two repeatable runs with headroom and no unexplained integrity errors.
