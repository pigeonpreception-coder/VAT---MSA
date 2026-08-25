# VAT-MSA Module Development Playbook

Status: working guidance for engineering. Not an architecture deliverable, not a governance artifact, not a commissioning approval. It translates the current-state assessment (`09-assessments/`), the implementation matrix (`ARCHITECTURE_IMPLEMENTATION_MATRIX.md`) and the domain catalogue (`08-enterprise-architecture/11-domain-capability-catalog.md`) into a sequenced, module-by-module build plan.

Last synchronised against: `ARCHITECTURE_IMPLEMENTATION_MATRIX.md` (2026-08-23) and `09-assessments/CURRENT STATE ASSESSMENT.md`. Re-check those two files before starting any module — this playbook describes *how* to build, not the live status; the matrix is the source of truth for *what's already done*.

## How to use this document

1. Pick **one module**. Read its section fully before writing code.
2. Each module is broken into **phases** (A, B, C…). Work phases in order within the module — later phases assume earlier ones exist. Each phase carries a relative size tag: **S** (days), **M** (roughly a sprint), **L** (multiple sprints) — relative to each other, not a calendar promise.
3. Cross-check the **data model anchors** listed per module against `08-enterprise-architecture/11-domain-capability-catalog.md` for the authoritative command/query/event/API contract — this playbook summarises them, the catalogue is canonical.
4. Check the module's current maturity label in `ARCHITECTURE_IMPLEMENTATION_MATRIX.md` (`VERIFIED PILOT` / `CONTROLLED FOUNDATION` / `DISABLED PENDING AUTHORITY` / `ARCHITECTURE ONLY`) — that tells you whether a phase is hardening, building out, or stubbing.
5. Each task should land as its own reviewable PR with its own migration (follow the existing `0000`…`0019` numbered-migration pattern) and its own tests.
6. Before merging, run the full release gate (lint, typecheck, `pnpm security:ci`, build) — the same gate already evidenced in the implementation matrix. A phase isn't done because it compiles; it's done because it clears the gate and the module's Definition of Done.
7. Update `ARCHITECTURE_IMPLEMENTATION_MATRIX.md` when a module's maturity label actually changes. Do not let status drift from reality — that drift is exactly what the `09-assessments/` audits had to catch and correct.

## Non-negotiable ground rules (apply to every module)

- **Fail closed on unconfirmed authority.** Mirror the existing pattern used for tax-rule binding (rejects unless an approved rule exists) and production signing (throws without an approved signer). Any new capability touching money, tax liability, or identity must refuse to act, not assume, whenever it depends on something not yet confirmed (an ITAS contract, a legal rule, a production key).
- **Never quietly enable a disabled production capability.** ITAS federation, payment settlement, and HSM/KMS signing stay disabled until the corresponding entry in `06-delivery/phase0-production-readiness-evidence-backlog.md` (PR-001–013) is closed with a real signature — not a passing local test. Build against typed adapters and mocks so the *code* is ready the day the *authority* arrives.
- **One canonical taxpayer/organisation identity.** No module may create a parallel identity concept, a shadow buyer/seller account, or a bypass of the canonical taxpayer record. Buyer and Seller are transaction capabilities, never separate accounts (ADR-001/ADR-002).
- **Statutory records are immutable.** Corrections are adjustment/reversal/credit-note/debit-note records, never overwrites or deletes.
- **Every phase ships tests before merge.** Minimum bar per `06-delivery/testing-strategy.md`: unit, security/policy, and integration tests for the module's own API boundary. Domains classified `RESTRICTED_TAX`, `RESTRICTED_RISK` or `RESTRICTED_IDENTITY` in the catalogue additionally need authorization-negative tests (prove the wrong role/scope is rejected, not just that the right one succeeds).
- **RBAC/ABAC on every command and query.** Every command carries actor, organisation/taxpayer scope, purpose and correlation ID; every query carries policy-derived predicates and bounded pagination — repo-wide rule (`11-domain-capability-catalog.md`, "Security rules common to every domain"), not a per-module option.
- **Small increments.** One capability (one command, one query, or one event) per PR wherever practical.

---

## Module sequencing

```
1. Identity, Taxpayer & Organisation Foundation
2. Tax Invoicing & VAT Engine
3. Reconciliation & Returns
4. Audit & Risk
5. Commercial Operations            ← parallelisable with 3–4, low coupling
6. Documents, Communication & Notifications
7. Reporting & Analytics / BI
8. Platform Administration, Security & Workflow   ← cross-cutting, start early, finish late
9. Refunds & Payments               ← gated on external payment authority
10. External Integrations (ITAS / SaaS / Developer Platform)  ← gated on external contracts
```

Modules 8–10 have components you can and should start early (platform/security scaffolding, the ITAS adapter *interface*) even though production activation is externally gated. "Build the adapter, stub the authority" is the standing pattern for both.

---

## Module 1 — Identity, Taxpayer & Organisation Foundation

