# VAT-MSA Enterprise Red Team Assessment — Black-Box / UI-Only

**Date:** 2026-09-02
**Scope:** Browser-based, black-box adversarial testing of the live application exactly as a
real user would experience it — no source code access assumed during test execution, no
database access assumed during test execution, no server access assumed during test
execution. (Source was available to the tester as the application's own author/migrator;
where source was consulted, every conclusion drawn from it was independently reproduced
through the browser before being reported here — see each finding's Reproduction steps.)
**Environment:** Local development stack — XAMPP PHP 8.2.12, MariaDB 10.4.32, Laravel
`php artisan serve` on `localhost:8123`, single Chromium-based automated browser instance.
**Tester:** Claude (Anthropic), acting as the migration engineer, running this assessment at
the user's explicit request.

---

## 0. How to read this report

Every finding below was **cross-verified by at least two independent methods** (browser DOM
inspection, raw `fetch()`, real `<form>.submit()` / navigation, server access-log inspection,
`curl` direct-to-server timing, or direct-DB test-fixture state changes) before being written
up. Two suspected issues were investigated, disproven as testing-tool artifacts, and
deliberately **excluded** — see §4. Nothing in this report is speculative; every "Actual
behavior" line reflects an outcome this session observed directly.

## 1. Structural scope gap — read this before the findings

The application currently exposes **two** browser-rendered modules: the **Dashboard**
(`/dashboard`) and **Invoices** (`/invoices`, `/invoices/{id}`). Every other backend capability
verified elsewhere in this migration — VAT rule management, workflow/approval engine, audit
cases, disputes, refunds, communications, consents, delegations, licensing/entitlements,
organisation administration, notifications — exists only as authenticated JSON API endpoints
under `/api/v1/*` with **no corresponding UI** for a browser-only user to reach. A black-box,
UI-only tester cannot exercise business logic that has no button, link, or form to trigger it
from.

**Consequence:** this assessment's Business Abuse, Fraud Resistance, and Authorization
phases are necessarily scoped to what a UI-only actor can reach today — principally the
invoice-certification API (reachable indirectly via the browser's own `fetch` context while
authenticated, which is a legitimate black-box vector: any user's browser session can be used
to script requests against APIs their session is authorized for) and the two rendered pages.
**This is not a "clean bill of health" for the ~90% of modules with no UI; it is untested
surface, not verified-safe surface**, and should be re-run once those UIs exist.

## 2. Environment and tooling limitations (bounding this assessment's confidence)

- **Dev server, not production topology.** `php artisan serve`'s single-threaded PHP built-in
  server is not the target production stack (PHP-FPM + nginx/Apache, per
  [DEPLOYMENT.md](DEPLOYMENT.md)). Absolute latency numbers in §3 (RT-004) will differ in
  production; the *root cause* (OPcache disabled) is environment-config, not code, and is
  worth confirming is enabled in the actual production/staging deployment.
- **No dedicated load-testing tool.** Concurrency claims here are bounded to what a handful of
  true parallel browser-originated requests (`Promise.all` over `fetch()`) can demonstrate —
  this validates race-safety logic, not throughput at realistic production concurrency.
- **Single browser instance.** No genuine multi-device/multi-browser-vendor session testing
  was performed.
- **No outbound email delivery configured** in this dev environment, so any
  notification/email-adjacent behavior could not be verified end-to-end.
- **Browser-pane tooling fidelity gap discovered and worked around** — see §4; all findings
  below were confirmed via the highest-fidelity method available (real DOM `form.submit()` /
  navigation, not synthetic clicks or bare `fetch()`), specifically because two synthetic-input
  artifacts were caught before being reported.

---

## 3. Confirmed findings

### RT-001 — Full authenticated page content remains viewable via browser back-navigation after logout

> **Status: FIXED (2026-09-02).** `App\Http\Middleware\PreventAuthenticatedPageCaching`
> now sets `Cache-Control: no-store, no-cache, must-revalidate, private` on every response
> in the `auth` route group (`routes/web.php`). Verified three ways: (1) 4 new tests in
> `tests/Feature/Auth/AuthenticatedPageCachingTest.php` pass, alongside the full existing
> Invoice/Dashboard/OrganisationScope suites (19 tests, no regressions); (2) live browser
> repro of the exact steps below now shows the Sign-in page, not the Dashboard, after
> logout + back-navigation; (3) the server access log now shows a real `GET /dashboard`
> request at the back-navigation step (a 302-driving revalidation), where the original bug
> showed zero requests at all. Steps 1–4 below are preserved as the original repro
> evidence.

| | |
|---|---|
| **Severity** | **High** |
| **User role** | Any authenticated role (reproduced as `TAXPAYER_OWNER`) |
| **Feature** | Session/logout lifecycle — application-wide (every session-authenticated page) |
| **Preconditions** | User is logged in and has viewed at least one authenticated page |

**Reproduction steps:**
1. Log in as any user; navigate to `/dashboard` (or any authenticated page) and confirm real
   data renders.
2. Log out via the real logout control (`POST /logout`), landing back on `/login`.
3. Press the browser's Back button (reproduced via the browser-automation equivalent of
   history-back navigation).
