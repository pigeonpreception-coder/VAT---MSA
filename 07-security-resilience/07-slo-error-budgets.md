# Service-level objectives and error budgets

## Proposed indicators

| Capability | SLI | Proposed objective | Alerting |
|---|---|---:|---|
| Invoice API availability | valid non-throttled requests receiving correct non-5xx response | 99.95% / 30 days | 2% budget burn in 1h and 5% in 6h |
| Invoice acceptance latency | time to durable acceptance and transaction ID | p95 ≤ 750 ms; p99 ≤ 1.5 s | multi-window latency burn |
| Invoice processing | accepted to validated/ledgered | 99% ≤ 60 s | queue-age and completion ratio |
| Portal reads | successful taxpayer-scoped page/API requests | 99.9% / 30 days; p95 ≤ 2 s | availability and latency burn |
| Security telemetry | required events reaching durable SIEM | 99.99%; p95 ≤ 60 s | immediate loss/lag alert |
| DR | Tier-0 recovery | RTO 30 min; RPO ≤ 5 min | exercise/incident evidence |

Throttled malicious/over-quota traffic is excluded only when policy is correct and observable. Incorrect authorization, cross-tenant disclosure, acknowledged-but-lost invoices and security-event loss are correctness failures regardless of HTTP status.

Targets remain provisional until load and recovery tests validate them. If a service consumes its monthly error budget, freeze non-risk-reducing changes, prioritize reliability and obtain accountable approval for exceptions. SLOs are reviewed quarterly against taxpayer impact and measured infrastructure.
