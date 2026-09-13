#!/usr/bin/env bash
#
# Per-release deploy for vat.safi-nuru.com, once provision.sh has already
# set the VPS up. Run as the vatmsa system user (not root) from
# /var/www/vat-msa/php-app:
#
#   su - vatmsa
#   cd /var/www/vat-msa/php-app
#   ./deploy/deploy.sh
#
# This is exactly docs/DEPLOYMENT.md's "Releasing a new version" section,
# scripted -- read that section for why each step exists (the OPcache/
# php-fpm-reload dependency in particular: this script's php-fpm reload
# is why deploy/php-fpm/99-vat-msa.ini uses opcache.validate_timestamps=0,
# not a slower revalidate-on-every-request mode).

set -euo pipefail

PHP_VERSION="8.4"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${APP_DIR}"

if [[ "$(id -un)" == "root" ]]; then
    echo "Run this as the vatmsa user, not root (composer/npm should not run as root)." >&2
    exit 1
fi

echo "==> Pulling latest code"
git fetch origin
git reset --hard origin/main

echo "==> Clearing caches from the previous release first"
# A stale config:cache in particular silently ignores .env changes until
# cleared -- see docs/DEPLOYMENT.md's "Releasing a new version" section.
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo "==> Installing dependencies"
composer install --no-dev --optimize-autoloader
npm ci
npm run build

echo "==> Migrating (never migrate:fresh against real data)"
php artisan migrate --force

echo "==> Re-caching config/routes/views for this release"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Resetting PHP-FPM so OPcache picks up the new code immediately"
sudo systemctl reload "php${PHP_VERSION}-fpm"

echo "==> Done. Verify with:"
echo "    SMOKE_TEST_PASSWORD=<password> php scripts/smoke-test.php https://vat.safi-nuru.com <email>"