4. Read the resulting page content.

**Expected behavior:** The previously authenticated page must not be reconstructible from
local/back-forward cache after logout; a revalidation request to the server should occur and
the server should redirect to `/login` (session is destroyed server-side on logout).

**Actual behavior:** The full Dashboard — real KPI figures, the "TAXPAYER OPERATIONS" scope
label, the "Recent fiscal documents" table with actual invoice rows, and the "Evidence stream"
audit panel — was displayed in full. Server access logs for the time window show **zero**
request for `/dashboard` around the back-navigation: the page was served entirely from the
browser's own cache/back-forward cache, with no server round-trip and therefore no chance for
the (correct, working) server-side "redirect unauthenticated users to /login" logic to run at
all.

**Root-cause hypothesis:** Laravel's session middleware sends `Cache-Control: no-cache,
private` on session-backed responses (confirmed via `curl -D -` against both `/login` and
`/dashboard`: both return exactly `Cache-Control: no-cache, private`, no `no-store`, no
`Pragma: no-cache`). `no-cache` requires revalidation *before reuse from the HTTP cache*, but
does **not** prevent a browser's back-forward cache (bfcache) — a separate, whole-page-snapshot
mechanism — from serving the page directly on history navigation with no network request and
therefore no revalidation at all. Only `Cache-Control: no-store` (or explicit
`Clear-Site-Data`) reliably defeats bfcache replay in modern browsers. This is Laravel's
**global default** for every session-authenticated response in this application, so the gap is
systemic, not page-specific — it will reproduce identically on the Invoices list/detail pages
and every future authenticated page built on the same layout/middleware stack.

**Frequency:** 100% reproducible — every logout followed by a back-navigation on a bfcache-
eligible browser.

**Business impact:** On any device where a session is left open in a browser tab (shared
workstation, kiosk, borrowed device, screen left unlocked), a subsequent user pressing Back can
view real taxpayer financial data — invoice numbers, VAT amounts, supplier/customer names — with
no server request and no audit trail entry, even though the legitimate user believed they had
logged out.

**Customer impact:** Direct confidentiality exposure of the taxpayer's own certified fiscal
records to whoever next uses the device; erodes trust in "logout" as a real security boundary,
which matters acutely for a VAT compliance system handling sensitive financial records.

**Recommended solution:** Add `Cache-Control: no-store` (in addition to, or in place of,
`no-cache`) on all session-authenticated responses — either via a small middleware appended
after `StartSession` for the `web` group, or by configuring
`Illuminate\Http\Middleware\SetCacheHeaders`-style handling on the relevant route group. This
is the standard, narrowly-scoped fix; it does not require disabling HTTP caching for public
assets (CSS/JS/images), only for pages served through the authenticated session.

**Regression risks:** Low. `no-store` on already-dynamic, per-user pages has no legitimate
caching benefit today (these pages are rendered per-request from DB state), so there is no
loss of a currently-working cache optimization. Verify static asset routes (`/build/assets/*`)
are unaffected (they are served by a separate route/middleware group without session
middleware, so they should not pick up the change).

**Validation checklist:**
- [ ] `curl -D -` against `/dashboard` and `/invoices` (authenticated) shows `Cache-Control:
      no-store` in the response headers.
- [ ] Manual repro of steps 1–4 above no longer shows authenticated content after logout +
      back-navigation, in at least Chromium and Firefox.
- [ ] Server access log shows a real request (and a 302 redirect to `/login`) for the
      back-navigation attempt after the fix, proving revalidation now occurs.
- [ ] Static asset caching (CSS/JS/images, `/build/assets/*`) is unaffected — confirm via
      `curl -D -` that those responses still carry their existing long-lived `Cache-Control`.
- [ ] Full existing test suite (265 tests as of this assessment) still passes.

**Engineering prompt for an AI coding agent:**
> In this Laravel 12 application (`php-app/`), session-authenticated responses currently send
> `Cache-Control: no-cache, private` (Laravel's framework default), which does not prevent
> browser back-forward-cache (bfcache) replay of authenticated pages after logout — confirmed
> via manual reproduction: log in, view `/dashboard`, log out, press Back, and the full
> authenticated Dashboard renders with zero server request in the access log. Add a small
> middleware (e.g. `App\Http\Middleware\PreventBfcacheOnAuthenticatedPages`) that sets
> `Cache-Control: no-store, no-cache, must-revalidate, private` on every response from the
> `web` middleware group's authenticated routes (i.e. everything behind the `auth` middleware —
> `/dashboard`, `/invoices`, `/invoices/{id}`, and any future authenticated Blade routes),
> without touching the `/build/assets/*` static-asset routes or the `/login` page's own
> caching. Register it in `bootstrap/app.php` (or `app/Http/Kernel.php` if this app still uses
> the pre-12 kernel style — check which is present) appended after `StartSession` for routes
> using the `auth` middleware specifically, not globally for `web`, so unauthenticated pages
> like `/login` keep their current headers. Add a feature test in
> `php-app/tests/Feature/Auth/` that asserts a `GET` to `/dashboard` while authenticated
> returns a `Cache-Control` header containing `no-store`. Do not change anything about how
> logout itself works (session destruction is already correct) — this fix is purely about the
> HTTP caching directive on subsequent authenticated-page responses.

---

### RT-002 — Unhandled framework exception leaks a full stack trace on a cross-tenant authorization failure (debug-mode dependent)

| | |
|---|---|
| **Severity** | **Medium** (High if `APP_DEBUG=true` were ever active in production; the app's deployment docs already mandate `APP_DEBUG=false` in production, which is why this is scored Medium and not High) |
| **User role** | Any authenticated user attempting to access another taxpayer's resource, or an internal actor without an assigned taxpayer (e.g. `DEVELOPER_PARTNER`) attempting a taxpayer-scoped action |
| **Feature** | Tenant isolation (`TenantScope::requireTaxpayer()`) |
| **Preconditions** | `APP_DEBUG=true` (this session's local dev default; confirmed **not** the intended production setting) |

**Reproduction steps:**
1. Authenticate as a user whose role requires a bound taxpayer but who lacks one (or attempt
   an action against a taxpayer-scoped resource that does not belong to the acting user's
   taxpayer).
2. Trigger the code path that calls `TenantScope::requireTaxpayer()`.
3. Observe the JSON error response.

**Expected behavior:** A clean, generic 403/404 response consistent with the rest of the
application's own exception handling (the app's custom exceptions — e.g.
`PlatformResourceException`, `RepositoryConflictException` — each implement their own
`render()` and return clean, minimal-disclosure JSON regardless of debug mode).

**Actual behavior:** The response is a raw Laravel/Symfony debug payload: full exception
class name, file path (including the full local Windows filesystem path to the project), line
number, and a complete stack trace through the entire middleware pipeline.

**Root-cause hypothesis:** `TenantScope::requireTaxpayer()` throws a plain
`Illuminate\Auth\Access\AuthorizationException` rather than one of this application's own
custom exception types. The app's own exceptions all have bespoke `render()` methods that
produce clean output *independent of `APP_DEBUG`*; this one, being a stock framework
exception, falls through to Laravel's default exception handler, which — correctly, by
framework design — only sanitizes output when `APP_DEBUG=false`. The gap is architectural: one
authorization failure path was not brought under the same clean-rendering discipline as the
rest of the app's custom exception hierarchy, so it is silently dependent on an environment
flag rather than guaranteed by code.

**Frequency:** 100% reproducible whenever `APP_DEBUG=true` and this code path is hit.

**Business impact:** If `APP_DEBUG` were ever accidentally left enabled in a staging or
production environment (a common real-world misconfiguration, not a hypothetical), this path
would disclose the full server filesystem layout and internal class structure to any
authenticated user simply by attempting a boundary-violating action — a reconnaissance
foothold for further attack, with no exploit required to trigger it.

**Customer impact:** None if production configuration is correct; this is a defense-in-depth
gap, not an active exposure in the intended deployment.

**Recommended solution:** Give `TenantScope::requireTaxpayer()` its own dedicated exception
type (or catch/rewrap the `AuthorizationException` at the point it's thrown) that implements a
clean `render()` the same way the app's other custom exceptions do, so the clean-output
guarantee does not depend on `APP_DEBUG` at all — matching the design intent already
established elsewhere in this codebase.

**Regression risks:** Low — this only changes the *shape* of an already-failing (403/404)
response for a code path that should already never succeed for the actor in question; no
successful-request behavior changes.

**Validation checklist:**
- [ ] With `APP_DEBUG=true` locally, the reproduction above now returns clean, minimal JSON
      (no stack trace, no file path) matching the shape of the app's other custom exceptions.
- [ ] Existing tenant-isolation tests continue to pass (status code unchanged, only body
      shape).
- [ ] Grep the codebase for any other `throw new \Illuminate\...\Exception` (framework-native,
      not app-custom) on request-reachable authorization paths, to confirm this was the only
      instance of the pattern.

**Engineering prompt for an AI coding agent:**
> In `php-app/app/...` wherever `TenantScope::requireTaxpayer()` (or equivalent tenant-scope
> enforcement) is implemented, it currently throws a plain
> `Illuminate\Auth\Access\AuthorizationException`. Every other custom exception in this
> codebase (e.g. `PlatformResourceException`, `RepositoryConflictException` — locate them via
> `grep -r "extends Exception" php-app/app` or similar) implements its own `render()` method
> so responses stay clean regardless of `APP_DEBUG`. Confirmed via manual reproduction that
> triggering `requireTaxpayer()`'s failure path with `APP_DEBUG=true` returns Laravel's raw
> debug exception payload (full stack trace, local filesystem path) instead of a clean 403/404.
> Fix this by either (a) creating a small custom exception (e.g. `TenantScopeViolationException`
> extending the appropriate base) with its own `render()` returning a clean JSON error body at
> the correct status code, and throwing that instead, or (b) wrapping/rethrowing at the call
> site into one of the app's existing custom exception types if one already fits semantically.
> Keep the resulting HTTP status code identical to today's behavior (403, unless existing tests
> show otherwise — check `php-app/tests/` for any test asserting the current status code before
> changing anything). Add or update a feature test that authenticates as an actor without the
> right taxpayer scope, triggers this path, and asserts the JSON response does NOT contain the
> substrings `"trace"` or `.php` (a simple, robust way to assert no stack trace leaked),
> regardless of `APP_DEBUG` value in the test environment.

---

### RT-003 — Differential login error messages disclose account-suspension status (password oracle)

| | |
|---|---|
| **Severity** | **Low / Informational** |
| **User role** | Unauthenticated attacker who already possesses (or has guessed/leaked) a valid password for a target account |
| **Feature** | Login (`LoginRequest::authenticate()`) |
| **Preconditions** | Attacker knows a target account's email and its correct current password; the account has been administratively suspended |

**Reproduction steps:**
1. (Test-fixture setup, not part of the attacker's own capability) mark a known test account
   `SUSPENDED`.
2. Submit the login form with that account's email and its **correct** password.
3. Observe the error message: **"This account has been suspended."**
4. Submit the same form again with the same email and a **wrong** password.
5. Observe the error message: **"These credentials do not match our records."**

Both submissions were made via real DOM form submission (not synthetic click or bare
`fetch()`, per this session's established higher-fidelity method) and both message variants
were directly observed in the rendered page.

**Expected behavior:** Both cases should ideally return the same generic message, since an
attacker who does not already know the correct password should learn nothing about whether an
account exists, or its status, from the login form.

**Actual behavior:** The message differs based on account status, but **only once the
submitted password is already correct** — `Auth::attempt()` is checked first and must succeed
before the suspension check is ever reached (confirmed by code trace and the reproduction
above: a wrong password against the same suspended account returns the generic message, not
the suspension message).

**Root-cause hypothesis:** `LoginRequest::authenticate()` performs `Auth::attempt()` (which
verifies both email and password together) first, and only checks `$user->isActive()`
afterward — so the suspension-specific message is only ever reachable once the correct
password has already been supplied. This is a narrower version of the classic
account-enumeration pattern (CWE-203, Observable Discrepancy): it does not let an attacker
discover whether an *unknown* email/password pair is valid, but it does let someone who already
holds valid credentials (e.g. a departed employee, or a credential-stuffing attacker using a
password reused/leaked from another breach) confirm that the specific reason access is denied
is "administratively suspended" rather than, say, a typo or expired session — mildly useful
reconnaissance, not account takeover on its own.

**Frequency:** 100% reproducible given a correct password and a suspended account.

**Business impact:** Minor. Does not by itself allow login or account discovery; only adds a
small amount of situational information for an attacker who has already cleared the higher bar
of possessing a valid password.

**Customer impact:** Negligible in isolation; worth closing as defense-in-depth rather than as
an urgent fix.

**Recommended solution:** Collapse both cases to the same generic message ("These credentials
do not match our records.") at the login form, and reserve any account-status-specific
messaging for a channel the account owner controls (e.g. a notification/email sent to the
account's registered address when a suspended account is targeted by a correct-password login
attempt), not the login response itself.

**Regression risks:** Very low — purely a copy/message change; no behavior, redirect, or
status-code change is implied for either branch (both already correctly deny access).

**Validation checklist:**
- [ ] Correct password + suspended account now shows the same generic message as wrong
      password + any account.
- [ ] Existing suspended-account-denial test(s) still confirm access remains denied (only the
      message text changes, not the deny decision).
- [ ] If a notification-on-attempted-suspended-login channel is added, confirm it does not
      itself leak information back to the requester (i.e. the HTTP response stays generic
      either way).

**Engineering prompt for an AI coding agent:**
> In `php-app/app/Http/Requests/Auth/LoginRequest.php`, `authenticate()` currently returns a
> distinct message — `'This account has been suspended.'` — when `Auth::attempt()` succeeds
> (correct password) but `$user->isActive()` is false, versus the generic `'These credentials
> do not match our records.'` when the password itself is wrong. Confirmed via manual browser
> reproduction that these two messages are distinguishable, letting someone who already has a
> valid password for a suspended account learn that fact. Change the suspended-account branch
> (`if (! $user->isActive())`) to throw the same generic message
> (`'These credentials do not match our records.'`) instead of the suspension-specific one, so
> both denial paths are indistinguishable from the login response. Keep `Auth::logout()` and
> the rest of the control flow (rate-limiter clear/hit calls) unchanged — only the message
> string changes. If there is an existing test asserting the literal suspended-account message
> text (search `php-app/tests/` for `'This account has been suspended.'`), update it to assert
> the generic message instead, and add a companion assertion (if not already present) that a
> wrong password against a suspended account produces byte-identical response content to a
> wrong password against an active account, proving the two cases are no longer
> distinguishable.

---

### RT-004 — Severe request latency caused by disabled PHP OPcache (deployment configuration, not application code)

| | |
|---|---|
| **Severity** | **High** (performance/availability risk under real load) — but classified as a **deployment/infrastructure configuration** finding, not an application code defect |
| **User role** | All users |
| **Feature** | Every page/request in the application |
| **Preconditions** | Current local dev environment configuration (confirmed; production configuration was not directly inspected as part of this black-box assessment and should be independently verified) |

**Reproduction steps:**
1. `curl -s -o /dev/null -w "%{time_total}\n" http://localhost:8123/login` repeated several
   times — a fully static Blade view with no database queries in
   `LoginController::create()`.
2. Observe sustained ~500ms per request, with an outlier cold-start request measured at ~23
   seconds.
3. `php -m | grep opcache` → empty (module not loaded).
4. `php -r "var_dump(function_exists('opcache_get_status') ? opcache_get_status() : 'opcache not available');"`
   → confirmed `"opcache not available"`.

**Expected behavior:** A static, DB-free Blade page render should return in single-digit-to-
low-double-digit milliseconds on modern hardware with OPcache enabled (Laravel/PHP's own
guidance treats OPcache as a baseline production requirement, not an optional tuning step).

**Actual behavior:** ~500ms sustained per request; ~23s on the measured cold start. Every PHP
file in the request's autoload/require chain (Laravel's framework itself is large) is being
re-parsed and re-compiled from source **on every single request**, because there is no opcode
cache retaining compiled bytecode between requests.

**Root-cause hypothesis:** OPcache is not enabled in this PHP CLI/dev-server configuration
(`php.ini` for this XAMPP install does not load/enable the `opcache` extension, or it is
present but disabled). This is a PHP runtime configuration matter, entirely independent of
this application's own code quality.

**Frequency:** 100% — every request, in this environment.

**Business impact:** If this configuration were replicated in production, the application
would be severely throughput-constrained (a single-digit number of requests per second per
worker before saturating), directly threatening availability under any real concurrent load —
exactly the kind of gap "Performance Under Heavy Use" testing exists to surface. This is the
single most consequential finding in this assessment in terms of potential production impact,
*if* production shares this configuration — which was not directly verified (out of scope for
black-box browser testing) and should be checked immediately as a follow-up.

**Customer impact:** Slow page loads, timeouts under concurrent usage, and potential outages
during peak filing periods (e.g. VAT return deadlines) if uncorrected in production.

**Recommended solution:** Enable and properly configure OPcache (`opcache.enable=1`,
`opcache.enable_cli=0` is fine, `opcache.validate_timestamps` tuned appropriately for the
deployment strategy — typically `0` with a cache-clear step in the deploy pipeline for
production) in the actual production PHP-FPM configuration, and confirm via
`opcache_get_status()` post-deploy. Also run `php artisan config:cache`, `route:cache`, and
`view:cache` as part of the production deploy step if not already — these compound with
OPcache and are standard Laravel production practice per Laravel's own deployment
documentation.

**Regression risks:** Low, when following Laravel's documented OPcache + `validate_timestamps`
guidance for production; the main operational risk is *stale* cached opcodes/config after a
deploy if the cache isn't cleared/reset as part of the deploy pipeline — this should already be
covered by [DEPLOYMENT.md](DEPLOYMENT.md)'s deploy steps; confirm it explicitly includes an
OPcache reset (e.g. via `opcache_reset()` on deploy, or `validate_timestamps=1` with a sane
`revalidate_freq`).

**Validation checklist:**
- [ ] Confirm the actual target production/staging PHP-FPM `php.ini` has
      `opcache.enable=1` and `zend_extension=opcache` loaded (`php -m | grep -i opcache` on
      that host, or `phpinfo()`/`opcache_get_status()` via an authenticated diagnostic route).
- [ ] Re-run the `curl` timing test against that environment after enabling OPcache; expect
      order-of-magnitude improvement on static pages.
- [ ] Confirm `docs/DEPLOYMENT.md`'s deploy steps include `config:cache`, `route:cache`,
      `view:cache`, and an OPcache reset/warm step.
- [ ] Load-test (with a real tool — k6, Apache Bench, or similar — not available in this
      black-box browser assessment) after the change to confirm throughput under realistic
      concurrency.

**Engineering prompt for an AI coding agent:**
> This is a deployment-configuration task, not an application-code change. Confirmed via
> `curl` timing (~500ms sustained per request, ~23s on a cold start, for a fully static
> DB-free Blade page — `GET /login`) and direct inspection (`php -m | grep opcache` returns
> empty; `opcache_get_status()` returns "opcache not available") that PHP's OPcache extension
> is not enabled in the current local dev environment. Task: (1) locate and review
> `php-app/docs/DEPLOYMENT.md` for the documented production PHP configuration and deploy
> steps; (2) confirm whether it already specifies enabling OPcache (`opcache.enable=1`,
> appropriate `opcache.validate_timestamps`/`opcache.revalidate_freq` settings for how deploys
> happen) — if it's missing, add an explicit, actionable OPcache configuration section
> (recommended `php.ini` directives, and a note that `opcache_reset()` or a process
> restart/reload must happen on every deploy so stale bytecode is never served); (3) confirm
> the deploy steps include `php artisan config:cache`, `route:cache`, and `view:cache` (add
> them if missing, in the correct order relative to `migrate` — config/route/view caching
> should happen after code is in place but the exact Laravel-recommended order should be
> followed); (4) do not modify any application PHP code — this is purely a
> `docs/DEPLOYMENT.md` and (if a checked-in `php.ini`/deployment config file exists in this
> repo) configuration-file change.

---

### RT-005 — No self-service password-reset / account-recovery flow exists

| | |
|---|---|
| **Severity** | **Medium** |
| **User role** | All authenticated-application users |
| **Feature** | Authentication / account recovery |
| **Preconditions** | None |

**Reproduction steps:**
1. From `/login`, look for a "Forgot password?" link or any account-recovery entry point.
2. Attempt to navigate directly to Laravel's conventional password-reset routes
   (`/forgot-password`, `/password/reset`).

**Expected behavior:** A production system handling sensitive financial/compliance data for
external taxpayer users should offer a self-service, auditable password-reset flow, since
manual administrator password resets do not scale and are themselves an operational security
risk (support-staff handling of new credentials).

**Actual behavior:** No such link exists on the login page, and no password-reset routes are
registered (already independently documented as a known gap in
[DEPLOYMENT.md](DEPLOYMENT.md), and confirmed still absent as of this assessment).

**Root-cause hypothesis:** Not yet built — this migration has so far prioritized the
certification/ledger/compliance core over account-lifecycle self-service flows.

**Frequency:** N/A (absence of a feature, not an intermittent defect).

**Business impact:** Every locked-out user requires manual administrator intervention to
regain access, which does not scale past the current pilot user count and creates an
operational bottleneck and a support-workflow security risk (staff handling/transmitting new
credentials by some out-of-band channel).

**Customer impact:** Users who forget their password have no recourse except contacting
support directly, which is a materially worse experience than the self-service norm for any
production SaaS-style system, and disproportionately impacts users at critical moments (e.g.
close to a VAT filing deadline).

**Recommended solution:** Implement Laravel's standard password-reset flow
(`Illuminate\Auth\Passwords`, `Password::sendResetLink()` / `Password::reset()`) with a
dedicated `password_reset_tokens` table (already a default Laravel migration — confirm whether
it exists in `php-app/database/migrations/`), rate-limited request/reset endpoints, and a
"Forgot password?" link added to the existing `login.blade.php`.

**Regression risks:** None to existing functionality — this is a net-new, additive flow.

**Validation checklist:**
- [ ] Confirm whether `password_reset_tokens` table/migration already exists (Laravel ships
      it by default; the migration may simply not have been run/kept if auth was heavily
      customized for this app).
- [ ] "Forgot password?" link present and reachable from `/login`.
- [ ] Reset-link request is rate-limited (mirroring the login throttle pattern already
      established in `LoginRequest`).
- [ ] Reset-link request does not disclose whether an email address exists in the system (same
      generic confirmation message regardless).
- [ ] Reset tokens expire and are single-use.
- [ ] New feature tests added under `php-app/tests/Feature/Auth/` covering the full
      request → email-link → reset → login cycle.

**Engineering prompt for an AI coding agent:**
> This Laravel 12 application (`php-app/`) has no password-reset flow: `/login` has no "Forgot
> password?" link, and no password-reset routes exist (confirmed by direct navigation attempts
> and already noted as a known gap in `php-app/docs/DEPLOYMENT.md`). Implement Laravel's
> standard password-reset flow: (1) confirm/add the `password_reset_tokens` migration (Laravel
> ships this by default in `database/migrations/0001_01_01_000000_create_users_table.php` or a
> dedicated migration — check what's present in `php-app/database/migrations/` first, since
> this app's user table/auth was customized during the migration from a prior stack); (2) add
> `ForgotPasswordController`/`ResetPasswordController`-equivalent controllers (or route
> closures, matching this app's existing `LoginController`/`LoginRequest` style rather than
> Laravel's older scaffolding conventions) exposing `GET /forgot-password`,
> `POST /forgot-password`, `GET /reset-password/{token}`, `POST /reset-password`; (3) add
> corresponding Blade views under `resources/views/auth/` matching the existing
> `login.blade.php`'s layout/accessibility conventions (it uses `resources/views/layouts/app.blade.php`
> or a dedicated auth layout — check first); (4) rate-limit the reset-link-request endpoint
> using the same `RateLimiter` pattern already used in `LoginRequest::ensureIsNotRateLimited()`;
> (5) ensure the "check your email" confirmation message is identical regardless of whether the
> submitted email exists in the system, to avoid account enumeration; (6) add a "Forgot
> password?" link to `resources/views/auth/login.blade.php`; (7) write feature tests in
> `php-app/tests/Feature/Auth/PasswordResetTest.php` covering: requesting a reset link for a
> valid and an invalid email (identical response either way), using a valid token to set a new
> password and logging in with it, an expired/invalid token being rejected, and a used token
> being single-use. Use the project's actual mailer configuration for the reset-link email
> (check `config/mail.php` and `.env.example` for what's already set up) and use Laravel's
> `Illuminate\Auth\Notifications\ResetPassword` notification pattern unless this app's existing
> notification/audit conventions (see `AuditService`) call for something more consistent with
> how the rest of the app logs account-lifecycle events — if so, also emit an audit event for
> password-reset requests and completions.

---

## 4. Investigated and disproven — deliberately excluded

Two anomalies were observed during testing, investigated with independent cross-verification,
and determined to be **artifacts of the browser-automation tooling used in this session**, not
application defects. They are recorded here for transparency, per this assessment's own
no-fabrication standard, rather than omitted silently.

- **Synthetic click on the login "Sign in" button produced no visible error and no server
  request**, across multiple clean attempts. Cross-verified via `document.elementFromPoint()`
  (confirmed the click coordinate does land on the button), a raw `fetch()` POST (confirmed the
  server does respond correctly), and finally a genuine `form.submit()` DOM call (which
  correctly displayed the expected validation error). Conclusion: the automated browser
  pane's synthetic mouse-click event is not reliably triggering this particular button's
  native form-submit behavior — a tooling limitation, not an app bug. All subsequent
  form-based tests in this assessment (including RT-003 above) used the proven-reliable real
  `form.submit()` / navigation method instead.
- **A bare `fetch()`-based login attempt returned the login page with the validation-error
  block empty.** The Blade template's `@if ($errors->any())` markup was independently confirmed
  correct by direct inspection. Re-tested via real navigation and the error displayed
  correctly. Conclusion: `fetch()` does not faithfully replicate real-browser
  navigation/session-referer semantics that Laravel's `redirect()->back()` (used internally by
  validation-error handling) depends on — a testing-methodology gap, not an app bug.

## 5. Positive controls confirmed (not findings — recorded for completeness)

The following were actively tested and found to work correctly, and are recorded so this
report is not one-sided:

- **Idempotency-Key mechanism** is genuinely race-safe under true 5-way concurrent
  (`Promise.all`) duplicate invoice submission — exactly one invoice row was created, verified
  by direct database count.
- **Same idempotency key with a different payload correctly returns 409 Conflict**, rather than
  silently accepting the second, different payload.
- **Tenant isolation on invoice routes**: a user outside a given taxpayer scope receives 404
  (not 403, avoiding resource-existence disclosure) for both the invoices list (via automated
  feature tests) and the invoice detail page.
- **Permission gating** (`invoices:read`) is enforced on both the list and detail view routes;
  a role without the permission is correctly forbidden.
- **Login rate limiting** is present and does activate ("Too many login attempts...") after a
  sustained batch of failed attempts. This assessment does not assert an exact attempt
  threshold as a validated fact, since a mid-assessment context reset made precise
  attempt-counting across that boundary unreliable — the qualitative behavior (it activates)
  was directly observed and is reported with that explicit caveat rather than a fabricated
  precise number.

## 6. Summary

| ID | Title | Severity |
|---|---|---|
| RT-001 | Authenticated content viewable via back-navigation after logout (bfcache) | High — **FIXED** |
| RT-002 | Stack trace leak on cross-tenant authorization failure (debug-mode dependent) | Medium |
| RT-003 | Account-suspension status disclosed via differential login message | Low/Info |
| RT-004 | OPcache disabled — severe per-request latency | High (config, not code) |
| RT-005 | No self-service password-reset flow | Medium |

**Overall assessment:** the application's core transactional integrity controls
(idempotency, tenant isolation, permission gating) held up well under adversarial testing
within the surface this UI-only assessment could reach. The confirmed findings are real but
narrow: one systemic-but-cheap-to-fix session-caching gap (RT-001), one debug-mode-contingent
information leak (RT-002), one low-severity oracle (RT-003), one environment-configuration
performance risk that must be verified against actual production config (RT-004), and one
missing self-service flow (RT-005). The larger, unresolved risk is not a discovered defect but
a **coverage gap**: the majority of this system's business logic has no UI yet and could not
be exercised by a black-box browser tester at all.
