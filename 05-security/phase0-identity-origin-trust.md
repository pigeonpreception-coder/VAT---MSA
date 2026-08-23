# Phase 0 identity, origin and privileged-change boundary

Status: implementation baseline; production activation remains blocked until infrastructure evidence is approved.

## Trust boundary

Production accepts `oai-authenticated-user-*` identity assertions only when `VAT_MSA_IDENTITY_TRUST_MODE=SITES_DISPATCH`. The deployment must deny direct origin access, strip all inbound identity and step-up headers, authenticate the request at the dispatch boundary, and inject fresh platform-owned assertions. If that deployment invariant is absent, VAT-MSA treats the request as unauthenticated.

## Privileged changes

Production privileged commands require `x-vat-msa-step-up-evidence` as a v2 HMAC-SHA256 signed claims envelope plus the matching `x-vat-msa-session-id`. Evidence is bound to the actor, trusted issuer, asserted `mfa` authentication method, session, exact HTTP method/path audience and request origin. Assertion age and underlying authentication age are limited to five minutes with thirty seconds of future clock skew. The signed evidence is recorded once by digest in `step_up_evidence_uses`; reuse fails closed. `VAT_MSA_STEP_UP_ISSUER` must exactly name the trusted broker, and the HMAC secret must be held in the deployment secret store with at least 32 random characters.

These application checks do not prove that MFA occurred. Production acceptance additionally requires evidence that only the approved identity broker can sign the claims, that it derives `mfa`, session and authentication time from the accepted IdP protocol, and that key custody and rotation are controlled.

Legacy assurance headers and the local confirmation shortcut remain development-only. They are not accepted by production application code and must also be stripped at the edge.

## Required production evidence

- Managed-edge configuration proving direct-origin denial and header stripping/re-injection.
- Identity-broker ownership, key/secret rotation, revocation and incident procedures.
- Clock synchronisation and monitoring for dispatch and application workloads.
- Penetration tests for header spoofing, direct-origin bypass, replay, cross-user evidence and expiry.
- An approved HSM/KMS certificate-signing adapter; the local development signer is deliberately disabled in production.
