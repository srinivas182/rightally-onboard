#!/usr/bin/env bash
# Deploy the latest code (or a given branch/tag). Run as the app user from the app folder:
#   ./deploy/deploy.sh            # latest main
#   ./deploy/deploy.sh v1.0.0     # a tag
# Backs up the database first; if migrations fail, the site stays in maintenance mode
# and the backup file name is printed for DISASTER RECOVERY (see DEPLOYMENT.md).
set -euo pipefail
cd "$(dirname "$0")/.."
REF="${1:-main}"

# PHP: $PHP_BIN if set, else cPanel's PHP 8.4/8.3 if present, else "php".
if [[ -z "${PHP_BIN:-}" ]]; then
  for c in /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php; do [[ -x "$c" ]] && PHP_BIN="$c" && break; done
  PHP_BIN="${PHP_BIN:-php}"
fi
# Composer: ~/composer.phar run with that PHP (allow_url_fopen on for Composer only), else "composer".
if [[ -f "$HOME/composer.phar" ]]; then COMPOSER=("$PHP_BIN" -d allow_url_fopen=On "$HOME/composer.phar"); else COMPOSER=(composer); fi
# Node: load nvm if it's installed in this account.
[[ -s "$HOME/.nvm/nvm.sh" ]] && source "$HOME/.nvm/nvm.sh"
echo "Using $("$PHP_BIN" -r 'echo "PHP ".PHP_VERSION;'), $("${COMPOSER[@]}" --version 2>/dev/null | head -1)"

echo "==> Backup before deploy"
./deploy/backup.sh

echo "==> Code ($REF)"
# cPanel's MultiPHP Manager adds a PHP-version handler block to public/.htaccess.
# Keep it across updates, otherwise the site could fall back to an older PHP.
CPANEL_BLOCK="$(sed -n '/# php -- BEGIN cPanel-generated handler/,/# php -- END cPanel-generated handler/p' public/.htaccess 2>/dev/null || true)"
git fetch --tags --prune origin
if git show-ref --verify --quiet "refs/remotes/origin/$REF"; then git checkout -q "$REF" && git reset -q --hard "origin/$REF"; else git checkout -q "tags/$REF"; fi
if [[ -n "$CPANEL_BLOCK" ]] && ! grep -q "BEGIN cPanel-generated handler" public/.htaccess; then
  printf '\n%s\n' "$CPANEL_BLOCK" >> public/.htaccess
  git update-index --assume-unchanged public/.htaccess
fi

echo "==> Dependencies and assets"
"${COMPOSER[@]}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
npm ci --no-audit --no-fund
npm run build

echo "==> Maintenance mode, migrate"
"$PHP_BIN" artisan down --retry=30 || true
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan db:seed --force   # seeders only add what's missing (roles, templates)

echo "==> Cache and restart workers"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan optimize
"$PHP_BIN" artisan queue:restart
"$PHP_BIN" artisan up

echo "==> Health"
APP_URL="$(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d '"')"
curl -fsS "$APP_URL/up" >/dev/null && echo "Site is up." || echo "WARNING: $APP_URL/up did not answer. Check storage/logs/laravel.log."
echo "Full status (Stripe, email, queue, scheduler): $APP_URL/health"
