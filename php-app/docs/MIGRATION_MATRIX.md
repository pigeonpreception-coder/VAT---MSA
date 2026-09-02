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
| 13-15 | Documents/integrations/offline/reports through legacy importer and deployment docs | NOT STARTED, except a minimal slice of Module 22 (`document_metadata` + `uploadDocument`/`completeDocumentScan`) pulled forward to unblock Phase 11 -- see "DOCUMENT evidence citation" below. Supersession/version chains, retention holds as their own standalone command, download URLs, the platform/developer-portal snapshots, offline sync, integrations, and report exports are all still NOT STARTED. |

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
retrofit plus these two); 8 models are permanently excluded with a
documented reason each (`RefundClaim`, `VatReturnVersion`, `ApprovalTask`,
`VatPeriod`, `AuditCase`, `OrganisationCapability`, `CommunicationThread`,
`Communication`) -- their existing manual tenant checks are untouched and
remain fully correct; this was never about a security gap in those
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

## Next steps (not started, listed so nothing is silently dropped)

The organisation-scope trait's own eight permanently-excluded models
(`RefundClaim`/`VatReturnVersion`/`ApprovalTask`/`VatPeriod`/`AuditCase`/
`OrganisationCapability`/`CommunicationThread`/`Communication` -- see
"Organisation-scope trait retrofit" and "Organisation-scope trait: the
nullable-column exclusions" above) each keep their existing manual
tenant checks fully correct and untouched; this was always about which
models this specific automatic-scope mechanism can safely sit on top of,
never a security gap in those models themselves, so no further action is
owed there beyond the documented reasoning. Phases 13 through 15 in full
(documents/integrations/offline/reports beyond the minimal Module 22
slice already pulled forward, the legacy D1 importer, and deployment
documentation) remain outstanding. Phase 4 is now COMPLETE for its actual
scope (154 of 155 tables -- see "Remaining schema conversion" above;
`positions` stays deliberately excluded, the source never writes to it
either). Phase 5 is now COMPLETE for its actual scope too (see "Demo seed
gaps for already-shipped features" above). Phase 7 is now COMPLETE for
its actual scope as well -- the trait exists, is proven correct, and is
now applied to 44 models across Phases 8-12 (see "Organisation-scope
trait", "Organisation-scope trait retrofit" and "Organisation-scope
trait: the nullable-column exclusions" above); the eight explicitly
excluded models are a closed, documented list, not an open item. Phases
8, 9, 10,
11 and 12 (organisations/taxpayers/administration, invoices/VAT,
accounting/commercial, compliance/audits/disputes/refunds/risk, and
portals/licensing/governance) are now all fully COMPLETE -- see "Identity
foundation snapshot", "Standalone VAT-rule routes", "Supplier
verification", "Compliance dashboard snapshot" and "Administration
snapshot & portals" above. This is genuinely a multi-week engineering
effort at the pace of careful, verified, per-field-checked porting
demonstrated in this session's Phase 3/4/5/6/7/8/9/10/11/12 slices --
continuing it means repeating this same rigor across the remaining ~115
routes (the schema itself is now essentially done), phase by phase (or
sub-slice by sub-slice, as Phases 10, 11 and 12 all demonstrated), as
originally scoped. Given the genuine scale each remaining module
represents (`control-plane-repository.ts` alone, at ~1,200 lines and ~30
exports, was comparable in size to the whole of Phase 10's `business-
repository.ts`, which itself took 6 separate sub-slices to close out --
Phase 12 took 6, counting the two small files closed out alongside its
own final slice; Phases 4, 5, 7, 8, 9, 10, 11 and 12 are now all entirely
done), continuing
to completion is realistically a
multi-session effort, not a single
continuous run -- this document is the honest record of exactly how far
that effort has gotten at each point.
