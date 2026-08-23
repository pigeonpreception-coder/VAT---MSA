# ADR-030: Separate Government Tax Authority and commercial SaaS subscription decisions

- Status: Approved for local/staging implementation baseline
- Date: 2026-08-23
- Deciders: VAT-MSA architecture owner (user-approved requirement); production authority decisions remain external

## Context

The pilot uses one organisation licence and a mixed plan containing `CORE_VAT` and commercial modules. That model can incorrectly make a company purchase appear authoritative for government tax access and lacks a separate taxpayer-authorization decision. The approved requirement establishes two subscription authorities that may coexist for one canonical organisation but may never grant each other's capabilities.

## Decision

1. Classify every feature as `GOVERNMENT_TAX`, `COMMERCIAL_SAAS` or `PLATFORM_CONTROL`.
2. Route government features exclusively to a Government Tax Authorization Service evaluating authority subscription, taxpayer authorization, VAT status, jurisdiction, feature and user scope.
3. Route commercial features exclusively to a License & Entitlement Service evaluating organisation subscription/licence, plan/module, explicit capacity, membership, role and scope.
4. Enforce plan-feature domain compatibility and finite capacity in the database as well as services/APIs.
5. Permit only verified Company System Administrators to start commercial signup; ordinary employees use invitations/sign-in.
6. Maintain one canonical organisation/taxpayer and federated identity links; adapters do not create duplicates.
7. Represent capacity as `FINITE`, `UNLIMITED` or `NOT_APPLICABLE`. Downgrade below use opens an exception; expiry/deactivation is non-destructive.
8. Local/staging uses synthetic evidence and cannot activate payment, live ITAS, production government subscriptions or unapproved statutory rules.

## Consequences

One user request may require both domain decisions when a workflow combines tax and commercial resources, but neither decision can substitute for the other. Existing mixed plan seeds and central policy evaluation must be migrated. More entities, evidence versions, denial modes and negative tests are required. This complexity is accepted to prevent authority escalation and preserve lawful continuity.

## Rejected alternatives

- One combined licence: violates authority ownership and permits cross-domain escalation.
- UI-only separation: direct APIs/database races bypass it.
- Treat NULL or a high seat number as unlimited: ambiguous and unsafe.
- Create a second taxpayer for each identity provider: fragments legal identity and audit history.
