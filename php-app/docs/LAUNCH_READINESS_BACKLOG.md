# VAT-MSA: Launch Readiness Backlog

Compiled 2026-09-03, refreshed 2026-09-13. `docs/MIGRATION_MATRIX.md`
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

**Evidence**: `find resources/views -name "*.blade.php" | wc -l` = 56 (up
from 14); `grep -c "Route::" routes/web.php` = 308. `docs/MIGRATION_MATRIX.md`'s
own "Next steps" section independently confirms: "every phase this
migration originally scoped (1 through 15) is now COMPLETE for its own
actual scope."

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
**Status: Buildable now, substantial scope (real secret provisioning, QR enrollment, backup codes).**

Sensitive actions currently use Laravel's `password.confirm`
re-authentication as a stand-in for the original's real server-verified
TOTP. The `step_up_events`/`mfa_totp_credentials` tables exist,
schema-only -- nothing reads or writes them yet.

**Evidence**: `docs/MIGRATION_MATRIX.md`'s Phase 6 note;
`App\Support\Access\StepUp`'s own doc comment.

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
**Status: NARROWED, not closed (2026-09-13).** See
`docs/RED_TEAM_ASSESSMENT_2026-09-13.md` -- a systemic, cross-cutting
pass (rate limiting, CSRF, mass assignment, IDOR/tenant scoping,
self-approval/SoD, `password.confirm` coverage, file-upload validation,
and the dynamic-permission/custom-role privilege boundary) plus a
targeted adversarial attempt against this session's own newest,
highest-privilege feature (the access-rights screen). Found and fixed
one genuine gap (RT-006: no rate limiting on the password-confirmation
step-up gate, the endpoint standing directly in front of every
privileged action in the app) and fully reproduced-then-disproved one
serious-looking privilege-escalation hypothesis (an ordinary tenant
admin escalating to `access-rights:manage`/SUPER_ADMIN via a custom
organisation role -- already blocked by a pre-existing allowlist,
`Permissions::tenantGrantablePermissions()`), kept as permanent
regression coverage. This was not an exhaustive per-module adversarial
pass across all 56 views -- it targeted the failure modes most likely to
matter (an unthrottled auth control, tenant-to-platform escalation) --
so further module-by-module testing remains buildable-now, no external
dependency, if deeper assurance is wanted.

**Evidence**: `docs/RED_TEAM_ASSESSMENT_2026-09-13.md`; `tests/Feature/
Auth/ConfirmPasswordTest.php`'s 2 new rate-limit tests; `tests/Feature/
Security/TenantRoleEscalationTest.php`'s 8 tests.

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

## Recommended next step (refreshed 2026-09-13)

The frontend UI build-out (#2) that was the previous recommendation is
now closed. With ITAS (#1) still the one genuine launch-blocker and
still blocked on external NamRA credentials/API access no amount of
further engineering here can obtain, and #3/#4/#6/#7/#11 all similarly
blocked on external credentials or production host access, **the two
remaining buildable-now items with no external dependency are #8 (TOTP
step-up parity) and #10 (a broader security review of the now much
larger UI surface)**. Between the two, #10 is the more urgent: every
module this migration has built since the original 3-module red-team
pass has shipped with feature-test coverage but zero adversarial
testing, and that gap has only grown as more UI shipped. #8 is a larger,
more self-contained scope (real secret provisioning, QR enrollment,
backup codes) that can be picked up independently whenever there's
appetite for it.

Everything else genuinely needs something only NamRA/the deploying
organisation can supply -- real ITAS credentials, a mail provider, S3/R2
bucket credentials, production host access for OPcache/load-testing
verification, and a real legacy dataset -- and cannot be closed by
further code changes alone.
