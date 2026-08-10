# ADR-017: Separate licence and entitlement authority

- Status: PROPOSED - REQUIRES APPROVAL
- Owners: Commercial Product, Architecture, CISO, Finance, Legal and Records

## Context

Enterprise SaaS features and limits must be enforced consistently without allowing commercial configuration to override statutory, security or retention duties.

## Decision

Create a License and Entitlement bounded context owning effective-dated plans, subscriptions, organisation licences, feature grants, limits, usage reservations and events. Every protected operation repeats server-side entitlement evaluation after identity and tenant resolution. The strictest identity, security, tax, SoD or licence denial wins. Expiry, cancellation and downgrade never silently delete taxpayer records.

## Consequences

Commercial controls become auditable and race-safe, but require an approved provider, plan catalogue, metering semantics, continuity rules, dispute process and usage reconciliation. Licence state cannot be supplied by clients or modified by ordinary organisation administrators.

## Acceptance

Approve plan/version ownership, provider contracts, state behavior, retention/export/statutory continuity, quota consistency model, administrative separation and abuse tests.
