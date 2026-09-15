# VAT-MSA Red Team Assessment — Concurrent User Simulation, High Interaction Stress, Performance Under Heavy Use (Phases 5, 6 & 7)

**Date:** 2026-09-14
**Scope:** The three phases of the user's original 10-phase brief this
assessment had, until now, treated as infeasible -- every earlier pass
noted that PHP's built-in `artisan serve` dev server processes one
request at a time, so no genuinely concurrent request could ever be
produced, only simulated. This pass opens by resolving that constraint
directly, then uses the result to run all three phases for real.
**Method:** Described in full in section 0. In short: PHP's built-in
server supports genuine multi-process concurrency via the
`PHP_CLI_SERVER_WORKERS` environment variable, but only when launched the
way `artisan serve` itself launches it, bypassing a quirk in how
`artisan serve` passes that variable through to its child process. Once
launched correctly (8 worker processes, confirmed via `ps aux`), this
session fired genuinely simultaneous `curl` requests at a real running
instance and inspected the resulting HTTP responses, database rows, and
`storage/logs/laravel.log` entries directly.
**Environment:** This session's own sandboxed dev stack -- PHP 8.4, real
MySQL, a real multi-worker PHP built-in server, full suite (642 tests
before this pass).
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request ("proceed on the remaining phases").

---

## 0. Unblocking true concurrency

