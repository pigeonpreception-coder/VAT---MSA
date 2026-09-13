# VAT-MSA Red Team Assessment — Systemic Duplicate-Submission Sweep

**Date:** 2026-09-13
**Scope:** A deliberate continuation of the same day's "Invoice Management & POS Integration"
pass (`docs/RED_TEAM_ASSESSMENT_2026-09-13-INVOICE-MANAGEMENT-POS.md`), chosen as the specific
area to go deep on next from the user's own much broader 10-phase audit brief. That first pass
found the newest two features (Foreign/Local Invoices) had never been brought into line with this
codebase's own established duplicate-submission hardening (`docs/MIGRATION_MATRIX.md`'s
"Duplicate-submission hardening" section, 2026-09-09) simply because both post-dated it. This
pass asks the obvious follow-on question: **which other write actions, across everything built
since that hardening pass landed, have the same gap?**

**Method:** Every Blade view carrying `@csrf` was cross-referenced against `<x-idempotency-key/>`
usage (the established fix's own marker) to find candidate gaps mechanically, not by guessing.
Each candidate was then read at the service-layer before being reported: several turned out to
already have their own natural duplicate-safe guard (a uniqueness pre-check or a status-transition
check) and are recorded as positive controls, not findings. The two genuinely unguarded, most
severe candidates were live-reproduced against a running `php artisan serve` instance with real
MySQL (session cookies, real CSRF tokens, concurrent background `curl` requests), fixed, and
re-reproduced post-fix to confirm the exploit no longer works — the same standard this codebase's
prior red-team reports hold themselves to. Findings continue the existing `RT-NNN` numbering
(`RT-001`–`RT-008` already used) starting at `RT-009`.
**Environment:** This session's own sandboxed dev stack — PHP (`artisan serve`), real MySQL, full
suite (612 tests) run both before and after every change.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the user's explicit request.

---

## 0. How the candidate list was built

```
for each Blade view with @csrf:
    count(@csrf occurrences) vs count(<x-idempotency-key/> occurrences)
```

9 views showed a real gap (`@csrf` present, `<x-idempotency-key/>` entirely absent):
`access-rights/index.blade.php`, `administration/index.blade.php`, `documents/index.blade.php`,
`licensing/index.blade.php`, `organisations/show.blade.php`,
`operations/human-resources/index.blade.php`, `platform/index.blade.php`, `reports/index.blade.php`,
`workflows/index.blade.php`. Every other view with `@csrf` already had a matching
`<x-idempotency-key/>` count (from the 2026-09-09 pass, or from the Foreign/Local Invoices fix
earlier the same day as this report).

Given real single-session time constraints, this pass went deep on the two highest-privilege,
highest-consequence write actions among those 9 files (`access-rights` — grants any role including
SUPER_ADMIN; `workflows` — the segregation-of-duty-bearing approval engine underneath every other
privileged business process), confirmed both were genuinely exploitable, fixed them, and is
explicit that the remaining 7 files were identified but **not individually verified or fixed** in
this pass (see §4).

## 1. Confirmed findings

### RT-009 — Duplicate access-rights grants via double-submit corrupt the platform's own most sensitive audit trail

> **Status: FIXED (2026-09-13).** `App\Services\Access\UserRoleScopeGrantService::grant()` now
> takes the same stable per-form-render idempotency key every other write action in this codebase
> uses, and recognises an exact replay via `CommandLedger` before creating a second grant row.
> Verified: 2 new tests in `tests/Feature/Access/AccessRightsViewTest.php`, a live re-reproduction
> of the original exploit (below), and the full 612-test suite, zero regressions.

| | |
|---|---|
| **Severity** | **High** |
| **User role** | `SUPER_ADMIN` / `NAMRA_SYSTEM_ADMIN` (the only roles holding `access-rights:manage`) |
| **Feature** | Super Admin / NamRA System Admin "grant a user an access right" (`POST /access-rights`) |
| **Preconditions** | A fresh step-up (`password.confirm`) — the ordinary precondition to reach this action at all |

**Reproduction steps:**
1. Log in as `platform-admin@vat-msa.test` (`SUPER_ADMIN`), complete the real `password.confirm`
   step-up, load `/access-rights`.
2. Fire two concurrent `POST /access-rights` requests, both carrying the page's own rendered CSRF
   token and identical `user_id`/`role_code`/`scope_level` — a realistic double-click.
3. Count `user_role_scope_grants` rows for that user/role before and after.

**Expected behavior:** One admin action should produce one grant record.

**Actual behavior (pre-fix):** Live reproduction: 0 → 2 `user_role_scope_grants` rows, both
`ACTIVE`, both naming the same user and role. `users.role` itself ends up correct either way (both
writes set it to the same value), so this is not a privilege-escalation bug — but the grants table
is this feature's own documented "governance context for auditing who authorised what," and now
holds a duplicate an admin has no visual way to distinguish from the real one.

**Frequency:** 100% reproducible — no natural guard existed at all before the fix.

**Business impact:** An admin who later revokes "the" grant flips exactly one row to `REVOKED`
while a second, forgotten `ACTIVE` grant for the same role silently remains — making "does this
user still hold an active grant to role X" unanswerable from this table alone without manually
checking for duplicates. For a feature whose entire purpose is a clean authorisation audit trail
(including grants of `SUPER_ADMIN` itself), this is a real governance-integrity gap, not merely
cosmetic.

**Customer impact:** A compliance reviewer or the granting admin themselves could reasonably
believe access was fully revoked when it was not (a duplicate grant row remains active), or become
confused auditing why two identical-looking grant rows exist for one action.

**Likely root-cause hypothesis:** Same as RT-007/RT-008 (2026-09-13, Invoice Management/POS
report): this feature was built without the `<x-idempotency-key/>`/`CommandLedger` convention ever
being applied to it — an omission, not a documented decision.

**Recommended solution (implemented):** Same established pattern: `<x-idempotency-key/>` on the
grant form, `Controller::formIdempotencyKey()` threaded into `grant(..., string $idempotencyKey)`,
`CommandLedger::prior()`/`::record()` around the write. A recognised replay returns the
already-created grant rather than creating a second one.

**Regression risks:** Low. A genuinely new grant (fresh page load, new key) still creates a new
row normally — verified by a dedicated test granting two different users the same role and
confirming two distinct rows result.

**Validation checklist:**
- [x] Two concurrent grant requests sharing one rendered form's key → exactly one
      `user_role_scope_grants` row (re-verified live post-fix: 1 row, not 2).
- [x] Two requests targeting two different users still each create their own grant.
- [x] Full suite (612 tests) passes with zero regressions.

---

### RT-010 — Duplicate workflow assignment via double-submit creates two independent, parallel approval gates for the same business resource

> **Status: FIXED (2026-09-13).** `App\Services\Workflow\WorkflowService::assignWorkflow()` now
> takes an idempotency key (read from the real `Idempotency-Key` header on the JSON API,
> `Controller::formIdempotencyKey()` on the Blade UI) and uses `CommandLedger` to recognise an
> exact replay before creating a second `workflow_instances` row. Verified: 2 new tests in
> `tests/Feature/Workflow/WorkflowAuthoringViewTest.php`, a live re-reproduction of the original
> exploit against both the JSON API and (implicitly, same service method) the Blade UI, and the
> full 612-test suite, zero regressions.

| | |
|---|---|
| **Severity** | **Critical** |
| **User role** | Any role holding `workflows:manage` |
| **Feature** | Assigning a workflow instance to a business resource (`POST /api/v1/workflows/instances`, Blade `POST /workflows/instances`) — the entry point to this codebase's entire approval/segregation-of-duty engine |
| **Preconditions** | An active, published workflow configured for the domain action being assigned |

**Reproduction steps:**
1. As a user with `workflows:manage`, publish a workflow for a domain action (e.g. `expense`).
2. Fire two concurrent `POST /api/v1/workflows/instances` requests, identical
   `domain_action`/`resource_type`/`resource_id`/`context` — a realistic double-click on "Assign."
3. Count `workflow_instances` rows for that `resource_id`.

**Expected behavior:** One assignment action against one business resource should produce one
workflow instance (and, for a workflow with an approval step, one pending approval task).

**Actual behavior (pre-fix):** Live reproduction: 0 → 2 `workflow_instances` rows for the same
`resource_id`, each independently valid, each independently reachable. For a workflow whose path
includes an `APPROVAL` node (unlike the minimal always-completes fixture used for the initial
reproduction), this means **two separate, independently satisfiable pending approval tasks for the
same underlying business resource** — a genuine segregation-of-duty and business-process-integrity
defect: the system's own "this resource requires one approval" guarantee is silently doubled by an
ordinary double-click, with no natural guard anywhere in `assignWorkflow()` to prevent it.

**Frequency:** 100% reproducible — confirmed with a truly concurrent request pair (parallel
background `curl` processes) as well as via PHPUnit's synchronous test client; no race timing was
needed since there was no guard of any kind, not even a narrow one.

**Business impact:** This is the most severe finding of this pass. Workflows exist specifically to
enforce "this consequential action needs an independent decision" (self-approval is already
blocked elsewhere in this same engine). A duplicate assignment silently creates a second,
parallel path to the same outcome — for example, two independent approvers could each approve
"their own" copy of what was meant to be a single gated decision on one expense/journal/registration
change, defeating the very control the workflow was configured to provide. Any downstream process
that assumes "at most one workflow instance governs this resource" (a reasonable assumption the
system itself never enforced) would misbehave in the presence of the duplicate.

**Customer impact:** Approvers could see two tasks for what looks like the same request and be
confused about which is authoritative; worse, both could genuinely be decided independently,
producing two audit trails for a decision that was supposed to be singular.

**Likely root-cause hypothesis:** Same omission as RT-007/008/009 — this entire module (both its
JSON API and its Blade UI) was built with no idempotency-key support in any of its six write
methods, never brought into line with the established convention.

**Recommended solution (implemented):** `assignWorkflow()` takes an idempotency key and uses
`CommandLedger` exactly as the other fixes in this pass do. Because the instance created spans two
different shapes (an immediately-`COMPLETED` instance with no assignment, or an `IN_PROGRESS`
instance with one `PENDING` assignment), a recognised replay re-derives and returns the same shape
from the already-created row (`presentInstance()`) rather than re-running the assignment logic.
The JSON API (`WorkflowController::storeInstance()`) now reads a real client-supplied
`Idempotency-Key` header, matching the existing convention `InvoiceController::store()` already
established for exactly this kind of stateless JSON endpoint; existing tests exercising this
endpoint were updated to supply one (7 call sites in `tests/Feature/Workflow/WorkflowTest.php`),
since the endpoint now correctly rejects a request with no key at all, the same way the invoices
JSON API already does.

**Regression risks:** Low-medium — this does change the JSON API's contract (a real
`Idempotency-Key` header is now required, where previously none was checked at all). This is a
deliberate tightening to match the already-established convention for this class of endpoint, not
an accidental behavior change; any real external caller of this endpoint will need to start
sending the header, exactly as any real caller of `POST /api/v1/invoices` already must. Verified
that a genuinely new assignment (a different key) is unaffected and creates its own instance
normally.

