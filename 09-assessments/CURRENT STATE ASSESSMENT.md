# VAT-MSA Current State Assessment

**Assessment date:** 23 August 2026

**Repository:** `C:\Users\Jean-Pierre\Desktop\2026 FOLDERS\SAFINA BUSINESS ADVISORY\SAFINA\VAT Management System`

**Assessed baseline before this increment:** `a7fccf200fdd7cf087f4b691084db3061e3ebb8c`

**Execution model:** controlled sequential remediation; Issue 1 only

**Environment:** local, synthetic data, no live external integrations

**Release decision:** **NOT PRODUCTION-READY**

## Executive update

VAT-MSA is a functional controlled-pilot application. The Phase 0 increment corrected the previous local database upgrade/startup failure, bound invoice certification to an authority-approved effective statutory rule, prohibited development certificate signing in production, reconciled all 52 runtime v1 paths with OpenAPI, introduced signed single-use privileged evidence, and restored a passing local release gate.

The earlier forensic assessment dated 23 August 2026 remains useful for its broad inventory, but its baseline and several current-state findings are superseded. In particular, the canonical local runtime is no longer blocked by the licence seed failure; OpenAPI is no longer missing runtime paths; the high-threshold dependency gate no longer reports critical/high advisories; and the assessed critical local journeys now render or respond successfully.

The supplied 27-issue prompt has been made executable through the [controlled sequential remediation execution addendum](../06-delivery/controlled-sequential-remediation-execution-addendum.md). The addendum corrects its incomplete state machine, defines authority and safety gates, resolves overlapping issue ownership, prevents stale failure assumptions and preserves strict PASS-only unlocking.

Under that strict sequence, **Issue 1 is the only unlocked issue**. It has locally verifiable controls, but it cannot receive PASS because the production identity provider, actual MFA and recovery operations, managed-edge origin isolation, revocation behaviour and independent security evidence are not present in this local environment. **Issue 1 is BLOCKED — EXTERNAL DEPENDENCY REQUIRED. Issues 2–27 remain LOCKED.**

## Current system position

| Area | Current evidence-backed state | Important remaining boundary |
| --- | --- | --- |
| Local runtime and database upgrade | Functional; legacy local D1 upgrades non-destructively and readiness/core routes respond | Managed production migration, backup/restore and rollback evidence |
| Identity | Provisioned Sites identity headers map by stable provider subject; production fails closed unless the Sites dispatch trust mode is declared | Signed production IdP contract, direct-origin denial, header strip/re-injection proof, identity lifecycle and revocation evidence |
| Privileged step-up | HMAC-SHA256 v2 evidence is actor-, MFA-method-, issuer-, session-, origin- and action-bound, time-limited and persisted once by digest | Trusted IdP/broker issuance, real MFA enrollment/recovery and independent spoof/replay testing |
| Authorization and governance | Central permissions/licence policy, no self-approval, immutable decisions, quarterly access reviews and non-destructive expiry are implemented foundations | Production IAM/PAM operation, tenant-isolation penetration evidence and controlled database administration |
| Self-service signup | Company intake works with synthetic pending records; no licence is activated | Identity proofing, verified notifications, sandbox payment lifecycle and provisioning acceptance |
| Licensing | Central page/API/search/command enforcement and expired read/export/compliance continuity are implemented | Provider-backed commercial lifecycle, reconciliation and race/load acceptance |
| Statutory VAT | Runtime now requires one effective `AUTHORITY_APPROVED` Namibia rule and fails closed otherwise | Tax/Finance approval, signed golden vectors and authoritative country rule activation |
| Invoice/certificate | Invoice pipeline, ledgers, audit and outbox exist; rule-bound certification checks are implemented | Production HSM/KMS signing, trusted counterparties, authority delivery and reconciliation |
| Expenses/documents | Receipt requirement, approved CLEAN scan gate, quarantine rules and maker-checker controls are implemented | Production malware/CDR provider, retention/legal hold and accounting posting/reversal policy |
| API contract | 52 runtime v1 paths and 65 method/path operations are represented in OpenAPI and contract-tested | External consumer conformance and production gateway policy |
| Dependencies/build | Phase 0 gate reported 0 critical, 0 high and 1 moderate development-only transitive advisory | Remove or formally time-bound the Drizzle/esbuild tooling risk |
| External integrations | ITAS, real payments, email, SMS and event delivery remain disabled or unconfigured | Signed contracts, approved environments, credentials, owners and operational acceptance |
| Production operations | Architecture, SLO and runbook foundations exist | Deployed observability, incident response, capacity, backup/restore and DR exercises |

## Issue 1 — Production Identity Foundation

### A. Problem

