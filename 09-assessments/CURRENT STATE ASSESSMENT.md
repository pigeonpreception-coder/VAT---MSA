# VAT-MSA Current State Assessment

**Assessment date:** 23 August 2026

**Repository:** `C:\Users\Jean-Pierre\Desktop\2026 FOLDERS\SAFINA BUSINESS ADVISORY\SAFINA\VAT Management System`

**Assessed baseline before this increment:** `db2355d`

**Execution model:** controlled sequential remediation with programme-authorised Issue 2, Issue 3 and Issue 4 sequence exceptions

**Environment:** local, synthetic data, no live external integrations

**Release decision:** **NOT PRODUCTION-READY**

## Executive update

VAT-MSA remains a functional controlled-pilot application. Issue 1's application controls are locally verified, but Issue 1 remains `BLOCKED — EXTERNAL DEPENDENCY REQUIRED` because production identity-provider, MFA/recovery, origin-isolation, revocation and independent attack evidence are unavailable.

On 23 August 2026 the programme authority explicitly instructed the remediation programme to move first to Issue 2 despite the Issue 1 blocker, then to Issue 3 despite the outstanding Issue 2 external evidence, and now to Issue 4 despite the outstanding Issue 3 authority evidence. These are recorded sequence exceptions, not waivers or production acceptance. Issue 5 and later issues remain locked unless Issue 4 passes or another explicit exception is recorded.

Issue 2 now has a durable local identity-proofing foundation: each registration receives one fail-closed proofing case; canonical VAT numbers and TINs are independently unique; deterministic reconciliation records explainable matched/conflicting fields and confidence; mismatch resolution requires an independent actor; synthetic evidence is database-separated from authority verification; and proofing decisions/events are append-only. The scoped API and registration UI expose the posture without returning raw provider evidence.

This increment does **not** claim that ITAS/NamRA verified any identity. Live ITAS remains disabled. Registration still stops at `PENDING_VERIFICATION`; no proofing result creates a taxpayer, organisation, user, membership, authorization, subscription or licence. Issue 2 consequently remains `BLOCKED — EXTERNAL DEPENDENCY REQUIRED` pending PR-011 and the applicable PR-003 authority integration evidence.

Issue 3 now has a fail-closed local counterparty-trust foundation. Customers and suppliers carry VAT, TIN and company-registration identifiers, an explicit provider/environment trust profile, tax-registration status, confidence, evidence provenance and expiry. New quotations, projects and supplier expenses reject pending, invalid, mismatched, unavailable or expired parties; taxed expenses additionally require active tax registration. Synthetic verification is restricted to local/explicit staging, expires after 24 hours and cannot be confused with authoritative NamRA/ITAS/BIPA evidence.

This increment does **not** claim that NamRA, ITAS or BIPA validated a counterparty. Issue 3 remains `BLOCKED — EXTERNAL DEPENDENCY REQUIRED` pending PR-012 and applicable PR-003 evidence.

Issue 4 now has a fail-closed local Tax Authority governance foundation. The system records an authority hierarchy, protected administrative duty catalogue, independently approved role assignments, environment-labelled federation registrations, durable onboarding cases, append-only decisions/events and authority-specific quarterly access reviews. Onboarding commands require assigned-authority scope, central licensing, step-up authentication and idempotency. A requester cannot decide their own case, and protected decision roles are database-enforced.

The API exposes only local-staging approval/rejection and records every production-target request as `BLOCKED_EXTERNAL`; it exposes no production-activation command. Database activation requires a current production-approved federation, a current completed access review, evidence/readiness references, and security, privacy, legal, integration and activation approvals from five distinct authorised reviewers. The synthetic ITAS federation remains `CONTRACT_PENDING` / `UNCONFIRMED`. Issue 4 therefore remains `BLOCKED — EXTERNAL DEPENDENCY REQUIRED` pending PR-013 plus PR-004 and applicable PR-003 evidence.

## Authorised sequence exception records

