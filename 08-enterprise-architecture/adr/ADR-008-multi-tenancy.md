# ADR-008: Shared platform with enforced tenant boundaries

**Status:** Proposed — requires Security, Data and Architecture approval.

## Context

National scale favors shared infrastructure, but taxpayer confidentiality requires strong isolation and proof against cross-tenant access.

## Decision

Use a shared application and database deployment initially, with mandatory organisation/tenant keys on tenant-owned records, policy-derived tenant context, row-level/data-access enforcement, tenant-aware caches/queues/object keys and independent authorization tests. High-risk government/security stores and exceptional tenants may use isolated deployments.

## Consequences

Efficiency and operations improve, while every access path must carry and verify tenant context. A tenant must not select arbitrary tenant IDs. Noisy-neighbour quotas and per-tenant audit apply.

## Alternatives rejected

Relying only on UI filters and a database per taxpayer from day one are rejected.

