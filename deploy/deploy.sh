#!/usr/bin/env bash
# Deploy the latest code (or a given branch/tag). Run as the app user from the app folder:
#   ./deploy/deploy.sh            # latest main
#   ./deploy/deploy.sh v1.0.0     # a tag
# Backs up the database first; if migrations fail, the site stays in maintenance mode
# and the backup file name is printed for DISASTER RECOVERY (see DEPLOYMENT.md).
set -euo pipefail
cd "$(dirname "$0")/.."
REF="${1:-main}"

echo "==> Backup before deploy"
./deploy/backup.sh

echo "==> Code ($REF)"
git fetch --tags --prune origin
if git show-ref --verify --quiet "refs/remotes/origin/$REF"; then git checkout -q "$REF" && git reset -q --hard "origin/$REF"; else git checkout -q "tags/$REF"; fi

echo "==> Dependencies and assets"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
npm ci --no-audit --no-fund
npm run build

echo "==> Maintenance mode, migrate"
php artisan down --retry=30 || true
php artisan migrate --force
php artisan db:seed --force   # seeders only add what's missing (roles, templates)

echo "==> Cache and restart workers"
php artisan optimize:clear
php artisan optimize
php artisan queue:restart
php artisan up

echo "==> Health"
APP_URL="$(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d '"')"
curl -fsS "$APP_URL/up" >/dev/null && echo "Site is up." || echo "WARNING: $APP_URL/up did not answer. Check storage/logs/laravel.log."
echo "Full status (Stripe, email, queue, scheduler): $APP_URL/health"
