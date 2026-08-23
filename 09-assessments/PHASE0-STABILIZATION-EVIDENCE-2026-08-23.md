# VAT-MSA Phase 0 stabilisation evidence

Assessment date: 23 August 2026

Implementation baseline: local `main` at `fadf9837ff908a27fe9e87a39b13de13d78a6b52`

Implementation authority: user approval recorded in the VAT-MSA development task

Environment: canonical local repository and project-local synthetic D1/R2 bindings

Production release decision: **BLOCKED — external acceptance evidence remains open**

## Outcome

The controllable Phase 0 code increment is implemented and passes the local release gate. The legacy project D1 upgrades without deletion, the application starts, statutory invoice certification fails closed unless one authority-approved effective Namibia rule exists, development certificate signing cannot execute in production, production identity assertions require the declared Sites dispatch trust boundary, privileged evidence is signed and single-use, OpenAPI matches runtime, and critical/high dependency advisories are zero.

This result does not approve a production deployment, a statutory tax rule, an ITAS connection, a production signing key, or infrastructure assurance.

## Implemented controls and evidence

| Phase 0 objective | Implemented evidence | Verification result |
| --- | --- | --- |
| Deterministic database recovery | Runtime compatibility upgrade repairs legacy authority-domain columns and missing reference rows without deleting records; migration `0015_phase0_stabilization.sql` records a required schema revision. Production request handling performs revision verification only and does not create or seed schema. | Existing project D1 upgraded; readiness 200; dashboard 200; company signup 200; `PRAGMA foreign_key_check` returned no rows. |
| Statutory rule binding | Invoice API resolves exactly one `AUTHORITY_APPROVED`, effective-dated Namibia rule with legal-authority evidence. Invoice, line and certificate database triggers enforce rule, date, currency, rate/category and certificate-version binding. Certification hash binds payload and rule evidence. | Golden integer-cent tests pass. A 14% synthetic invoice was rejected with `APPROVED_TAX_RULE_NOT_AVAILABLE` because the local pilot rule remains unapproved. |
| Production signing boundary | Local signatures use `DEV-SHA256-LOCAL-ONLY`; the adapter throws in production until an approved HSM/KMS signer exists. | Static/type/build gate passed; production signing remains externally blocked. |
| Identity, origin and step-up | Production platform headers require `VAT_MSA_IDENTITY_TRUST_MODE=SITES_DISPATCH`. Edge requirements deny direct origin and strip/re-inject identity/assurance headers. Privileged commands await actor-bound, five-minute, HMAC-SHA256 evidence persisted once by digest. | Tamper, cross-user, expiry, future-time and weak-secret tests pass; database duplicate digest is rejected. Managed-edge and identity-broker evidence remains externally blocked. |
| Dependency remediation | React, Vinext, Vite, Vitest, Cloudflare tooling and Wrangler were upgraded; `security:audit` is mandatory in `security:ci`; workerd is the only explicitly allowlisted install script. | 0 critical, 0 high, 1 moderate. The remaining moderate is development-only esbuild 0.18.20 through drizzle-kit tooling; no patched drizzle-kit dependency path is presently available. |
| API and event reconciliation | OpenAPI includes all 52 runtime v1 paths and 65 method/path operations; stale certificate route removed. Invoice rule/certification fields are reflected in OpenAPI and event catalogues. | Contract test compares route source with OpenAPI and passes. |
| Critical local journeys | Browser exercised dashboard, company signup, invoice entry and return maker-checker pages. Authenticated HTTP smoke exercised accounting, administration, expense and licence APIs. | Synthetic signup `VMS-2026-E437E7D560` was accepted as `PENDING_VERIFICATION` and `NOT_ACTIVATED`; no payment, message, account or licence activation occurred. |

## Release-gate result

Command: `pnpm security:ci`

- ESLint: pass.
- TypeScript: pass.
- Vitest: 21 files, 103 tests passed.
- Secret scan: pass (heuristic local baseline; enterprise detection remains required).
- Dependency audit at high threshold: pass; zero critical/high, one moderate development-only advisory.
- CycloneDX SBOM generation: pass and `artifacts/sbom.cdx.json` updated.
- Vinext/Vite production build: pass; 52 v1 API paths emitted.

## Residual restrictions

- No tax rule is promoted to `AUTHORITY_APPROVED` by this increment. Tax/Finance must supply and sign approved golden vectors and legal references.
- No production certificate can be issued until the HSM/KMS adapter, key ceremony, rotation, revocation and public-verification profile are accepted.
- Live ITAS, real payments, email, SMS and unapproved statutory behaviour remain disabled.
- Tenant-isolation penetration testing, managed-edge origin proof, operational observability, backup/restore and DR exercises require independent environments and owners.
- The remaining moderate development-server advisory is accepted only for this local build-tool path; it is not permission to expose the Drizzle development server.

The authoritative open acceptance record is [Phase 0 production-readiness evidence backlog](../06-delivery/phase0-production-readiness-evidence-backlog.md).
