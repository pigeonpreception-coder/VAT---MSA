# Dual-subscription acceptance criteria

## Architecture gate

- [ ] The 29 ordered artefacts exist, cross-reference the same two authority domains and contain no production-readiness claim.
- [ ] An approved ADR records separate Government Tax Authorization and commercial License & Entitlement decisions.
- [ ] The ERD, API catalogue, RBAC/ABAC matrix, threat model and tests trace the ten deepest rules.

## Authority separation

- [ ] Every protected feature is classified `GOVERNMENT_TAX`, `COMMERCIAL_SAAS` or `PLATFORM_CONTROL` and routes to exactly one authoritative decision path.
- [ ] Commercial plans cannot contain government tax features at service and database layers.
- [ ] Tax subscriptions/authorizations cannot contain or grant commercial features.
- [ ] Company administrators cannot provision/alter tax authority accounts, official VAT status, taxpayer authorization or government decisions.
- [ ] Tax authority administrators cannot manage unrelated company subscription, employees, roles or business data.
- [ ] Platform super administration confers no implicit tax, company, taxpayer or employee role.

## Onboarding and identity

- [ ] The public page clearly separates tax access, company administrator subscription and employee invitation/sign-in paths.
- [ ] Ordinary employees cannot initiate a company application through UI, API or database command.
- [ ] Pre-subscription access is limited to verification, organisation setup, plan/capacity review, terms and approved payment steps.
- [ ] The verified purchaser becomes Company System Administrator only after approved activation.
- [ ] ITAS/direct tax access resolves to one canonical taxpayer identity; ambiguity creates review, not a duplicate.
- [ ] Local/staging keeps real payment, live ITAS, production tax activation, email/SMS and unapproved rules disabled.

## Licence and capacity

- [ ] Capacity is explicitly `FINITE` with a positive value, `UNLIMITED`, or `NOT_APPLICABLE`; unlimited is never inferred.
- [ ] Active plus reserved counting memberships never exceed finite capacity under concurrent API calls.
- [ ] The 51st user on a 50-user licence receives `USER_LICENSE_LIMIT_REACHED` with no partial record.
- [ ] Deactivation releases a seat and preserves identity, transaction and audit history.
- [ ] Upgrade capacity is applied only after approved activation.
- [ ] Downgrade below current use opens a capacity exception and never deletes users.
- [ ] Expiry is non-destructive: commercial mutations stop while approved read/export/compliance continuity and independent tax access are preserved.

## Security and operation

- [ ] MFA/recent step-up protects privileged changes; no self-approval or emergency SoD override exists; quarterly access review evidence is produced.
- [ ] Tenant, taxpayer and jurisdiction scope is enforced for pages, APIs, searches, exports, background jobs and offline synchronization.
- [ ] Privileged successes/denials create immutable privacy-safe audit evidence.
- [ ] Idempotency, replay protection and database transactions protect signup, callback, authorization and seat commands.
- [ ] Security, schema, concurrency, API, UI, accessibility, migration and release-gate suites pass with zero unresolved critical/high findings.
- [ ] Scale/availability claims are made only after approved representative testing; external authority outage is isolated from commercial services.

## Implementation approval boundary

Passing this local/staging gate authorizes only synthetic-data implementation of the approved architecture baseline. Production payment, ITAS federation, government activation, statutory rules, communications and deployment remain separate decisions requiring the named owners' evidence and approval.
