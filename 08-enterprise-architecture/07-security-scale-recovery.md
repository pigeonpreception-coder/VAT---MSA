# K-M. Security, scalability and disaster-recovery architecture

The existing pilot control, threat, SLO, incident, capacity and recovery pack is `../07-security-resilience/`. This document binds and extends those controls for the complete enterprise domain/portal architecture.

## Zero-trust domain enforcement

Every portal and service call evaluates identity/provider assurance, user status, organisation membership/delegation, taxpayer/resource ownership, buyer/seller capability, role permission, department/region/case, classification, workflow, purpose, device/client risk and approval obligations. Service-to-service calls use short-lived workload identity and mTLS; network location grants no authority.

Taxpayer, statutory risk, security and platform-administration data have separate policy boundaries. Super Administration views technical metadata/health by default, not invoice payloads. NamRA Risk data is not exposed to taxpayer analytics. Documents use opaque authorized retrieval and malware quarantine.

## National-scale topology

Protected global edge routes to healthy regional entry points. Stateless portal/API replicas scale horizontally. Critical commands write an authoritative partition and outbox; durable partitioned streams feed independently autoscaled workers/read models. Cache serves public verification and approved reference data only. Analytics and reports are isolated and degrade before invoice receipt, identity and security monitoring.

Initial capacity hypotheses in `../07-security-resilience/04-capacity-scaling-performance.md` must be replaced by business forecasts and repeatable tests. Scaling metrics include concurrency, arrival rate, latency/SLO burn, queue age, partition hotness and database pressure; scale-in respects disruption/error budgets.

## Failure containment

Bulkheads isolate portal, tenant, integration, worker pool, queue partition, database shard, zone and region. Timeouts precede bounded jittered retries; retries require idempotency and a budget. Circuit breakers stop cascades. Backpressure applies fair tenant quotas and priority: invoice/transaction receipt, identity and security → VAT/returns → communications → reports/analytics.

## Availability and DR

Tier-0 identity, invoice receipt, transaction identity and security monitoring use multi-zone redundancy and a warm secondary region target. Proposed RTO/RPO are 30 minutes/≤5 minutes pending validation. Failover fences the primary, validates the recovery point and security controls, promotes authoritative state, proves synthetic/statutory reconciliation and records the actual gap. Failback rebuilds and resynchronizes before canary traffic.

Encrypted PITR, immutable vault copies and isolated/offline backups use separate credentials. Recovery tests verify taxpayer identifiers, invoice/ledger/return totals, outbox checkpoints, audit chains, documents and key access. Ransomware recovery uses clean-room infrastructure and replacement, not in-place repair.

## Security response and human governance

Reversible low-blast-radius actions may throttle, challenge or temporarily revoke at high confidence. Account/tenant quarantine, broad blocking, endpoint isolation, statutory decision or regional failover uses controlled approval except approved emergencies. Automation records evidence, confidence, policy version, expiry and rollback. AI may assist classification/explanation/detection but cannot make binding tax, refund, audit or access decisions without authorized human workflow.
