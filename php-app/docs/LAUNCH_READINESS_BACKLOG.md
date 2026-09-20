# VAT-MSA: Launch Readiness Backlog

Compiled 2026-09-03, refreshed 2026-09-13, refreshed again 2026-09-15,
refreshed again 2026-09-20 (every remaining `$plannedRoute` sidebar
placeholder closed -- see item #2's own update below).
`docs/MIGRATION_MATRIX.md`
remains the authoritative, continuously-updated record of what's been
built; this document is a prioritized view specifically answering "what's
left before a real launch," pulling from that record, `docs/DEPLOYMENT.md`'s
"What is not done yet," and `docs/RED_TEAM_ASSESSMENT_2026-09-02.md`.
Every item below was re-checked against the actual codebase as it stands
today, not inferred from memory or the original 2026-09-03 pass alone --
see each item's "Evidence" line. Items closed since the original compile
are marked **CLOSED** with the date and what closed them, not deleted --
same convention `docs/MIGRATION_MATRIX.md` uses throughout.

## How to read this

- **Critical**: launch cannot mean what it's supposed to mean without this.
- **High**: needed before trusting the system with real data and real users.
- **Medium**: hardening and gaps worth closing, not launch-blocking on their own.
- **Blocked**: cannot be started with what's currently available -- says on what.
- **Buildable now**: no external dependency (credentials, third-party spec,
  infrastructure access) is needed to start.

---

## Critical

### 1. Real ITAS integration
**Status: Blocked -- needs NamRA's actual submission API contract and credentials (confirmed with the user 2026-09-03; not available yet).**

`App\Integrations\Itas\UnavailableItasIdentityAdapter` is the *only*
implementation of the tax-authority submission port
(`App\Integrations\Itas\ItasIdentityPort`). It unconditionally reports
`configured: false` and throws `ItasIntegrationUnavailableException` on
every call. `VatLifecycleService::submitReturn()` correctly and honestly
routes every submission attempt into the `BLOCKED_CONFIGURATION` status
as a result -- this is not a bug, it's the system truthfully reporting
that no real filing channel exists yet.

This is the single most fundamental gap: the system cannot actually file
a VAT return with NamRA today. Nothing else on this list matters if this
isn't closed before a real rollout.

**Evidence**: `app/Integrations/Itas/UnavailableItasIdentityAdapter.php`
(the only class implementing the port); no other file references
`ItasIdentityPort` as an implementation.

**Unblocking this needs**: NamRA's real submission API documentation
(or a sandbox/UAT environment), authentication credentials/certificates,
and the exact request/response contract for `submitVatReturn` and
`verifyTaxpayer` (see `ItasIdentityPort`'s own interface for the shape
this migration already expects).

### 2. UI coverage: 3 of ~20+ backend modules on `main`, 5 in an open PR
**Status: CLOSED (2026-09-13).** ~~Buildable now, no external dependency.~~

This was the single largest concrete, no-external-dependency gap at the
2026-09-03 compile: 14 Blade views against 196 registered routes, with
only Dashboard, Invoices, and VAT Returns/Periods reachable through a
browser. It no longer describes the system. Across the sessions since,
every remaining backend module gained a real Blade UI: VAT Returns'
write actions, Refund claims, disputes, audit cases, risk indicators,
compliance overview, tax obligations, organisations & identity, business
parties, quotations, accounting, business operations (expenses/
inventory/projects/HR/logistics/fixed assets), administration,
documents, reports & analytics, licensing & entitlements, platform
config, the workflow authoring console, all six portal dashboards plus
the switchboard, and (this session) the Super Admin/NamRA System Admin
access-rights screen. A taxpayer or NamRA officer can now use essentially
every module through the actual application, not just invoices and VAT
returns.

**Evidence (as of 2026-09-13)**: `find resources/views -name "*.blade.php" | wc -l` = 56 (up
from 14); `grep -c "Route::" routes/web.php` = 308. `docs/MIGRATION_MATRIX.md`'s
own "Next steps" section independently confirms: "every phase this
migration originally scoped (1 through 15) is now COMPLETE for its own
actual scope."

**Update (2026-09-20)**: the 2026-09-13 closure above covered every
*whole missing module*. It did not cover a different, narrower class of
gap: individual sidebar links inside already-built modules that were
still wired to `$plannedRoute` -- a stub closure in `routes/web.php`
that gates on the correct permission and renders a generic "not yet
built" page rather than the real one, so the sidebar link worked but led
nowhere real. A full sweep this session found and closed every one of
these still remaining: Supplier Ledger, Customer Ledger, Budgets,
Purchase Orders, Cash Flow Projects, Service Providers, Converted
Quotations, Converted Quotations into Invoices, the three Project
Management views (Create New Project, Ongoing Project Reports, Completed
Projects), and New Credit Note/New Debit Note -- 13 sidebar links across
9 dated `docs/MIGRATION_MATRIX.md` sections (2026-09-19 through
2026-09-20). Each was individually investigated first, not
mechanically stubbed out: about half turned out to be a stale scope note
over an already-existing, already-tested service with no Blade UI
reaching it (Budgets, Cash Flow Projects); a couple were not a new page
at all, just a missing filter/relationship value on an existing page
(Service Providers, Converted Quotations); one pair required closing a
genuine gap in the ported domain service itself, not just the UI
(`ProjectService::activate()`/`complete()`, since nothing in the source
ever moved a project off `PLANNED`); and the last two (New Credit
Note/New Debit Note) carried their own explicit change-control note and
had their form design proposed and approved before being built, unlike
every other item here. `grep -c "\$plannedRoute(" routes/web.php` is now
`0`.

**Evidence (2026-09-20)**: `find resources/views -name "*.blade.php" | wc -l`
= 73 (up from 56); `grep -c "Route::" routes/web.php` = 351;
`grep -c "\$plannedRoute(" routes/web.php` = 0; full suite 784 tests
(up from 573 at the 2026-09-13 closure), 0 regressions. Each item has its
own dated section in `docs/MIGRATION_MATRIX.md` with live-HTTP
verification against the demo organisation and cleanup back to baseline.

---

## High priority

### 3. Real object storage (S3/R2-compatible)
**Status: Blocked on credentials for whichever provider is chosen; the code change itself is small.**

Documents and report exports live under `storage/app/private`
(`FILESYSTEM_DISK=local`) -- no redundancy at the storage layer. Every
service already goes through Laravel's `Storage::disk(...)->put()/get()/
exists()/delete()` interface, so swapping the disk driver is a config
change, not a code change, once real bucket credentials exist.

**Evidence**: `docs/DEPLOYMENT.md`'s "Storage" section.

### 4. Real mail delivery
**Status: Blocked on a real mail provider's credentials.**

`MAIL_MAILER=log` -- no email is ever actually sent; everything writes
to the log instead. This directly affects the password-reset flow built
this session (RT-005) and any future notification email -- both are
fully implemented and tested against the `log` driver, but neither has
ever sent a real message.

**Evidence**: `.env.example`'s `MAIL_MAILER=log`; confirmed live during
RT-005 verification (read the actual logged email rather than an inbox).

### 5. Production runtime re-verification
**Status: CLOSED (verified prior to this session's start).** ~~Buildable
now, once a target host is available to test against.~~

Every verification up to 2026-09-03 ran on PHP 8.2.12 and MariaDB
10.4.32 (XAMPP) -- a substitution for the PHP 8.3+/MySQL 8 target, never
confirmed. That confirmation has since happened: `migrate:fresh --seed`
and the full suite both ran clean against real PHP 8.4.19 and real
MySQL 8.0.46 (not MariaDB), which caught and fixed a genuine MariaDB-vs-
MySQL portability gap (4 migrations gave a `TEXT` column a literal
`->default(...)`, which MySQL 8 rejects outright per the SQL standard --
each now uses MySQL 8.0.13+'s parenthesized-expression syntax instead).
This session's own full suite (573 tests) also ran against that same
real MySQL, not SQLite or MariaDB.

**Evidence**: `docs/MIGRATION_MATRIX.md`'s "Next steps" section, "Re-
verified against the actual target runtime: PHP 8.4.19 and real MySQL
8.0.46" entry.

### 6. OPcache + release-caching, verified on the real host
**Status: Documentation complete (RT-004); verification blocked on production server access.**

`docs/DEPLOYMENT.md`'s "Performance: OPcache and framework caching"
section (added this session) gives the exact `php.ini` directives and
deploy steps required. None of it has been confirmed against the actual
production PHP-FPM configuration -- this assessment never had server
access to check.

**Evidence**: `docs/RED_TEAM_ASSESSMENT_2026-09-02.md`'s RT-004 entry.

### 7. Real load testing
**Status: Blocked on load-testing tooling and a non-local target environment.**

Never done. No such tooling exists in this development environment. The
RT-004 latency numbers are single-request `curl` timing, not
concurrency -- they establish a root cause (OPcache), not a throughput
ceiling under real traffic.

**Evidence**: `docs/RED_TEAM_ASSESSMENT_2026-09-02.md`'s own stated
environment limitations.

### 8. TOTP step-up parity
**Status: CLOSED (2026-09-15) -- infrastructure and full route cutover
both.** ~~Buildable now.~~ A real, server-verified RFC 6238 TOTP
implementation exists and works end to end -- enrolment, verification,
step-up confirmation with anti-replay, and a status read, all with the
identical JSON contract the original TypeScript source's own test suite
specifies (ported from `lib/domain/mfa.ts`/`lib/data/mfa-repository.ts`/
`lib/security/step-up.ts`, which turned out to be present in this
repository -- not something every prior session had noticed). Reverified
independently against Python's `pyotp` reference implementation, not just
self-consistency. A genuinely new self-service Blade UI (`Security (MFA)`
in the sidebar) sits alongside the JSON API, since no `page.tsx` for this
exists in the source either.

**The cutover, same day per explicit follow-up request**: every one of
the 44 routes that used to wear Laravel's `password.confirm` now wears
`App\Http\Middleware\EnsureFreshStepUp` instead, gated on a real
`step_up_events` row via `MfaService::hasFreshStepUp()` -- not a
re-entered password. `ConfirmPasswordController` and the
`/confirm-password` routes are deleted. `App\Support\Access\StepUp`
(the data-conditional gate on report exports) now delegates to the same
mechanism. Live-verified with a real browser session over HTTP: a
non-enrolled user hitting a step-up-gated route is redirected through
enrol -> verify -> confirm-step-up and lands back on the exact original
page, with the retried action succeeding and persisting.

**Evidence**: `docs/MIGRATION_MATRIX.md`'s "TOTP step-up parity
(2026-09-15, infrastructure only)" and "TOTP step-up cutover (2026-09-15)"
sections; `app/Support/Access/Totp.php`; `app/Services/Identity/
MfaService.php`; `app/Http/Middleware/EnsureFreshStepUp.php`;
`tests/Feature/Identity/{MfaTest,MfaViewTest}.php`'s 10 tests plus the
~200 call sites across 22 test files now exercising the real mechanism
via `Tests\Concerns\InteractsWithStepUp`.

---

## Medium priority (hardening, not launch-blocking on their own)

### 9. Platform-config values not wired to a real downstream consumer
**Status: CLOSED (Phase 13, prior to this session).** ~~Buildable now.~~
Three platform-config/access-policy values (`STEP_UP_WINDOW`'s
`window_seconds`, the reports export size limit, and the min cell-
suppression threshold) are now read live via
`App\Support\Platform\PlatformConfigReader` from `StepUp` and
`ReportExportService` -- change-managing one of them through the
Platform console genuinely changes runtime behaviour, not just a stored
row. Every other seeded value remains illustrative only, wired only when
a real consumer needs it -- see `docs/MIGRATION_MATRIX.md`'s "Platform
config now feeds three real consumers" section.

**Evidence**: `grep -rn PlatformConfigReader app/` shows real call sites
in `App\Support\Access\StepUp` and `App\Services\Platform\ReportExportService`.

### 10. Broader security review of the modules beyond the original red-team scope
**Status: Substantially complete for the user's own stated scope
(refreshed 2026-09-15).** The 2026-09-13 refresh above described one
cross-cutting pass plus a single targeted hypothesis. In the two sessions
since, the user's own 10-phase audit brief (business abuse, UI/high-
interaction stress, concurrent-user simulation, authentication/session
robustness, input validation, authorization/role isolation, performance
under heavy use, resilience to user errors, fraud resistance, UX failure
discovery) has been worked through **phase by phase**, each with its own
dedicated report, its own live reproduction against a running instance
with real MySQL (not just code reading), and its own permanent regression
tests. 14 red-team reports now exist in `docs/` (up from the original
single 2026-09-02 pass), collectively finding and fixing 16 further
genuine issues (RT-006 through RT-021) beyond the original assessment's
own 5:

- **Duplicate-submission / business-abuse series** (RT-007 through
  RT-016, four reports across 2026-09-13/14): every write action across
  the whole application -- Invoice Management, Access Rights, Workflows,
  Administration, Documents, Licensing, Organisations, Human Resources,
  Platform, Reports -- was mechanically swept for missing idempotency
  protection, then individually verified. 10 genuine double-submit/
  replay gaps found and fixed, ranging from Critical (a POS credential
  whose secret was permanently lost on a duplicate create) to Low.
- **Input Validation & Robustness** (RT-017): non-numeric amounts
  silently coerced to zero instead of rejected, across 7 call sites in
  3 controllers -- fixed. Stored XSS, raw-SQL injection, and CSV formula
  injection mechanically hunted for across the codebase and found absent.
- **Fraud Resistance** (RT-018): self-dealing invoices (a taxpayer
  certifying a government-backed invoice to themselves) were fully
  undetected by both the explicit business rules and the risk-scoring
  engine -- fixed at the point of certification.
- **Authorization & Role Isolation** (Phase 6, IDOR/horizontal privilege
  escalation): ~45 distinct read/action methods across every
  tenant-owned resource type traced for correct taxpayer/organisation
  scoping. No confirmed finding -- a genuine positive result, not a
  skipped check. One defense-in-depth hardening applied anyway to the
  highest-blast-radius screen in the app.
- **Authentication & Session Robustness** (RT-019): password reset (the
  flow offered specifically for a suspected-compromise scenario) did not
  invalidate the attacker's own pre-existing session -- fixed. Account-
  suspension enforcement and step-up re-auth coverage checked and already
  correct.
- **Resilience to User Errors** (RT-020): nine status-transition write
  paths validated an in-memory read against a stale row with no guard
  against a concurrent second transition silently overwriting the first
  -- fixed with a guarded UPDATE + affected-row check across all nine.
- **UX Failure Discovery** (RT-021) and a follow-up **sidebar audit**: a
  real browser crawl across ten roles, plus a systematic link-vs-
  permission cross-reference, found and fixed two sidebar groups
  rendering dead-end links for seven roles, one fully-built page with no
  navigation entry at all, and (the most structurally interesting find)
  permission-light roles like `SUPER_ADMIN` seeing clickable accordion
  headers that expanded to nothing.
- **Concurrent User Simulation / High Interaction Stress / Performance
  Under Heavy Use**: previously blocked on `artisan serve`'s single-
  request-at-a-time dev server -- unblocked by discovering
  `PHP_CLI_SERVER_WORKERS` gives PHP's built-in server genuine multi-
  process concurrency. Used to re-validate RT-020 under **true**
  concurrency (not simulation), and to confirm a plain-CREATE action's
  duplicate-key race is already caught cleanly by an existing global
  handler (RT-001). No new code fix needed here -- a genuine positive
  result. One honest gap flagged, not glossed over: this dev
  environment's seed data is too small to meaningfully test query
  performance/N+1 behaviour at production scale.

Every one of the user's original 10 phases now has at least one
dedicated, live-reproduced pass behind it. This was not literally every
one of the app's 58 views individually adversarial-tested -- passes
targeted the failure modes and surfaces most likely to matter per phase,
consistent with every report's own "what this pass did not cover"
section -- so further narrow, module-by-module sweeps remain buildable-
now if even deeper assurance is wanted. But the phase-by-phase gap this
item was tracking as of 2026-09-13 is closed.

**Follow-up (2026-09-20, user requested "a deeper security sweep"):**
targeted the modules built this session that had no dedicated adversarial
pass yet (they didn't exist when the 14 reports above ran) -- the New
Credit Note/New Debit Note flow, Purchase Orders, and the Project
Management transitions. Found and fixed two genuine TOCTOU races:

1. **High** -- `InvoiceService::submit()`'s credit-note cumulative-
   credit-cap check ran before its own `DB::transaction()` opened, with
   no lock -- a race with no `UNIQUE` constraint to backstop it (unlike
   the idempotency-key/invoice-number races the same method already
   guards), so two concurrent credit notes against the same original
   invoice could together exceed its certified value/VAT.
2. **Medium** -- `PurchaseOrderService::convertToExpense()` was the only
   one of its six transitions missing the affected-row check every
   sibling has, and it creates a real `Expense` row before that unguarded
   write -- two concurrent conversions of the same order could each
   create their own real Expense, with the loser's left orphaned and no
   error ever shown.

Both fixed with a `lockForUpdate()` inside the transaction, acquired
before any side-effecting write. See
`docs/RED_TEAM_ASSESSMENT_2026-09-20-CORRECTION-RACE.md` for both
findings in full, live pre-fix/post-fix reproduction for each, a
systematic sweep of every other `App\Services\*` aggregate-before-write
pattern (nothing else exploitable found), and one documented,
deliberately-unfixed limitation (per-line credit-quantity tracking
across multiple credit notes -- inherited from the original source's own
aggregate-only cap design, not a migration-introduced gap).

**Evidence**: `ls docs/RED_TEAM_ASSESSMENT_*.md` (15 files);
`docs/MIGRATION_MATRIX.md`'s own dated sections for each pass; full suite
645 tests, 0 regressions as of the 2026-09-15 security-review pass; 784
tests as of the 2026-09-20 `$plannedRoute` sweep in #2 above; 792 tests,
0 regressions as of this follow-up.

### 11. Legacy data cutover
**Status: Blocked on the legacy system's actual data being made
available.** `php artisan legacy:import-d1` is real and generic but has
only ever run against a synthetic fixture (`tests/Feature/Console/
LegacyD1ImportTest.php`) -- no real production dataset exists anywhere
in this repository to test against.

---

## Explicitly corrected: NOT a real gap

**Background-job / outbox-event processing.** `outbox_events` rows are
written by every command (`CommandLedger::outbox()`), but nothing drains
them -- this looks like a gap until you check the table's own migration
comment: *"transactional outbox pattern -- no queue/cron infra in the
source's Workers deployment, matched here rather than assuming
Laravel's queue changes that."* **The original TypeScript source never
consumed this queue either.** It's durable infrastructure for a future
downstream consumer that doesn't exist in the source at all, not even
as a described requirement -- not a broken or missing current feature.
Building a generic consumer with nothing real for it to do would be
speculative scope, not a fix. Revisit this only once a concrete real
consumer (a webhook target, a search index, a partner integration) is
actually needed.

## Outside what this assessment can speak to

NamRA's own sign-off/approval process, legal and regulatory compliance
review, user training materials, a real pilot rollout plan, and
coordination with whoever owns the actual legacy data for a real
cutover. No visibility into any of this from the codebase alone.

---

## Recommended next step (refreshed 2026-09-20, after every remaining `$plannedRoute` placeholder closed)

#8 is now fully closed, infrastructure and the full route cutover both --
see its own entry above. #10 (broader security review) closed the same
refresh, just before it. #2 (UI coverage) has now had its own final
mop-up: every sidebar link that still fell through to a generic
`$plannedRoute` stub -- 13 of them, found by a full sweep of
`routes/web.php` rather than assumed complete -- is now a real page (see
its own updated entry above). With ITAS (#1) still the one genuine
launch-blocker and still blocked on external NamRA credentials/API
access, and #3/#4/#6/#7/#11 all similarly blocked on external credentials
or production host access, **the buildable-now items with no external
dependency left on this list are**:

- The handful of red-team reports above that named an explicit,
  not-yet-individually-verified follow-up in their own "what this pass
  did not cover" section (each report says exactly what it left open).
  Consolidated into a single punch list, cross-checked against the
  current codebase and this document, at
  `docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_2026-09-15.md` -- **every
  buildable-now item on that list (the 5-item small batch and the
  5-item medium batch, #1-#10) is now closed**, the same day it was
  pulled together, across several genuine fixes (a workflow-delegation
  authorization race, 9 unguarded status-transition races, 6 instances
  of a JSON-array-to-"Array"-string coercion bug, an invoice-endpoint
  crash on malformed `lines`) and several honestly-audited-clean
  results. What's left on that list is 2 items already tracked here as
  blocked on external access (#6/#7 above) and one (multi-invoice
  circular self-dealing between colluding taxpayers) that needs a
  beneficial-ownership data model this platform doesn't have, not a
  code fix.
- The `$plannedRoute` sweep itself (see #2's updated entry above): all
  13 remaining sidebar stubs closed 2026-09-19/20. `grep -c
  "\$plannedRoute(" routes/web.php` is `0`. This was the last
  no-external-dependency item of its kind on this list.

With that sweep done, **no further buildable-now, no-external-dependency
item remains on this backlog.**

**Correction (2026-09-20, same refresh):** an earlier version of this
section wrongly described the production-scale performance/N+1 check as
still open. It was already closed on 2026-09-15 -- see item #10's own
entry above and `docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_2026-09-15.md`'s
item #12, both of which this refresh should have cross-checked the first
time. `database/seeders/SyntheticLoadSeeder.php` (real, already built,
run on demand) generated 5,000 invoices/2,000 expenses/500 audit
cases/1,000 documents/200 fixed assets against real MySQL and found 4
genuine N+1s, all fixed with regression tests. What that pass's own
seeder did *not* cover -- because the pages didn't exist yet -- is the
batched-query discipline this session's own new views rely on
(`SupplierLedgerService`/`CustomerLedgerService`, `BudgetsView
Controller`, `CashFlowViewController`, `ProjectManagementView
Controller`, `QuotationService::crossReference()`), each of which
explicitly claims to batch reads into a fixed number of queries
regardless of row count but has never been run against real volume to
confirm that claim. Closed by extending `SyntheticLoadSeeder` with
quotations/projects/business-parties/purchase-order volume and
re-running those specific pages against it -- see `docs/MIGRATION_MATRIX.md`'s
own dated section for the result.

The only remaining half of backlog item #7 (raw throughput/concurrency
under genuine simultaneous load, as opposed to query-plan/N+1 at volume)
stays blocked on real load-testing tooling (k6, Apache Bench, or
similar) run against a non-local target -- neither exists in this
sandbox, and data volume alone doesn't substitute for it.

Everything else genuinely needs something only NamRA/the deploying
organisation can supply -- real ITAS credentials, a mail provider, S3/R2
bucket credentials, production host access for OPcache/load-testing
verification, and a real legacy dataset -- and cannot be closed by
further code changes alone.
