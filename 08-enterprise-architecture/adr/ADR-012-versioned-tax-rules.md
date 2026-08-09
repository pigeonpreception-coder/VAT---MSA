# ADR-012: Immutable fiscal history and versioned tax rules

**Status:** Proposed — requires Tax Policy, Legal and NamRA approval.

## Context

Tax rates, exemptions and validation rules change. Audits must reproduce the lawful result that applied when a transaction occurred.

## Decision

Execute typed, deterministic, signed and effective-dated rule bundles. Every calculation stores rule-set identifier, version/hash, inputs and trace. Certified transactions and ledger postings are append-only; correction uses linked reversals, adjustments, credit/debit notes or superseding returns. Historical data is never silently recomputed.

## Consequences

Results are reproducible and governed; tax specialists need a maker-checker lifecycle, golden tests, shadow deployment, monitoring and rollback. Rule retention becomes permanent for the lifetime of affected records.

## Alternatives rejected

Hard-coded scattered rates, arbitrary executable scripts and retroactive in-place updates are rejected.

## Gate

Legal rule authority, effective-time semantics, rounding, correction and retention **require NamRA/legal confirmation**.

