# VAT-MSA Red Team Assessment — Invoice Management & POS Integration

**Date:** 2026-09-13
**Scope:** A focused, black-box UI/API adversarial pass against the newest, previously-unaudited
surface in this application — the Foreign Invoices (E-Tariff) and Local Invoices (private-POS API
+ credential issuance) features built earlier this same session — plus a targeted duplicate-
submission/business-abuse check on this surface, following the user's own request for a much
broader "extreme production stress, abuse & resilience audit" (10-phase brief: business abuse,
UI stress, concurrency, auth/session robustness, input validation, authorization isolation,
performance, user-error resilience, fraud resistance, UX failure discovery). Given real time/
compute constraints in a single session, this pass was explicitly scoped — with the user's
agreement — to the highest-risk, least-tested surface (this session's own two newest features)
rather than attempting a shallow sweep across all ~45 routes, ~13 roles, and 10 phases at once.
See "What this pass did not cover" at the end of this report for the explicit boundary.
**Method:** Real, reproduced exploitation against a live running instance — every finding below was
triggered with actual HTTP requests (`curl` with real session cookies/CSRF tokens, concurrent
background requests to simulate a double-click or network retry) against `php artisan serve` +
real MySQL, never inferred from reading code alone, matching the standard this codebase's own
prior red-team reports (`docs/RED_TEAM_ASSESSMENT_2026-09-02.md`,
`docs/RED_TEAM_ASSESSMENT_2026-09-13.md`) hold themselves to.
**Environment:** This session's own sandboxed dev stack — PHP (`artisan serve`), real
MySQL/MariaDB, full suite run both before and after every change.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the user's explicit request.

---

## 0. How to read this report

Every finding was independently reproduced live before being written up, and every fix was
verified two ways: a live re-reproduction of the exact original exploit steps (proving the
behavior actually changed on a running server, not just in a unit test's mocked world), and a new
permanent regression test. Findings continue this codebase's existing `RT-NNN` numbering
(`RT-001`–`RT-006` already used across the two prior reports) starting at `RT-007`, since these are
the same class of finding (duplicate-submission/idempotency) as `RT-001`–`RT-003`'s own 2026-09-09
predecessor incident (see `docs/MIGRATION_MATRIX.md`'s "Duplicate-submission hardening" section).

## 1. Confirmed findings

### RT-007 — Rapid double-submission on POS API credential issuance creates two live, unrevoked credentials and permanently loses the first one's secret

> **Status: FIXED (2026-09-13).** `App\Services\Integration\PosApiClientService::issue()` now
> takes the same stable per-form-render idempotency key every other write action in this codebase
> uses, and recognises an exact replay via `App\Support\Business\CommandLedger` before minting a
> second credential. Verified: 2 new tests in `tests/Feature/Business/LocalInvoiceViewTest.php`
> plus a live re-reproduction of the original exploit (below), and the full 608-test suite, zero
> regressions.

| | |
|---|---|
| **Severity** | **Critical** |
| **User role** | Any taxpayer role holding `integrations:manage` (e.g. `TAXPAYER_OWNER`, `TAXPAYER_ADMIN`) |
| **Feature** | Local Invoices → "Private POS API credentials" → Issue credential (`POST /invoice-management/local/credentials`) |
| **Preconditions** | An authenticated session with `integrations:manage` on an organisation holding an active SELLER capability — the ordinary, ungated precondition to use this feature at all |

**Reproduction steps:**
1. Log in as `owner@demo-trading.test` and load `/invoice-management/local`.
2. Note the page's own rendered CSRF token and hidden `idempotency_key` value.
3. Fire two `POST /invoice-management/local/credentials` requests **concurrently**, both carrying
   the exact same token/key and `name=Double Click Till` — reproducing a realistic double-click, a
   double-tap on a slow mobile connection, or a browser/proxy's own automatic retry of a POST that
   appeared to time out (all of which resend the identical already-rendered form).
4. Observe the database and the page the user actually sees next.

**Expected behavior:** One user action ("click Issue credential once") should produce exactly one
credential. A recognised resubmission of the same rendered form should be treated as a replay, not
a second, independent command — exactly how every other write form in this application already
behaves (`docs/MIGRATION_MATRIX.md`'s "Duplicate-submission hardening" section, closed in an
earlier session for 9 other controllers).

**Actual behavior (pre-fix):** Two distinct `api_clients` rows were created, both `ACTIVE`, both
named identically ("Double Click Till"), with two different `client_key`/secret pairs. Because the
plaintext secret is (by design, correctly) shown only once, in the session flash of whichever
request's redirect the user's browser actually renders, **only one of the two secrets was ever
visible to the user** — confirmed live: after the double-submit, the rendered page showed the
secret for only the second request's credential; the first request's credential existed in the
database as a fully live, `ACTIVE`, never-revoked row whose secret was gone forever (only its
bcrypt hash exists). Live evidence:

```
$ curl (two concurrent POSTs, same idempotency key) ...
submit 1 status: 302
submit 2 status: 302

$ php artisan tinker
App\Models\ApiClient::where('name','Double Click Till')->count();  // => 2
```

**Frequency:** 100% reproducible on every attempt with concurrent/rapid identical resubmission —
this is not a race that sometimes wins; both requests reliably completed and both committed a row,
since nothing in the original code checked for a prior identical request at all.

**Business impact:** An organisation accumulates orphaned, permanently-live API credentials it has
no record of and cannot identify in its own credentials list (same name, no distinguishing label
beyond the client_key). This is a direct hygiene/security-surface-area problem for a feature whose
entire purpose is controlling exactly which external systems can push certified VAT invoices into
the platform: every accidental double-click permanently widens that surface by one more live,
forgotten credential that only a `client_key`-by-`client_key` audit could ever surface, and normal
users have no reason to think to do that. It also directly matches the exact "duplicate
transactions from repeated submissions" business-abuse pattern this session's own broader
red-team brief calls out by name.

**Customer impact:** Confusion (an admin auditing "what POS systems can push invoices for us"
cannot tell the two "Front counter till" rows apart, or which one is actually configured into
their terminal) and a latent, avoidable security exposure (a live credential nobody has the secret
for is still a live credential — if it were ever guessed, brute-forced, or found through some other
channel, it would authenticate exactly as well as the intended one).

**Likely root-cause hypothesis:** This action was built in the same session as, but *after*, the
existing "Duplicate-submission hardening" pass (`docs/MIGRATION_MATRIX.md`) that added the
`<x-idempotency-key/>` component + `Controller::formIdempotencyKey()` convention to 33 other write
call sites across 9 controllers — the new controller/service simply never had that established
convention applied to it, an omission rather than a deliberate decision (nothing in either
controller's or service's own doc comments claimed this was intentionally left unprotected).

**Recommended solution (implemented):** Apply the exact existing convention: add
`<x-idempotency-key/>` to the "Issue credential" form, thread `Controller::formIdempotencyKey()`
through the controller into `PosApiClientService::issue(...,string $idempotencyKey)`, and use
`CommandLedger::prior()`/`::record()` to recognise a replay. Because the underlying resource (a
one-time secret) genuinely cannot be reconstructed on replay, a recognised replay returns the same
credential's `client_key` with `client_secret` left `null` and a clear session message explaining
the secret was already shown once and cannot be retrieved again — honest behavior rather than
fabricating a second "shown" secret.

**Regression risks:** Low. A genuinely new "Issue credential" click (a fresh page load, which
renders a fresh idempotency key) is unaffected and still creates a new credential normally —
verified by a dedicated regression test asserting two distinct requests without a shared key
produce two distinct credentials.

**Validation checklist:**
- [x] Two concurrent POSTs sharing one rendered form's idempotency key create exactly one
      `api_clients` row (re-verified live post-fix with the identical `curl` reproduction: 1 row,
      not 2).
- [x] The replayed request's response never displays a fabricated or reused plaintext secret.
- [x] Two POSTs from two genuinely separate page loads (two different idempotency keys) still
      create two separate credentials.
- [x] Full existing test suite (608 tests including the 4 new ones from this pass) passes with
      zero regressions.

---

### RT-008 — Foreign Invoices "Pull from E-Tariff" has the same missing-idempotency gap (audit-log duplication today; will double-call a live external system once E-Tariff is real)

> **Status: FIXED (2026-09-13).** `App\Services\Business\ForeignInvoiceService::pullFromEtariff()`
> now takes the same idempotency key and uses `CommandLedger` to recognise an exact replay before
> re-invoking the E-Tariff port or writing a second audit entry. `App\Services\Integration\
> PosApiClientService::revoke()` was hardened the same way for consistency, even though it was
> already naturally idempotent (see "positive control" note below). Verified: 2 new tests in
> `tests/Feature/Business/ForeignInvoiceViewTest.php`, plus the full 608-test suite, zero
> regressions.

| | |
|---|---|
| **Severity** | **Medium** (today) / would be **High** once a real E-Tariff contract exists |
| **User role** | Any role holding `imports:manage` |
| **Feature** | Foreign Invoices → "Pull from E-Tariff" (`POST /invoice-management/foreign/pull`) |
| **Preconditions** | None beyond ordinary access to the feature |

**Reproduction steps:**
1. Log in as a user with `imports:manage`, load `/invoice-management/foreign`.
2. Fire three rapid `POST /invoice-management/foreign/pull` requests carrying the same rendered
   form's idempotency key (a realistic rapid triple-click).
3. Count `FOREIGN_INVOICE_PULL_BLOCKED` audit rows for the organisation before and after.

**Expected behavior:** One user action should produce one outcome and one audit entry — matching
this codebase's own established convention for every other write action.

**Actual behavior (pre-fix):** Live reproduction: audit count went from 1 to 4 (three new rows)
after three rapid clicks — one row per click, with nothing recognising the repeats as the same
user intent.

**Frequency:** 100% reproducible, same mechanism as RT-007.

**Business impact today:** Low in isolation — the E-Tariff integration is fully stubbed
(`UnavailableEtariffAdapter`, per its own doc comment), so every pull is rejected identically
regardless of how many times it's clicked, and the only observable effect is audit-log noise
(several near-simultaneous `FOREIGN_INVOICE_PULL_BLOCKED` rows for one real user action, making the
audit trail a less reliable count of genuine attempts). **Business impact once E-Tariff is real:**
each accidental double-click would fire a real outbound call to a live NamRA border/customs system
per click — wasted quota against a real government integration's own rate limits, and duplicate
entries in *that* system's own audit trail attributable to this platform, for what was really one
user action.

**Likely root-cause hypothesis:** Same as RT-007 — built after the established hardening
convention existed, without it being applied.

**Recommended solution (implemented):** Same `<x-idempotency-key/>` + `CommandLedger` pattern.
Because this command can touch zero-to-many `ImportRecord` rows (not one single created resource),
a recognised replay short-circuits before calling the port at all and returns a
`DUPLICATE_REQUEST_SUPPRESSED` status with a plain "already processed, reload to try again"
message — never silently swallowed, and a genuine new attempt after a fresh page reload (a new
key) still runs normally.

**Regression risks:** Low — same shape as RT-007's own regression risk, verified by the same kind
of "two distinct requests, two distinct outcomes" test.

**Validation checklist:**
- [x] Three rapid POSTs sharing one idempotency key write exactly one audit row (re-verified live
      post-fix: count stayed at its pre-test baseline + 1, not + 3).
- [x] Two POSTs from two separate page loads still each run and each write their own audit row.
- [x] Full suite (608 tests) passes with zero regressions.

## 2. Positive controls confirmed (not findings — recorded for completeness, per this report's own no-fabrication standard)

Actively checked and found already correct on this same new surface:

- **Revocation is already naturally idempotent.** Double-clicking "Revoke" on the same credential
  cannot create inconsistent state: `PosApiClientService::revoke()`'s own `if ($client->status ===
  'REVOKED') { return; }` guard already made a second revoke attempt a safe no-op before this
  pass — hardened with the same `CommandLedger` mechanism regardless, for consistency with every
  other write action, but this was not itself an exploitable gap.
- **Stored credential names are correctly HTML-escaped.** A credential name containing
  `<script>alert(1)</script>` plus emoji/Unicode (`🚀<script>alert(1)</script> Till Ñoño`) was
  accepted (valid, if unusual, input) and rendered back on the Local Invoices page as literal
  escaped text (`&lt;script&gt;`), not executed — Blade's default `{{ }}` escaping holds on this
  new surface, confirmed live rather than assumed.
- **Extreme-length and empty input are correctly rejected.** An empty credential name and a
  500-character one were both rejected with a friendly validation error and no database row
  created (confirmed via direct DB check, not just the redirect status) — the existing 1–100
  character bound in `PosApiClientService::issue()` holds.
- **The RT-001 (2026-09-02) bfcache/no-store fix extends correctly to both new routes.** Both
  `/invoice-management/local` and `/invoice-management/foreign` return `Cache-Control: no-store,
  no-cache, must-revalidate, private` — confirmed via direct header inspection, not inferred from
  route-group membership alone.
- **Post-logout direct URL access is correctly denied.** A bookmarked/direct `GET
  /invoice-management/local` after logout redirects to `/login`, not to the page's content — no
  session-fixation or logout-bypass gap on this new surface.
- **The external POS ingestion API's credential boundary holds under all four negative cases**
  (no header, unknown key, wrong secret, revoked credential) — already covered by
  `tests/Feature/Integration/PosInvoiceApiTest.php` from this feature's original build, re-
  confirmed still passing after this pass's changes.

## 3. Summary

| ID | Title | Severity |
|---|---|---|
| RT-007 | Duplicate POS API credential creation via double-submit; first credential's secret permanently lost | Critical — **FIXED** |
| RT-008 | Missing idempotency protection on the Foreign Invoices E-Tariff pull action | Medium (High once E-Tariff is real) — **FIXED** |

**Overall assessment:** the one systemic gap on this newest surface was exactly the same class of
issue this codebase had already found and fixed once before (2026-09-09) on 9 other controllers —
the new Foreign/Local Invoices write actions simply post-dated that hardening pass and were never
brought into line with it. Both are now closed using the identical established mechanism
(`CommandLedger` + `<x-idempotency-key/>`), not a bespoke one-off fix, so the same regression-
prevention story applies. Everything else actively checked on this surface (XSS escaping, input
bounds, bfcache/session hygiene, direct-URL authorization, the external API's credential boundary)
held up correctly.

## 4. Engineering prompts

Both findings were fixed within this same pass (per this codebase's own established convention of
closing a red-team finding same-day, not filing it for later — see `docs/RED_TEAM_ASSESSMENT_2026-
09-13.md`'s RT-006 for precedent). The standalone prompts below record exactly what was
implemented, in the format an implementation agent would need, for reference and as a durable
definition-of-done record.

### Prompt: RT-007 — Fix duplicate POS API credential creation on double-submit

**Objective:** Make `PosApiClientService::issue()` idempotent per rendered form submission, so a
double-click, network retry, or back-button resubmission of the "Issue credential" form on
`/invoice-management/local` cannot create two live credentials from one user action.

**Affected modules:**
- `app/Services/Integration/PosApiClientService.php` (`issue()`)
- `app/Http/Controllers/Business/LocalInvoiceViewController.php` (`storeCredential()`)
- `resources/views/invoice-management/local.blade.php` (the "Issue credential" form)

**Observable problem:** Two concurrent/rapid identical POSTs to
`/invoice-management/local/credentials` create two distinct `ACTIVE` `api_clients` rows with two
different `client_key`/secret pairs. Only one secret is ever shown to the user (whichever
request's session flash write wins); the other credential is permanently live and unrecoverable.

**Desired behavior:** A resubmission carrying the same idempotency key as a request already
processed for this actor must not create a second credential. It should return information about
the *existing* credential (its `client_key`) without fabricating or re-displaying a secret, since
the real secret is genuinely unrecoverable once already shown. A resubmission with a *different*
key (a genuine new attempt after a fresh page load) must behave normally.

**Implementation expectations:**
- Add a hidden, per-page-render idempotency key to the form, using this codebase's own existing
  `<x-idempotency-key/>` Blade component (already used by 33+ other write forms).
- Thread it through the controller via the existing `Controller::formIdempotencyKey($request)`
  helper into a new `string $idempotencyKey` parameter on `issue()`.
- Use the existing `App\Support\Business\CommandLedger` helper (`validateIdempotencyKey`,
  `requestHash`, `prior`, `record`) exactly as every other hardened write action in this codebase
  already does — do not invent a parallel idempotency mechanism.
- On a recognised replay, look up the previously-created `ApiClient` by the resource id
  `CommandLedger::prior()` returns and return its `client_key` with `client_secret` set to `null`
  and a `replayed: true` flag; the controller must show a clear, honest message on replay ("this
  was already issued; the secret cannot be shown again — revoke and reissue if lost"), never a
  fabricated secret.
- Catch `RepositoryConflictException` (thrown by `CommandLedger::prior()` if the same key was
  somehow reused with a different payload) in the controller with a friendly `withErrors()`
  redirect, matching this codebase's own established exception-handling convention elsewhere.

**Validation requirements:**
- Two concurrent POSTs with the same idempotency key → exactly one `api_clients` row.
- The replayed response never contains a plaintext secret that wasn't the original one.
- Two POSTs with two different idempotency keys (simulating two real separate clicks after two
  page loads) → two distinct credentials.
- Existing seller-capability and name-length validation still reject invalid input before any
  idempotency check short-circuits it incorrectly.

**Regression tests:** `tests/Feature/Business/LocalInvoiceViewTest.php`:
`test_double_submitting_the_same_rendered_issue_credential_form_creates_only_one_credential`,
`test_a_genuinely_new_page_load_between_two_issue_requests_is_not_treated_as_a_replay`. Also
update `tests/Feature/Integration/PosInvoiceApiTest.php`'s direct service-call helper to pass a
real idempotency key now that the method signature requires one.

**Acceptance criteria:** Both new tests pass; the full existing suite passes with zero
regressions; a live `curl`-based double-submit reproduction (two concurrent POSTs, same rendered
form) creates exactly one `api_clients` row, confirmed via direct database query, not just HTTP
status codes.

**Definition of done:** Fix merged, both regression tests present and passing, full suite green,
live reproduction re-run post-fix and confirmed fixed, finding documented as FIXED in the red-team
report with before/after evidence.

---

### Prompt: RT-008 — Fix missing idempotency protection on the Foreign Invoices E-Tariff pull action

**Objective:** Make `ForeignInvoiceService::pullFromEtariff()` idempotent per rendered form
submission, matching RT-007's fix and this codebase's own established convention, so a double-
click on "Pull from E-Tariff" cannot write duplicate audit entries today, and cannot fire a
duplicate outbound call to a real E-Tariff system once one exists.

**Affected modules:**
- `app/Services/Business/ForeignInvoiceService.php` (`pullFromEtariff()`)
- `app/Http/Controllers/Business/ForeignInvoiceViewController.php` (`pull()`)
- `resources/views/invoice-management/foreign.blade.php` (the "Pull from E-Tariff" form)

**Observable problem:** Three rapid POSTs to `/invoice-management/foreign/pull` with the same
rendered form each independently call `EtariffPort::pullDeclarations()` and each write their own
`FOREIGN_INVOICE_PULL_BLOCKED` (or, once real, `FOREIGN_INVOICE_PULLED`) audit entry, live-
reproduced as a 1→4 audit-row jump after three clicks.

**Desired behavior:** A resubmission carrying an idempotency key already used for this actor must
not re-invoke the port or write a second audit entry; it should return a
`DUPLICATE_REQUEST_SUPPRESSED` outcome with a plain message telling the user to reload the page to
try again. A resubmission with a genuinely new key (fresh page load) must run normally — including,
once E-Tariff is real, actually re-checking availability rather than being silently suppressed
forever.

**Implementation expectations:**
- Same `<x-idempotency-key/>` + `Controller::formIdempotencyKey()` + `CommandLedger` pattern as
  RT-007's fix — do not build a separate mechanism.
- Because this command can touch zero-to-many `ImportRecord` rows rather than one single created
  resource, use a stable proxy resource id for the ledger (e.g. the organisation id) rather than
  trying to force a single "created resource" model onto a bulk operation.
- Record the ledger entry on *every* real code path that completes (both the blocked-configuration
  branch and the successful-pull branch), not just one, so a genuine retry after either outcome is
  still correctly recognised as a replay if it reuses the same key.
- Catch `RepositoryConflictException` in the controller the same way as RT-007's fix.

**Validation requirements:**
- Three rapid POSTs with the same idempotency key → exactly one audit entry for that user action.
- Two POSTs with two different idempotency keys → two independent audit entries, each pull
  actually attempted.
- The existing "blocked configuration shows a friendly error, creates no `ImportRecord` rows"
  behavior is unchanged.

**Regression tests:** `tests/Feature/Business/ForeignInvoiceViewTest.php`:
`test_double_submitting_the_same_rendered_pull_form_writes_only_one_audit_entry`,
`test_a_fresh_pull_request_after_a_new_page_load_is_not_treated_as_a_replay`.

**Acceptance criteria:** Both new tests pass; the full existing suite passes with zero
regressions; a live reproduction (three rapid POSTs, same rendered form) writes exactly one audit
row, confirmed via direct database query.

**Definition of done:** Fix merged, both regression tests present and passing, full suite green,
live reproduction re-run post-fix and confirmed fixed, finding documented as FIXED in the red-team
report with before/after evidence.

## 5. What this pass did not cover

Per the explicit scoping agreed with the user before this pass began (given real single-session
time/compute constraints against a 10-phase, all-routes/all-roles brief):

- **Not an exhaustive walk of all ~45 routes × ~13 roles × 10 phases.** This pass targeted the
  two newest, never-before-audited features plus the specific duplicate-submission abuse class
  this codebase has a known history of (RT-001–RT-003, 2026-09-09). Older, already-shipped
  modules were not re-tested here (see `docs/RED_TEAM_ASSESSMENT_2026-09-02.md` and
  `docs/RED_TEAM_ASSESSMENT_2026-09-13.md` for what those passes covered).
- **No true concurrent-user simulation beyond a handful of parallel requests/browser contexts.**
  Multi-tab, multi-device, and genuinely simultaneous multi-user scenarios (Phase 3 of the user's
  brief) were not exercised at scale.
- **No load/performance testing at any realistic scale** (Phase 7) — this environment is a
  single-worker dev server, not comparable to a production deployment.
- **No systematic fraud-pattern testing** (Phase 9 — multiple accounts, referral/coupon abuse) —
  this application has no referral/coupon/reward mechanism to abuse, and multi-account creation
  abuse was not separately tested in this pass.
- **No dedicated UX-only walkthrough** (Phase 10) beyond what surfaced incidentally while
  reproducing the two findings above.

If a genuinely exhaustive pass across the rest of the brief is wanted, it is recommended as its own
scoped, multi-session effort — the user has already agreed this narrower first pass was the right
starting point given real constraints, and can direct which area (which module, which role, which
phase) to go deep on next.
