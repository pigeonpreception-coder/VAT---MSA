# VAT-MSA Security Review — Post-2026-09-02 Modules

**Date:** 2026-09-13
**Scope:** Following up on `docs/RED_TEAM_ASSESSMENT_2026-09-02.md`'s own stated limitation —
that assessment was explicitly UI-only and scoped to the 3 modules that had a browser UI at the
time (Dashboard, Invoices, VAT Returns). Every other module has since gained a real Blade UI
(56 views today, per `docs/LAUNCH_READINESS_BACKLOG.md`'s item #2) but none of that surface had
ever had an adversarial pass. This review targeted that gap: systemic, cross-cutting checks
(rate limiting on sensitive endpoints, CSRF, mass assignment, IDOR/tenant scoping, self-approval/
segregation-of-duties, `password.confirm` step-up coverage, file-upload validation, custom-role/
dynamic-permission privilege boundaries) plus a targeted look at the highest-privilege modules
built since 2026-09-02 — most pointedly this session's own new "grant a user an access right"
feature, since a brand-new privileged feature is exactly where a reviewer's own blind spots are
most likely to hide.
**Method:** Source-available review (this session is the application's own author/migrator),
with every conclusion independently reproduced — a live PHPUnit feature test proving the actual
HTTP/service-layer behavior, not just a reading of the code — before being reported here, same
standard the 2026-09-02 assessment held itself to.
**Environment:** This session's own sandboxed dev stack — PHP (`artisan serve`), real
MySQL/MariaDB (not SQLite), full suite run both before and after every change.
**Tester:** Claude (Anthropic), acting as the migration engineer, running this review at the
user's explicit request (`docs/LAUNCH_READINESS_BACKLOG.md`'s own recommended next step).

---

## 0. How to read this report

Same convention as the 2026-09-02 assessment: every finding was independently reproduced (a
live feature test, not a code-reading inference) before being written up. One investigated
hypothesis was **disproven** — a suspected critical privilege-escalation path turned out to
already be blocked by an existing safeguard — and is recorded in full under §3 rather than
omitted, per that same no-fabrication standard. Nothing below is speculative.

## 1. Confirmed findings

### RT-006 — No rate limiting on the password-confirmation step-up gate (brute-force oracle in front of every privileged action)

> **Status: FIXED (2026-09-13).** `App\Http\Controllers\Auth\ConfirmPasswordController::store()`
> now rate-limits on the same 5-attempts-then-lock shape as `LoginRequest`, keyed by the
> authenticated user's own id plus IP (the actor is already known here, unlike a pre-auth login
> attempt — email+IP would not be meaningful). A wrong password increments the limiter and
> returns the existing friendly field error unchanged; a correct one clears it, also unchanged.
> Verified: 2 new tests in `tests/Feature/Auth/ConfirmPasswordTest.php` (5 wrong attempts locks
> out a 6th, even-correct, attempt with a friendly rate-limit message; a correct password before
> the limit clears the counter) plus the full 583-test suite, zero regressions.

| | |
|---|---|
| **Severity** | **High** |
| **User role** | Any authenticated user (the attacker needs a live session — stolen cookie, XSS, a shared/left-unlocked device — but not the account's actual password) |
| **Feature** | `password.confirm` step-up re-authentication — the gate in front of every privileged POST route in the app (grant/revoke an access right, provision platform staff, suspend a taxpayer, decide a registration, assign a membership, decide/certify/revoke access governance, and more) |
| **Preconditions** | Attacker already holds (or has hijacked) a live authenticated session as the target user, but does not know that account's password |

**Reproduction steps:**
1. Authenticate as any user (a live session is the only precondition — no privileged role
   needed, since the gate sits in front of the privileged action, not behind one).
2. `POST /confirm-password` repeatedly with a wrong password.
3. Observe: no lockout occurs at any attempt count — every submission returns the same
   "provided password does not match" error, indefinitely.
4. Compare against `POST /login`: the same repeated-wrong-password pattern locks out after 5
   attempts with a "Too many login attempts" message (`LoginRequest::ensureIsNotRateLimited()`).

**Expected behavior:** A password re-entry endpoint gating privileged actions should be at
least as hard to brute-force as the login form itself — arguably harder, since the actor is
already authenticated and the check exists specifically to defend against a stolen-session
scenario the login form's own rate limiter does not cover at all (the login form's limiter never
even runs in this scenario, since the attacker skips login entirely by reusing a live session).

**Actual behavior:** Zero rate limiting existed on this endpoint. An attacker with a live
session could submit unlimited password guesses against `/confirm-password` with no lockout,
no delay, and no distinguishable signal to a defender beyond ordinary failed-attempt logging (if
any).