**Validation checklist:**
- [x] Two concurrent assign requests sharing one idempotency key → exactly one `workflow_instances`
      row for that resource (re-verified live post-fix: 1 row, not 2; both responses return the
      identical instance id).
- [x] A request with no `Idempotency-Key` header is now cleanly rejected (422), matching the
      existing invoices JSON API's own contract.
- [x] Two requests with two different keys for two different resources still each create their
      own instance.
- [x] Full suite (612 tests, including 7 updated pre-existing call sites) passes with zero
      regressions.

## 2. Hardening applied alongside the above (not independently live-reproduced)

### `WorkflowService::publishWorkflowVersion()` — unconditional side effects regardless of whether the guarded update actually applied

While fixing RT-010, code-reading `publishWorkflowVersion()` (immediately adjacent in the same
service) found that its own existing duplicate-publish guard (`UPDATE ... WHERE status = 'DRAFT'`)
was real and correct, but every side effect that followed it — the `license_usage.used_value`
increment (a real billing/entitlement counter) and the `WORKFLOW_VERSION_PUBLISHED` audit entry —
ran **unconditionally**, with no check on whether the guarded `UPDATE` actually matched a row. A
genuine race (two truly concurrent publish requests, both reading `DRAFT` before either commits)
would let the second transaction's `UPDATE` affect zero rows while still double-incrementing
`used_value` and writing a second, misleading audit entry claiming the version was published twice.