| Issue unlocked | Authority direction | Recorded scope | Predecessor/production effect | Downstream effect |
| --- | --- | --- | --- | --- |
| Issue 2 | “move to issue number 2, since issue 1 can't be resolved and completed now” | Bounded local/staging identity-proofing implementation with synthetic data | Issue 1 remains blocked; live identity proofing/ITAS stay disabled | Issue 3 remained locked until separately directed |
| Issue 3 | “move to issue number 3, since issue 2 is now resolved and completed” | Bounded local/staging counterparty-trust implementation with synthetic data | Direction does not create PR-011/PR-003 evidence; Issue 2 remains blocked/not closed; live NamRA/ITAS/BIPA validation stays disabled | Issue 4–27 remain locked absent Issue 3 PASS or another explicit exception |
| Issue 4 | “move to issue number 4, since issue 3 is now resolved and completed” | Bounded local/staging authority-provisioning, governance and federation foundation with synthetic data | Direction does not create PR-012/PR-003 evidence; Issue 3 remains blocked/not closed; live federation and production activation stay disabled | Issue 5–27 remain locked absent Issue 4 PASS or another explicit exception |

## Current system position

| Area | Current evidence-backed state | Important remaining boundary |
| --- | --- | --- |
| Local runtime and database upgrade | Functional; forward-only revisions and non-destructive local upgrades are enforced | Managed production migration, backup/restore and rollback evidence |
| Identity authentication | Sites identity maps by stable provider subject and production fails closed without declared dispatch trust | Production IdP, MFA/recovery, origin and revocation acceptance (PR-004) |
| Identity proofing | Durable scoped cases, deterministic candidates, mismatch evidence, immutable events and synthetic/authority separation | ITAS/NamRA proofing contract, accepted attributes/provenance/freshness, merge rules and production-equivalent conformance (PR-011) |
| Taxpayer uniqueness | VAT and TIN are database-unique; one registration has at most one proofing case | NamRA-approved identifier precedence, deregistration and legitimate merge procedure |
| Counterparty trust | VAT/TIN/company-registration uniqueness, durable trust/tax status, expiring evidence, immutable snapshots/events and database transaction gates; synthetic evidence is test-only | NamRA/ITAS/BIPA contracts, authoritative status/provenance/freshness/reconciliation and conformance evidence (PR-012/PR-003) |
| Tax Authority governance | Assigned-authority hierarchy, protected roles/SoD, contract-labelled federation, step-up onboarding, immutable independent decisions, quarterly reviews and database production-activation gates | Participating-authority appointment, federation/conformance, five-party activation, rollback and incident evidence (PR-013), plus PR-004/PR-003 as applicable |
| Authorization and governance | Central permissions/licence policy, no self-approval, immutable decisions, quarterly access reviews and non-destructive expiry foundations | Production IAM/PAM, tenant-isolation assessment and controlled database administration |
| Self-service signup | Company intake works with synthetic pending records; no licence is activated | Authoritative identity/taxpayer proof, verified notifications, sandbox payment and provisioning acceptance |
| Licensing | Central page/API/search/command enforcement and expired read/export/compliance continuity are implemented | Provider-backed commercial lifecycle, reconciliation and race/load acceptance |
| Statutory VAT | Runtime requires one effective `AUTHORITY_APPROVED` Namibia rule and fails closed otherwise | Tax/Finance approval, signed golden vectors and authoritative rule activation |
| Expenses/documents | Receipt, approved CLEAN scan, quarantine and maker-checker controls are implemented | Production malware/CDR provider, retention/legal hold and posting/reversal policy |
| API contract | 56 runtime v1 paths and 70 method/path operations are represented and contract-tested | External consumer and production gateway conformance |
| External integrations | ITAS, real payments, email, SMS and event delivery remain disabled/unconfigured | Signed contracts, approved environments, credentials, owners and operational acceptance |
| Production operations | Architecture, SLO and runbook foundations exist | Deployed observability, capacity, incident, backup/restore and DR exercises |

## Issue 1 — Production Identity Foundation (carried forward)

Issue 1 implemented signed v2 step-up evidence bound to actor, issuer, MFA method, session, origin and exact action; bounded assertion/authentication age; single-use database digests; fail-closed production trust configuration; and negative replay/spoofing coverage. Its last complete local gate passed 21 test files and 106 tests.

**Decision:** `BLOCKED — EXTERNAL DEPENDENCY REQUIRED`. PR-004 still requires an approved production-equivalent IdP/edge environment, accountable IAM/Cloud owners and CISO acceptance for phishing-resistant MFA, recovery, revocation, session controls, direct-origin denial, trusted-header replacement, key custody and authorized attack tests. The Issue 2 sequence exception does not change this decision.

