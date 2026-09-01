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
| 4 | Convert database schema to MySQL migrations | PARTIAL -- 60 of 155 tables. The identity/access core (taxpayers, users, organisations, branches, identity_providers, identity_links, access_roles, access_permissions, role_permission_grants, organisation_memberships) plus Phase 8's registration/audit infrastructure (audit_events, outbox_events, taxpayer_identifiers, organisation_capabilities, registration_applications, registration_verifications) plus Phase 9's invoice/VAT core (vat_rules, invoices, invoice_lines, certificates, invoice_corrections, ledger_entries, vat_transactions, reconciliation_exceptions, idempotency_records, security_events) |
| 5 | Convert seed data | PARTIAL -- RoleSeeder, PermissionSeeder, VatRuleSeeder, DemoSeeder written and verified; two genuine gaps found and completed (see "Source-fidelity findings" below) |
| 6 | Authentication | COMPLETE for its actual scope -- real Laravel session auth (login/logout, password hashing, CSRF, rate-limited attempts, session regeneration, account-status check) verified end-to-end over HTTP; no password reset flow yet |
| 7 | Role/permission/organisation security | COMPLETE for its actual scope -- `App\Support\Access\Permissions` (RBAC) and `App\Support\Access\TenantScope` (tenant isolation) are now genuinely exercised by every Phase 8 controller via `Gate::authorize('permission', ...)` and `OrganisationService::requireInScope()`/`get()`, proven by real 403s in the test suite (a `TAXPAYER_VIEWER` denied `registrations:submit`, a `TAXPAYER_OWNER` denied `taxpayers:suspend`) and by cross-tenant scope checks on every organisation-scoped read/write. No Eloquent *global* scope class exists yet (each service calls `TenantScope` explicitly instead) -- a reusable trait is a natural follow-up once more modules land, not a gap in the security property itself. |
| 8 | Organisations, taxpayers, administration | COMPLETE for its actual scope (see below) -- registration submission/decision (with materialization), taxpayer suspension, branch list/create/update, and membership assignment. NOT covered yet: employees/positions/departments/HR org-chart tables, organisation-defined custom roles (`organisation_roles`/`organisation_role_permissions`), access requests/reviews, and the `GetIdentityFoundationSnapshot`/administration-dashboard aggregate query -- deferred, not silently dropped. |
| 9 | Invoices and VAT | COMPLETE for its actual scope (see below) -- invoice certification (`TAX_INVOICE`/`SIMPLIFIED_TAX_INVOICE`/`SELF_BILLED_INVOICE`) and correction (`CREDIT_NOTE`/`DEBIT_NOTE`) submission, VAT-rule resolution, idempotent replay (including the concurrent-race recovery path), the ledger/certificate/audit/outbox/security-event side effects, and invoice list/detail reads. NOT covered yet: `cancelInvoice`, `explainInvoiceVat`'s full computation/timeline, `getTransactionTimeline`, the standalone VAT-rule evaluate/propose/approve routes, and the whole VAT-period/return/adjustment/reconciliation-workflow surface built on top of these tables -- deferred, not silently dropped. |
| 10 | Accounting/commercial | COMPLETE for its actual scope (see below) -- all 5 sub-slices of business-repository.ts's ~34 functions: business parties, quotations (incl. conversion into a real certified invoice via Phase 9's InvoiceService), accounting (chart of accounts, journal posting/reversal, period close, trial balance, financial statements), expenses (categories, the DRAFT->SUBMITTED->APPROVED/REJECTED maker-checker lifecycle, expense reporting), inventory (products, warehouses, stock movements/transfers with weighted-average costing, availability/valuation), and projects (budgets with maker-checker approval, cost posting from an approved expense or manually, profitability reusing the accounting infrastructure for revenue). The one function NOT ported: `verifySupplier`/party verification snapshots -- it reuses `classifyTransaction` from the still-unported `identity-repository.ts`, deferred not silently dropped. |
| 11 | Compliance/audits/disputes/refunds/risk | PARTIAL -- slices 1-2 of compliance-repository.ts's ~30 functions COMPLETE for their actual scope (see below): audit cases (the full PROPOSED->...->CLOSED lifecycle state machine, findings, evidence with custody events and legal hold, append-only notes), tax obligations (create/mark-satisfied), disputes (taxpayer self-filing), risk (assign review/approve action/evaluate/restricted query, including the risk->case escalation gate), communications/conversations (SendNotice/Respond/Close/Inbox/GetConversation, referencing an audit case or reconciliation exception), and the standalone notification commands (queue/cancel/mark-read/preferences/list). NOT covered yet within Phase 11: refunds (blocked on Phase 9's still-deferred vat_return_versions/tax_rule_sets tables; REFUND_CLAIM-referenced notices are consequently deferred alongside it), DOCUMENT/VAT_RETURN-sourced evidence citation, and the compliance dashboard snapshot aggregate -- deferred, not silently dropped. |
| 12-15 | Portals/licensing/governance through legacy importer and deployment docs | NOT STARTED |

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

