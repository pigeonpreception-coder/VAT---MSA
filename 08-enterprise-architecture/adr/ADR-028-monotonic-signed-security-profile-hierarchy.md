# ADR-028: monotonic signed security-profile hierarchy

- Status: Proposed
- Date: 2026-08-11
- Decision owners: Architecture Board, CISO, Privacy Authority, Country Regulatory Authority

## Context

A global platform needs regional, country and organisation variation, but configuration must not create weaker security, silent legal fallback or country code forks.

## Decision

Use a monotonic hierarchy: non-bypassable platform invariants, global baseline, regional profile, country profile, organisation policy and user/session obligations. A child can tighten but cannot weaken a mandatory parent control. Profiles are schema-validated, canonicalized, hashed, signed, effective-dated, compatibility checked and anti-downgrade protected.

Author, reviewer, approver, signer and activator are separated. Conflicting/unknown mandatory rules, invalid signatures and unauthorized downgrade fail closed and open a governed exception. Legal requirements are encoded only after an approved applicability/interpretation decision.

## Consequences

- one global security core supports country variation without forks;
- profiles require signing-key custody, registry, tests and readiness evidence;
- legal conflicts cannot be resolved automatically;
- current profile drafts remain non-executable.

## Rejected

- tenant override of global security;
- unsigned configuration files as production authority;
- automatic regional rules for every country in a region;
- engineer-selected fallback when law or jurisdiction is uncertain.