## Issue 2 — Production Identity Proofing

### A. Problem

The prior registration path rejected obvious VAT/TIN duplicates and recorded a placeholder ITAS verification state, but it did not own a durable proofing case, field-level candidate confidence, mismatch lifecycle or database distinction between synthetic evidence and authoritative verification. TIN uniqueness was not enforced directly on the canonical taxpayer table. A future integration or privileged database actor could therefore have lacked a complete, immutable proofing boundary.

### B. Root cause

The live ITAS/NamRA proofing protocol, authoritative attributes, signature/provenance model, freshness, identifier precedence, duplicate/merge policy and operational acceptance are external decisions. The repository had correctly avoided inventing them, but it also lacked the internal case/evidence model needed to integrate them safely later.

### C. Existing implementation before this increment

- Idempotent registration intake with VAT/TIN checks against canonical and active applications.
- Unique VAT number plus unique typed identifier records.
- Registration verification record held at `AWAITING_PROVIDER_CONTRACT`.
- ITAS adapter that fails closed when unavailable.
- Registration list and submission UI/API.
- One-taxpayer/one-organisation architecture with NamRA confirmation explicitly outstanding.

### D. Changes made in this increment

- Added one durable proofing case per registration with provider/environment, confidence, evidence digest, reason, requester/reviewer and timestamps.
- Added append-only reconciliation candidates and proofing events plus independent mismatch cases.
- Added deterministic explainable reconciliation for VAT, TIN, company registration number and normalized legal name.
- Added canonical TIN uniqueness alongside existing VAT uniqueness.
- Automatically creates a `PENDING_PROVIDER` / `CONTRACT_PENDING` proofing case and atomic outbox event with every accepted registration.
- Added fail-closed database guards for authority and synthetic states, immutable authority decisions/history/evidence, and mismatch no-self-resolution.
- Added a permission/licence-scoped proofing queue API and identity-proofing status, confidence and mismatch columns to the registration UI.
- Added forward-only migrations, runtime revision enforcement, OpenAPI, event catalogue, API catalogue and security-boundary evidence.

### E. Files/components changed

- `db/schema.ts`, `db/runtime.ts`
- `drizzle/0016_identity_proofing_core.sql`, `drizzle/0017_identity_proofing_enforcement.sql` and generated snapshots/journal
- `lib/domain/identity-proofing.ts`, `lib/data/identity-repository.ts`
- `app/api/v1/identity-proofing-cases/route.ts`, `app/api/v1/registration-applications/route.ts`
- `app/registrations/page.tsx`
- `tests/identity-domain.test.ts`, `tests/identity-proofing-migration.test.ts`, Phase 0/signup migration regressions
- `03-api/openapi.yaml`, `03-api/event-catalog.yaml`, `08-enterprise-architecture/api-catalog.yaml`
- `05-security/issue2-identity-proofing-boundary.md`
- delivery control, evidence backlog, architecture matrix and this assessment

### F. Database changes

Migration `0016_identity_proofing_core.sql` creates `identity_proofing_cases`, `identity_reconciliation_candidates`, `identity_mismatch_cases` and `identity_proofing_events`. Migration `0017_identity_proofing_enforcement.sql` adds canonical TIN uniqueness, evidence/state triggers, immutable-history triggers, independent mismatch resolution, legacy pending-case/event backfill and schema revision `issue2-identity-proofing-2026-08-23`.

Production startup does not mutate schema at request time; it requires the registered revision. The local upgrade is forward-only and backfills existing synthetic registration `reg-0001` as pending provider evidence, not verified.

### G. API and event contract changes

- Added `GET /api/v1/identity-proofing-cases` (`registrations:read`, central licensed `READ`) returning at most 100 scope-filtered minimized case summaries.
- Registration `POST` acceptance now returns `proofing_case_id` and `identity_proofing_status`.
- Added `na.vatmsa.identity.proofing-requested.v1` with a minimized atomic-outbox payload.
- OpenAPI now covers 53 v1 paths and 66 method/path operations.

No mutation endpoint claims authority verification. No raw provider response or evidence digest is returned.

