# ADR-023: signed pack lifecycle and independent Country Readiness Gate

- Status: Proposed
- Date: 2026-08-11
- Decision owners: Country Readiness Board, CISO, Regulatory Authority

## Context

A syntactically valid configuration file is not evidence that a country is legally, securely or operationally ready.

## Decision

Require schema validation, source evidence, compliance review, security review, independent approval, content hash, digital signature, scheduled activation, golden tests, global regression and rollback. Separately require Country Readiness states: `NOT READY`, `IN DEVELOPMENT`, `UNDER REGULATORY REVIEW`, `TECHNICALLY READY`, `APPROVED`, `PRODUCTION ENABLED`.

Only a signed approved pack plus `PRODUCTION ENABLED` readiness and an active country licence can serve fiscal operations.

## Consequences

- uploads cannot self-activate;
- signing keys require approved KMS/HSM custody;
- readiness evidence is auditable and expires;
- any critical unknown blocks production.

## Rejected

- developer approval as regulatory authority;
- single-person create/approve/deploy;
- treating automated test success as legal approval.
