# VAT-MSA Red Team Assessment — The Defeated-Idempotency-Key Sweep

**Date:** 2026-09-14
**Scope:** The user's own explicit follow-up to `docs/RED_TEAM_ASSESSMENT_2026-09-13-
DUPLICATE-SUBMISSION-SWEEP.md`'s named next step: individually verify the 7
grep-identified candidate files that pass left unverified
(`administration`, `documents`, `licensing`, `organisations`,
`operations/human-resources`, `platform`, `reports`).
**Method:** Every write route reachable from those 7 Blade views was traced
to its controller and underlying service method, and every service method
was read in full before any live reproduction was attempted -- the same
"read before you exploit" discipline the prior two passes established.
**Environment:** This session's own sandboxed dev stack -- PHP (`artisan
serve`), real MySQL, full suite (612 tests before this pass, 617 after) run
before and after every change.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request.

---

## 0. What this pass found that the mechanical grep couldn't see

The prior pass's own grep method (`@csrf` present, `<x-idempotency-key/>`
absent) only detects a write action with **zero** idempotency-key support
at the Blade layer. Reading each of the 7 files' underlying services in
full surfaced a second, more insidious variant of the same root cause: a
write action whose **service method already has real, correct
`CommandLedger` support** -- `validateIdempotencyKey()`, `requestHash()`,
`prior()`, `record()`, all present and correct -- but whose **Blade
controller silently defeats it** by calling the service with
`(string) Str::uuid()` generated fresh on every request, instead of
`Controller::formIdempotencyKey($request)`'s stable per-form-render key.

This is worse than the RT-007–010 pattern in one specific way: it looks
protected on inspection. A reviewer grepping for `CommandLedger::record`
in `PlatformChangeService` or `ReportExportService` finds it, in every
write method, and could reasonably conclude the module is hardened. The
bug is entirely in the caller, one layer up, and is invisible unless you
read the exact literal argument passed for the idempotency key at every
call site. `App\Http\Controllers\Controller::formIdempotencyKey()`'s own
doc comment already names this exact failure mode ("a fresh random key
per request silently defeats `CommandLedger`'s replay detection... the
exact bug a red-team pass found across every `*ViewController`") -- this
pass found it had not, in fact, been swept from every `*ViewController`,
specifically `PlatformConfigViewController` and `ReportViewController`.

Nine call sites across these two controllers had this exact defeated-key
bug. Each was read against its service method to determine whether a
**natural** guard (a uniqueness check, a status-transition check) already
made the defeated key harmless, or whether the write action was genuinely
exploitable with no other protection at all:

| Call site | Natural guard? | Outcome |
|---|---|---|
| `PlatformChangeService::requestChange()` | None | **RT-011 (High) -- live-reproduced, fixed** |
| `PlatformChangeService::decideChange()` | Status `PENDING` check + guarded UPDATE | Hardened anyway (§2) |
| `PlatformChangeService::provisionStaff()` | Uniqueness check (`external_user_id`/email) | Hardened anyway (§2) |
| `ReportExportService::publish()` | Status `COMPLETED_INLINE` check + guarded UPDATE | Hardened anyway (§2) |
| `ReportExportService::requestExport()` | None | **RT-012 (High) -- live-reproduced, fixed** |
| `ReportExportService::approveExport()` | Status `PENDING_APPROVAL` check + guarded UPDATE | Hardened anyway (§2) |
| `ReportExportService::cancelExport()` | Status `PENDING_APPROVAL` check + guarded UPDATE | Hardened anyway (§2) |
| `DataProductService::runModel()` | None | **RT-013 (Medium) -- live-reproduced, fixed** |
| `DataProductService::publish()` | Uniqueness check (`model_run_id`) | Hardened anyway (§2) |

Three call sites had no natural guard at all and were genuinely
exploitable; all three were live-reproduced against a running instance
with real MySQL before any fix was written. The other six were hardened
regardless -- the fix is the same one-line controller change either way,
and leaving a known-defeated `CommandLedger` call in place (even behind a
guard that happens to catch the same failure another way) is exactly the
kind of thing that reads as protected but isn't, matching the "fix cheap,
strictly-more-correct hardening the same day" precedent this codebase's
own prior red-team fixes already established.

## 1. Confirmed findings

### RT-011 — Duplicate platform change requests via double-submit

> **Status: FIXED (2026-09-14).** `PlatformConfigViewController::
> requestChange()` now passes `$this->formIdempotencyKey($request)` (a
> stable key rendered once per page load by `<x-idempotency-key/>`,
> already added to all three "Propose change" forms) instead of a fresh
> `Str::uuid()`. `PlatformChangeService::requestChange()`'s own
> `CommandLedger` logic needed no change at all -- it was always correct;
> only the caller was wrong.

| | |
|---|---|
| **Severity** | **High** |
| **User role** | `platform:manage` (`SUPER_ADMIN`/`INFRASTRUCTURE_ADMIN`) |
| **Feature** | Proposing a feature-flag/platform-config/access-policy change (`POST /platform/change-requests`) |

**Reproduction steps (pre-fix, live against a running instance):**
1. Log in as `platform-admin@vat-msa.test` (`SUPER_ADMIN`), load `/platform`.
2. Fire two concurrent `POST /platform/change-requests` requests carrying
   the page's own rendered CSRF token and identical `target_type`/
   `target_id`/`value`/`reason` -- a realistic double-click on "Propose."
3. Count `change_requests` rows for that target.

**Actual behavior (pre-fix):** 0 → 2 `PENDING` `change_requests` rows,
identical `target_id` and `proposed_value`, both awaiting independent
review:

```
id                                    status   target_id                             proposed_value
17d4bd4d-e55a-406b-9d85-a5e77ce49737  PENDING  14280493-f5e4-49b9-a83f-bba7d2cf0bce  {"value":"409600"}
c620d98f-1198-4cc0-83dc-e8c694a47955  PENDING  14280493-f5e4-49b9-a83f-bba7d2cf0bce  {"value":"409600"}
```

**Business impact:** `requestChange()` has no natural duplicate guard
(nothing stops two `PENDING` requests existing for one target) -- the
system's own maker-checker design intends exactly one proposal per
change, decided once by an independent reviewer. A double-click instead
queues the same proposal twice: a reviewer who decides one and stops has
still left a second, identical, un-decided item sitting in the queue
indefinitely (nothing ever prompts them to notice or clean it up), and
the platform's change-request audit log now shows two "requested"
entries for what was one human decision to propose a change. Since both
carry the same `proposed_value`, deciding both does not corrupt the final
config/flag/policy value itself -- but it does corrupt the record of
*how many times, and by whom, a request was made*, the exact governance
property this table exists to preserve.

**Root-cause hypothesis:** `PlatformChangeService::requestChange()` was
always built with correct `CommandLedger` support; `PlatformConfigViewController`
was simply never wired to actually use it -- an omission matching every
prior finding in this series, not a design decision.

**Recommended solution (implemented):** `$this->formIdempotencyKey($request)`
in place of `(string) Str::uuid()`; `<x-idempotency-key/>` added to all
three "Propose change" forms (`platform/index.blade.php`).

**Validation checklist:**
- [x] Two concurrent proposals sharing one rendered form's key → exactly
      one `change_requests` row (re-verified live post-fix: before=2,
      after=3 across the double-submit, i.e. exactly one new row).
- [x] Two genuinely separate page loads still each create their own
      request (`test_a_genuinely_new_change_request_after_a_new_page_load_is_not_treated_as_a_replay`).
- [x] Full suite (617 tests) passes with zero regressions.

---

### RT-012 — Duplicate report exports via double-submit quarantine two copies of a sensitive document for one approval decision

> **Status: FIXED (2026-09-14).** `ReportViewController::requestExport()`
> now passes `$this->formIdempotencyKey($request)`; `<x-idempotency-key/>`
> added to the "Request export" form (`reports/index.blade.php`).

| | |
|---|---|
| **Severity** | **High** |
| **User role** | `reports:run` |
| **Feature** | Requesting an export of a report run (`POST /reports/runs/{id}/export`) |

**Reproduction steps (pre-fix, live against a running instance):**
1. As a `reports:run` holder, run a report inline (`NATIONAL_VAT_AGGREGATE`,
   a non-sensitive `PUBLIC` report, used here specifically so the
   reproduction needs no step-up confirmation first -- the bug is in
   `requestExport()` itself, not in the step-up gate).
2. Fire two concurrent `POST /reports/runs/{id}/export` requests, same
   rendered form's CSRF token, no other input required.
3. Count `report_exports` rows for that run.

**Actual behavior (pre-fix):** 0 → 2 `APPROVED` `report_exports` rows for
the same `report_run_id`, each with its **own** `document_metadata` row
and its own file written to disk under a distinct `exports/{org}/{doc}/`
object key -- a genuine double write to storage, not just a duplicate
database row.

**Business impact:** For a non-sensitive report this is mostly storage
waste and a confusing "My exports" list showing two identical entries.
For a **sensitive** report (`TAX_CONFIDENTIAL`/`RESTRICTED`, requiring
step-up and a second reviewer's approval before download), the same bug
means a double-click creates two separate `PENDING_APPROVAL` exports --
two separate quarantined copies of confidential tax data, each an
independent target for approval. A reviewer working through the "Pending
export approvals" queue sees two rows that look identical and has no way
to tell from the UI that they are the same underlying request submitted
twice; approving both releases **two** downloadable copies of the same
sensitive data where the maker-checker design intended exactly one
reviewed release. This is the same shape of defect as RT-010 (duplicate
approval-gated resource from one user action), applied here to
confidential-data export rather than a workflow task.

**Root-cause hypothesis:** Same as RT-011 -- `ReportExportService::
requestExport()`'s `CommandLedger` logic was correct; `ReportViewController`
passed a fresh `Str::uuid()` instead of the stable per-render key.

**Recommended solution (implemented):** `$this->formIdempotencyKey($request)`
in place of `(string) Str::uuid()`, `<x-idempotency-key/>` added to the
export-request form.

**Validation checklist:**
- [x] Two concurrent export requests sharing one rendered form's key →
      exactly one `report_exports` row (re-verified live post-fix:
      `export_rows = 1`, not 2).
- [x] Two genuinely separate page loads still each create their own
      export (`test_a_genuinely_new_export_request_after_a_new_page_load_is_not_treated_as_a_replay`).
- [x] Full suite passes with zero regressions.

---

### RT-013 — Duplicate analytics model runs via double-submit on "Run model"

> **Status: FIXED (2026-09-14).** `ReportViewController::runModel()` now
> passes `$this->formIdempotencyKey($request)`; `<x-idempotency-key/>`
> added to the "Run model" form (`reports/index.blade.php`).

| | |
|---|---|
| **Severity** | **Medium** |
| **User role** | A national-scope role holding `reports:run` (`DataProductService::runModel()`'s own national-only gate) |
| **Feature** | Running an analytics model against a published report run (`POST /analytics/data-products/{id}/run-model`) |

**Reproduction steps (pre-fix, live against a running instance):**
1. As a national-scope `reports:run` holder, run and publish a
   `SALES_VAT_SUMMARY` report (the seeded `VAT_TRENDS` data product's
   configured source).
2. Fire two concurrent `POST /analytics/data-products/{id}/run-model`
   requests, same rendered form's CSRF token, same `report_run_id`.
3. Count `analytics_model_runs` rows for that data product/report-run pair.

**Actual behavior (pre-fix):** 0 → 2 `COMPLETED` `analytics_model_runs`
rows, same `data_product_id` and `report_run_id`, different `id`.

**Business impact:** Lower severity than RT-011/012 because
`DataProductService::publish()` (the next step, promoting a model run to
the data product's live snapshot) **does** have a natural guard -- a
`model_run_id` uniqueness check on `data_product_snapshots` -- so at most
one of the two duplicate model runs can ever actually be published; the
system cannot end up with two live snapshots from one double-click. The
real impact is narrower: a cluttered "Completed model run to publish"
dropdown showing two indistinguishable entries for what was one analysis
request, and a duplicate `ANALYTICS_MODEL_RUN` audit-trail entry for a
single human action -- a genuine governance/audit-integrity duplicate in
the same family as RT-009, just without RT-009's downstream authorization
consequence.

**Root-cause hypothesis:** Same as RT-011/012.

**Recommended solution (implemented):** `$this->formIdempotencyKey($request)`
in place of `(string) Str::uuid()`, `<x-idempotency-key/>` added to the
run-model form.

**Validation checklist:**
- [x] Two concurrent run-model requests sharing one rendered form's key →
      exactly one `analytics_model_runs` row (re-verified live post-fix:
      `model_run_rows = 1`, not 2).
- [x] Full suite passes with zero regressions
      (`test_double_submitting_the_same_rendered_run_model_form_creates_only_one_model_run`).

## 2. Hardened alongside the confirmed findings (no natural gap, fixed anyway)

Six more call sites had the identical defeated-`Str::uuid()` bug but were
**not** independently exploitable, because each already has its own
natural guard that happens to catch a duplicate submission a different
way (a status-transition check with a guarded `UPDATE`, or a uniqueness
check before insert):

- `PlatformChangeService::decideChange()` -- `status !== 'PENDING'` throws before any write.
- `PlatformChangeService::provisionStaff()` -- duplicate `external_user_id`/email throws before any write.
- `ReportExportService::publish()` -- `status !== 'COMPLETED_INLINE'` throws before any write.
- `ReportExportService::approveExport()` / `cancelExport()` -- `status !== 'PENDING_APPROVAL'` throws before any write.
- `DataProductService::publish()` -- duplicate `model_run_id` on `data_product_snapshots` throws before any write.

All six were fixed identically (`formIdempotencyKey()` + `<x-idempotency-key/>`)
for the same reason `WorkflowService::publishWorkflowVersion()`'s
unconditional side effects were hardened in the prior pass even though
they couldn't be independently live-reproduced here: leaving a visibly
present but silently-defeated `CommandLedger` call in a maker-checker
write path is a real defect in its own right, not merely cosmetic --
the next engineer to touch one of these methods, seeing `CommandLedger`
correctly wired inside it, has every reason to assume the caller is
correct too. It no longer has to be assumed; it is now verified true for
every one of these nine call sites.

**Not extended to this pass:** the same "unconditional side effect after
a guarded `UPDATE`" latent-race pattern `publishWorkflowVersion()` was
hardened against in the prior pass exists identically in `decideChange()`,
`publish()`, `approveExport()` and `cancelExport()` (each runs its
license/config/document side effects without checking the guarded
`UPDATE`'s own affected-row count). This is a distinct defect from the
defeated-idempotency-key bug this pass fixed, requires touching each
service method's own transaction body rather than only its controller
caller, and -- like `publishWorkflowVersion()`'s own instance -- is not
reproducible against this environment's single-worker dev server. Left
as an explicit, named follow-up rather than folded into this pass's
scope (see §4).

## 3. Positive controls confirmed (not findings)

Every other write action in the 7 originally-flagged files was read at
the service layer and found to already have a natural duplicate-safe
guard, with **no** `CommandLedger`/idempotency-key involvement of any
kind -- these were not "half-migrated," they simply never needed the
mechanism:

- **`OrganisationAdminService::inviteEmployee()`** (`administration.employees.store`,
  `operations.human-resources.employees.store`) -- pre-checks a duplicate
  `employee_number`/email before insert.
- **`OrganisationAdminService::terminateEmployee()`** (`operations.human-resources.employees.termination`) --
  explicitly idempotent: `if ($employee->status === 'TERMINATED') return [...]` before any write.
- **`Identity\BranchService::create()`** (`organisations.branches.store`) --
  pre-checks a duplicate branch `code` within the organisation.
- **`Identity\MembershipService::assign()`** (`organisations.memberships.store`) --
  pre-checks an existing `ACTIVE` membership for the same user/organisation.
- **`Identity\TaxpayerService::suspend()`** (`organisations.taxpayer-suspension.store`) --
  explicitly idempotent (its own doc comment says so): suspending an
  already-suspended taxpayer is a no-op.
- **`Licensing\LicensingService::changeState()`** (`licensing.state.store`) --
  `LicensingValidator::assertStateTransition()` is a real state machine;
  e.g. `SUSPEND` is not a valid transition *from* `SUSPENDED`, so a
  double-submit's second call is rejected by the same state check a
  sequential second attempt would be.

## 4. What this pass did not cover (explicit, not silently dropped)

- **`App\Services\Document\DocumentService::upload()`** (`documents.store`) --
  has no `CommandLedger`/idempotency-key support of any kind and no
  natural guard: a double-submit of the upload form writes two separate
  files to disk and creates two `QUARANTINED` `document_metadata` rows
  needing two independent scan decisions. Lower severity than RT-012
  (uploads are evidence, not the report/policy-approval queues this pass
  prioritised) but a real, same-family gap. Adding `CommandLedger` here
  means widening `upload()`'s own signature (it currently takes no
  idempotency key at all, unlike `completeScan()`/`supersede()`/
  `setRetentionHold()` in the same service, which already have it) --
  a slightly larger change than this pass's controller-only fixes,
  recommended as the next scoped follow-up.
- **`App\Services\Platform\ReportExportService::runInline()`** (`reports.run`) --
  same shape as `upload()`: no idempotency support, no natural guard, a
  double-submit creates two `COMPLETED_INLINE` `report_runs` rows. Lowest
  severity of everything in this report -- a report run is read-only
  analysis, not authoritative until `publish()` (which *is* now correctly
  guarded), so the only real cost is a cluttered "My report runs" list.
  Not fixed in this pass; recommended as a low-priority follow-up.
- **`App\Services\OrganisationAdmin\OrganisationAdminService::
  createOrganisationRole()`** (`administration.roles.store`) -- no
  idempotency support and no uniqueness guard on role `name`; a
  double-submit creates two `ACTIVE` `OrganisationRole` rows with the
  same name (versions 1 and 2, both usable, both currently live) rather
  than being rejected or treated as a replay. A real governance/RBAC
  catalogue-duplication concern in the same family as RT-009, not fixed
  in this pass; recommended as a follow-up.
- **The "unconditional side effect after a guarded `UPDATE`" latent race**
  in `decideChange()`/`publish()`/`approveExport()`/`cancelExport()`,
  named in §2 above -- a distinct defect from the one this pass fixed,
  not independently reproducible in this environment, left as a named
  follow-up rather than folded into this pass's scope.
- **The remaining 9 phases of the user's original 10-phase brief** remain
  out of scope, consistent with every prior pass in this series.

## 5. Summary

| ID | Title | Severity |
|---|---|---|
| RT-011 | Duplicate platform change requests via double-submit | High — **FIXED** |
| RT-012 | Duplicate report exports double-quarantine confidential data for one approval | High — **FIXED** |
| RT-013 | Duplicate analytics model runs via double-submit | Medium — **FIXED** |
| — | 6 more defeated-idempotency-key call sites, each already naturally guarded | Hardened anyway |

**Overall assessment:** the 2026-09-13 sweep's own scope boundary named
these 7 files as the next follow-up; reading them in full surfaced not
just the "zero idempotency support" pattern that sweep's grep could
detect, but a second, harder-to-spot variant already warned against in
this codebase's own `Controller::formIdempotencyKey()` doc comment --
a service correctly wired for replay detection, defeated by its own
caller. All three genuinely exploitable instances are now fixed and
live-reproduced pre- and post-fix; the six merely-defeated-but-already-
guarded instances are hardened to remove the same doubt; three lower-
severity gaps with no `CommandLedger` involvement at all are named
explicitly as follow-up work, not silently left unaddressed.
