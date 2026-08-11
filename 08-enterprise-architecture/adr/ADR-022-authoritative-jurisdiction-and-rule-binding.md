# ADR-022: authoritative jurisdiction resolution and immutable rule binding

- Status: Proposed
- Date: 2026-08-11
- Decision owners: Architecture Board, Tax Governance, Identity/Data Owners

## Context

IP address, user selection and browser locale cannot establish tax jurisdiction. Historical transactions must not be recalculated under current rules.

## Decision

Resolve jurisdiction from verified legal-entity/taxpayer evidence, effective tax registration and matching licensed-country entitlement. Require an approved production-enabled pack version. Pin jurisdiction, pack and tax-rule versions plus resolution evidence to each fiscal transaction.

Country change uses a migration case with authorisation, verification, effective date and preserved history. Missing or conflicting evidence fails closed.

## Consequences

- UI country selectors cannot change legal jurisdiction;
- registrations and licences require effective-dated provenance;
- device geography is only a risk signal;
- corrections create linked records rather than rebinding original rules.

## Rejected

- IP/GPS country detection as authority;
- default-country fallback for fiscal certification;
- recalculation of historical records with latest rules.
