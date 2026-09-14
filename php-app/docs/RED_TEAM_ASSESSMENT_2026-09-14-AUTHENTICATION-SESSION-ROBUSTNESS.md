# VAT-MSA Red Team Assessment — Authentication & Session Robustness (Phase 7)

**Date:** 2026-09-14
**Scope:** Phase 7 of the user's original 10-phase brief. Prior passes had
already hardened login (rate limiting, generic error messages -- RT-003) and
added a self-service password-reset flow (RT-005), both from the
2026-09-02 assessment. This pass asks a different question: once a session
exists, how resilient is it to the events that should end it early --
an administrative account suspension mid-session, and a password reset
triggered because the account may be compromised?
**Method:** Read the authentication surface end-to-end
(`LoginController`, `LoginRequest`, `ForgotPasswordRequest`/
`ResetPasswordRequest`, `ConfirmPasswordController`, the `permission` Gate
in `AppServiceProvider`, the employee-offboarding suspension path in
`OrganisationAdminService`) to map every point that could end a session
early, then live-reproduced both candidate gaps against a real running
instance (`artisan serve`, real MySQL, real cookie jars via `curl` --
**not** PHPUnit's `actingAs()`/test client, which turned out to give a
misleading result for one of the two candidates; see the false-start note
below).
**Environment:** This session's own sandboxed dev stack. Full suite: 628
tests before this pass, 629 after.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request.

---

## 1. Confirmed finding

### RT-019 — A password reset left every pre-existing session (an attacker's included) fully valid

> **Status: FIXED (2026-09-14).** `ResetPasswordRequest::resetPassword()`
> changed the password hash and rotated `remember_token`, but never
> touched the `sessions` table -- Laravel's session guard trusts an
> already-established session cookie without re-checking the password
> hash on every request, so nothing stopped a session created **before**
> the reset (an attacker's, if the reset was prompted by suspected
> credential compromise; or simply the same legitimate user's other
> already-open browser/device) from continuing to work indefinitely with
> full access, entirely unaffected by the password having changed. Fixed
> by deleting every `sessions` row for that user as part of the reset,
> forcing re-authentication everywhere.

| | |
|---|---|
| **Severity** | **High** |
| **User role** | Any authenticated user (the entire self-service password-reset flow, added by RT-005, exists specifically for locked-out/possibly-compromised accounts) |
| **Feature** | `POST /reset-password` (the self-service password reset completion) |

