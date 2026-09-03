# VAT-MSA: Launch Readiness Backlog

Compiled 2026-09-03. `docs/MIGRATION_MATRIX.md` remains the authoritative,
continuously-updated record of what's been built; this document is a
prioritized view specifically answering "what's left before a real
launch," pulling from that record, `docs/DEPLOYMENT.md`'s "What is not
done yet," and `docs/RED_TEAM_ASSESSMENT_2026-09-02.md`. Every item below
was checked against the actual codebase, not inferred from memory alone --
see each item's "Evidence" line.

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
**Status: Buildable now, no external dependency.**

11 Blade view files exist on `main` against 196 registered routes:
Dashboard, Invoices, and VAT Returns/Periods have real screens.
[PR #2](https://github.com/pigeonpreception-coder/VAT---MSA/pull/2)
(open, not yet merged as of this writing) adds a vertical sidebar nav
plus two more full modules -- VAT Returns/Periods' real write actions
(generate/adjust/approve/submit) and Refund claims (request/review/
dispute) -- bringing it to 5 of ~20+ once merged. Everything else is
still JSON-API-only, reachable only by a direct HTTP client, not a
browser: disputes, audit cases, communications, notifications,
licensing & entitlements, organisation administration, the whole
business/accounting/expenses/inventory/projects suite, reports &
analytics, access governance, the workflow engine.

No taxpayer or NamRA officer can use any of those through the actual
application yet -- a "launch" today would only cover invoice
certification and VAT return generation/approval, not the compliance,
refund, audit-case, or commercial-accounting workflows the platform is
meant to provide.

**Evidence**: `find resources/views -name "*.blade.php" | wc -l` = 14;
`grep -c "Route::" routes/web.php` = 196 (near-all under `api/v1`).

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
**Status: Buildable now, once a target host is available to test against.**

Every verification in this migration ran on PHP 8.2.12 and MariaDB
10.4.32 (XAMPP) -- the target is PHP 8.3+ and MySQL 8, flagged
throughout as a substitution, never confirmed. Code was deliberately
written to avoid anything that needs 8.3+ or MySQL-8-only syntax, but
that's a design intent, not a verified fact about the target.

**Evidence**: `docs/DEPLOYMENT.md`'s "Requirements" section;
`docs/MIGRATION_MATRIX.md`'s "Next steps".

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
**Status: Buildable now.** See `docs/MIGRATION_MATRIX.md`'s "Platform
config & change-management" section -- values can be set and change-
managed today, but nothing downstream actually consumes them yet.

### 10. Broader security review of the API-only modules
**Status: Buildable now** (curl/fetch-based, doesn't need a UI). The
red-team assessment this session was explicitly UI-only and scoped to
the 3 modules that have UI (Dashboard, Invoices, VAT Returns). The other
~17+ API-only modules -- disputes, refunds, audit cases, licensing,
access governance, the workflow engine, and the rest -- have had zero
adversarial testing. That's untested surface, not verified-safe surface.

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

## Recommended next step

With ITAS confirmed blocked (2026-09-03) and background-job wiring
corrected off this list, **the highest-value buildable-now item is
continuing the frontend UI build-out (#2)** -- it's the largest concrete
gap with no external dependency, and every module built brings the
system closer to something a real taxpayer or NamRA officer could
actually use end to end.
