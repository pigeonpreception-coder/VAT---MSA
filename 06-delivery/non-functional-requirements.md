# Non-Functional Requirements

All numeric values below are architecture baselines for procurement, sizing and test design. Gate 0 volume modelling and NamRA business-impact analysis must approve or replace them before contractual use.

## Service levels

| NFR | Baseline target | Measurement and acceptance |
|---|---|---|
| Core certification availability | 99.95% monthly, excluding approved maintenance | External synthetic transaction and platform SLI; error-budget policy enforced |
| Taxpayer/NamRA portal availability | 99.90% monthly | Successful authenticated journeys, not host uptime |
| Public verification availability | 99.95% monthly | Multi-location verification probe |
| Synchronous invoice latency | p95 <= 2 s; p99 <= 5 s for invoices up to 100 lines | Gateway-to-response under approved load; excludes client network |
| Large invoice handling | Up to 10,000 lines via asynchronous endpoint | Completed within 2 minutes at p95 under reference load |
| Status query latency | p95 <= 500 ms | Authorised status query under reference load |
| Event propagation | 99% of committed outbox events published within 10 s | Outbox age and consumer-lag SLI |
| Search freshness | 95% within 60 s of committed event | Indexed-document timestamp comparison |
| Warehouse freshness | Standard dashboard <= 15 min; regulated daily reconciliation by 06:00 | Pipeline watermark and source-target reconciliation |

## Capacity and elasticity

- Provisional validation target: 2,000 fiscal documents/second sustained and 10,000/second for a 15-minute burst, with no loss and bounded queue recovery. Replace with NamRA volume model at Gate 0.
- Horizontal scale must add API/worker capacity without repartitioning the transactional data during routine peaks.
- A single taxpayer or partner may not exhaust shared capacity. Per-tenant concurrency, payload and queue limits are mandatory.
- Backpressure returns explicit retry guidance and preserves idempotency. The system must not accept work it cannot durably retain.
- Capacity tests model month/period end, network recovery from offline devices, connector retries and analytical backfills concurrently.

## Reliability and integrity

- A certified invoice must have exactly one committed canonical record, transaction and balanced VAT posting.
- At-least-once delivery must not create duplicate ledger, certificate, exception, return or notification effects.
- Accepted fiscal records are immutable. Reversal/correction chains must reconcile to the net legal position.
- Daily automated controls compare invoice totals, VAT transactions, ledger entries, certificates, outbox events, broker delivery and analytical ingestion.
- Every business response includes a correlation ID; every event contains a globally unique event ID and trace context.

## Recovery objectives

| Service tier | Examples | RTO | RPO | Recovery pattern |
|---|---|---:|---:|---|
| Tier 0 | Certification, posting, verification | <= 60 min | <= 5 min | Warm DR, continuous encrypted replication, automated rebuild |
| Tier 1 | Portal, exceptions, returns, integrations | <= 4 h | <= 15 min | Warm services, replicated data, queued recovery |
| Tier 2 | Warehouse, BI, non-urgent reporting | <= 24 h | <= 4 h | Rebuild/replay from governed sources |

- Backups must be encrypted, immutable, access-separated and restored in quarterly exercises.
- A DR exercise must prove DNS/traffic failover, secrets and key access, data reconciliation, business validation and controlled failback.
- RPO does not permit loss of legally accepted invoices; edge and client retry/idempotency design must reconcile any acknowledgement ambiguity.

## Security, privacy and auditability

- All privileged and NamRA workforce access uses MFA. Critical privileges are just-in-time and time-bounded.
- No standing administrator role may read tax-confidential payloads. Emergency access is dual-approved, recorded and reviewed.
- All external API access is authenticated except the privacy-minimised verification endpoint.
- Tax-confidential data is encrypted in transit and at rest; keys are separated by environment and data domain.
- Fiscal signatures use non-exportable HSM keys and support verification after key rotation.
- Audit events are append-only, tamper-evident, time-synchronised and searchable within 5 minutes.
- Security logs exclude secrets and minimise personal/tax data while retaining investigative value.
- Data extraction is purpose-limited, watermarked where appropriate, rate-limited and fully audited.

## Maintainability and operability

- Domain code has >= 80% meaningful unit coverage; tax rule and ledger invariant branches target 100% decision coverage.
- Contract, migration, security and resilience tests run automatically in delivery pipelines.
- APIs and events follow semantic versioning with published compatibility and deprecation periods.
- Database changes use backward-compatible expand/migrate/contract deployment where zero downtime is required.
- Every service/module exposes health, readiness, metrics, traces and structured logs without exposing tax-confidential data.
- Critical alert runbooks link from the alert and identify business impact, safe diagnostic queries and escalation.

## Accessibility and user experience

- Web applications target WCAG 2.2 AA.
- English is the initial language assumption; localisation architecture supports additional languages without code duplication.
- Error messages state what failed, what the user can do and a reference/correlation ID; they must not reveal sensitive internals.
- Taxpayer portals work on current supported desktop/mobile browsers and on constrained connections; core journeys remain usable at 400 ms latency and intermittent packet loss.

## Portability and procurement

- Deployments are containerised and described through infrastructure as code.
- Business services avoid provider-specific APIs behind defined platform interfaces where practical.
- Source, schemas, pipelines, infrastructure definitions, runbooks and build provenance are part of the handover.
- Every material proprietary dependency has a documented licence, exit/export plan and continuity assessment.

