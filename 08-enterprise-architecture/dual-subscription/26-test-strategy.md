# Dual-subscription test strategy

## Assurance objectives

Testing proves the two authorities cannot grant each other's capabilities, finite capacity is a transactional invariant, unlimited capacity is explicit, onboarding actor rules hold below the UI, expiry/deactivation/downgrade are non-destructive, and disabled integrations cannot activate themselves.

## Test layers

1. **Schema/migration:** CHECK, unique, foreign-key and trigger tests for authority-domain compatibility, administrator scope, canonical identity, capacity modes, concurrent seat use and immutable decisions.
2. **Domain/property:** state-machine transition tables and generated combinations for subscription, authorization, VAT status, feature domain, capacity mode, role and operation.
3. **Service/contract:** independent commercial/tax evaluators, stable denial codes, idempotency, evidence expiry and OpenAPI compatibility.
4. **API/security integration:** authentication, step-up, CSRF, RBAC/ABAC, cross-tenant/country/domain negative tests, rate limits and privacy-safe errors.
5. **Concurrency/resilience:** last-seat races, callback replay, transaction rollback, adapter timeout, outbox replay and cache invalidation.
6. **UI/end-to-end:** dual landing paths; administrator-only commercial application; employee sign-in/invitation; disabled ITAS/payment presentation; accessibility and responsive states.
7. **Release assurance:** lint, typecheck, unit/integration/security suites, migration from supported baselines, secret scan, dependency/SBOM review, production build and smoke tests.

## Data and environments

Local/staging uses named synthetic organisations, taxpayers, authority administrators and commercial administrators. No real identity, payment, ITAS, email/SMS, statutory rule or customer communication is permitted. Test fixtures make government and commercial authority visibly distinct.

## Entry/exit

Implementation starts only after all 29 artefacts exist and the architecture traceability gate passes. Release requires zero unresolved critical/high authority-boundary findings, deterministic concurrency results, migration rollback/recovery evidence, no enabled production adapter, and acceptance criteria traceability. National scale claims require separately approved load limits/window and independent evidence.
