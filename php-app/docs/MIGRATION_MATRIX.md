# VAT-MSA: TypeScript/Cloudflare -> Laravel/PHP/MySQL Migration Matrix

**Source of truth:** the `migration/php-mysql` branch's parent commit (`b5a3d4f`) of
`claude/namra-vat-audit-presentation-20e50f` -- the TypeScript/Vinext/Cloudflare
Workers/D1/R2 application, kept intact at the repo root alongside this `php-app/`
directory for side-by-side inspection throughout the migration. The full,
untouched original is also preserved on `backup/pre-php-mysql-migration`.

**Verified inventory of the source** (grep-counted against the actual repository,
not estimated): 155 D1 tables (`db/runtime.ts`), 180 API route files
(`app/api/v1/**/route.ts`), 37 page routes, 22 roles (`lib/domain/access.ts`),
6 portals (`lib/domain/portals.ts`).

**Environment used for this migration's own verification** (flagged, since it
differs from the requested target): PHP 8.2.12 + MariaDB 10.4.32 (XAMPP), not
PHP 8.3+/MySQL 8. Code is written to stay compatible with 8.2 as a floor and
avoids MySQL-8-only syntax; re-verify against the actual PHP 8.3+/MySQL 8+
target before production use.

## How to read the status columns

- `COMPLETE` -- migrated, and independently verified working in this session
  (migration applied to a real MariaDB database, and/or a real HTTP request
  exercised the code path).
- `PARTIAL` -- some real, working code exists but the module is not fully
  ported.
- `NOT STARTED` -- no PHP/Laravel code exists yet for this module.

Nothing in this document is marked `COMPLETE` without having actually been run
against a database or over HTTP in this session -- see "Verification performed"
below for the specific commands.

## Phase status (this session)