**Explicitly not ported in this slice** (see the Phase 11 matrix row
above): refunds (`requestRefund`/`getRefundClaimChecks`/
`transitionRefundClaim`/`disputeRefund` -- fundamentally anchored to
`vat_return_versions`, a real prerequisite this migration has not built
yet, not a scoping choice), `DOCUMENT`/`VAT_RETURN`-sourced evidence
citation (both need tables from still-unported modules), and
`getComplianceSnapshot` (the fixed-list dashboard aggregate, consistent
with the same deferral pattern applied to `getBusinessPlatformSnapshot` in
Phase 10). The source's own partial unique index on `audit_evidence`
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

## Source-fidelity findings (genuine gaps in the original, not introduced here)

Two places where the TypeScript source's own seed data was incomplete
relative to its own `lib/domain/access.ts` RBAC definitions, discovered while
porting and completed rather than carried forward silently:

1. **`access_roles` never seeded `SELLER_ADMIN`, `SELLER_OPERATOR`,
   `SELLER_VIEWER`, `BUYER_ADMIN`, `BUYER_USER`**, even though
   `ROLE_PERMISSIONS` grants all five real permission sets. Tolerated in the
   source because `app_users.role` there is a plain unconstrained `TEXT`
   column with no FK to `access_roles`. `RoleSeeder` completes the registry
   (marked distinctly in its own doc comment) since the Laravel schema's
   `role_code` columns carry real foreign keys.
2. **`access_permissions` never seeded 11 permission codes** that
   `ROLE_PERMISSIONS` grants: `taxpayers:suspend`, `registrations:approve`,
   `invoices:cancel`, `vat-rules:read`, `vat-rules:manage`,
   `cases:override-sod`, `obligations:manage`, `payments:record`,
   `security:manage`, `accounting:close-period`, `documents:manage`.
   `PermissionSeeder` completes these too, marked distinctly, with
   resource/action/classification values inferred from the seeded rows' own
   pattern (not verified source data -- flagged as such in the seeder's
   comment).

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

Phase 4 (95 more tables), Phase 5 (remaining seed data -- identity proofing,
licensing, navigation, etc.), Phase 7's reusable Eloquent
organisation-scope trait/global scope, the rest of Phase 8
(employees/positions/departments, organisation-defined custom roles, access
requests/reviews, the administration-dashboard aggregate), the rest of
Phase 9 (`cancelInvoice`, `explainInvoiceVat`'s full computation, transaction
timeline, standalone VAT-rule evaluate/propose/approve routes -- see the
Phase 9 verification section above), the rest of Phase 10 (`verifySupplier`
only -- see the Phase 10 verification sections above), the rest of Phase 11
(refunds and DOCUMENT/VAT_RETURN evidence citation, both blocked on
still-unbuilt prerequisites -- see the Phase 11 verification sections
above), and Phases 12 through 15 in full (portals/licensing/governance,
documents/integrations/offline/reports, the legacy D1 importer, and
deployment documentation) are all outstanding. This is genuinely a
multi-week engineering effort at the pace of careful, verified,
per-field-checked porting demonstrated in this session's Phase
3/4/6/7/8/9/10/11 slice -- continuing it means repeating this same rigor
across the remaining ~95 tables and ~163 routes, phase by phase (or
sub-slice by sub-slice, as Phases 10 and 11 both now demonstrate), as
originally scoped. Given the genuine scale each remaining module
represents (Phase 10's own `business-repository.ts` alone was larger than
everything ported in Phases 8 and 9 combined, and took 5 separate
sub-slices to close out; Phase 11's `compliance-repository.ts` is now
essentially complete except refunds, which -- once Phase 9's
VAT-return-generation prerequisite exists -- is its own substantial
sub-slice on top), continuing to completion is realistically a
multi-session effort, not a single continuous run -- this document is the
honest record of exactly how far that effort has gotten at each point.
