# Audit and compliance architecture

## Evidence model

Every privileged or authority decision records an append-only event with: event ID/type/version; UTC time; actor and authenticated subject; authority domain; organisation/tax authority/jurisdiction/taxpayer scope; action/resource; prior/new state; decision and reason code; step-up/authentication assurance; policy/plan/authorization versions; idempotency/correlation IDs; originating service; privacy-safe request fingerprint; and integrity-chain metadata.

Sensitive values are tokenized or referenced by evidence ID. Audit readers are independently authorized; application administrators cannot edit events. Events are exported to immutable retention/SIEM storage with reconciliation of source sequence gaps.

## Mandatory audited actions

- tax authority appointment and subscription lifecycle;
- taxpayer authorize, suspend, revoke and reinstate;
- company administrator verification and commercial application lifecycle;
- payment confirmation and licence activation/change/expiry;
- plan-feature catalogue and adapter configuration;
- invitation, privileged role assignment, activation, suspension and deactivation;
- capacity denial/exception and unlimited-capacity activation;
- cross-domain/tenant/jurisdiction denial and step-up failure;
- expiry-continuity read/export;
- identity-link create, conflict, merge and unlink.

## Governance

No self-approval and no emergency separation-of-duties override are supported. Privileged memberships undergo quarterly access review with attestation, removals and evidence. Retention, disclosure, residency and legal-hold policies are country-specific and require legal approval; this architecture does not assert statutory periods. Audit export must be reproducible without granting commercial administrators government records or tax administrators unrelated commercial records.