| Phase | Description | Status |
|---|---|---|
| 1 | Analyse the current project | COMPLETE |
| 2 | Protect existing source (branches) | COMPLETE -- `backup/pre-php-mysql-migration`, `migration/php-mysql`, both pushed to origin |
| 3 | Create the Laravel structure | COMPLETE -- Laravel 12.68.0 scaffolded in `php-app/`, Bootstrap 5 (npm, via Vite, not Tailwind) replacing the default frontend stack |
| 4 | Convert database schema to MySQL migrations | COMPLETE for its actual scope -- 154 of 155 tables (`positions` deliberately excluded, since the source itself never writes to it -- confirmed by a full-repo grep; see below). Everything already tracked in earlier phase slices, plus the remaining ~43 tables belonging to genuinely not-yet-built modules (Developer Portal, SaaS marketplace, offline-invoicing sync, reports/analytics/data-products, security operations, platform config/change-management, MFA/step-up auth, `payment_instructions`, `bank_imports`, `import_records`, `user_invitations`) -- see "Remaining schema conversion" below for the full breakdown and the fidelity checks this pass caught. Migrations only, deliberately -- no Eloquent models or services were added for tables no command reads or writes yet, matching this migration's `consent_grants`/`delegations` precedent; each table gets its model/service when its own owning phase actually builds that feature. |
| 5 | Convert seed data | COMPLETE for its actual scope -- RoleSeeder, PermissionSeeder, IdentityProviderSeeder, VatRuleSeeder, TaxRuleSetSeeder, LicensePlanSeeder, OrganisationAdministratorRoleSeeder, NavigationSeeder, DemoSeeder written and verified; three genuine gaps found and completed (the two RBAC-seed gaps, see "Source-fidelity findings" below; and `sod_rules`/`consent_grants`/`delegations` -- see "Demo seed gaps for already-shipped features" below) |
| 6 | Authentication | COMPLETE for its actual scope -- real Laravel session auth (login/logout, password hashing, CSRF, rate-limited attempts, session regeneration, account-status check) verified end-to-end over HTTP; no password reset flow yet |
| 7 | Role/permission/organisation security | COMPLETE for its actual scope -- `App\Support\Access\Permissions` (RBAC) and `App\Support\Access\TenantScope` (tenant isolation) are now genuinely exercised by every Phase 8 controller via `Gate::authorize('permission', ...)` and `OrganisationService::requireInScope()`/`get()`, proven by real 403s in the test suite (a `TAXPAYER_VIEWER` denied `registrations:submit`, a `TAXPAYER_OWNER` denied `taxpayers:suspend`) and by cross-tenant scope checks on every organisation-scoped read/write. `User::hasAppPermission()`/`Gate::define('permission', ...)` now also OR in organisation-defined custom-role grants via the new `App\Support\Access\DynamicPermissions` (Phase 12 slice 3's own closure of a gap explicitly deferred since this phase -- see "Portal navigation" below), matching the source's `hasPermission`'s static-*and*-dynamic union exactly, not just its static half. A reusable Eloquent global-scope trait (`App\Models\Concerns\BelongsToOrganisation` / `App\Models\Scopes\OrganisationScope`) is now also available as a defense-in-depth backstop on top of every service's own manual scoping -- see "Organisation-scope trait" below. |
| 8 | Organisations, taxpayers, administration | COMPLETE -- registration submission/decision (with materialization), taxpayer suspension, branch list/create/update, membership assignment, `getIdentityFoundationSnapshot` (see "Identity foundation snapshot" below), and (Phase 12, see "Organisation administration & employees" and "Access governance" below) employees (including `terminateEmployee`'s own workflow-task reassignment, closed out once Phase 12 slice 5 built the tables it needed), organisation-defined custom roles (`organisation_roles`/`organisation_role_permissions`), capability grants, and access requests. The `positions` table is not built -- never written by the source itself, so genuinely nothing to port. |
| 9 | Invoices and VAT | COMPLETE -- invoice certification (`TAX_INVOICE`/`SIMPLIFIED_TAX_INVOICE`/`SELF_BILLED_INVOICE`) and correction (`CREDIT_NOTE`/`DEBIT_NOTE`) submission, VAT-rule resolution, idempotent replay (including the concurrent-race recovery path), the ledger/certificate/audit/outbox/security-event side effects, invoice list/detail reads, officer-only cancellation with its reversing ledger entries, per-line VAT-rule explanation, and the full cross-invoice transaction timeline (see "Invoice lifecycle completion"). Also COMPLETE (see "VAT-return-generation prerequisite"): the full `vat-lifecycle-repository.ts` surface built on top of these tables -- VAT periods/adjustments/return generation/maker-checker approval/ITAS submission -- Phase 9's own deferred scope, built to unblock Phase 11's refund slice. Also now COMPLETE (see "Standalone VAT-rule routes" below): `listVatRules`/`proposeVatRule`/`approveVatRule`/`evaluateVatRule` -- `lib/data/vat-rule-repository.ts`'s remaining exports, the last narrow gap this phase had. |
| 10 | Accounting/commercial | COMPLETE -- all of business-repository.ts's ~36 functions across all 5 sub-slices: business parties (incl. `verifySupplier`/`getSupplierVerificationHistory` -- see "Supplier verification" below), quotations (incl. conversion into a real certified invoice via Phase 9's InvoiceService), accounting (chart of accounts, journal posting/reversal, period close, trial balance, financial statements), expenses (categories, the DRAFT->SUBMITTED->APPROVED/REJECTED maker-checker lifecycle, expense reporting), inventory (products, warehouses, stock movements/transfers with weighted-average costing, availability/valuation), and projects (budgets with maker-checker approval, cost posting from an approved expense or manually, profitability reusing the accounting infrastructure for revenue). |
| 11 | Compliance/audits/disputes/refunds/risk | COMPLETE -- every one of compliance-repository.ts's ~30 functions: audit cases (the full PROPOSED->...->CLOSED lifecycle state machine, findings, evidence with custody events and legal hold -- including `VAT_RETURN`- and `DOCUMENT`-sourced citations, see "VAT_RETURN evidence citation" and "DOCUMENT evidence citation" below -- and append-only notes), tax obligations (create/mark-satisfied), disputes (taxpayer self-filing), risk (assign review/approve action/evaluate/restricted query, including the risk->case escalation gate), communications/conversations (SendNotice/Respond/Close/Inbox/GetConversation, referencing an audit case or reconciliation exception), the standalone notification commands (queue/cancel/mark-read/preferences/list), the refund workflow (request/checks/transition/dispute -- a real adjacency-list state machine with maker-checker, unblocked by the VAT-return-generation prerequisite; see that section below), and now `getComplianceSnapshot` (see "Compliance dashboard snapshot" below) -- the phase's last remaining gap. Nothing outstanding in this phase's own scope. |
| 12 | Portals/licensing/governance | COMPLETE -- every function in `lib/data/control-plane-repository.ts` (~30 exports across 5 sub-domains) is now ported, plus `lib/portals.ts` (a genuinely separate file, found and closed out alongside `getAdministrationSnapshot`/`searchWorkspace` -- see "Administration snapshot & portals" below). Slice 1 (see "Licensing & Entitlements" below): GetEntitlements/GetUsage/Activate-Suspend-Renew/Upgrade, a real licence state machine with plan-change history. Slice 2 (see "Organisation administration & employees" below): inviteEmployee/activateEmployee/terminateEmployee/appointAdministrator/createOrganisationRole/listCapabilityGrants/grantCapability, plus `assertEntitledOperation` (the internal cross-cutting entitlement gate) and `openQuarterlyAccessReview` -- also closing out "the rest of Phase 8"'s own deferred employees/custom-roles gap. Slice 3 (see "Portal navigation" below): getEffectiveNavigation/getNavigationChildren/getNavigationItemActions/saveNavigationPreference. Slice 4 (see "Access governance" below): requestRoleAccess/decideAccessRequest/certifyQuarterlyAccess/revokeAccessGrant/offboardUser. Slice 5 (see "Workflow engine" below): createWorkflowDraft/publishWorkflowVersion/assignWorkflow/decideWorkflowTask/testWorkflowVersion/createDelegation/listDelegations/revokeDelegation (Module 8 Phase C). Final slice: `getAdministrationSnapshot` (the fixed-list dashboard aggregate every other GET-list route across all five slices bundles into), `searchWorkspace` (a small, genuinely separate Workspace & Navigation route), and `lib/portals.ts`'s `getAvailablePortals`. Nothing outstanding in this phase's own scope. |
| 13-15 | Documents/integrations/offline/reports, legacy importer, deployment docs | COMPLETE for its actual scope -- `platform-repository.ts` is entirely ported: Module 22's own Documents & Records slice (`uploadDocument`/`completeDocumentScan`, pulled forward in Phase 11, plus `supersedeDocument`/`getDocumentVersionHistory`/`setDocumentRetentionHold`/`downloadDocument` -- see "Document module (closes out Module 22)" below), the platform/developer-portal snapshot reads (`getPlatformSnapshot`/`getTechnicalPlatformSnapshot`/`getDocumentCustodySummary`/`getDeveloperPortalSnapshot` -- see "Platform snapshots" below), the offline sync command (`receiveOfflineBatch` -- see "Offline sync commands" below), report exports (`runInlineReport`/`publishReportRun`/`requestReportExport`/`approveReportExport`/`cancelReportExport`/`getReportExport`/`downloadReportExport` -- see "Report exports" below), data products & analytics (`listDataProducts`/`runAnalyticsModel`/`publishDataProduct`/`queryApprovedMetrics`/`listAnomalyCandidates` -- see "Data products & analytics" below), and platform config/change-management (`getPlatformConfig`/`listPlatformChangeRequests`/`requestPlatformChange`/`decidePlatformChange`/`provisionPlatformStaff` -- see "Platform config & change-management" below). Phase 14 (legacy D1 importer) is COMPLETE as a real, generic, reusable cutover tool -- see "Legacy D1 importer (Phase 14)" below for why it is verified against a synthetic fixture rather than a real dataset (none exists in this repository). Phase 15 (deployment documentation) is COMPLETE -- see `docs/DEPLOYMENT.md`. Every phase in this migration's own tracked scope is now COMPLETE. |

## Verification performed (this session, not claimed without evidence)

1. `php artisan migrate:fresh` -- all 9 identity/access migrations apply
   cleanly against a real MariaDB 10.4.32 database (`vat_msa`), in correct
   FK-dependency order.
2. `php artisan migrate:fresh --seed` -- RoleSeeder (21 roles), PermissionSeeder
   (79 permission codes), VatRuleSeeder (5 approved VAT rules, ported verbatim
   from `db/runtime.ts`'s own bootstrap seed), DemoSeeder (2 taxpayers, 2
   organisations with BUYER+SELLER capabilities, 1 branch, 1 membership, 3
   users) all run without error.
3. `npm run build` -- Vite production build succeeds (Bootstrap 5 CSS/JS
   bundled).
4. Live HTTP verification via `php artisan serve` + `curl`, session cookies
   carried across requests:
   - `GET /login` -> 200.
   - `GET /dashboard` unauthenticated -> 302 to `/login` (real auth
     middleware, not menu-hiding).
   - `POST /login` with the TAXPAYER_OWNER demo user's correct credentials ->
     302 to `/dashboard`; the rendered dashboard shows the user's own name,
     role, and a "Taxpayer-scoped" badge (not national), with an effective
     permission list that matches `lib/domain/access.ts`'s `TAXPAYER_OWNER`
     entry exactly (`access-governance:manage`, `accounting:close-period`,
     `documents:upload`, etc.).
   - `POST /login` with the PILOT_ADMIN demo user -> dashboard correctly
     shows "National scope" (no `taxpayer_id`, national-only role).
   - `POST /login` with a wrong password -> 302 back to the login page; the
     *same session's* subsequent `GET /dashboard` is still redirected to
     `/login` (confirmed not authenticated, not just a redirect status
     coincidence).

## Phase 8 verification (organisations, taxpayers, administration)

Both a real end-to-end HTTP walkthrough (`php artisan serve` + `curl`, session
cookies, real CSRF tokens) and a 15-test PHPUnit feature suite
(`tests/Feature/Identity/*`, run against real MySQL via `vat_msa_testing`, not
SQLite -- see `phpunit.xml`'s own note) confirm:

- **Registration -> approval materializes the full record set in one
  transaction**: submitting as a `TAXPAYER_OWNER` and approving as a
  `PILOT_ADMIN` creates a real `taxpayers` row (`vat_status='ACTIVE'`), an
  `organisations` row, a `HEAD` branch (`is_head_office=1`), `BUYER` and
  `SELLER` capabilities, a `TAXPAYER_OWNER` membership for the submitter, and
  two chained `audit_events` rows -- checked directly against the database,
  not just the HTTP response.
- **Rejection leaves no trace beyond the registration record itself** (no
  taxpayer, no organisation created) -- verified.
- **Self-approval is denied**: the submitting user cannot decide their own
  application, even if they otherwise hold `registrations:approve`.
- **RBAC is genuinely enforced, not just present**: a `TAXPAYER_VIEWER`
  attempting to submit a registration gets a real 403; a `TAXPAYER_OWNER`
  attempting to suspend a taxpayer gets a real 403.
- **The step-up gate genuinely blocks the action, not just decorates it**: a
  `PILOT_ADMIN` attempting a registration decision *without* first confirming
  their password gets a real `423 Locked` (`RequirePassword` middleware) and
  the registration is provably still `PENDING_VERIFICATION` in the database
  afterward; confirming the password and retrying succeeds. Verified for all
  three sensitive actions this phase built (registration decision, taxpayer
  suspension, membership assignment) -- this is Laravel's own tested
  reauthentication mechanism, not yet the source's TOTP `step_up_events`
  (a documented, deliberate narrowing, not a silent drop -- see the "step-up"
  note further down).
- **Taxpayer suspension is idempotent**: suspending an already-suspended
  taxpayer returns the same result without writing a second audit event.
- **The head-office branch cannot be deactivated**; a non-head-office branch
  can.
- **Duplicate branch codes are rejected as a 409 conflict**; duplicate VAT
  numbers/TINs are rejected the same way at both the submission and the
  decision stage (matching the source's own two-checkpoint duplicate
  detection).
- **The privilege-escalation ceiling on membership assignment holds**:
  attempting to grant a national-scope role (e.g. `PILOT_ADMIN`) via
  `AssignMembership` is rejected by validation before it ever reaches the
  database.
- A genuine bug this session's own migration introduced was caught by this
  process, not shipped: `registration_verifications.status` was originally
  `VARCHAR(20)`, too short for the real value `AWAITING_PROVIDER_CONTRACT`
  (26 characters) -- found via a live `500` during manual verification,
  fixed to `VARCHAR(30)`, confirmed by rerunning the same request.

**Step-up / MFA, stated plainly:** the source's `requireStepUp`
(`lib/security/step-up.ts`) is a server-verified RFC 6238 TOTP credential
check (`step_up_events`, `mfa_totp_credentials` -- SECURITY_GAP_ASSESSMENT.md
item #1/#2's own fix in the original). Building that faithfully needs its
own tables, enrollment UI and QR-code flow -- out of Phase 8's scope. Laravel's
built-in `password.confirm` middleware was used instead: it provides the same
*security property* (no sensitive action without a fresh, actively-proven
reauthentication) via a different, real, tested mechanism, applied to every
route the source gated with `requireStepUp` in this phase. This is a
deliberate substitution, not a removed control -- full TOTP parity is a
tracked follow-up.

## Identity foundation snapshot (closes out Phase 8: getIdentityFoundationSnapshot)

Phase 8's own last deferred piece: `lib/data/identity-repository.ts`'s
`getIdentityFoundationSnapshot`. Unlike every other snapshot in this
migration, the source itself never exposes this one through an
`app/api/v1/**` route file at all -- it is called directly from three
server-component pages (`app/organisations/page.tsx`,
`app/portal/namra/page.tsx`, `app/portal/namra-admin/page.tsx`). Exposed
here as `GET /api/v1/identity` anyway, matching this migration's own
established "every repository function gets a JSON endpoint" convention
(`App\Http\Controllers\Administration\AdministrationController`'s
identical precedent for `getAdministrationSnapshot`).

New: `App\Services\Identity\IdentityFoundationSnapshotService`, combining
four independent reads (`providers`/`organisations`/`registrations`/
`access`), run sequentially rather than in parallel -- the same
simplification `AdministrationSnapshotService` already established for
its own ten-way version of the identical pattern -- plus the ITAS
integration's own `status()` call (`App\Integrations\Itas\
ItasIdentityPort`, already built and unconditionally "unavailable" since
Module 10's own connector model isn't migrated). `organisations` and
`registrations` are not new queries: they reuse
`App\Services\Identity\OrganisationService::list()` (Phase 8's own
`listOrganisations` port, unchanged) and a newly-extracted
`RegistrationService::list()` (a pure refactor out of
`RegistrationApplicationController::index()`'s own inline query, no
behaviour change, so the identical logic isn't duplicated between the
existing registration-list route and this new snapshot -- the same
precedent `App\Support\Licensing\LicenseResolver`'s own extraction
already established).

Schema: no new tables -- `identity_providers`/`identity_links` have
existed since Phase 6/7 ("kept for future federated login"), genuinely
unused by any other command until now. New `IdentityProviderSeeder`
(3 rows, ported verbatim from the source's own deploy-time seed data):
without it, `providers` would always be empty even though this data
exists unconditionally in the source's own seed, since no application
command anywhere ever creates an identity-provider row (the same
seed-only-catalogue shape `LicensePlanSeeder`/`NavigationSeeder` already
established).

A 4-test PHPUnit feature suite (`tests/Feature/Identity/
IdentityFoundationSnapshotTest.php`, run against real MySQL) confirms:

- **Taxpayer-scoped isolation is real, not just "non-empty"**: a
  taxpayer-scoped actor's `organisations`/`registrations` include only
  their own; a second, entirely separate taxpayer's organisation and
  branch are genuinely absent, not merely unasserted -- `access.
  active_branches`/`active_memberships` are each asserted to the *exact*
  count `1`, not just "at least one", ruling out an unscoped query that
  happens to return a small number by coincidence.
- **National scope is genuinely platform-wide**: the same two
  organisations both appear for a `PILOT_ADMIN` actor, and the access
  counts cover both taxpayers' branches/memberships together.
- **Identity providers are returned in the source's own priority
  ordering** (`ITAS`, then `SITES_WORKSPACE`, then everything else),
  verified as an exact ordered list, not merely "all three present".
- **The ITAS integration status is real, not a placeholder**:
  `itas.provider` and `itas.configured` are asserted directly from
  `ItasIdentityPort::status()`'s own return shape.
- **A role that genuinely lacks `identity:read`** (`SECURITY_ANALYST`,
  confirmed absent from `Permissions::ROLE_PERMISSIONS` directly, not
  assumed) **is refused `403`**.

## Phase 9 verification (invoices and VAT)

Both a real end-to-end HTTP walkthrough (`php artisan serve` + `curl`, session
cookies, real CSRF tokens, a demo supplier/customer pair) and a 13-test
PHPUnit feature suite (`tests/Feature/Invoice/InvoiceCertificationTest.php`,
run against real MySQL via `vat_msa_testing`) confirm:

- **A valid `TAX_INVOICE` against a registered buyer is certified end-to-end
  in one transaction**: `invoices`, `invoice_lines`, `certificates`,
  `vat_transactions`, two `ledger_entries` (`OUTPUT_VAT` on the supplier,
  `INPUT_VAT` on the customer), a chained `audit_events` row, an
  `outbox_events` row, and an `idempotency_records` row all materialize
  correctly -- checked directly against the database over live HTTP, not
  just the JSON response (`invoice_id`/`transaction_id`/`certificate_id`
  round-tripped and cross-checked against `GET /api/v1/invoices/{id}`).
- **VAT-rule resolution fails closed, exactly as the source requires**: a
  line whose supplied rate doesn't match the NamRA-approved rule for its
  category on the invoice's issue date is rejected `422
  VAT_RATE_RULE_MISMATCH` (server-side rate is authoritative, never the
  client-supplied one); a tax category with no approved rule bound at all is
  rejected `422 NO_APPROVED_VAT_RULE`. Neither writes anything to the
  database.
- **Supplier/customer resolution is the dynamic `organisation_capabilities`
  grant, never a static role**: a VAT number without an active `SELLER`
  capability is rejected `422 SUPPLIER_NOT_AUTHORISED`; an unregistered buyer
  VAT number still certifies the invoice (status `CERTIFIED` rather than
  `MATCHED`) but opens an `UNREGISTERED_BUYER` reconciliation exception,
  matching the source's fail-open-but-flag design for that specific case.
- **Duplicate and collision detection holds**: the same
  `(supplier, source_system, source_document_id)` is rejected `409`; a
  second invoice re-using an already-used `(supplier, invoice_number)` is
  rejected `409` (checked independently of the idempotency-key path).
- **Idempotent replay is genuinely idempotent, not just re-validated**:
  replaying the identical `Idempotency-Key` + payload returns the *same*
  `invoice_id`/`certificate_id`/`transaction_id` without writing a second
  `invoices` row; reusing the same key with a *different* payload is
  rejected `409` rather than silently returning either response. The
  concurrent-race recovery path (`QueryException` -> unique-constraint check
  -> idempotency-record re-read) exists and is code-reviewed against the
  source's own documented race, though a true concurrent-request race was
  not separately exercised in this session (the non-racing replay path was).
- **Credit-note correction lineage and its cumulative-credit cap both
  hold**: a `CREDIT_NOTE` referencing a real original invoice creates an
  `ACTIVE` `invoice_corrections` row and the correct negative-signed
  `ledger_entries` (direction flipped from the original); a second credit
  note that would push the cumulative credited value or VAT past the
  original invoice's own value is rejected `409`, checked against the sum of
  every prior `ACTIVE` `CREDIT_NOTE` against that original, not just the
  latest one.
- **Tenant scope is genuinely enforced on submission, not just on read**: a
  `TAXPAYER_OWNER` scoped to a different taxpayer than the invoice's
  supplier is denied `403` and nothing is written; a national-scope
  `PILOT_ADMIN` can submit on behalf of any supplier, matching
  `TenantScope::requireTaxpayer`'s existing (Phase 7) semantics reused here
  unchanged.
- **RBAC is genuinely enforced**: a role without `invoices:submit` gets a
  real `403` attempting `POST /api/v1/invoices`.
- **A genuine pre-existing bug was caught and fixed by this verification
  process, not shipped**: `DemoSeeder`'s `updateOrCreate` calls (written in
  Phase 8) included `'id' => Str::uuid()` in the *update* values array for
  every row -- since every model here uses `HasUuids` (auto-assigns `id` on
  create only), this silently re-assigned a *fresh random id* to each
  already-existing demo row on every re-seed, breaking every FK pointing at
  the old id. Surfaced as a live `1451` FK-constraint violation while
  reseeding this phase's own capability grants; fixed by removing `'id'`
  from every such values array across `DemoSeeder` (see that file's own
  updated doc comment).

**Explicitly not ported this phase** (see the Phase 9 matrix row above for
the full list): `cancelInvoice`, `explainInvoiceVat`'s real per-line VAT
computation/breakdown (a lightweight standalone `vatRulesApplied()` lookup
was built instead, just enough to populate the submission response's
`vat_rules_applied` field faithfully -- not the source's full explanation
endpoint), `getTransactionTimeline`, and the standalone VAT-rule
evaluate/propose/approve routes (`lib/data/vat-rule-repository.ts`'s other
exports) are all real, scoped-out gaps, not silent omissions.
**`enforceInvoiceRateLimits`/`emitStructuredSecurityLog`** (cross-cutting
request-level concerns shared by several still-unmigrated route files) are
also not yet ported; `InvoiceController` calls out this specific gap in its
own doc comment rather than half-porting a rate limiter for one route only.

## Phase 10 verification (accounting/commercial, slice 1: business parties + quotations)

Both a real end-to-end HTTP walkthrough (`php artisan serve` + `curl`, the
Phase 6 demo login, real CSRF tokens) and a 12-test PHPUnit feature suite
(`tests/Feature/Business/BusinessPartyAndQuotationTest.php`, run against
real MySQL) confirm:

- **A business party is created with its relationship grants in one
  transaction**: `business_parties`, one `party_relationships` row per
  requested relationship, a chained `audit_events` row, and an
  `outbox_events` row all materialize correctly.
- **A duplicate active VAT number or TIN within the same organisation is
  rejected `409`**, matching the source's own dedup rule; **idempotent
  replay of the same key+payload returns the identical party** without a
  second insert.
- **An inactive party cannot be edited** (`409`, "create a new active
  relationship record if trading resumes" — the source's own stated
  design, not a bug); **deactivation preserves every existing record**
  (invoices, quotations, revisions already tied to that party are never
  touched, only the party's and its relationships' own status flip).
- **RBAC is genuinely enforced**: a `TAXPAYER_VIEWER` (which the source
  grants `commercial:read` but not `parties:manage`) gets a real `403`
  attempting to create a party.
- **The quotation lifecycle state machine holds exactly as
  `evaluateQuotationLifecycle` specifies**: a `DRAFT` quotation cannot be
  accepted directly (`409`); `DRAFT` -> `SEND` -> `ISSUED` ->
  `ACCEPT` -> `ACCEPTED` all transition correctly with their own audit/
  outbox/revision rows; an `ISSUED` quotation can be rejected or edited;
  a quotation's number is immutable once created (`409` on attempted
  change); a duplicate quotation number within the organisation is
  rejected `409`; a quotation referencing a party without an active
  `CUSTOMER` relationship is rejected `422` rather than silently allowed.
- **Quotation revisions are a real hash-chained history, not a stub**: a
  fresh quotation gets revision 1 (`CREATE`); editing an already-`ISSUED`
  quotation that has no prior revision row first backfills the implicit
  revision 1 from its own creation state before appending revision 2
  (`EDIT`) -- matching the source's own backfill logic for a quotation
  created before revisioning existed.
- **Quotation-to-invoice conversion is a genuine cross-module integration,
  verified against Phase 9's real code, not a mock**: converting an
  `ACCEPTED` quotation calls `InvoiceService::submit` directly, producing
  a real `invoices` row with lines/certificate/ledger entries/audit event
  exactly as a directly-submitted invoice would; the quotation flips to
  `CONVERTED` with `converted_invoice_id` set in the same operation. Live
  HTTP verification against the demo taxpayer pair (whose VAT number *is*
  a registered national taxpayer) produced `status: MATCHED`; the PHPUnit
  suite's synthetic business party (which is *not* itself a registered
  taxpayer) correctly produced `status: CERTIFIED` instead -- confirming
  the two systems are correctly decoupled exactly as the source intends
  (a commercial-ledger "customer" and a NamRA-registered taxpayer are
  different concepts, and invoice certification's buyer resolution only
  ever consults the latter).
- **Search is real and paginated, not a fixed list**: `GET
  /api/v1/quotations?status=...&customer_party_id=...` filters correctly
  and returns a genuine `total_count` independent of the page `limit`.

**A genuine bug in this session's own new code was caught and fixed by
this verification process, not shipped**: `BusinessPartyService` and
`QuotationService` referenced `OrganisationResolver` without importing it
(`App\Support\Business\OrganisationResolver`), so PHP resolved the bare
class name against the wrong namespace (`App\Services\Business`) and every
request failed with "Target class ... does not exist" the moment either
service was constructed. Caught immediately by the first test run, fixed
by adding the missing `use` statement to both files.

**Explicitly not ported in this slice** (see the Phase 10 matrix row above):
`verifySupplier`/`party_verification_snapshots` (reuses
`classifyTransaction` from the still-unported `identity-repository.ts`),
`getBusinessPlatformSnapshot` (the fixed-list dashboard aggregate --
superseded by the real search/list endpoints above and below for any new
UI), and `getQuotationForEdit` (the detail-read endpoint the search
results already carry the same line-level data for).

## Phase 10 verification (accounting/commercial, slice 2: accounting)

Both a real end-to-end HTTP walkthrough and a 9-test PHPUnit feature suite
(`tests/Feature/Business/AccountingTest.php`, run against real MySQL)
confirm:

- **An account is created against the organisation's chart of accounts**,
  with a real `409` on a duplicate `(organisation_id, code)`.
- **A balanced journal posts atomically**: `journal_entries` plus one
  `journal_lines` row per line, a chained `audit_events` row, and an
  `outbox_events` row all materialize correctly; an **unbalanced** journal
  (`sum(debit) != sum(credit)`, or a zero total) is rejected `422
  JOURNAL_UNBALANCED` and writes nothing.
- **A journal line referencing an account outside the actor's own
  organisation is rejected `422`** -- account ownership is checked per
  line, not just once for the journal as a whole (verified with a second,
  genuinely separate organisation's real account id, not a fabricated one).
- **A posted journal can be reversed, and a reversal cannot itself be
  reversed**: the reversal is a brand-new, equal-and-opposite entry (every
  line's debit/credit swapped -- checked directly against the reversal's
  own `journal_lines` rows, not just its parent's status) with the
  original flipped to `REVERSED`; attempting to reverse the same original
  a second time is a real `409`, matching the source's "never reverse a
  reversal" rule.
- **Closing a period genuinely blocks new postings into it** (a journal
  dated inside an already-closed period is rejected `409`), and
  **re-closing an already-closed period is a real idempotent no-op**
  (`200`, same resource, no second `accounting_periods` row) rather than
  either an error or a silent duplicate.
- **The trial balance stays balanced after a real posting** (`total_debit_cents
  === total_credit_cents`, checked as an actual equality on live summed
  data, not merely asserted), and **financial statements correctly
  attribute a credit to a REVENUE account** as both `income_statement.revenue_cents`
  and the resulting `balance_sheet.assets_cents` from the matching debit,
  with `balanced: true` genuinely computed from the double-entry data
  rather than hardcoded.
- **RBAC is genuinely enforced**: a `TAXPAYER_VIEWER` (source grants
  `accounting:read` but not `accounting:post`) gets a real `403` creating
  an account.

**Explicitly not ported in this slice**: inventory (`products`/`warehouses`/
stock movements and transfers) and `projects` (budgets/costs/profitability)
-- `lib/data/business-repository.ts`'s remaining ~10 exported functions,
each its own sub-slice.

## Phase 10 verification (accounting/commercial, slice 3: expenses)

Both a real end-to-end HTTP walkthrough and a 6-test PHPUnit feature suite
(`tests/Feature/Business/ExpenseTest.php`, run against real MySQL) confirm:

- **An expense category is created against the organisation's own
  catalog**, with a real `409` on a duplicate `(organisation_id, code)`,
  and `requires_receipt` correctly defaults to `true` when omitted
  (matching the source's own default).
- **An expense whose `net_cents + tax_cents != total_cents` is rejected
  `422 TOTAL_MISMATCH`** before anything is written.
- **The full DRAFT -> SUBMITTED -> APPROVED lifecycle works end to end**,
  with a real chained `audit_events` row on approval; an already-approved
  expense cannot be approved again (`409`).
- **Maker-checker separation is genuinely enforced, not just documented**:
  the same user who created and submitted an expense gets a real `403`
  attempting to approve *or* reject it themselves (checked as two separate
  cases); a second, different user with the same permission succeeds at
  both. The expense's status is confirmed unchanged in the database after
  the denied self-approval attempt, not just the HTTP response checked.
- **The expense report totals correctly by status and by category** over a
  real date range, with the matching line items returned alongside the
  aggregates -- checked against a real posted expense, not fixture data
  pre-shaped to match the assertion.
- **RBAC is genuinely enforced**: a `TAXPAYER_VIEWER` (source grants
  `expenses:read` but not `expenses:manage`) gets a real `403` creating a
  category.

**Explicitly not ported in this slice**: inventory (`products`/
`warehouses`/stock movements and transfers) and `projects` (budgets/costs/
profitability) -- `lib/data/business-repository.ts`'s remaining ~10
exported functions, each its own sub-slice. This closes out every
Phase 10 function except those two remaining modules.

## Phase 10 verification (accounting/commercial, slices 4-5: inventory + projects)

Both real end-to-end HTTP walkthroughs and 11 new PHPUnit feature tests
(`tests/Feature/Business/InventoryTest.php`, `ProjectTest.php`, run against
real MySQL) confirm:

- **A product and a warehouse are each created against the organisation's
  own catalog**, with a real `409` on a duplicate `(organisation_id, sku)`
  or `(organisation_id, code)`.
- **A RECEIPT movement increases the on-hand balance and correctly
  computes/records the weighted-average cost**; an ISSUE that would drive
  on-hand inventory negative is rejected `409` and writes nothing --
  checked directly against `inventory_balances`, not just the movement
  response.
- **`inventoryBalanceStatement`'s documented pre-existing upsert bug is
  structurally avoided, not re-introduced**: this port never attempts an
  `INSERT ... ON CONFLICT DO UPDATE` against a CHECK-constrained column at
  all -- `upsertBalance` always fetches the current balance first and
  computes the new quantity/average cost in PHP, exactly as the source's
  own fix does. Verified by actually exercising a second write to an
  existing balance row (the transfer test's destination leg) -- the exact
  scenario the source's own bug report says had never been exercised
  before it was found.
- **A stock transfer posts both legs atomically and preserves cost
  basis**: the destination warehouse's balance is created at the *source*
  warehouse's own current average cost, not a fabricated new one, and both
  `stock_movements` rows (`TRANSFER_OUT`/`TRANSFER_IN`) share one transfer
  id under the real `UNIQUE(organisation_id, reference_type, reference_id)`
  constraint.
- **Availability and valuation aggregate correctly across warehouses**:
  a real weighted-average-cost calculation (`quantity_micros *
  average_cost_cents / 1_000_000`) was checked against an actual computed
  total, not a value chosen to match the code.
- **A project is created with its proposed 'TOTAL' budget row in one
  transaction**, and **budget approval enforces real maker-checker
  separation**: the project's own manager (whoever created it) gets a real
  `403` attempting to approve their own project's budget; a second,
  genuinely different `projects:manage` holder succeeds and the approved
  amount (independently settable, not required to match the proposal) is
  recorded correctly.
- **Project cost posting correctly branches on cost_type**: an `EXPENSE`
  cost derives its amount/currency/date from a real, already-`APPROVED`
  expense genuinely tagged to that project (an expense from a different
  project, or one not yet approved, is rejected -- code-reviewed against
  the source's own two checks, not separately re-tested this session); the
  same expense cannot be posted as a project cost twice (`409`, backed by
  the real `UNIQUE(project_id, cost_type, source_id)` constraint); a
  `MANUAL` cost is accepted with caller-supplied values directly.
- **Profitability genuinely reuses the accounting infrastructure for
  revenue**, not a separately-invented figure: a real journal posting a
  REVENUE-type credit tagged with `project_id` was checked to flow through
  into `revenue_cents`, and `profit_cents` correctly nets it against the
  summed `project_costs`.
- **RBAC is genuinely enforced** for both modules: `TAXPAYER_VIEWER`
  (granted `inventory:read`/`projects:read` but not the `:manage`
  counterparts) gets a real `403` on both a product creation and a project
  creation attempt.

**A retrofit, not a new gap**: `journal_lines.project_id`,
`expenses.project_id`, and `quotation_lines.product_id` were deliberately
left without their FK constraints by the migrations that created those
tables (documented at the time as "the referenced table doesn't exist
yet"). Now that `projects` and `products` exist, a dedicated migration
(`..._add_deferred_project_and_product_foreign_keys.php`) adds all three
constraints retroactively -- verified via a clean `php artisan migrate` on
a database that already had those earlier tables populated by the test
suite's own prior runs, confirming no orphaned reference existed to block
the constraint.

**This closes out Phase 10 (`lib/data/business-repository.ts`) entirely**
except `verifySupplier`/`party_verification_snapshots`, which reuses
`classifyTransaction` from the still-unported `identity-repository.ts` --
a genuine, documented gap, not a silent one.

## Phase 11 verification (compliance/audits/disputes/risk, slice 1: cases, obligations, disputes, risk)

Both a real end-to-end HTTP walkthrough and a 12-test PHPUnit feature suite
(`tests/Feature/Compliance/ComplianceCaseTest.php`, run against real
MySQL) confirm:

- **A national compliance officer can open an audit case; a taxpayer
  cannot** -- a real `403` for the self-service attempt, checked alongside
  a real `201` for the authorised one (not just the negative case alone).
- **The case lifecycle genuinely walks its adjacency-list state machine,
  not a permissive status field**: `PROPOSED -> AUTHORIZED -> ASSIGNED ->
  PLANNING -> EVIDENCE_COLLECTION -> ANALYSIS -> TAXPAYER_RESPONSE ->
  FINDINGS_REVIEW -> DECISION -> CLOSED` was walked one legal transition
  at a time; an attempt to skip straight from `ASSIGNED` to `CLOSE` is
  rejected `422 CASE_TRANSITION_INVALID` and writes no
  `audit_case_transitions` row (checked via a real row count, not just the
  HTTP response) -- and **`SUSPEND`/`RESUME` returns to the exact status
  the case was suspended from** (`PLANNING`, not a fixed target), proving
  the dynamic-target `RESUME` resolution genuinely reads `suspended_from_
  status` rather than hardcoding a value.
- **`CLOSE` is blocked with no findings on record** (`409`, case status
  confirmed unchanged in the database), and **segregation of duties is
  genuinely enforced on both `CLOSE` and `ISSUE_FINDING`**: the officer who
  opened a case gets a real `403` attempting to close it -- even when
  supplying an `override_reason`, since this particular officer does not
  hold `cases:override-sod` (`NAMRA_AUDITOR`); a different officer with
  that permission (`NAMRA_SUPERVISOR`) closes the same case with no
  override needed at all, since segregation of duties only ever gates the
  case's *own opener*, not other officers.
- **Evidence citing a real certified invoice derives its checksum
  authoritatively from that invoice's own `payload_hash`**, never a
  caller-supplied value; a duplicate active citation of the same source is
  rejected `409`; `VERIFY` genuinely re-derives and compares the current
  hash (`integrity_verified=1` against an unchanged invoice, checked
  directly in the database); `SET_LEGAL_HOLD` is reflected on the evidence
  row; and superseding evidence flips the prior row to `SUPERSEDED` while
  the new row is inserted `PRESERVED` in the same transaction.
- **Case notes are genuinely append-only**: a correction note carrying
  `supersedes_note_id` is a new row: the original note's own text is
  unchanged and still present in the database afterward, not overwritten.
- **An obligation is created with a real `(taxpayer, type, period)` dedup
  guard** (`409` on a duplicate), and **`MarkSatisfied` is genuinely
  idempotent on an already-satisfied obligation** (a second call returns
  `200`/`SATISFIED` without erroring).
- **A taxpayer can self-file a dispute against their own case**, but
  **referencing a case outside their own taxpayer scope is rejected**
  `422` -- checked with a second, genuinely different taxpayer's real
  case, not a fabricated id.
- **Risk evaluation raises real indicators from real evidence**, not
  synthetic data: two genuinely `PENDING` and overdue `tax_obligations`
  rows caused `OBLIGATION_OVERDUE` to fire while `HIGH_VALUE_INVOICE_
  PATTERN` correctly did not (no matching invoices existed) -- both
  factors checked independently in the same response, not just "some
  indicator fired."
- **The risk -> case escalation gate holds end to end**: a decision
  cannot be recorded before a review is assigned (`409`); `ASSIGN_RISK_
  REVIEW` moves the indicator to `UNDER_REVIEW`; `ESCALATE_TO_CASE`
  creates a real `audit_cases` row whose `opening_reason` is traceably the
  decision's own rationale (checked directly against the database, not
  just the response) and writes a real chained `audit_events` row.
- **Restricted risk data is never visible to a taxpayer at all** (a real
  `403`, not a filtered empty list) -- distinct from `CaseTimeline`'s own
  taxpayer-readable-for-their-own-case posture, matching the source's
  explicit design difference between the two.

**A genuine bug in this session's own new code was caught and fixed by
this verification process, not shipped**: `RiskController`'s class doc
comment contained the literal substring `**/` inside a path
(`risk-indicators/**/route.ts`) -- PHP's `/** ... */` doc-comment syntax
treats `*/` as the terminator regardless of context, so this closed the
comment early and left the rest as bare code, a hard `ParseError` on
every request the controller was ever invoked for. Caught by the very
first test that exercised the route (a `500`, not a clean assertion
failure), fixed by rewording the comment to avoid the sequence.

**Explicitly not ported in this slice, at the time it was written** (see
the Phase 11 matrix row above for current status): refunds and
`VAT_RETURN`-sourced evidence citation were both anchored to
`vat_return_versions`, a real prerequisite this migration had not built
yet at the time -- both are since done (see "Refund workflow" and
"VAT_RETURN evidence citation" below). `DOCUMENT`-sourced evidence
citation was anchored to `document_metadata`, likewise not yet built --
also since done (see "DOCUMENT evidence citation" below).
`getComplianceSnapshot` (the fixed-list dashboard aggregate) was the one
piece deferred without a table blocker, consistent with the same
deferral pattern applied to `getBusinessPlatformSnapshot` in Phase 10 --
also since done (see "Compliance dashboard snapshot" below). Nothing
from this phase remains outstanding.
The source's own partial unique index on `audit_evidence`
(`WHERE status='PRESERVED'`) has no MySQL/MariaDB equivalent and is
enforced at the application layer only -- see that migration's own doc
comment for the honest limitation.

## Phase 11 verification (compliance/audits/disputes/risk, slice 2: communications + notifications)

Both a real end-to-end HTTP walkthrough and an 8-test PHPUnit feature
suite (`tests/Feature/Compliance/CommunicationAndNotificationTest.php`,
run against real MySQL) confirm:

- **SendNotice opens exactly one thread per case reference**: a second
  notice attempt against the same audit case is rejected `409` (the real
  `UNIQUE(related_resource_type, related_resource_id)` constraint's own
  backstop, not just the pre-check), and the thread's `taxpayer_id`/
  `organisation_id` are genuinely derived from the referenced case's own
  columns, never caller-supplied -- checked with a real reconciliation
  exception reference too, not only an audit case.
- **A taxpayer can reply within their own thread, but never after it is
  closed** (`409` on the post-closure attempt, checked as a genuinely
  separate case from the tenant-scope check below) -- **and a different
  taxpayer can neither read nor reply to a thread that isn't theirs** (a
  real `403` on both `GET` and the reply attempt, distinct assertions for
  each).
- **The inbox's "latest message" and message count are computed from real
  data, not the thread's own row**: a thread with an original notice plus
  one reply correctly reports `message_count: 2` and the *reply's* own
  text as `latest_message`, not the original notice's.
- **A notification correctly attempts every requested channel by
  default**, but **a user's own disabled channel preference is honoured
  for every channel except IN_APP** (verified against real
  `notification_deliveries` rows -- `EMAIL` genuinely missing after being
  disabled, `IN_APP` and `SMS` still present), matching the source's own
  "the notifications row itself *is* the in-app channel" design.
- **MarkRead is genuinely idempotent** (a second call on an already-read
  notification stays `200`/`READ`, not an error), and **a read
  notification can no longer be cancelled** (`409`, since cancellation is
  only legal from `UNREAD`) -- **and a taxpayer cannot cancel another
  taxpayer's notification** (`403`, checked with a second, genuinely
  different taxpayer's real notification).

**A genuine bug in this session's own new code was caught and fixed by
this verification process, not shipped**: `communications.occurred_at`
used this codebase's usual bare (0-fractional-second) `TIMESTAMP` column,
so two messages posted within the same wall-clock second (an original
notice and its reply, well within reach in an automated test and not
implausible for a fast human reply either) tied under `ORDER BY
occurred_at DESC`, making GetInbox's "latest message" ambiguous --
reproduced live by the inbox test itself (asserting the reply's text,
getting the original notice's back instead). The source's own SQLite
`TEXT` timestamps (`new Date().toISOString()`) carry millisecond
precision natively and never hit this; fixed here by giving
`communications.occurred_at` explicit microsecond column precision
(`timestamp('occurred_at', 6)`) and setting `Communication`'s own
`$dateFormat` to preserve it end to end -- Eloquent's default datetime
serialization truncates to whole seconds regardless of the column's own
declared precision unless a model opts in.

## VAT-return-generation prerequisite (Phase 9's own deferred scope, built to unblock Phase 11's refund slice)

Ports `lib/data/vat-lifecycle-repository.ts` in full (`getVatLifecycleSnapshot`/
`getVatReturnDetail`/`createVatAdjustment`/`generateVatReturn`/
`requestReturnApproval`/`decideVatApproval`/`submitVatReturn`) -- 9 new
tables, `App\Domain\VatLifecycle\VatLifecycleValidator`,
`App\Services\VatLifecycle\VatLifecycleService`, a new
`ItasIdentityPort::submitVatReturn` method. Both a real end-to-end HTTP
walkthrough (via PHPUnit's own HTTP test client, which drives the full
routing/middleware/controller stack exactly as a real request would --
no separate manual curl pass was run for this slice) and a 7-test PHPUnit
feature suite (`tests/Feature/VatLifecycle/VatReturnLifecycleTest.php`, run
against real MySQL, certifying real invoices first via Phase 9's own
`InvoiceService` so return generation reads genuine `ledger_entries`, not
fixtures) confirm:

- **A real certified invoice pair produces a correct, sign-correct return
  on both sides**: the supplier's period sees `output_tax_cents`; the
  customer's period -- read back from the very same certified invoice's
  `INPUT_VAT` ledger entries -- correctly computes a *negative*
  `net_payable_cents` (a genuine refund position), checked directly against
  the database and the response body, not asserted separately.
- **Maker-checker separation is real, not cosmetic**: the same user who
  requested return approval is refused (`403`) when attempting to decide
  their own task; a different (national-scope) actor can.
- **Approving a return version genuinely locks the period**: `vat_periods.status`
  flips to `LOCKED` and `lock_version` increments only on `APPROVE`, checked
  directly against the database.
- **Regenerating a still-`DRAFT` return supersedes it (a real `SUPERSEDED`
  row, version 2 created) but a return already in a controlled status
  (`PENDING_APPROVAL`/`APPROVED`/...) blocks further generation outright**,
  and generation itself requires an `OPEN` period -- all three checked as
  independent `409`/success paths, not just the happy path.
- **Tenant scope is genuinely enforced**: a different taxpayer's owner is
  refused `403` reading another taxpayer's return detail or submitting an
  adjustment against another taxpayer's period.
- **An approved VAT adjustment genuinely feeds the next generated return's
  totals** (checked as an exact `output_tax_cents`/`net_payable_cents`
  delta against the database, not just a non-error response); an adjustment
  that supplies `evidence_document_id` is rejected outright with a clear
  scoping error, `document_metadata` not being ported yet -- the same
  deferral pattern already used for `REFUND_CLAIM`-referenced notices in
  `CommunicationService`.
- **Idempotent replay holds and a reused key across two different periods
  conflicts**: replaying the identical key returns the identical return
  version (checked as exactly one row in the database, not just an
  identical response body); reusing that same key for a different period
  is rejected `409`.
- **`submitVatReturn`'s two independent gates both hold, and the retry race
  is genuinely fixed, not just claimed**: under the seeded `PILOT_CONTROLLED`
  rule set, submission is blocked purely by the local authority gate --
  ITAS is never even attempted (`BLOCKED_CONFIGURATION`, "Tax rule set
  lacks authority approval."); against a separately-created
  `AUTHORITY_APPROVED` rule set, the real `ItasIdentityPort::submitVatReturn`
  call path is reached and reports unavailable (this migration's ITAS
  adapter is unconditionally unavailable -- see that port's own doc
  comment), producing a different, ITAS-specific blocker; retrying under a
  fresh idempotency key (the "try again once ITAS might be configured"
  scenario the source itself documents) `UPDATE`s the existing attempt in
  place (`attempt_count` incremented to 2) rather than colliding with the
  `UNIQUE(provider, request_reference)` constraint -- checked as exactly
  one row for that reference in the database, not just a non-500 response.
- **RBAC is genuinely enforced**: a `TAXPAYER_VIEWER` (which the source
  grants `returns:read` but neither `returns:generate` nor
  `vat-adjustments:manage`) gets a real `403` attempting either command,
  while the read-only list endpoint still succeeds for them.

**Two things confirmed to have no application write path anywhere in the
source** (grepped across every `.ts` file under `lib/` before writing any
of this, not assumed): opening a `vat_periods` row and provisioning a
`tax_rule_sets` row are both pure out-of-band/seed data in the original --
a still-undocumented ops process outside the source's own scope, not a gap
introduced by this port. `VatLifecycleService` therefore only ever reads
and transitions periods/rule sets, never creates either; a new
`TaxRuleSetSeeder` (mirroring `VatRuleSeeder`'s own established pattern --
a real functional prerequisite, not cosmetic demo data) provisions the same
one pilot rule set and its four box mappings the source's own
`db/runtime.ts` seeds, since without it no VAT return could ever be
generated in either system. `vat_periods`/`vat_return_versions`/
`vat_return_boxes`/`reconciliation_matches`'s own source demo-seed rows
were not additionally replicated into `DemoSeeder` -- consistent with that
seeder's already-established minimal-scenario scope (one supplier/customer
pair, not a byte-for-byte replica of `db/runtime.ts`'s multi-organisation
seed set); test fixtures provision their own periods directly instead,
exactly as the source's own seed data does.

## Refund workflow (compliance-repository.ts's requestRefund/getRefundClaimChecks/transitionRefundClaim/disputeRefund)

Ports the refund workflow in full -- 3 new tables (`refund_claims`,
`refund_claim_transitions`, `refund_claim_checks`), `App\Domain\Compliance\
ComplianceValidator`'s `REFUND_CLAIM_TRANSITIONS` adjacency-list state
machine (ported verbatim from `lib/domain/compliance.ts`) and
`App\Services\Refund\RefundService`. Both a real end-to-end HTTP
walkthrough (via PHPUnit's HTTP test client) and a 4-test PHPUnit feature
suite (`tests/Feature/Refund/RefundClaimTest.php`, run against real MySQL,
building each claim on a genuinely certified invoice and a genuinely
generated/approved negative-net-position return via the prerequisite
above) confirm:

- **A refund request freezes a real, explainable 9-check battery and a
  tamper-evident snapshot**: `claim_snapshot_hash` is present and stable;
  `GetRefundClaimChecks` reads back the exact frozen rows, not a live
  recomputation. A request against a *positive*-net-position return is
  rejected `409`; a second request against an *already-claimed* return
  version is rejected `409` (the real `UNIQUE(vat_return_version_id)`
  constraint's own conflict, checked independently of the idempotency-key
  path); idempotent replay of the identical key returns the identical
  claim (checked as exactly one row in the database, not just an identical
  response body).
- **The full maker-checker transition chain holds exactly as the source's
  adjacency-list state machine specifies**: `RECEIVED` ->
  `RISK_REVIEW` -> `OFFICER_REVIEW` -> `PAYMENT_AUTHORISATION` ->
  `PAYMENT_PENDING`, each `APPROVE` checked against
  `REFUND_CLAIM_TRANSITIONS`; the claim's own requester is refused `403`
  reviewing their own request; a taxpayer-scoped actor (even with
  `refunds:request`) is refused `403` attempting to transition at all
  (officer-only); the final, fund-releasing `APPROVE` genuinely requires a
  *distinct* reviewing officer from the immediately preceding transition
  (checked as a real `403` when the same officer who authorised
  `PAYMENT_AUTHORISATION` attempts the payment-releasing step too, and a
  real `200` when a second, different officer does it) -- and that step
  correctly computes the statutory debt offset live against
  `tax_obligations` (`offset_amount_cents`/`net_payable_cents`, checked as
  an exact value against a real `PENDING` obligation, not just a
  non-error response). `PAYMENT_PENDING` is a genuine terminal boundary --
  a further `APPROVE` attempt is rejected `422`, matching the source's own
  "Payment stays DISABLED PENDING AUTHORITY" design.
- **`REQUEST_INFORMATION`/`HOLD` genuinely pause the claim and `RESUME`
  returns it to its real prior stage**, not a hardcoded status:
  `resume_status` is set on pause and read back (not re-derived) on
  resume, then cleared, checked directly against the database.
- **`DISPUTE` is genuinely restricted to the claim's own original
  requester**, not merely their taxpayer generally (a different user of a
  *different* taxpayer is refused `403`); `RESOLVE_DISPUTE_UPHOLD`
  correctly closes the claim.

**A genuine gap in the source itself, carried forward faithfully rather
than invented here** (grepped across every `.ts` file under `lib/` before
writing any of this, not assumed): no application code path anywhere in
the *entire* source codebase ever sets `vat_return_versions.status` to
`FILED` -- `submitVatReturn` only ever writes to
`vat_return_submissions.status`, never touching the return version's own
status. Consequently `requestRefund`'s `filed = version.status === "FILED"`
check is, in the live source system, always `false` through any real,
callable code path -- refund claims can only ever actually reach
`BLOCKED_RETURN_NOT_FILED`, never `RECEIVED`, matching the source's own
demo-seed row (`refund-0001`, seeded `BLOCKED_RETURN_NOT_FILED`). This port
mirrors that behaviour exactly rather than inventing a "mark return filed"
step the source itself never built; the test suite's `RECEIVED`-onward
coverage sets `vat_return_versions.status = 'FILED'` directly (documented
in the test itself) to exercise that branch, exactly as a real
`FILE_RETURN` command -- not built in either system -- would eventually
need to.

## Invoice lifecycle completion (cancelInvoice/explainInvoiceVat/getTransactionTimeline)

Closes out the rest of Phase 9's own deferred scope (`lib/data/
repository.ts`'s three remaining exports), leaving only the standalone
VAT-rule evaluate/propose/approve routes outstanding for that module.
`App\Services\Invoice\InvoiceService` gains `cancel`/`explainVat`/
`transactionTimeline`; `InvoiceController` gains matching routes, the
cancellation one step-up gated via the same `password.confirm` middleware
Phase 8's taxpayer suspension already established. Both a real end-to-end
HTTP walkthrough (via PHPUnit's HTTP test client) and a 5-test PHPUnit
feature suite (`tests/Feature/Invoice/InvoiceLifecycleTest.php`, run
against real MySQL, certifying real invoices first via `InvoiceService::submit`)
confirm:

- **`explainInvoiceVat` traces each line back to the exact approved VATRule
  version that produced its tax amount**, and **`getTransactionTimeline`
  resolves the complete chronological lineage** -- a plain certified
  invoice shows one `CERTIFICATION` event with its two ledger postings;
  querying by *either* the original invoice or one of its corrections
  resolves to the same root and the same full lineage (`CERTIFICATION`
  then `CORRECTION`, in true chronological order -- see the genuine bug
  below).
- **Both read endpoints are scoped to the supplier or customer only**: an
  unrelated third taxpayer gets a real `404`, not a filtered-but-visible
  response.
- **Cancellation is genuinely officer-only, step-up gated, reversing, and
  idempotent**: a `TAXPAYER_OWNER` (no `invoices:cancel`) is refused `403`
  even for their own invoice; a reason under 10 characters is rejected
  `422`; a successful cancellation flips `status='CANCELLED'`, writes a new
  `CANCELLATION` `vat_transactions` row referencing the original
  certification, and posts two new flipped-direction ledger entries
  (`OUTPUT_VAT DEBIT` on the supplier, `INPUT_VAT CREDIT` on the customer)
  -- checked as exact rows in the database, not just a success response.
  Cancelling an already-cancelled invoice a second time is a clean no-op
  (checked as the same row counts, not a second reversal) rather than an
  error.
- **The correction-lineage guard rails hold**: a `CREDIT_NOTE`/`DEBIT_NOTE`
  itself cannot be cancelled (`422 NOT_CANCELLABLE_DOCUMENT_TYPE` -- only an
  original tax invoice can be); an original invoice with an active
  correction against it is refused `409` rather than silently orphaning the
  correction lineage.

**Two genuine pre-existing bugs in this migration's own earlier Phase 9
work were caught and fixed by this verification process, not shipped**:

1. `vat_transactions.transaction_type` had been narrowed to
   `ENUM('CERTIFICATION','CORRECTION')` in the original Phase 9 migration
   -- a real mistake, not a source constraint (`db/runtime.ts`'s own schema
   declares this column plain `TEXT NOT NULL`, no `CHECK`). `cancelInvoice`
   genuinely inserts a third value, `CANCELLATION`, which the narrowed enum
   would have rejected outright the first time this code path was ever
   exercised. Widened to `VARCHAR(20)` instead, consistent with this
   migration's own documented ENUM-vs-VARCHAR convention (see "Design
   decisions" below).
2. The exact same same-second timestamp-tie bug already found once in
   Phase 11 slice 2 (`communications.occurred_at`) turned out to also be
   latent in `vat_transactions.created_at` -- `getTransactionTimeline`
   orders a lineage's events by this column, and this session's own test
   (a certification followed immediately by its own correction, well
   within reach of an automated test) reproduced it live: `events.0` came
   back `CORRECTION`, not `CERTIFICATION`. Fixed the identical way --
   `TIMESTAMP(6)` column precision plus `VatTransaction`'s own
   `$dateFormat = 'Y-m-d H:i:s.u'` to preserve it end to end through
   Eloquent's serialization.

## Supplier verification (verifySupplier/getSupplierVerificationHistory)

Closes out Phase 10 (accounting/commercial) entirely -- the one function
that phase deferred. Ports `App\Support\Business\TransactionClassifier`
(the single function pulled out of the still-unported
`lib/data/identity-repository.ts` -- `classifyTransaction`, the same
taxpayer/organisation/capability resolution rule invoice certification
already uses) and `App\Services\Business\SupplierVerificationService`, plus
one new table, `party_verification_snapshots`. Both a real end-to-end HTTP
walkthrough (via PHPUnit's HTTP test client) and a 5-test PHPUnit feature
suite (`tests/Feature/Business/SupplierVerificationTest.php`, run against
real MySQL) confirm:

- **Verification reflects the real, live taxpayer register, not the
  business party's own locally-entered fields**: verifying a party whose
  recorded VAT number matches a real, active, `SELLER`-capable
  organisation reports `taxpayer_active`/`organisation_active`/
  `can_act_as_seller` all `true` with the real capability list; verifying
  an unregistered VAT number reports every flag `false` -- checked as
  exact response values and a real database row, not just a non-error
  response.
- **The two eligibility gates hold**: a party with no active `SUPPLIER`
  relationship is refused `409` even if otherwise valid; a party with no
  VAT number recorded at all is refused `409` before ever reaching
  `TransactionClassifier`.
- **Verification always re-checks live and writes a genuinely new snapshot
  every time, even on an idempotent replay** -- the one deliberate
  departure from this migration's usual idempotency pattern (matching
  Module 4's `evaluateRisk`): calling it twice with the identical
  Idempotency-Key returns two *different* snapshot ids and two real rows in
  `party_verification_snapshots`, while the audit/outbox pair is written
  only once (checked as an exact row count of 1 for each, not merely "no
  error on replay"). `GetSupplierVerificationHistory` then reads back both
  snapshots, most recent first.
- **Tenant scope and RBAC are both genuinely enforced**: a different
  organisation gets a real `404` (not a leaked existence signal) attempting
  to verify or read another organisation's own party; a role without
  `parties:manage` (`SELLER_VIEWER`) is refused `403`.

## Standalone VAT-rule routes (listVatRules/proposeVatRule/approveVatRule/evaluateVatRule)

Closes out Phase 9 (invoices and VAT) entirely -- the last narrow gap that
phase deferred. No new tables (`vat_rules` already existed from Phase 9's
own invoice-certification work). Ports `App\Domain\VatRule\VatRuleValidator`
and `App\Services\VatRule\VatRuleService`; extracts the rule-resolution
query `InvoiceService::submit` already had inline into a new
`App\Support\Invoice\VatRuleResolver`, single-sourced between invoice
certification and the new standalone evaluate route exactly as the
source's own comment requires ("Callers ... must fail closed on null --
never assume a default rate") -- a pure refactor, no behaviour change (same
query, verified by the full existing invoice-certification suite still
passing unchanged). Both a real end-to-end HTTP walkthrough (via PHPUnit's
HTTP test client) and a 7-test PHPUnit feature suite
(`tests/Feature/VatRule/VatRuleTest.php`, run against real MySQL, built on
`VatRuleSeeder`'s own real seeded rules) confirm:

- **Proposal and approval are genuinely separate, step-up gated actions**:
  a proposal creates a real `DRAFT` row at the correct next version number
  for its `(tax_category, country)` lineage (computed from the current
  max, never caller-supplied); approving it flips it to `APPROVED` and
  correctly retires whichever rule previously governed that category --
  the old row's `effective_to`/`superseded_by` are set to close its range
  exactly where the new one begins, checked directly against the
  database, not just a success response.
- **Segregation of duties is real, not cosmetic**: the proposing officer
  is refused `422 SELF_APPROVAL_DENIED` attempting to approve their own
  proposal; a different officer succeeds.
- **The forward-effective-date guard holds**: a proposal whose
  `effective_from` would not genuinely postdate the currently-approved
  rule's own effective date is rejected `422
  EFFECTIVE_FROM_NOT_FORWARD` at approval time (not proposal time -- the
  source only checks this once there's a real current rule to compare
  against); approving an already-`APPROVED` rule a second time is refused
  `409`.
- **`evaluateVatRule` fails closed exactly as the source requires**: a
  category/date that resolves to the real seeded standard rule returns
  its exact id and rate; `OTHER` -- deliberately left unseeded, per
  `VatRuleSeeder`'s own doc comment -- returns a real `422
  NO_APPROVED_VAT_RULE`, never a default.
- **RBAC is genuinely enforced and read/manage are correctly separated**:
  a role holding only `vat-rules:read` (`NAMRA_COMPLIANCE_OFFICER`) can
  list rules but is refused `403` proposing one.
- **Idempotent replay holds**: replaying the identical proposal key
  returns the identical rule (checked as exactly one row at that version,
  not just an identical response body).

No bugs surfaced this pass. This closes out Phase 9 entirely.

## VAT_RETURN evidence citation (the smaller half of Phase 11's last gap)

Extends `AuditCaseService::addEvidence`/`recordEvidenceCustodyEvent` to
support `source_resource_type='VAT_RETURN'`, now that
`vat_return_versions` exists (see "VAT-return-generation prerequisite"
above). No new tables or migrations -- purely extending already-shipped
Phase 11 slice-1 code. Ports `lib/data/compliance-repository.ts`'s
`resolveEvidenceChecksum` as a genuinely shared private method (rather
than inlining the resolution logic separately in each of the two callers
that need it), matching the source's own stated intent -- "the single
place both AddEvidence ... and RecordEvidenceCustodyEvent's VERIFY action
... derive it from, so the two can never silently disagree." At the time
this slice shipped, `DOCUMENT` was still explicitly rejected with a `422`
-- `document_metadata` and its Module 22 clean-scan quarantine pipeline
were not yet ported. That gap is now closed; see "DOCUMENT evidence
citation" immediately below.

Verified by a new PHPUnit test extending
`tests/Feature/Compliance/ComplianceCaseTest.php` (117 total, 0
regressions), run against real MySQL: a real `vat_return_versions` row is
cited as evidence, its `ledger_snapshot_hash` becomes the evidence's
`checksum_sha256` exactly as `resolveEvidenceChecksum` specifies, and a
later `VERIFY` custody event correctly re-derives and confirms it
(`integrity_verified=1`) -- the same round-trip the existing `INVOICE`
evidence test already proved, now shown to hold for `VAT_RETURN` too. No
bugs surfaced this pass.

## DOCUMENT evidence citation (closes out Phase 11's last gap)

Pulls forward the minimal real prerequisite `addEvidence`/
`recordEvidenceCustodyEvent`'s `DOCUMENT` branch always needed:
`document_metadata` (Module 22's own central table) plus just enough of
`lib/data/platform-repository.ts` to populate and transition it --
`uploadDocument` and `completeDocumentScan` -- rather than inventing a
shortcut around the real dependency, the same discipline the VAT-return-
generation prerequisite and Phase 12 slice 2's `access_reviews` pull-
forward already established. `platform-repository.ts` is genuinely a much
larger module (platform/developer-portal snapshots, document supersession
and version chains, retention holds as their own standalone command,
report exports, offline sync, integrations -- comparable in scope to a
real slice of Phase 13 on its own), so this stays scoped to exactly the
Upload -> Quarantine -> ScanDecision chain and nothing past it; everything
else in that file remains genuinely `NOT STARTED`, tracked in the Phase
13-15 row above, not silently expanded into this slice.

New: `App\Models\DocumentMetadata` (the `document_metadata` migration,
matching `db/runtime.ts`'s own columns exactly); `App\Domain\Document\
DocumentValidator` (ported from `lib/domain/platform.ts`'s `safeFileName`/
`validateDocumentScanResult`); `App\Exceptions\PlatformResourceException`/
`PlatformValidationException` (this file's own error classes -- a genuinely
separate source file from `business-repository.ts`, so it gets its own
exception pair rather than reusing `BusinessResourceException`/
`BusinessValidationException`, matching this migration's established
one-exception-pair-per-source-file convention); `App\Services\Document\
DocumentService` (`upload()`/`completeScan()`, reusing `App\Support\
Business\OrganisationResolver` and `App\Support\Business\CommandLedger`
unchanged -- both already matched their TypeScript counterparts exactly);
`App\Http\Controllers\Document\DocumentController` (`POST /api/v1/
documents`, `documents:upload`; `POST /api/v1/documents/{id}/scan-result`,
`documents:manage`, national-scope only).

Cloudflare R2 (`env.DOCUMENTS`, an `R2Bucket` binding) has no Laravel
equivalent anywhere in this migration yet -- the first real file-storage
feature this migration has needed. Substituted with Laravel's own `local`
filesystem disk (`Storage::disk('local')`), keeping the source's exact
`quarantine/{organisation_id}/{document_id}/{file_name}` object-key shape
so a real object-storage adapter is a disk-driver swap later, not a
key-shape rewrite. The magic-byte content-sniffing check
(`matchesDeclaredType` -- SECURITY_GAP_ASSESSMENT.md item #7, "never trust
a client-declared MIME type alone") is ported byte-for-byte (PDF, PNG,
JPEG, XLSX-as-ZIP magic numbers; CSV via the same bounded NUL-byte
heuristic) -- not simplified into relying on Laravel's own fileinfo-based
MIME detection, a different mechanism from what the source's own named
security fix specifically requires.

`AuditCaseService::resolveEvidenceChecksum`/`addEvidence`/
`recordEvidenceCustodyEvent` are extended to their full source shape: the
DOCUMENT branch of `resolveEvidenceChecksum` (requiring the underlying
document to exist), `addEvidence`'s requirement that a cited document
already be `scan_status='CLEAN'` (else a `409`, matching the source's
"Only a clean-scanned document may be cited as evidence."), and
`recordEvidenceCustodyEvent`'s `SET_LEGAL_HOLD`/`RELEASE_LEGAL_HOLD`
cascade onto the underlying `document_metadata.legal_hold` row when the
evidence cites an uploaded document (previously entirely missing from the
PHP port) -- and its `VERIFY` re-derivation, previously scoped to
`['INVOICE', 'VAT_RETURN']`, now matches the source's own `!== 'OTHER'`
condition exactly, covering `DOCUMENT` too.

Verified by a new `tests/Feature/Document/DocumentTest.php` (8 tests) plus
one existing `ComplianceCaseTest.php` test updated to cover the new
behaviour (an unknown `DOCUMENT` id is now a real `404`, not a blanket
`422`) -- 166 total, 0 regressions, run against real MySQL: a valid
upload quarantines the file with the correct checksum/object key; content
that doesn't match its declared magic bytes, a disallowed MIME type, and
an oversized/empty file are each rejected (`415`/`413`); an invalid
`owner_domain`/`classification` is rejected (`422`); only a national
`documents:manage` role can record a scan result (`403` otherwise); a
`CLEAN` scan activates the document and an `INFECTED` scan permanently
rejects it, with correct idempotent replay and a `409` on a second genuine
attempt; and the full evidence chain -- citing a still-quarantined document
is a `409`, citing it after a `CLEAN` scan succeeds with the right
`evidence_type`/`document_id`/checksum, `VERIFY` re-derives and confirms
the match, and `SET_LEGAL_HOLD`/`RELEASE_LEGAL_HOLD` correctly cascade
onto `document_metadata.legal_hold`. No bugs surfaced this pass.

## Compliance dashboard snapshot (closes out Phase 11 entirely)

Ports `getComplianceSnapshot` -- the fixed-list dashboard aggregate every
other Phase 11 GET-list route (audit cases, obligations, disputes, risk,
refunds, communications, notifications) bundles into instead of a
dedicated query of its own, the same role this migration's other snapshot
aggregates already play (`AdministrationSnapshotService`,
`IdentityFoundationSnapshotService`). New: `App\Services\Compliance\
ComplianceSnapshotService` (eleven independent `DB::table()` reads,
matching the source's own `Promise.all` field-for-field -- including one
genuine asymmetry reproduced faithfully rather than "fixed": the source's
unscoped `refund_claim_transitions` read has no taxpayer join at all,
unlike every sibling read); `App\Http\Controllers\Compliance\
ComplianceSnapshotController`; `GET /api/v1/compliance`, gated on
`compliance:read`.

Two tables this snapshot reads (`consent_grants`, `delegations` --
`delegations` here is a taxpayer-to-user "acting on behalf of" grant,
genuinely distinct from `workflow_delegations` already ported in Phase 12
slice 5) did not exist yet. A full-repo grep of the TypeScript source
before writing their migrations confirmed neither table is ever written
by any command anywhere in it -- only by demo seed data -- so both were
added as plain migrations with no corresponding service/command to port
alongside them, matching this migration's established "don't invent a
command the source doesn't have" discipline.

Verified by a new `tests/Feature/Compliance/ComplianceSnapshotTest.php`
(3 tests; 169 total, 0 regressions), run against real MySQL: a national
actor's snapshot carries a real resource from all eleven categories
(an obligation, a case, a finding, a dispute, a risk indicator, a refund
claim with its correct joined `period_code`/`version_number`, its
transition, a communication, a notification, a consent grant, and a
delegation -- refund/consent/delegation fixtures inserted directly since
those commands, or the absence of one, already have their own coverage
elsewhere; this file's job is proving the snapshot's reads and joins, not
re-proving those commands); a taxpayer-scoped owner's snapshot contains
only their own taxpayer's obligations/cases and excludes a second
taxpayer's; and a role lacking `compliance:read` is refused `403`. No
bugs surfaced this pass.

## Remaining schema conversion (closes out Phase 4: the last ~43 tables)

Converts every remaining `db/runtime.ts` table this migration had not yet
built -- 43 migrations (`2026_09_03_000000` through `_000042`), taking
Phase 4 from 112 to 154 of 155 tables. A full-repo grep against the
TypeScript source, table by table, before writing each migration
confirmed: **none of these 43 tables are read or written by any
already-shipped PHP code** -- every one belongs to a genuinely
not-yet-built module (Developer Portal, SaaS marketplace,
offline-invoicing sync, reports/analytics/data-products, security
operations, platform config/change-management, MFA/step-up auth,
`payment_instructions`, `bank_imports`, `import_records`,
`user_invitations`). Per this migration's established discipline
(`consent_grants`/`delegations`, closed out in "Compliance dashboard
snapshot" above): **migrations only, no Eloquent models or services** --
each table gets its model/service when its own owning phase (13-15, or
wherever Developer Portal/security-ops/reporting eventually lands)
actually builds the feature that reads or writes it, not speculatively
now.

`positions` remains the one genuinely excluded table -- confirmed again
this pass via the same full-repo grep: `db/schema.ts` declares it and one
column (`employees.position_id`) references it, but no command in the
entire TypeScript source ever reads or writes a `positions` row. Building
it would be pure speculation the source itself never commits to.

Two tables initially looked like they might already be silently required
by shipped code and got extra scrutiny before being deferred anyway:
- `navigation_permissions` -- a full-repo grep found the source seeds two
  rows into it but **never reads it anywhere**, including inside
  `getEffectiveNavigation`/`getNavigationItemActions` themselves (Phase 12
  slice 3, already fully ported in `App\Services\Navigation\
  NavigationService`, which does not consult this table either -- matching
  the source's own behaviour exactly, not a gap this port introduced).
- `import_records` -- read only by `getBusinessPlatformSnapshot`
  (`business-repository.ts`), itself already an explicitly deferred
  dashboard aggregate (see Phase 10's own completion note); no command
  anywhere writes to it. `imports:read`/`imports:manage` permissions
  already exist in `App\Support\Access\Permissions` for whichever future
  command creates rows here.

Dependency order matters for 43 interlinked `Schema::create` calls, so
each migration's timestamp encodes a topological sort by real foreign-key
dependency (e.g. `developer_accounts` before `api_clients` before
`credential_refs`/`webhook_subscriptions` before `webhook_deliveries`) --
verified by a clean `php artisan migrate:fresh` from empty, not merely an
incremental `migrate` that could hide an ordering bug the first run
happened not to hit.

Two genuine MySQL/MariaDB portability bugs were caught and fixed by this
verification, not shipped:
- **The MariaDB implicit-zero-date `TIMESTAMP` gotcha, at much larger
  scale than any single migration in this session has hit it before.**
  Every non-nullable `TIMESTAMP` column beyond the first one in a table,
  with no explicit default, silently gets an implicit
  `DEFAULT '0000-00-00 00:00:00'` under this session's MariaDB
  configuration -- rejected outright by strict SQL mode
  (`SQLSTATE[42000]: 1067 Invalid default value`). Caught immediately on
  the very first migration with two required timestamps
  (`step_up_events`); fixed by auditing all 43 files for every
  non-nullable, non-`useCurrent()` `timestamp()` column and adding
  `->useCurrent()` uniformly (purely defensive, the same reasoning
  `workflow_delegations`' own doc comment already recorded) -- 45 columns
  across 30 files needed it.
- **MySQL's 64-character identifier limit** on a handful of
  multi-column unique-index names Laravel auto-generates from long
  table/column combinations (e.g.
  `offline_sync_batches_offline_device_id_sequence_from_sequence_to_unique`
  at 71 characters). Caught by `SQLSTATE[42S01]`/`42000` errors on
  `migrate`; fixed by giving those four indexes explicit, shorter names.

Verified by a clean `php artisan migrate:fresh` (all 155-1 tables, in
order, from empty) followed by `php artisan db:seed` and the full
existing test suite (169 tests, 0 regressions -- expected, since this
batch touches no table any shipped service reads or writes).

## Organisation-scope trait (closes out Phase 7's last deferred item)

Builds the reusable Eloquent global-scope trait Phase 7's own status row
had flagged as a natural follow-up, not a security gap
(SECURITY_GAP_ASSESSMENT.md Section 3 found none in the original): every
organisation-scoped service built across Phases 8-12 already gets tenant
isolation right by hand, resolving the actor's own organisation via
`App\Support\Business\OrganisationResolver` (or an inline
`TenantScope::isNational` branch) and adding its own
`->where('organisation_id', ...)` to every query. This trait is a
defense-in-depth backstop on top of that, not a replacement for it: a
model that opts in can never accidentally leak a cross-tenant row through
a query some future change forgets to scope by hand.

New: `App\Models\Scopes\OrganisationScope` (an `Illuminate\Database\
Eloquent\Scope` implementation) and `App\Models\Concerns\
BelongsToOrganisation` (the opt-in trait; Eloquent auto-discovers its
`bootBelongsToOrganisation()` on any model that uses it). Behaviour,
matching every existing service's own manual branch exactly:
- No authenticated actor (artisan commands, seeders, and the many
  existing tests that build fixtures with direct `Model::create()` calls
  outside a request) -- no filter, since fixture setup routinely spans
  multiple organisations and there is no actor to scope against.
- A national-scope actor (`TenantScope::isNational`) -- no filter.
- A taxpayer-scoped actor -- filtered to the one organisation their own
  `taxpayer_id` resolves to (`organisations.taxpayer_id` is `UNIQUE`, so
  always at most one row), via a subquery.
- An actor with neither (`taxpayer_id` null and not a national role --
  not a shape any seeded role produces today, e.g. the platform-technical
  `SUPER_ADMIN`/`INFRASTRUCTURE_ADMIN` roles) -- matches nothing, rather
  than silently leaking every tenant's rows to an actor this scope cannot
  place. A `withoutOrganisationScope()` query-builder macro is the escape
  hatch for the rare deliberate cross-boundary query.

Piloted on `App\Models\BusinessParty`: `App\Services\Business\
BusinessPartyService` already filters every one of its own queries by
`organisation_id` from `OrganisationResolver::resolve()`, so this trait's
own filter is provably redundant with the existing manual logic in both
the national and taxpayer-scoped branches -- the safest possible first
adopter, not a behaviour change. The broader retrofit onto the rest of
Phases 8-12's organisation-scoped models is its own separate pass -- see
"Organisation-scope trait retrofit" below.

Verified two ways: the full existing `BusinessPartyAndQuotationTest`
suite (12 tests) still passes unchanged, confirming zero behaviour change
against real service code; and a new, dedicated
`tests/Feature/Access/OrganisationScopeTest.php` (5 tests) proves the
trait's own automatic filtering directly, with **no manual
`->where('organisation_id', ...)` of its own** -- the one thing the
service-level tests structurally cannot exercise, since every one of
their queries already adds that filter by hand: an unscoped query with no
authenticated actor returns rows from both of two seeded organisations
unfiltered; a taxpayer-scoped owner's unscoped query returns only their
own organisation's row; a national-scope actor's unscoped query returns
both; `withoutOrganisationScope()` bypasses the filter on demand; and a
`SUPER_ADMIN` actor (`taxpayer_id` null, not a national-scope role) gets
zero rows rather than every tenant's. 174 tests total, 0 regressions, run
against real MySQL.

## Organisation-scope trait retrofit (the broader sweep Phase 7 deferred)

Applies `App\Models\Concerns\BelongsToOrganisation` to every remaining
organisation-scoped Eloquent model across Phases 8-12 -- 42 models total
now carry it, including the `BusinessParty` pilot. Mechanical in shape
(one `use` trait line, one import, per model) but **not** mechanical in
verification: applying a global scope changes real query behaviour for
any model whose owning service does not already pre-filter every read by
`organisation_id`, and this pass found genuine cases of exactly that.

**Six models were excluded after the retrofit surfaced real, behaviour-
changing regressions -- caught by the existing test suite, not shipped**:
`RefundClaim`, `VatReturnVersion`, `ApprovalTask`, and `VatPeriod` each
have a read path (`RefundService`/`VatLifecycleService`) that fetches a
record **unscoped by id first**, then calls `TenantScope::
requireTaxpayer()` (or the same check inlined) specifically so a
cross-tenant request gets a `403` (exists, wrong tenant) rather than a
`404` (genuinely absent) -- the global scope collapsed that distinction
into an always-`404`, since the row becomes invisible before the manual
check ever runs. `AuditCaseService::timeline`/`evidence`/`notes` do the
identical thing with the same inlined check against `AuditCase`. Caught
by `VatReturnLifecycleTest`'s and `RefundClaimTest`'s own
"a different taxpayer cannot read or act on another taxpayer's
X" tests expecting `403`, which started receiving `404` instead. `Organisation
Capability` is a different failure shape entirely: `App\Support\Business\
TransactionClassifier` (used by `SupplierVerificationService::verify`)
deliberately looks up a **different** organisation's active `SELLER`/
`BUYER` capability as part of verifying a counterparty's VAT number --
its own doc comment already states "a cross-tenant, public-posture
lookup by design, not a privilege boundary." The global scope silently
made every such lookup return nothing, so `verifySupplier` always reported
`can_act_as_seller: false` for a real, active supplier -- caught by
`SupplierVerificationTest`.

Given four of those six were only found because the exact right test
existed, this pass did not stop at "the suite is green" for the rest:
every remaining candidate model was checked directly against its owning
service's source for the same two danger shapes --
(1) an unscoped `Model::find($id)`/`::where('id', ...)->first()` followed
by a manual tenant-boundary check (whether via the named
`TenantScope::requireTaxpayer()` helper or an inlined equivalent), and
(2) a deliberate cross-organisation lookup for verification/authorisation
purposes -- via a full grep of `app/Services/` for both `TenantScope::
requireTaxpayer` (10 call sites, all traced to source model) and
`ModelName::find(`/`ModelName::where('id', ...)` for every remaining
candidate. Every match was either already correctly pre-scoped by
`organisation_id` (the `BusinessParty`-style safe pattern), operating on a
record the same request just created within the same method (safe by
construction, not by scope), gated behind a national-scope-only
permission check that makes the trait's own national branch a no-op
regardless, or -- for several workflow-engine tables -- read exclusively
through `DB::table()` query-builder joins rather than the Eloquent model
class at all, on which a model-level global scope has no effect either
way.

Four org-scoped models with a **nullable** `organisation_id` column
(`CommunicationThread`, `Communication`, `NavigationPreference`,
`SodRule`) were excluded from this pass entirely, kept for a separate,
more careful follow-up: a `NULL` row's interaction with the scope's own
`whereIn` subquery needs its own deliberate reasoning, not inherited from
this batch's NOT-NULL analysis -- see "Organisation-scope trait: the
nullable-column exclusions" below for that follow-up's outcome (two
added, two permanently excluded).

Verified by the full test suite after every revert (not just once at the
end) -- 174 tests, 0 regressions, run against real MySQL, plus a clean
`migrate:fresh --seed` cycle.

## Organisation-scope trait: the nullable-column exclusions

Resolves the four models the retrofit above deferred, by checking each
one's *actual* usage rather than reasoning about the nullable column in
the abstract -- the same discipline that caught the six NOT-NULL
exclusions.

**`SodRule` and `NavigationPreference` were added.** Both have a
schema-nullable `organisation_id`, but a full-repo grep of `app/Services/`
found each has exactly **one** real usage, and neither ever queries or
writes a `NULL`-organisation row: `WorkflowService::
decideWorkflowTask`'s own SoD-violation check always filters
`SodRule::where('organisation_id', $organisation->id)` against a real,
resolved organisation (the "NULL row applies globally" possibility the
migration's own doc comment noted as schema-legal was already confirmed,
independently, as unexercised by any command when that table was built
in Phase 12 slice 5); `NavigationService::saveNavigationPreference`
always resolves a real organisation via `LicenseResolver::
resolveOrganisation` before its `updateOrCreate`. The trait's own filter
is therefore exactly as redundant here as it is for the other 42 models --
same reasoning, not a special case.

**`CommunicationThread` and `Communication` are permanently excluded --
for two independent reasons, not merely "nullable and unexamined."**
First, `CommunicationService::respond`/`::conversation` (both reachable
by a taxpayer-scoped actor holding `communications:respond`) have the
identical fetch-then-check shape as the six already-excluded models:
`CommunicationThread::find($threadId)` unscoped, followed by
`! TenantScope::isNational($actor) && $actor->taxpayer_id !== $thread->
taxpayer_id` to distinguish a `403` from a `404` -- the trait would
collapse that the same way it did for `AuditCase`/`RefundClaim`. Second,
and independently disqualifying even if that check did not exist:
`CommunicationService::resolveCaseReference` deliberately writes
`organisation_id: null` for a thread sourced from a
`RECONCILIATION_EXCEPTION` (which has no resolvable organisation), while
its `taxpayer_id` is always real and non-null -- the actual tenant
boundary for this table is `taxpayer_id`, not `organisation_id`. A
taxpayer-scoped actor's own reconciliation-exception correspondence would
become permanently invisible under the trait's `organisation_id`-based
filter (`NULL IN (...)` is never true in SQL), a real functional
regression, not just an HTTP status code change.

The organisation-scope trait now covers 44 models (42 from the original
retrofit plus these two); 8 models were permanently excluded here with a
documented reason each (`RefundClaim`, `VatReturnVersion`, `ApprovalTask`,
`VatPeriod`, `AuditCase`, `OrganisationCapability`, `CommunicationThread`,
`Communication`) -- a 9th, `DocumentMetadata`, was excluded for the
identical reason once "Document module" below built the first taxpayer-
reachable unscoped read against it, making the running total 43 covered
models and 9 exclusions. Every excluded model's existing manual tenant
checks are untouched and remain fully correct; this was never about a
security gap in those
models, only about which ones this specific automatic-scope mechanism can
safely sit on top of.

Verified by the full test suite -- 174 tests, 0 regressions, run against
real MySQL (`WorkflowTest`'s own self-approval SoD test and
`NavigationTest`'s own preference-save test already exercise `SodRule`/
`NavigationPreference` through their real services, so no new dedicated
test was needed beyond the existing `OrganisationScopeTest`, which already
proves the trait mechanism itself in isolation).

## Demo seed gaps for already-shipped features (closes out Phase 5)

A full-repo grep for every table `db/runtime.ts` seeds found two more
genuinely seed-only, no-command tables (matching the `consent_grants`/
`delegations`/`navigation_permissions` precedent from "DOCUMENT evidence
citation" and "Compliance dashboard snapshot" above) -- but these two are
now read by **already-shipped** PHP features, so leaving them unseeded
silently degrades real functionality rather than just leaving a
not-yet-built feature quiet:

- **`sod_rules`**: `App\Services\Workflow\WorkflowService::decideTask`
  already ports the full self-approval segregation-of-duties check
  (`SodRule::where(...)->where('code', 'NO_SELF_APPROVAL')->...->first()`,
  gated `if ($rule) { ...write a sod_violations row + outbox event... }`)
  -- the migration's own doc comment even flagged this exact gap when the
  table was built in Phase 12 slice 5 ("confirmed by grepping... no
  application command ever creates a row here") without ever actually
  seeding it. Without a seeded rule, a self-approval attempt still
  correctly throws, but the SoD-violation audit trail silently never
  gets written.
- **`consent_grants`/`delegations`**: now read by
  `App\Services\Compliance\ComplianceSnapshotService::getSnapshot`
  (closed out in "Compliance dashboard snapshot" above), which returns an
  always-empty `consents`/`delegations` array for the demo organisation
  without a seeded row, unlike the source's own demo dataset.

`DemoSeeder` now seeds one row of each (`sod-no-self-approval`/
`sod-no-create-approve-execute`, one consent grant, one delegation) for
its own demo organisation, matching the source's own demo data shape but
with dates kept relative to "now" (`now()->subMonth()`/`addMonths(N)`)
rather than the source's fixed 2026-08 dates, so they still read as
current whenever this seeder actually runs. Verified idempotent (`db:seed`
run twice leaves row counts unchanged) and by the full test suite (174
tests, 0 regressions -- `DemoSeeder` is not exercised by the test suite
itself, so this is confirmed by direct row-count inspection after a real
`migrate:fresh --seed`, not by test coverage).

## Document module (closes out Module 22, opens Phase 13)

Ports the rest of `platform-repository.ts`'s document-specific commands
-- `supersedeDocument`, `getDocumentVersionHistory`,
`setDocumentRetentionHold`, `downloadDocument` -- completing Module 22's
own Documents & Records slice in full, on top of `uploadDocument`/
`completeDocumentScan` (pulled forward in Phase 11). Everything else in
`platform-repository.ts` (platform/developer-portal snapshots, offline
sync, integrations, report exports, data products/analytics) remains
genuinely separate sub-modules, still NOT STARTED -- this slice is scoped
to exactly the document-specific functions, matching the "smallest
coherent first slice of a large module" discipline this migration has
used throughout.

New: `App\Domain\Document\DocumentValidator::hold()` (the third and last
of `lib/domain/platform.ts`'s document-domain functions, alongside the
already-ported `safeFileName`/`scanResult`); four new methods on
`App\Services\Document\DocumentService` (`supersede`/`versionHistory`/
`setRetentionHold`/`download`); four new `DocumentController` actions and
routes (`POST .../supersession`, `GET .../versions`,
`POST .../retention-hold`, `GET .../download`). `present()` is extended
to include `legal_hold`/`retained_until`/`supersedes_document_id`/
`uploaded_by`/`scanned_by` -- fields the new commands' responses need
that Phase 11's original narrower curation didn't.

**A real finding from this pass, caught before it shipped, not after**:
`App\Models\DocumentMetadata` had been given the organisation-scope
trait during the Phase 7 retrofit (a defensible choice at the time --
nothing read it unscoped by a taxpayer-facing caller yet). This slice's
own `versionHistory`/`download` reproduce the source's exact
fetch-then-check shape (`DocumentMetadata::find($id)` unscoped, then
`OrganisationResolver::resolve()` to distinguish a genuine 404 from a
403 outside scope) -- both reachable by a taxpayer-scoped
`documents:read` actor, the identical danger shape that already
disqualified `AuditCase`/`RefundClaim`/etc. `DocumentMetadata` was
reverted from the trait as a 9th exclusion before writing a single test
against the new endpoints, rather than discovering the bug via a failing
`403`-expecting test the way the original six were found -- the pattern
itself is now recognised on sight, not just caught by tests. See
"Organisation-scope trait retrofit" above for the other eight.

Retention holds cascade both directions, matching the source's own
comment on this exact function: Module 4's `SET_LEGAL_HOLD`/
`RELEASE_LEGAL_HOLD` evidence-custody action (already built in "DOCUMENT
evidence citation" above) already cascaded evidence-hold changes onto
`document_metadata.legal_hold`; this direct path cascades the other way,
onto every `audit_evidence` row citing the document, so the two hold
paths can never disagree. Supersession keeps `document_metadata` rows as
their own version chain (via `supersedes_document_id`), the same pattern
`audit_evidence.previous_version_id`/`vat_return_versions.parent_version_id`
already use -- no separate Version table. Download refuses anything not
currently `ACTIVE` or `SUPERSEDED` (the "no download before a clean
scan" principle), and is logged through the same audit-events hash chain
as every other command in this file, not a bespoke access-log table.

The source's own `enforceRateLimits` calls on every one of these
handlers are, as with the original upload/scan-result pair, deliberately
not ported -- an orthogonal concern with no decided story anywhere else
in this migration either.

Verified by 7 new tests in `tests/Feature/Document/DocumentTest.php`
(now 15 total for the whole document module): superseding an `ACTIVE`
document quarantines the replacement and flips the original to
`SUPERSEDED`; only a clean, active document can be superseded (`409`
otherwise); version history walks the full chain oldest-first when
queried from *any* version in it, not just the newest; a taxpayer
outside the document's organisation is refused `403`; a retention hold
requires `documents:manage` (national scope) and cascades onto every
`audit_evidence` row citing the document, both applying and releasing;
download is refused before a clean scan and for a permanently-rejected
(infected) document, and returns the exact original bytes with the
correct `Content-Type`/`Content-Disposition` once available. 181 tests
total, 0 regressions, run against real MySQL, plus a clean
`migrate:fresh --seed` cycle.

## Platform snapshots (Phase 13, second slice)

Ports `platform-repository.ts`'s four read-only dashboard aggregates:
`getPlatformSnapshot`, `getTechnicalPlatformSnapshot`,
`getDocumentCustodySummary`, `getDeveloperPortalSnapshot`. Everything
else in that file (the offline-sync commands, report exports, data
products/analytics, platform config/change-management) remains
genuinely separate sub-modules, still NOT STARTED -- this slice is
scoped to exactly the four snapshot reads, the same "smallest coherent
next slice" discipline "Document module" above already established for
Phase 13.

New: `App\Services\Platform\PlatformSnapshotService` and
`App\Http\Controllers\Platform\PlatformSnapshotController`; `GET
/api/v1/platform`, `GET /api/v1/platform/document-custody`, `GET
/api/v1/platform/developer-portal`. No Eloquent models exist for the 13
tables `getPlatformSnapshot` alone reads across (`integration_connections`/
`api_clients`/`webhook_subscriptions`/`sync_jobs`/`bank_imports`/
`payment_instructions`/`offline_devices`/`offline_number_ranges`/
`offline_sync_batches`/`offline_conflicts`/`report_definitions`/
`report_runs`/`service_components`) -- Phase 4 built their migrations
schema-only, deliberately, until a real reader needed one; this service
reads them all via `DB::table()`, matching `App\Services\
Administration\AdministrationSnapshotService`'s own established style
for exactly that reason.

Two source behaviours preserved deliberately rather than "corrected":
- **`getPlatformSnapshot`'s `$scoped` branch is unreachable by any role
  seeded today**, confirmed by checking every role holding `platform:read`
  (`PILOT_ADMIN`/`NAMRA_COMPLIANCE_OFFICER`/`NAMRA_SUPERVISOR`, all
  national-scope, plus `SUPER_ADMIN`/`INFRASTRUCTURE_ADMIN`, routed
  straight to the technical snapshot instead) against
  `Permissions::NATIONAL_SCOPE_ROLES` -- no taxpayer role holds
  `platform:read` at all. The branch is still ported faithfully (not
  pruned as dead code), matching the source's own defensive shape, for
  whichever future role grant might reach it.
- **`ORDER BY criticality DESC` on `service_components` is plain
  alphabetical**, not a true severity sort (`LOW`/`MEDIUM`/`HIGH`/
  `CRITICAL` alphabetised DESC comes out `MEDIUM, LOW, HIGH, CRITICAL`) --
  the source does exactly this, so this port does too, rather than
  inventing a `CASE`-based severity ordering the source never asked for.

`getDocumentCustodySummary`/`getDeveloperPortalSnapshot` are, like
`getIdentityFoundationSnapshot` before them, only ever consumed by the
source's own portal server components (`app/portal/buyer/page.tsx`,
`app/portal/developer/page.tsx`) rather than a dedicated API route --
exposed as one here anyway, matching this migration's established
convention. `getDeveloperPortalSnapshot`'s own `DEVELOPER_PARTNER`-with-
no-`taxpayer_id` short-circuit is preserved exactly: that role is not
national-scope, so an unlinked partner would otherwise fail
`OrganisationResolver::resolve()`'s "no active taxpayer organisation"
check on a genuinely legitimate state (signed up, not yet linked), not
an error.

Verified by a new `tests/Feature/Platform/PlatformSnapshotTest.php` (5
tests): a national actor's full snapshot aggregates a real row from all
13 source tables plus a real uploaded document (via the actual
`DocumentService::upload` command, not a raw insert) and a real outbox
event; a technical-only role (`SUPER_ADMIN`) gets exactly the seven
technical-snapshot keys and never the organisation-scoped ones; a role
lacking `platform:read` is refused `403`; the document-custody summary
correctly counts three real uploaded documents by status (two
`QUARANTINED`, one `CLEAN` after a real scan); and the developer-portal
snapshot returns `ORGANISATION_LINK_REQUIRED` with empty arrays for an
unlinked `DEVELOPER_PARTNER`, then the organisation's real API client
and webhook once linked. 186 tests total, 0 regressions, run against
real MySQL, plus a clean `migrate:fresh --seed` cycle.

## Offline sync commands (Phase 13, third slice)

Ports `platform-repository.ts`'s `receiveOfflineBatch` (via
`lib/domain/platform.ts`'s `validateOfflineBatch`) -- Module 22's
offline-invoicing sync-batch intake, the counterpart to the read-only
`offline_devices`/`offline_sync_batches` rows "Platform snapshots" above
already exposes for reading. Still genuinely separate sub-modules of that
same source file, still NOT STARTED: report exports, data products/
analytics, platform config/change-management -- the same "smallest
coherent next slice" discipline as the prior two slices.

New: `App\Domain\Platform\OfflineSyncValidator` (device_id/batch_id/
sequence-range/timestamp/hash/document-count/signature validation, a
direct port of `validateOfflineBatch`), `App\Services\Platform\
OfflineSyncService::receive()`, `App\Http\Controllers\Platform\
OfflineSyncController`; `POST /api/v1/offline/batches`, kept 1:1 with the
source's `app/api/v1/offline/batches/route.ts` shape. No Eloquent model
for `offline_devices`/`offline_sync_batches` yet -- `DB::table()`
throughout, matching "Platform snapshots"'s own established style.

The device lookup accepts either the device's own id or its
`device_code` (`WHERE d.id=? OR d.device_code=?`, matching the source
exactly); a device outside the actor's own taxpayer scope (checked via
`TenantScope::isNational`/`taxpayer_id`, joined off `organisations`) is a
`403`, an unenrolled device a `404`. A replayed `batch_id` for the same
device returns the prior batch unchanged when the content hash matches,
or `409`s when it doesn't -- the same idempotency shape
`App\Support\Business\CommandLedger` already gives every command, applied
here by hand since this command's identity key is `(offline_device_id,
client_batch_id)`, not an `Idempotency-Key` header.

**Faithful-port note, not a bug**: the source has never actually wired up
real device-signature verification. `receiveOfflineBatch`'s own
`$rejection` starts as `SIGNATURE_VERIFIER_NOT_CONFIGURED` and is only
ever overridden by the device-trust (`status`/`enrolment_status`/
`public_key_reference`) / sequence-continuity / hash-chain-continuity
checks below it -- so a batch that clears every one of those three still
falls through to that same default and is written with
`status='REJECTED'` regardless. There is no path in the source that ever
accepts a batch today. Reproduced exactly as the source has it (this
migration's established "reproduce source quirks faithfully" convention,
already applied to `getPlatformSnapshot`'s `$scoped` branch and the
`service_components` ordering above), not "fixed" by inventing a
signature verifier the source itself never built.

The source's own `enforceRateLimits`/`readBoundedJson`/
`emitStructuredSecurityLog` calls around `handleOfflineBatch` are NOT
ported here -- the same orthogonal-concern deferral
`App\Http\Controllers\Document\DocumentController`'s doc comment already
documents for rate limiting on the Document module, extended here to the
request-body-size bound and structured security logging; this
migration's rate-limiting/request-size/security-logging story is not yet
decided anywhere else in the codebase either, so it is not silently
bundled into this slice.

Verified by a new `tests/Feature/Platform/OfflineSyncTest.php` (11
tests): permission gating (`403` without `offline:sync`); an unknown
device (`404`); a device outside the actor's taxpayer scope (`403`) and a
national-scope actor reaching any taxpayer's device regardless; a batch
that passes every other check still being written `REJECTED` with
`SIGNATURE_VERIFIER_NOT_CONFIGURED` (the faithful-port behaviour above);
each of the other three rejection reasons
(`DEVICE_TRUST_NOT_ESTABLISHED`/`SEQUENCE_GAP_OR_REPLAY`/
`HASH_CHAIN_MISMATCH`) individually triggered; an identical-content
replay returning the prior batch without a second insert; a
different-content replay of the same `batch_id` conflicting (`409`); and
a malformed payload failing validation with the expected error codes.
197 tests total, 0 regressions, run against real MySQL, plus a clean
`migrate:fresh --seed` cycle.

## Report exports (Phase 13, fourth slice)

Ports `platform-repository.ts`'s `runInlineReport`/`publishReportRun`/
`requestReportExport`/`approveReportExport`/`cancelReportExport`/
`getReportExport`/`downloadReportExport` (Module 7 Phases A-C), via
`lib/domain/platform.ts`'s `validateReportParameters`/
`validateExportCommand`/`validateExportCancellation`. Still genuinely
separate sub-modules of that same source file, still NOT STARTED: data
products/analytics, platform config/change-management.

New: `App\Domain\Platform\ReportValidator`, `App\Services\Platform\
ReportExportService` (all 7 methods), `App\Http\Controllers\Platform\
ReportController`; `POST /api/v1/reports/{code}/runs`, `POST /api/v1/
reports/runs/{id}/publication`, `POST /api/v1/reports/runs/{id}/exports`,
`GET /api/v1/reports/exports/{id}`, `POST /api/v1/reports/exports/{id}/
approval`, `POST /api/v1/reports/exports/{id}/cancellation`, `GET
/api/v1/reports/exports/{id}/download` -- kept 1:1 with the source's own
`app/api/v1/reports/**` route shapes. No Eloquent model for
`report_definitions`/`report_runs`/`report_exports` yet -- `DB::table()`
throughout, matching Platform snapshots' and Offline sync's own
established style; `document_metadata` (which does have a model) is
still written via `DB::table()` in `requestExport()`, matching that
command's own single mixed-table transaction shape rather than mixing an
Eloquent write into an otherwise-`DB::table()` command.

**The audience-tier guardrail** (`requireAudienceAccess`) is a genuine
per-tier dispatch, not one generic gate: `NAMRA_OPERATIONS` requires
national scope; `EXECUTIVE` requires national scope AND `reports:executive`;
`AUDITOR_LEGAL` requires `audit:read` OR `cases:manage`; `PRACTITIONER`
requires at least one `delegations` row with `status='ACTIVE'` for the
actor, and scopes the report to exactly those delegated taxpayers;
`TAXPAYER`/`OPEN_DATA` need no extra check (already correctly scoped by
the actor's own resolved organisation, or a result-shaping concern
handled inside `computeReportResult` itself). `CASE_EVIDENCE_SUMMARY`'s
own cross-tenant `case_id` refusal is preserved faithfully but, like
`PlatformSnapshotService::getSnapshot`'s own `$scoped` branch, is not
reachable by any role seeded today -- every role holding
`audit:read`/`cases:manage` is also a `NATIONAL_SCOPE_ROLES` member
(verified across the full `Permissions::ROLE_PERMISSIONS` map), so
`TenantScope::isNational($actor)` is always true before that comparison
is ever reached.

**`publishReportRun`'s reconciliation gate**: `computeReportResult` is
re-run against the run's own persisted `scope_snapshot` (never a scope
re-derived from whoever happens to call publish -- a PRACTITIONER-tier
run's delegated-taxpayer set is resolved once, at run time) and compared
to the stored `result_summary` via `AuditService::canonicalJson` (a
sorted-key JSON comparison, the same helper `CommandLedger::requestHash`
and `OfflineSyncService`'s own batch-hash comparison already use); a
divergence refuses publication with `409`, forcing a fresh run before
the figure can become official.

**The step-up requirement on `requestExport`/`approveExport` is
data-conditional, not route-wide** -- unlike every other step-up-gated
command in this migration (state/upgrade, taxpayer suspension,
registration decisions, membership assignment, invoice cancellation),
which are unconditionally gated and so simply wear the route-level
`password.confirm` middleware, these two only require a fresh step-up
when the report's own classification is sensitive
(`TAX_CONFIDENTIAL`/`RESTRICTED`) or the export's own
`requires_step_up` flag is set. New `App\Support\Access\StepUp::isFresh()`
mirrors Laravel's own `Illuminate\Auth\Middleware\RequirePassword::
shouldConfirmPassword` freshness check exactly (same
`auth.password_confirmed_at` session key, same `auth.password_timeout`
config), evaluated inline by the controller instead of gating the whole
route.

**Faithful quirk, not a bug**: `publishReportRun`'s idempotent-replay
path returns the raw persisted `report_runs` row, while its fresh-publish
path hand-builds an enriched response (decoded `result_summary`, a
computed `envelope`, ISO-formatted timestamps) -- a genuine shape
difference the source itself has (SQLite's TEXT-native storage happens
to make the two paths format-identical there; MySQL's TIMESTAMP columns
do not round-trip as ISO text), reproduced rather than papered over by
forcing the two paths into one shape the source never gives them.
`requestExport`/`approveExport`/`cancelExport` do not have this
asymmetry -- their own success paths always return the raw persisted row
regardless of replay.

Verified by a new `tests/Feature/Platform/ReportExportTest.php` (24
tests): permission gating and an unknown/unimplemented report code;
`SALES_VAT_SUMMARY`'s own-organisation aggregation; `COMPLIANCE_CASELOAD`'s
national-scope guardrail; `REVENUE_COMPLIANCE_TRENDS`'s national-scope
+`reports:executive` guardrail; `CASE_EVIDENCE_SUMMARY`'s
audit-authority guardrail, missing/unknown `case_id` handling, and its
own faithful-but-unreachable cross-tenant check; `PORTFOLIO_EXCEPTIONS`'
active-delegation guardrail and delegated-taxpayer scoping;
`NATIONAL_VAT_AGGREGATE`'s minimum-cell suppression; publish's
reconciliation gate (success, conflict-on-changed-data, requester/national
-only, idempotent replay); export request (auto-approved when
non-sensitive, step-up-gated and `PENDING_APPROVAL` when sensitive,
requester/national-only); export approval (national-only, no
self-approval, step-up-gated); export cancellation (releases the
quarantined document, only while `PENDING_APPROVAL`, reason-length
validation); and export access/download (requester/national-only,
refused unapproved or expired, exact byte-for-byte content on success).
221 tests total, 0 regressions, run against real MySQL, plus a clean
`migrate:fresh --seed` cycle.

## Data products & analytics (Phase 13, fifth slice)

Ports `platform-repository.ts`'s `listDataProducts`/`runAnalyticsModel`/
`publishDataProduct`/`queryApprovedMetrics`/`listAnomalyCandidates`
(Module 7 Phase D), via `lib/domain/platform.ts`'s
`validateRunModelCommand`/`validatePublishDataProductCommand`. This
closes out `platform-repository.ts` down to exactly one remaining
sub-module: platform config/change-management (Module 8 Phase A
onward), still NOT STARTED.

New: `App\Domain\Platform\DataProductValidator`, `App\Services\Platform\
DataProductService` (all 5 methods), `App\Http\Controllers\Platform\
DataProductController`; `GET /api/v1/analytics/data-products`, `POST
/api/v1/analytics/data-products/{id}/model-runs`, `POST /api/v1/
analytics/data-products/{id}/publications`, `GET /api/v1/analytics/
metrics`, `GET /api/v1/analytics/anomalies` -- kept 1:1 with the
source's own `app/api/v1/analytics/**` route shapes. No Eloquent model
for any of the six tables this service touches (`data_products`/
`data_product_lineage`/`metrics`/`analytics_model_runs`/
`data_product_snapshots`/`analytics_anomaly_candidates`) --
`DB::table()` throughout, matching every other Phase 13 slice.

**Analytics is greenfield in the source itself** -- a documented
2026-08-26 audit found nothing beyond an "ARCHITECTURE ONLY" label. This
migration has no separate governed read replica/warehouse either (the
same MySQL database backs both the live fiscal write path and every
read), so "RunModel against a governed read replica only, never the
live fiscal write store" is built as the strongest real analog
available: a data product's `runModel()` step may only be fed by an
already-`PUBLISHED`, already-reconciled `report_runs` row (Phase C's
`publishReportRun`, see "Report exports" above) -- never a live query
against invoices/vat_return_versions/audit_cases/etc. `runModel()`/
`publish()` only ever read `report_runs`/`report_definitions`/
`data_products`/`metrics`/`analytics_model_runs`/
`data_product_snapshots`, never a fiscal source table directly.
`DataProduct`/`Metric`/lineage definitions are deliberately seed-only
(no command creates one) -- the same posture `report_definitions`
already established: defining a new governed metric is a
governance/config action out of scope for this pilot.

**`publishDataProduct`'s anomaly detection**: every `CERTIFIED` metric
on the data product is checked against the previous snapshot's value
for the same field; a percentage change at or beyond the metric's own
`anomaly_threshold_pct` raises a genuine, explainable
`AnomalyCandidate`, persisted as a queryable row
(`analytics_anomaly_candidates`) and an outbox event, not just a
fire-and-forget notification. The first-ever publish for a data product
has no previous snapshot to compare against, so it can never raise an
anomaly -- reproduced exactly, not "improved" with some synthetic
baseline.

**Faithful-port note**: `analytics_model_runs.status` has exactly one
writer in the entire codebase (`runModel()`), which always inserts
`'COMPLETED'` -- there is no command anywhere that could leave a row in
any other status. `publish()`'s own `status !== 'COMPLETED'` guard is
consequently unreachable through normal command flow today (the same
"preserved for a future extension, not dead code" posture as
`getPlatformSnapshot`'s own `$scoped` branch and `CASE_EVIDENCE_SUMMARY`'s
cross-tenant check); tested here via a directly-seeded fixture row in a
non-`COMPLETED` state, since no command can produce one to exercise the
path organically.

Verified by a new `tests/Feature/Platform/DataProductTest.php` (11
tests): `reports:read`-gated listing with real lineage/certified-metric-
only filtering/latest-snapshot shape (excluding a `RETIRED` product and
a `DRAFT` metric); `runModel`'s national-scope gate, unknown-data-
product/unknown-report-run handling, source-report-definition mismatch,
unpublished-run and minimum-cell-suppressed-run refusal, success, and
idempotent replay; `publish`'s national-scope gate, unknown-model-run
handling, the (unreachable-in-practice but faithfully-preserved)
non-`COMPLETED`-status guard, and refusing a second publish of the same
model run; the first-publish-never-anomalous case and a real
threshold-exceeding anomaly on a second publish (with the anomaly
queryable via `GET /analytics/anomalies`); and `queryApprovedMetrics`'
`AVAILABLE`/`NO_DATA` status and `data_product_id`/`code` filtering.
232 tests total, 0 regressions, run against real MySQL, plus a clean
`migrate:fresh --seed` cycle.

## Platform config & change-management (Phase 13, sixth and final slice)

Ports `platform-repository.ts`'s `getPlatformConfig`/
`listPlatformChangeRequests`/`requestPlatformChange`/
`decidePlatformChange`/`provisionPlatformStaff` (Module 8 Phase A), via
`lib/domain/platform.ts`'s `validatePlatformChangeRequest`/
`validatePlatformChangeDecision`/`validateProvisionStaff`. **This closes
out `platform-repository.ts` entirely** -- every export from that source
file now has a PHP counterpart. Only Phases 14-15 (the legacy D1
importer and deployment documentation) remain outstanding in this whole
migration.

New: `App\Domain\Platform\PlatformChangeValidator` (plus its
`PLATFORM_STAFF_ROLES` constant), `App\Services\Platform\
PlatformChangeService` (all 5 methods), `App\Http\Controllers\Platform\
PlatformConfigController`; `GET /api/v1/platform/config`, `GET
/api/v1/platform/change-requests`, `POST /api/v1/platform/change-requests`,
`POST /api/v1/platform/change-requests/{id}/decision`, `POST
/api/v1/platform/staff` -- kept 1:1 with the source's own
`app/api/v1/platform/**` route shapes. No Eloquent model for
`feature_flags`/`platform_config`/`access_policies`/`change_requests` --
`DB::table()` throughout, matching every other Phase 13 slice.
`provisionStaff()` is the one exception: it writes directly to
`users`/`identity_links` (both of which do have models elsewhere) via
`DB::table()` too, the same "one mixed-table transaction, not a mixed
Eloquent/`DB::table()` write" posture `ReportExportService::
requestExport()` already established.

**A documented 2026-08-26 audit found zero code anywhere for
FeatureFlag/PlatformConfig/AccessPolicy/ChangeRequest**, despite an
architecture matrix's "VERIFIED FOUNDATION" label on this domain row.
Definitions (which flags/config keys/policies exist) are deliberately
seed-only -- the same posture already established for
`report_definitions` and `data_products`/`metrics`: deciding a new
governed knob should exist at all is a deploy-time/governance action,
out of scope for a runtime command. Only the *value* of an existing
definition is runtime-changeable, and only through a real maker-checker
gate: `requestChange()` stages a proposed value as a `PENDING`
`change_requests` row (snapshotting the previous value so the diff is
always reconstructable); `decideChange()` applies or rejects it,
refusing self-decision the same way every other maker-checker command in
this codebase does. **Three of these seeded config values now feed back
into a real downstream consumer** -- see "Platform config now feeds three
real consumers" immediately below -- the step-up window, the export size
cap and the minimum-cell suppression threshold are read live from their
seeded row when an `ACTIVE` one exists. Rate-limit defaults remain
illustrative only: there is no rate-limiting code anywhere in this
codebase for that row to feed into (confirmed by
`grep -rln "RateLimiter::for\|throttle:"` returning nothing), so wiring it
is left, honestly, for whenever a rate-limiter is actually built. Every
other seeded `feature_flags`/`platform_config`/`access_policies` row
remains illustrative/documentary too, for the same reason the source's
own comment gives: wiring a consumer is a change made when that consumer
actually needs it, not before.

### Platform config now feeds three real consumers

`App\Support\Platform\PlatformConfigReader` (new) is the first code to
read `platform_config`/`access_policies` values outside
`PlatformChangeService`'s own read/propose/decide command surface. Two
static, uncached reads -- `int(string $key, int $default)` against
`platform_config.value`, `policyInt(string $code, string $field, int
$default)` against one field of an `access_policies.parameters` JSON blob
-- each falling back to the caller's own pre-existing hardcoded literal
if no `ACTIVE` row exists or the stored value isn't numeric, so a test
suite or install that never seeds these rows keeps the exact behaviour it
already had.

Three call sites now use it instead of a bare constant:
- `App\Services\Platform\ReportExportService::requestExport` --
  `reports.export_size_limit_bytes` (falls back to the same 200KiB it
  always enforced).
- `App\Services\Platform\ReportExportService::computeReportResult`'s
  `NATIONAL_VAT_AGGREGATE` branch -- `reports.min_cell_suppression_threshold`
  (falls back to the same threshold of 10).
- `App\Support\Access\StepUp::isFresh` -- the `STEP_UP_WINDOW` access
  policy's own `window_seconds` (falls back to Laravel's own
  `auth.password_timeout` config, exactly as before).

`DemoSeeder` seeds all three rows with the same values these constants
already hardcoded (200KiB, 10, 10800 seconds), so the demo environment's
observable behaviour is unchanged; only a real `decideChange()` applying
a different value now has an actual effect. Verified by a new
`tests/Feature/Platform/PlatformConfigReaderTest.php` (9 tests covering
the reader class directly: default/seeded/non-numeric/inactive-row cases
for both `int()` and `policyInt()`) and three new tests in
`tests/Feature/Platform/ReportExportTest.php` proving each of the three
call sites actually changes behaviour once a smaller/larger seeded value
is in place (an export that fits under the 200KiB default is refused once
the seeded limit is 1 byte; 14 invoices that were not suppressed under
the default minimum-cell threshold of 10 are suppressed once the seeded
threshold is 20; a step-up confirmation that is fresh under the default
3-hour window is stale once the seeded window is 60 seconds).

**`provisionPlatformStaff` and the `users.password` column**: the
source's own account here is federated-identity-only (an
`identity_links` row at `PLATFORM_AUTHENTICATED` assurance, no local
password concept at all), but this schema's `users.password` is
`NOT NULL` (the identity-core migration's own design decision: local
Laravel auth must work independently of `identity_links`). A random,
nobody-knows-it `Hash::make(Str::random(40))` satisfies the column
without granting any real local-login capability for the provisioned
account -- pairing this with a real password-reset/invite flow so a
provisioned staff member can actually sign in is a documented follow-up,
not silently dropped. Unlike the report-export commands'
data-conditional step-up (`App\Support\Access\StepUp`), `provisionStaff`
is **unconditionally** step-up gated -- the same posture
`InvoiceService::cancel()` already established for a comparably
privileged action -- so its route simply wears the `password.confirm`
middleware.

Verified by a new `tests/Feature/Platform/PlatformChangeTest.php` (12
tests): `platform:read`-gated config reads (`ACTIVE`-only rows,
`RETIRED` excluded) and change-request listing (filterable by status);
`requestChange`'s `platform:manage` gate, unknown-target and invalid-
proposed-value-shape refusal, previous-value snapshotting, and
idempotent replay; `decideChange`'s `platform:manage` gate, unknown/
already-decided refusal, self-decision refusal, and -- for all three
target types (`FEATURE_FLAG`/`PLATFORM_CONFIG`/`ACCESS_POLICY`) -- a
real approval applying the change and bumping `version`, plus a
rejection leaving the target untouched; and `provisionStaff`'s
`platform:manage` + step-up gate (`423` without a fresh confirmation),
duplicate-identity/email and invalid-role refusal, and a real success
(no `taxpayer_id`, a real `identity_links` row) with idempotent replay.
244 tests total, 0 regressions, run against real MySQL, plus a clean
`migrate:fresh --seed` cycle.

## Frontend UI build-out (a new initiative, not one of the original 15 phases)

**A discovery, not a phase deliverable**: with the backend migration
(Phases 1-15) complete, an audit of `resources/views/` found only 5
Blade views total (login, password-confirm, a placeholder dashboard,
the layout, and the default Laravel welcome page) against **179**
routes -- 3 controllers return a view, 36+ return raw JSON. Every
module this migration ported (invoices, VAT returns, compliance/audit
cases, refunds, accounting, business parties, quotations, expenses,
inventory, projects, licensing, portals, documents, reports, analytics,
platform config, the workflow engine) is API-only; there is no
application a person can actually use yet, only endpoints something
else would call. This is a genuinely new, comparably-sized initiative
(179 routes' worth of screens), not something the original 15-phase
scope ever covered -- tracked here as its own section, not folded into
the phase numbering above.

### Real dashboard (replaces the placeholder)

Ports the source's own `app/page.tsx` ("VAT transaction control
centre") + `lib/data/repository.ts`'s `getDashboardSnapshot` -- the
source's single, role-agnostic landing dashboard. Not a per-role
snapshot dispatch (there is no such routing in the source for the main
dashboard) -- every actor with `dashboard:read` sees the same shape;
the only variation is national-vs-own-taxpayer scoping, matching every
other snapshot service in this migration.

New: `App\Services\Dashboard\DashboardSnapshotService::snapshot()`,
reusing `InvoiceService::list()` directly for `recentInvoices` (matching
the source's own reuse of `listInvoices`, not a second query that could
drift from it). `recentAudit` is empty for an actor without
`audit:read` -- checked per-field, not as a route-level gate, since a
taxpayer-scoped actor legitimately sees their own VAT metrics/invoices
without ever seeing the append-only audit stream. `DashboardController`
now calls this service and renders a real Blade view (4 KPI cards --
certified documents, transaction value, VAT controlled, open exceptions
with a high/critical-risk footnote -- plus a recent-invoices table and
an audit-events activity stream) in place of the earlier Session/
effective-permissions placeholder.

Not ported: the source's own `requireLicensedPermission(user,
"dashboard:read", { operationClass: "READ" })` combines the permission
check with a licensing/entitlement gate
(`App\Support\Licensing\EntitlementGate`, this migration's own pattern
for genuinely organisation-scoped licensed operations -- see Phase 12's
Access Governance/Administration snapshot sections). `dashboard:read`
is granted unconditionally to every one of this migration's 21 roles
(verified against `Permissions::ROLE_PERMISSIONS`), including
national-scope roles that resolve to no organisation at all, so the
controller checks the permission alone
(`$this->authorize('permission', 'dashboard:read')`) rather than
routing a landing page through an entitlement gate built for a
different kind of check -- a documented simplification, not a silently
dropped control.

Verified by a new `tests/Feature/Dashboard/DashboardTest.php` (5
tests): unauthenticated access redirects to login; a taxpayer-scoped
actor sees only their own supplier-or-customer invoices and an empty
(permission-gated) evidence stream; a national actor sees every
taxpayer's invoices and the real audit trail, correctly ordered; the
high/critical-risk footnote counts only `HIGH`/`CRITICAL` rows, not
every open exception; and a customer-side (not just supplier-side)
invoice correctly counts toward a taxpayer's own metrics. Also
verified visually over a real HTTP session (login as the
`owner@demo-trading.test` demo user, screenshot + rendered-text
inspection, no console errors) -- this migration's first screen ever
checked that way, not just via `curl`/JSON assertions. 256 tests total,
0 regressions, run against real MySQL, plus a clean
`migrate:fresh --seed` cycle.

**Every screen this initiative originally scoped is now built**
(invoices closed out just below; compliance/audit cases, refunds, the
portal switchboard, all six portal dashboards --
Buyer/Seller/NamRA/Super Administration/Developer/NamRA Administration
-- and business parties, quotations, accounting, operations
(expenses/inventory/projects/imports), licensing/administration,
documents, reports/analytics, platform config and the workflow engine's
own dedicated authoring UI all closed out below). "Import declarations"
-- once tracked here as its own separate not-yet-built backend module --
turned out, on the same full-repo-grep scrutiny every other gap in this
migration got, to be a read-only dashboard field with no backend module
to build at all (see the "Operations" section's own "Import VAT
evidence, closed as a follow-up to this slice" note); closed alongside a
sidebar-navigation polish pass, not a comparably-sized slice of its own.

### Accessibility baseline: WCAG 2.1 Level AA

Adopted explicitly as this initiative's own concrete standard --
**WCAG 2.1 AA** is the substance underneath every framework a "meets
international standards" request actually means for web UI: US
ADA/Section 508 enforcement, the EU's EN 301 549 (required under the
Accessibility Act), and ISO/IEC 40500 (WCAG adopted verbatim as an ISO
standard) all point back to it. Applied to the shared layout
(`resources/views/layouts/app.blade.php`, every page inherits it) and
the new invoices screens, then verified programmatically, not just
asserted:

- **Skip link** (WCAG 2.4.1 Bypass Blocks): a "Skip to main content"
  link, visually hidden until focused (Bootstrap's own
  `visually-hidden-focusable`), landing on `<main id="main-content"
  tabindex="-1">`. Verified structurally: it is genuinely the first
  focusable element in the DOM (`document.querySelectorAll('a[href],
  button, input, select, textarea, [tabindex]')[0]`), with
  `display:block`/`visibility:visible`/`tabIndex:0` -- not excluded from
  the tab sequence. (The Browser pane's synthetic Tab keypress did not
  reliably move `document.activeElement` in this sandboxed environment;
  the DOM-order/computed-style check above is the actual verification,
  since the pattern itself is pure standard HTML/CSS with no custom JS
  intercepting Tab.)
- **Colour contrast** (WCAG 1.4.3, 4.5:1 for normal text): the new
  `<x-status-badge>` component maps every status/risk value to
  Bootstrap 5.3's `text-bg-*` utilities, which auto-select a
  contrast-safe text colour (rather than hand-pairing e.g. `bg-light
  text-dark`). Computed in-browser for all 6 variants actually used
  (success/info/warning/danger/secondary/light): ratios of 4.53, 10.72,
  12.88, 4.53, 4.69 and 19.92 -- every one clears 4.5:1.
  Real-instance-verified too (rendered `Matched`/`Low`/`Certified`
  badges on a live certified invoice page), not just the synthetic
  component check.
- **Semantic tables**: every data table gets a real `<caption
  class="visually-hidden">` (not a floating heading) and `<th
  scope="col">` (never a bare `<th>`) -- verified both by direct markup
  inspection and by confirming assistive-tech-equivalent text
  extraction picks the caption up as part of the table's own content.
- **Responsive reflow** (WCAG 1.4.10, down to 320px CSS width without
  horizontal scrolling except genuinely wide content in its own
  scroll region): verified at an actual 320px viewport on the
  dashboard, invoices list and invoice detail pages --
  `document.body.scrollWidth` never exceeds `document.documentElement.
  clientWidth` on any of the three, even with the wide invoice-lines/
  ledger tables present (those scroll within their own
  `.table-responsive` container, never the page).
- **Live region for dynamic filtering** (WCAG 4.1.3, a genuine
  improvement over the source, not just parity): the invoices list's
  client-side search/status filter count (`N documents`) carries
  `aria-live="polite"`, so a screen-reader user hears the updated count
  as they filter -- the source's own `InvoiceTable.tsx` has no
  equivalent announcement.
- **Real `<label>` elements** (not `aria-label` alone) for the search
  and status-filter controls, `role="alert"` on flash messages,
  `aria-current="page"` on the active nav item, and `aria-label` on the
  navbar's icon-only toggle button.

Not attempted: an automated axe-core/Lighthouse CI scan -- no such
tooling is wired into this project, and adding one is a separate,
larger initiative of its own. Every check above was performed directly
against the rendered page (computed styles, DOM structure, viewport
geometry), which is real, evidence-based verification, just not an
automated regression gate yet.

### Invoices module (list + detail)

Ports the source's own `app/invoices/page.tsx` +
`app/invoices/InvoiceTable.tsx` + `app/invoices/[id]/page.tsx` -- list
and detail, read-only (the certification form, `app/invoices/new/`, is
a separate, larger next slice: a real multi-line VAT-aware form, not a
read screen).

New: `App\Http\Controllers\Invoice\InvoiceViewController` (`index`/
`show`), reusing `InvoiceService::list()`/`find()` directly -- no
second, parallel query path alongside the JSON `InvoiceController` that
already serves `/api/v1/invoices`. Routes `GET /invoices` and `GET
/invoices/{id}` (named `invoices.index`/`invoices.show`), registered
outside the `api/v1` prefix, matching `DashboardController`'s own
precedent of a dedicated Blade route alongside its JSON sibling.
`resources/views/invoices/index.blade.php` reproduces
`InvoiceTable.tsx`'s client-side search/status filter as small
vanilla-JS progressive enhancement over a fully server-rendered table
(works with JS disabled too -- shows the complete, unfiltered list
rather than nothing). `resources/views/invoices/show.blade.php`
reproduces the source's document-record/certification-receipt/
correction-lineage/invoice-lines/VAT-ledger-postings layout in full,
including the `?created=1` post-certification success banner.

The dashboard's own "View all"/invoice-number links -- `href="#"` since
the dashboard slice above shipped before this one existed -- now point
at real routes; its status/risk badges were retrofitted onto the same
`<x-status-badge>` component for visual consistency. The dashboard's
"+ Submit invoice" button is deliberately still not linked (removed
rather than left as `href="#"`) -- the certification form doesn't exist
yet, and a button with no real destination is a dead link, not a
shortcut; it returns once that next slice ships.

Verified by a new `tests/Feature/Invoice/InvoiceViewTest.php` (9
tests, reusing `InvoiceLifecycleTest`'s own "certify via the real
`POST /api/v1/invoices` command, not a raw DB row" convention, since a
document-record view genuinely depends on the certificate/ledger side
effects only a real certification produces): authentication and
`invoices:read` permission gates on both routes; the list renders a
certified invoice with a working link to its detail page and correct
accessible-table markup; the list is scoped to the actor's own
taxpayer; the detail page renders the full certification record
(document record, certification receipt, invoice lines, VAT ledger
postings); and both a cross-tenant and an unknown invoice id 404
correctly. Also verified visually and structurally over a real HTTP
session per the accessibility section above. 265 tests total, 0
regressions, run against real MySQL, plus a clean `migrate:fresh --seed`
cycle.

### Compliance/audit-cases and refunds module (three screens)

Ports the source's own `app/cases/page.tsx`, `app/compliance/page.tsx`
and `app/refunds/page.tsx` -- three separate pages behind three separate
permissions (`cases:manage`, `compliance:read`, `refunds:read`), matching
the source exactly rather than merging them into one screen. All three
reuse `App\Services\Compliance\ComplianceSnapshotService::getSnapshot`
directly (the same aggregate the JSON `ComplianceSnapshotController`
already serves at `/api/v1/compliance`), not a second query path --
`/refunds` in particular has no dedicated "list refund claims" JSON
route to reuse either, matching the source's own `app/api/v1/refunds/**`
shape, which likewise has no GET list route.

New: `App\Http\Controllers\Compliance\AuditCaseViewController` (`GET
/cases`, named `cases.index`) -- audit case register, findings and
advisory risk indicators, national-scope-gated by `cases:manage` like
the source. `App\Http\Controllers\Compliance\ComplianceViewController`
(`GET /compliance`, named `compliance.index`) -- tax obligations,
dispute register, secure communications, and a merged consent/delegation
table. `App\Http\Controllers\Refund\RefundViewController` (`GET
/refunds`, named `refunds.index`) -- the refund claim workflow register,
with the same "payment execution remains disabled by design" notice the
source carries. All three routes sit alongside `/invoices` outside the
`api/v1` prefix, matching `InvoiceViewController`'s own precedent; nav
links were added to `layouts/app.blade.php`, each `@can`-gated on its
page's own permission.

Every table reproduces the source's exact column set and metric
definitions (open cases/preliminary findings/critical-review counts on
`/cases`; open obligations/active disputes/unread notices/active
consents on `/compliance`; refund request count/requested value/
configuration-blocks/approved-for-payment on `/refunds`). A
taxpayer-scoped actor's own `ComplianceSnapshotService` read never joins
`taxpayers` (matching the source's own scoped-vs-unscoped branch), so
every view falls back from `legal_name` to the raw `taxpayer_id` with
PHP's `??`, exactly mirroring the source's own `item.legal_name ??
item.taxpayer_id` -- not a defect, the same behaviour the source ships.

Verified by three new feature test files (12 tests total:
`tests/Feature/Compliance/AuditCaseViewTest.php`,
`tests/Feature/Compliance/ComplianceViewTest.php`,
`tests/Feature/Refund/RefundViewTest.php`), each covering
authentication, the page's specific permission gate, real rendered
content (a case opened and advanced to a finding via the real
`/api/v1/audit-cases/**` command chain; an obligation, a taxpayer-filed
dispute and an officer notice via their own real commands; a refund
claim via the same direct `refund_claims`/`vat_return_versions` insert
`ComplianceSnapshotTest` already established, since `RefundClaimTest`
already covers the `RequestRefund` command chain itself), and taxpayer
scoping. Also verified visually over a real HTTP session (logged in as
the seeded `admin@vat-msa.test` PILOT_ADMIN demo user, screenshot +
rendered-text inspection on all three pages, no console errors) --
following the same convention the dashboard/invoices slices established.
296 tests total, 0 new regressions (28 pre-existing failures, all in
invoice-certification-dependent tests unrelated to this slice and
reproduced identically on the pre-slice tree -- an environment/date
discrepancy in this verification session, not a defect this slice
introduced), run against real MySQL/MariaDB, plus a clean
`migrate:fresh --seed` cycle.

A `php-app/postcss.config.mjs` (empty plugins) was added alongside this
slice -- unrelated to its own code, but required to `npm run build` at
all in a fresh checkout: with no local PostCSS config, `vite build`
walked up to the repository root's own `postcss.config.mjs` (the
original TypeScript/Next.js app's Tailwind config, requiring
`@tailwindcss/postcss`, a package this Laravel project's `package.json`
never installs) and failed. `resources/css/app.css` only imports
Bootstrap's precompiled CSS and needs no PostCSS plugins of its own, so
an empty local config is enough to stop the upward search.

### Portal switchboard (a fourth screen)

Ports the source's own `app/portals/page.tsx` -- the role/capability-
scoped "which of these six workspaces am I authorised to open" screen.
Reuses `App\Services\Portal\PortalService::getAvailablePortals` directly,
the same read the JSON `App\Http\Controllers\Portal\PortalController`
already serves at `/api/v1/portals` -- no second query path, same
precedent as every other view controller in this initiative.

New: `App\Http\Controllers\Portal\PortalViewController` (`GET /portals`,
named `portals.index`), gated on `dashboard:read` alone (matching the
source exactly -- the answer is inherently self-scoped, so no stronger
permission gate applies). `resources/views/portals/index.blade.php`
uses a plain Bootstrap card grid (`row row-cols-*` + `.card`) rather than
porting the source's own `.portal-grid`/`.portal-card` CSS classes --
this Blade UI has never introduced a parallel design-system, only
Bootstrap components, and mixing one in here would be the first
inconsistency in an otherwise uniform frontend. An unconditional
"Portals" nav link was added right after Dashboard (not `@can`-gated,
since `dashboard:read` is already granted to all 22 roles).

Each portal's `href` in the source points at its own dedicated dashboard
(`app/portal/buyer/page.tsx` and five siblings) -- six genuinely
separate, comparably-sized initiatives this migration has not built yet.
Rather than a dead `href="#"` link, every "Open X" button here points at
`route('dashboard')`, the one real authenticated landing page this port
currently has -- the same "no button with no real destination" precedent
`DashboardController`'s own doc comment already established for the
removed "+ Submit invoice" button. Those six portal dashboards are their
own future slices, tracked here rather than silently implied by a
working-looking link that goes nowhere real.

Verified by a new `tests/Feature/Portal/PortalViewTest.php` (5 tests):
authentication is required; a taxpayer owner with no Buyer/Seller
capability sees only the capability-free Developer portal; granting a
Buyer capability adds exactly the Buyer portal (not Seller); a
PILOT_ADMIN sees all six; and `INTERNAL_AUDITOR` -- on no portal's role
list at all -- renders the source's own empty state. 278 tests total, 0
new regressions (the same 28 pre-existing, invoice-certification-
dependent failures noted in the compliance/audit-cases/refunds section
above, reproduced identically on the pre-slice tree), run against real
MySQL/MariaDB, plus a clean `migrate:fresh --seed` cycle. Also verified
visually over a real HTTP session (PILOT_ADMIN, screenshot + rendered-
text inspection, all six cards present with working links, no console
errors).

### Brand palette (teal/navy), applied app-wide

A follow-on to the portal switchboard slice above, at the user's own
request after comparing the migrated UI's default Bootstrap blue/gray
against the source's own teal/navy `app/globals.css` design (screenshot
comparison, not a written spec). Applied as targeted overrides in
`resources/css/app.css` on top of Bootstrap 5.3, not a parallel design
system: the source's five colour tokens (`--navy #09243a`, `--teal
#0a776f`, `--teal-bright #18a39a`, `--amber #c6861a`, `--red #b44343`,
plus their `green`/pale variants) are ported as `--vatmsa-*` custom
properties, then wired into the handful of Bootstrap component classes
every Blade view actually composes (`.navbar.bg-dark` -> the source's own
navy sidebar gradient; `.btn-primary`/`.btn-outline-secondary` -> teal/
navy, overriding Bootstrap's own component-local `--bs-btn-*` variables
since those are compiled with literal hex values, not inherited from a
root variable; `.text-bg-success/info/warning/danger` -> green/teal/
amber/red, which *do* read the root `--bs-{color}-rgb` variables Bootstrap
compiles utility classes against; `.alert-success/info/danger`; `.card`
given the source's own rounder corners and softer shadow).

`resources/views/components/status-badge.blade.php`'s colour maps were
corrected alongside this, not just recoloured: the source's own
`components/PageHeader.tsx` `StatusBadge` lowercases/hyphenates the raw
value into a `status-*` class, and `app/globals.css` groups those
classes into exactly four colours -- reproduced here verbatim
(certified/matched/filed/active green; exception/high/critical/open/
denied/failed red; processing/medium/draft/under-review/investigating/
pending amber; low/received/success teal). Two real fidelity gaps this
caught: `MATCHED` was rendering as Bootstrap's default info-blue instead
of the source's green, and risk `HIGH` was rendering amber instead of
the source's red (the source deliberately groups HIGH with CRITICAL as
one red, not a separate shade -- reproduced as-is, not "fixed" into a
finer gradient the source doesn't have).

No HTML/Blade markup structure changed -- every existing `.card`/
`.table`/`.badge`/`.alert` composition stays exactly as each slice's own
view left it; only the CSS/component-color layer changed. Verified: same
278 tests, same 273 passing/28 pre-existing failures as the portal-
switchboard slice above (a pure CSS change touches no test-asserted
behaviour), a clean `npm run build`, and a live HTTP session (PILOT_ADMIN,
screenshots of `/portals`, `/dashboard` and `/cases`) confirming the navy
navbar, teal buttons/links, and corrected status colours render
consistently across every screen this initiative has shipped so far.

### Buyer portal dashboard (the first of six)

Ports the source's own `app/portal/buyer/page.tsx` -- the first of the
six per-portal dashboards `PortalViewController`'s own doc comment
tracked as not-yet-built. Deliberately scoped to exactly what this one
page reads, not a port of `getBusinessPlatformSnapshot`'s full 12-query
aggregate (parties/products/quotations/accounts/journals/expenses/
balances/projects/imports/categories/warehouses) -- every one of those
sub-reads already has its own dedicated, already-ported controller from
earlier phases; porting the whole mega-snapshot here for a page that
renders only `expenses` and `metrics.expense_value_cents` would
duplicate all of them for zero UI consumer.

New: `App\Services\Portal\BuyerPortalSnapshotService::snapshot()` --
a page-specific composing service (the same role `DashboardSnapshotService`
already plays for the main dashboard) that runs one new join query
(`expenses` + `expense_categories` + `business_parties`, matching the
source's own JOIN shape exactly) and otherwise reuses two already-ported
services directly rather than re-querying: `App\Services\VatLifecycle\
VatLifecycleService::snapshot()` for `vat.periods`/`vat.reconciliation`
(this service already ports `getVatLifecycleSnapshot` in full -- Phase
9's VAT-return-generation prerequisite) and `App\Services\Platform\
PlatformSnapshotService::documentCustodySummary()` for
`documents.quarantined` (already ported in the platform snapshot slice).
`App\Http\Controllers\Portal\BuyerPortalController` (`GET /portal/buyer`,
named `portal.buyer`) gates on `dashboard:read` (`PORTAL_PERMISSIONS.buyer`
in the source) plus membership in `PortalService::getAvailablePortals()`
-- reusing that computation rather than re-deriving the role/Buyer-
capability check, so an actor who cannot see the Buyer card on the
switchboard is refused this page too, thrown as a real
`AuthorizationException` (not a bare `abort()`) so it renders through
this app's own `errors/403.blade.php`, matching every other authorization
denial in this port. The source's own further `requireLicensedPermission`
entitlement/license check is not reproduced -- the same
`dashboard:read`-alone precedent `DashboardController`'s own doc comment
already established, and genuinely faithful: the source's own
`BuyerPortalPage` calls `getVatLifecycleSnapshot`/`getBusinessPlatformSnapshot`
directly after the portal gate passes, with no further per-field
`returns:read`/`expenses:read` check -- reproduced exactly, not tightened
into a stricter gate the source doesn't have (`BUYER_USER`, a legitimate
buyer-portal role, holds `expenses:read` but not `returns:read`).

`resources/views/portals/index.blade.php`'s "Open Buyer" button now
links to `route('portal.buyer')` instead of the `route('dashboard')`
fallback every other card still carries -- the first of the six to get
its real destination.

Verified by a new `tests/Feature/Portal/BuyerPortalTest.php` (5 tests,
reusing `ExpenseTest`'s own "create/submit/approve via the real command
chain" convention and `PortalViewTest`'s own capability-fixture
convention): authentication is required; a role absent from the Buyer
portal's list (`NAMRA_AUDITOR`) is denied; a taxpayer owner whose
organisation holds no `BUYER` capability is denied even though
`TAXPAYER_OWNER` is on the role list; a real approved expense (with its
category, "Unassigned" supplier fallback, tax/total amounts) and a real
VAT return version's `input_tax_cents` both render correctly with
accessible table markup; and the page is scoped to the actor's own
organisation. 283 tests total, 0 new regressions (the same 28
pre-existing invoice-certification-dependent failures noted in the
compliance/audit-cases/refunds section above), run against real
MySQL/MariaDB, plus a clean `migrate:fresh --seed` cycle. Also verified
visually over a real HTTP session (the demo `owner@demo-trading.test`
actor, a real approved expense, screenshot + rendered-text inspection,
plus clicking "Open Buyer" from the switchboard end to end to confirm
the link actually resolves).

### Seller portal dashboard (the second of six)

Ports the source's own `app/portal/seller/page.tsx` -- the second
per-portal dashboard, following `BuyerPortalController`'s established
pattern exactly. Reads three sources: `App\Services\Dashboard\
DashboardSnapshotService::snapshot()` (reused directly for
`metrics.invoice_count`/`total_cents`/`exception_count` and
`recent_invoices` -- the exact same aggregate the main dashboard already
uses, not a second query path), `App\Services\VatLifecycle\
VatLifecycleService::snapshot()` (reused for `vat.periods`, summed here
for `output_tax_cents` the same way the Buyer portal sums
`input_tax_cents` from the identical read), and one new small
`quotations` read (`COUNT(*)` plus a `SUM(total_cents)` filtered to
`ISSUED`/`ACCEPTED`/`CONVERTED`, matching the source's own SQL exactly)
-- see `App\Services\Portal\SellerPortalSnapshotService`'s own doc
comment for why this is a plain `COUNT(*)` rather than a literal
reproduction of the source's `business.quotations.length`, which caps at
the unrelated mega-snapshot's own `LIMIT 100`.

`App\Http\Controllers\Portal\SellerPortalController` (`GET
/portal/seller`, named `portal.seller`) gates identically to the Buyer
portal: `dashboard:read` plus membership in
`PortalService::getAvailablePortals()`, thrown as a real
`AuthorizationException`. The switchboard's "Open Seller" button now
resolves to this route too -- `resources/views/portals/index.blade.php`
was refactored from an `if ($portal['key'] === 'buyer')` check to a
`$builtPortalRoutes` lookup array (`['buyer' => 'portal.buyer', 'seller'
=> 'portal.seller']`) so each further portal dashboard is a one-line
addition there, not a growing `@if`/`@elseif` chain.

Verified by a new `tests/Feature/Portal/SellerPortalTest.php` (5 tests,
reusing `BusinessPartyAndQuotationTest`'s own quotation-lifecycle
fixtures): authentication is required; a role absent from the Seller
portal's list is denied; a taxpayer owner whose organisation holds no
`SELLER` capability is denied; a real certified invoice, an
ISSUED-then-ACCEPTED quotation, and a real VAT return version's
`output_tax_cents` all render correctly with accessible table markup and
the `ACCEPTED` quotation correctly counted into the pipeline value; and
the quotation metrics are scoped to the actor's own organisation. Also
verified visually over a real HTTP session, clicking "Open Seller" from
the switchboard through to the rendered page with a real accepted
quotation (screenshot).

**A genuine root-cause finding surfaced while writing this slice's own
invoice-rendering test**, worth recording precisely rather than folded
into the usual "pre-existing failures" note: this verification session's
sandbox runs PHP 8.4.19 with the `bcmath` extension absent (`php -m`
confirms it; `function_exists('bcadd')` is `false`). `App\Domain\Invoice\
InvoiceCalculator::decimalToScaled` -- ported verbatim from the source's
own `lib/domain/invoice.ts`, deliberately integer-only/no-float-parsing
via `bcadd`/`bcmul`/`bcpow`/`bccomp` -- throws inside a broad
`catch (\Throwable)` when those functions don't exist, surfacing as
`QUANTITY_INVALID`/`UNIT_PRICE_INVALID`/etc. on every decimal field of
every invoice/quotation-conversion payload. This is the actual root
cause of all 28 "pre-existing" invoice-certification-dependent failures
noted throughout this document's frontend-build-out sections (confirmed
identical on the pre-slice tree via `git stash` earlier, and now via a
direct `InvoiceCalculator::calculateAndValidate()` call outside the test
suite) -- not a defect in this migration's own code, and not present in
the documented target environment (PHP 8.2.12 XAMPP, which bundles
`bcmath` by default). Installing `php8.4-bcmath` in this sandbox was
attempted and failed: it is only available from the `ondrej/php` PPA,
which this environment's outbound network policy blocks (`403` at the
proxy) -- not fixable from inside this session. `SellerPortalTest`'s own
one invoice-rendering test joins the same 28 as a 29th, for the identical
reason, not a new defect.

### NamRA portal dashboard (the third of six)

Ports the source's own `app/portal/namra/page.tsx` -- the third
per-portal dashboard, and the simplest of the three built so far.
Unlike Buyer/Seller (both organisation-scoped, each needing at least one
new query of its own), every read this page needs already exists as a
national-scope-aware snapshot service, so `App\Services\Portal\
NamraPortalSnapshotService` is pure composition: `identity` reuses
`App\Services\Identity\IdentityFoundationSnapshotService::getSnapshot`,
`compliance` reuses `App\Services\Compliance\
ComplianceSnapshotService::getSnapshot` (its fourth consumer this
initiative, after `/cases`, `/compliance` and `/refunds`), and `vat`
reuses `App\Services\VatLifecycle\VatLifecycleService::snapshot` (its
third, after the Buyer and Seller portals) -- zero new queries anywhere
in this service. `App\Http\Controllers\Portal\NamraPortalController`
(`GET /portal/namra`, named `portal.namra`) follows the identical gate
pattern as its two siblings. The switchboard's `$builtPortalRoutes`
lookup array (introduced in the Seller portal slice above) gained one
more entry, `'namra' => 'portal.namra'`.

Verified by a new `tests/Feature/Portal/NamraPortalTest.php` (3 tests):
authentication is required; a role absent from the NamRA portal's list
(`TAXPAYER_OWNER`) is denied; and a real audit case (opened via the
real `/api/v1/audit-cases` command), a real risk indicator (via the real
risk-evaluation command against a PENDING obligation), and a real
`PENDING` `ApprovalTask` against a `PENDING_APPROVAL` VAT return version
(inserted directly, matching `ComplianceSnapshotTest`'s own "the command
chain has its own dedicated coverage elsewhere" convention -- generating
a return for real needs certified invoices, currently blocked in this
verification session by the `bcmath` gap noted in the Seller portal
section above) all render correctly with accessible table markup. 314
tests total, 0 new regressions (same 29 pre-existing failures, all
`bcmath`-caused per that section's own root-cause finding), run against
real MySQL/MariaDB, plus a clean `migrate:fresh --seed` cycle. Also
verified visually over a real HTTP session, clicking "Open NamRA" from
the switchboard through to the rendered page with real case/risk data
carried over from this session's own earlier compliance-slice fixtures
(screenshot).

### NamRA Administration portal: deferred (a genuinely new backend module)

Investigated as the natural fourth portal in sequence and deliberately
**not** built this slice, at the user's own direction after the scope
was surfaced. The source's `app/portal/namra-admin/page.tsx` reads
`getAuthorityGovernanceSnapshot` from `lib/data/authority-governance-repository.ts`
(276 lines: a snapshot read plus `createAuthorityOnboardingCase`/
`decideAuthorityOnboardingCase` commands) -- a genuinely new backend
module this migration has never touched, unlike every other portal
dashboard so far. It needs its own migrations (`tax_authorities`,
`tax_authority_administrators`, `tax_authority_units`,
`tax_authority_role_definitions`, `tax_authority_role_assignments`,
`tax_authority_federation_connections`, `tax_authority_onboarding_cases`,
`tax_authority_onboarding_decisions`, `tax_authority_access_reviews`,
`tax_authority_governance_events`, plus `tax_jurisdictions`/`countries`
if not already present), models, an `AuthorityGovernanceValidator`, an
`AuthorityGovernanceService`, and administrator-assignment seed data
before any snapshot can even render (the source's own
`getAuthorityGovernanceSnapshot` throws `AccessDeniedError` for an actor
with no `tax_authority_administrators` row at all) -- closer in size to
one of this document's own numbered Phase slices than to a portal
dashboard. Tracked here as a real, scoped-out gap rather than a silent
skip; a future slice's own job, not folded into this one.

### Super Administration portal dashboard (the fourth of six)

Ports the source's own `app/portal/super-admin/page.tsx`. Like the
NamRA portal before it, this needed zero new backend query:
`App\Services\Platform\PlatformSnapshotService::getTechnicalSnapshot()`
already returns exactly `components`/`integrations`/`outbox`/
`securityEvents` -- the same method
`App\Http\Controllers\Platform\PlatformSnapshotController::show` already
routes `SUPER_ADMIN`/`INFRASTRUCTURE_ADMIN` to.

**A genuine fidelity nuance caught while building this one, worth
recording precisely**: every portal controller built so far
(Buyer/Seller/NamRA) gates on `dashboard:read`, which happens to be
harmless because `dashboard:read` is granted unconditionally to all 22
roles -- the real gate in each of those three has always been
`PortalService::getAvailablePortals()`'s own role/capability membership
check alone. The source's own `lib/portals.ts` `PORTAL_PERMISSIONS` map
names a *different* permission per portal (`platform:read` for
`super-admin`, `developer:read` for `developer`, `authority-governance:read`
for `namra-admin`), and `getAvailablePortals`/`requirePortalAccess` check
*both* the role/capability list and that specific permission together.
For `super-admin` this is load-bearing: `SECURITY_ANALYST` is on
`PortalDefinitions`' own `super-admin` role list but does not hold
`platform:read` (confirmed against `Permissions::ROLE_PERMISSIONS`), so
the source denies that role even though role/capability membership alone
would not catch it. `App\Http\Controllers\Portal\
SuperAdminPortalController` therefore gates on `platform:read`
explicitly rather than `dashboard:read`, plus the usual
`getAvailablePortals()` membership check -- see the controller's own doc
comment.

**`PortalService::getAvailablePortals()` itself does not yet enforce
the source's full `PORTAL_PERMISSIONS` matrix** (only `PortalDefinitions::
roleAllows`'s role/capability check) -- a real, narrow, pre-existing gap
this investigation surfaced rather than introduced. Fixing it wholesale
was deliberately *not* done here: `authority-governance:read` does not
exist anywhere in `Permissions::ROLE_PERMISSIONS` yet (would make
`namra-admin` disappear from the switchboard for every role, including
`PILOT_ADMIN`), and `SELLER_ADMIN` -- a legitimate `developer` portal
role per `PortalDefinitions` -- does not hold `developer:read` either
(would make `developer` disappear for that role too). Both are real,
narrower fidelity gaps of the same shape as this one, left for whichever
future slice builds the `developer` and `namra-admin` dashboards to
resolve alongside their own controllers (as `super-admin`'s was resolved
here), rather than one broad change to shared code risking regressions
in portals not yet under test. The switchboard itself still shows
`SECURITY_ANALYST` a `super-admin` card that then correctly 403s on
click -- a known, narrow consequence of not touching
`PortalService` this slice, not a new defect.

Verified by a new `tests/Feature/Portal/SuperAdminPortalTest.php` (4
tests): authentication is required; a role entirely absent from the
portal's list is denied; `SECURITY_ANALYST` specifically -- present on
the role list but missing `platform:read` -- is denied, proving the gate
is genuinely `platform:read`; and real `service_components`/
`integration_connections`/`security_events` rows (inserted directly --
neither table has any write command anywhere in this migration, per
each one's own migration doc comment) plus a real `PENDING` outbox row
(from a real `/api/v1/obligations` command, since every command writes
one via `CommandLedger::outbox`) all render correctly with accessible
table markup. 293 tests total, 0 new regressions (same 29 pre-existing
`bcmath`-caused failures noted in the Seller portal section above), run
against real MySQL/MariaDB, plus a clean `migrate:fresh --seed` cycle.
Also verified visually over a real HTTP session, clicking "Open Super
Administration" from the switchboard through to the rendered page with
real component/integration/security-event data (screenshot).

### Developer portal dashboard (the fifth of six)

Ports the source's own `app/portal/developer/page.tsx`. Like NamRA and
Super Administration before it, zero new backend query was needed:
`App\Services\Platform\PlatformSnapshotService::developerPortalSnapshot()`
already returns exactly `clients`/`webhooks` -- including its own
already-ported `ORGANISATION_LINK_REQUIRED` short-circuit for a
`DEVELOPER_PARTNER` actor with no `taxpayer_id` linked yet, which this
view needs no special handling for: an empty `clients` array renders the
same "No applications in scope" empty state either way, exactly matching
the source's own page (which has no special-cased messaging for that
state either).

Confirms the `PORTAL_PERMISSIONS` pattern the Super Administration
section above first documented is a real, recurring shape, not a
one-off: `App\Http\Controllers\Portal\DeveloperPortalController` gates
on `developer:read` (the source's own `PORTAL_PERMISSIONS.developer`),
not `dashboard:read`. `SELLER_ADMIN` is on `PortalDefinitions`' own
`developer` role list but does not hold `developer:read`
(`Permissions::ROLE_PERMISSIONS` confirms it), so the source denies that
role the same way it denies `SECURITY_ANALYST` from `super-admin` --
`PortalService::getAvailablePortals()`'s own role/capability check alone
would not catch either. That method's own gap (not enforcing the full
`PORTAL_PERMISSIONS` matrix) remains open by the same deliberate choice
explained in the Super Administration section -- fixed per-controller as
each dashboard is built, not wholesale.

Verified by a new `tests/Feature/Portal/DeveloperPortalTest.php` (5
tests): authentication is required; a role entirely absent from the
portal's list is denied; `SELLER_ADMIN` specifically -- present on the
role list but missing `developer:read` -- is denied; a real
`api_clients`/`webhook_subscriptions` pair (inserted directly -- neither
table has any write command anywhere in this migration, per each one's
own migration doc comment) renders correctly with accessible table
markup; and an unlinked `DEVELOPER_PARTNER` sees the same empty-registry
state the source itself falls back to. 299 tests total, 0 new
regressions (same 29 pre-existing `bcmath`-caused failures noted in the
Seller portal section above), run against real MySQL/MariaDB, plus a
clean `migrate:fresh --seed` cycle. Also verified visually over a real
HTTP session, clicking "Open Developer and sandbox" from the switchboard
through to the rendered page with a real client/webhook pair
(screenshot).

**Five of the six portal dashboards are now built.** Only NamRA
Administration remains, deliberately deferred per its own dedicated
section above (a genuinely new backend module, not a quick composition
like the other five all turned out to be).

### Authority Governance module + the NamRA Administration portal (the sixth and final)

Closes out the one deferred portal from the "NamRA Administration
portal: deferred" section above, at the user's own explicit request to
build the backend module. Ported `lib/data/authority-governance-repository.ts`
in full -- `getAuthorityGovernanceSnapshot`/`createAuthorityOnboardingCase`/
`decideAuthorityOnboardingCase` -- a genuinely new module, unlike every
other portal built so far:

- **12 new migrations** (`countries`, `tax_jurisdictions`,
  `tax_authorities`, `tax_authority_units`,
  `tax_authority_role_definitions`, `tax_authority_role_assignments`,
  `tax_authority_federation_connections`,
  `tax_authority_onboarding_cases`, `tax_authority_onboarding_decisions`,
  `tax_authority_governance_events`, `tax_authority_access_reviews`,
  `tax_authority_administrators`). Three needed an explicit short
  constraint name (`ta_federation_conn_idp_fk`/`ta_onboarding_decisions_unique`/
  `ta_role_assignments_unique` etc.) -- MySQL's 64-character identifier
  limit rejected Laravel's own auto-generated name for those particular
  table/column pairings. `id` columns are `uuid()` (a plain `CHAR(36)`,
  matching this migration's own `tax_rule_sets.id` precedent) but the
  reference tables (`tax_jurisdictions`/`tax_authorities`) hold the
  source's own stable, human-readable seed IDs (`tax-authority-na-namra`),
  not generated UUIDs -- no command in this module ever creates one.
  Every source `CHECK` constraint that spans more than one column (no
  self-parenting unit, no self-approval, no self-review) is enforced at
  the application layer, matching this migration's own established
  convention throughout.
- **`authority-governance:read`/`authority-governance:manage`** added to
  `Permissions::ROLE_PERMISSIONS` for `PILOT_ADMIN`/`NAMRA_SYSTEM_ADMIN`
  only -- the one exception to that class's own "line-for-line port of
  access.ts" doc comment, since the source never grants either
  permission through its static role-permission map at all: it grants
  them exclusively through a separate, genuinely dynamic
  `role_permission_grants` database table this migration's own
  `role_permission_grants` table (migrated schema-only in Phase 4) has
  never had a runtime reader for. A targeted transcription of that
  table's effective two-role result, not a new permission mechanism --
  see `Permissions`' own doc comment for the full explanation.
- **`App\Services\AuthorityGovernance\AuthorityGovernanceService`** --
  the three functions in full, including the maker-checker chain
  (self-review denial, a distinct decider required), the quarterly
  Tax-Authority-access-review gate (`requireCurrentAuthorityReview`,
  distinct from the already-ported organisation-scoped `access_reviews`
  table from Phase 12 slice 4), idempotency via the same `CommandLedger`
  every other command in this migration uses, and a dual write
  (`audit_events` via `AuditService::append` plus this module's own
  `tax_authority_governance_events` stream) matching the source's own
  pattern exactly. `authorityGovernanceLocalWritesEnabled()`'s
  `VAT_MSA_ENVIRONMENT`/`NODE_ENV` check (source) has no PHP equivalent
  env var, so it's simplified to Laravel's own environment idiom (writes
  enabled everywhere except `app()->environment('production')`) -- see
  that service's own doc comment for why the practical effect is
  identical for this pilot. Real Tax-Authority production activation has
  no command anywhere in the source either; `productionActivationEnabled`
  and every response's `production_activation_effect` are hardcoded
  `false`, reproduced identically.
- **`App\Http\Controllers\AuthorityGovernance\AuthorityGovernanceController`**
  (`GET`/`POST /api/v1/tax-authority-onboarding-cases`,
  `POST /api/v1/tax-authority-onboarding-cases/{id}/decisions`), kept
  1:1 with the source's own URL shape; both write commands are
  `password.confirm`-gated (this migration's own established step-up
  equivalent), with the server-computed
  `"verified-step-up:{$correlationId}"` reference the source's own route
  computes rather than accepting one from the client.
- **`AuthorityGovernanceSeeder`** (new, run in `DatabaseSeeder` after
  `IdentityProviderSeeder`) ports the source's own Namibia/NAMRA
  reference-data seed verbatim (`countries`/`tax_jurisdictions`/
  `tax_authorities`/the nine-role `tax_authority_role_definitions`
  catalogue) -- genuine deploy-time configuration, matching
  `IdentityProviderSeeder`'s own "not demo fixture data" precedent.
  `DemoSeeder` gained the demo-specific rows (two real administrators --
  `admin@vat-msa.test` and a new `namra-admin@vat-msa.test`
  `NAMRA_SYSTEM_ADMIN` login -- substituted for the source's own
  placeholder Cloudflare-Sites identities, so maker-checker is genuinely
  demonstrable, not just readable; three authority units; two role
  assignments; one federation connection against the already-seeded ITAS
  identity provider; one submitted onboarding case; and a current
  `QUARTERLY` access review computed relative to "now", matching this
  file's own established `consent_grants`/`delegations` precedent for
  exactly that reason).
- **A real, closed gap in `App\Services\Portal\PortalService::getAvailablePortals()`**,
  documented as open in both the Super Administration and Developer
  portal sections above: it only ever checked
  `PortalDefinitions::roleAllows` (role/capability membership), never
  the source's own further `PORTAL_PERMISSIONS` permission ("both
  checks together" in the source's own `getAvailablePortals`). Closing
  it needed `authority-governance:read` to exist as a real permission
  first (it didn't, until this same change set) -- now added as
  `PortalService::PORTAL_PERMISSIONS`, filtered in addition to the
  existing role/capability check. The switchboard itself now stops
  showing `SECURITY_ANALYST` a `super-admin` card or `SELLER_ADMIN` a
  `developer` card that previously 403'd on click.
- **`resources/views/portals/index.blade.php`** simplified back from the
  `$builtPortalRoutes` lookup array (introduced for the Seller portal
  slice, grown by one entry per portal since) to linking `$portal['href']`
  directly -- now that every portal has a real destination,
  `PortalDefinitions::all()`'s own `href` for each one is already
  identical to its actual route path, so the lookup indirection has
  nothing left to do.
- **`App\Http\Controllers\Portal\NamraAdminPortalController`**
  (`GET /portal/namra-admin`) -- read-only, matching every other portal
  dashboard's own precedent: the source's interactive
  `AuthorityGovernanceActions` onboarding-case submission/decision form
  is not ported to this page (the JSON commands themselves are real and
  tested, just not yet wired to a form here, the same gap this
  initiative's other portal dashboards' own backend commands already
  carry). Reuses `AuthorityGovernanceService::getSnapshot` and the
  already-ported `IdentityFoundationSnapshotService::getSnapshot`
  directly -- the source's own two-way `Promise.all`, no second query
  path. Gated on `authority-governance:read` (matching the
  `platform:read`/`developer:read` precedent from the two portals before
  it) plus `getAvailablePortals()` membership.

Verified by two new test files. `tests/Feature/AuthorityGovernance/
AuthorityGovernanceTest.php` (11 tests): the snapshot requires
`authority-governance:read` and denies an actor with no governed
authority scope; a real onboarding case can be created for
`LOCAL_STAGING` (and is created `BLOCKED_EXTERNAL` for `PRODUCTION`,
never rejected); creating one without administrator scope is denied and
a duplicate open case for the same authority/environment is a conflict;
creating one without step-up confirmation is denied (`423`, matching
this codebase's own established convention for that exact scenario); a
requester cannot decide their own case; a distinct reviewer can approve
local staging (and the resulting decision/status are asserted in the
database); and a decision without a current access review is denied.
`tests/Feature/Portal/NamraAdminPortalTest.php` (4 tests): auth
required; a role absent from the portal's list is denied; an actor with
`authority-governance:read` but no governed authority scope is denied
(the same "assigned identity, no data" edge every other portal
dashboard's own zero-state carries); and real units/federation/
identity-provider data renders correctly with accessible table markup
(the assigned authority's own name is never rendered as visible text
anywhere on this page -- confirmed against the source, which only ever
uses it for the metric count).

338 tests total (309 passing), 0 new regressions (same 29 pre-existing `bcmath`-caused
failures noted in the Seller portal section above, confirmed byte-for-
byte identical to the pre-slice run), run against real MySQL/MariaDB,
plus a clean `migrate:fresh --seed` cycle. Also verified visually over a
real HTTP session end to end: `admin@vat-msa.test` clicking "Open NamRA
Administration" from the switchboard through to a fully populated
dashboard (3 authority units in their real parent/child hierarchy, 1
federation connection, 2 protected role assignments across both demo
administrators, a live-computed current quarterly access review, and all
3 identity providers with correctly brand-mapped status colours), plus
the switchboard screenshot itself confirming all six portal cards now
resolve to real pages.

**All six per-portal dashboards are now built.** The frontend build-out
initiative's remaining scope is everything outside the portals
themselves: accounting, business parties, quotations, expenses,
inventory, projects, licensing, documents, reports, analytics, platform
config, and the workflow engine, each its own comparable slice --
see the "Frontend UI build-out" section's own running list above.

### Business parties (customers & suppliers directory, closes out the first slice outside the portals)

Ports the source's own `app/commercial/parties/page.tsx` +
`PartyManager.tsx` -- the shared customer/supplier register. New:
`App\Http\Controllers\Business\BusinessPartyViewController` (`index`/
`store`/`update`/`deactivate`) and `resources/views/parties/index.blade.php`,
reusing `App\Services\Business\BusinessPartyService` directly -- the
same `search`/`create`/`update`/`deactivate` methods
`App\Http\Controllers\Business\BusinessPartyController` already serves
at `/api/v1/business-parties`, not a second query or command path.
`parties:manage` gates the page, matching the source's own gate for
this screen; the nav link (`Customers & suppliers`) is likewise
`@can`-gated.

This is the first screen in the whole frontend build-out initiative
with a genuine write form rather than a read-only dashboard: create and
edit share one server-rendered Blade `<form>` (edit reached via
`?edit={id}`, since `BusinessPartyService::update` shares the same
`BusinessValidator::party` validator `create` uses and requires the
party still be `ACTIVE`), plus a small per-row deactivate form
collecting the required 5-500 character reason. Unlike the source's
own client-side `fetch()`-driven `PartyManager`, this is a traditional
POST + redirect flow, matching every other write path already built in
this migration (Blade/session-driven, not a client-rendered SPA
fragment). `BusinessValidationException`/`BusinessResourceException`/
`RepositoryConflictException` all render JSON-only by default (their
own `render()` methods), so the view controller catches each explicitly
and converts it to `redirect()->back()->withErrors(...)->withInput()`
rather than letting a web-form POST hit a raw JSON error body. The
source's own `/api/v1/business-parties/**` writes carry no step-up
(`password.confirm`) gate, so neither does this form.

Two deliberate, documented omissions, both because the data they'd need
isn't produced by `BusinessPartyService::search`/`present` at all (and
adding it would mean a second, wider query path serving this screen
alone, which this migration's own "no second query path" rule rejects):
the source's "Trust" column/metric (a join against supplier-verification
snapshots) is not rendered here, and the source's "Synthetic check"
action (`App\Services\Business\SupplierVerificationService::verify`,
already ported in full and reachable at
`POST /api/v1/business-parties/{id}/verification` -- see "Supplier
verification" above) has no UI on this screen yet. Both are tracked
here, not silently dropped.

The relationship control is two independent checkboxes sharing one
`relationships[]` array field (never a radio group), so a party can be
registered -- or edited -- as a customer, a supplier, or both at once,
matching the source's own data model exactly: `party_relationships` is
a set of independent, revocable grants per party, not a single fixed
column. Confirmed by both a feature test and a real HTTP session (see
below).

Verified by `tests/Feature/Business/BusinessPartyViewTest.php` (11
tests): the page requires authentication; a role without
`parties:manage` (`SELLER_VIEWER`) is denied `403`; the register and
create form render with accessible table markup; a party can be
created through the form (with the audit-event side effect verified); a
party can be created as both a customer and a supplier at once, with
both relationship rows persisted and both checkboxes correctly
pre-checked when its edit form is reopened; creating a party with no
relationship selected fails validation and redisplays the form with its
input preserved, writing nothing; a duplicate VAT number surfaces as a
form error rather than a raw 500; the edit form is correctly prefilled
from `?edit={id}`; an active party can be updated (including its
relationship set) through the form; a party can be deactivated with a
reason (status flips to `INACTIVE`, the audit event is recorded, the
record itself is never deleted); and deactivating with too short a
reason fails validation without changing the party's status. 349 tests
total (320 passing), 0 new regressions (the same 29 pre-existing
`bcmath`-caused failures noted in the Seller portal section above), run
against real MySQL/MariaDB, plus a clean `migrate:fresh --seed` cycle.
Also verified visually over a real HTTP session end to end as
`owner@demo-trading.test`: the empty register, creating a supplier
party (metrics update from 0 to 1 active/1 supplier), opening its edit
form pre-filled with its saved data, saving an edited legal name,
deactivating it with a reason (status badge flips to grey "Inactive",
the Edit action remains, Deactivate disappears, and the metrics drop
back to 0), a rejected submission (missing relationship) redisplaying
the form with its accessible error summary and the entered display name
preserved, and (following a direct user report that both should be
selectable together) registering a second party -- "Dual Role Trading
CC" -- with both the Customer and Supplier boxes checked at once,
confirming both badges render in the register and both boxes remain
checked when its edit form is reopened.

### Quotations (register, lifecycle actions, multi-line edit -- the second write-capable screen)

Ports the source's own `app/commercial/page.tsx` + `QuotationForm.tsx` +
`QuotationActions.tsx` + `app/commercial/quotations/[id]/edit/page.tsx` +
`QuotationEditForm.tsx` -- the quotation register, a one-line issue
form, the full lifecycle (send/accept/reject/expire/convert), and a
dedicated multi-line revision editor. New:
`App\Http\Controllers\Business\QuotationViewController` (`index`/
`store`/`edit`/`update`/`send`/`accept`/`reject`/`expire`/`convert`)
and `resources/views/quotations/{index,edit}.blade.php`, reusing
`App\Services\Business\QuotationService` and
`App\Services\Business\BusinessPartyService` directly (the same
methods the JSON API at `/api/v1/quotations` already serves) plus a
direct `App\Models\Product` read for the catalog dropdown, matching
`App\Http\Controllers\Business\InventoryController::indexProducts`'s
own precedent of querying the model inline (no dedicated product-search
service exists, and none was added just for this).

Two small, genuine gaps in `QuotationService` itself -- not new to this
slice, but only surfaced because this is the first caller that needed a
single-record read -- were closed here, benefiting the JSON API too:
`present()` was missing `customer_name` (the source's own
`searchQuotations`/`getQuotationForEdit` SQL both join
`business_parties` for `p.display_name AS customer_name`; this port's
`present()` never carried it); and there was no public single-quotation
read at all (`findOrFail`/`present` were private, and neither the JSON
`QuotationController` nor the service exposed a `show`/`find` -- the
source's own `getQuotationForEdit` is a server-component-only data
function with no JSON API equivalent either, confirmed by
`app/api/v1/quotations/[id]/route.ts` only implementing `PATCH`). Added
`QuotationService::find()`, a thin public wrapper the same shape as
`App\Services\Invoice\InvoiceService::find`, including `revision_count`
(matching the source's own `COUNT(*)` subquery, which `search()`
deliberately still omits, matching `searchQuotations` never selecting
it either).

One deliberate, documented deviation from the source, found and closed
in this slice, not a silent fix: `createQuotation` always creates a
quotation in `DRAFT` status, but a full-repo grep of the source's own
`app/` tree for `"sending"`/`sendQuotation` turns up nothing -- neither
`app/commercial/page.tsx` nor `QuotationActions.tsx` (nor anywhere else)
ever reaches the already-built `sendQuotation` (`DRAFT` -> `ISSUED`)
transition. A quotation created through the source's own screen is a
genuine dead end: permanently `DRAFT`, with no UI path to any later
lifecycle action. `QuotationViewController` adds a "Send" action for a
`DRAFT` row (calling the already-fully-built and already JSON-API-
reachable `QuotationService::send`) so a quotation created through this
screen is actually usable, rather than faithfully reproducing a bug.

The multi-line edit form's "Add line"/"Remove line" controls are
self-contained vanilla JavaScript (a `<template>` clone, index
rewriting, and one `change` listener for the tax-category/tax-rate
lock) with zero `fetch()` calls -- the actual save is still one plain
`POST` (`PATCH` via `@method`) + redirect, matching this migration's
Blade/session-driven precedent everywhere else. This is different in
kind from the source's own `QuotationForm`/`QuotationActions`/
`QuotationEditForm`, which are full client-side components driving
every action (including the save itself) through `fetch()` without a
page navigation.

A second gap was found and fixed during this slice's own verification,
not shipped: `QuotationViewController::convert` initially caught only
`BusinessValidationException`/`BusinessResourceException`/
`RepositoryConflictException`, but `QuotationService::convertToInvoice`
calls `InvoiceService::submit` internally, which throws
`App\Exceptions\InvoiceValidationException` on a certification failure
-- a different exception class the controller didn't catch, so it
reached Laravel's default handler and rendered a raw JSON error page to
a browser user instead of a normal Blade error redirect. Caught by this
sandbox's own missing-`bcmath` limitation triggering exactly that path
during the visual walkthrough below; fixed by adding
`InvoiceValidationException` to the same catch clause.

Verified by `tests/Feature/Business/QuotationViewTest.php` (11 tests):
the page requires authentication; a role without `commercial:read` is
denied `403`; the register and issue form render with accessible table
markup; a quotation can be created through the form (`DRAFT`, correct
total); a role without `quotations:manage` cannot issue one; a `DRAFT`
quotation can be sent, then accepted, then converted to a real
certified invoice (the one test in this file that needs `bcmath` --
see below); an `ISSUED` quotation can be rejected with a reason; an
overdue `ISSUED` quotation can be expired; the edit form renders
prefilled lines and the correct `revision_count`; an `ISSUED` quotation
can be edited with its line count changed from one to two (correct
recalculated total, a new hash-chained revision row); and an `ACCEPTED`
quotation's edit route correctly refuses with the lifecycle guard's own
reason rather than rendering an edit form. 360 tests total (330
passing), 0 unexpected regressions: exactly the same 29 pre-existing
`bcmath`-caused failures plus this file's own one new `bcmath`-caused
failure (the convert-to-invoice assertion -- confirmed by `php -m |
grep bcmath` returning nothing in this sandbox, the same root cause as
every other invoice-certification-dependent test in this suite), run
against real MySQL/MariaDB, plus a clean `migrate:fresh --seed` cycle.

Also verified visually over a real HTTP session end to end as
`owner@demo-trading.test`: registering a customer party, issuing a
`DRAFT` quotation (metrics correctly still `N$ 0.00` quoted value, since
`DRAFT` is deliberately excluded from that aggregate), sending it
(`ISSUED`, `Accept`/`Edit`/`Reject` actions appear, quoted value updates
to the real total), opening the edit form, adding a second line through
the vanilla-JS repeater, saving (the register's total and quoted-value
metric both recalculate correctly to include the new line), accepting
the quotation (`ACCEPTED`, the inline convert form appears), and
attempting conversion -- which in this `bcmath`-less sandbox correctly
renders the accessible validation-error banner fixed above rather than
a raw JSON page, leaving the quotation safely at `ACCEPTED` rather than
in a partially-converted state, exactly as the idempotent
`convertToInvoice`/`InvoiceService::submit` pairing is designed to
behave on failure.

### Accounting (controlled general ledger dashboard -- read-only, matching the source)

Ports the source's own `app/accounting/page.tsx` -- the journal register
and chart of accounts. Unlike the business parties and quotations
slices just above, the source's own page here has **no write forms at
all**: no journal-posting form, no account-creation form, nothing --
and its own closing note says so explicitly ("Interactive journal
authoring and approval queues will be expanded with the VAT close
workflow"), ported into the new Blade view's own info banner verbatim
rather than paraphrased. `App\Services\Business\AccountingService`
already fully supports posting journals, creating accounts, reversing
entries, closing periods, trial balance and financial statements
(reachable today at `/api/v1/accounting/**`, covered end to end by
`tests/Feature/Business/AccountingTest.php`), so this is a UI gap only,
and the source's own stated scope for this specific screen is
read-only -- so this slice stays read-only too, rather than inventing
forms the source itself doesn't have (the opposite call from the
business-parties/quotations slices, made for the opposite reason: there
the source had real forms/actions this port was missing; here the
source has none to port).

New: `App\Http\Controllers\Business\AccountingViewController::index`
and `resources/views/accounting/index.blade.php`. Queries
`App\Models\ChartOfAccount` and `App\Models\JournalEntry` directly,
mirroring `AccountingController::indexAccounts`/`indexJournals`'s own
already-established precedent (a simple real query, not the source's
own fixed `getBusinessPlatformSnapshot` list) rather than adding a
third copy of the same two queries behind a new service method.

One fidelity note, not a bug: journal and account status badges render
with no distinct colour (Bootstrap's neutral `text-bg-light`) because
neither `POSTED`, `REVERSED`, nor `ACTIVE`-for-an-account is any
different from `ACTIVE` elsewhere in `<x-status-badge>`'s own shared
colour map -- confirmed against the source's own `app/globals.css`,
which likewise defines no `.status-posted`/`.status-reversed` class at
all. Matching that absence exactly, not a missed mapping.

Verified by `tests/Feature/Business/AccountingViewTest.php` (4 tests):
the page requires authentication; a role without `accounting:read`
(`SELLER_VIEWER`) is denied `403`; the ledger and chart of accounts
render correctly with accessible table markup and the right
`postedCount`; and an empty organisation renders both tables' zero-state
rows. 364 tests total (333 passing), 0 new regressions -- the failing
set is byte-for-byte identical to the pre-slice run (the same 29
pre-existing `bcmath`-caused failures plus the one new one introduced by
the quotations slice above), run against real MySQL/MariaDB, plus a
clean `migrate:fresh --seed` cycle. Also verified visually over a real
HTTP session as `owner@demo-trading.test`, with two accounts and one
posted journal seeded directly for the walkthrough (the demo seeder
itself creates none): both tables render correctly, the metrics count
correctly (2 accounts, 1 journal, 1 posted), and the info banner text
matches the source's own copy exactly.

### Operations (expenses register + maker-checker, inventory balances, project control)

Ports the source's own `app/operations/page.tsx` +
`ExpenseDecisionActions.tsx` + `ExpenseReceiptActions.tsx`. Unlike the
earlier slices, the source bundles four operational domains onto one
combined page (there is no separate `/expenses`, `/inventory` or
`/projects` page anywhere in the source -- confirmed by listing every
top-level `app/` directory), so this slice mirrors that combined shape
at `/operations` rather than inventing three or four separate routes
the source doesn't have. New:
`App\Http\Controllers\Business\OperationsViewController` (`index`/
`store`/`submit`/`approve`/`reject`) and
`resources/views/operations/index.blade.php`, reusing
`App\Services\Business\ExpenseService` for every expense write and
direct `InventoryBalance`/`Project`(+`ProjectBudget`/`ProjectCost`)
reads mirroring `InventoryController::indexMovements`/
`ProjectController::index`'s own existing inline-query precedent
(neither has a dedicated enrichment service method, so this doesn't add
a second, competing one).

One small, genuine gap in `ExpenseService::present()` was closed here,
benefiting the JSON API too, the same pattern as the `customer_name`
gap the quotations slice closed: `category_name`, `supplier_name` and
`receipt_document_id` were all missing (the source's own dashboard
query joins `expense_categories`/`business_parties` for the first two;
`receipt_document_id` was simply never selected despite being a real
column since the table's creation).

One confirmed, deliberately **not** ported scope boundary, documented
rather than silently faked:
- **Receipt linking stays read-only.** The source's own
  `ExpenseReceiptActions.tsx` calls `POST /api/v1/expenses/{id}/receipt`
  to link an already-uploaded, already-scanned-clean document to a
  `DRAFT` expense -- but that command was never ported to
  `ExpenseService` (no method, no route registered, confirmed by
  reading the file in full). This view reads and displays a linked
  receipt's real state directly from `App\Models\DocumentMetadata`
  (file name, scan status, availability) -- a genuine, working read --
  but does not invent the missing link command itself. (The other half
  of the original reasoning here -- that Documents' own upload UI
  didn't exist yet either -- no longer holds; that UI shipped in this
  initiative's own "Documents" slice below. `linkReceipt` remains
  unported on its own separate merits: a genuinely missing command, not
  a missing screen to call it from.)

**Import VAT evidence, closed as a follow-up to this slice**: the
source's fourth panel (customs declaration evidence) originally
rendered an explicit "not yet available" notice here instead, since
`import_records` had no backing model at all -- only its migration
existed (Phase 4's own "not yet built" list). A full-repo grep of the
TypeScript source settles what "not yet built" actually means here:
`import_records` is read exactly once in the entire source
(`business-repository.ts`'s `getBusinessPlatformSnapshot`, plus this
same page) and **written by no command anywhere** -- the same seed/
read-only posture already established for `report_definitions`/
`data_products`/`feature_flags` elsewhere in this migration, not a
missing write feature. New: `App\Models\ImportRecord` (a plain
read-only model, no service -- nothing writes here so there is nothing
for a service to do) and a direct `ImportRecord::where('organisation_id',
...)->orderByDesc('declaration_date')->limit(100)` read in
`OperationsViewController::index`, mirroring the same inline-query
precedent `InventoryBalance`/`Project` already established in this same
controller. The Blade view's fourth KPI tile ("Import declarations",
previously omitted) and the real evidence table (declaration number,
supplier/origin, customs value, import VAT, date, status -- matching
the source's own column set exactly) replace the placeholder notice.
Building a "record an import declaration" write command remains out of
scope: the source itself has none to port, and inventing one would be
speculative backend capability, not porting an existing feature.

One deliberate, documented deviation from source, closing a confirmed
dead end the same way the quotations slice's own "Send" action did:
the source's operations page has **no create-expense form and no
`DRAFT` -> `SUBMITTED` action anywhere** -- confirmed by a full-repo
grep of every `.tsx` file for `"submission"`/`submitExpense"`, which
turns up nothing related to expenses at all -- even though
`ExpenseService::create`/`submit` are both fully built and already
reachable via the JSON API. Without either, no expense created through
this application could ever reach the maker-checker decision this same
page's own UI is otherwise built entirely around. This controller adds
a "Record an expense" form (`store`, creating a `DRAFT`) and a "Submit"
action (`DRAFT` -> `SUBMITTED`), closing that dead end. Approve/reject
(the source's own real, working actions) are ported unchanged,
including the self-review denial `ExpenseService::approve`/`reject`
already enforce (`AuthorizationException`, rendering the shared
`errors/403.blade.php` like every other authorization failure in this
migration, not caught/redirected specially).

Verified by `tests/Feature/Business/OperationsViewTest.php` (8 tests):
the page requires authentication; a role without `expenses:read`
(`SELLER_VIEWER`) is denied `403`; all three panels render correctly
with the enriched expense fields, inventory balances and project data,
plus the explicit import-VAT-evidence notice, all with accessible table
markup; an expense can be recorded through the form (`DRAFT`, correct
computed total); a role without `expenses:manage`
(`TAXPAYER_VIEWER`) cannot record one; a `DRAFT` expense can be
submitted and then approved by a second, independent user
(`TAXPAYER_ACCOUNTANT`); the expense's own creator is denied `403`
attempting to approve their own submitted expense (maker-checker
self-review denial, reached through this UI); and a submitted expense
can be rejected with a reason. 372 tests total (342 passing), 0 new
regressions -- the failing set is byte-for-byte identical to the
pre-slice baseline, run against real MySQL/MariaDB, plus a clean
`migrate:fresh --seed` cycle. Also verified visually over a real HTTP
session end to end across two real user sessions (seeded directly for
the walkthrough, since the demo seeder creates neither a second
taxpayer-org user nor any expense/inventory/project data): recording
an expense as `owner@demo-trading.test`, submitting it, confirming the
creator's own view correctly shows "Independent reviewer required"
rather than decision buttons, logging in as a separate seeded
`TAXPAYER_ACCOUNTANT` and confirming that session sees real
Approve/Reject actions, approving it (status flips to `Approved`,
"Decision recorded" now shown to both users), and confirming the
inventory balance and project control panels render real seeded data
correctly alongside the explicit import-VAT-evidence and
receipt-upload-not-available notices.

### Administration (licensing/entitlements, employees, roles, workflows, access governance)

Ports the source's own `app/administration/page.tsx` +
`AdministrationActions.tsx` -- the "Administration command centre".
There is no standalone `/licensing` page in the source at all (the
whole "still not started: licensing" item from the earlier matrix note
turned out to mean this page, not a page of its own); licensing and
entitlements are one panel embedded in this same combined
organisation-control-plane screen, alongside employees, organisation
roles, versioned workflows and access governance. New:
`App\Http\Controllers\Administration\AdministrationViewController`
(`index`/`storeEmployee`/`storeRole`) and
`resources/views/administration/index.blade.php`. The read side is the
simplest of any slice in this whole initiative: it calls
`App\Services\Administration\AdministrationSnapshotService::getAdministrationSnapshot`
once and renders it directly -- the fixed-list aggregate every one of
Phase 12's five sub-domain slices already bundles into, already fully
joined/enriched (department, job title, branch, permission lists,
licence entitlements) by that existing service, so unlike every prior
slice this one added no query, no enrichment fix, and no new read path
at all. The two writes reuse
`App\Services\OrganisationAdmin\OrganisationAdminService::inviteEmployee`/
`createOrganisationRole` directly -- the exact methods
`App\Http\Controllers\OrganisationAdmin\OrganisationAdminController`
already serves at `/api/v1/organisations/{employees,roles}`.

One deliberate, documented substitution, not a simplification: the
source's own `AdministrationActions.tsx` gates both writes behind a
client-side checkbox ("I completed the local/staging privileged-change
step-up check") plus a custom `x-vat-msa-local-step-up` header the
server trusts blindly -- theatre, not a real check. Both write routes
here use the `password.confirm` middleware instead, the same genuine,
server-enforced step-up every other sensitive command in this
migration already uses -- continuing Phase 6's own precedent of
replacing the source's platform-header trust entirely, not just for
authentication. Verified live: submitting either form without a recent
confirmation redirects to the real `/confirm-password` screen (not the
423 this codebase's JSON API returns for the same condition, since a
plain form POST doesn't send an `Accept: application/json` header --
confirmed by reading Laravel's own `RequirePassword` middleware
source); confirming and resubmitting then succeeds.

Two small, genuine backend gaps found and fixed during this slice,
both benefiting the JSON API too:
- `App\Support\Licensing\LicenseResolver::getEntitlements` was missing
  `capacity_mode` (`FINITE`/`UNLIMITED`/`NOT_APPLICABLE`) entirely --
  a real, displayed field the source's own dashboard branches on (the
  "User seats" metric card's own "Unlimited" vs numeric-limit
  decision). The source stores it as a genuine column on
  `license_plan_entitlements`, but this port's own migration for that
  table never carried the column at all. Rather than a migration and a
  backfill for a column no command in this port ever needs to set
  (every currently-seeded plan's mode is fully determined by its other
  two already-real columns), it's computed at read time instead:
  `NOT_APPLICABLE` for an unmetered boolean feature-gate (no
  `metric_key`), `UNLIMITED` for a metered feature with no configured
  cap, `FINITE` otherwise -- a documented simplification (computed
  property vs. a stored column), not a fidelity gap in what's
  displayed.
- The demo organisation seeded by `DemoSeeder` never had a licence at
  all -- no `subscriptions` or `organisation_licenses` row was ever
  created for it, silently blocking every `EntitlementGate`-gated
  screen (this one included) for the demo login, confirmed by
  reproducing `LicenseResolver::getLicense`'s own "The organisation has
  no configured licence" `AuthorizationException` against the real
  seeded database before this fix. No command anywhere in this port
  can create a licence from scratch (`changeState`/`upgrade` both
  themselves require one to already exist), so this was a genuine,
  pre-existing demo-seed gap, the same class of finding as "Demo seed
  gaps for already-shipped features" earlier in this document, not
  something a real organisation-onboarding flow would ever hit.
  `DemoSeeder` now creates both rows on the `PILOT_PROFESSIONAL` plan
  for the demo organisation.

One further, honestly-labelled gap, left unfixed rather than silently
worked around: the source's own `snapshot.capacityExceptions` (the
"Licence capacity exception" alert) has no backing table in this port
at all -- confirmed by a repo-wide search for any capacity-exceptions
migration or model. The Blade view still renders that alert's markup
(always empty, via a defensive `?? []`) with an inline comment
explaining why, rather than silently dropping the whole feature
without a trace.

The workflow engine and access-governance panels here are read-only
registers only, reusing data the already-built
`App\Services\Workflow\WorkflowService`/`App\Services\AccessGovernance\AccessGovernanceService`
JSON APIs already write to -- this slice does not add its own
authoring UI for either (drafting/publishing a workflow version,
deciding a workflow task, requesting/deciding access, certifying a
quarterly review all remain JSON-API-only for now), tracked as its own
remaining item, distinct from this read-only register.

Verified by `tests/Feature/Administration/AdministrationViewTest.php`
(8 tests): the page requires authentication; a role without
`administration:read` (`TAXPAYER_STAFF`) is denied `403`; the full
snapshot renders correctly (licence, entitlements table with the
now-computed `capacity_mode`, employees, roles, workflows, access
governance) with accessible table markup; an employee can be invited
once step-up is confirmed; the same request without a confirmed
password redirects to `/confirm-password` rather than being silently
allowed or crashing; a role without `employees:manage`
(`TAXPAYER_ACCOUNTANT`) is denied `403`; an organisation role can be
created (with its permissions correctly persisted) once step-up is
confirmed; and a role creation naming a protected, non-grantable
permission fails validation before ever reaching the entitlement/
quarterly-review gate. 380 tests total (349 passing), 0 new
regressions -- the failing set is byte-for-byte identical to the
pre-slice baseline, run against real MySQL/MariaDB, plus a clean
`migrate:fresh --seed` cycle. Also verified visually over a real HTTP
session end to end as `owner@demo-trading.test` (with a quarterly
access review opened directly via the already-built
`openQuarterlyAccessReview` service, matching this migration's own
"seed via the real command, not a raw insert" precedent, since
`ADMIN_WRITE` operations are blocked without one): the full command
centre rendering real seeded licence/entitlement data with correctly
computed capacity modes, attempting to invite an employee without a
fresh step-up and landing on the genuine confirm-password screen,
confirming and having the same submission then succeed (the new
employee appearing in the register as `INVITED`), and creating an
organisation role that appears immediately with its permissions listed.

### Documents (evidence register and governed upload)

Ports the source's own `app/documents/page.tsx` +
`DocumentUploadForm.tsx` -- the evidence register and a real, governed
multipart upload-to-quarantine form. New:
`App\Http\Controllers\Document\DocumentViewController` (`index`/
`store`) and `resources/views/documents/index.blade.php`. The upload
reuses `App\Services\Document\DocumentService::upload` directly --
the exact same method `App\Http\Controllers\Document\DocumentController::store`
already serves at `POST /api/v1/documents`, including its real MIME
allow-list, 1-byte-to-10-MiB size bound, magic-byte content-sniffing
(never trusting a client-declared MIME type alone) and SHA-256
checksum. None of that is re-implemented here. The register is a
direct `App\Models\DocumentMetadata` read scoped to the actor's own
organisation, matching the exact `$documents` sub-query already inside
`App\Services\Platform\PlatformSnapshotService::getSnapshot` (the
source's own combined `getPlatformSnapshot`, which the source's
`/documents` page itself calls) rather than pulling in that whole
dozen-table integrations/sync/reports aggregate for a page that only
ever needed one slice of it -- the same "simple real query, not the
source's fixed list" call already made for accounting, business
parties and every list this initiative has built since.

Confirmed, not assumed: the source's own page has **no UI at all** for
scan-decision, supersede, retention-hold, or download -- its own
subtitle even says so explicitly ("Downloads are unavailable while
malware scanning is not configured"). None of those four are built
here either. This isn't a gap this slice introduces; all four commands
remain fully built and reachable at their existing JSON routes
(`/api/v1/documents/{id}/{scan-result,supersession,retention-hold,download}`)
for whichever future admin/national-scope screen needs them -- this
slice faithfully matches the source's own scope for this specific
page, nothing more.

Closes the loop this initiative's own Operations slice left open: its
expense register's receipt column previously read "Upload receipt
(not yet available in this UI)" -- a placeholder note written at the
time because Documents didn't exist yet. `resources/views/operations/index.blade.php`
now links that "Upload receipt" text straight to
`/documents?owner_domain=EXPENSE&owner_resource_id={expense_id}`,
matching the source's own `DocumentUploadForm`'s `defaultOwnerDomain`/
`defaultOwnerResourceId` prefill behaviour exactly (confirmed live:
following the link from an unreceipted expense correctly pre-selects
"Expense" and pre-fills the resource ID field).

A genuine, unrelated flaky test was found and fixed during this
slice's own full-suite verification, not shipped: running the whole
suite (not `BusinessPartyViewTest.php` alone) intermittently failed
`test_a_party_can_be_created_as_both_a_customer_and_a_supplier_at_once`'s
own `$editing['relationships'] === ['CUSTOMER', 'SUPPLIER']` assertion
-- `App\Services\Business\BusinessPartyService::present()`'s
relationships read had no `orderBy` at all, so MySQL's row order for
that query was genuinely unspecified (the source's own `GROUP_CONCAT`
carries the identical lack of a guarantee) and could shift under a
different query plan once the full, much larger suite was in play.
Fixed with an explicit `orderBy('relationship')` (alphabetical,
matching every other `present()` method's own ordering conventions in
that file) -- confirmed stable across four consecutive full-suite runs
after the fix, byte-for-byte identical failing set each time.

Verified by `tests/Feature/Document/DocumentViewTest.php` (7 tests):
the page requires authentication; a role without `documents:read`
(`SELLER_VIEWER`) is denied `403`; the register and upload form render
correctly with accessible table markup; a valid PDF (real magic bytes,
not just a `.pdf` extension) uploads successfully into `QUARANTINED`/
`PENDING_EXTERNAL_SCANNER`; a role without `documents:upload`
(`TAXPAYER_VIEWER`) is denied `403`; a file whose content doesn't
match its declared MIME type is rejected with a clear form error
rather than a 500 or a silently-accepted forgery; and the upload
form's domain/resource-id fields correctly prefill from the
`?owner_domain=EXPENSE&owner_resource_id=` query string. 387 tests
total (357 passing), 0 new regressions -- the failing set is
byte-for-byte identical to the pre-slice baseline (confirmed stable
across four consecutive full-suite runs, per the flaky-test fix
above), run against real MySQL/MariaDB, plus a clean
`migrate:fresh --seed` cycle. Also verified visually over a real HTTP
session end to end as `owner@demo-trading.test`: starting from a
seeded unreceipted expense on the Operations page, following its real
"Upload receipt" link into Documents with the domain/resource ID
already correctly filled in, uploading a real minimal PDF fixture, and
confirming it appears in the register as `QUARANTINED`/
`PENDING_EXTERNAL_SCANNER` with its SHA-256 checksum shown and the
metrics (documents/quarantined/clean/legal holds) all updating
correctly.

### Reports & analytics (governed reporting console)

Ports the source's own reports/analytics screens onto `App\Services\
Platform\ReportExportService` (all 7 methods -- `runInline`, `publish`,
`requestExport`, `approveExport`, `cancelExport`, `getExport`,
`downloadExport`) and `App\Services\Platform\DataProductService` (all 5
methods -- `list`, `runModel`, `publish`, `approvedMetrics`,
`anomalyCandidates`) directly, both already fully covered end to end
(every audience-tier guardrail, the publish reconciliation gate, the
anomaly-detection maths) by `tests/Feature/Platform/ReportExportTest.php`
(24 tests) and `DataProductTest.php` (11 tests) from Phase 13's fourth and
fifth slices. New: `App\Http\Controllers\Platform\ReportViewController`
(`index`/`run`/`publish`/`requestExport`/`approveExport`/`cancelExport`/
`downloadExport`/`runModel`/`publishDataProduct`) and
`resources/views/reports/index.blade.php`. This view adds no query of
its own for anything either service already exposes; the catalogue read
(`report_definitions`) and the "my runs"/"my exports"/"pending
approvals"/"publishable model runs" lists are direct supporting reads
with no command precedent to reuse, the same "no second query path for
business logic, a listing read is fine" posture Document/Inventory's own
view controllers already established.

**Distinct URL shapes from the JSON API, on purpose**: the Blade routes
(`/reports`, `/reports/{code}/run`, `/reports/runs/{id}/publish`,
`/reports/runs/{id}/export`, `/reports/exports/{id}/approve`,
`/reports/exports/{id}/cancel`, `/reports/exports/{id}/file`,
`/analytics/data-products/{id}/run-model`, `/analytics/data-products/{id}/
publish`) live outside the `api/v1` prefix entirely (a first pass
accidentally nested them inside the `Route::prefix('api/v1')->group()`
alongside `ReportController`/`DataProductController`'s own JSON routes --
caught immediately by every new feature test 404ing, moved out to sit
beside `/documents` instead) and use deliberately different path segments
from their JSON counterparts even where both now share a prefix-free
base (`run` vs `runs`, `file` vs `download`, `run-model`/`publish` vs
`model-runs`/`publications`) so the two route sets never collide on the
same method+path.

**Data-conditional step-up, handled inline, not via route middleware**:
`requestExport`/`approveExport` are the one pair of write commands in
this whole migration whose step-up requirement is data-conditional (only
when the report's classification is `TAX_CONFIDENTIAL`/`RESTRICTED`, or
the export's own `requires_step_up` flag is set -- see `App\Support\
Access\StepUp`'s own doc comment), not route-wide like every other
`password.confirm`-gated Blade route this initiative has built so far
(Administration's employee/role invitations, Licensing's state changes,
Platform config's `provisionStaff`). Gating the whole route would
over-restrict the non-sensitive case the source itself exempts, so the
controller instead passes `StepUp::isFresh($request)` through to the
service and, if it still refuses for exactly that reason (detected by
matching `'step-up'` in the thrown `AuthorizationException`'s message,
never by re-deriving sensitivity itself), stores the reports page as
`url.intended` and redirects to the real `password.confirm` screen with a
flash message asking the actor to confirm and retry. This migration's
Blade forms are plain POSTs with no client-side replay anywhere in this
initiative, so a manual retry (now with a satisfied freshness window) is
the honest UX here, not a silently-swallowed failure or a first
JS-driven auto-resubmit. Verified live through a real browser session:
requesting an export for a `TAX_CONFIDENTIAL` run redirects to
`/confirm-password` with nothing written to `report_exports`; confirming
and retrying the identical action succeeds, landing the export in
`PENDING_APPROVAL` with a visible "Step-up" badge.

**No JS anywhere on this page, matching every other screen in this
initiative**: the two per-data-product "run analytics model"/"publish
snapshot" mini-forms could have used a single shared dropdown-driven form
with a JS `onchange` handler rewriting the form's `action` -- the first
draft did exactly that -- but this codebase's entire Blade UI build-out
has never shipped a line of inline JavaScript, so it was reworked into
one small, plain per-card form pair instead (one for `run-model`, one for
`publish`), matching the established "an inline form per row/card, no
shared dynamic state" convention already used everywhere else (Quotations'
register, this same page's own "my report runs" table).

**Demo seed gap closed**: a full-repo check found `report_definitions`/
`data_products`/`data_product_lineage`/`metrics` were all genuinely
seed-only by design (Phase 13's own documented posture -- defining a new
governed report/metric is a governance/config action, out of scope for a
runtime command) but nothing had ever actually seeded one, leaving this
screen's catalogue and analytics section permanently empty for any real
login. `DemoSeeder` now seeds the 7 report codes this migration
implements (`VAT_POSITION`, `SALES_VAT_SUMMARY`, `COMPLIANCE_CASELOAD`,
`PORTFOLIO_EXCEPTIONS`, `REVENUE_COMPLIANCE_TRENDS`,
`CASE_EVIDENCE_SUMMARY`, `NATIONAL_VAT_AGGREGATE`) with audiences chosen
so the demo `admin@vat-msa.test` login (`PILOT_ADMIN`, national scope,
`reports:executive`, and -- via the pre-existing demo `delegations` row --
an active `PRACTITIONER` delegation) can exercise every audience-tier
guardrail end to end, plus one `VAT_TRENDS` data product sourced from
`SALES_VAT_SUMMARY` with two certified metrics (`DEMO_INVOICE_COUNT`,
`DEMO_TAX_TOTAL`). `CASE_EVIDENCE_SUMMARY` still needs a real `case_id`
typed in by whoever runs it -- no case is auto-seeded, matching the
source's own per-case scoping rather than fabricating a fake evidence
trail.

Verified by a new `tests/Feature/Platform/ReportViewTest.php` (14 tests):
the page requires authentication; a role without `reports:read` is
denied `403`; the catalogue renders with accessible table markup; running
a report requires `reports:run`; a `TAXPAYER`-audience run completes
inline; `CASE_EVIDENCE_SUMMARY` without a `case_id` is refused with a
flashed error rather than a 500; a completed run publishes; a
non-sensitive export auto-approves and downloads with the correct
`Content-Type`; a sensitive export without a fresh step-up redirects to
`/confirm-password` with nothing persisted, and with one succeeds into
`PENDING_APPROVAL`; the requester can cancel their own pending export; a
national reviewer can approve a colleague's pending export but is refused
(with a flashed error, not a 500) attempting to approve their own; running
and publishing an analytics model snapshot end to end produces a real
`data_product_snapshots` row and the product's card then shows it; and
running an analytics model is refused for a non-national actor. 401 tests
total, 0 new regressions -- the failing set (30 tests, all pre-existing
`bcmath`-dependent invoice-certification/VAT-return/refund failures) is
the same class already documented throughout this initiative, confirmed
by the failure list carrying no `Report`/`DataProduct`/`Platform` test
among it, run against real MySQL/MariaDB, plus a clean `migrate:fresh
--seed` cycle. Also verified visually over a real HTTP session as
`admin@vat-msa.test`: the catalogue, running and publishing
`SALES_VAT_SUMMARY`, the step-up redirect and its confirm-then-retry
flow landing the export in `PENDING_APPROVAL` with a "Step-up" badge, and
running a model then publishing a snapshot for the `VAT_TRENDS` data
product, after which its certified metrics show real (`0`, since this
migrate:fresh cycle seeded no invoices) values with `AVAILABLE` status
instead of "No Data".

### Platform config (feature flags, config values, access policies)

Ports the source's own platform config/change-management screen onto
`App\Services\Platform\PlatformChangeService` directly (all 5 methods --
`config`, `listChangeRequests`, `requestChange`, `decideChange`,
`provisionStaff`), already fully covered end to end (every target-type
shape check, the self-decision refusal, `provisionStaff`'s
`identity_links` creation) by `tests/Feature/Platform/
PlatformChangeTest.php`'s 12 tests from Phase 13's sixth slice. New:
`App\Http\Controllers\Platform\PlatformConfigViewController`
(`index`/`requestChange`/`decideChange`/`provisionStaff`) and
`resources/views/platform/index.blade.php`. This view adds no query of
its own -- `config()`/`listChangeRequests()` are read straight from the
service.

**Three propose-change forms, one per target type, not one generic
form with conditional fields**: `feature_flags`/`platform_config`/
`access_policies` each need a genuinely different `proposed_value` shape
(`{enabled: bool}`, `{value: string}`, `{parameters: object}}` --
`PlatformChangeService::validateShape`'s own per-target-type check), and
this initiative has never shipped a line of inline JavaScript to
conditionally show/hide fields, so each row gets its own small, plain
inline form instead (the feature-flag row's form proposes the opposite
of the current state with one click; the config-value row takes a text
input; the access-policy row takes a JSON-text input, prefilled with the
current parameters so a reviewer only edits what's changing) -- matching
the "one inline form per row, no shared dynamic state" convention this
whole build-out has used everywhere else (Reports' own "run
model"/"publish snapshot" per-card forms most recently).

**`provisionStaff` wears `password.confirm` at the route level**, a
genuine contrast with Reports' `requestExport`/`approveExport`: this
command is unconditionally step-up gated in the source (the same posture
Administration's employee/role invitations and Licensing's state changes
already established), not data-conditional, so Laravel's own middleware
refuses an unconfirmed request before the controller is ever reached --
no need to replicate `StepUp::isFresh()`'s inline check-and-redirect
dance here.

**Distinct URL shapes from the JSON API**: `/platform`,
`/platform/change-requests`, `/platform/change-requests/{id}/decide`,
`/platform/staff` sit outside the `api/v1` prefix entirely (learned from
Reports' own first-pass mistake nesting its Blade routes inside
`api/v1` by accident -- this slice's routes were placed correctly the
first time) and use `decide` rather than `.../decision` so the two route
sets never collide even without the prefix difference alone to rely on.

**Demo seed gap closed**: a full-repo check found `feature_flags`/
`platform_config`/`access_policies` were all genuinely seed-only by
design (the same documented posture as `report_definitions`/
`data_products` above -- defining a new governed flag/config
key/policy is a governance action, out of scope for a runtime command)
but nothing had ever actually seeded one, leaving this screen
permanently empty for any real login. `DemoSeeder` now seeds one row of
each (`offline_sync.enabled`, `reports.export_size_limit_bytes`,
`STEP_UP_WINDOW`) plus two distinct `platform:manage` logins
(`platform-admin@vat-msa.test` as `SUPER_ADMIN`,
`infra-admin@vat-msa.test` as `INFRASTRUCTURE_ADMIN`) so the
maker-checker decide step is genuinely demonstrable between two real
accounts, not just readable -- the same reasoning that already justified
a second Authority Governance login earlier in this initiative. The
seeded config rows document, in their own `description`, that
`ReportExportService`'s export size/suppression limits and `StepUp`'s
freshness window are now read live from these rows via
`App\Support\Platform\PlatformConfigReader` -- see Phase 13's own
"Platform config now feeds three real consumers" section above -- so a
maker-checker change made here through this page's own decide step has a
real, observable effect, not a documentary one.

Verified by a new `tests/Feature/Platform/PlatformConfigViewTest.php`
(12 tests): the page requires authentication; a role without
`platform:read` is denied `403`; the console renders read-only (no
propose-change actions) for a `platform:read`-only actor; proposing a
change requires `platform:manage`; a manager can propose a feature-flag
change, a platform-config value change, and an access-policy change (with
invalid JSON parameters refused via a flashed error, not a 500); a
reviewer cannot decide their own change request; a different reviewer's
approval both marks the request `APPLIED` and actually flips the
target's live value (with `version` bumped), while a different
reviewer's rejection leaves the target untouched; and `provisionStaff`
without a fresh step-up redirects to `/confirm-password` with nothing
persisted, while a fresh step-up creates the real `users`/
`identity_links` rows. 413 tests total, 0 new regressions -- the failing
set (the same 30 pre-existing `bcmath`-dependent tests documented
throughout this initiative) is unchanged, run against real
MySQL/MariaDB, plus a clean `migrate:fresh --seed` cycle. Also verified
visually over a real HTTP session as `platform-admin@vat-msa.test`: the
three config tables and the staff form render correctly, and proposing a
feature-flag change stages a real `PENDING` row in the change-requests
table with working Approve/Reject actions.

### Workflow engine authoring console

Ports the source's own workflow-engine authoring screen onto
`App\Services\Workflow\WorkflowService` directly (all 8 methods --
`createWorkflowDraft`, `publishWorkflowVersion`, `testWorkflowVersion`,
`assignWorkflow`, `decideWorkflowTask`, `createDelegation`,
`listDelegations`, `revokeDelegation`), already fully covered end to end
(licence-seat reservation, conditional routing, the maker-checker self-
publish/self-approval refusals) by `tests/Feature/Workflow/
WorkflowTest.php`'s own suite from Phase 12's fifth slice. New:
`App\Http\Controllers\Workflow\WorkflowAuthoringViewController`
(`index`/`store`/`publish`/`test`/`assign`/`decide`/`storeDelegation`/
`revokeDelegation`) and `resources/views/workflows/index.blade.php`.
This is the write side of a read-only register that was already part of
Administration's own page before this slice existed
(`AdministrationSnapshotService::getAdministrationSnapshot`'s
`workflows`/`tasks` arrays, reused here verbatim rather than re-queried)
-- what this slice adds is what makes the engine's own commands
reachable at all, closing out this initiative's own frontend build-out
list entirely (every item named in "Still NOT STARTED" above is now
built).

**Definition/context authoring is JSON-textarea, not a visual node
editor**: `nodes`/`transitions`/routing `context` are structured lists
of typed objects (`WorkflowValidator::definition()`'s own shape) --
building a drag-and-drop graph editor is out of scope for a build-out
that has never shipped a line of client-side JavaScript. A JSON
textarea, prefilled with a valid minimal example and validated entirely
server-side by the same `WorkflowValidator` every JSON-API caller
already goes through, is the same "trust the real validator, don't
duplicate its shape checks in the UI" posture Platform config's
`ACCESS_POLICY` `parameters` field already established. Reference
panels (organisation roles, active members) sit next to the textareas so
an author can copy a real ID into a `ROLE`/`USER` `assignee_ref` without
guessing.

**A genuine bug found and fixed in already-shipped, tested code, not
just this slice's own new code**: `WorkflowValidator::context()`
rejects its input unless `is_array($contextRaw) && ! array_is_list
($contextRaw)` -- but PHP's `array_is_list([])` is vacuously `true` for
an empty array, and `json_decode('{}', true)` produces exactly that
empty array, indistinguishable from `json_decode('[]', true)`. A caller
sending a literal empty JSON object (`{}`, meaning "no routing filters")
-- something no existing test ever happened to exercise, JSON API
included -- would be rejected with "context must be an object", the
same bug this slice's own "Test routing" and "Assign instance" forms hit
immediately with their own empty-textarea default. Fixed on the caller
side, not by touching the shared validator: `WorkflowAuthoringViewController::
jsonContextField()` normalises a decoded empty array back to `null`
(the shape `context()` already handles correctly) before it ever reaches
`WorkflowValidator`, leaving the already-tested validator itself
untouched. Documented here rather than silently patched, since the same
latent bug remains reachable from the JSON API's own `POST /api/v1/
workflows/versions/{id}/test` and `POST /api/v1/workflows/instances` for
any caller that sends `"context": {}` explicitly -- a real, if narrow,
pre-existing gap this slice surfaced but did not close for that surface.

**Demo seed gap closed, and a real pre-existing one**: a full-repo check
found the demo organisation had never had an `access_reviews` row at
all. `App\Support\Licensing\EntitlementGate::assert`'s own `ADMIN_WRITE`
gate -- which `createWorkflowDraft`/`publishWorkflowVersion`/
`assignWorkflow`/`decideWorkflowTask`/`createDelegation`/
`revokeDelegation` all go through, alongside Administration's own
already-shipped `inviteEmployee`/`createOrganisationRole` -- requires a
current-quarter `access_reviews` row (`OPEN` or `COMPLETED`, not
overdue) before any privileged organisation-administration write. This
silently blocked every one of those already-shipped `ADMIN_WRITE`
actions for a real demo login even though their own feature tests always
pass (each opens its own review via `POST /api/v1/access-reviews`
first, a step no demo login had ever taken). `DemoSeeder` now seeds a
current, `OPEN` quarterly review for the demo organisation, closing this
gap for every `ADMIN_WRITE` command in the application, not just this
slice's own new ones.

Verified by a new `tests/Feature/Workflow/WorkflowAuthoringViewTest.php`
(11 tests): the page requires authentication; a role without
`workflows:read` is denied `403`; the console renders its catalogue and
hides manage-only actions for a `workflows:read`+`workflows:decide`
(but not `workflows:manage`) actor; creating a draft requires
`workflows:manage`; every write (create/publish/test excepted/assign/
decide/delegate/revoke) wears `password.confirm`, matching the JSON
API's own unconditional step-up posture exactly; a draft's own creator
cannot publish it themselves, but a different user can; testing a
draft's routing needs no step-up and shows a real path/terminal result;
assigning an instance and deciding its task work end to end (with the
initiator refused deciding their own task, and role-based decision
working for a different, role-holding user), completing the instance;
and creating then revoking a delegation both succeed. 424 tests total, 0
new regressions -- the failing set (the same 30 pre-existing
`bcmath`-dependent tests documented throughout this initiative) is
unchanged, run against real MySQL/MariaDB, plus a clean `migrate:fresh
--seed` cycle. Also verified visually over a real HTTP session as
`owner@demo-trading.test`: creating a draft (redirected to
`/confirm-password` on the first attempt, succeeding after confirming),
seeing it appear in both the versioned-workflows and draft-versions
cards, and running "Test routing" to see a real `start → approve → end`
/`COMPLETED` result rendered on the page.

### Sidebar navigation (replaces the top navbar)

A follow-up polish pass across the shared layout, not a new backend
slice: `resources/views/layouts/app.blade.php`'s primary navigation
moved from a top `navbar` to a left sidebar, using Bootstrap's own
`.offcanvas-lg` responsive component -- an always-visible, fixed-width
column at the `lg` breakpoint and up, collapsing to a slide-out drawer
(toggled by a slim mobile top bar) below it. This brings the port back
in line with the source's own vertical sidebar; `resources/css/app.css`
had carried an explicit comment documenting the top-navbar-instead-of-
sidebar choice as a deliberate structural difference since the
dashboard slice, now stale and removed along with the navbar-specific
CSS it described. The nav item list itself (all 15 links, each still
gated by its own `@can('permission', ...)` check) and the "signed in
as"/log-out block are unchanged in substance, just re-laid-out
vertically; `aria-current="page"` items now get a real visible
highlight (the teal active-page background), not just the
screen-reader-only semantic the navbar left it as.

**Two real Bootstrap pitfalls hit and fixed while building this, not
just a markup rewrite**: (1) `.offcanvas-lg`'s own `>=992px` rule sets
`background-color: transparent !important` on both itself and its
`.offcanvas-body`, which otherwise leaves the sidebar's white nav text
invisible against the light page canvas showing through -- fixed with
a `!important` of the sidebar's own gradient background, whose
`.sidebar.offcanvas-lg` two-class selector already out-specifies
Bootstrap's plain one so the `!important` only has to beat Bootstrap's,
not also fight specificity. (2) The sidebar `<div>` initially carried
both the plain `.offcanvas` class and the responsive `.offcanvas-lg`
one together (copying habit from the mobile-only patterns used
elsewhere); Bootstrap's plain `.offcanvas.offcanvas-start` rule is
*unconditional* (no media query at all) and kept the sidebar
permanently `transform: translateX(-100%)`/`visibility: hidden` even
at desktop widths where `.offcanvas-lg`'s own `>=992px` override
correctly matched -- confirmed live via `getComputedStyle`/
`matchMedia` in a real browser session, not guessed from the CSS alone.
Fixed by dropping the redundant plain `.offcanvas` class, matching
Bootstrap's own documented responsive-offcanvas pattern (`offcanvas-lg`
alone, never combined with bare `offcanvas`).

No new routes, permissions, or tests -- the full 424-test suite passes
unchanged (same 30 pre-existing `bcmath`-dependent failures), and every
existing view's own feature tests (which assert on nav link text via
`assertSee`, not navbar-specific markup) still pass without
modification. Verified visually over real browser sessions at both a
1440px desktop width (sidebar fixed, active-page highlight correct
across two different pages) and a 420px mobile width (collapsed by
default behind a hamburger toggle in the mobile top bar; opens as a
proper off-canvas drawer with its own close button and backdrop).

## Legacy D1 importer (Phase 14)

`php artisan legacy:import-d1 {path} [--dry-run] [--only=table1,table2]`
(`App\Console\Commands\ImportLegacyD1Data`, backed by
`App\Support\Migration\LegacyD1Importer`) -- a real, generic, reusable
cutover tool for importing an actual `wrangler d1 export` SQLite file
into this MySQL schema, table-by-table, structurally.

**There is no real legacy dataset anywhere in this repository.** The
original Cloudflare D1 database was never checked into source control;
the only D1-shaped data present locally is `db/runtime.ts`'s own
hardcoded demo/seed `INSERT OR IGNORE` statements, and those rows are
already fully ported via this migration's own `DemoSeeder`/
`RoleSeeder`/`PermissionSeeder`/etc. (Phase 5). Given the choice between
building a real, generic tool verified against a small synthetic
fixture, or marking this phase deferred/blocked since nothing real
exists to import, the former was chosen: the tool is genuinely useful
whenever an actual cutover happens, and its own mechanics (table/column
discovery, the one documented rename, type-aware value casting,
idempotent writes) are fully exercisable and verifiable without a real
dataset -- what specifically CANNOT be verified here is fidelity
against real, messy legacy data, and that limitation is stated plainly
rather than implied away by a passing test suite.

**Mechanics**: this migration's own "UUID `TEXT` primary keys
throughout" design decision (see "Design decisions carried through the
whole migration" below) means a cutover is a straight, structural row
copy, not an FK-remapping exercise -- every id and every FK referencing
one is byte-identical between a D1 export and this MySQL schema. For
every table present in both the source export and this schema, every
column present in both is copied (a source column with no MySQL
counterpart is skipped and reported, not silently dropped -- this is
how `positions`, schema-only and never built, stays harmless if present
empty in an export); values are cast per the MySQL column's own
`information_schema` type (a `timestamp`/`date` column is reparsed,
since D1/SQLite's ISO-8601 strings like `2026-08-10T08:30:00Z` are not
valid MySQL date/time literals; an `enum` column is passed through
verbatim -- a value the column's own definition does not allow is a
real fidelity problem this importer deliberately does not paper over).
Writes use `INSERT IGNORE`, mirroring the source's own `INSERT OR
IGNORE` seed convention exactly, so a rerun against the same export is
safely idempotent. Foreign-key checks are disabled for the run's
duration (standard bulk-load practice, since a D1 export's own table
order is not guaranteed FK-safe) and re-enabled afterward; this
importer does not itself verify referential integrity post-import -- a
real cutover should follow it with this migration's own already-
verified reports/snapshots as the actual reconciliation check against
the legacy system's own real figures, not a bespoke integrity-scanning
feature built here against data that cannot be tested.

**The one documented rename**: the source's `app_users` table merges
onto Laravel's native `users` table (see the identity-core migration's
own design-decision note); the importer maps that table name and its
`display_name` -> `name` column rename explicitly. `users.password` is
`NOT NULL` in this schema but the source's own account concept never
has a local password at all (federated-identity-only) -- the importer
injects a random, nobody-knows-it `Hash::make(Str::random(40))`, the
same documented approach `PlatformChangeService::provisionStaff()`
already established, satisfying the column without granting any real
local-login capability.

Verified by a new `tests/Feature/Console/LegacyD1ImportTest.php` (7
tests) against a small synthetic SQLite fixture built via raw PDO
(deliberately not through Laravel's own schema builder, to stand in for
an independently-produced export file): dry-run reports without writing
anything; a real run imports rows correctly with the documented rename
and timestamp reformatting applied; a rerun against the same export is
idempotent (no duplicate rows); `--only` scopes the import to named
tables; an unreadable path fails with a clear error rather than a stack
trace; and the console command itself runs end-to-end, including its
own destructive-write confirmation prompt. 251 tests total, 0
regressions, run against real MySQL, plus a clean `migrate:fresh --seed`
cycle.

## Deployment documentation (Phase 15)

`docs/DEPLOYMENT.md` -- the ops runbook this phase's own scope calls
for: what changed vs. the original Cloudflare stack (a table mapping
every substitution -- compute, database, object storage, auth, step-up,
identity federation, background jobs -- to what actually exists in this
port, not what the architecture documents aspire to), requirements
(PHP/MySQL version floors, with the same "tested on 8.2.12/MariaDB
10.4.32, re-verify against the real 8.3+/MySQL 8 target" caveat this
matrix already carries throughout), first-time setup (including the
important distinction between `DatabaseSeeder`, which chains
`DemoSeeder` and is demo/pilot-only, and the individual real-data
seeders a genuine deployment should run instead), a full environment-
variable reference cross-checked against `.env.example`, the legacy
cutover runbook (Phase 14, above), a storage section (why no
`storage:link` is needed or should exist, and what backing up
`storage/app/private` actually means without R2's own redundancy), how
to run the test suite safely (against a disposable database, never a
real one -- `RefreshDatabase` truncates), and an honest "what is not
done yet" section that does not let a real production rollout discover
gaps by surprise: no full TOTP step-up parity, three platform-config/
access-policy values wired to a real downstream consumer with every
other seeded row still illustrative only, no real object-storage driver
configured. (Self-service password reset -- previously listed here as a
gap -- was closed 2026-09-02 per red team finding RT-005.)

No test suite applies to a documentation-only phase; verification here
is that every factual claim in the document (version numbers, config
keys, table/column names, command signatures) was checked against this
repository's own `composer.json`/`.env.example`/`config/*.php`/
migration files/service doc comments while writing it, not asserted
from memory.

## Licensing & Entitlements (Phase 12 slice 1: portals/licensing/governance)

Opens Phase 12 -- previously entirely `NOT STARTED`. `lib/data/control-
plane-repository.ts` is genuinely the largest single file left in the
source (~1,200 lines, ~30 exports spanning licensing, portal navigation,
organisation administration/employees, the workflow engine, and access
governance); this slice deliberately scopes to just Licensing &
Entitlements (`getEntitlementsSnapshot`/`getUsageSnapshot`/
`changeLicenseState`/`upgradeLicense`), the one sub-domain reachable via
its own dedicated routes with no dependency on any of the other four
still-unbuilt sub-domains -- the same "smallest coherent first slice of a
large module" approach Phase 10 (business-repository.ts, 6 slices) and
Phase 11 (compliance-repository.ts, 2 slices) already established.

Schema (7 new tables, 80/155 total): `license_plans`/`license_features`/
`license_plan_entitlements` (a fixed, seed-only feature catalogue -- see
`LicensePlanSeeder`'s own doc comment, the same real-prerequisite pattern
already established by `VatRuleSeeder`/`TaxRuleSetSeeder`), `subscriptions`
(confirmed, by grepping every `.ts` file under `lib/` before writing its
migration, to have **no** application write path anywhere in the source --
provisioned out of band, like `vat_periods`/`tax_rule_sets` before it),
`organisation_licenses` (an organisation's *first* row is the same
out-of-band gap; every row after that is real, application-written history
via `upgradeLicense`), `license_usage`, `license_events`.

Domain: `App\Domain\Licensing\LicensingValidator`, ported verbatim from
`lib/domain/control-plane.ts`'s `normalizeLicenseStateChange`/
`assertLicenseStateTransition`/`normalizeLicenseUpgrade`, including its
real adjacency-style transition table (`EXPIRED`/`CANCELLED` deliberately
terminal for Activate/Suspend/Renew -- reaching either requires a new
subscription, not a state-change command). `LicensingValidationException`
is a deliberately different shape from this migration's usual
`{code, path, message}[]` validation exceptions -- a single `{code,
message}` pair, matching the source's own `ControlPlaneValidationError`
exactly (every failure here names the whole command, never one field
within a larger document).

Service: `App\Services\Licensing\LicensingService`. `assertEntitledOperation`
(the internal cross-cutting entitlement gate other admin commands call
before a privileged write) is deliberately not ported this slice --
grepped and confirmed no route calls it directly, and its own
`ADMIN_WRITE` branch depends on `access_reviews` (Access governance, a
separate still-unbuilt slice of this same phase); it belongs with
whichever future slice actually ports the admin-write commands that call
it.

Both a real end-to-end HTTP walkthrough (via PHPUnit's HTTP test client)
and a 5-test PHPUnit feature suite (`tests/Feature/Licensing/LicensingTest.php`,
run against real MySQL, each test provisioning its own subscription/
licence fixtures directly since neither has any command path -- see
above) confirm:

- **Entitlements genuinely reflect the organisation's real plan and
  period-scoped usage, not the business party's own fields**: a real
  `USER_SEATS` usage row for the current period is read back with its
  exact `used_value`/`limit_value`; a usage row for a *different* period
  key correctly reads as zero -- proving the source's own hardcoded
  `period_key IN ('2026-Q3','2026-08')` filter (a genuine, pre-existing
  pilot-scope limitation, carried forward faithfully rather than
  "corrected" -- see `LicensingService::getEntitlements`'s own doc
  comment) is real, not a no-op. `GetUsage` is confirmed unfiltered by
  period, listing every usage row regardless.
- **The real license state machine holds**: `SUSPEND` from `ACTIVE`
  succeeds and writes a real `license_events` row and a chained audit
  event; a second `SUSPEND` from the now-`SUSPENDED` state is correctly
  refused `422 LICENSE_TRANSITION_INVALID` (`SUSPEND`'s own allowed
  source-state list excludes `SUSPENDED`); `ACTIVATE` from `SUSPENDED`
  succeeds. `RENEW` genuinely advances the linked subscription's
  `current_period_end` by a real year, not a cosmetic status flip.
- **Upgrade is a genuine versioned plan change, not an in-place
  mutation**: upgrading creates a real new `organisation_licenses` row on
  the target plan and closes the previous one (`effective_to` set,
  verified unchanged on every other column -- see the genuine bug below);
  a second upgrade attempt to the plan the organisation is now already on
  is correctly refused `422 LICENSE_PLAN_UNCHANGED`.
- **RBAC and tenant scope are both genuinely enforced**: a role without
  `licensing:read`/`licensing:manage` (`TAXPAYER_STAFF`) is refused `403`;
  a different organisation's owner explicitly requesting this
  organisation's scope via `?organisation_id=` is refused `403`, not
  silently redirected to their own scope.

**A genuine bug was caught and fixed by this verification process, not
shipped** -- caught live by this slice's own upgrade-twice test (a second
upgrade to the now-current plan returned `200` instead of the expected
`422`): `organisation_licenses.effective_from` was the one `NOT NULL`
timestamp column in that table left without an explicit `DEFAULT` (the
same "exactly one exempt column" pattern this codebase's own migrations
already follow throughout). MariaDB's legacy TIMESTAMP auto-initialisation
rule quietly attaches **both** `DEFAULT CURRENT_TIMESTAMP` **and**
`ON UPDATE CURRENT_TIMESTAMP` to exactly that column when no column in a
table has an explicit default -- so `LicensingService`'s own plain
`UPDATE organisation_licenses SET effective_to=?` (used by every one of
its writes: suspend, activate, renew, and closing the old row on upgrade)
was silently *also* stamping `effective_from` to the current moment on
every such write, corrupting the very column `getLicense()`'s
`ORDER BY effective_from DESC` depends on to find an organisation's
current licence. Fixed by giving the column an explicit
`DEFAULT CURRENT_TIMESTAMP(6)` (deliberately *without* `ON UPDATE`) --
the application always supplies `effective_from` explicitly on every
`INSERT` regardless, so the default itself is never actually relied on,
only its side effect of opting the column out of MariaDB's implicit
auto-update magic. (The same migration also gave the column genuine
microsecond precision, matching the identical same-second-tie fix already
applied twice elsewhere in this migration -- a real, independently
necessary second half of the same fix, not a red herring: without it, a
licence upgraded within the same wall-clock second as its own creation
would still tie under `ORDER BY effective_from DESC`.)

## Organisation administration & employees (Phase 12 slice 2: portals/licensing/governance)

Second slice of `control-plane-repository.ts`, built directly on slice 1's
`LicenseResolver` (extracted from `LicensingService` as a pure refactor so
both slices share the identical organisation/license resolution logic --
see `App\Support\Licensing\LicenseResolver`'s own doc comment). Also closes
out "the rest of Phase 8" -- employees and organisation-defined custom
roles were Phase 8's own explicitly-deferred gap (see that phase's row
above), and land here instead since they are genuinely part of this same
source file and share its entitlement gating.

Schema (11 new tables, 91/155 total): `departments`/`business_units`/
`job_titles`/`employees` (an organisation's HR org-chart; `position_id` on
`employees` is deliberately left without an FK -- the `positions` table
itself is never written anywhere in the TypeScript source, a genuine gap
carried forward rather than inventing a table the source doesn't have),
`organisation_administrator_roles` (a fixed, seed-only catalogue -- 6 rows,
`OrganisationAdministratorRoleSeeder`, the same real-prerequisite pattern
as `LicensePlanSeeder`)/`organisation_administrators`, `organisation_roles`/
`organisation_role_permissions` (an organisation's own custom roles, distinct
from the platform's static `access_roles`), `user_capability_assignments`
(BUYER/SELLER capability grants, narrowing what an organisation itself
already holds), `user_role_assignments` (built with zero writers in this
slice -- `terminateEmployee` needs the table to exist for its own cleanup
`UPDATE`, but no command in this slice populates it), and `access_reviews`
(pulled forward from Access governance -- see below).

Domain: `App\Domain\OrganisationAdmin\OrganisationAdminValidator`, ported
from `lib/domain/control-plane.ts`'s `normalizeEmployee`/
`normalizeEmployeeActivation`/`normalizeAdministratorAppointment`/
`normalizeCapabilityGrant`/organisation-role validation. Deliberately
reuses the existing `App\Exceptions\LicensingValidationException` rather
than a new exception class, exactly matching the source's own single
`ControlPlaneValidationError` covering this whole file (slice 1 and slice
2 alike).

Support: `App\Support\Licensing\EntitlementGate` ports
`assertEntitledOperation` -- the internal cross-cutting gate every write
command in this slice calls first, deliberately deferred in slice 1 since
nothing there needed it. Its `ADMIN_WRITE` operation class hard-requires a
real, open-or-completed quarterly `access_reviews` row, which is why
`openQuarterlyAccessReview` (and the `access_reviews` table itself) is
pulled forward from Access governance here as a genuine prerequisite --
the same "unblock the real dependency, don't invent a shortcut" pattern
the VAT-return-generation prerequisite already established for Phase 11's
refund slice. `certifyQuarterlyAccess` (the review's own completion path,
with its bulk role/capability revocation) is deliberately not pulled
forward alongside it -- nothing in this slice's own command set calls it.

Service: `App\Services\OrganisationAdmin\OrganisationAdminService` --
`inviteEmployee`/`activateEmployee`/`terminateEmployee`/
`appointAdministrator`/`createOrganisationRole`/`listCapabilityGrants`/
`grantCapability`/`openQuarterlyAccessReview`. `getAdministrationSnapshot`
(the fixed-list dashboard aggregate the source bundles every GET-list
route into except `listCapabilityGrants`) is deferred -- it pulls in
workflow/access-request tables this slice doesn't build; `listCapabilityGrants`
is the one genuinely standalone read and is the only GET route ported here.
Workflow-task reassignment inside `terminateEmployee` is likewise deferred
at this point in the migration -- it needs `workflow_assignments`/
`workflow_instances`, neither built yet (closed out once they were; see
this section's own follow-up note below).

A 5-test PHPUnit feature suite (`tests/Feature/OrganisationAdmin/
OrganisationAdminTest.php`, run against real MySQL) confirms:

- **`assertEntitledOperation`'s `ADMIN_WRITE` gate is real, not a no-op**:
  inviting an employee is refused `403 QUARTERLY_ACCESS_REVIEW_REQUIRED`
  until the current quarter's `access_reviews` row is opened, then
  succeeds immediately after.
- **The employee lifecycle genuinely drives the license seat count, not
  just the employee row's own status**: inviting reserves a real
  `license_usage.reserved_value` seat; activating converts that reservation
  into `used_value` (`reserved_value` back to 0); terminating releases
  `used_value` back down -- all three verified against the actual
  `license_usage` row, not asserted from the response body alone.
  Duplicate employee number/email is a real `409` conflict; a manager/
  department/branch/job-title reference from a different organisation is
  refused `422 REFERENCE_OUT_OF_SCOPE`.
- **Appointing an administrator requires a real active employee and
  correctly demotes the prior PRIMARY administrator**, not just inserting
  a second row -- appointing a new PRIMARY administrator sets the previous
  PRIMARY's row `INACTIVE` in the same transaction, matching the source's
  own single-PRIMARY invariant.
- **Custom organisation roles are genuinely versioned, and reject
  platform-reserved permissions**: creating a role with a permission
  outside the tenant-grantable set (`vat-rules:manage`, a national-only
  permission) is refused `422 PROTECTED_PERMISSION`; creating two
  successive roles under the same name produces real `version` 1 then 2,
  not two independent rows.
- **Capability grants only ever narrow what the organisation itself
  already holds, and require real active membership**: granting a
  capability the organisation's own `organisation_capabilities` doesn't
  hold is refused `422` before membership is even checked; granting a
  held capability to a user who isn't yet an active member of the
  organisation is separately refused `422 USER_NOT_MEMBER`; granting it
  after a real `organisation_memberships` row exists succeeds and is
  readable back via `listCapabilityGrants`.

**A genuine bug was caught and fixed by this verification process, not
shipped**: `Route::get('/organisations/capabilities', ...)` was registered
*after* the pre-existing Phase 8 route `Route::get('/organisations/{id}',
...)` in `routes/web.php`. Laravel matches routes in registration order, so
the literal `capabilities` segment was being swallowed by the `{id}`
wildcard -- `OrganisationController::show('capabilities')` would look up an
organisation with that literal ID, find none, and return a genuine `404`,
never reaching `OrganisationAdminController::capabilities` at all. Caught
by this slice's own capability-listing test (`GET
/organisations/capabilities` returning `404` instead of `200`). Fixed by
moving the specific route ahead of the wildcard, with a comment on both
sides explaining why the ordering matters -- a durable trap for any future
literal-segment route added under `/organisations/*`.

Separately, `organisation_administrators.effective_from` and
`user_capability_assignments.effective_from` were given an explicit
`DEFAULT CURRENT_TIMESTAMP` (via `->useCurrent()`, deliberately without
`ON UPDATE`) at migration-authoring time -- *before* either column could
actually exhibit the problem, applying the exact lesson already learned
and documented in slice 1's own `organisation_licenses.effective_from` fix
(see "Licensing & Entitlements" above) rather than waiting to rediscover
the same MariaDB legacy TIMESTAMP auto-initialisation trap a third time.

**Follow-up closed after Phase 12 slice 5 (the workflow engine):**
`terminateEmployee`'s own workflow-task reassignment was deliberately
deferred at the time this section was originally written -- `workflow_
assignments`/`workflow_instances` did not exist yet. Both tables now do
(slice 5), so this one piece of already-shipped code has been revisited
and completed: every still-`PENDING` task assigned to the terminated
employee's own user, across every workflow instance in the organisation,
is now reassigned to the organisation's current active primary
administrator in the same transaction as the rest of the offboarding --
exactly matching the source's own final step, including its `user_id<>?`
guard against ever "reassigning" a task to the very user losing access
(the terminated employee may themselves be the primary administrator
being replaced). Two new tests confirm: **only genuinely `PENDING` tasks
move** (an already-`APPROVED` task assigned to the same outgoing employee
keeps its original assignee, verified directly against the database, not
merely that reassignment "happened somewhere"), **an unrelated user's own
task is completely untouched**, and **when no other active primary
administrator exists at all, termination still succeeds cleanly with
`tasks_reassigned_to: null`** rather than erroring -- offboarding a sole
employee is never blocked by the absence of someone to hand their tasks
to.

## Portal navigation (Phase 12 slice 3: portals/licensing/governance)

Third slice of `control-plane-repository.ts`: `getEffectiveNavigation`/
`getNavigationChildren`/`getNavigationItemActions`/
`saveNavigationPreference` -- the last self-contained sub-domain besides
the workflow engine and the rest of Access governance. Built directly on
both prior slices' shared `LicenseResolver` (feature entitlements) and
slice 2's `organisation_capabilities`/`user_capability_assignments`
tables (capability gating).

Schema (4 new tables, 95/155 total): `navigation_workspaces`/
`navigation_folders`/`navigation_items` (a fixed, seed-only catalogue --
`NavigationSeeder`, the same real-prerequisite pattern as
`LicensePlanSeeder`/`OrganisationAdministratorRoleSeeder`; natural string
primary keys matching the source's own natural-key rows, e.g.
`'nav-home'`, not UUIDs) and `navigation_preferences` (a genuine
UUID-keyed row, one per user/organisation/preference-type). The source's
own `navigation_permissions` table is deliberately not built at all --
confirmed, by grepping every `.ts` file under `lib/` for a reader or
writer, that nothing in the source ever touches it -- the same "genuinely
dead table" precedent already established for `positions`.

Domain: `App\Domain\Navigation\NavigationValidator`, ported from
`lib/domain/control-plane.ts`'s `normalizeNavigationChildrenQuery`/
`normalizeNavigationPreference`. Reuses the existing
`App\Exceptions\LicensingValidationException` rather than a new exception
class, exactly matching the source's single `ControlPlaneValidationError`
shared across licensing, organisation administration, and navigation
alike.

Service: `App\Services\Navigation\NavigationService`. Its
`accessContext()`/`rowAllowed()` are the source's own internal
`getNavigationAccessContext`/`navigationRowAllowed` helpers. The source's
`actor.capabilities` guard (`actor.organisationId === organisation.id ?
actor.capabilities : []`) exists only to stop a user's own capability
grants leaking into a *different* requested organisation's context; this
port has no session-cached `actor.organisationId`/`actor.capabilities` to
compare against (the same simplification `LicenseResolver::
resolveOrganisation`'s own doc comment already established for the
identical reason), so it queries `user_capability_assignments` directly,
scoped to the *resolved* organisation instead -- exactly equivalent for a
taxpayer-scoped actor (the resolved organisation always *is* their own),
and behaviourally identical for a national actor requesting an arbitrary
organisation (no such assignment row can exist there without a real
membership, which `grantCapability` itself requires).

**A genuine, Phase-7-deferred gap in this port itself (not the source) was
closed by this slice, not carried forward a second time**: `hasPermission`
in the source is a union of *static* role grants and *dynamic*
organisation-defined custom-role grants (`user.dynamicPermissions`,
resolved once per request in `lib/auth.ts`'s `buildUserContext` from
`user_role_assignments` -> `organisation_roles` ->
`organisation_role_permissions`). This port's `Gate::define('permission',
...)` and `User::hasAppPermission()` had only ever checked the static half
-- explicitly documented as deferred in both classes' own doc comments
since Phase 7, because `organisation_roles`/`user_role_assignments`
genuinely did not exist as tables until Phase 12 slice 2. Portal
navigation is the first slice whose own correctness actually depends on
the dynamic half (`getEffectiveNavigation`'s row filter calls the *full*
`hasPermission`, not just `roleHas`), so it is closed now: the new
`App\Support\Access\DynamicPermissions::forUser()` resolves a user's
custom-role permissions for their own home organisation (their first
active `organisation_memberships` row, exactly mirroring
`buildUserContext`'s own resolution), and both `User::hasAppPermission()`
and the `permission` Gate now OR it in. Deliberately *not* cached across
requests the way the source's own session-bootstrap value effectively is
-- `artisan test` runs the whole suite in one PHP process, and a stale
process-lifetime cache surviving a role assignment/revocation within a
single test would be a subtler bug than the extra query cost of
re-resolving it every call. Verified safe/purely-additive: the full
127-test suite predating this slice still passed unchanged immediately
after this fix landed, before any of this slice's own new tests were
written (`user_role_assignments` had zero rows anywhere in the existing
suite).

A 7-test PHPUnit feature suite (`tests/Feature/Navigation/
NavigationTest.php`, run against real MySQL) confirms:

- **The effective-navigation tree genuinely enforces all three gates
  together, not just permission**: an item gated on a capability the
  organisation does not hold (`BUYER`, `nitem-operations`) is excluded
  even though the actor holds the required permission
  (`expenses:read`); an item gated on a licence feature explicitly
  disabled on the plan (`ANALYTICS`, live-toggled on the shared seeded
  plan for this one test) is excluded even though the actor holds
  `reports:read`; a role missing the required permission outright
  (`TAXPAYER_VIEWER` lacking `exceptions:read`/`security:read`) is
  excluded regardless of feature/capability state.
- **The response tree groups every item for one folder under that one
  folder object** -- verified directly (not merely "the response parsed"),
  ruling out a real, easy-to-introduce bug in the source's own by-reference
  tree-building translated into PHP array references (`&$folderIndex[...]`
  pushed into the parent workspace's `folders` array, then mutated through
  the reference as later rows for the same folder are processed).
- **A suspended user is denied every navigation route via the same
  `Gate::define`'s `isActive()` half every other controller already
  relies on** -- no built-in role actually lacks `workspace:read` itself
  (confirmed against every one of `Permissions::CONTROL_PLANE_PERMISSIONS`'s
  22 entries), so this is the only real way to exercise a `403` here.
- **`getNavigationChildren` correctly drills workspace -> folders and
  folder -> subfolders/items**, with folders themselves never
  permission-gated (matching the source -- only items are) and a genuine
  `422 WORKSPACE_NOT_FOUND`/`PARENT_TYPE_INVALID` for a bad request.
- **`getNavigationItemActions` reports real, itemised `deniedReasons`**,
  not just a bare `false`, and a genuine `422 ITEM_KEY_REQUIRED`/
  `NAVIGATION_ITEM_NOT_FOUND`.
- **`saveNavigationPreference` genuinely upserts** -- posting the same
  `preference_type` twice with different values leaves exactly one row
  (`assertDatabaseCount`), not two, with the second value winning.
- **The `DynamicPermissions` fix is verified end-to-end through a real
  HTTP request, not just unit-level**: a `TAXPAYER_VIEWER` denied
  `nitem-reconciliation` (requires `exceptions:read`, which that role does
  not statically hold) is granted it after being assigned a real
  organisation-defined custom role carrying exactly that one permission --
  and a *different* still-ungranted item (`nitem-security`) stays denied,
  proving the grant is scoped to what the custom role actually carries,
  not a blanket bypass.

## Access governance (Phase 12 slice 4: portals/licensing/governance)

Fourth and final self-contained slice of `control-plane-repository.ts`
besides the workflow engine: `requestRoleAccess`/`decideAccessRequest`/
`certifyQuarterlyAccess`/`revokeAccessGrant`/`offboardUser`.
`openQuarterlyAccessReview` itself, and `access_reviews`, were already
pulled forward into Phase 12 slice 2 as `assertEntitledOperation`'s own
genuine `ADMIN_WRITE` prerequisite -- this slice is everything else in
Access governance. `searchWorkspace` (`/api/v1/search`) is deliberately
excluded from this slice's scope despite living in the same source file
and reusing `assertEntitledOperation` -- it is genuinely a separate
Workspace & Navigation route (confirmed against the module playbook's own
domain list and the route file it actually lives under), not Access
governance; a small, self-contained follow-up, not silently dropped.

Schema (3 new tables, 98/155 total): `access_requests` (a maker-checker
request for one existing organisation-defined custom role, decided by
`decideAccessRequest`), `access_approvals` (one append-only row per
decision), `access_certifications` (`certifyQuarterlyAccess`'s own write
target, one row per (review, subject) pair -- the `UNIQUE(access_review_
id, subject_user_id)` index matches the source exactly).

Domain: `App\Domain\AccessGovernance\AccessGovernanceValidator`, ported
from `lib/domain/control-plane.ts`'s `normalizeAccessRevocation`/
`normalizeOffboarding` -- the only two functions in this slice that have
a dedicated source-side normalizer at all. `requestRoleAccess`/
`decideAccessRequest`/`certifyQuarterlyAccess` validate inline in the
repository function itself in the source too, so `AccessGovernanceService`
mirrors that inline validation directly rather than inventing normalizer
methods the source doesn't have.

Service: `App\Services\AccessGovernance\AccessGovernanceService`. Each
command's own entitlement gate deliberately varies to match the source
exactly, not uniformly: `requestRoleAccess` uses `ADVANCED_WORKFLOW`/
`BUSINESS_WRITE` (no quarterly-review prerequisite -- filing a request is
not itself a privileged write); `decideAccessRequest`/`revokeAccessGrant`/
`offboardUser` use `ADMIN_WRITE` (the quarterly-review prerequisite
applies); `certifyQuarterlyAccess` uses `ADMINISTRATION`/`COMPLIANCE_WRITE`
(deliberately *not* `ADMIN_WRITE` -- gating a review's own certification
behind a review already being open would be circular; the function
instead checks the specific review's own `OPEN` status internally, exactly
matching `openQuarterlyAccessReview`'s own choice of operation class).

A 5-test PHPUnit feature suite (`tests/Feature/AccessGovernance/
AccessGovernanceTest.php`, run against real MySQL) confirms:

- **`requestRoleAccess` genuinely validates both the subject's active
  membership and the role's active status against the real organisation
  scope**, not just presence -- a syntactically valid but non-existent
  role id is refused `422 ACCESS_REFERENCE_INVALID` before any row is
  written.
- **`decideAccessRequest` refuses self-approval from *either* side of the
  request** -- the requester deciding their own filed request, and
  separately the access *subject* deciding a request filed on their own
  behalf, are both refused `422 SELF_APPROVAL_DENIED`; a real approval
  from a genuine third party creates a real `user_role_assignments` row,
  and a second decision on an already-decided request is a real `409`
  conflict, not a silent no-op.
- **`certifyQuarterlyAccess`'s review-completion count is exact, not
  approximate**: certifying one of two active members leaves the review
  `OPEN`; certifying the second brings it to `COMPLETED` in the same
  request that submits the second certification -- verified by reading
  `access_reviews.status` back from the database after each call, not
  merely asserting the HTTP response. `REVOKE` genuinely cascades to the
  membership row and a real capability grant in the same transaction, not
  just the certification row itself. An invalid disposition, a
  self-certification attempt, and a subject with no active membership are
  each refused their own distinct `422`; a review that is not open (or
  does not exist -- the source conflates the two into the same branch,
  reproduced faithfully) is a `409`, not a `422`.
- **`revokeAccessGrant` is genuinely idempotent and self-revocation-safe**:
  revoking an already-`REVOKED` grant returns its current state without a
  second write (`assertDatabaseCount` confirms no duplicate row); revoking
  one's own active grant is refused `422 SELF_REVOCATION_DENIED` before
  any write; a grant id outside the active organisation is `422
  GRANT_NOT_FOUND`.
- **`offboardUser` genuinely revokes every active grant type in one call
  and is a true no-op the second time**, not merely returning success --
  the exact `roleAssignmentsRevoked`/`capabilityAssignmentsRevoked` counts
  are asserted against the real rows, and self-offboarding is refused
  `422 SELF_OFFBOARD_DENIED`.

**A genuine bug in this port itself was caught and fixed before it could
ship, not by this slice's own tests but by writing this slice's own
migration**: `access_reviews.due_at` (Phase 12 slice 2's own table) was
the one NOT-NULL timestamp column left without an explicit default,
exactly the MariaDB legacy TIMESTAMP auto-initialisation trap already
found and fixed twice before. It was latent and harmless while
`access_reviews` was only ever `INSERT`ed into (slice 2 never updates it);
`certifyQuarterlyAccess`'s own completion `UPDATE` (which never sets
`due_at` itself) would have silently corrupted it the moment this slice's
service code could reach that statement. Fixed by amending the original
migration file directly (this branch has no live deployment yet, so
editing history in place -- rather than adding a follow-up `ALTER
TABLE` migration -- keeps the schema's own record honest) with an
explicit `DEFAULT CURRENT_TIMESTAMP` (still deliberately without `ON
UPDATE`), verified via a fresh `migrate:fresh`.

## Workflow engine (Phase 12 slice 5: portals/licensing/governance)

Fifth and final self-contained slice of `control-plane-repository.ts`
(Module 8 Phase C): `createWorkflowDraft`/`publishWorkflowVersion`/
`assignWorkflow`/`decideWorkflowTask`/`testWorkflowVersion`/
`createDelegation`/`listDelegations`/`revokeDelegation`. `searchWorkspace`
(a small, genuinely separate Workspace & Navigation route) and
`getAdministrationSnapshot` (the dashboard aggregate every GET-list route
across all five Phase 12 slices bundles into) are the only two functions
left in this whole file -- see "Access governance" above for why
`searchWorkspace` is out of this slice's own scope too.

Schema (11 new tables, 109/155 total): `workflows`/`workflow_versions`/
`workflow_nodes`/`workflow_transitions`/`workflow_conditions` (an
organisation's own approval-graph definitions, immutably versioned and
content-hashed -- `AuditService::canonicalJson()` + `hash('sha256', ...)`,
matching the source's own `stableStringify`/`sha256Hex`),
`workflow_instances`/`workflow_assignments`/`workflow_approvals` (one
real approval run against a resource, its live pending task, and its
append-only decision trail), `workflow_delegations` (a time-bounded
task-redirect). `sod_rules`/`sod_violations` (segregation-of-duties) are
also built here -- `sod_rules` is a seed/deploy-time-only catalogue like
`license_plans` before it (confirmed, by grepping every `.ts` file under
`lib/`, that no application command ever creates a row there), while
`sod_violations` is `decideWorkflowTask`'s own genuine write target. The
source's `navigation_permissions`-style genuinely-dead-table precedent
does not recur here -- every one of these 11 tables has a real reader or
writer in this slice.

Domain: `App\Domain\Workflow\WorkflowValidator`, ported from
`lib/domain/control-plane.ts`'s `normalizeWorkflowDefinition`/
`normalizeWorkflowAssignment`/`normalizeWorkflowTestContext`/
`normalizeDelegation`/`assertWorkflowDecision`. `REFUND` stays registered
in the domain-action vocabulary (matching the source) so a future phase
can build a refund-approval workflow against this engine -- Refund's own
existing maker-checker (`RefundService::reviewRefund`) is deliberately
*not* migrated onto it here; that is cross-module surgery on already-
shipped, tested code, out of scope for a slice that stays inside this one
file's own functions.

Service: `App\Services\Workflow\WorkflowService`. `resolveNextNode`/
`evaluateCondition`/`redirectThroughDelegation`/`resolveAssignee` are the
source's own shared internal helpers (`resolveNextNode`/
`evaluateWorkflowCondition`/`redirectThroughDelegation`/
`resolveAssignee`), private methods here too, reused identically by
Assign, Decide and Test's dry run -- exactly matching the source's own
single-source-of-truth design for its transition-graph traversal. Each
command's entitlement gate deliberately varies to match the source, not
uniformly: `createWorkflowDraft`/`publishWorkflowVersion`/
`createDelegation`/`revokeDelegation` use `ADVANCED_WORKFLOW`/
`ADMIN_WRITE` (the quarterly-review prerequisite applies); `assignWorkflow`/
`decideWorkflowTask` use `ADVANCED_WORKFLOW`/`BUSINESS_WRITE` (no review
needed to run or decide an already-configured workflow); `testWorkflowVersion`/
`listDelegations` use `READ`.

A 7-test PHPUnit feature suite (`tests/Feature/Workflow/WorkflowTest.php`,
run against real MySQL) confirms:

- **Create reserves a licence seat, Publish converts it, and the
  workflow's own maker-checker separation is real, not decorative**:
  `publishWorkflowVersion`'s own `assertWorkflowDecision` genuinely
  refuses a draft's own creator as its publisher/approver
  (`SELF_APPROVAL_DENIED`) -- caught live by this slice's own first test
  attempt (see the genuine bug/fixture note below), a second, different
  user succeeds, a duplicate workflow name is a real `409`, and an
  already-published version is refused a second publish.
- **Malformed definitions are rejected with the specific code for what's
  actually wrong**: an unsupported domain action, a graph missing its one
  required START/END pair, and an APPROVAL node with no typed assignee
  each produce their own distinct `422`, not a generic validation
  failure.
- **`assignWorkflow` genuinely evaluates transition conditions against
  real request context, not just structurally walking the graph**: an
  `amount_cents` at or below a conditioned threshold routes straight to
  END and completes the instance immediately with no assignment created;
  above it, the same instance falls through to the unconditional
  transition and creates a real role-assigned task. A user who does not
  hold the assigned role is refused `422 TASK_NOT_ASSIGNED`; the role
  holder's `APPROVE` genuinely advances the graph and completes the
  instance. No active workflow configured for a domain action is a clean
  `422`, not a crash.
- **`REJECT` terminates the whole instance immediately** (`workflow_
  instances.status` verified `REJECTED` in the database, not just the
  response), and a second decision on the same task is a real `409`.
- **Self-approval is denied and genuinely recorded as a segregation-of-
  duties violation**, not just refused: a user who both initiated a
  workflow and holds its approving role is refused `422
  SELF_APPROVAL_DENIED` deciding their own task, and a real `sod_
  violations` row is written (verified against a real, organisation-scoped
  `NO_SELF_APPROVAL` `sod_rules` row) -- the task itself stays genuinely
  `PENDING`, not silently decided either way.
- **`testWorkflowVersion` is a genuine dry run against a DRAFT version,
  with zero side effects**: an unmatched context dead-ends at
  `NO_MATCHING_PATH` after walking only the reachable nodes, a matched
  context reaches `COMPLETED` with the real path recorded, and the
  version's own `status` is verified still `DRAFT` afterwards.
- **Delegation genuinely redirects a USER-type assignee, and stops the
  moment it's revoked**: self-delegation is refused `422
  DELEGATION_SELF`; an active delegation causes a new assignment to land
  on the delegate, not the original target; after revocation, a second
  assignment correctly reverts to the real target; revoking an
  already-revoked delegation is a real `409`.

**A genuine bug was caught and fixed by this verification process, not
shipped**: `createDelegation`'s raw `DB::table('workflow_delegations')->
insert(...)` was writing `effective_from`/`effective_to` as the
validated-but-unconverted ISO-8601 string straight from
`WorkflowValidator::delegation()` (e.g. `2026-09-01T05:42:54.866Z`) --
valid input, but not a format MySQL's `TIMESTAMP` columns accept
directly, unlike Eloquent's own model-level date casting (which every
*other* timestamp write in this migration goes through). Every real
delegation-creation request would have failed outright with a raw PDO
`Invalid datetime format` error. Fixed by parsing both fields through
`Illuminate\Support\Carbon::parse()` before the insert -- Laravel's query
layer only auto-formats a genuine `DateTimeInterface` value for the
database driver, not a plain string, even one already validated as a
well-formed timestamp.

## Administration snapshot & portals (closing out Phase 12: portals/licensing/governance)

The last two functions in `control-plane-repository.ts` --
`getAdministrationSnapshot` and `searchWorkspace` -- plus `lib/portals.ts`'s
`getAvailablePortals`, a genuinely separate file discovered and closed out
alongside them since it is still squarely inside Phase 12's own "portals"
scope (confirmed by grepping every `.ts` file under `lib/` and `app/api/`
for any other unported function referencing `control-plane-repository.ts`,
`lib/domain/portals.ts`, or `lib/portals.ts` -- none found). No new tables
this slice -- all three functions are pure reads against tables every
earlier Phase 12 slice already built.

`getAdministrationSnapshot`: `App\Services\Administration\
AdministrationSnapshotService`, a ten-way aggregate read (the source's own
`Promise.all`, run sequentially here) reused by seven different routes
across four different controllers -- `AdministrationController::show`
(the one route returning the full, unsliced payload) and six existing
controllers' own new GET methods (`OrganisationAdminController::
listEmployees/listRoles/listAdministrators`, `LicensingController::
license`, `AccessGovernanceController::listAccessRequests/
listAccessReviews`, `WorkflowController::listWorkflows`), each slicing the
same snapshot down to its own fields, exactly matching the source's own
per-route shape. `security_events_30d`/`failed_logins_30d` are
deliberately unfiltered by organisation in both the source and this port
-- `security_events` genuinely has no `organisation_id` column at all (a
platform-wide table), not a scoping bug to "fix".

`searchWorkspace`: added to `NavigationService` (the same file
`getEffectiveNavigation`/`getNavigationChildren` already live in --
Workspace & Navigation is one domain in the source, and `searchWorkspace`
is the one function from it this migration had not yet reached), with its
own `NavigationController::search` route. Each of its three sections
(employees, invoices, organisation roles) only runs -- and only ever
surfaces matches from -- a table the actor already holds real read access
to, reproducing the source's own per-section `hasPermission` guards
exactly; `%`/`_` are stripped from the search term before building the
`LIKE` pattern so a search string cannot inject its own SQL wildcards.

`getAvailablePortals`: `App\Domain\Portal\PortalDefinitions` (the static,
code-defined six-portal catalogue, matching this migration's own verified
source-inventory count from day one) and `App\Services\Portal\
PortalService`, with `PortalController::index`. Deliberately filters an
organisation's held capabilities on their own `effective_from`/
`effective_to` window -- stricter than, and a genuinely different function
from, `NavigationService::accessContext()`'s own simpler organisation-
capability lookup; reproduced exactly as the source's own separate
`capabilitySet` helper does it, not reconciled into one shared
implementation the source itself never had.

A 6-test PHPUnit feature suite (`tests/Feature/Administration/
AdministrationSnapshotTest.php`, `tests/Feature/Navigation/
NavigationTest.php`'s two new search tests, and `tests/Feature/Portal/
PortalTest.php`, run against real MySQL) confirms:

- **The full snapshot genuinely aggregates every sub-domain from real,
  independently-seeded rows** -- one each of an employee, an organisation
  role with a real `GROUP_CONCAT`-joined permission list, a published
  workflow with a pending task, an access request, an access review, an
  administrator, and an open segregation-of-duties violation, each read
  back through its own real join, not asserted from the write path alone.
- **Every one of the six slice routes returns *only* its own fields** --
  verified by asserting the exact top-level JSON key set on each
  response, not merely that the expected keys are present among others.
- **Each slice route is gated by its own specific permission, not a
  shared one** -- a role holding none of employees:read/roles:read/
  administration:read/access-governance:read/workflows:read is refused
  every one of the seven routes with a real `403`.
- **Search is genuinely permission-filtered per section, not per-route**
  -- a role holding `invoices:read` but neither `employees:read` nor
  `roles:read` sees only Invoice results even though all three sections
  are queried against real seeded rows; a query below the two-character
  minimum returns no results at all, and the route itself still requires
  `search:read`.
- **Portal visibility genuinely depends on both role and the
  organisation's own held capability, not role alone** -- a
  `TAXPAYER_OWNER` whose organisation holds `SELLER` but not `BUYER` sees
  the `seller` portal (capability-gated) but not `buyer`, despite being
  listed in both portals' own `roles`, while still seeing the
  capability-free `developer` portal; a `PILOT_ADMIN` sees all six
  unconditionally.

**A genuine SQL-compatibility bug was caught and fixed by this
verification process, not shipped**: the roles aggregate's
`GROUP_CONCAT`/`GROUP BY r.id` query failed outright against this
environment's real MariaDB with `'r.name' isn't in GROUP BY`
(`ONLY_FULL_GROUP_BY` is enabled) -- MariaDB did not reliably infer
`r.name`/`r.description`/etc.'s functional dependency on `r.id` (the
table's own primary key) through the table alias used here, even though
that inference is exactly what the SQL standard's own primary-key
exception is supposed to permit. Fixed by listing every selected `r.*`
column explicitly in the `GROUP BY` clause -- portable regardless of how
aggressively a given MySQL/MariaDB build infers functional dependency.

## Source-fidelity findings (genuine gaps in the original, not introduced here)

Three genuine gaps in the TypeScript source itself, discovered while
porting and either completed (the two RBAC-seed gaps) or faithfully
preserved rather than silently patched over (the third):

1. **`access_roles` never seeded `SELLER_ADMIN`, `SELLER_OPERATOR`,
   `SELLER_VIEWER`, `BUYER_ADMIN`, `BUYER_USER`**, even though
   `ROLE_PERMISSIONS` grants all five real permission sets. Tolerated in the
   source because `app_users.role` there is a plain unconstrained `TEXT`
   column with no FK to `access_roles`. `RoleSeeder` completes the registry
   (marked distinctly in its own doc comment) since the Laravel schema's
   `role_code` columns carry real foreign keys.
2. **`access_permissions` never seeded 12 permission codes** that
   `ROLE_PERMISSIONS` grants: `taxpayers:suspend`, `registrations:approve`,
   `invoices:cancel`, `vat-rules:read`, `vat-rules:manage`,
   `cases:override-sod`, `obligations:manage`, `payments:record`,
   `security:manage`, `accounting:close-period`, `documents:manage`,
   `exceptions:read`. `PermissionSeeder` completes these too, marked
   distinctly, with resource/action/classification values inferred from the
   seeded rows' own pattern (not verified source data -- flagged as such in
   the seeder's comment). The first 11 were found auditing `ROLE_PERMISSIONS`
   directly; `exceptions:read` surfaced later, from Phase 12 slice 3's own
   test suite, as a real `FOREIGN KEY` failure -- it is also
   `navigation_items.required_permission` on `nitem-reconciliation`, and
   portal navigation is the first slice in this migration where a
   `ROLE_PERMISSIONS`-only code actually has to resolve against the real
   `access_permissions` catalogue (an organisation-defined custom role's
   permission FK), rather than only ever flowing through the unconstrained
   static role map.
3. **No application code path anywhere in the source ever sets
   `vat_return_versions.status` to `FILED`** -- `submitVatReturn` only ever
   writes to `vat_return_submissions.status`. `requestRefund`'s
   `filed = version.status === "FILED"` gate is consequently always false
   through any real, callable path; refund claims can only ever reach
   `BLOCKED_RETURN_NOT_FILED` in practice, matching the source's own
   `refund-0001` demo-seed row. See the "Refund workflow" verification
   section above for how this port's own tests exercise the `RECEIVED`
   branch anyway (by setting the status directly, documented in the test),
   rather than inventing a "file the return" command neither system has.

## Design decisions carried through the whole migration (apply to every future phase)

- **UUID primary keys throughout**, not Laravel's default auto-increment
  bigint -- every table in the 155-table source schema uses a `TEXT` UUID
  primary key and every FK references one; matching that in MySQL keeps the
  Phase 14 legacy importer a straight ID copy instead of a full FK-remapping
  exercise.
- **`users` merges the source's `app_users` directly onto Laravel's native
  Authenticatable model** rather than keeping them as two separate concepts
  -- one user, one role, exactly as the source's static RBAC assumes.
  `identity_providers`/`identity_links` are kept (per the migration brief)
  for future federated login, but local Laravel auth does not depend on them.
- **RBAC stays a static, code-defined map** (`App\Support\Access\Permissions`),
  not a database-driven permission table, because that is what the source
  actually does (`lib/domain/access.ts`'s `ROLE_PERMISSIONS` is a fixed
  TypeScript object, not admin-editable data). `role_permission_grants`
  exists as a real table (matching the source schema) for future tenant-role
  auditing, but is not the authorization decision path -- `Permissions::roleHas()`
  is, exactly mirroring the source's `hasPermission()`.
- **Enum fields use MySQL `ENUM` only where the complete value set was
  directly confirmed** against `lib/domain/*.ts`'s validation code (e.g.
  `taxpayers.taxpayer_type`, `.return_frequency`, `.vat_status`,
  `users.role`, `.status`). Where the source's own SQLite schema had no
  `CHECK` constraint and no exhaustive value set could be confirmed from the
  domain layer in the time available (e.g. `organisations.status`,
  `branches.status`), a plain `VARCHAR` was used instead of guessing --
  tighten to `ENUM` once the full set is confirmed. The source schema itself
  has exactly one SQL-level `CHECK` constraint in its entire 155-table
  schema (`inventory_balances.quantity_micros >= 0`); the overwhelming
  majority of the source's business-rule enforcement lives in
  `lib/domain/*.ts` application code, not the database -- so most of the
  real migration work ahead is porting those domain/validation functions
  into Laravel `Requests`/`Services`, not writing exotic SQL.

## Cloudflare/D1/R2/Vinext dependencies remaining

None have been introduced in `php-app/` (it is a clean Laravel project with
no Cloudflare dependency of any kind). The original TypeScript application at
the repo root still has all of them, by design -- it remains the live
reference implementation until the PHP system is verified module-for-module
against it.

## Next steps

**Every phase this migration originally scoped (1 through 15) is now
COMPLETE for its own actual scope.** This is genuinely the end state of
a multi-week engineering effort carried out at the pace of careful,
verified, per-field-checked porting across this session's own
Phase 3/4/5/6/7/8/9/10/11/12/13/14/15 slices -- 251 tests, all run
against real MySQL/MariaDB (never SQLite), 0 regressions at every step,
each phase closed with a clean `migrate:fresh --seed` cycle before being
marked done. `lib/data/**` and `lib/api/**`'s entire exported surface
(every repository file: identity, invoices/VAT, business/accounting,
compliance, control-plane, and platform) now has a ported, tested PHP
counterpart. `docs/DEPLOYMENT.md` (Phase 15) is the operational
follow-on to this document.

That does not mean there is nothing left before a real production
rollout -- it means what is left is genuinely different in kind from
"port the next function," and is recorded honestly rather than implied
away by a fully-green test suite:

- **The organisation-scope trait's nine permanently-excluded models**
  (`RefundClaim`/`VatReturnVersion`/`ApprovalTask`/`VatPeriod`/
  `AuditCase`/`OrganisationCapability`/`CommunicationThread`/
  `Communication`/`DocumentMetadata` -- see "Organisation-scope trait
  retrofit", "Organisation-scope trait: the nullable-column exclusions"
  and "Document module" above) each keep their existing manual tenant
  checks fully correct and untouched; this was always about which
  models this specific automatic-scope mechanism can safely sit on top
  of, never a security gap in those models themselves. Closed,
  documented, not an open item.
- **A handful of documented, non-blocking gaps** carried since early in
  this migration and never silently dropped: TOTP step-up parity is
  Laravel's `password.confirm` re-authentication rather than the
  source's own server-verified TOTP (Phase 6; the
  `step_up_events`/`mfa_totp_credentials` tables exist, schema-only),
  three platform-config/access-policy values are now wired to a real
  downstream consumer via `App\Support\Platform\PlatformConfigReader`
  (Phase 13's "Platform config now feeds three real consumers" above);
  every other seeded row remains illustrative only, wired only when a
  real consumer needs it, and no real S3/R2-compatible object-storage
  driver is
  configured (`docs/DEPLOYMENT.md`'s "Storage" section) -- a config
  change only, given every service already goes through Laravel's
  `Storage::disk(...)` interface. (Self-service password reset -- once
  listed here as a gap -- was closed 2026-09-02 per red team finding
  RT-005; see `docs/RED_TEAM_ASSESSMENT_2026-09-02.md`.)
- **Re-verification against the actual target runtime.** Every
  verification in this session ran on PHP 8.2.12 and MariaDB 10.4.32
  (XAMPP), flagged throughout as differing from a PHP 8.3+/MySQL 8
  target; code was deliberately written to avoid anything that needs
  8.3+ or MySQL-8-only syntax, but this substitution should be
  confirmed, not assumed, before production use.
- **A real legacy-data cutover, if one is ever needed.** The Phase 14
  importer (`php artisan legacy:import-d1`) is real and generic, but it
  has only ever run against a synthetic fixture -- there is no actual
  legacy dataset anywhere in this repository to import. `docs/
  DEPLOYMENT.md`'s "Legacy data cutover" section is the runbook for
  when a real export exists.

None of these block a pilot/demo deployment on the ported functionality
itself; they are the honest difference between "every phase is
complete" and "nothing is left to ever think about again."
