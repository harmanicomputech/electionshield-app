#!/usr/bin/env bash
# Builds dist/election-shield-web-shared-hosting.zip for cPanel / DirectAdmin
# hosting without SSH: production dependencies included, a public_html with
# the front controller and the PWA files, and a .env with generated secrets.
#
#   scripts/build-shared-hosting.sh            first install (includes .env)
#   scripts/build-shared-hosting.sh --update   update zip without .env, so
#                                              extracting it keeps your settings
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP=election-shield-web
BUILD="$ROOT/build/shared-hosting"
DIST="$ROOT/dist"
UPDATE=false
[ "${1:-}" = "--update" ] && UPDATE=true
SUFFIX=""
if $UPDATE; then SUFFIX="-update"; fi
ZIP="$DIST/$APP-shared-hosting$SUFFIX.zip"

rm -rf "$BUILD" && mkdir -p "$BUILD/$APP" "$BUILD/public_html" "$DIST"

# Application code (tracked files only, so local .env / databases never leak).
git -C "$ROOT" ls-files -z --cached --others --exclude-standard \
  | grep -zv -E '^(tests/|\.github/|deploy/|scripts/|public/|phpunit\.xml)' \
  | (cd "$ROOT" && while IFS= read -r -d '' file; do
      [ -f "$file" ] && cp --parents "$file" "$BUILD/$APP"
    done)

(cd "$BUILD/$APP" && COMPOSER_ALLOW_SUPERUSER=1 composer install \
  --prefer-dist --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts --quiet)
(cd "$BUILD/$APP" && php artisan package:discover --ansi >/dev/null)

find "$BUILD/$APP/vendor" -depth -type d \( -name .git -o -name .github \) -exec rm -rf {} +

mkdir -p "$BUILD/$APP/storage/"{app/private,framework/{cache/data,sessions,views},logs}
rm -f "$BUILD/$APP/bootstrap/cache/"*.php

# Web root: the PWA files (manifest, service worker, CSS/JS, icons) plus the
# shared-hosting front controller, which finds the app folder outside it.
(cd "$ROOT/public" && git -C "$ROOT" ls-files -z --cached --others --exclude-standard public | sed -z 's#^public/##' \
  | while IFS= read -r -d '' file; do cp --parents "$file" "$BUILD/public_html"; done)
cp "$ROOT/deploy/shared-hosting/"{index.php,.htaccess,robots.txt} "$BUILD/public_html/"

$UPDATE || php -r '
    $env = file_get_contents($argv[1]);
    $random = fn ($bytes) => bin2hex(random_bytes($bytes));
    $values = [
        "APP_KEY" => "base64:".base64_encode(random_bytes(32)),
        "ADMIN_PASSWORD" => $random(12),
        "USSD_WEBHOOK_TOKEN" => $random(24),
        "USSD_WEBHOOK_SECRET" => $random(32),
    ];
    foreach ($values as $key => $value) {
        $env = str_replace("{{".$key."}}", $value, $env);
    }
    file_put_contents($argv[2], $env);
' "$ROOT/deploy/shared-hosting/env.template" "$BUILD/$APP/.env"

rm -f "$ZIP"
(cd "$BUILD" && zip -qr "$ZIP" "$APP" public_html)

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1))"
$UPDATE || echo "Setup key (ADMIN_PASSWORD): $(grep '^ADMIN_PASSWORD=' "$BUILD/$APP/.env" | cut -d= -f2)"