**Domains:** Identity, Taxpayer, Organisation, User Management, Buyer/Seller capability, Licensing & Entitlements, Organisation Administration, Organisation Authorization, Access Governance, Workspace & Navigation.
**Depends on:** nothing — this is the foundation. **Unlocks:** every other module.
**Maturity today:** `CONTROLLED FOUNDATION` (Identity — see Phase A decision below), `VERIFIED PILOT` (Taxpayer, Organisation, User Management, Buyer/Seller, Licensing & Entitlements, Organisation Administration, Organisation Authorization, Workspace & Navigation).

**Two decisions recorded 2026-08-25** (were open questions; both now settled and built against):
1. **Identity/Session model:** this system has no session it actually controls — no cookie, no JWT; the ChatGPT/OpenAI platform is the real authentication authority, and `getCurrentUser()` just trusts a fresh header per request. Building a literal `Session`/`CredentialMetadata` table would be synthetic and could make `RevokeSession` look like it does something it can't fully back. **Decision: "session" = the `identity_link`.** `RevokeSession` revokes the identity_link (a real, verifiable effect — `getCurrentUser()`'s join requires `status='ACTIVE'`, so a revoked link stops authenticating on its very next request). `Session`/`CredentialMetadata` tables stay deferred until a real credential-issuing mechanism exists (ITAS confirmed, or the standalone `VAT_MSA_STANDALONE` identity provider — seeded but `PENDING`/`REQUIRES_SECURITY_DECISION` — is activated).
2. **Self-service provisioning:** there is still no path for a brand-new person (no existing `app_users` row) to get their first account — `getCurrentUser()` deliberately throws rather than auto-provisioning. **Decision: explicit invite-and-claim**, generalizing the `inviteEmployee`/`activateEmployee` pattern beyond employees. **Built**: `POST /api/v1/organisations/:id/invitations` (org admin invites an email to a taxpayer-side role; returns a single-use claim token since this repo has no outbound email integration to deliver it) and `POST /api/v1/invitations/claim` (the invitee, authenticated only by the platform identity — no `app_users` row required to reach this one route — redeems the token; creates their `app_users` row, identity link and membership atomically, with an email-match check as defense in depth against a leaked token).

**Data model anchors:** `Provider`, `IdentityLink`, ~~`Session`, `CredentialMetadata`~~ (deferred, see decision above) · `Taxpayer`, `VATRegistration`, `TIN`, `IdentifierVersion` · `Organisation` aggregate, `Branch`, `Capability` · `User`, `Membership`, `Invitation`, `LifecycleCase` · `OrganisationCapability`, `TransactionRole` · `LicensePlan`, `Subscription`, `OrganisationLicense`, `Entitlement`, `LicenseUsage` · `Employee`, `Position`, `Department`, `BusinessUnit`, `OrganisationAdministrator` · `OrganisationRole`, `RolePermission`, `UserRole`, `UserCapability` · `AccessRequest`, `AccessReview`, `AccessCertification` · `NavigationWorkspace`, `NavigationFolder`, `NavigationItem`, `NavigationPermission`.

### Phase A — Canonical identity & session (M)
- [x] `Provider`, `IdentityLink` model (`Session`/`CredentialMetadata` deferred — see decision above).
- [x] `LinkIdentity` (`POST /api/v1/identity/links`, admin-only, fails closed against any provider not `ACTIVE`+`CONFIGURED`) / `RevokeSession` (`POST /api/v1/identity/links/:id/revocation`, = revoke the identity_link).
- [x] `ResolveIdentity` (`GET /api/v1/identity/links`) / `GetAssurance` (`GET /api/v1/identity/assurance`, combines identity-link assurance with request-level step-up freshness) queries.

### Phase B — Taxpayer & organisation core (M)
- [x] `SuspendTaxpayer` (`POST /api/v1/taxpayers/:id/suspension`, idempotent, immediately enforced via existing `vat_status='ACTIVE'` filters).
- [x] `IdentifierVersion`/effective-dating on `taxpayer_identifiers` (`version`, `effective_from`, `effective_to`, `previous_version_id` columns). `POST /api/v1/taxpayers/:id/identifiers/:identifierId/correction` supersedes the current VAT_NUMBER/TIN row rather than overwriting it, and keeps the denormalized `taxpayers.vat_number`/`tin` columns in sync so the correction actually takes effect on counterparty resolution. `GetOrganisation` now surfaces the full version history per identifier.
- [x] `VerifyIdentifiers` standalone (`POST /api/v1/taxpayers/:id/identifiers/verification`) — re-triggerable at any time after registration (previously verification only ever happened once, at intake). Calls the same `ItasIdentityPort` registration intake uses; today that always fails closed since ITAS is unconfigured, and the command honestly reports `AWAITING_PROVIDER_CONTRACT` rather than faking success.
- [x] `ActivateOrganisation`, `EnableCapability` (both via `POST /api/v1/registration-applications/:id/decision`, which also creates the head-office branch and owner membership) — `GetOrganisation` pre-existing, `ListBranches` + branch create/update now standalone (`GET`/`POST /api/v1/organisations/:id/branches`, `PATCH .../branches/:branchId`).