### H. Security changes

Authority verification requires ITAS, `PRODUCTION_EQUIVALENT` or `PRODUCTION`, a matched taxpayer, evidence digest and independent reviewer/time. Synthetic matches require `SYNTHETIC_TEST`; they cannot be relabelled as authority evidence. Reviewed-by/requested-by separation, mismatch resolver separation, unique identifiers/cases/candidates and append-only proofing history are database-enforced. Non-national callers see only their requested cases.

### I. Automated verification

Focused verification passed 4 files and 17 tests. The complete local suite passed 22 files and 114 tests, including:

- exact, partial, name-only, conflict and no-candidate reconciliation outcomes;
- authority-state rejection for synthetic/unproven evidence;
- synthetic-state environment/evidence enforcement and no activation side effect;
- VAT/TIN and one-case-per-registration uniqueness;
- immutable candidate/event/authority history;
- mismatch requester self-resolution rejection and independent resolution acceptance;
- schema revision/snapshot verification and existing Phase 0/signup migration regressions;
- exact runtime/OpenAPI path and operation reconciliation.

The complete canonical release gate passed: ESLint and TypeScript completed without errors; 22 test files and 114 tests passed; the heuristic local secret scan passed; the high-threshold dependency audit reported zero critical/high and one moderate development-only advisory; the CycloneDX SBOM was regenerated with four production components; and the Vinext/Vite production build completed with all 53 v1 API paths, including `/api/v1/identity-proofing-cases`.

### J. End-to-end and operational verification

**NOT VERIFIED — EXTERNAL ENVIRONMENT REQUIRED.** No approved ITAS/NamRA sandbox or production-equivalent endpoint, protocol, credentials, disposable authority identities, lawful test record set, signature chain, response freshness contract or independent reviewer was supplied. Browser testing was not used as a substitute for those facts.

### K. Evidence

- `05-security/issue2-identity-proofing-boundary.md`
- migrations `0016` and `0017` plus snapshots and schema revision
- `tests/identity-domain.test.ts` and `tests/identity-proofing-migration.test.ts`
- OpenAPI/event/API catalogues
- `06-delivery/phase0-production-readiness-evidence-backlog.md`, PR-011 and PR-003

### L. Residual risk and exact external dependency

PR-011 requires Identity/Master Data ownership and NamRA/ITAS plus CISO/Privacy acceptance of identifier precedence, lawful/minimal provider attributes, signed provenance, freshness/expiry, confidence and mismatch policy, independent review, merge/deregistration, sandbox/production separation, conformance/rejection cases and monitoring. PR-003 must supply the accepted live integration contract and operational controls.

Until those packages exist, local confidence can detect candidate similarity but cannot prove legal identity, ownership, current VAT status or authority. Direct database administration remains an operational-control risk requiring production privileged-access and audit evidence.

### M. Acceptance decision

**BLOCKED — EXTERNAL DEPENDENCY REQUIRED**

The local increment is **PARTIALLY COMPLETE / LOCALLY VERIFIED**. It supplies the safe integration foundation and negative controls, but Issue 2 does not PASS or CLOSE without production-equivalent and operational authority evidence.

## Issue 3 — Taxpayer and Counterparty Trust

### A. Problem

The prior customer/supplier lifecycle validated formatting, rejected duplicate VAT/TIN identifiers and required an active relationship, but every newly created active party was immediately usable. Company registration was not captured, trust and tax-registration evidence was not durable or freshness-bound, and no model separated a synthetic local result from an authoritative NamRA/ITAS/BIPA decision.

### B. Root cause

The repository correctly did not invent a national-registry or tax-authority protocol, but it also lacked the internal trust state machine, cached evidence model and database transaction gates required to consume a future authoritative result safely.

### C. Existing implementation before this increment

- Tenant-scoped business-party create, update and non-destructive deactivate lifecycle.
- Optional VAT/TIN identifiers with application-level active duplicate rejection.
- Customer/supplier relationships, audit, outbox and immutable transaction snapshots.
- Active-relationship checks for quotations, projects and expenses.
- Disabled BIPA integration metadata and a fail-closed ITAS adapter boundary.

### D. Changes made in this increment

