# ADR-016: Backend-enforced dynamic workspace and navigation

- Status: PROPOSED - REQUIRES APPROVAL
- Owners: Product Architecture, UX, IAM and CISO

## Context

Static portal menus cannot safely or clearly represent organisation-specific licences, capabilities, roles and policies. Frontend hiding is not an authorization boundary.

## Decision

Store workspace, folder and item definitions as versioned configuration. Return a server-built projection filtered by identity, organisation, licence, capability, permission and security policy. Re-authorize every destination and action independently. Use lazy hierarchy loading, breadcrumbs, permitted search, favourites and recent-function preferences.

## Consequences

Navigation can vary without source changes and remains usable at high cardinality. Policy and entitlement changes require reliable cache invalidation. Clients cannot be trusted to evaluate or submit policy expressions. Sensitive restricted functions may be hidden rather than advertised.

## Acceptance

Approve the canonical workspace taxonomy, protected classifications, safe restriction messages, cache invalidation bound, WCAG target and negative tests proving modified clients cannot gain access.
