# High availability, fault tolerance, backup, continuity and DR

## Service tiers and objectives

Targets are proposed acceptance criteria pending business-impact and platform validation.

| Tier | Capability | Availability SLO | RTO | RPO |
|---|---|---:|---:|---:|
| 0 | Identity, invoice receipt, transaction ID, security monitoring | 99.95% monthly | 30 minutes | ≤ 5 minutes |
| 1 | Ledger/return processing and operational lookup | 99.9% monthly | 2 hours | ≤ 15 minutes |
| 2 | reports, historical analytics and bulk services | 99.5% monthly | 24 hours | ≤ 24 hours |

## Primary architecture and fault containment

The primary region spans at least three failure domains. Stateless replicas use anti-affinity, readiness probes, disruption budgets and rolling/canary releases. Data, queue, identity, edge and observability tiers are redundant. Dependency timeouts, bulkheads, circuit breakers and workload priority prevent cascading failure. No single load balancer, credential, administrator or monitoring node is authoritative.

## Disaster recovery and failover

A warm secondary region maintains tested infrastructure, security policy, keys under independent controls, critical data replication and sufficient Tier-0 capacity. Health-based traffic changes require incident command approval except for a narrowly defined catastrophic edge trigger.

Failover sequence: declare incident and freeze risky changes → confirm secondary integrity/replication point → stop or fence primary writers → promote secondary data/queues → switch protected traffic → run synthetic and reconciliation checks → scale by priority → communicate status. Record actual RPO gap.

Failback occurs only after root cause is removed and primary is rebuilt/validated: synchronize from the new source of truth → prove integrity → canary traffic → controlled switch → reconcile transaction IDs/outbox/ledgers → retain evidence. Split-brain prevention and authoritative-region fencing are mandatory.

## Backup and ransomware resilience

Use encrypted PITR plus daily snapshots, immutable retention and an isolated/offline copy in a separate administrative boundary. Backup operators cannot change production; production administrators cannot delete vault retention. Keys and recovery credentials use separate custody. Back up data, configurations, audit evidence, source/provenance and recovery instructions.

Weekly automated sample restores validate readability; quarterly full service restores validate data integrity and RTO; semiannual regional exercises validate failover/failback. Restore evidence includes snapshot ID, hashes/counts, schema version, key access, duration, reconciliation and approver. An untested backup is not counted as recoverable.

## Business continuity

Tier-0 remains prioritized. If regional capacity is constrained, suspend exports, analytics, large searches and nonessential notifications; cap integrations fairly by tenant; maintain receipt IDs and public status communications. Manual workarounds must never bypass identity, authorization or immutable audit. Critical suppliers need escalation contacts, recovery commitments and exit plans.

## Failure scenarios and exercises

Exercises cover zone loss, regional isolation, identity degradation, primary data corruption, queue loss/backlog, compromised signing key, ransomware, malicious administrator and observability outage. Every exercise has an incident commander, injects, success criteria, safety stop, evidence, lessons and dated remediation owner.
