#!/bin/bash
set -uo pipefail

# Builds and enables the PHP `bcmath` extension for the Laravel app in
# php-app/. This sandbox's PHP install doesn't bundle bcmath, and the
# apt package (php<major>.<minor>-bcmath) only exists in the ondrej/php
# PPA, which this environment's outbound network policy blocks (403 at
# the egress proxy). bcmath is a bundled PHP extension, not a separate
# PECL package, so it can be built directly from php-src's own source at
# the exact tag matching the running PHP version -- no PPA, no apt.
#
# Without this, `App\Domain\Invoice\InvoiceCalculator::decimalToScaled`
# (php-app/app/Domain/Invoice/InvoiceCalculator.php) silently fails
# every decimal validation on every invoice/quotation payload, which
# cascades into ~29 failing tests across the invoice/quotation/refund/
# VAT-lifecycle suites. See php-app/docs/MIGRATION_MATRIX.md's "Seller
# portal dashboard" section for the full root-cause writeup.
#
# Idempotent and fast when bcmath is already loaded (e.g. a local dev
# machine, or a future base image that bundles it) -- only remote web
# sessions without it pay the one-time build cost.

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

if ! command -v php >/dev/null 2>&1; then
  exit 0
fi

if php -r 'exit(extension_loaded("bcmath") ? 0 : 1);' 2>/dev/null; then
  echo "bcmath already loaded, nothing to do."
  exit 0
fi

for tool in git phpize php-config make cc; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "bcmath build skipped: '$tool' not found." >&2
    exit 0
  fi
done

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
EXT_DIR="$(php -r 'echo ini_get("extension_dir");')"
INI_SCAN_DIR="$(php -r 'echo php_ini_scanned_files() !== false ? dirname(explode(",", php_ini_scanned_files())[0]) : "";' 2>/dev/null)"
if [ -z "$INI_SCAN_DIR" ]; then
  INI_SCAN_DIR="$(php -i 2>/dev/null | sed -n 's/^Scan this dir for additional \.ini files => //p')"
fi

if [ -z "$EXT_DIR" ] || [ ! -d "$EXT_DIR" ] || [ -z "$INI_SCAN_DIR" ] || [ ! -d "$INI_SCAN_DIR" ]; then
  echo "bcmath build skipped: could not resolve extension_dir/ini scan dir." >&2
  exit 0
fi

BUILD_DIR="$(mktemp -d)"
cleanup() { rm -rf "$BUILD_DIR"; }
trap cleanup EXIT

# Try the exact patch tag first (guarantees a matching Zend/Module API);
# fall back to the release branch if that tag isn't pushed yet.
if ! git clone --quiet --no-checkout --depth 1 --branch "php-${PHP_VERSION}" \
    https://github.com/php/php-src.git "$BUILD_DIR/php-src" 2>/dev/null; then
  MAJOR_MINOR="$(echo "$PHP_VERSION" | cut -d. -f1,2)"
  if ! git clone --quiet --no-checkout --depth 1 --branch "PHP-${MAJOR_MINOR}" \
      https://github.com/php/php-src.git "$BUILD_DIR/php-src" 2>/dev/null; then
    echo "bcmath build skipped: could not fetch php-src for PHP ${PHP_VERSION}." >&2
    exit 0
  fi
fi

(
  cd "$BUILD_DIR/php-src" &&
  git sparse-checkout init --cone &&
  git sparse-checkout set ext/bcmath &&
  git checkout --quiet
) >/dev/null || { echo "bcmath build skipped: sparse checkout failed." >&2; exit 0; }

(
  cd "$BUILD_DIR/php-src/ext/bcmath" &&
  phpize >/dev/null &&
  ./configure --enable-bcmath >/dev/null &&
  make -j"$(nproc)" >/dev/null
) || { echo "bcmath build skipped: build failed." >&2; exit 0; }

MODULE="$BUILD_DIR/php-src/ext/bcmath/modules/bcmath.so"
if [ ! -f "$MODULE" ]; then
  echo "bcmath build skipped: bcmath.so not produced." >&2
  exit 0
fi

cp "$MODULE" "$EXT_DIR/bcmath.so"
echo "extension=bcmath.so" > "$INI_SCAN_DIR/20-bcmath.ini"

if php -r 'exit(extension_loaded("bcmath") ? 0 : 1);' 2>/dev/null; then
  echo "bcmath built and enabled for PHP ${PHP_VERSION}."
else
  echo "bcmath build completed but the extension did not load; check ${INI_SCAN_DIR}/20-bcmath.ini." >&2
fi
