# Error Catalog and API Resilience Standard

## Canonical error envelope

All synchronous APIs return RFC 9457-compatible problem details: `type`, `title`, `status`, stable `code`, safe `detail`, `instance`, `correlationId`, `timestamp`, optional field `errors`, retry metadata and documentation link. No stack trace, query, secret, internal host or other tenant identifier is exposed. Localized user messages map from stable codes at the client; logs retain protected diagnostic context under the correlation ID.

| Code | HTTP | Meaning | Client action | Operator/control action |
|---|---:|---|---|---|
| AUTH_REQUIRED | 401 | missing/expired authentication | reauthenticate once | inspect issuer/token telemetry |
| AUTH_STEP_UP_REQUIRED | 401 | stronger proof needed | complete MFA/step-up | log high-risk action |
| ACCESS_DENIED | 403 | role/attribute/purpose denied | do not retry unchanged | policy decision audited |
| TENANT_SCOPE_INVALID | 403 | cross-tenant/branch scope | correct context or escalate | high-severity security signal |
| RESOURCE_NOT_FOUND | 404 | absent or deliberately concealed | verify identifier | prevent existence disclosure |
| VERSION_CONFLICT | 409 | optimistic concurrency failed | refresh and intentionally reapply | record conflict rate |
| IDEMPOTENCY_CONFLICT | 409 | key reused with different payload | use original payload/new key | retain request hash evidence |
| DUPLICATE_FISCAL_RECORD | 409 | semantic duplicate detected | inspect existing record | fraud/duplicate workflow |
| VALIDATION_FAILED | 422 | field/business rule errors | fix stated fields | aggregate quality trend |
| TAX_RULE_UNAVAILABLE | 503 | no approved applicable rule | queue/draft only | fiscal issuance blocked and paged |
| PERIOD_CLOSED | 422 | attempted posting to closed period | use correction/adjustment flow | no override outside policy |
| ITAS_UNAVAILABLE | 503 | provider circuit open/outage | retry only when advised | durable queue/continuity mode |
| EXTERNAL_OUTCOME_UNKNOWN | 202/503 | timed out after dispatch | query status; do not blindly repeat | reconcile by correlation/idempotency |
| RATE_LIMITED | 429 | quota/fairness limit | honor `Retry-After` | protect shared capacity |
| MALWARE_DETECTED | 422 | upload quarantined | replace file/contact support | SOC alert and custody record |
| OFFLINE_POLICY_EXPIRED | 409 | device/range/rules stale | reconnect and renew | prevent certification |
| OFFLINE_CONFLICT | 409 | server state conflicts | use guided resolution | preserve both versions/evidence |
| RETURN_CONTROL_FAILED | 422 | filing controls unresolved | resolve listed exceptions | blocks submission as policy requires |
| SERVICE_DEGRADED | 503 | safe path temporarily unavailable | bounded retry | activate SLO/runbook |
| INTERNAL_ERROR | 500 | unexpected failure | retry only if operation is idempotent | page on error-budget impact |

Bulk endpoints return per-item outcome plus an overall receipt. Async operations return `202` with an operation resource whose states are `Queued`, `Running`, `Succeeded`, `PartiallySucceeded`, `Failed`, `Cancelled` and `Expired`.

## Resilience rules

| Concern | Mandatory pattern |
|---|---|
| timeouts | explicit connect/read/overall deadlines shorter than caller deadline; cancel downstream work where safe |
| retries | only transient failures and idempotent operations; exponential backoff + jitter; bounded attempts/time |
| idempotency | client key + tenant + operation; canonical request hash; durable outcome and replayed response |
| circuit breaking | per provider/tenant/operation where possible; half-open probes; observable state |
| bulkheads | independent pools/queues for fiscal, administrative, reporting and each external provider |
| backpressure | reject/queue before saturation; `429/503` and retry guidance; bounded queues |
| event delivery | at-least-once transport, idempotent consumers, transactional outbox/inbox, DLQ and controlled replay |
| ordering | aggregate key and sequence/version; no assumption of global ordering |
| schema evolution | additive compatible changes; versioned contracts; consumer-driven compatibility tests |
| graceful degradation | preserve draft/offline/queue or read-only capability; clearly mark stale/provisional data |
| load shedding | protect identity and fiscal lanes; disable expensive search/export/BI first |
| reconciliation | control totals, status queries and exception queues prove eventual external outcomes |

Retry budgets prevent amplification: no nested unbounded retries, maximum elapsed time is documented, and gateways do not retry non-idempotent commands without a persisted key. Poison messages quarantine after threshold; operators repair root cause before replay. DLQs are not long-term storage.

## API governance and tests

Every API has an owner, classification, authentication scheme, authorization policy, rate tier, version policy, SLO, data contract, deprecation date and runbook. CI validates OpenAPI/AsyncAPI linting, backward compatibility, authorization, tenant isolation, fuzz/property cases, idempotency, rate limiting, timeout/retry behavior, oversized payloads, malicious documents and safe error content. Production conformance is continuously sampled without exposing taxpayer data.