**Root-cause hypothesis:** `ConfirmPasswordController::store()` was built (2026-09-02, RT-002/
this feature's own original construction) around `Auth::guard('web')->validate()` alone, mirroring
Laravel's own minimal scaffolding convention for this controller — which itself has no built-in
rate limiter, unlike `Illuminate\Foundation\Auth\AuthenticatesUsers`'s login trait. The gap was
never a deliberate decision recorded anywhere in `docs/MIGRATION_MATRIX.md` or this controller's
own doc comment; it was an omission that a UI-only black-box assessment (2026-09-02, scoped to
Dashboard/Invoices/VAT Returns, none of which sat behind a step-up gate at the time) had no
occasion to surface.

**Frequency:** 100% reproducible — every attempt, no threshold ever reached.

**Business impact:** This gate stands directly in front of the single most consequential set of
actions in the entire application: granting a user any role (including SUPER_ADMIN, via this
session's own new access-rights feature), suspending a taxpayer, deciding a registration,
assigning organisation memberships, and every other step-up-gated write. An attacker who
compromises a session (a stolen cookie is a far lower bar than a stolen password — session
tokens leak via XSS, unlocked devices, or a misconfigured reverse proxy far more often than a
password does) could brute-force a weak or reused password with no lockout, then perform any
one of those actions as the legitimate account holder, with the audit trail showing the real
user's own id as the actor.

**Customer impact:** Direct path to full account takeover of privileged actions for any
compromised session, regardless of password strength, since there is no limit on attempts.

**Recommended solution (implemented):** Rate-limit `ConfirmPasswordController::store()` on the
same shape as `LoginRequest` — 5 attempts, then a timed lockout — keyed by user id + IP (not
email + IP, since the actor's identity is already established by the live session).

**Regression risks:** Low. The only behavior change is a lockout after 5 consecutive wrong
passwords from the same authenticated user — a correct password at any point before that clears
the counter, so a legitimate user who mistypes their password a few times is unaffected.

**Validation checklist:**
- [x] 5 consecutive wrong passwords, then a 6th attempt (even with the correct password) returns
      a friendly rate-limit message, not a stack trace or generic 500.
- [x] A correct password before the 5-attempt threshold clears the limiter (confirmed via a
      dedicated test, not just absence of a counter-example).
- [x] Full existing test suite (583 tests including the 2 new ones) passes with zero regressions.
- [x] The rate-limit key does not collide across different users sharing an IP (keyed by user id
      first) or the same user across different IPs (both dimensions included, matching
      `LoginRequest`'s own `email|ip` shape adapted to `userId|ip`).

---

## 2. Investigated and disproven — deliberately excluded

Per this report's own no-fabrication standard (matching §4 of the 2026-09-02 assessment): one
serious-looking hypothesis was investigated in depth, reproduced with a real exploit attempt via
a live feature test, and found to already be blocked. Recorded here in full rather than omitted
silently.

### Hypothesis: an ordinary tenant admin can escalate to `access-rights:manage` (and thence to SUPER_ADMIN) via a custom organisation role

**Why this looked plausible:** This session's own new access-rights feature added
`access-rights:read`/`access-rights:manage` to the global `access_permissions` catalogue table.
Separately, Phase 12's pre-existing dynamic-role system lets any user holding `roles:manage`
(which `TAXPAYER_OWNER`/`TAXPAYER_ADMIN` — ordinary tenant business roles — both hold, via the
`ORGANISATION_CONTROL` permission bundle) create a custom organisation role naming *any*
permission code, subject only to `OrganisationAdminService::createOrganisationRole()`'s own
"is this a real code in the `access_permissions` catalogue" check. A second `access-governance:
manage`-holding user in the same organisation (also ordinary — a co-admin, no collusion beyond
two people who already work together) can then approve a maker-checker access request granting
that role to any org member (self-approval is blocked, but a second same-tier colleague is not).
If that chain reached `hasAppPermission()` — which does a flat boolean OR between a user's static
role permissions and `DynamicPermissions::forUser()`'s dynamic grants, with no organisation-scope
distinction applied at that check — the result would be an ordinary taxpayer business's own admin
staff escalating to grant themselves (or a colluding colleague) SUPER_ADMIN, with no
national/platform account ever involved.

**What actually happens:** `OrganisationAdminService::createOrganisationRole()`'s *first* line
calls `App\Domain\OrganisationAdmin\OrganisationAdminValidator::organisationRole()`, which
validates every requested permission against `Permissions::tenantGrantablePermissions()` — a
pre-existing (Phase 12), self-maintaining allowlist computed as the union of every permission
actually held by a role *not* in `NATIONAL_OR_PLATFORM_ONLY_ROLES`. Since `access-rights:manage`/
`access-rights:read` are only ever granted to `SUPER_ADMIN` and `NAMRA_SYSTEM_ADMIN` — both
already members of `NATIONAL_OR_PLATFORM_ONLY_ROLES` — they were automatically excluded from
that allowlist the moment they were added to `Permissions::ROLE_PERMISSIONS`, with no extra step
required. The custom-role creation request fails validation (`PROTECTED_PERMISSION`,
"...is system-controlled and cannot be placed in an organisation role") before an
`organisation_roles` row is ever written — the escalation chain is severed at its very first
step, long before the maker-checker request/approval flow is even reached.

**Verification performed:** A full end-to-end exploit attempt via `tests/Feature/Security/
TenantRoleEscalationTest.php`: two real `TAXPAYER_ADMIN` accounts in the same organisation,
attempting to create a custom role holding `access-rights:manage` through the real
`POST /administration/roles` HTTP route (not a service call bypassing the controller), confirmed
blocked with a session validation error and no `organisation_roles` row created. Extended (via a
`#[DataProvider]`) to `access-rights:read`, `platform:manage`, `platform:read`,
`security:manage`, and `authority-governance:manage` — all six blocked identically. A separate
test confirms neither admin ends up holding `access-rights:manage` via `hasAppPermission()` and
that the `/access-rights` route still 403s for them.

**Kept as a permanent regression test** (not just a one-off finding) precisely because this is
exactly the kind of protection a future change could silently erode — e.g., a role rename or a
future permission accidentally omitted from `NATIONAL_OR_PLATFORM_ONLY_ROLES` while still only
being granted to a national/platform role in practice. 8 tests, `tests/Feature/Security/
TenantRoleEscalationTest.php`.

**One adjacent, non-security observation surfaced during this investigation:** `developer:manage`
*is* present in `tenantGrantablePermissions()` (it's genuinely held by ordinary roles —
`TAXPAYER_ADMIN`, `SELLER_ADMIN`, `DEVELOPER_PARTNER` — for managing an organisation's own API
client/sandbox configuration, not a platform-wide capability). Grepping for where it's actually
enforced (`grep -rn "developer:manage" app/Http/Controllers app/Services`) returns **zero**
matches beyond its own declaration — it is a real permission a tenant role can hold and even
delegate via a custom role, but nothing in the application currently checks for it anywhere. Not
a vulnerability (an unenforced permission grants no actual capability), but worth noting
alongside `docs/MIGRATION_MATRIX.md`'s existing "illustrative only, wired only when a real
consumer needs it" pattern for other not-yet-consumed configuration.

## 3. Positive controls confirmed (not findings — recorded for completeness)

Actively checked and found already correct, recorded so this report is not one-sided:

- **CSRF protection has zero exclusions.** `grep`ing `bootstrap/app.php` and every middleware
  class for a CSRF `except` list returns nothing — every state-changing route in the `web` group
  is protected by Laravel's default `VerifyCsrfToken`.
- **Tenant/organisation scoping is consistently delegated to the service layer, not
  re-implemented ad hoc per controller.** Spot-checked across `DisputeViewController`,
  `QuotationViewController`, `BusinessPartyViewController`, `AuditCaseViewController`, and
  `RiskViewController` (the last of which is deliberately officer-only by design, gated at the
  permission level since no taxpayer-facing role ever holds `risk:read`) — every `show`/`edit`
  action passes the acting user into its service method, which enforces scope and throws a typed,
  404-rendering resource exception on an out-of-scope id, matching the pattern the rest of this
  migration already established.
- **Document upload retains its real MIME allow-list, size bound, and magic-byte content-sniffing
  check** (`DocumentService::matchesDeclaredType`) — confirmed still wired into the newer
  `DocumentViewController`'s `store()` action, not bypassed by the Blade-form path.
- **Self-approval/segregation-of-duties enforcement extends to newer modules correctly**: the
  workflow engine, refund claims, and this session's own new access-rights grant all block an
  actor from approving/deciding/granting against themselves, consistent with the app's
  established convention.

## 4. Summary

| ID | Title | Severity |
|---|---|---|
| RT-006 | No rate limiting on the password-confirmation step-up gate | High — **FIXED** |
| — | Tenant-role escalation to `access-rights:manage`/platform permissions via a custom organisation role | Investigated — **already blocked**, kept as permanent regression coverage |

**Overall assessment:** the systemic controls this migration has built up across every phase
(tenant scoping delegated to services, CSRF, self-approval/SoD, file-upload validation, and —
critically — the pre-existing tenant-grantable-permissions allowlist) held up well against a
genuine, fully-reproduced escalation attempt targeting this session's own newest feature. The one
real finding (RT-006) was a narrow, cheap-to-fix gap in an authentication control that had simply
never been exercised by the 2026-09-02 assessment's own necessarily narrower scope. Both are now
closed — RT-006 with a code fix and dedicated regression tests, and the escalation hypothesis with
permanent regression coverage proving the existing safeguard holds. As with the previous
assessment, this remains a UI/source-available review, not a full penetration test with dedicated
tooling — `docs/LAUNCH_READINESS_BACKLOG.md`'s own item #10 (a broader security review) is
narrowed by this pass, not closed outright: the ~56-view surface is now known to hold up against
its two most likely failure modes (an unthrottled step-up gate, and tenant-to-platform privilege
escalation), but has not had an exhaustive per-module adversarial pass.
