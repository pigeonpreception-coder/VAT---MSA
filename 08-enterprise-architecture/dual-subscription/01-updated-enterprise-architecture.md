# Dual Subscription and Self-Service Onboarding Enterprise Architecture

**Sequence:** 01 of 29
**Status:** approved local/staging implementation baseline; no production activation
**Decision authority:** mandatory VAT-MSA dual-authority requirement
**Supersedes:** any interpretation of ADR-017 that lets one organisation licence authorize both government tax and commercial SaaS functions

## Governing model

VAT-MSA has two non-interchangeable authorities:

| Domain | Subscription owner | Access grantee | Entitlement source | Prohibited effect |
|---|---|---|---|---|
| Government tax | Country Tax Governing Authority | verified VAT taxpayer and authorized tax users | active country tax subscription + taxpayer authorization + active VAT status + country feature grant | commercial purchase cannot grant tax access or government roles |
| Commercial SaaS | verified Company System Administrator | organisation employees | active commercial subscription + organisation licence + module/seat entitlement + role | tax authority action cannot grant unrelated business modules or company-admin authority |

The Global Platform Super Administrator operates platform infrastructure and catalogues. It is not implicitly a Tax Authority Administrator, Company System Administrator, taxpayer or employee. No role inherits across these boundaries.

## Canonical identity and tenancy

One legal taxpayer maps to one canonical organisation. Buyer, seller, supplier, importer and exporter are effective-dated capabilities of that organisation, never separate accounts. A global human identity may link to authority, taxpayer or organisation memberships, but each membership has its own issuer, assurance, tenant, jurisdiction, role, validity and policy context.

Commercial records are isolated by `organisation_id`. Government records are isolated by `tax_authority_id + jurisdiction_id`, with taxpayer access further constrained by `taxpayer_id`. Country adapters never become authorization authorities.

## Bounded contexts

1. **Global Identity:** providers, external subjects, MFA/assurance, sessions and linked identities.
2. **Government Tax Authorization:** tax authorities, jurisdiction environments, tax subscriptions, taxpayer authorizations, government administrators and tax feature grants.
3. **Commercial Licensing:** commercial subscriptions, organisation licences, plans, modules, limits, capacity exceptions and licence events.
4. **Organisation Administration:** verified administrators, invitations, employees, hierarchy, roles, workflows and deactivation.
5. **Unified Policy Decision:** combines identity, tenant, permission, authority domain, feature, subscription, tax authorization, licence, capacity, security context and SoD. It never merges source authorities.
6. **Country Adapter Framework:** ITAS and other authority protocols behind disabled-by-default adapters with signed configuration and conformance evidence.

The authoritative commercial policy decision point is the **License & Entitlement Service**. The independently authoritative tax policy decision point is the **Government Tax Authorization Service**. They may share identity and audit infrastructure, but neither service may call the other to obtain a substitute grant.
One authority decision must never substitute for the other authority decision.

## State machines

### Tax subscription

`DRAFT -> UNDER_AUTHORITY_REVIEW -> APPROVED -> ACTIVE -> SUSPENDED | EXPIRED | REVOKED`

Only a verified Tax Authority Administrator operating within the same authority/jurisdiction may request progression. Activation requires country readiness, signed authority evidence and approved adapter posture. No local UI flag is authoritative.

### Taxpayer authorization

`PENDING_VERIFICATION -> AUTHORIZED -> SUSPENDED -> AUTHORIZED | REVOKED`

Authorization requires an active tax subscription, canonical taxpayer match, active VAT status and authority evidence. Suspension denies new tax actions but preserves lawful retrieval, export, correction and audit paths according to policy.

### Commercial onboarding

`APPLICATION -> IDENTITY_VERIFICATION -> ORGANISATION_VERIFICATION -> PLAN_SELECTION -> CAPACITY_SELECTION -> REVIEW -> PAYMENT_PENDING -> PAYMENT_CONFIRMED -> LICENCE_ACTIVE`

The approved local/staging baseline stops at `PAYMENT_PENDING`. Real payment confirmation and licence activation remain disabled. Before activation, the applicant has only onboarding-scope access and cannot enter business workspaces.

### Commercial licence

`PENDING -> ACTIVE -> GRACE | SUSPENDED | EXPIRED | CANCELLED`, with approved upgrade/downgrade events. A downgrade below active use creates `CAPACITY_EXCEPTION`; it never deletes users.

## Feature authority classification

Every feature is exactly one of:

- `GOVERNMENT_TAX`: VAT returns, tax invoices/certification, input/output VAT, tax reconciliation, taxpayer tax records, statutory compliance, audit/refund authority and tax integrations.
- `COMMERCIAL_SAAS`: accounting, ledger, expenses, quotations, inventory, projects, organisation administration, internal workflow, management reporting and approved business integrations.
- `PLATFORM_CONTROL`: global security, operational health and catalog administration; never licensable to a company or tax authority.

Database constraints prohibit a commercial plan from containing `GOVERNMENT_TAX` features and a tax plan from containing `COMMERCIAL_SAAS` features.

## Final authorization algorithm

`identity + session assurance + tenant/jurisdiction + role + permission + feature authority domain + relevant subscription + relevant authorization/licence + capacity + workflow + SoD + security context = allow with obligations or deny`.

For a government-tax feature, the commercial licence is ignored as a grant. For a commercial feature, tax authorization is ignored as a grant. Either may still contribute a denial where tenant, security, legal hold or identity posture requires it. Unknown feature classifications and missing policies fail closed.

## Hard invariants

1. Only a verified authority administrator may provision government tax access.
2. Only a verified Company System Administrator may initiate a company subscription.
3. Commercial plans cannot grant government-tax features or roles.
4. Tax subscriptions cannot grant commercial modules or company administration.
5. Active/invited seat-consuming users never exceed finite capacity.
6. Unlimited capacity is an explicit `UNLIMITED` mode, never an inferred null.
7. Capacity is checked at UI, API, service and database transaction layers.
8. Employee deactivation preserves historical actor attribution and releases capacity.
9. Privileged changes require step-up authentication, independent approval where configured, quarterly access review and immutable audit.
10. No self-approval or emergency SoD override is available.

## Production boundary

Local/staging may use synthetic authority, taxpayer and commercial subscription evidence. Real payments, live ITAS or other authority federation, production country activation, government administrator provisioning and unapproved statutory rules remain disabled until their named authorities approve contracts, keys, assurance, controls and acceptance evidence.