**Reproduction steps (pre-fix, live against a running instance with real
MySQL, real session cookies via `curl` against `php artisan serve` --
deliberately *not* PHPUnit's test client, see the note below):**

1. Log in as a real user twice, with two independent cookie jars (Session
   A and Session B) -- representing, for example, the legitimate user's
   own second device, or an attacker who obtained valid credentials and
   is already logged in on their own machine.
2. `GET /dashboard` with each cookie jar: both return `200`.
3. Trigger the real forgot-password flow (`POST /forgot-password`),
   retrieve the reset token (the dev mail driver logs it; production
   would email it to the account holder), and complete the reset
   (`POST /reset-password`) with a brand-new password. `302` redirect to
   `/login`, no errors -- a fully successful reset.
4. `GET /dashboard` again with **both original cookie jars, unchanged**.

**Actual behavior (pre-fix):** Both Session A and Session B still
returned `200` -- full, unimpeded dashboard access, completely
unaffected by the password reset that had just occurred. A fresh login
with the *new* password also worked, confirming the reset itself was
otherwise correct; the gap was specifically that it did nothing to the
sessions that already existed under the *old* password.

**A false start worth recording:** the first attempt to reproduce this
used PHPUnit's `actingAs()`/in-process test client rather than real HTTP
against a running server, and appeared to show a *different*, unrelated
result was inconclusive for a similar-looking candidate (see \S3's note on
the suspended-user probe) purely as an artifact of the test harness
reusing a single booted application container -- and therefore a single
cached `Auth` guard instance -- across what should have been two
independent "requests" within one test method. That risk applies equally
to any session-lifecycle claim tested this way, so this specific finding
was deliberately verified with real, separate HTTP requests against
`php artisan serve` instead, each one a genuinely fresh process
resolving the user fresh from the database -- the only way to trust the
result for a question that is fundamentally about *inter-request* state.

**Business impact:** The self-service password-reset flow exists
specifically to let a user recover from a locked-out or **compromised**
account (RT-005's own stated rationale). If a credential compromise is
the actual reason a user resets their password -- the single most
important real-world case this flow is meant to serve -- the fix was a
no-op against the one session that matters most: the attacker's own,
already-authenticated one. For a government tax-certification platform
handling VAT filings, refund claims, and invoice certification, an
attacker who is not evicted by their victim's own remediation step can
continue submitting certified transactions, viewing financial records, or
taking any other action available to that account for as long as their
session cookie remains valid (`SESSION_LIFETIME=120` minutes of
inactivity by default, materially longer with a "remember me" cookie) --
completely undetectable to the legitimate user, who has every reason to
believe the reset "fixed" the problem.

**Root-cause hypothesis:** Laravel's `SessionGuard` authenticates a
request purely from the session's stored user ID (`login_web_<hash>`),
re-resolving the `User` model fresh from the database on each request but
never re-validating the password hash against anything -- by design, this
is what lets a session outlive a page reload without asking for the
password again. That design choice makes it the *application's*
responsibility to actively terminate other sessions on a
security-sensitive event; this app already has exactly the mechanism for
that (`SESSION_DRIVER=database`, with a `sessions` table carrying an
indexed `user_id` column specifically enabling a targeted
"log out this user everywhere" query), but nothing in the reset flow used
it.

**Recommended solution (implemented):** Inside `ResetPasswordRequest::
resetPassword()`'s `Password::reset()` callback, immediately after the
new password is saved:

```php
// Fraud/Authentication Resistance pass (2026-09-14): a password reset is
// very often a response to a suspected compromise -- but Laravel's session
// guard trusts an already-established session cookie without re-checking
// the password hash, so nothing here previously stopped an attacker's own
// already-authenticated session (or any other device's) from continuing to
// work with the new password never having invalidated it.
DB::table('sessions')->where('user_id', $user->id)->delete();
```

This forces every device -- including the one the reset itself was
submitted from -- to log in again with the new credential, which is the
standard, expected UX for "I just reset my password" (confirmed live:
the reset's own redirect target is already `/login`, so the submitting
browser was never relying on an existing session surviving the reset in
the first place).

**Regression risks:** None expected -- a user who has just reset their
password expects, and is already being redirected, to log in again; no
existing flow relies on a session surviving a password reset. Verified
with a live re-reproduction of the original exploit (both pre-existing
sessions now correctly redirected to `/login`, and a fresh login with the
new password still worked normally) plus a new permanent regression test
operating directly on the `sessions` table (real `database` driver
semantics, since the test environment's own `SESSION_DRIVER=array`
cannot exercise this any other way -- see \S3).

**Validation checklist:**
- [x] Two independent pre-reset sessions for the same user → both
      redirected to `/login` immediately after the reset (re-verified
      live against a running instance with real, separate `curl` cookie
      jars).
- [x] A fresh login with the new password still succeeds and reaches the
      dashboard normally.
- [x] A different user's own unrelated session is left untouched (a
      `user_id`-scoped delete, not a table truncate).
- [x] 1 new permanent regression test in
      `tests/Feature/Auth/PasswordResetTest.php`, asserting directly
      against the `sessions` table.
- [x] Full suite (629 tests) passes with zero regressions.

## 2. Positive controls confirmed (not findings)

- **Login itself is already well-hardened.** `LoginRequest` rate-limits
  by `email|ip` (5 attempts), returns the identical generic message for a
  wrong password, an unknown email, *and* a correct password against a
  suspended account (RT-003's own fix, re-read and confirmed still
  correct), and `LoginController::store()` regenerates the session ID on
  every successful login (fixation protection). `LoginController::
  destroy()` (logout) both invalidates the session and rotates the CSRF
  token.
- **An account suspension mid-session is, in practice, enforced on the
  very next request -- live-confirmed, correcting an initial
  misdiagnosis.** `Gate::define('permission', ...)` in
  `AppServiceProvider` checks `$user->isActive()` on every
  permission-gated action, and essentially every authenticated route in
  this app (including the plain dashboard) is permission-gated. A first
  attempt to test this used PHPUnit's `actingAs()` test client and
  appeared to show a suspended user's session continuing to work --
  investigating further, this was purely an artifact of the test
  harness reusing one booted container (and therefore one cached `Auth`
  guard) across "separate" requests in a single test method, not real
  inter-request behavior. Re-tested against a real running instance with
  two genuinely separate HTTP requests (`curl`, real cookies): a
  suspended user's pre-existing session correctly received `403` on its
  very next request. No fix needed here; recorded as a positive control,
  and as a caution about this specific class of test (see \S3).
- **Password strength and reset-token handling are already correct.**
  `ResetPasswordRequest` enforces a 10-character minimum with mixed case
  and numbers; a used or invalid/expired token is rejected with the same
  generic message either way (no account-enumeration or replay surface);
  the forgot-password request itself is separately rate-limited and
  returns an identical response regardless of whether the email is
  registered (RT-005, re-confirmed still correct).
- **Sensitive administrative actions already carry step-up
  re-authentication.** Employee termination/suspension, taxpayer
  suspension, organisation-membership changes, VAT-rule approval, and
  several other high-impact routes all carry the `password.confirm`
  middleware, requiring a fresh password confirmation even from an
  already-authenticated admin session -- confirmed by reading
  `routes/web.php`'s middleware annotations, not a new finding.

## 3. A methodology note: PHPUnit's `actingAs()` is the wrong tool for inter-request session-lifecycle questions

This pass's own process is worth recording since it nearly produced a
false conclusion. `actingAs()`/the standard Laravel feature-test client
reuses a single booted service container (and therefore a single, already
-resolved `Auth` guard and `User` instance) across multiple `$this->get()`
calls within one test method, rather than tearing down and rebuilding the
application the way two genuinely separate HTTP requests to `php artisan
serve` (or any real deployment) would. A probe test written this way
showed a suspended user's dashboard request returning `200` on both sides
of the suspension -- which, taken at face value, would have been a
serious, wrongly-diagnosed finding. Re-testing with real, independent
`curl` requests against a running instance immediately showed the correct
`403`. Every finding and positive control in this report that depends on
*inter-request* state (both items in \S1/\S2 above) was therefore
verified this way rather than trusted from a PHPUnit run alone --
consistent with this session's established rigor standard, but worth
calling out explicitly here since it is exactly the class of question
where that standard's payoff (catching a real gap, and avoiding a false
one) was most visible.