Every prior pass in this assessment noted the same limitation: `php
artisan serve` processes one request at a time, so "concurrent user"
scenarios could only be *simulated* (a `DB::listen()` hook injecting a
second write mid-request, as RT-020's regression tests do) rather than
genuinely reproduced. That simulation technique is still valid and still
used below, but this pass additionally found a way to produce real
concurrency:

- PHP's built-in development server has supported a
  `PHP_CLI_SERVER_WORKERS` environment variable since PHP 7.4, which
  forks N worker processes instead of handling requests one at a time.
- Setting this via `artisan serve`'s own environment (env var or `.env`)
  does **not** work -- `Illuminate\Foundation\Console\ServeCommand`
  reads the variable correctly (confirmed via `tinker`) but the actual
  spawned `php -S` child process never receives it (confirmed by
  inspecting `/proc/<pid>/environ`), a Symfony Process env-merging quirk
  not worth chasing further since a direct workaround exists.
- `ServeCommand`'s own `serverCommand()` reveals the exact underlying
  invocation: `php -S <host>:<port> <router>`, run with
  `public_path()` as the working directory (not a `-t` docroot flag --
  Laravel's router script assumes the working directory *is* the public
  folder). Launching that exact command directly, with the environment
  variable set on the shell itself, works:

  ```sh
  cd public && PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8000 \
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
  ```

  Confirmed via `ps aux` (1 listener + 8 worker processes) and via a
  stopwatch test: six concurrent requests that each `sleep(2)` completed
  in ~2.02s total wall time, not ~12s.

This is a dev/test-methodology finding, not an application change --
nothing in the app itself was modified to enable it, and no application
code depends on it. It's recorded here because it directly unblocks the
three phases below and is worth reusing in any future pass that needs
genuine concurrency.

---

## 1. Concurrent User Simulation

Three genuinely concurrent scenarios were run against a real instance,
escalating in specificity: first confirming an already-fixed race holds
under true concurrency (not just simulation), then testing the boundary
of that fix's protection.

### 1a. RT-020's guarded-UPDATE pattern, confirmed under true concurrency (positive control)

RT-020 (2026-09-14, Resilience to User Errors) added a `->where('status',
$fromStatus)` guard + affected-row check to nine status-transition call
sites, validated at the time only via `DB::listen()` simulation. This
pass re-validated it with two genuinely simultaneous `curl` requests
firing two different, mutually exclusive actions (`FLAG_MAINTENANCE` and
`DISPOSE`) against the same `ACTIVE` fixed asset, using duplicated
cookie jars from one authenticated session (to avoid a cookie-file write
race between the two parallel `curl` processes while sharing one login).

**Result:** exactly one of the two transitions won; the loser received a
friendly flashed conflict error; the audit trail carried exactly one
entry, matching the winner. Confirms the RT-020 fix holds under real
concurrency, not only the simulated regression tests.

### 1b. RT-020's guard incidentally also closes the CommandLedger idempotency race, for guarded actions (positive control)

`CommandLedger::record()` (`app/Support/Business/CommandLedger.php`)
inserts into `command_idempotency` with no try/catch around its own
unique constraint (`actor_id`, `command_type`, `idempotency_key`) --  in
principle a second, genuinely concurrent request carrying the identical
idempotency key could race this insert. For any of the nine call sites
RT-020 guarded, this isn't reachable: the guarded UPDATE's row-level lock
serializes the two requests before either reaches `CommandLedger::
record()` -- the loser's affected-row check (`$updated === 0`) throws
`RepositoryConflictException` first. Confirmed live: firing two
concurrent requests with the *same* action and the *same* idempotency key
at a guarded transition produced exactly one `command_idempotency` row,
exactly one audit event, no crash.

This protection is incidental to RT-020, not a deliberate design for the
idempotency race, and it doesn't extend to actions with no prior guarded
UPDATE -- see 1c.

### 1c. A plain-CREATE action's duplicate-key race, confirmed already safe by an earlier fix (positive control)

`FixedAssetService::register()` (`app/Services/Operations/
FixedAssetService.php`) has no prior row to guard: its only defence
against two concurrent registrations of the same `asset_code` is an
application-level pre-check (`$existing = FixedAsset::where(...)
->first()`) with no lock, so two genuinely concurrent requests can both
pass that SELECT before either commits its own INSERT -- exactly the
class of race 1b's reasoning predicts should still be exposed.

Confirmed live: two genuinely simultaneous `curl` POSTs, identical
`asset_code` and identical `idempotency_key`, both directed at
`/operations/fixed-assets`. A real `Illuminate\Database\
UniqueConstraintViolationException` was thrown and logged (SQLSTATE
23000, `fixed_assets_organisation_id_asset_code_unique`) -- confirming
the race is real, not hypothetical.

**What happens to the losing request, though, is already correct.**
`bootstrap/app.php` carries a global `QueryException` render() callback
from an earlier pass (red-team finding RT-001, 2026-09-09) that catches
*any* SQLSTATE 23000 duplicate-entry violation anywhere in the app --
not scoped to any one controller or service -- and converts it to the
same friendly conflict message the application-level pre-check itself
would have produced. `UniqueConstraintViolationException extends
QueryException` (confirmed by reading the framework source), so this
race is covered by that same handler. Confirmed live end-to-end:

- Winning request: clean 302 to the normal success route.
- Losing request: clean 302 (via `back()`), flashed error `"This action
  conflicts with an existing record -- it may already have been
  submitted."` -- no raw SQL, no stack trace, no 500.
- Exactly one `fixed_assets` row and one `command_idempotency` row
  existed afterwards.
- The exception was still logged to `storage/logs/laravel.log` at
  `local.ERROR` (Laravel reports an exception before a custom render()
  callback can intercept the response), so this remains observable to
  operators even though end users never see it.

**No code fix required.** This is exactly the scenario RT-001's own
bootstrap/app.php comment says it was written to cover ("any uncaught
duplicate-key violation anywhere in the app now renders as a real, if
generic, conflict message instead of a raw SQL error page") -- and
because the handler is registered globally by exception type and SQLSTATE
rather than per call site, it already covers every other plain-CREATE
action with its own unique constraint (quotations, business parties,
logistics deliveries, audit cases, etc.), not only fixed assets.

A permanent regression test was added
(`tests/Feature/Operations/FixedAssetViewTest.php`,
`test_a_genuine_concurrent_registration_race_that_bypasses_the_pre_check_is_still_a_friendly_conflict`)
using the same `DB::listen()`-simulation technique as RT-020's own tests,
so this specific race path stays covered by the fast PHPUnit suite even
though the live reproduction above needed the true-concurrency server.
It exercises the full HTTP layer (not just the service method), so
`bootstrap/app.php`'s render() callback is the thing actually under test.

---

## 2. High Interaction Stress

### 2a. Same-session concurrent requests do not serialize (positive control)

A classic PHP pitfall (native file-based sessions acquiring an exclusive
lock per session ID via `flock`, silently serializing a single user's own
concurrent requests even on a multi-process server) does not apply here.
Three concurrent requests from the *same* authenticated session (`SESSION_
DRIVER=database`) completed in ~40-46ms each with ~59ms total wall time --
genuinely parallel, not queued. Laravel's database session store performs
independent read/write queries rather than an OS-level file lock, so one
user rapidly interacting with the UI (multiple tabs, rapid navigation)
is not artificially bottlenecked by session handling.

### 2b. The double-click / rapid-resubmit scenario is already closed by an existing fix (positive control)

The realistic "high interaction stress" case for a single form is a user
double-clicking Submit, or using the browser's back button to resubmit an
already-rendered page. `resources/views/components/idempotency-key.blade.
php` (an existing fix, documented in its own header comment as closing a
prior red-team finding) generates its hidden `idempotency_key` field
*once*, at the moment the form is rendered by a GET request, baked as a
fixed value into the returned HTML. A double-click or back-button
resubmit of that same rendered page therefore always carries the
*identical* idempotency key on both submissions -- which is exactly the
scenario section 1c tested and confirmed is handled safely, whether the
action is a guarded UPDATE (1b) or a plain CREATE (1c). A genuine new
submission always comes from a fresh GET-rendered page with a new key, so
it is never mistaken for a replay.

No further action needed here -- this was a design already in place
before this pass, now confirmed correct under genuine concurrency rather
than only by code inspection.

---

## 3. Performance Under Heavy Use

### 3a. What this pass could test, and what it honestly could not

This dev environment's seed data is minimal (0 invoices, 5 fixed assets,
12 users at the time of testing) -- nowhere near production-representative
volume. Query-plan performance, N+1 detection, and index-adequacy testing
need realistic data volumes to be meaningful, and fabricating a
misleadingly small "heavy load" test against near-empty tables would
produce a false sense of confidence rather than a genuine finding. This
pass does **not** claim to have validated query performance at scale --
that remains a real, open gap for a future pass with either a
production-scale seed or a staging environment closer to production
sizing.

### 3b. What this pass did test: connection/request-handling under a concurrent burst (positive control)

What genuinely is testable at this data volume is whether the
application layer itself handles a burst of concurrent connections
without errors, timeouts, or bad serialization. 20 genuinely concurrent
authenticated GET requests against `/dashboard` (the 8-worker true-
concurrency server from section 0) all returned `200`, with individual
request times ranging ~36-157ms and ~230ms total wall time for the whole
burst -- no failures, no connection errors, reasonable queueing behavior
consistent with 8 workers absorbing 20 simultaneous requests.

This confirms the request-handling layer itself is sound at this scale;
it does not confirm database query performance at production data
volumes, which is explicitly out of scope for this pass (see 3a). The
8-worker figure is also itself a dev-server artifact (PHP's built-in
server via `PHP_CLI_SERVER_WORKERS`), not a statement about the
production deployment's actual worker/process configuration (e.g.
PHP-FPM pool size, Octane worker count), which this pass did not
investigate.

