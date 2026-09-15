# Consolidated open items from the 14 red-team reports (2026-09-15)

`docs/LAUNCH_READINESS_BACKLOG.md`'s "Recommended next step" section names
"the handful of red-team reports above that named an explicit,
not-yet-individually-verified follow-up in their own 'what this pass did
not cover' section" as the buildable-now backlog once #1/#3/#4/#6/#7/#8/#10
were closed or blocked. This document makes that concrete: every one of
the 14 dated `docs/RED_TEAM_ASSESSMENT_*.md` reports was read in full and
checked against the current codebase and `docs/MIGRATION_MATRIX.md` for
what it explicitly flagged as not covered, still open, or a follow-up not
yet done. Findings already fixed within their own report, and items later
closed by a subsequent report or session (the duplicate-submission-sweep
follow-ups, closed by RT-014/015/016 the same day; TOTP/`password.confirm`
step-up, fully cut over 2026-09-15; sidebar per-permission gating, reverted
2026-09-15 per explicit user request) are not carried forward here.

Not a new red-team pass -- no new reproduction was attempted beyond
confirming each item is still true in the code as it stands today. Treat
each line as a pointer back to its source report, not a restatement of it.

**Update (2026-09-15, same day):** the small batch (#1-#5) is now closed
-- four genuine fixes plus one audited-clean result, each with its own
regression test or live verification. See each item's own strikethrough
entry below for what changed. #4 also turned up a real, previously-
unnoticed instance of RT-017's own bug in four controllers the original
grep sweep never matched, not just a verification of the existing fix.

**Update (2026-09-15, same day):** item #6 (self-service session logout)
from the medium batch is also closed -- see its own strikethrough entry.

**Update (2026-09-15, same day):** item #7 (authorization-check TOCTOU
races) is also closed -- see its own strikethrough entry for what a
focused audit actually found and fixed.

**Update (2026-09-15, same day):** item #8 (exhaustive stale-read sweep)
is also closed -- see its own strikethrough entry for the full ranked
findings list and what was fixed vs. left as genuinely lower-risk.

**Update (2026-09-15, same day):** item #9 (JSON API payload fuzzing) is
also closed -- see its own strikethrough entry. Found and fixed 6
instances of a real, JSON-only-reachable coercion bug plus one genuine
crash (500) on the highest money-value endpoint in the app.

**Update (2026-09-15, same day):** item #10 (risk-scoring calibration
review) is also closed, with an honest caveat -- see its own
strikethrough entry for what an audit without real transaction data can
and can't do.

