# Testing Strategy

## Quality model

VAT-MSA quality is proven through executable business rules, transaction invariants, contract conformance, security evidence and production-like operational exercises. Passing UI tests alone is not release evidence.

## Test layers

| Layer | Primary purpose | Required examples | Gate |
|---|---|---|---|
| Unit and property tests | Prove calculations and invariants quickly | Rounding, tax categories, credit chains, balanced ledger, idempotency state machine | Every commit |
| Rule-pack tests | Prove each effective-dated tax rule against approved examples | Valid, boundary, exemption, zero-rate, input restriction and transition cases | Rule approval |
| Component tests | Exercise domain module with real database/broker substitutes | Atomic invoice/posting/outbox; state transitions; access policy | Pull request |
| Contract tests | Protect API/event compatibility | OpenAPI validation, JSON Schema, consumer-driven event tests, error catalogue | Pull request and partner certification |
| Integration tests | Prove real dependencies | ITAS, IAM, HSM, broker, object store, customs, payments/refunds | Release candidate |
| End-to-end tests | Prove taxpayer-to-NamRA outcomes | Sell, buy, match, exception, return, audit, credit note and refund referral | Release candidate |
| Data tests | Prove source-to-ledger-to-warehouse integrity | Counts, sums, hashes, late events, replay, lineage and masking | Continuous/daily |
| Security tests | Prove identity, authorisation and attack resistance | BOLA/BFLA, credential abuse, injection, SSRF, rate limits, export controls | Each release; penetration before launch |
| Performance tests | Prove capacity and latency | Sustained/burst, large invoices, period end, offline recovery, partner retry storms | Each major release |
| Resilience tests | Prove graceful failure/recovery | Broker/DB node loss, dependency timeout, zone failure, duplicate/reordered events | Monthly in pre-production |
| DR and restore tests | Prove RTO/RPO and evidence integrity | Point-in-time restore, site failover/failback, key availability, reconciliation | Quarterly |
| Accessibility/usability | Prove inclusive completion of core journeys | Keyboard, screen reader, contrast, error recovery, constrained network | Each portal release |
| Operational acceptance | Prove support readiness | Alerts, runbooks, dashboards, on-call, incident simulation, capacity headroom | Production gate |

## Golden business scenarios

1. Standard-rated domestic B2B invoice is certified, posts balanced seller/buyer VAT entries, matches and appears in both period views.
2. Consumer/non-registered buyer invoice posts seller output VAT but does not create an eligible buyer input claim.
3. Zero-rated and exempt lines preserve rule references and do not contaminate the standard-rate total.
4. Mixed-rate invoice reconciles line, category and document totals under the approved rounding rule.
5. Exact retry with the same idempotency key returns the original business outcome; changed payload is rejected.
6. Same invoice through a different idempotency key is detected as a business duplicate.
7. Credit note references an eligible original and creates linked reversing entries without editing history.
8. Buyer-reported difference opens an exception; authorised evidence resolves it and the full decision chain remains auditable.
9. Offline batch preserves device order and hash chain, rejects a gap/replay and processes independent document outcomes safely.
10. Rule change at an effective-date boundary applies the correct version and reproduces historical calculations.
11. Closed-period late event follows approved adjustment/amendment policy and does not silently rewrite a filed return.
12. Refund risk alert explains reason codes; the human approval workflow enforces preparation/approval segregation.

## Test data

- Synthetic taxpayer and invoice data are the default in development and shared test environments.
- Production data may enter a test environment only under a documented, approved and audited exception with minimisation and masking.
- A versioned fiscal conformance pack is owned jointly by VAT Policy, Legal, Product and Quality Engineering.
- Test data covers identifiers, currencies, dates, time zones, extreme values, rounding edges, Unicode names, line volume, duplicates and malicious payloads.

## Release evidence pack

Every production release archives the approved change, traceable requirements, test results, unresolved defects, risk acceptance, SBOM, vulnerability results, signed build provenance, database migration/rollback evidence, performance comparison, observability checks and deployment/fallback plan.

## Defect policy

- No open critical security, ledger-integrity, data-loss or statutory-calculation defect at release.
- High defects require named business/security approval, bounded exposure, compensating control and expiry.
- Flaky tests are defects. A quarantined test has an owner, reason, due date and compensating coverage.
- A failed reconciliation, audit-chain or restore test blocks production promotion.

