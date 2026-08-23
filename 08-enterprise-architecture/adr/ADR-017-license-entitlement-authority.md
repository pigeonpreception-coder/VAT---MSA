# ADR-017: Separate licence and entitlement authority

- Status: ACCEPTED - IMPLEMENTATION BASELINE (local/staging, 2026-08-23)
- Owners: Commercial Product, Architecture, CISO, Finance, Legal and Records

## Context

Enterprise SaaS features and limits must be enforced consistently without allowing commercial configuration to override statutory, security or retention duties.

## Decision

Create a License and Entitlement bounded context owning effective-dated plans, subscriptions, organisation licences, feature grants, limits, usage reservations and events. Every protected operation repeats server-side entitlement evaluation after identity and tenant resolution. The strictest identity, security, tax, SoD or licence denial wins. Expiry, cancellation and downgrade never silently delete taxpayer records.

## Consequences

Commercial controls become auditable and race-safe, but require an approved provider, plan catalogue, metering semantics, continuity rules, dispute process and usage reconciliation. Licence state cannot be supplied by clients or modified by ordinary organisation administrators.

## Acceptance

Approve plan/version ownership, provider contracts, state behavior, retention/export/statutory continuity, quota consistency model, administrative separation and abuse tests.

## Implemented binding

The approved local/staging implementation stores the fail-closed permission-to-feature and permission-to-operation mapping in `license_permission_policies`. All protected pages, portal projections and API handlers call the same server-side licence guard after identity and tenant resolution. Business, VAT, compliance and platform command handlers repeat the guard before mutation; workspace search and navigation apply the same resolved organisation scope.

`SUSPENDED`, `EXPIRED` and `CANCELLED` allow only `READ`, `EXPORT`, `COMPLIANCE_WRITE` and `CORRECTION_WRITE`. `BUSINESS_WRITE` and `ADMIN_WRITE` are denied, records remain intact, and write-only navigation is removed. Health/readiness and privacy-minimised public certificate verification are the only unauthenticated licence exemptions. Real pricing, payments, live ITAS and unapproved statutory rules remain disabled.
