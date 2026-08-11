# ADR-020: one global core with signed country compliance packs

- Status: Proposed
- Date: 2026-08-11
- Decision owners: Architecture Board, Tax Governance, CISO

## Context

Country forks duplicate security fixes, fragment data contracts and make historical tax governance unmanageable. Country rules also change independently of core release cadence.

## Decision

Maintain one global core. Represent country law, terminology, currency, documents, calendars, integrations, privacy and residency as schema-constrained, versioned, signed and approved compliance-pack modules. Packs contain no executable code and cannot weaken global controls.

## Consequences

- new countries use the pack contract and adapters rather than core forks;
- pack registry, schema validation, approval and signature infrastructure are mandatory;
- a second-country proof is required before claiming global extensibility;
- pack content errors become regulatory releases and require rollback discipline.

## Rejected

- repository/application fork per country;
- tenant-editable tax settings;
- arbitrary script or expression execution in packs.

## Approval gate

Architecture, tax governance and security must approve the pack schema, signing authority and operating model before implementation.