- Added company-registration capture plus database uniqueness for active VAT, TIN and company-registration identifiers.
- Added one durable trust profile per party with provider/environment, trust state, tax-registration state, per-identifier results, confidence, source/evidence, requester/reviewer, checked time and expiry.
- Added append-only verification snapshots and lifecycle events.
- Added deterministic reconciliation of VAT, TIN, company registration and normalized legal name with explainable matched/conflicting fields.
- Added local/explicit-staging synthetic verification with a 24-hour expiry and an explicit `SYNTHETIC_TEST` boundary; it never creates authority evidence.
- Added database and repository gates requiring current trust before new quotations, projects and supplier expenses; taxed expenses also require `ACTIVE` tax registration.
- Identity changes invalidate trust before the changed party can be used again.
- Added UI status, company-registration input, test-only synthetic action, API command, OpenAPI/events/catalogue, migration, security boundary and automated tests.

### E. Files/components changed

- `db/schema.ts`, `db/runtime.ts`
- `drizzle/0018_counterparty_trust.sql`, generated snapshot and journal
- `lib/domain/counterparty-trust.ts`, `lib/domain/business.ts`
- `lib/data/business-repository.ts`, `lib/api/business.ts`
- `app/api/v1/business-parties/[id]/synthetic-verification/route.ts`
- `app/commercial/parties/page.tsx`, `app/commercial/parties/PartyManager.tsx`
- `tests/counterparty-trust-migration.test.ts`, domain and migration regressions
- OpenAPI, event/API catalogues, environment template, security boundary and delivery evidence

### F. Database changes

Migration `0018_counterparty_trust.sql` adds the company-registration field, active identifier indexes, trust profiles, immutable verification snapshots/events, existing-party pending backfill and schema revision `issue3-counterparty-trust-2026-08-23`. Database triggers reject authority claims without production-equivalent evidence, synthetic claims outside their environment, trust/history deletion or mutation, identity changes without re-verification and new transactions involving an untrusted/expired party. Tax-bearing supplier expenses require active tax registration.

Production startup does not mutate schema at request time; it requires the registered revision. The local upgrade is forward-only and backfills every prior party as `PENDING_PROVIDER`, never verified.

### G. API and event contract changes

- Added `POST /api/v1/business-parties/{business_party_id}/synthetic-verification` (`parties:manage`, central licensed `WRITE`) for local or explicitly enabled staging tests only.
- Added `na.vatmsa.counterparty.verification-requested.v1` and `na.vatmsa.counterparty.trust-evaluated.v1` outbox contracts.
- OpenAPI now covers 54 v1 paths and 67 method/path operations.

The command accepts a synthetic authority-shaped record solely to exercise reconciliation and enforcement. It is unavailable in production and cannot emit `AUTHORITY_VERIFIED`.

### H. Security changes

Authority trust requires an approved provider, a production-equivalent/production environment, an evidence digest, current checked/expiry times and an independent reviewer. Synthetic trust requires `SYNTHETIC_TEST` evidence and is allowed only locally or in explicitly configured staging. Trust expiry, mismatches, invalid/unavailable states and identity changes fail closed. Snapshots and events are append-only, trust profiles cannot be deleted, and no raw provider response is exposed through the UI/API.

### I. Automated verification

Focused verification passed 5 files and 29 tests. The complete suite passed 23 files and 121 tests, including:

- exact synthetic matches, conflicts and tax-status separation;
- untrusted and expired transaction rejection;
- active tax-registration enforcement for taxed supplier expenses;
- VAT/TIN/company-registration uniqueness;
- authority/synthetic evidence separation;
- identity-change re-verification;
- immutable verification snapshots/events;
- legacy pending backfill, revision/snapshot verification and prior migration regressions;
- exact runtime/OpenAPI path and operation reconciliation.

The complete canonical release gate passed: ESLint and TypeScript completed without errors; 23 test files and 121 tests passed; the heuristic local secret scan passed; the high-threshold dependency audit reported zero critical/high and one moderate development-only advisory; the CycloneDX SBOM was regenerated with four production components; and the production build completed with all 54 v1 API paths and 67 method/path operations.

### J. End-to-end and operational verification