The current application consumes hosting-injected identity headers and relies on a declared Sites dispatch boundary. The repository does not prove that a production origin is unreachable directly or that caller-supplied identity headers are stripped and replaced. Actual MFA enrollment, recovery, revocation and production-equivalent IdP operation are also unevidenced.

The Phase 0 step-up token was signed, actor-bound, time-limited and single-use, but its v1 payload did not cryptographically bind the asserted MFA method, trusted issuer, application session, request action or origin. The Phase 0 evidence description therefore exceeded what the token itself proved.

### B. Root cause

The identity assurance boundary spans services and owners outside this repository: the IdP, Sites/edge dispatch, DNS/origin controls, secret management, MFA/recovery administration and independent security assessment. The initial local step-up format intentionally covered a smaller Phase 0 slice and did not carry the complete assurance context.

### C. Existing implementation before this increment

- `app/chatgpt-auth.ts` rejected production identity unless `VAT_MSA_IDENTITY_TRUST_MODE=SITES_DISPATCH` and mapped platform subject identifiers to active provisioned identity links.
- `lib/security/step-up-evidence.ts` signed `v1.<issued-at>.<nonce>.<HMAC>` and rejected tampering, actor mismatch, expiry, future timestamps and weak secrets.
- `lib/security/step-up.ts` rejected unsigned privileged evidence in production and persisted an evidence digest once in `step_up_evidence_uses`.
- Migration `0015_phase0_stabilization.sql` created the single-use evidence table and unique digest control.
- PR-004 in the production-readiness backlog correctly remained open for identity and origin assurance.

### D. Changes made in this increment

- Replaced the minimal v1 step-up format with a signed v2 claims envelope.
- Bound evidence to the internal actor, exact issuer, exact HTTP method/path audience, application origin and application session.
- Required a signed `mfa` authentication-method claim and bounded both assertion age and underlying authentication age.
- Retained five-minute validity, future-clock-skew rejection, HMAC-SHA256 verification and database-enforced single use.
- Added exact issuer configuration and made a missing/weak verifier fail closed.
- Added negative coverage for cross-user, cross-session, cross-origin, cross-action and wrong-issuer reuse, tampering, expiry, future time, stale authentication and weak configuration.
- Added the remediation execution addendum so prompt status and acceptance decisions are unambiguous.

### E. Files/components changed

- `lib/security/step-up-evidence.ts`
- `lib/security/step-up.ts`
- `tests/step-up-evidence.test.ts`
- `.env.example`
- `03-api/openapi.yaml`
- `05-security/phase0-identity-origin-trust.md`
- `06-delivery/controlled-sequential-remediation-execution-addendum.md`
- `09-assessments/CURRENT STATE ASSESSMENT.md`
- `09-assessments/VAT-MSA-CURRENT-STATE-ASSESSMENT-2026-08-23.md` (supersession notice only)

### F. Database changes

None. The existing unique `step_up_evidence_uses.evidence_digest` persistence remains suitable for single-use v2 evidence.

### G. API/contract changes

No route was added or removed. Privileged command requests carrying signed evidence now require:

- `x-vat-msa-step-up-evidence`: v2 signed claims envelope;
- `x-vat-msa-session-id`: the session identifier included in the signed claims;
- configured `VAT_MSA_STEP_UP_ISSUER`: exact trusted issuer match.

The signed audience is the uppercase HTTP method plus exact route pathname, and the signed origin must match the request origin. Existing local synthetic confirmation remains non-production only.

### H. Security changes

The v2 format prevents a valid captured token from being repurposed for another actor, session, origin or privileged action and prevents a recently issued assertion from masking stale authentication. Database digest uniqueness continues to reject exact replay. These are local application controls; their trustworthiness in production still depends on exclusive signing-key custody and an accepted identity broker.

### I. Tests

The targeted tests passed (2 files, 10 tests). The complete local release gate also passed:

- fresh valid MFA evidence;
- tamper and cross-user rejection;
- cross-session, cross-origin, cross-action and wrong-issuer rejection;
- expired, future-dated and stale-authentication rejection;
- weak verifier rejection;
- database duplicate-digest rejection;
- lint, typecheck, complete unit/migration suite, secret scan, dependency audit, SBOM and production build.

Recorded result: ESLint passed; TypeScript passed; 21 test files and 106 tests passed; the heuristic local secret scan passed; the high-threshold dependency audit passed with zero critical/high and one moderate development-only advisory; the CycloneDX SBOM was regenerated with four production components; and the Vinext/Vite production build completed with all 52 v1 API paths emitted.

### J. End-to-end verification

**NOT VERIFIED — EXTERNAL ENVIRONMENT REQUIRED.** A local signed fixture is not a production-equivalent IdP login. No approved IdP tenant, disposable identity set, phishing-resistant MFA factor, recovery operator, managed origin or independent attack-test environment was supplied for this assessment.

