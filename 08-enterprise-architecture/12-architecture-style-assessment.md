# Deliverable 04 — Modular monolith, microservices and hybrid decision

## Options assessment

| Criterion | Modular monolith | Microservices | Hybrid |
|---|---|---|---|
| Development speed | High initially | Lower due platform/contracts | High for core; targeted independent work |
| Data consistency | Simple local transactions | distributed consistency/sagas | statutory boundary local; events across domains |
| Horizontal scale | coarse deployment scale | fine-grained | independent hot paths plus modular core |
| Availability/fault isolation | limited by process | strong if engineered | strong for edge/receipt/events/docs/analytics |
| Operational cost | lowest | highest | medium and evidence-driven |
| Security surface | fewer network boundaries | many identities/policies/endpoints | isolated high-risk zones without all-service sprawl |
| Team autonomy | code ownership can conflict | strong with mature teams | bounded contexts/contracts enable gradual autonomy |
| Latency | in-process efficient | network/serialization cost | critical path minimized; async offload |
| Deployment | simple but coupled | independent but complex | independent critical services; coordinated modular releases |
| Maintainability | good only with enforced modules | good with strong platform discipline | good if extraction criteria and dependency rules enforced |

## Decision

Adopt a hybrid, domain-modular architecture. The early controlled implementation remains modular within a small number of deployables to protect transaction consistency and development velocity. National production independently scales/isolate these capabilities from the outset or when measured demand requires:

- protected edge/API gateway;
- identity/policy plane;
- invoice receipt/certification and VAT transaction boundary;
- outbox relay/event platform and worker pools;
- document scan/storage;
- ITAS/SaaS integration gateway and sandbox;
- reporting/analytics;
- audit/security telemetry and SOC;
- notification delivery.

Business modules (parties, quotations, accounting, inventory, expenses, projects) can initially share a taxpayer-business deployable with hard module/database ownership. NamRA case/compliance/refund can share a restricted NamRA deployable before workload/team evidence supports extraction.

## Extraction criteria

Extract only with: independent scaling need; availability/blast-radius requirement; separate data/classification boundary; independent release/team ownership; incompatible runtime/storage; materially different compliance; or sustained change contention. Before extraction, define contract/version, ownership, event replay, migration/reconciliation, SLO/on-call, threat model and rollback.

## Risks

Modular erosion is mitigated by architecture tests, import rules and no cross-domain table access. Distributed complexity is mitigated by limiting synchronous chains, transactional outbox, idempotent handlers, observability and platform standards. A “service” without independent ownership/SLO/data boundary remains a module.