**NOT VERIFIED — EXTERNAL ENVIRONMENT REQUIRED.** No approved NamRA/ITAS/BIPA sandbox or production-equivalent endpoint, protocol, credentials, disposable authority records, lawful test data, signature chain, status/freshness contract or reconciliation owner was supplied. Browser testing was not used as a substitute for those facts.

### K. Evidence

- `05-security/issue3-counterparty-trust-boundary.md`
- migration `0018`, generated snapshot and schema revision
- `tests/counterparty-trust-migration.test.ts` and counterparty domain tests
- OpenAPI/event/API catalogues
- `06-delivery/phase0-production-readiness-evidence-backlog.md`, PR-012 and PR-003

### L. Residual risk and exact external dependency

PR-012 requires Business Master Data/Tax/Finance ownership and NamRA/ITAS/BIPA plus CISO/Privacy acceptance of authoritative identifiers and legal-name precedence, lawful/minimal attributes, signed provenance, status semantics, caching/freshness/reconciliation, non-VAT-party handling, merge/deregistration, sandbox/production separation, conformance, outage/rejection cases and monitoring. PR-003 must supply any applicable accepted live ITAS contract and operational controls.

Until those packages exist, local matching can prove the repository's fail-closed behavior but cannot prove legal existence, beneficial ownership, current VAT registration or authority standing. Synthetic trust is deliberately test-only.

### M. Acceptance decision

**BLOCKED — EXTERNAL DEPENDENCY REQUIRED**

The local increment is **PARTIALLY COMPLETE / LOCALLY VERIFIED**. It supplies the safe counterparty-trust foundation and negative controls, but Issue 3 does not PASS or CLOSE without production-equivalent and operational authority evidence.

## Issue 4 — Tax Authority Provisioning, Federation and Governance

### A. Problem

The prior architecture held synthetic Tax Authority, administrator, user, subscription and identity-provider records, but those records did not form a production-governance lifecycle. There was no durable authority-onboarding case, authority-unit hierarchy, protected duty catalogue, governed federation-registration state, independent onboarding decision evidence, authority-specific quarterly review or database gate preventing an unsupported production activation.

### B. Root cause

A real authority appointment, hierarchy, administrator mandate, OIDC/SAML issuer and audience, claims/assurance contract, conformance result and production activation approval are external governance facts. The repository correctly did not invent them, but it also needed an internal fail-closed control plane capable of preserving and enforcing those facts when they become available.

### C. Existing implementation before this increment

- Synthetic `tax_authorities`, administrators/users, tax subscriptions/features and taxpayer-authorization records.
- Identity-provider and immutable provider-subject-link models.
- NamRA Administration portal with basic provider and administrator counts.
- Maker/checker, no-self-approval, step-up and quarterly access-review architecture requirements.
- Disabled live ITAS adapter and explicit local/staging boundary.

### D. Changes made in this increment

- Added an authority-unit hierarchy with same-authority parent enforcement.
- Added protected authority role definitions and independently approved, effective-dated, scoped assignments.
- Added environment-labelled OIDC/SAML federation registrations with contract, configuration, conformance, local-ready, production-approved, suspension and revocation states.
- Added durable local-staging/production-target onboarding cases, immutable decisions and append-only governance events.
- Added assigned-authority-scoped reads and step-up/idempotent commands for case creation and independent local approval/rejection.
- Added authority-specific quarterly access reviews and protected decision-role checks.
- Added database production-activation gates requiring current federation, access review, evidence/readiness and five distinct protected approvals.
- Expanded the NamRA Administration portal with hierarchy, federation, role, review and onboarding-control projections/actions while retaining the no-finance/no-implicit-taxpayer-access boundary.
- Added OpenAPI, event/API catalogues, migration, security boundary, delivery evidence and automated tests.

### E. Files/components changed

- `db/schema.ts`, `db/runtime.ts`
- `drizzle/0019_authority_governance.sql`, generated snapshot and journal
- `lib/domain/authority-governance.ts`, `lib/data/authority-governance-repository.ts`
- `lib/auth.ts`, `lib/portals.ts`, `lib/api/control-plane.ts`
- `app/api/v1/tax-authority-onboarding-cases/route.ts`
- `app/api/v1/tax-authority-onboarding-cases/[id]/decisions/route.ts`
- `app/portal/namra-admin/page.tsx`, `app/portal/namra-admin/AuthorityGovernanceActions.tsx`
- `tests/authority-governance-domain.test.ts`, `tests/authority-governance-migration.test.ts` and migration/licence regressions
- OpenAPI, event/API catalogues, environment template, security boundary and delivery/architecture evidence

