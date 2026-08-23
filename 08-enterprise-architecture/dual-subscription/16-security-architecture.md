# Dual-subscription security architecture

## Trust boundaries

The public onboarding edge, global identity plane, commercial tenant plane, Government Tax Authority plane, integration adapters, payment adapter, audit plane and data stores are separate trust zones. An authenticated identity is not automatically a member of either authority domain.

The authorization pipeline is:

`authenticated subject -> session assurance -> RBAC -> tenant/jurisdiction ABAC -> authority-domain router -> domain-specific entitlement decision -> resource/workflow policy -> audit decision`

Commercial features call only the License & Entitlement Service. Government tax features call only the Government Tax Authorization Service. The database rejects plan-feature and administrator-domain mismatches so a service defect cannot convert one subscription into the other.

## Controls

- Phishing-resistant MFA and recent step-up for subscription, administrator, taxpayer authorization, privileged invitation and adapter changes.
- No self-approval; no emergency separation-of-duties override; quarterly access reviews.
- Tenant and jurisdiction scoping on every query, command, search and export.
- Secure, HttpOnly, SameSite session cookies; rotation after authentication/privilege changes; short tax-session TTLs.
- CSRF protection for browser mutations; strict origin checks; schema validation and parameterized queries.
- Idempotency and database serialization for application, payment callback and seat-consuming operations.
- Passwords use an approved memory-hard hash; federation links store opaque subjects, not external passwords.
- Envelope encryption, managed keys, rotation, environment separation and field protection for high-risk identifiers.
- Append-only audit events with integrity chaining/export, privacy-safe denial codes and centralized monitoring.
- Rate limits by IP, identity, organisation, authority, taxpayer and operation; enumeration-resistant public responses.
- Non-destructive expiry, suspension and deactivation; historical financial/tax evidence remains immutable under retention policy.

## Disabled production capabilities

Local/staging does not activate real payment, live ITAS, government subscription purchasing, production email/SMS or unapproved statutory decisions. Synthetic data and adapter stubs must be conspicuously labelled and cannot be promoted by configuration alone; production activation requires approved evidence and controlled deployment.