### K. Evidence

- Phase 0 evidence: `09-assessments/PHASE0-STABILIZATION-EVIDENCE-2026-08-23.md`
- Identity/origin boundary: `05-security/phase0-identity-origin-trust.md`
- External acceptance backlog: `06-delivery/phase0-production-readiness-evidence-backlog.md`, item PR-004
- Automated tests: `tests/step-up-evidence.test.ts` and `tests/phase0-migration.test.ts`

### L. Residual risk and exact external dependency

PR-004 requires an approved production-equivalent IdP and edge environment plus accountable IAM/Cloud owners and CISO acceptance. Evidence must cover OIDC/SAML configuration as applicable, issuer/audience/signature/time validation, phishing-resistant MFA enrollment and enforcement, controlled recovery, revocation, session controls, direct-origin denial, trusted-header replacement, key custody/rotation, clock synchronization, and authorized rejection tests for forgery, replay, invalid issuer/audience/signature/origin and unauthorized recovery.

Until that evidence exists, a compromised or misconfigured dispatch/origin/broker could undermine the local application controls.

### M. Acceptance decision

**BLOCKED — EXTERNAL DEPENDENCY REQUIRED**

Local remediation is materially stronger and is **PARTIALLY COMPLETE / LOCALLY VERIFIED**. It does not satisfy the mandatory production-equivalent end-to-end or operational gates. Issue 1 does not PASS and is not CLOSED.

## Sequential remediation control board

| # | Issue | Status | Gate/evidence position |
| ---: | --- | --- | --- |
| 1 | Production identity foundation | **BLOCKED — EXTERNAL DEPENDENCY REQUIRED** | Local controls improved; PR-004 and production-equivalent E2E/operational evidence open |
| 2 | Production identity proofing | **LOCKED** | Issue 1 has not passed |
| 3 | Taxpayer/counterparty trust | **LOCKED** | Issue 1 has not passed |
| 4 | Authority provisioning/federation | **LOCKED** | Issue 1 has not passed |
| 5 | Commercial subscription/licence operations | **LOCKED** | Issue 1 has not passed |
| 6 | Security bypass/runtime viability | **LOCKED** | Issue 1 has not passed |
| 7 | Account-creation lifecycle | **LOCKED** | Issue 1 has not passed; “failing page” must be freshly reproduced |
| 8 | Live ITAS/authority adapter | **LOCKED** | Issue 1 has not passed |
| 9 | Email/SMS/invitations | **LOCKED** | Issue 1 has not passed |
| 10 | Statutory VAT rule engine | **LOCKED** | Issue 1 has not passed |
| 11 | Production-scale taxpayer reconciliation | **LOCKED** | Issue 1 has not passed |
| 12 | Full reconciliation lifecycle | **LOCKED** | Issue 1 has not passed |
| 13 | VAT return formulas/authority submission | **LOCKED** | Issue 1 has not passed |
| 14 | Tax authority case management | **LOCKED** | Issue 1 has not passed |
| 15 | Financial accounting core | **LOCKED** | Issue 1 has not passed |
| 16 | Accounting posting/malware protection | **LOCKED** | Issue 1 has not passed |
| 17 | Inventory management | **LOCKED** | Issue 1 has not passed |
| 18 | Order-to-cash/fulfilment | **LOCKED** | Issue 1 has not passed |
| 19 | Process catalogue/serialization | **LOCKED** | Issue 1 has not passed |
| 20 | File security/retention | **LOCKED** | Issue 1 has not passed |
| 21 | Reporting/high-volume reads | **LOCKED** | Issue 1 has not passed |
| 22 | Device trust/offline operation | **LOCKED** | Issue 1 has not passed |
| 23 | External system contracts | **LOCKED** | Issue 1 has not passed |
| 24 | Payment-provider productionization | **LOCKED** | Issue 1 has not passed |
| 25 | Event platform | **LOCKED** | Issue 1 has not passed |
| 26 | Communication platform | **LOCKED** | Issue 1 has not passed |
| 27 | AI platform | **LOCKED** | Issue 1 has not passed |

## Readiness determination

| Readiness dimension | Determination |
| --- | --- |
| Functional local pilot | **YES — with disabled external/statutory production paths** |
| Issue 1 local application controls | **PARTIALLY COMPLETE / LOCALLY VERIFIED; full local gate passed** |
| Issue 1 production acceptance | **BLOCKED** |
| Production readiness | **NO** |
| Enterprise readiness | **NO** |
| Government integration readiness | **NO** |
| Global deployment readiness | **NO** |

The next permitted action under the supplied strict sequence is to obtain and test the PR-004 production-equivalent identity/origin evidence package. No subsequent issue is unlocked by this assessment.