### F. Database changes

Migration `0019_authority_governance.sql` adds units, role definitions/assignments, federation connections, onboarding cases/decisions/events and quarterly reviews. It registers the new access permissions and central licence policies, backfills each existing authority with a head office, a contract-pending/unconfirmed ITAS federation, a production-target `BLOCKED_EXTERNAL` case and an open current-quarter review, and records revision `issue4-authority-governance-2026-08-23`.

Triggers reject cross-authority hierarchy/assignment links, self-approved assignments, maker/protected-approver duty conflicts, self decisions, missing/expired reviews, decision-role mismatch, mutable decisions/events, deletion of governance evidence, synthetic production federation claims and unsupported lifecycle transitions. Production activation requires all accepted external evidence and five distinct protected decision makers.

### G. API and event contract changes

- Added `GET /api/v1/tax-authority-onboarding-cases` (`authority-governance:read`) with assigned-authority-scoped governance projection.
- Added `POST /api/v1/tax-authority-onboarding-cases` (`authority-governance:manage`, step-up, idempotency) for local review or fail-closed production requests.
- Added `POST /api/v1/tax-authority-onboarding-cases/{onboarding_case_id}/decisions` for independent local-staging approval/rejection only.
- Added requested, decided and production-blocked atomic-outbox event contracts.
- OpenAPI now covers 56 v1 paths and 70 method/path operations.

No route can approve production federation or activate a production authority.

### H. Security changes

Governance reads and writes require central permission/licence enforcement and explicit active authority-administrator assignment. Privileged mutations require step-up authentication and idempotency. Requester/decider separation, protected-duty membership, current quarterly review and immutable evidence are database-enforced. Production federation requires a supported protocol, issuer/audience/assurance values, current evidence and claims/metadata digests, and an independent reviewer. Local/staging synthetic writes are explicitly environment-gated and always disabled in production.

Authority administration is not authentication and does not itself grant taxpayer, financial or statutory access. Technical authority governance remains isolated from Finance data and business commands.

### I. Automated verification

Focused Issue 4 verification passed 5 files and 18 tests. The complete suite passed 25 files and 129 tests, including:

- strict payload normalization and mass-assignment rejection;
- production request preservation in a blocked state;
- same-authority hierarchy and assignment enforcement;
- onboarding-maker/protected-approver SoD;
- self-decision rejection and independent scoped local approval;
- current quarterly-review and protected duty requirements;
- unsupported federation/activation rejection;
- immutable decisions/events/assignments and schema revision verification;
- licence-policy and prior migration regressions;
- exact runtime/OpenAPI path and operation reconciliation.

The complete canonical release gate passed: ESLint and TypeScript completed without errors; 25 test files and 129 tests passed; the heuristic local secret scan passed; the high-threshold dependency audit reported zero critical/high and one moderate development-only advisory; the CycloneDX SBOM was regenerated with four production components; and the Vinext/Vite production build completed with all 56 v1 API paths and 70 method/path operations.

### J. End-to-end and operational verification

**NOT VERIFIED — EXTERNAL ENVIRONMENT REQUIRED.** No approved participating-authority appointment, production IdP/federation contract, issuer/audience/metadata/claims package, assurance profile, disposable authority credentials, conformance endpoint, access-certification sign-off, five-party activation decision or rollback/incident exercise was supplied. Browser testing was not used as a substitute for those facts.

### K. Evidence

- `05-security/issue4-authority-governance-boundary.md`
- migration `0019`, generated snapshot and schema revision
- `tests/authority-governance-domain.test.ts` and `tests/authority-governance-migration.test.ts`
- OpenAPI/event/API catalogues
- `06-delivery/phase0-production-readiness-evidence-backlog.md`, PR-013, PR-004 and applicable PR-003

### L. Residual risk and exact external dependency

PR-013 requires Tax Authority Governance/IAM/Platform ownership and participating-authority, CISO, Privacy/Legal, Integration and Country Readiness approval of the real appointment/hierarchy, administrator role catalogue, federation metadata/claims/assurance/revocation/key rollover, independent protected approvals, quarterly review, activation/rollback, conformance, outage and incident evidence. PR-004 must accept the production authentication/origin boundary, and PR-003 must accept any applicable live ITAS integration.

