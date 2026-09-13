#!/usr/bin/env bash
#
# One-time provisioning for a fresh Ubuntu 22.04/24.04 VPS: PHP 8.4-FPM,
# MySQL 8, nginx, Composer, Node 20, certbot -- everything docs/DEPLOYMENT.md
# lists under "Requirements", installed and wired together for
# vat.safi-nuru.com specifically. Debian instead of Ubuntu: swap the
# `add-apt-repository ppa:ondrej/php` step for Sury's own repo
# (https://deb.sury.org/#debian-instructions) -- the package names below
# are identical either way.
#
# Run as root (or with sudo) on the target VPS itself -- this is not meant
# to run anywhere else, and does nothing remote. Safe to re-run: each step
# either checks first or uses an idempotent apt/systemctl command, EXCEPT
# the MySQL app-user creation, which is skipped automatically if that user
# already exists (see step 5) rather than resetting its password.
#
# What this script does NOT do, on purpose:
#   - Write your .env for you (secrets belong in your hands, not a script
#     you might commit) -- it stops right before that step with exact
#     instructions.
#   - Seed DemoSeeder (docs/DEPLOYMENT.md: demo data is for staging, not
#     a real deployment) -- seeds only the individually-listed seeders.
#   - Set up a queue worker or cron scheduler -- neither is needed yet
#     (no ShouldQueue job or Schedule::command exists anywhere in this
#     codebase as of this writing; add one only once a real feature needs
#     it, per this repo's own "don't build ahead of need" convention).

set -euo pipefail

DOMAIN="vat.safi-nuru.com"
APP_USER="vatmsa"
APP_DIR="/var/www/vat-msa"
PHP_VERSION="8.4"
DB_NAME="vat_msa"
DB_USER="vatmsa"
REPO_URL="${REPO_URL:-}"   # export REPO_URL=https://github.com/<owner>/<repo>.git before running, or edit here

if [[ $EUID -ne 0 ]]; then
    echo "Run this as root (sudo bash provision.sh)." >&2
    exit 1
fi

echo "==> Base packages"
apt-get update -y
apt-get install -y software-properties-common curl gnupg2 lsb-release unzip git ca-certificates

echo "==> PHP ${PHP_VERSION} (Ondrej Sury's PPA -- Ubuntu's own repos don't carry 8.4 yet)"
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-common" \
    "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
    "php${PHP_VERSION}-opcache" "php${PHP_VERSION}-sqlite3"
# php8.4-sqlite3 is only needed if the Phase 14 legacy importer
# (php artisan legacy:import-d1) will ever run on this host -- see
# docs/DEPLOYMENT.md's "Legacy data cutover" section. Harmless if unused.

echo "==> MySQL 8"
apt-get install -y mysql-server
systemctl enable --now mysql

echo "==> nginx"
apt-get install -y nginx

echo "==> Composer 2"
if ! command -v composer >/dev/null 2>&1; then
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    HASH="$(curl -fsSL https://composer.github.io/installer.sig)"
    php -r "if (hash_file('sha384', 'composer-setup.php') !== '${HASH}') { unlink('composer-setup.php'); exit(1); }"
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f composer-setup.php
fi

echo "==> Node 20 LTS (for the Vite/Bootstrap asset build -- npm run build)"
if ! command -v node >/dev/null 2>&1; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

echo "==> certbot"
apt-get install -y certbot python3-certbot-nginx

echo "==> System user '${APP_USER}' (owns the checkout; php-fpm pool runs as this user too)"
if ! id -u "${APP_USER}" >/dev/null 2>&1; then
    useradd --system --create-home --shell /bin/bash "${APP_USER}"
fi

echo "==> Scoped sudo rule for deploy.sh's php-fpm reload"
# deploy.sh runs as ${APP_USER}, not root, but it does need to reload
# php-fpm after each release (see docs/DEPLOYMENT.md's OPcache section for
# why). Grant exactly that one command, nothing broader.
cat > "/etc/sudoers.d/${APP_USER}-php-fpm" <<EOF
${APP_USER} ALL=(root) NOPASSWD: /bin/systemctl reload php${PHP_VERSION}-fpm, /bin/systemctl restart php${PHP_VERSION}-fpm
EOF
chmod 0440 "/etc/sudoers.d/${APP_USER}-php-fpm"
visudo -cf "/etc/sudoers.d/${APP_USER}-php-fpm"