## 4. What this pass did not cover

- **"Remember me" token lifecycle beyond the reset fix above.** The
  reset flow already rotates `remember_token` (pre-existing behavior,
  unrelated to this pass's fix), but this pass did not separately audit
  the remember-me cookie's own expiry/rotation semantics beyond
  confirming the new session-table delete also covers it (a
  `remember_token`-authenticated request still resolves through the same
  `sessions` table once re-authenticated).
- **Multi-factor authentication** does not exist in this application at
  all (confirmed by the absence of any MFA-related code, config, or
  route) -- out of scope for a gap-finding red-team pass against existing
  functionality, but worth naming as a design-level absence for the
  user's own prioritization, not a "fix" this pass attempted.
- **Concurrent-session limits / device management UI** (e.g. a
  "log out all other sessions" self-service button, or a list of active
  sessions) do not exist. RT-019's fix handles the specific
  security-relevant case (a password reset), but a user with no other
  reason to reset their password has no way to voluntarily audit or
  terminate their own other sessions.
- **The remaining phases** of the user's original 10-phase brief (High
  Interaction Stress, Concurrent User Simulation, Performance Under Heavy
  Use, Resilience to User Errors beyond RT-017's data-entry-error angle,
  UX Failure Discovery) remain untouched, for the same single-worker
  `php artisan serve` constraint this session has repeatedly and honestly
  noted for the concurrency-dependent phases specifically.

## 5. Summary

| ID | Title | Severity |
|---|---|---|
| RT-019 | Password reset did not invalidate pre-existing sessions | High — **FIXED** |
| — | Account-suspension enforcement, login hardening, step-up re-auth coverage | Checked, already correct |

**Overall assessment:** one genuine, live-reproduced session-lifecycle gap
found and fixed -- a password reset, the flow this application offers
specifically for a suspected-compromise scenario, did not evict the one
session an attacker in that exact scenario would already hold. A second,
superficially similar candidate (suspension enforcement) was initially
misdiagnosed as broken by a test-harness artifact and, on correct live
re-verification, confirmed to already work correctly -- recorded here in
detail as a methodology caution for any future work on this class of
question.
