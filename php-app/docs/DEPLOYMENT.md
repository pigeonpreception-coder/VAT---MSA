# VAT-MSA: Deployment & Operations Runbook

Phase 15 of `docs/MIGRATION_MATRIX.md`'s tracked migration -- the last
piece of that migration's own scope. This is an ops runbook for the
Laravel/PHP/MySQL application in `php-app/`, not architecture narrative;
see `docs/MIGRATION_MATRIX.md` for what has been ported, what hasn't,
and why each design decision was made the way it was. Every claim below
is either a fact checked against this repository's own code/config, or
explicitly marked as a decision this runbook is making, not one the
original TypeScript/Cloudflare source made for us.

## What changed vs. the original stack

The original application (kept intact at the repo root, one level above
`php-app/`, for side-by-side reference) ran on Cloudflare Workers, with
D1 (SQLite) as its database, R2 for object storage, and a
platform-header trust model for authentication. None of that
Cloudflare-specific infrastructure exists in this port -- by design, not
oversight (see `docs/MIGRATION_MATRIX.md`'s "Cloudflare/D1/R2/Vinext
dependencies remaining" section, which confirms zero such dependency
exists anywhere in `php-app/`). The substitutions:

| Concern | Original (Cloudflare) | This port (Laravel/PHP) |
|---|---|---|
| Compute | Workers (edge functions) | Any standard PHP-FPM/Apache/Nginx host, or a container |
| Database | D1 (SQLite, edge-replicated) | MySQL 8 / MariaDB 10.4+ (see "Database" below for the version caveat actually tested) |
| Object storage (documents, exports) | R2 bucket (`env.DOCUMENTS`) | Laravel's `local` filesystem disk (`storage/app/private`); a real S3/R2-compatible driver is a documented follow-up (swap the disk config, no key-shape change needed -- object keys already match the source's own `{prefix}/{organisation_id}/{id}/{file}` shape) |
| Authentication | Platform-header trust (`lib/auth.ts`) | Real Laravel session auth -- login form, bcrypt password hashing, CSRF, rate-limited attempts, session regeneration (see Phase 6 in `docs/MIGRATION_MATRIX.md`) |
| Step-up re-authentication | `lib/security/step-up.ts` (server-verified TOTP) | Laravel's own `password.confirm` re-authentication flow (route middleware for unconditional step-up commands; `App\Support\Access\StepUp` for the two data-conditional ones -- report exports). Full TOTP parity (`step_up_events`/`mfa_totp_credentials` tables exist, schema-only) is a documented follow-up, not silently dropped. |
| Identity federation | N/A (platform-header only) | `identity_providers`/`identity_links` tables exist and are seeded, but local Laravel auth (`users.password`) works fully independently of them -- no federated login flow is wired up yet |
| Background jobs / async work | None found in the source (verified by a full-repo grep before this migration started) | None wired up here either -- `outbox_events` rows are written by every command (matching the source's own durable-event-log pattern) but nothing drains them; if a real downstream consumer is ever needed, Laravel's queue system (already configured, unused) is the natural place to add it |

## Requirements

- **PHP 8.2 or newer** (`composer.json` pins `"php": "^8.2"`). This
  migration's own verification in this session ran on PHP 8.2.12 --
  flagged explicitly in `docs/MIGRATION_MATRIX.md` since it differs from
  a PHP 8.3+ target; code deliberately avoids anything that needs 8.3+,
  but re-verify against the actual target runtime before production use.
- **MySQL 8+ or MariaDB 10.4+**. This session's own verification used
  MariaDB 10.4.32 (XAMPP), not MySQL 8 -- the same caveat as above
  applies; code avoids MySQL-8-only syntax, but re-verify against the
  real target.
- **Composer 2** and **Node.js** (for the Vite/Bootstrap 5 asset build
  -- `npm run build`; there is no separate frontend framework, this is
  server-rendered Blade with a small asset bundle).
- Required PHP extensions: the standard Laravel set (`pdo_mysql`,
  `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`,
  `fileinfo`) plus `pdo_sqlite` **only** if the Phase 14 legacy importer
  (`php artisan legacy:import-d1`, see below) will ever be run on this
  host -- nothing else in the application touches SQLite.

## First-time setup

```bash
cd php-app
composer install --no-dev --optimize-autoloader   # drop --no-dev for a dev environment
npm install && npm run build
cp .env.example .env
php artisan key:generate
```

Edit `.env` (see "Environment variables" below), then:

```bash
php artisan migrate            # never migrate:fresh against real data -- see "Database" below
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=IdentityProviderSeeder
php artisan db:seed --class=VatRuleSeeder
php artisan db:seed --class=TaxRuleSetSeeder
php artisan db:seed --class=LicensePlanSeeder
php artisan db:seed --class=OrganisationAdministratorRoleSeeder
php artisan db:seed --class=NavigationSeeder
# DemoSeeder is demo/pilot data only -- do not run it against a real deployment.
```

(`php artisan db:seed` with no `--class` runs `DatabaseSeeder`, which
in this codebase chains all of the above **including** `DemoSeeder` --
appropriate for a demo/staging environment, not for a real production
database. Run the seeders above individually, in that order, for a real
deployment.)

## Environment variables

Grouped by concern; unlisted keys keep Laravel's own framework default
and this application does not read them directly.

| Variable | Purpose | This port's setting |
|---|---|---|
| `APP_KEY` | Encryption/session-signing key | Generate per environment with `php artisan key:generate` -- never share across environments or commit a real value |
| `APP_ENV` | `local` / `staging` / `production` | Set `production` for a real deployment; disables debug-mode error pages |
| `APP_DEBUG` | Verbose error output | `false` in any environment reachable by anyone but a developer |
| `APP_URL` | Base URL used in generated links | The real public hostname |
| `DB_CONNECTION` | Query builder driver | `mysql` (the only driver the application itself uses -- `sqlite` is configured dynamically, in-process, only by the Phase 14 importer, and only for the export file it's pointed at, never for the application's own data) |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | MySQL/MariaDB connection | Real credentials scoped to this application's own database, least-privilege |
| `SESSION_DRIVER` | Where sessions live | `database` (the `sessions` table already exists via the identity-core migration) |
| `SESSION_LIFETIME` | Minutes of inactivity before logout | `120` by default; tune per the organisation's own session-timeout policy |
| `SESSION_ENCRYPT` | Encrypt session payloads at rest | Consider `true` for a production deployment |
| `AUTH_PASSWORD_TIMEOUT` | Seconds a step-up (`password.confirm`) confirmation stays fresh | Not in `.env.example` -- Laravel defaults to `10800` (3 hours) via `config/auth.php`; this is the same freshness window `App\Support\Access\StepUp` and every `password.confirm`-gated route use |
| `FILESYSTEM_DISK` | Default disk | `local` -- documents/exports live under `storage/app/private`, never web-served directly (see "Storage" below) |
| `QUEUE_CONNECTION` | Queue driver | `database` by default, currently unused (no `ShouldQueue` job exists anywhere in this codebase yet -- see the table above) |
| `CACHE_STORE` | Cache driver | `database` by default; swap to `redis`/`memcached` under real load, no application code depends on the choice |
| `BCRYPT_ROUNDS` | Password-hashing cost | `12` -- also the cost used for the random, unusable password this migration's `LegacyD1Importer`/`PlatformChangeService::provisionStaff()` generate for federated-identity-only accounts |
| `MAIL_*` | Outbound mail | `log` driver by default (mail is written to the log, never actually sent) -- no part of this migration currently sends real email; wire a real mailer only once a feature needs one |
| `LOG_LEVEL` | Log verbosity | `debug` locally; `error` or `warning` in production |

Variables the original source's own `.env`-equivalent (Cloudflare
`wrangler.json` bindings) had that have **no counterpart here at all**
(by design): any `R2_*`/`D1_*`/Workers-specific binding. There is
nothing to configure for them because the corresponding Cloudflare
service is not part of this stack.