**All 10 buildable-now items (the small batch #1-#5 and the medium
batch #6-#10) are now closed as of this update.** What remains on this
document is the 1 item still genuinely blocked on external access
(#11, production OPcache verification -- fully audited/documented,
just needs someone with host access to run two commands) and the 1
item that's out of scope for a code-only fix (multi-invoice circular
self-dealing -- needs a beneficial-ownership data model this platform
doesn't have). Item #12 is now split: its query-plan/N+1 half is
closed (see its own strikethrough entry), its raw-throughput half
remains blocked on load-testing tooling this sandbox doesn't have.

## Buildable now, small

1. ~~**`WorkflowService::createDelegation()`/`revokeDelegation()` have no
   duplicate-name/pairing guard**~~ **CLOSED (2026-09-15).** Source:
   `RED_TEAM_ASSESSMENT_2026-09-13-DUPLICATE-SUBMISSION-SWEEP.md`. Both
   now take the same `CommandLedger` idempotency-key pattern
   `assignWorkflow()` already used (validate key, hash+check for a prior
   replay, record inside the transaction); `revokeDelegation()` also
   picked up the affected-row guard from #2 below, since it had the
   identical unguarded-audit-write race. Both controllers and the Blade
   forms (`<x-idempotency-key/>`) updated; a new regression test proves a
   replayed create returns the same delegation, not a second row.
2. ~~**`WorkflowService::decideWorkflowTask()`'s audit-log write isn't
   guarded by the assignment update's affected-row count**~~ **CLOSED
   (2026-09-15).** Source: same report. The `workflow_assignments` UPDATE
   now runs first with its affected-row count checked -- 0 rows throws
   `RepositoryConflictException` before the `workflow_approvals` insert
   ever runs. New regression test (`WorkflowTest`) simulates the race via
   `DB::listen()`, the same technique `ComplianceCaseTest`'s own race
   regressions established.
3. ~~**`RefundService::dispute()`/`RiskService::approveAction()` got the
   RT-020 stale-read fix by pattern parity, never their own regression
   test**~~ **CLOSED (2026-09-15).** Source:
   `RED_TEAM_ASSESSMENT_2026-09-14-RESILIENCE-TO-USER-ERRORS.md`. Both now
   have their own independent race regression (`RefundClaimTest`,
   `ComplianceCaseTest`), same `DB::listen()` simulation technique as the
   report's own precedent tests.
4. ~~**The other ~6 `*ViewController` classes were only grep-checked, not
   live-reproduced, for the RT-017 int-cast silent-coercion bug**~~
   **CLOSED (2026-09-15) -- and a genuine, previously-unnoticed instance
   found and fixed.** Source: `RED_TEAM_ASSESSMENT_2026-09-14-INPUT-
   VALIDATION.md`. A fresh grep confirmed the original sweep's literal
   `(int) $request->input(...)` pattern is gone everywhere -- but a
   *different*-named private helper, `centsFromDecimal()` (`(int)
   round(((float) $amount) * 100)`), carried the exact same coercion bug
   in four controllers RT-017 never looked at:
   `AuditCaseViewController`, `DisputeViewController`,
   `ObligationViewController`, `VatLifecycleViewController`. Fixed with a
   new shared `Controller::safeDecimalCentsInput()` helper (same contract
   as `safeIntegerInput`/`safeMicrosInput`); each controller's own
   `centsFromDecimal()` removed. Regression test added for each of the 4,
   plus live-verified over real HTTP (`/obligations`, `/quotations`) that
   a non-numeric amount now gets a clean field error, not a silent
   zero-amount row.
5. ~~**"Remember me" cookie lifecycle was never independently audited**~~
   **CLOSED (2026-09-15), audited, no gap found.** Source:
   `RED_TEAM_ASSESSMENT_2026-09-14-AUTHENTICATION-SESSION-ROBUSTNESS.md`.
   Checked whether any other account-locking event (employee termination,
   `OrganisationAdminService`'s own `User::status = SUSPENDED`) has the
   same gap RT-019 fixed for password reset -- an already-authenticated
   session or remember-me cookie surviving the lock. It doesn't:
   `Gate::define('permission', ...)` in `AppServiceProvider` calls
   `$user->isActive()` fresh on *every* privileged request (not just at
   login), and per that Gate's own doc comment every one of this app's
   165 route files is permission-gated. Live-verified over real HTTP: an
   already-logged-in session got `200` on `/dashboard`, then `403` on the
   very next request with the *same* session cookie, no logout or
   session-table wipe involved, immediately after flipping that user's
   `status` to `SUSPENDED` directly in the database. This reasoning
   covers a remember-me-only "session" identically, since Laravel
   resolves both through the same fresh per-request `User` model the Gate
   checks. Password reset's own explicit `remember_token` rotation +
   `sessions` table wipe (RT-019) remains real defense-in-depth for its
   own narrower threat model (a compromised password, not an account
   lock), just not something every lock-type event turns out to need.

## Buildable now, medium

6. ~~**No self-service "log out my other sessions"**~~ **CLOSED
   (2026-09-15).** Source: same authentication/session report. The
   Security page now lists every `sessions` row for the current user
   (device/browser, IP, last active, "This device" badge) with a
   per-session revoke action plus a "log out other sessions" bulk action,
   both on `MfaViewController` (same self-service, no-step-up-needed
   reasoning as MFA enroll/verify). Revoking is scoped to the actor's own
   `user_id` so one user can never end another's session by guessing its
   ID; revoking your own current session is refused with a form error
   rather than silently applied. New regression tests cover listing
   (own-only, not another user's), scoped revoke, the current-session
   refusal, and "log out others" leaving the current session intact.
7. ~~**Authorization-check TOCTOU races (a scope check racing the write it
   gates) were named as a gap but never actually probed under true
   concurrency**~~ **CLOSED (2026-09-15).** Source:
   `RED_TEAM_ASSESSMENT_2026-09-14-AUTHORIZATION-ISOLATION.md`. A focused
   audit (not a general sweep -- see `docs/MIGRATION_MATRIX.md`'s own
   entry for the full ranked candidate list) found one real, wide-open
   gap and one structurally identical sibling, both fixed; several
   narrower same-request candidates were found genuinely safer (small
   window, no I/O between check and write) and left as-is rather than
   forcing a fix onto every one:
   - **`WorkflowService`: a delegation redirect resolved once at Assign
     was never re-verified at Decide.** `resolveAssignee()` overwrites
     `assigned_user_id` with the delegate's own id at assign time, so
     `decideWorkflowTask()`'s only identity check (a plain
     `actor->id === assigned_user_id` comparison) could no longer tell a
     direct assignment from a delegated one -- `revokeDelegation()` never
     stopped an already-redirected task from still being decided by the
     former delegate. Not a narrow race: the gap was open for the task's
     entire pending lifetime (hours to days). Fixed by carrying the
     delegator's id into a new `workflow_assignments.delegated_from_user_id`
     column and re-verifying, every decision, that a covering ACTIVE
     delegation still exists. New regression test proves a task routed
     through a delegation can no longer be decided once that delegation
     is revoked (and that the original delegator can't step in either --
     pre-existing behaviour, unchanged).
   - **`AccessGovernanceService::decideAccessRequest()`: the same
     shape.** `requestRoleAccess()`'s subject-membership check only ran
     at request time; a request can sit `PENDING_MANAGER` for days, and
     `offboardUser()`/`certifyQuarterlyAccess(REVOKE)` ending the
     subject's membership in between never stopped a later APPROVE from
     still granting the role. Now re-checked on every APPROVE. While in
     this method, also gave `access_requests.status` the same guarded-
     UPDATE-with-affected-row-check pattern this codebase already uses
     elsewhere (it was a plain unguarded Eloquent `->update()`, a
     same-request sibling of the RT-020 stale-read race, not the TOCTOU
     pattern itself) -- new regression tests cover both the
     offboarded-subject refusal and the concurrent-decision race.
8. ~~**No exhaustive sweep of every `Model::update()` call for the RT-020
   stale-read race pattern**~~ **CLOSED (2026-09-15).** Source: the same
   resilience report. A systematic sweep of every `app/Services/*`
   subdirectory (23 in total) found 9 genuine unguarded status-transition
   writes beyond what RT-020's own recon and the item-#7 pass already
   covered, plus several narrower same-request cases judged genuinely
   lower-risk and left as-is. Full ranked list and per-fix detail in
   `docs/MIGRATION_MATRIX.md`; summary here:
   - **`LicensingService::changeState()`** -- `state_version` existed for
     optimistic locking but was only ever bumped, never checked; a
     concurrent ACTIVATE/SUSPEND/RENEW race could corrupt the license
     audit trail or double-extend a subscription period on RENEW.
   - **`OrganisationAdminService::activateEmployee()`/
     `terminateEmployee()`** -- both could double-consume or double-
     release a paid license seat under a concurrent race.
   - **`AccountingService::reverseJournalEntry()`** -- a race could post
     two reversing journal entries against one original; the guarded
     write on the original now closes both this and the separate
     `alreadyReversed` duplicate-citation race at once.
   - **`ExpenseService::submit()`/`approve()`/`reject()`** -- a
     concurrent approve/reject race on the same maker-checker expense
     could otherwise both succeed.
   - **`VatRuleService::approve()`** -- both the rule's own APPROVED
     transition and the superseded rule's `effective_to`/`superseded_by`
     write were unguarded, risking corruption of the VAT rate chain this
     feeds directly.
   - **`ProjectService::approveBudget()`** -- a race with two different
     approved amounts could lost-update the approved figure.
   - **`RegistrationService::decide()`** -- both the APPROVE and REJECT
     branches were unguarded; a race could materialise a live Taxpayer/
     Organisation/Membership *and* mark the application REJECTED.
   - **`AuditCaseService::addEvidence()`'s supersede path** -- a race
     could let two new evidence rows both claim succession of the same
     original, corrupting the chain-of-custody this model exists to
     protect.

   All 9 got the same guarded-UPDATE-with-affected-row-check pattern used
   throughout this codebase, plus a dedicated `DB::listen()`-simulated
   race regression test each. Left as-is (narrower same-request windows
   with no intervening I/O, or an idempotent write target where the only
   loss is attribution metadata/duplicate log rows, not a genuine
   double-side-effect): `AccountingService::closePeriod()`,
   `ObligationService::markSatisfied()`, `PosApiClientService::revoke()`,
   `MfaService::verifyTotpEnrollment()`,
   `UserRoleScopeGrantService::revoke()`, and
   `AccessGovernanceService::certifyQuarterlyAccess()`'s review-completion
   write -- full detail in `docs/MIGRATION_MATRIX.md`.
9. ~~**Malformed/oversized JSON API payloads were never fuzzed** against
   the `*Controller` JSON API siblings of the fixed Blade forms~~ **CLOSED
   (2026-09-15).** Source: `RED_TEAM_ASSESSMENT_2026-09-14-INPUT-
   VALIDATION.md`. Every `*Controller`/`*ViewController` pair was mapped
   for shared-validator risk, then a representative set of the highest
   money/approval-value JSON-only or JSON-first endpoints was actually
   fuzzed with real malformed HTTP payloads (arrays where scalars were
   expected, oversized digit strings, non-list "lines", etc.), rather
   than just re-reading validator code and assuming it was fine. Found
   two distinct real bugs, both closed with regression tests:
   - **A bare `(string) $x` cast on a JSON value silently converts any
     array to the literal 5-character string `"Array"`**, which then
     slides straight past a `< 5` minimum-length check as if it were
     real text -- a different failure shape than RT-017's own
     silent-zero-coercion bug (RT-017 was numeric fields cast with
     `(int)`; this is free-text fields cast with `(string)`), so nothing
     RT-017 tested would have caught it. Six instances found and fixed,
     all approval/administrative-action audit-trail reason fields:
     `WorkflowService::decideWorkflowTask()`'s `reason`,
     `WorkflowService::revokeDelegation()`'s `reason`,
     `WorkflowValidator::delegation()`'s `reason` (createDelegation),
     `AccessGovernanceService::decideAccessRequest()`'s `reason`,
     `LicensingValidator::stateChange()`'s `reason`, and
     `OrganisationAdminValidator`'s `approval_reference`
     (appointAdministrator). Each now guards with `is_string($x) ? $x :
     ''` first, matching this codebase's own `textValue()`/`text()`
     idiom used elsewhere -- a non-string value normalizes to `''`,
     which correctly fails the same minimum-length check instead of
     sliding past it.
   - **A genuine crash (500), not just a coercion bug:** `POST
     /api/v1/invoices` -- the highest money-value endpoint in the app --
     threw an uncaught `TypeError` ("Unsupported operand types: string +
     int") when `lines` was a JSON *object* (e.g. `{"foo":"bar"}`)
     instead of an array. `InvoiceCalculator::calculateAndValidate()`'s
     own `is_array($rawLines) && count($rawLines) > 0` check passed for
     an associative array, so the following `foreach` iterated with a
     string key and crashed computing `$index + 1`. Fixed with an
     `array_is_list()` check. This is exactly the "genuinely untested,
     never actually fuzzed" gap this item's own title named -- the code
     had been read as "looks safe" (bcmath overflow guard, try/catch
     around amount parsing) but this specific shape had never been
     tried against a real request.

   Also confirmed clean (422s, not crashes) without needing a fix:
   `VatRuleService`'s `rate_bps`/`tax_category` fuzzing (array, oversized
   digit string), and `InvoiceCalculator`'s handling of a scalar
   `customer`, a scalar line `tax`, and an overflowing `payable_amount`.
   New regression tests for every fix and every confirmed-clean case.
10. ~~**`InvoiceCalculator::score()`'s risk-threshold calibration overall
    was never audited**, only its blind spot for self-dealing~~ **CLOSED
    (2026-09-15), audited honestly -- not recalibrated, because this
    sandbox has no real transaction data to recalibrate against.**
    Source: `RED_TEAM_ASSESSMENT_2026-09-14-FRAUD-RESISTANCE.md`, which
    itself named exactly this limitation ("whether its value/category
    thresholds are otherwise well-calibrated is a separate question this
    pass does not answer"). What an audit *can* do without real data,
    and what it did:
    - **Structural/logical correctness review.** No off-by-one, no
      double-counting, no dead branch, no threshold gap between the two
      value tiers -- confirmed sound. The `CREDIT_NOTE`-only (not
      `DEBIT_NOTE`) scoring bump is deliberate, not a bug: a credit note
      reduces output VAT liability (the fraud-prone direction -- VAT
      carousel/refund fraud runs through credit notes, not debit notes).
    - **Closed a genuine, total absence of test coverage.** Before this,
      not one test anywhere in this codebase asserted on `risk_level` or
      a value-threshold boundary -- the scoring function had never been
      exercised by name. New tests pin down every threshold explicitly
      (both value tiers' exact `>=` boundaries, the 45-point HIGH
      crossing via two smaller factors combining, the LOW-but-still-
      logged-as-MEDIUM-severity-exception behavior for a single small
      risk factor) so any future change to these numbers is now a
      deliberate, reviewed diff against a named, failing test -- not
      silent, unnoticed drift.
    - **Named one concrete, real limitation rather than inventing
      numbers to fix it.** The two absolute-dollar value tiers
      (N$250,000 / N$1,000,000) step rather than scale, and are
      per-invoice with no supplier-level historical or aggregate
      signal -- an invoice at N$249,999.99 (one cent under the first
      tier) scores identically to one at N$10,000, and a taxpayer could
      in principle structure one large transaction as several invoices
      each just under a tier to avoid the extra scrutiny. This is real
      and worth a follow-up, but *fixing* it responsibly needs either
      real transaction-volume data (to know what an actual N$250,000+
      taxpayer's invoice pattern looks like, so a new threshold isn't
      just a different guess) or a genuinely different design
      (supplier-level rolling aggregates, statistical/percentile-based
      scoring instead of fixed dollar tiers) -- both bigger than a
      calibration tweak and both out of scope without the data or a
      product decision this pass can't make on its own.

## Blocked on external access (already tracked in the backlog)

11. **Production OPcache config was never independently verified**
    (source: `RED_TEAM_ASSESSMENT_2026-09-02.md`, RT-004; tracked as
    backlog item #6). Re-audited (2026-09-15): every piece of this that
    doesn't require an actual production host is already done and
    verified consistent -- `docs/DEPLOYMENT.md`'s required directives,
    `deploy/php-fpm/99-vat-msa.ini` (matches those directives exactly),
    `deploy/provision.sh` (installs the extension, installs the ini),
    and `deploy/deploy.sh` (reloads PHP-FPM every release, which is what
    makes `validate_timestamps=0` safe). What's left is genuinely just
    running `php -m | grep opcache` / `opcache_get_status()` against the
    real `vat.safi-nuru.com` host after a deploy -- no code or config
    left to write, still blocked on someone having that access.

12. ~~**Real query/N+1 performance at production-representative data
    volumes was never tested**~~ **CLOSED (2026-09-15) for query-plan/N+1
    testing; still blocked for raw throughput.** Source:
    `RED_TEAM_ASSESSMENT_2026-09-14-CONCURRENT-USER-SIMULATION.md`
    (tracked as backlog item #7). That report's own honest finding was
    it needed "either a production-scale seed or a staging environment"
    to test this -- a synthetic seed is buildable without external
    access, so built one:
    `database/seeders/SyntheticLoadSeeder.php` (run on demand via
    `php artisan db:seed --class=SyntheticLoadSeeder`, not part of the
    default install) generates 20 taxpayers/organisations, 110 users,
    5,000 invoices, 2,000 expenses, 500 audit cases (2,000 evidence
    rows), 1,000 documents, and 200 fixed assets -- run against a real
    local MySQL instance (28s to seed), not simulated.

    A static-analysis pass across every `*ViewController`'s list/index
    method found most of this codebase already disciplined about N+1
    (denormalized summary columns or `->with()`/batched `whereIn()`
    reads throughout -- Invoice, AuditCase, Workflow, Document, Report,
    Compliance-overview list pages all confirmed clean). Four genuine
    N+1s were found and fixed, each made concretely visible for the
    first time by actually running the seeded volume through the real
    page, not just reading the code:
    - `OperationsViewController::index()`'s expense register -- lazy
      `category`/`supplier` plus a `DocumentMetadata::find()` per row
      (up to 3 extra queries/row).
    - The same controller's project panel -- a `ProjectBudget`/
      `ProjectCost` `SUM()` query pair per row (2 extra queries/row).
    - `QuotationService::search()` -- every row ran through `present()`
      (built for the single-record `find()` case), lazy-loading
      `customer` and running a full `QuotationLine` query per row (the
      worst of the four: 2 extra queries/row, one of them a full table
      scan of line items never even rendered on the list view). A new
      `presentSummary()` variant (no `lines`) plus `->with('customer')`
      fixes it.
    - `BusinessPartyService::search()` -- a `PartyRelationship` query
      per row, reached from both the parties register and the
      `OperationsViewController` supplier filter.

    All four fixed with the established `->with()`/batched-`whereIn()`
    pattern already used correctly elsewhere in this codebase. New
    regression tests assert query counts stay small and row-count-
    independent at a 30-row scale (an unfixed N+1 would be unmistakable
    at that size: the expense/project page went from 15 to 44 queries
    without the fix). Live-verified over real HTTP against the full
    5,000-invoice/2,000-expense synthetic dataset: every page tested
    (`/operations`, `/quotations`, `/invoices`, `/audit-cases`, as both
    a taxpayer-scoped and a national-scope NamRA user) rendered
    correctly in well under 200ms.

    **Still genuinely blocked, not closed by this**: raw throughput/
    concurrency under real simultaneous load (backlog item #7's other
    half) needs actual load-testing tooling (k6, Apache Bench, or
    similar) against a non-local target -- neither exists in this
    sandbox. This pass closes the query-plan/N+1 half specifically,
    which needed data volume, not load-testing tools, to test.

## Out of scope for a code-only fix

- **Multi-invoice circular self-dealing between colluding taxpayers**
  (source: the fraud-resistance report). Two taxpayers under common
  control trading invoices back and forth to inflate turnover would not
  trigger the same-taxpayer check RT-018 added. Needs a
  beneficial-ownership data model this platform doesn't have -- a KYC
  capability, not a bug fix.