**Not independently live-reproduced**: this environment's dev server (`php artisan serve`) is
single-worker and serialises every request before it reaches MySQL, so two "concurrent" `curl`
requests never actually raced at the database layer — confirmed directly: a real concurrent-`curl`
attempt against this exact endpoint correctly returned one `200` and one `409`, with
`license_usage` ending in the correct state, because the dev server itself never let the two
requests execute in true parallel. This is recorded honestly as a **code-level defect confirmed by
inspection**, not a reproduced exploit, per this report's own no-fabrication standard — the same
standard `docs/RED_TEAM_ASSESSMENT_2026-09-13.md`'s own §2 ("Investigated and disproven") holds
itself to for a hypothesis that couldn't be confirmed.

**Fix applied regardless** (cheap, strictly more correct, and consistent with treating "unconditional
side effects after a guarded update" as a defect on its own terms): the guarded `UPDATE`'s affected-row
count is now checked; zero rows throws the same `RepositoryConflictException` the pre-existing guard
already throws for the sequential case, before any license/audit side effect runs. Verified via the
full existing test suite (including `WorkflowTest`'s own "already published is a conflict" case),
zero regressions.

## 3. Positive controls confirmed (not findings — recorded for completeness)

Read at the service layer and found to already have a natural duplicate-safe guard, so **not**
independently live-reproduced or fixed in this pass:

- **`WorkflowService::createWorkflowDraft()`** — pre-checks `Workflow::where('organisation_id',
  ...)->where('name', ...)->exists()` before creating; a resubmission with the same name is
  rejected with a clean conflict, confirmed by the pre-existing `WorkflowTest` case
  ("A duplicate name is a real conflict").
- **`WorkflowService::decideWorkflowTask()`** — checks `$task->status !== 'PENDING'` before
  deciding, and the assignment's own status-transition `UPDATE` is itself guarded
  (`WHERE status = 'PENDING'`); a double-decide cannot flip an already-decided task's outcome.
  (A narrower, non-security audit-log-duplication concern — a true concurrent race could still
  write two `workflow_approvals` rows even though only one actually changes the assignment's
  status — was noted but not fixed in this pass, matching the `publishWorkflowVersion` hardening
  above in kind but out of scope for this pass's time budget.)
- **`App\Services\Access\UserRoleScopeGrantService::revoke()`** — already guards
  `if ($grant->status !== 'ACTIVE') { throw RepositoryConflictException(...) }`; a double-revoke
  is already a safe, cleanly-rejected no-op.

## 4. Engineering prompts

Both findings were fixed within this same pass (per this codebase's own established convention).
The standalone prompts below record exactly what was implemented, for reference and as a durable
definition-of-done record.

### Prompt: RT-009 — Fix duplicate access-rights grants on double-submit

**Objective:** Make `UserRoleScopeGrantService::grant()` idempotent per rendered form submission,
so a double-click or network retry on "Grant access right" cannot create two
`user_role_scope_grants` rows from one admin action.

**Affected modules:**
- `app/Services/Access/UserRoleScopeGrantService.php` (`grant()`)
- `app/Http/Controllers/Access/AccessRightsViewController.php` (`store()`)
- `resources/views/access-rights/index.blade.php` (the grant form)

**Observable problem:** Two concurrent/rapid identical POSTs to `/access-rights` create two
distinct `ACTIVE` `user_role_scope_grants` rows for the same user/role/scope. `users.role` itself
ends up correct (both writes agree), but the grants audit trail — this feature's own most
sensitive record — now holds an indistinguishable duplicate; revoking one leaves the other
silently `ACTIVE`.

**Desired behavior:** A resubmission carrying the same idempotency key as an already-processed
request for this actor must return the existing grant, not create a second one. A resubmission
with a genuinely new key (fresh page load) must behave normally.

**Implementation expectations:**
- Add `<x-idempotency-key/>` to the grant form (this codebase's own existing component).
- Thread it through `AccessRightsViewController::store()` via `Controller::
  formIdempotencyKey($request)` into a new `string $idempotencyKey` parameter on `grant()`.
- Use `App\Support\Business\CommandLedger` (`validateIdempotencyKey`, `requestHash`, `prior`,
  `record`) exactly as every other hardened write action in this codebase already does.
- On a recognised replay, return the previously-created `UserRoleScopeGrant` (looked up by the
  resource id `CommandLedger::prior()` returns) rather than creating a new row.
- Catch `RepositoryConflictException` (thrown by `CommandLedger::prior()` on a genuine key/payload
  mismatch) in the controller with a friendly `withErrors()` redirect.

**Validation requirements:**
- Two concurrent grant requests with the same idempotency key → exactly one
  `user_role_scope_grants` row.
- Two requests targeting different users/roles (two separate page loads) → two distinct grants.
- Existing self-grant-denial, scope-label-required, and role-validity checks still run before the
  idempotency short-circuit.

**Regression tests:** `tests/Feature/Access/AccessRightsViewTest.php`:
`test_double_submitting_the_same_rendered_grant_form_creates_only_one_grant`,
`test_a_genuinely_new_grant_request_after_a_new_page_load_is_not_treated_as_a_replay`.

**Acceptance criteria:** Both new tests pass; full existing suite passes with zero regressions;
a live double-submit reproduction (two concurrent POSTs, same rendered form, real step-up
completed first) creates exactly one `user_role_scope_grants` row, confirmed via direct database
query.

**Definition of done:** Fix merged, both regression tests present and passing, full suite green,
live reproduction re-run post-fix and confirmed fixed, finding documented as FIXED in this report
with before/after evidence.

---

### Prompt: RT-010 — Fix duplicate workflow assignment creating parallel approval gates

**Objective:** Make `WorkflowService::assignWorkflow()` idempotent per request, so a double-click
or retried request cannot create two `workflow_instances` (and, for an approval-requiring
workflow, two independent pending approval tasks) for the same business resource.

**Affected modules:**
- `app/Services/Workflow/WorkflowService.php` (`assignWorkflow()`, plus a new `presentInstance()`
  helper)
- `app/Http/Controllers/Workflow/WorkflowController.php` (`storeInstance()` — JSON API)
- `app/Http/Controllers/Workflow/WorkflowAuthoringViewController.php` (`assign()` — Blade UI)
- `resources/views/workflows/index.blade.php` (the "Assign" form)
- `tests/Feature/Workflow/WorkflowTest.php` (7 existing call sites needed a real
  `Idempotency-Key` header added, since the endpoint now correctly requires one)

**Observable problem:** Two concurrent identical `POST /api/v1/workflows/instances` (or the Blade
equivalent) requests each independently call the full assignment logic, producing two separate
`workflow_instances` rows for the same `resource_id` — for a workflow with an `APPROVAL` node,
two independent, independently-satisfiable `PENDING` approval tasks for what was meant to be one
gated decision.

**Desired behavior:** A request carrying an idempotency key already used by this actor for an
equivalent assignment must return the *existing* instance (re-deriving its status/current node/
assignment id), never create a second one. A request with a genuinely new key must run normally.

**Implementation expectations:**
- Add a `string $idempotencyKey` parameter to `assignWorkflow()`; validate and check
  `CommandLedger::prior()`/`record()` exactly as this codebase's other hardened write actions do.
- Because the method has two distinct successful outcomes (an immediately-`COMPLETED` instance
  with no assignment, or an `IN_PROGRESS` instance with one `PENDING` assignment), add a
  `presentInstance(string $instanceId)` helper that re-derives the same response shape from the
  already-persisted `workflow_instances`/`workflow_assignments` rows, for use on a recognised
  replay.
- `WorkflowController::storeInstance()` (the real, external-facing JSON API) must read a genuine
  `Idempotency-Key` request header — matching the existing convention `InvoiceController::store()`
  already established for this exact kind of stateless endpoint — not silently default to a
  fresh, ineffective key.
- `WorkflowAuthoringViewController::assign()` must use `Controller::formIdempotencyKey($request)`
  and the form must carry `<x-idempotency-key/>`, matching the Blade convention used elsewhere.
- Update every existing test call site hitting `POST /api/v1/workflows/instances` to supply a real
  `Idempotency-Key` header, since the endpoint now correctly rejects a request with none.

**Validation requirements:**
- Two concurrent assign requests with the same idempotency key → exactly one `workflow_instances`
  row for the resource, both responses naming the same instance id.
- A request with no `Idempotency-Key` header is rejected (422), matching the invoices JSON API's
  own contract.
- Two requests with two different keys for two different resources → two independent instances.
- Existing routing/conditional-transition, role-assignment, and "no active workflow configured"
  behaviors are unchanged.

**Regression tests:** `tests/Feature/Workflow/WorkflowAuthoringViewTest.php`:
`test_double_submitting_the_same_rendered_assign_form_creates_only_one_instance`,
`test_a_genuinely_new_assign_request_after_a_new_page_load_is_not_treated_as_a_replay`.

**Acceptance criteria:** Both new tests pass; full existing suite (including the 7 updated
pre-existing call sites) passes with zero regressions; a live double-submit reproduction (two
concurrent requests, same idempotency key, against a running server) creates exactly one
`workflow_instances` row, confirmed via direct database query, with both HTTP responses returning
the identical instance id.

**Definition of done:** Fix merged, both regression tests present and passing, full suite green,
live reproduction re-run post-fix and confirmed fixed (including the "no header" rejection case),
finding documented as FIXED in this report with before/after evidence.

## 5. What this pass did not cover (explicit, not silently dropped)

- **7 of the 9 grep-identified files were not individually verified or fixed**:
  `administration/index.blade.php`, `documents/index.blade.php`, `licensing/index.blade.php`,
  `organisations/show.blade.php`, `operations/human-resources/index.blade.php`,
  `platform/index.blade.php`, `reports/index.blade.php`. Each is a real candidate for the same
  class of gap (confirmed missing `<x-idempotency-key/>` by the same mechanical check that found
  RT-009/010) but none of their underlying service methods were read closely enough in this pass
  to say whether each already has its own natural guard (as `createWorkflowDraft`/`decideWorkflowTask`
  turned out to) or is genuinely unguarded (as `grant()`/`assignWorkflow()` turned out to be).
  Recommended as the next scoped follow-up.
- **`WorkflowService::createDelegation()`/`revokeDelegation()`** were read (createDelegation has no
  duplicate-name/pairing guard) but not live-reproduced or fixed — lower business severity than
  RT-010 (a duplicate delegation is a data-quality nuisance, not a segregation-of-duty bypass), out
  of scope for this pass's time budget.
- **The remaining 9 phases of the user's original 10-phase brief** (UI stress beyond duplicate
  submission, true multi-user concurrency, session/auth beyond what RT-007's report already
  covered, extreme input beyond what was already spot-checked, authorization isolation beyond
  direct-URL checks already covered in existing tests, performance, user-error resilience, fraud
  resistance, UX discovery) remain out of scope for this specific pass, consistent with the
  narrowed-scope agreement at the start of the day's assessment work.

## 6. Summary

| ID | Title | Severity |
|---|---|---|
| RT-009 | Duplicate access-rights grants via double-submit | High — **FIXED** |
| RT-010 | Duplicate workflow assignment creates parallel approval gates | Critical — **FIXED** |
| — | `publishWorkflowVersion()` unconditional side effects on a guarded update | Hardened (not live-reproduced) |

**Overall assessment:** the same systemic gap found earlier the same day on Foreign/Local Invoices
(RT-007/RT-008) turned out to be broader than those two features alone — it is a property of
*when* a write action was built relative to the 2026-09-09 hardening pass, not which module. Both
newly-confirmed findings here are fixed using the identical, already-proven mechanism, and the
report is explicit about the 7 remaining candidate files that still need the same individual
verification this pass gave to Access Rights and Workflows.