Until those packages exist, the repository can prove that unsupported production provisioning fails closed but cannot establish a legal authority mandate, authenticate through a real federation or activate a production authority.

### M. Acceptance decision

**BLOCKED — EXTERNAL DEPENDENCY REQUIRED**

The local increment is **PARTIALLY COMPLETE / LOCALLY VERIFIED**. It supplies the safe authority-governance and activation-control foundation, but Issue 4 does not PASS or CLOSE without production-equivalent and operational authority evidence.

## Sequential remediation control board

| # | Issue | Status | Gate/evidence position |
| ---: | --- | --- | --- |
| 1 | Production identity foundation | **BLOCKED — EXTERNAL DEPENDENCY REQUIRED** | PR-004 open; Issue 2 sequence exception recorded |
| 2 | Production identity proofing | **BLOCKED — EXTERNAL DEPENDENCY REQUIRED** | Local foundation verified; PR-011/PR-003 open; Issue 3 sequence exception recorded |
| 3 | Taxpayer/counterparty trust | **BLOCKED — EXTERNAL DEPENDENCY REQUIRED** | Local foundation verified; PR-012 and applicable PR-003 evidence open; Issue 4 sequence exception recorded |
| 4 | Authority provisioning/federation | **BLOCKED — EXTERNAL DEPENDENCY REQUIRED** | Local governance/activation-control foundation verified; PR-013, PR-004 and applicable PR-003 open |
| 5 | Commercial subscription/licence operations | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 6 | Security bypass/runtime viability | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 7 | Account-creation lifecycle | **LOCKED** | Issue 4 has not passed; stale-fact reproduction still required |
| 8 | Live ITAS/authority adapter | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 9 | Email/SMS/invitations | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 10 | Statutory VAT rule engine | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 11 | Production-scale taxpayer reconciliation | **LOCKED** | Issue 4 has not passed; must reuse Issue 2/3 evidence models |
| 12 | Full reconciliation lifecycle | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 13 | VAT return formulas/authority submission | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 14 | Tax authority case management | **LOCKED** | Issue 4 has not passed; must reuse Issue 4 authority scope/governance |
| 15 | Financial accounting core | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 16 | Accounting posting/malware protection | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 17 | Inventory management | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 18 | Order-to-cash/fulfilment | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 19 | Process catalogue/serialization | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 20 | File security/retention | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 21 | Reporting/high-volume reads | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 22 | Device trust/offline operation | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 23 | External system contracts | **LOCKED** | Issue 4 has not passed; must consume the governed federation boundary |
| 24 | Payment-provider productionization | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 25 | Event platform | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 26 | Communication platform | **LOCKED** | Issue 4 has not passed; no further exception recorded |
| 27 | AI platform | **LOCKED** | Issue 4 has not passed; no further exception recorded |

## Readiness determination

| Readiness dimension | Determination |
| --- | --- |
| Functional local pilot | **YES — external/statutory production paths disabled** |
| Issue 1 local controls | **PARTIALLY COMPLETE / LOCALLY VERIFIED** |
| Issue 1 production acceptance | **BLOCKED** |
| Issue 2 local controls | **PARTIALLY COMPLETE / LOCALLY VERIFIED** |
| Issue 2 production acceptance | **BLOCKED** |
| Issue 3 local controls | **PARTIALLY COMPLETE / LOCALLY VERIFIED** |
| Issue 3 production acceptance | **BLOCKED** |
| Issue 4 local controls | **PARTIALLY COMPLETE / LOCALLY VERIFIED** |
| Issue 4 production acceptance | **BLOCKED** |
| Production readiness | **NO** |
| Enterprise readiness | **NO** |
| Government integration readiness | **NO** |
| Global deployment readiness | **NO** |

The next normal action is to obtain and test PR-013 authority appointment, federation, conformance and activation evidence together with PR-004 and applicable PR-003 packages. PR-012 remains open for Issue 3 and PR-011 remains open for Issue 2. Because Issue 4 is blocked, Issue 5 remains locked unless the programme authority explicitly records another sequence exception.