## Database

- **Never run `php artisan migrate:fresh` against a database holding
  real data** -- it drops every table first. Use `php artisan migrate`
  for ordinary deploys; this migration's own test suite is the only
  place `migrate:fresh` is appropriate (a disposable database).
- All 155 tables from the original D1 schema are represented (154
  built with real Eloquent models/services behind them where a command
  actually reads or writes them; `positions` is schema-only-never-built,
  confirmed by a full-repo grep that the source itself never writes to
  it either -- see `docs/MIGRATION_MATRIX.md`'s "Remaining schema
  conversion" section).
- UUID (`CHAR(36)`) primary keys throughout, not auto-increment integers
  -- a deliberate design decision (see `docs/MIGRATION_MATRIX.md`'s
  "Design decisions carried through the whole migration") that keeps a
  real legacy-data cutover (below) a straight row copy.
- Run migrations inside a maintenance window for any release that adds
  a `NOT NULL` column without a default to a table already holding
  rows; check the specific migration file first.

## Legacy data cutover (Phase 14)

`php artisan legacy:import-d1 {path} [--dry-run] [--only=table1,table2]`
imports a real `wrangler d1 export --output=...` SQLite file into this
MySQL schema -- a structural, table-by-table row copy (see
`App\Support\Migration\LegacyD1Importer`'s own doc comment for the full
mechanics: the one documented table/column rename, timestamp
reformatting, `INSERT IGNORE`-based idempotency, and why referential
integrity is intentionally left to the target system's own real read
paths rather than a bespoke verification feature built against data
that cannot be tested here).

**There is no real legacy dataset anywhere in this repository** -- this
tool is verified against a small synthetic fixture built to the same
shape a real export would have (`tests/Feature/Console/
LegacyD1ImportTest.php`), not against real production rows, because
none exist to test against. Before a real cutover:

1. `wrangler d1 export <database-name> --output=legacy-export.sqlite`
   against the actual production D1 database.
2. `php artisan legacy:import-d1 legacy-export.sqlite --dry-run` first,
   and read its report -- particularly the "Skipped columns" column,
   which flags any real column the export has that this schema does
   not expect.
3. Run it for real only against a disposable staging database first,
   then reconcile a handful of real numbers (e.g. total invoice count
   and value for a known taxpayer, via this application's own already-
   verified reports) against the legacy system's own figures before
   trusting the result.
4. Only then run it against the real production target, inside a
   maintenance window, with `FOREIGN_KEY_CHECKS` disabled automatically
   by the tool for the duration of the run.

## Storage

Documents and report exports live under `storage/app/private`
(`FILESYSTEM_DISK=local`), addressed by the same object-key shape the
source's R2 bucket used
(`{quarantine|exports}/{organisation_id}/{id}/{file_name}`). This
directory is:

- **Never** web-served directly -- every read goes through an
  authenticated download endpoint (`DocumentService::download()`,
  `ReportExportService::downloadExport()`), so no `storage:link`
  symlink is needed or should be created.
- The thing to back up. There is no redundancy at the storage-driver
  level the way R2 had; either put it on redundant storage at the
  infrastructure layer, or swap `FILESYSTEM_DISK` to a real S3/
  R2-compatible driver (a config change only -- Laravel's `local` and
  `s3` drivers share the same `Storage::disk(...)->put()/get()/
  exists()/delete()` interface every service in this codebase already
  uses).

## Running the test suite

```bash
php artisan test
```

Runs against the **real MySQL/MariaDB connection configured in
`.env`** via `RefreshDatabase` (every test class in this codebase uses
it) -- **do not** point a test run's `.env`/`phpunit.xml` at a database
holding real data; `RefreshDatabase` truncates between tests. Use a
disposable `DB_DATABASE` (e.g. `vat_msa_test`) for CI and local test
runs. This migration deliberately never ran its own test suite against
SQLite (`:memory:` or otherwise) -- every fidelity check (MariaDB's
strict-mode TIMESTAMP defaults, the 64-character identifier limit,
real `ENUM` validation) only surfaces against the real target engine.

## What is not done yet

`docs/MIGRATION_MATRIX.md` is the authoritative, continuously-updated
record. As of this document: every application-code phase (3 through
13) is COMPLETE for its own actual scope -- every export from the
original source's `lib/data/**` and `lib/api/**` now has a ported PHP
counterpart. Known, documented gaps that are not silently pretended
complete: no password-reset flow (Phase 6), no full TOTP step-up parity
(Phase 6 note above), platform-config values not yet wired to any real
downstream consumer (Phase 13's "Platform config & change-management"
section), and no real object-storage driver configured yet (this
document's "Storage" section). None of these block a pilot/demo
deployment; all are called out here so a real production rollout
doesn't discover them by surprise.
