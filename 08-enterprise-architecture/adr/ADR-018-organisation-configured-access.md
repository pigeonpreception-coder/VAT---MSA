# ADR-018: Organisation-configured roles under system policy ceilings

- Status: PROPOSED - REQUIRES APPROVAL
- Owners: IAM Architecture, CISO, Product, HR and Business Control Owners

## Context

Different organisations use different job titles, departments, roles, scopes and financial authorities. Hardcoded organisation structures are not viable, but tenant configuration cannot become a security boundary.

## Decision

Separate Employee, JobTitle and Position from User and permission. Allow organisation-owned roles and permission sets only from a protected grantable catalogue and within platform/NamRA policy ceilings. Evaluate role, capability, tenant, record scope, amount, workflow authority, assurance, risk and entitlement server-side. Retain protected system roles for NamRA, platform operations and primary organisation administration.

## Consequences

Organisations can model their workforce without source changes. Policy compilation, privileged grant workflows, recertification, tenant-safe queries and revocation become core controls. Existing `Taxpayer Administrator` maps to the canonical Organisation Portal Administrator role rather than creating a second identity.

## Acceptance

Approve the grantable permission catalogue, administrator hierarchy, financial thresholds/ceilings, reviewer scope, any higher-risk review frequency, dormant definition and revocation SLO. Protected and privileged access review remains at least quarterly.
