# VAT-MSA security, hyperscale and resilience architecture pack

Status: production-reference architecture with implemented pilot controls. The local pilot proves the application controls; managed edge, regional data, SIEM, HSM/KMS and multi-region capabilities require an approved production platform and security operations team.

## Decision principles

- Verify every human, machine, device and request; authorize against role, attributes, tenant and resource.
- Keep the request path stateless; persist state in replicated data, queues and controlled caches.
- Prefer transactional acceptance plus asynchronous processing under load.
- Contain faults and incidents by tenant, credential, service, namespace, zone and region.
- Preserve invoice receipt, transaction identity and security monitoring before analytical workloads.
- Make recovery measurable through SLOs, RTO/RPO, exercises and evidence.

## Required-deliverable register

| # | Required deliverable | Repository evidence |
|---:|---|---|
| 1 | Cybersecurity Architecture | `01-cybersecurity-zero-trust.md` |
| 2 | Zero-Trust Architecture | `01-cybersecurity-zero-trust.md`, `diagrams/zero-trust.mmd` |
| 3 | Threat Model | `02-threat-model-attack-surface.md` |
| 4 | Threat Catalogue | `02-threat-model-attack-surface.md` |
| 5 | Attack Surface Map | `02-threat-model-attack-surface.md` |
| 6 | Security Controls Matrix | `03-security-controls-matrix.csv` |
| 7 | IAM Architecture | `01-cybersecurity-zero-trust.md` |
| 8 | API Security Architecture | `01-cybersecurity-zero-trust.md`, implemented `/api/v1` guards |
| 9 | Data Security Architecture | `09-data-protection-key-management.md` |
| 10 | SOC Architecture | `06-observability-devsecops-operations.md`, implemented `/security` view |
| 11 | SIEM Architecture | `06-observability-devsecops-operations.md` |
| 12 | Incident Response Architecture | `08-incident-response-playbooks.md` |
| 13 | Disaster Recovery Security Architecture | `05-high-availability-disaster-recovery.md` |
| 14 | Capacity Model | `04-capacity-scaling-performance.md` |
| 15 | Traffic Model | `04-capacity-scaling-performance.md` |
| 16 | Scalability Architecture | `04-capacity-scaling-performance.md`, `diagrams/hyperscale-runtime.mmd` |
| 17 | Load-Balancing Architecture | `04-capacity-scaling-performance.md` |
| 18 | Autoscaling Architecture | `04-capacity-scaling-performance.md`, Kubernetes HPA baseline |
| 19 | Database Scaling Architecture | `04-capacity-scaling-performance.md` |
| 20 | Caching Architecture | `04-capacity-scaling-performance.md` |
| 21 | Queue Architecture | `04-capacity-scaling-performance.md`, implemented transactional outbox |
| 22 | Performance Test Plan | `04-capacity-scaling-performance.md`, `tests/load/` |
| 23 | High-Availability Architecture | `05-high-availability-disaster-recovery.md` |
| 24 | Fault-Tolerance Architecture | `05-high-availability-disaster-recovery.md` |
| 25 | Disaster Recovery Architecture | `05-high-availability-disaster-recovery.md` |
| 26 | Backup Architecture | `05-high-availability-disaster-recovery.md` |
| 27 | Business Continuity Architecture | `05-high-availability-disaster-recovery.md` |
| 28 | Failover/Failback Architecture | `05-high-availability-disaster-recovery.md` |
| 29 | Observability Architecture | `06-observability-devsecops-operations.md` |
| 30 | Monitoring Architecture | `06-observability-devsecops-operations.md`, Prometheus rule baseline |
| 31 | Logging Architecture | `06-observability-devsecops-operations.md` |
| 32 | Distributed Tracing Architecture | `06-observability-devsecops-operations.md` |
| 33 | DevSecOps Architecture | `06-observability-devsecops-operations.md`, `security:ci` |
| 34 | Deployment Architecture | `06-observability-devsecops-operations.md`, Kubernetes baseline |
| 35 | Incident Management Architecture | `08-incident-response-playbooks.md`, `diagrams/incident-lifecycle.mmd` |

## Architecture gates

This pack is not an authorization to deploy. Named service selections, legal controls, sovereign hosting, data residency, exact load targets and operational staffing are decisions to be approved through threat modelling, privacy impact assessment, capacity testing and the production acceptance gates.
