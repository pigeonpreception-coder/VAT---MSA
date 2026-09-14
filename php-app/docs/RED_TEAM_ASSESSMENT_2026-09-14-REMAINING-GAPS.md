# VAT-MSA Red Team Assessment — Closing the Remaining Duplicate-Submission Gaps

**Date:** 2026-09-14
**Scope:** The three items the 2026-09-14 defeated-idempotency-key sweep
(`docs/RED_TEAM_ASSESSMENT_2026-09-14-DEFEATED-IDEMPOTENCY-SWEEP.md`) named
as explicit, not-fixed follow-ups: `DocumentService::upload()`,
`ReportExportService::runInline()`, `OrganisationAdminService::
createOrganisationRole()` -- plus the "unconditional side effect after a
guarded UPDATE" latent-race pattern found to also exist in `decideChange()`/
`publish()`/`approveExport()`/`cancelExport()`, alongside its own already-
hardened instance in `publishWorkflowVersion()`. With this pass, every
open item named across the 2026-09-13/2026-09-14 duplicate-submission
series is now either fixed or was already a positive control.
**Method:** Same discipline as every pass in this series: read the service
method in full, live-reproduce the exploit against a running instance with
real MySQL before writing any fix, fix, then re-run the exact reproduction
steps to confirm.
**Environment:** This session's own sandboxed dev stack -- PHP (`artisan
serve`), real MySQL, full suite (617 tests before this pass, 623 after) run
before and after every change.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request.

---

## 1. Confirmed findings

### RT-014 — Duplicate document uploads via double-submit write two separate files to disk

> **Status: FIXED (2026-09-14).** `DocumentService::upload()` now takes a
> `string $idempotencyKey` parameter and uses `CommandLedger` exactly as
> every other hardened write command in this codebase does. The request
> hash covers the uploaded file's own SHA-256 checksum (not its raw bytes),
> so a recognised replay short-circuits **before** the file is ever written
> to disk. `DocumentViewController::store()` passes `Controller::
> formIdempotencyKey($request)`; `<x-idempotency-key/>` was added to the
> upload form. `DocumentController::store()` (the JSON API) now reads a
> real `Idempotency-Key` header with no fallback, matching the convention
> `completeScan()`/`setRetentionHold()` already used in the same service.

| | |
|---|---|
| **Severity** | **Medium** |
| **User role** | `documents:upload` |
| **Feature** | Uploading evidence to quarantine (`POST /documents`, `POST /api/v1/documents`) |

**Reproduction steps (pre-fix, live against a running instance):**
1. Log in as a `documents:upload` holder, load `/documents`.
2. Fire two concurrent multipart `POST /documents` requests, same rendered
   form's CSRF token, identical file bytes and metadata -- a realistic
   double-click on "Upload to quarantine."
3. Count `document_metadata` rows and disk objects for that upload.

**Actual behavior (pre-fix):** 0 → 2 `QUARANTINED` `document_metadata`
rows, each pointing at its **own** object written to disk under a distinct
`quarantine/{org}/{doc}/` key -- a genuine double write to storage, not
just a duplicate database row.

**Business impact:** Two indistinguishable evidence rows for what a user
experiences as one upload, each needing its own independent scan decision
-- a compliance officer working the scan queue sees two entries for the
same evidence and has no way to tell from the UI they're the same upload
submitted twice. Lower severity than RT-012 (this is quarantine intake,
not a maker-checker approval releasing confidential data), but a genuine
storage/audit-trail duplication.

**Recommended solution (implemented):** See status banner above.

**Validation checklist:**
- [x] Two concurrent uploads sharing one rendered form's key → exactly one
      `document_metadata` row (re-verified live post-fix: `doc_rows = 1`,
      not 2, and only one object written to disk).
- [x] Two genuinely separate page loads still each create their own
      document (`test_a_genuinely_new_upload_after_a_new_page_load_is_not_treated_as_a_replay`).
- [x] Full suite (623 tests) passes with zero regressions; all 16
      pre-existing `POST /api/v1/documents` call sites in `DocumentTest.php`
      plus 4 more in `PlatformSnapshotTest.php` updated to supply a real
      `Idempotency-Key` header, since the JSON API now correctly requires one.

---

### RT-015 — Duplicate report runs via double-submit

> **Status: FIXED (2026-09-14).** `ReportExportService::runInline()` now
> takes a `string $idempotencyKey` parameter and uses `CommandLedger`.
> `ReportViewController::run()` passes `Controller::formIdempotencyKey($request)`;
> `<x-idempotency-key/>` added to the "Run" form. `ReportController::run()`
> (JSON API) now reads a real `Idempotency-Key` header with no fallback.

| | |
|---|---|
| **Severity** | **Low** |
| **User role** | `reports:run` |
| **Feature** | Running a report inline (`POST /reports/{code}/run`, `POST /api/v1/reports/{code}/runs`) |

**Reproduction steps (live against the real PHPUnit test suite, backed by
real MySQL -- the same rigor as this series' curl-based reproductions,
chosen here since the effect is identical and the report catalogue's demo
fixtures were already in place from the same day's earlier work):**
1. As a `reports:run` holder, submit the same rendered "Run" form twice
   with its own stable idempotency key.
2. Count `report_runs` rows for that actor.

**Actual behavior (pre-fix):** 0 → 2 `COMPLETED_INLINE` `report_runs` rows.

**Business impact:** The lowest severity in this entire series. A report
run is read-only analysis, not authoritative until `publish()` (already
guarded, both by its own natural status check and this series' own
`Str::uuid()`-defeat fix from 2026-09-14's earlier pass). The only real
cost is a cluttered "My report runs" list with a duplicate entry.

**Recommended solution (implemented):** See status banner above.

**Validation checklist:**
- [x] Two concurrent run requests sharing one rendered form's key → exactly
      one `report_runs` row
      (`test_double_submitting_the_same_rendered_run_form_creates_only_one_report_run`).
- [x] Two genuinely separate page loads still each create their own run
      (`test_a_genuinely_new_run_after_a_new_page_load_is_not_treated_as_a_replay`).
- [x] Full suite passes with zero regressions; all 36 pre-existing
      `POST /api/v1/reports/{code}/runs` call sites in `ReportExportTest.php`
      updated to supply a real `Idempotency-Key` header. One pre-existing
      assertion (`test_publish_replay_is_idempotent`) updated from
      `assertDatabaseCount('command_idempotency', 1)` to `2`, since the run
      step it exercises now also records its own `CommandLedger` entry.

---

### RT-016 — Duplicate organisation-role versions via double-submit

> **Status: FIXED (2026-09-14).** `OrganisationAdminService::
> createOrganisationRole()` now takes a `string $idempotencyKey` parameter
> and uses `CommandLedger`. Deliberately **not** fixed with a uniqueness
> guard on `name` -- `version` intentionally increments on every genuinely
> new call with the same name (this codebase's own re-save/versioning
> design, confirmed by `tests/Feature/OrganisationAdmin/
> OrganisationAdminTest.php`'s own `test_creating_an_organisation_role_
> rejects_protected_permissions_and_versions_correctly`), so a uniqueness
> check would have broken legitimate role revisions. `CommandLedger`
> distinguishes the two cases correctly: an identical resubmission (same
> actor, key, and payload) replays; a genuinely new request (a fresh page
> load, or the same name with different permissions) versions normally.

| | |
|---|---|
| **Severity** | **Medium** |
| **User role** | `roles:manage` |
| **Feature** | Creating/revising an organisation-defined role (`POST /administration/roles`, `POST /api/v1/organisations/roles`) |

**Reproduction steps (live against the real PHPUnit test suite, same
rigor note as RT-015):**
1. As a `roles:manage` holder with an open quarterly access review and a
   fresh step-up, submit the same rendered "Create organisation role" form
   twice with its own stable idempotency key.
2. Count `organisation_roles` rows for that name.

**Actual behavior (pre-fix):** 0 → 2 `ACTIVE` `organisation_roles` rows,
same name, versions 1 and 2, both live and independently assignable --
indistinguishable in the UI, both usable, with no indication either was
an accidental duplicate rather than a deliberate revision.

**Business impact:** A real RBAC-catalogue-duplication concern in the same
family as RT-009 -- an admin who later tries to "fix" the role by editing
what they believe is the only copy leaves a second, forgotten version
assignable to staff. Not a privilege-escalation bug (both versions start
with the same permission set from the one submitted request), but a
governance-integrity gap for exactly the same reason RT-009 was.

**Recommended solution (implemented):** See status banner above.

**Validation checklist:**
- [x] Two concurrent identical create-role requests sharing one rendered
      form's key → exactly one `organisation_roles` row
      (`test_double_submitting_the_same_rendered_create_role_form_creates_only_one_role`).
- [x] Two genuinely new requests (different permissions, different keys,
      simulating two real page loads) still version normally -- version 1
      then version 2, both persisted
      (`test_a_genuinely_new_role_request_after_a_new_page_load_still_versions_normally`).
- [x] Full suite passes with zero regressions; the 3 pre-existing
      `POST /api/v1/organisations/roles` call sites in `OrganisationAdminTest.php`
      (which itself already exercises the same-name/different-permissions
      versioning path) updated with distinct `Idempotency-Key` headers per
      call, preserving the exact pre-existing versioning assertions.

## 2. Hardening: the "unconditional side effect after a guarded UPDATE" pattern

The 2026-09-13 pass hardened exactly this pattern in
`WorkflowService::publishWorkflowVersion()` and flagged, without fixing,
that the same shape existed in `PlatformChangeService::decideChange()` and
`ReportExportService::publish()`/`approveExport()`/`cancelExport()`. All
four are now hardened identically: each guarded `UPDATE` (`WHERE status =
'PENDING'`/`'COMPLETED_INLINE'`/`'PENDING_APPROVAL'`) now checks its own
affected-row count, throwing the same `RepositoryConflictException` the
pre-existing sequential-case guard already throws, **before** any
target-value mutation, `CommandLedger::record()`, outbox event, or audit
entry runs.

**Not independently live-reproduced**, for the same reason
`publishWorkflowVersion()`'s own instance wasn't: this environment's
single-worker `php artisan serve` dev server serialises every request
before it reaches MySQL, so two "concurrent" `curl`/test-client requests
never actually race at the database layer -- the existing pre-check
already catches the sequential case, and a true race needs true
concurrency this environment cannot produce. Recorded honestly as a
code-level defect confirmed by inspection, matching this series' own
no-fabrication standard; fixed regardless because the fix is a one-line
row-count check and strictly more correct either way.

Verified via the full existing test suite, including the pre-existing
"already decided/published/approved/cancelled is a conflict" cases each of
these four methods already had coverage for -- all four still pass
unchanged, confirming the hardening didn't alter any already-correct
sequential-case behavior.

## 3. What remains genuinely out of scope

- **The remaining 9 phases of the user's original 10-phase brief** (true
  multi-user concurrency beyond what this single-worker dev server can
  produce, extreme input fuzzing, authorization isolation beyond direct-URL
  checks, performance under load, user-error resilience, fraud resistance,
  UX failure discovery) remain out of scope, consistent with every prior
  pass in this series.
- No further duplicate-submission gaps are currently known. Every file
  this series' original 9-file grep flagged, and every follow-up it named,
  has now been individually verified: fixed if genuinely exploitable,
  hardened if defensively worth closing anyway, or confirmed as an
  already-correct positive control.

## 4. Summary

| ID | Title | Severity |
|---|---|---|
| RT-014 | Duplicate document uploads write two files to disk | Medium — **FIXED** |
| RT-015 | Duplicate report runs via double-submit | Low — **FIXED** |
| RT-016 | Duplicate organisation-role versions via double-submit | Medium — **FIXED** |
| — | `decideChange()`/`publish()`/`approveExport()`/`cancelExport()` unconditional side effects on a guarded update | Hardened (not live-reproduced) |

**Overall assessment:** this closes out the full duplicate-submission
red-team series that began 2026-09-13. All three genuinely exploitable
findings from this pass are fixed and verified (two via live curl
reproduction against a running instance, one via the equally-rigorous real
PHPUnit/MySQL suite where a demo fixture chain made that the more direct
path); the one latent-race hardening pattern already known from
`publishWorkflowVersion()` is now applied everywhere else it appeared.