echo "==> MySQL database + least-privilege app user"
if [[ -z "$(mysql -N -e "SELECT User FROM mysql.user WHERE User='${DB_USER}'" 2>/dev/null)" ]]; then
    DB_PASSWORD="$(openssl rand -base64 24)"
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
    echo
    echo "############################################################"
    echo "# Generated MySQL password for '${DB_USER}'@'localhost' -- save this"
    echo "# now, it is not stored anywhere and will not be shown again:"
    echo "#   ${DB_PASSWORD}"
    echo "############################################################"
    echo
else
    echo "MySQL user '${DB_USER}' already exists -- not touching its password. Skipping."
fi

echo "==> Cloning the application"
if [[ ! -d "${APP_DIR}/.git" ]]; then
    if [[ -z "${REPO_URL}" ]]; then
        echo "REPO_URL is not set and ${APP_DIR} doesn't exist yet -- export REPO_URL=<git url> and re-run." >&2
        exit 1
    fi
    sudo -u "${APP_USER}" git clone "${REPO_URL}" "${APP_DIR}"
else
    echo "${APP_DIR} already checked out -- leaving it as-is (use deploy.sh for updates)."
fi

echo "==> PHP-FPM pool + ini overrides"
# Remove the stock pool so it doesn't also bind a socket/port and conflict.
rm -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
cp "${APP_DIR}/php-app/deploy/php-fpm/vat-msa-pool.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/vat-msa.conf"
cp "${APP_DIR}/php-app/deploy/php-fpm/99-vat-msa.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/99-vat-msa.ini"
mkdir -p /var/log/php-fpm
chown "${APP_USER}:${APP_USER}" /var/log/php-fpm
systemctl restart "php${PHP_VERSION}-fpm"

echo "==> nginx site"
cp "${APP_DIR}/php-app/deploy/nginx/vat-msa.conf" /etc/nginx/sites-available/vat-msa
ln -sf /etc/nginx/sites-available/vat-msa /etc/nginx/sites-enabled/vat-msa
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

cat <<EOF

==============================================================================
Base provisioning is done. Remaining steps -- deliberately manual, they touch
real secrets and real data:

1. As ${APP_USER}, set up .env (docs/DEPLOYMENT.md's "Environment variables"
   section has the full reference):
     su - ${APP_USER}
     cd ${APP_DIR}/php-app
     cp .env.example .env
     php artisan key:generate
     # Edit .env: APP_ENV=production, APP_DEBUG=false, APP_URL=https://${DOMAIN},
     # DB_DATABASE=${DB_NAME}, DB_USERNAME=${DB_USER}, DB_PASSWORD=<the generated password above>

2. Install dependencies and build assets, then migrate + seed (never
   migrate:fresh -- see docs/DEPLOYMENT.md's "Database" section):
     composer install --no-dev --optimize-autoloader
     npm install && npm run build
     php artisan migrate --force
     php artisan db:seed --class=RoleSeeder
     php artisan db:seed --class=PermissionSeeder
     php artisan db:seed --class=IdentityProviderSeeder
     php artisan db:seed --class=VatRuleSeeder
     php artisan db:seed --class=TaxRuleSetSeeder
     php artisan db:seed --class=LicensePlanSeeder
     php artisan db:seed --class=OrganisationAdministratorRoleSeeder
     php artisan db:seed --class=NavigationSeeder
     php artisan config:cache && php artisan route:cache && php artisan view:cache

3. Fix storage/bootstrap ownership (composer install as ${APP_USER} already
   gets this right if step 1-2 ran as that user; only needed if anything
   above ran as root by mistake):
     chown -R ${APP_USER}:${APP_USER} ${APP_DIR}/php-app/storage ${APP_DIR}/php-app/bootstrap/cache

4. TLS -- run this as root, once DNS for ${DOMAIN} is confirmed pointed here:
     certbot --nginx -d ${DOMAIN}

5. Verify: SMOKE_TEST_PASSWORD=<real-password> php scripts/smoke-test.php https://${DOMAIN} <real-email>
   (see docs/DEPLOYMENT.md's "Post-deploy smoke test" section)

For every release after this first one, use deploy.sh instead of repeating
the above.
==============================================================================
EOF
