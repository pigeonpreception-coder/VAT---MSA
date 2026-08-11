# ADR-024: separate regulatory administration from organisation and technical administration

- Status: Proposed
- Date: 2026-08-11
- Decision owners: CISO, Regulatory Authority, Architecture Board

## Context

Organisation administrators must configure their business without gaining national tax authority. Technical administrators must operate infrastructure without changing law.

## Decision

Create a separate Jurisdiction Configuration Administration boundary with regulatory maker, reviewer, approver, country release operator and auditor roles. Enforce jurisdiction ABAC, step-up authentication, two-person approval, immutable evidence and no emergency SoD override. Organisation roles cannot receive regulatory or pack-signing permissions. Technical super-administration cannot approve tax content.

## Consequences

- separate portal, service, permission catalogue and audit classification;
- regulatory roles are jurisdiction-scoped and regularly certified;
- changes are structured diffs against signed versions;
- incident recovery cannot bypass regulatory approval.

## Rejected

- general NamRA employee rule editing;
- platform super-admin implicit tax authority;
- ordinary tenant-configurable VAT rates.
