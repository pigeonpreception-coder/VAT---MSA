# VPS deployment for vat.safi-nuru.com

Concrete, version-controlled config for the VPS setup docs/DEPLOYMENT.md
describes at a narrative level: Nginx + PHP-FPM 8.3 + MySQL 8, TLS via
Let's Encrypt/certbot. Everything here is written for a fresh Ubuntu
22.04/24.04 box and the domain `vat.safi-nuru.com` specifically -- adjust
`DOMAIN`/`APP_DIR`/`APP_USER` at the top of the two scripts (and the
`server_name`/socket path in the nginx config, and the pool name in the
php-fpm config) if any of those change. PHP_VERSION is pinned to 8.3 to
match the actual production host; if it's ever upgraded, update
`PHP_VERSION` in both scripts and the hardcoded `/etc/php/8.3/...` paths
in the two file headers below to match.

## Files

| File | Purpose |
|---|---|
| `provision.sh` | One-time: installs PHP 8.3-FPM, MySQL 8, nginx, Composer, Node 20, certbot; creates the `vatmsa` system user and MySQL database/user; wires the config files below into place. Run once, as root, on a fresh box. |
| `deploy.sh` | Every release after that: `git pull` + `composer install` + `npm run build` + `migrate --force` + cache + PHP-FPM reload. Run as the `vatmsa` user. |
| `nginx/vat-msa.conf` | The site's nginx server block -- HTTP only until certbot edits it in place to add TLS. |
| `php-fpm/vat-msa-pool.conf` | A dedicated PHP-FPM pool (not the stock `www` pool) running as `vatmsa`, socket-based, shared with nginx via the `www-data` group. |
| `php-fpm/99-vat-msa.ini` | OPcache settings (RT-004, see `docs/DEPLOYMENT.md`) and upload-size limits matching `DocumentService`'s own 10 MiB bound. |

## First deploy, start to finish

**The production host (a Hostinger VPS with root/SSH access) is already
live** -- PHP, MySQL and the application are already running there (see
the `symfony/filesystem` PHP-8.3 compatibility fix in commit `2615658`,
verified directly against that host with `scripts/smoke-test.php`). Do
**not** run `provision.sh` against it: it assumes a from-scratch box
(removes the stock nginx `default` site and PHP-FPM `www` pool,
(re)creates the `vatmsa` system user and MySQL database, etc.), and
re-running those steps against an already-configured, already-serving
host risks disrupting it. `provision.sh` stays here as a reference for
provisioning a *new* box (e.g. if this app is ever redeployed elsewhere,
or the current VPS is rebuilt from scratch) and for cross-checking that
the live host's nginx/php-fpm setup matches what's version-controlled
here. Only `deploy.sh`, below, is meant to run against the current
production host.

```bash
# On a FRESH box only, as root:
export REPO_URL=<this repo's git URL>
bash provision.sh
# Read its final output carefully -- it prints the generated MySQL
# password once and ends with the manual .env/migrate/seed/certbot steps
# it deliberately does NOT automate.
```

Every release against the live host:

```bash
su - vatmsa
cd /var/www/vat-msa/php-app
./deploy/deploy.sh
```

## Why some things are deliberately not here

- **No queue worker service.** Nothing in this codebase enqueues a
  `ShouldQueue` job yet (`outbox_events` rows are written by every
  command, but nothing drains them -- see docs/DEPLOYMENT.md's own
  "What changed vs. the original stack" table). Add a
  `queue:work`-running systemd unit only once a real consumer exists;
  running one against an empty queue forever is exactly the kind of
  built-ahead-of-need code this project avoids elsewhere.
- **No cron/scheduler entry.** No `Schedule::command(...)` exists in
  `routes/console.php` or anywhere else in this codebase (checked before
  writing this) -- there is nothing for `php artisan schedule:run` to
  run yet.
- **No `.env` in this directory.** Secrets don't belong in a script or a
  git-tracked directory, even a private repo. `provision.sh` stops right
  before that step and prints exactly what to fill in, referencing
  docs/DEPLOYMENT.md's "Environment variables" section for the rest.