---

## 4. Summary

| ID | Title | Severity | Outcome |
|---|---|---|---|
| — | RT-020's guarded-UPDATE pattern holds under true concurrency | — | Confirmed (positive control) |
| — | RT-020's guard incidentally closes the CommandLedger idempotency race for guarded actions | — | Confirmed (positive control) |
| — | `FixedAssetService::register()`'s plain-CREATE duplicate-key race is real but already safely handled by RT-001's global handler | — | Confirmed (positive control); new permanent regression test added |
| — | Same-session concurrent requests do not serialize | — | Confirmed (positive control) |
| — | Double-click / rapid-resubmit is already closed by the existing idempotency-key component | — | Confirmed (positive control) |
| — | Application layer absorbs a 20-request concurrent burst without error | — | Confirmed (positive control) |
| — | Query performance at production data volumes | — | **Not tested -- genuine scope gap**, needs representative data or a staging environment |

**Overall assessment:** every scenario this pass could genuinely test
under true concurrency -- including the specific hypothesis-driven
question of whether a plain-CREATE action's duplicate-key race is
actually caught cleanly, not just theoretically covered by a global
handler -- came back as a positive control: the application already
handles it correctly, mostly due to defences added in earlier passes
(RT-001, RT-020) that turned out to generalize further than the passes
that introduced them explicitly tested for. No new code fix was needed;
one new permanent regression test was added to keep the plain-CREATE
race path covered by the fast suite going forward. The one honest gap is
query performance at realistic data volumes, which this pass's dev
seed data cannot meaningfully exercise -- flagged as a real limitation
rather than glossed over.
