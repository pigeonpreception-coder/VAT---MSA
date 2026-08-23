# Phase 0 identity, origin and privileged-change boundary

Status: implementation baseline; production activation remains blocked until infrastructure evidence is approved.

## Trust boundary

Production accepts `oai-authenticated-user-*` identity assertions only when `VAT_MSA_IDENTITY_TRUST_MODE=SITES_DISPATCH`. The deployment must deny direct origin access, strip all inbound identity and step-up headers, authenticate the request at the dispatch boundary, and inject fresh platform-owned assertions. If that deployment invariant is absent, VAT-MSA treats the request as unauthenticated.

## Privileged changes

Production privileged commands require `x-vat-msa-step-up-evidence` in the form `v1.<issued-at-ms>.<nonce>.<HMAC-SHA256>`. Evidence is actor-bound, valid for five minutes, permits thirty seconds of future clock skew, and is recorded once by digest in `step_up_evidence_uses`. Reuse fails closed. The HMAC secret must be held in the deployment secret store and contain at least 32 random characters.

Legacy assurance headers and the local confirmation shortcut remain development-only. They are not accepted by production application code and must also be stripped at the edge.

## Required production evidence

- Managed-edge configuration proving direct-origin denial and header stripping/re-injection.
- Identity-broker ownership, key/secret rotation, revocation and incident procedures.
- Clock synchronisation and monitoring for dispatch and application workloads.
- Penetration tests for header spoofing, direct-origin bypass, replay, cross-user evidence and expiry.
- An approved HSM/KMS certificate-signing adapter; the local development signer is deliberately disabled in production.
