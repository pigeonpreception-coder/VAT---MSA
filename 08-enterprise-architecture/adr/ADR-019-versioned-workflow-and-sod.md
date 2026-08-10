# ADR-019: Typed versioned workflow with mandatory segregation of duties

- Status: PROPOSED - REQUIRES APPROVAL
- Owners: Workflow Architecture, CISO, Finance Controls, Domain Owners and Records

## Context

Organisation approval chains must be configurable, while historical decisions, legal domain transitions and segregation of duties must remain system controlled.

## Decision

Provide a typed workflow designer with an approved node, assignment and condition vocabulary. Publishing produces an immutable version and definition hash. Each workflow instance pins one version; completed decisions are append-only. Domain services expose approved transitions and retain legal invariants. System SoD rules are evaluated at design, assignment and decision time and cannot be weakened by organisation administrators.

## Consequences

Sequential, parallel, conditional, amount/branch/department/role approvals, escalation, delegation and substitution are supported without tenant-authored code. Timer recovery, version retention, simulation, quorum semantics, migration rules and violation remediation require explicit governance.

## Acceptance

Approve configurable transition boundaries, expression vocabulary, publication quorum, delegation rules, timer/recovery SLOs, SoD catalogue and whether a tightly controlled emergency override exists.
