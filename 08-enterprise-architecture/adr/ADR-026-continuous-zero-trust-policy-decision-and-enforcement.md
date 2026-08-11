# ADR-026: continuous zero-trust policy decision and enforcement

- Status: Proposed
- Date: 2026-08-11
- Decision owners: Architecture Board, CISO, Identity Authority, Domain Owners

## Context

Route permissions or network location cannot protect a multi-tenant national tax platform. Decisions depend on identity, tenant, resource, country, classification, purpose, workflow, licence, device/risk and authentication strength, and must apply consistently to APIs, events, search and data.

## Decision

Use a central versioned decision contract with distributed enforcement points. Every protected action is authenticated and explicitly authorized against trusted resource ownership. Policy evaluates RBAC plus ABAC and returns allow, deny, challenge or approval obligations with reason and version references. Enforcement fails closed for protected actions when mandatory policy is missing, stale or conflicting.

Workload identity is short-lived and audience-bound. Privileged access is phishing-resistant step-up, JIT, independently approved, monitored and expiring. Self-approval and emergency SoD overrides are prohibited.

## Consequences

- UI visibility remains a projection, not enforcement;
- event/search/analytics/export consumers must enforce the same authority;
- policy service availability and latency require resilient design and evidence;
- negative authorization and tenant-isolation testing becomes release-critical.

## Rejected

- perimeter trust;
- client-side roles as authority;
- tenant IDs trusted from caller headers;
- standing shared administrators;
- break-glass that grants fiscal authority or bypasses SoD.