### Phase C — Users, roles & buyer/seller capability (M)
- [x] `ProvisionUser` (invite-and-claim, see decision above). `SuspendUser` (`POST /api/v1/users/:id/suspension`) and its reverse (`POST /api/v1/users/:id/reactivation`) are now standalone — a reversible lockout distinct from `terminateEmployee`'s one-way offboarding, which still also suspends `app_users` as one part of a larger irreversible action.
- [x] `AssignMembership` (`POST /api/v1/organisations/:id/memberships`, restricted to TAXPAYER_* roles — a deliberate privilege-escalation guard).
- [x] `GetUserAccess` (`GET /api/v1/me/access`) — the full effective RBAC+ABAC predicate set, computed live.
- [x] `CreateRole`/`AssignPermission` pre-existing; `GrantCapability` now standalone (`GET`/`POST /api/v1/organisations/capabilities`, upserts, requires the org to already hold the capability).
- [x] `ClassifyTransaction` (`GET /api/v1/counterparties/classification`) and `GetAvailablePortals` (`GET /api/v1/portals` — the underlying logic already existed, gating every portal page; this exposed it as its own endpoint).

### Phase D — Licensing, administration & access governance (L)
- [x] `Activate`/`Suspend`/`Renew` (`POST /api/v1/licensing/state`) and `Upgrade` (`POST /api/v1/licensing/upgrade`, a distinct plan-change operation, not a state transition); standalone `GetEntitlements`/`GetUsage` (`GET /api/v1/licensing/entitlements`, `GET /api/v1/licensing/usage`).
- [x] `AppointAdministrator` (`GET`/`POST /api/v1/organisations/administrators`) and employee `INVITED → ACTIVE` (`POST /api/v1/organisations/employees/:id/activation`, also converts the USER_SEATS licence reservation into usage).
- [x] `AccessRequest`/`AccessReview`/`AccessCertification` request/approve/certify already existed; standalone `RevokeAccess` (`POST /api/v1/access-grants/revocation` — revokes one active role or capability grant on demand) and `Offboard` (`POST /api/v1/organisations/offboarding` — revokes every active grant plus the membership itself, immediately) are now distinct commands, decoupled from `certifyQuarterlyAccess`'s review-gated bulk revoke and from `terminateEmployee`'s licence/employment-coupled offboarding.

### Phase E — Workspace projection & hardening (S)
- [x] `GetWorkspace` pre-existing; `GetChildren` (`GET /api/v1/navigation/children`, properly traverses nested `parent_folder_id`), `GetActions` (`GET /api/v1/navigation/actions`, explainable single-item access check) and `SavePreference` (`POST /api/v1/navigation/preferences`, upserts) now built. `NavigationPermission`/`navigation_permissions` remains unwired — flagged, not fixed.
- [x] Negative-test suite: `tests/routes/module-1-access-control.test.ts` — cross-organisation access, cross-role access, expired step-up and insufficient assurance level are now provably rejected through real route handlers, backed by a real SQLite engine (`tests/support/fake-d1.ts`, over Node's built-in `node:sqlite`) via test-only stand-ins for `cloudflare:workers` and `next/headers` (`tests/fakes/`) — both only resolve inside their real runtimes, which is why no route/repository code could be tested end-to-end before this. `vitest.config.ts` aliases the three specifiers to these fakes; pure-domain tests are unaffected since they never import anything that reaches them.

**Watch-outs:**
- `GetUserAccess` must never be a cached/stale snapshot — every module trusts it live.
- Buyer/Seller capability is a flag on the one `Organisation`, never a second account row.
- Licensing/Entitlement gates features; it must never be able to gate statutory correctness (a suspended licence can't be the reason a VAT calculation comes out wrong).

**Definition of done:** a user can be provisioned, assigned a role scoped to one organisation, and every subsequent API call in the system can resolve "who is this, what organisation, what capability, what assurance level" from this module alone — with negative tests proving cross-organisation and cross-role access is rejected.

---

## Module 2 — Tax Invoicing & VAT Engine

**Domains:** Tax Invoice, VAT (rules/calculation), Transaction (immutable ledger).
**Depends on:** Module 1 (resolved identity/organisation/branch). **Unlocks:** Module 3 (returns need certified invoices/transactions), Module 5 (quotation conversion), Module 9 (refund freezes invoices/transactions).
**Maturity today:** `VERIFIED PILOT` — the most mature module in the system; treat remaining work as hardening, not net-new build.

**Data model anchors:** `TaxInvoice` aggregate, `InvoiceItem`, `Sequence`, `Reservation`, `Certificate` · `VATRule`, `VATCalculation`, `EligibilityDecision` · `VATTransaction` aggregate, `Adjustment`, `Reversal`.

### Phase A — Rule engine (M)
- [ ] `VATRule` with effective-dating and an `ApproveRuleVersion` workflow; `VATCalculation`, `EligibilityDecision`.
- [ ] `EvaluateVAT`, `ExplainCalculation` — every VAT figure traceable to the exact rule version that produced it; fails closed with no output if no approved rule is bound.

### Phase B — Invoice lifecycle (L)
- [ ] `TaxInvoice` aggregate, `InvoiceItem`, `Sequence`, `Reservation`, `Certificate`.
- [ ] `Submit` / `Certify` / `Cancel`; `Get`/`VerifyInvoice` public verification route.
- [ ] `Sequence`/`Reservation` crash-safety: a crash mid-reservation must never produce a gap ambiguity or a duplicate number — this is the single riskiest concurrency spot in the whole system; test it explicitly.

### Phase C — Correction lineage (M)
- [ ] `CreateCredit` / `CreateDebit` against a certified invoice, linkage preserved end-to-end into `VerifyInvoice`'s public output.
- [ ] Original invoice record never mutated by a correction.

### Phase D — Immutable ledger (M)
- [ ] `VATTransaction` aggregate, `Adjustment`, `Reversal`; `PostTransaction`, `Reverse`, `Adjust` — always producing a new linked record.
- [ ] `GetTransactionTimeline` reads as a complete audit narrative for one transaction.

### Phase E — Hardening (M)
- [ ] Idempotency under **concurrent** retries (a concurrency test, not just a sequential unit test) — same request in flight twice must never double-post.
- [ ] Unidentified-buyer guarantee under test: an invoice to an unresolved buyer never produces an input-VAT posting. Treat any regression here as P0 — same severity class as the statutory rate-validation defect already fixed in Phase 0.

**Watch-outs:**
- `ExplainCalculation`'s fail-closed behaviour is load-bearing for the whole system's statutory defensibility — don't let a future refactor quietly add a fallback rate.
- Correction records and reversal records look similar; keep their semantics (supersede vs. cancel) distinct in the data model, not just in naming.

**Definition of done:** invoice → certified → VAT-calculated → transaction-posted → correction-capable, fully idempotent under concurrency, fully explainable to a specific rule version, with the unidentified-buyer guarantee under test.

---

## Module 3 — Reconciliation & Returns

**Domains:** Reconciliation, VAT Return, Compliance.
**Depends on:** Module 2 (invoices/transactions to match), Module 1 (officer identity for assignment). **Unlocks:** Module 9 (refund freezes a submitted return), Module 4 (exceptions can seed audit referrals).
**Maturity today:** `VERIFIED PILOT` (VAT Return), `CONTROLLED FOUNDATION` (Reconciliation, Compliance).

**Data model anchors:** `Match`, `Exception`, `Resolution` · `VATPeriod` aggregate, `VATReturn`, `ReturnLine`, `Submission` · `ComplianceProfile`, `Obligation`, `Deadline`, `Action`.

### Phase A — Matching engine (L)
- [ ] `Match`, `Exception`, `Resolution`; `RunMatch` as a scheduled/event-driven job (invoice ↔ ledger ↔ return); `VATTransactionMatched` / `ExceptionDetected` events.
- [ ] `Open` / `Assign` / `ResolveException` as a real work-queue with officer assignment and ageing.
- [ ] `RunMatch` idempotency: a retried match job must not create duplicate `Match`/`Exception` rows.

### Phase B — Work queue (M)
- [ ] `GetWorkQueue` with the filter/status/office/age predicates already illustrated in the exception-queue concept screen delivered to NamRA — build the query contract first, UI follows.

### Phase C — Return assembly (M)
- [ ] `VATPeriod` aggregate, `VATReturn`, `ReturnLine`, `Submission`; `Open`/`ClosePeriod`, `Generate`/`SubmitReturn`.
- [ ] `ClosePeriod` is a hard lock — nothing re-opens or re-generates a return afterward without an explicit, audited unlock action.
- [ ] Submission assembles and locks the return, then stops cleanly at the ITAS boundary with an explicit "blocked pending authority" status — never a silent success or an unhandled failure.

### Phase D — Compliance centre (M)
- [ ] `ComplianceProfile`, `Obligation`, `Deadline`, `Action`; `CreateObligation`, `MarkSatisfied`, `GetComplianceCentre` — feeds the compliance-calendar concept already shown in the taxpayer portal mock-up.

### Phase E — Consistency hardening (S)
- [ ] One canonical reconciliation-status computation, reused everywhere it's displayed. If you find it computed twice, that's a design defect — unify it before moving on.

**Watch-outs:**
- Match/Exception volume can get large fast — design `GetWorkQueue` pagination and indexing up front, don't retrofit it after the first performance complaint.
- Compliance deadlines must derive from the same rule-version source Module 2 uses — a second, independently-maintained deadline calendar will drift.

**Definition of done:** a return can be generated from reconciled, evidenced data, locked, and taken right up to (but not through) the ITAS submission boundary; every reconciliation exception is queued, assignable, and auditable to resolution.

---

## Module 4 — Audit & Risk

**Domains:** Audit, Risk.
**Depends on:** Module 1 (officer identity/roles); benefits from Module 3 (exceptions as referral source). **Unlocks:** Module 9 (refund reuses `Risk.EvaluateRisk`), Module 8 (`SoDViolationDetected` consumed platform-wide).
**Maturity today:** `CONTROLLED FOUNDATION`.

**Data model anchors:** `AuditCase` aggregate, `Request`, `Finding`, `Review` · `RiskIndicator`, `ModelVersion`, `RiskCase`.

### Phase A — Risk signals (M)
- [ ] `RiskIndicator`, `ModelVersion`, `RiskCase`; `EvaluateRisk` returning **explainable factors** with the model/rule version preserved — never a bare score. `GetRestrictedRisk` query.

### Phase B — Human authorisation gate (S)
- [ ] `AssignReview`, `ApproveAction` — only this explicit, human-authorised path may create an `AuditCase` from a risk signal. `EvaluateRisk` must never auto-create a case as a side effect.

### Phase C — Case lifecycle state machine (L)
- [ ] Full 10-state lifecycle (`Proposed → Authorized → Assigned → Planning → EvidenceCollection → Analysis → TaxpayerResponse → FindingsReview → Decision → Closed`) plus `Suspended`/`Reopened`/`AppealLinked`/`Cancelled` as controlled side-transitions — model this as a real state-machine construct, not scattered status-string checks.
- [ ] `Create`/`Assign`/`IssueFinding`/`CloseCase`, `CaseTimeline`; every transition persists actor, reason, time, and prior/new state.

### Phase D — Evidence sub-model (M)
- [ ] `Request`, `Finding`, `Review` extended with source, hash, classification, custody history, legal hold, immutable versioning — a first-class evidence sub-model, not file attachments bolted onto the case record.
- [ ] Append-only, versioned notes: corrections supersede, never overwrite a prior note.

### Phase E — Segregation of duties enforcement (M)
- [ ] Command-layer check blocking the referral-originator from also issuing the finding or closing the same case, with a logged exceptional-oversight override path.
- [ ] Automated test proving the same-actor path is actually blocked, not just documented as a rule.

**Watch-outs:**
- The lifecycle state machine is the highest-complexity piece in this module — under-modelling it now costs far more to retrofit later than building it as a real state machine up front.
- Evidence custody history must survive case reassignment and reopening without gaps.

**Definition of done:** a risk signal can be raised, reviewed, and (if authorised) turned into a fully lifecycle-tracked audit case with evidenced, custody-tracked findings — with an automated test proving the SoD rule blocks the same-actor path.

---

## Module 5 — Commercial Operations

**Domains:** Customer, Supplier, Quotation, Accounting, Inventory, Expense, Project.
**Depends on:** Module 1 (identity/org), Module 2 (`Quotation.Convert` produces a real Tax Invoice). **Unlocks:** nothing on the critical path — parallelisable.
**Maturity today:** `VERIFIED PILOT` across the board — the "extensible business layer," useful to taxpayers but not statutory-critical.

**Data model anchors:** `Customer`, `Contact`, `Address` · `Supplier`, `Contact`, `VerificationSnapshot` · `Quotation` aggregate, `QuotationItem`, `Approval` · `Account`, `Journal`, `JournalLine`, `AccountingPeriod` · `Product`, `Warehouse`, `StockItem`, `StockMovement` · `Expense` aggregate, `Category`, `Budget`, `Approval` · `Project` aggregate, `ProjectBudget`, `ProjectCost`.

### Phase A — Parties (M)
- [ ] `Customer`, `Contact`, `Address`; `Create`/`UpdateCustomer`, `SearchCustomers`.
- [ ] `Supplier`, `Contact`, `VerificationSnapshot`; `CreateSupplier`, `VerifySupplier` (against Module 1's taxpayer adapter, not a fresh identity concept), `SearchSuppliers`.

### Phase B — Quotation lifecycle (M)
- [ ] `Quotation` aggregate, `QuotationItem`, `Approval`; `Create`/`Edit`/`Send`/`Accept`/`Reject`/`Expire`/`Convert`, `SearchQuotes`.
- [ ] `Convert` calls Module 2's real `Submit`/`Certify` path — no shortcut around invoice certification.

### Phase C — Accounting (M)
- [ ] `Account`, `Journal`, `JournalLine`, `AccountingPeriod`; `Post`/`ReverseJournal`, `ClosePeriod`, `TrialBalance`/`Statements` — sourced from Module 2 transactions, never an independently recomputed VAT figure.

### Phase D — Inventory (M)
- [ ] `Product`, `Warehouse`, `StockItem`, `StockMovement`; `Receive`/`Issue`/`Adjust`/`TransferStock`, `GetAvailability`/`Valuation`.

### Phase E — Expense & Project (M)
- [ ] `Expense` aggregate, `Category`, `Budget`, `Approval`; `Submit`/`Approve`/`RejectExpense`, `ExpenseReport`.
- [ ] `Project` aggregate, `ProjectBudget`, `ProjectCost`; `CreateProject`, `ApproveBudget`, `PostCost`, `ProfitabilityReport`.

**Watch-outs:**
- Low statutory risk but high surface area — resist "just one more field" scope creep; keep each entity's command set to what the catalogue specifies.
- `Quotation.Convert` is the one place this module touches the statutory core — hold that boundary to Module 2's full rigor (idempotency, certification path), not this module's lighter CRUD standard.

**Definition of done:** a quotation flows to a certified invoice through the real Module 2 path; accounting periods close against reconciled transaction data, not a shadow ledger.

---

## Module 6 — Documents, Communication & Notifications

**Domains:** Document, Communication, Notification.
**Depends on:** Module 1 (identity for access). **Unlocks:** improves evidence quality for Modules 3/4/9, but nothing hard-blocks on it.
**Maturity today:** `VERIFIED PILOT` (Document — already "verified quarantine" per the matrix), `CONTROLLED FOUNDATION` (Communication, Notification).

**Data model anchors:** `Document` aggregate, `Version`, `Scan`, `RetentionHold` · `Conversation`, `Message`, `Notice`, `CaseReference` · `Notification`, `TemplateVersion`, `DeliveryAttempt`.

### Phase A — Document intake & scanning (M)
- [ ] `Document` aggregate, `Version`, `Scan`; `InitiateUpload`, `Accept`/`Quarantine` — one shared entry point for every module's uploads (audit evidence, correspondence attachments, exports). No second module gets its own unscanned upload path.

### Phase B — Retention & legal hold (S)
- [ ] `RetentionHold`; `ApplyHold` checked by every deletion/retention job repo-wide before it runs.
- [ ] `AuthorizedDownload` with access logging.

### Phase C — Case correspondence (M)
- [ ] `Conversation`, `Message`, `Notice`, `CaseReference`; `SendNotice`, `Respond`, `CloseConversation`, `GetInbox` — `CaseReference` generic enough to point at Audit cases, Refund claims, and Reconciliation exceptions alike, not hardcoded to one case type.

### Phase D — Notifications (M)
- [ ] `Notification`, `TemplateVersion`, `DeliveryAttempt`; `Queue`/`CancelNotification`, `UpdatePreference`, `GetNotifications` — multi-channel, always respecting stated preference, never a hardcoded channel.

**Watch-outs:**
- Audit this explicitly once Module 4/9 evidence features land: confirm they're calling Document's scan/quarantine path, not growing a parallel upload route.
- Retention/legal-hold checks are easy to forget in a new deletion job months later — consider making the hold check structurally unavoidable (a required parameter, not an optional call) rather than relying on developer discipline.

**Definition of done:** every document entering the system, from any module, goes through one scan/quarantine/hold path; every case correspondence is two-way, logged, and reachable from the case it belongs to.

---

## Module 7 — Reporting & Analytics / BI

**Domains:** Reporting, Analytics.
**Depends on:** Module 1 (access/step-up), Module 2/3 (source data) — benefits from every module feeding it. **Unlocks:** nothing blocks on this.
**Maturity today:** `VERIFIED PILOT` (Reporting), `ARCHITECTURE ONLY` (Analytics — nothing built yet).

**Data model anchors:** `ReportDefinition`, `Job`, `ExportManifest` · `DataProduct`, `Metric`, `Lineage`, `ModelRun`.

### Phase A — Report definitions & audience tiers (M)
- [ ] `ReportDefinition`; implement the audience/product/freshness matrix from `08-enterprise-architecture/22-audit-refund-reporting.md` — taxpayer, practitioner, NamRA operations, executive, auditor/legal, open-data tiers, each with its own guardrails.

### Phase B — Job execution & export controls (M)
- [ ] `Job`, `ExportManifest`; `Request`/`CancelReport`, `ApproveExport`, `GetReport` — asynchronous, size-limited, encrypted, expiring, watermarked, audited.
- [ ] Large/sensitive exports route through Module 1's step-up approval flow.

### Phase C — Response envelope standard (S)
- [ ] Shared as-of-time / source-freshness / filters / currency-basis / rule-version envelope wrapping every report response — build once, reuse everywhere; don't leave it as a per-report convention people forget.
- [ ] Reconciliation-to-source-control-totals as a hard publication gate: a failed reconciliation blocks official publication. Build this before the first dashboard ships, not after.

### Phase D — Analytics foundation (L, greenfield)
- [ ] `DataProduct`, `Metric`, `Lineage`, `ModelRun`; `PublishDataProduct` against a governed read replica only, never the live fiscal write store.
- [ ] `QueryApprovedMetrics` against certified metric definitions only; `AnalyticsRefreshed`/`AnomalyCandidate` events.

**Watch-outs:**
- Resist letting Analytics query operational tables "just this once" — every such shortcut becomes the next audit's finding.
- Row/column security belongs at the data layer, not just the UI — don't rely on the dashboard to hide what the query already returned.

**Definition of done:** every report/dashboard reconciles to source control totals and states its own freshness; Analytics has at least one certified metric published end-to-end through the governed path.

---

## Module 8 — Platform Administration, Security & Workflow

**Domains:** Administration, Security, Workflow.
**Depends on:** Module 1 (roles) — cross-cutting for all modules. **Unlocks:** stronger SoD enforcement in Modules 4/9 once `SoDViolationDetected` is wired.
**Maturity today:** `CONTROLLED FOUNDATION` / architecture-defined. Production-grade pieces (SIEM/SOC integration, independent pen-testing) are gated by the commissioning workstream, not engineering effort alone — don't block this module's other phases waiting on them.

**Data model anchors:** `FeatureFlag`, `PlatformConfig`, `AccessPolicy`, `ChangeRequest` · `SecurityEvent`, `Detection`, `Incident`, `PlaybookAction` · `Workflow`, `WorkflowVersion`, `WorkflowInstance`, `WorkflowApproval`.

### Phase A — Administration core (M)
- [ ] `FeatureFlag`, `PlatformConfig`, `AccessPolicy`, `ChangeRequest`; `ChangeFeature`/`Policy`, `ProvisionStaff`, `GetHealth`/`Config`.
- [ ] Keep NamRA tax-access administration and platform technical administration as genuinely separate privilege sets — Super Administration operates technology, NamRA Administration governs tax access; technical privilege must never imply taxpayer-data privilege.

### Phase B — Security telemetry & incident model (M)
- [ ] `SecurityEvent`, `Detection`, `Incident`, `PlaybookAction`; `CreateIncident`, `Contain`/`Revoke`/`Close`, `GetSOCQueue` — build the data model and workflow now even without a production SOC; SIEM integration later becomes a connector, not a rewrite.

### Phase C — Workflow engine (L)
- [ ] `Workflow`, `WorkflowVersion`, `WorkflowInstance`, `WorkflowApproval`; `Create`/`Test`/`Publish`/`Assign`/`Decide`/`Delegate` — immutable published versions, task assignment, approval, escalation, delegation.
- [ ] `SoDViolationDetected` as a first-class, subscribable event — Audit's SoD rule (Module 4) and Refund's maker-checker (Module 9) should both be expressible as Workflow instances, not two hand-rolled implementations.

### Phase D — Audit trail integration (S)
- [ ] Wire the existing hash-chained audit trail as the mandatory sink for every command in every module.
- [ ] Chain-verification job with alerting on breaks; a restricted read path for Internal Audit.

**Watch-outs:**
- Don't let Workflow become bespoke per consuming module — that duplication is exactly the kind of drift the rest of this playbook is trying to avoid.
- Administration's `ChangeFeature`/`Policy` commands are themselves privileged actions — they must flow through this module's own audit-trail integration (Phase D), not be exempt from it.

**Definition of done:** every privileged action anywhere in the system produces a chained, verifiable audit record; a segregation-of-duties violation anywhere (not just in Audit/Refund) raises a detectable event.

---

## Module 9 — Refunds & Payments

**Domains:** Payment, plus the refund workflow described in `08-enterprise-architecture/22-audit-refund-reporting.md` (not a separate catalogue domain — model as a state machine owned jointly by VAT Return and Payment).
**Depends on:** Module 2 (frozen invoices/transactions), Module 3 (frozen return), Module 4 (`Risk.EvaluateRisk` reuse), Module 8 (maker-checker via Workflow). **Unlocks:** nothing further — mostly terminal.
**Maturity today:** Payment is explicitly `DISABLED PENDING AUTHORITY`. **Do not build toward a live payment instruction.** Build the entire workflow up to, and stopping cleanly at, the payment authority boundary.

**Data model anchors:** `Payment`, `Allocation`, `SettlementReference` · refund-claim aggregate (state machine spanning `VATReturn` + `Payment`).

### Phase A — Claim state machine (L)
- [ ] Full state machine: `Received → Validation → RiskReview → EvidenceRequested → OfficerReview → Approved/Rejected → Offset → PaymentPending → Paid/Failed/Reversed → Disputed → Closed`.
- [ ] Every state transition under test — this is the workflow equivalent of Module 4's case lifecycle; give it the same rigor.

### Phase B — Freeze & integrity checks (M)
- [ ] Snapshot the submitted return version, invoices, reconciliation state and rule version at claim time — immutable from that point, even if the underlying data later changes.
- [ ] Eligibility, duplicate, debt-offset, identity, bank/account-ownership, sanctions, anomaly checks — each its own testable policy with an explainable pass/fail, never a black-box composite score.

### Phase C — Risk routing & maker-checker (M)
- [ ] Reuse Module 4's `Risk.EvaluateRisk` — do not fork a second risk engine here.
- [ ] Route by risk tier to configured review lanes.
- [ ] Two-distinct-actor maker-checker enforcement for any material outcome (reuse Module 8 Workflow if it's ready).

### Phase D — Payment connector interface (M — stops at the boundary)
- [ ] `Payment`, `Allocation`, `SettlementReference`; `Record`/`AllocatePayment`, `GetOutstanding` against a typed interface only, backed by a sandbox/mock implementation.
- [ ] An explicit environment guard that refuses to run outside sandbox configuration — not just documentation saying "don't." A mock that's too permissive can quietly become a de facto production path.

**Watch-outs:**
- This is the single most dangerous module for an accidental "just make it work end-to-end" shortcut — treat Phase D's environment guard as mandatory, not optional polish.
- Freeze-on-claim must be genuinely immutable; verify with a test that mutates the source return/invoice after freezing and asserts the claim snapshot is unaffected.

**Definition of done:** a refund claim can be validated, risk-scored, reviewed, and maker-checker approved, arriving cleanly at `PaymentPending` against a mock connector — with a hard, tested guarantee that no code path reaches a real payment instruction while Payment remains `DISABLED PENDING AUTHORITY`.

---

## Module 10 — External Integrations (ITAS / SaaS / Developer Platform)

**Domains:** Integration, SaaS, Developer Platform.
**Depends on:** Module 1 (identity to federate against). **Unlocks:** production activation of Modules 1/3/9's externally-gated paths once real credentials land.
**Maturity today:** weakest module in the system — "ports and placeholders" per the assessment. Gated on external contracts (ITAS federation, SaaS provider agreements) outside engineering's control.

**Data model anchors:** `Integration` aggregate, `Connector`, `SyncRecord`, `Webhook` · `SaaSProvider`, `Application`, `EnvironmentApproval` · `DeveloperAccount`, `APIClient`, `CredentialRef`, `TestRun`.

### Phase A — Generic connector model (M)
- [ ] `Integration` aggregate, `Connector`, `SyncRecord`, `Webhook`; `Register`/`Approve`/`SuspendIntegration`, `StartSync`, `GetHealth` — built provider-agnostic, not ITAS-specific, so SaaS/ERP providers reuse the same shape.

### Phase B — ITAS anti-corruption layer (L — highest leverage in this playbook)
- [ ] Full typed interface for identity resolution, taxpayer verification, and return submission.
- [ ] Mock implementation behind a feature flag, validated against the documented event/API shapes (`IdentityLinked`, `TaxpayerVerified`, etc.) — not a convenience shape that needs rework once the real contract lands.
- [ ] Every ITAS-dependent call path in Modules 1/3 returns a typed "blocked pending authority" result — never a silent success, a timeout, or an unhandled error.

### Phase C — SaaS provider onboarding (M)
- [ ] `SaaSProvider`, `Application`, `EnvironmentApproval`; `RegisterProvider`, `SubmitConformance`, `GetUsage` — conformance test harness built ahead of any specific provider signing.

### Phase D — Developer platform (M)
- [ ] `DeveloperAccount`, `APIClient`, `CredentialRef`, `TestRun`; `CreateClient`, `Rotate`/`RevokeCredential`, `RunConformance` — scoped sandbox credentials independent of the ITAS timeline.

**Watch-outs:**
- Phase B is the single most valuable piece of speculative work in the entire playbook — it's the one place where building ahead of an external dependency directly shortens the critical path once that dependency clears. Prioritise it even if the rest of this module waits.
- Don't let the mock ITAS adapter drift from the documented contract shape over time — re-validate it against the catalogue whenever the catalogue itself changes.

**Definition of done:** every module that needs ITAS already talks to the real interface, pointed at a mock; swapping the mock for a live credential set requires no code change in the calling modules, only configuration.

---

## The commissioning workstream (runs in parallel, not engineering)

Modules 1–10 make the system *buildable*. They do not make it *commissionable*. That is gated by a separate, non-engineering workstream someone needs to own explicitly:

- **Approval Gate sign-off** (`08-enterprise-architecture/29-architecture-approval-gate.md`): 14 named authorities, currently all blank. Assign an owner to actively pursue each signature — this doesn't happen passively.
- **Production-readiness evidence backlog** (`06-delivery/phase0-production-readiness-evidence-backlog.md`, PR-001–013): 0 of 13 closed. Each already has a named accountable owner and required approver column — use it.
- **Sequential remediation programme** (`09-assessments/CURRENT STATE ASSESSMENT.md`): Issues 1–4 are `BLOCKED — external dependency`; Issues 5–27 are `LOCKED`. Engineering work on Modules 1–10 will not unblock these — they need NamRA/ITAS/legal/security-authority engagement.
- **Keep the two workstreams visibly separate.** A module reaching "Definition of Done" here means it's engineered and tested — it does not mean it's authorised for production. Never let a passing test suite get represented as commissioning progress; that conflation is precisely what the `09-assessments/` audits exist to catch.

---

## Suggested cadence

- Treat each **phase** (not the whole module) as your sprint-sized unit of work — a module's total size is roughly the sum of its phase sizes (S/M/L), so a module with four M phases is a bigger commitment than one with two S phases and an M.
- Keep exactly one module "primary focus" at a time, but let Module 8 (Platform/Security/Workflow) and the commissioning workstream run continuously underneath whichever module is primary — they're cross-cutting, not sequential.
- After each phase, re-check `ARCHITECTURE_IMPLEMENTATION_MATRIX.md` and update it if the module's real status changed. If a module's status doesn't match this playbook's Definition of Done, fix the gap before declaring it complete and moving to the next module — this repo's own history shows what happens when status drifts ahead of evidence.
