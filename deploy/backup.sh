#!/usr/bin/env bash
# Nightly backup (cron 2:30 AM) and before every deploy.
# Database (gzip) + private files (signed agreements, signatures) + .env, kept 14 days.
# Optional off-site copy: install rclone, configure a remote, set BACKUP_RCLONE_REMOTE in .env
# (e.g. "b2:rightally-backups" or "s3:bucket/rightally").
set -euo pipefail
cd "$(dirname "$0")/.."
# Backup folder: $BACKUP_DIR, else /var/backups/rightally if writable (VPS), else ~/backups/rightally (cPanel).
if [[ -n "${BACKUP_DIR:-}" ]]; then DEST="$BACKUP_DIR"
elif mkdir -p /var/backups/rightally 2>/dev/null && [[ -w /var/backups/rightally ]]; then DEST=/var/backups/rightally
else DEST="$HOME/backups/rightally"; fi
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$DEST"
umask 077

# Reads a value from .env, removing surrounding single or double quotes.
env_get() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e "s/^\"\(.*\)\"$/\1/" -e "s/^'\(.*\)'$/\1/"; }
DB_NAME="$(env_get DB_DATABASE)"; DB_USER="$(env_get DB_USERNAME)"; DB_PASS="$(env_get DB_PASSWORD)"; DB_HOST="$(env_get DB_HOST)"
REMOTE="$(env_get BACKUP_RCLONE_REMOTE || true)"

MYSQL_PWD="$DB_PASS" mysqldump -h "${DB_HOST:-127.0.0.1}" -u "$DB_USER" --single-transaction --routines --no-tablespaces "$DB_NAME" | gzip > "$DEST/db-$STAMP.sql.gz"
tar -czf "$DEST/files-$STAMP.tar.gz" storage/app/private .env 2>/dev/null || tar -czf "$DEST/files-$STAMP.tar.gz" .env
echo "Backup written: $DEST/db-$STAMP.sql.gz and files-$STAMP.tar.gz"

find "$DEST" -name 'db-*.sql.gz' -mtime +14 -delete
find "$DEST" -name 'files-*.tar.gz' -mtime +14 -delete

if [[ -n "${REMOTE:-}" ]] && command -v rclone >/dev/null; then
  rclone copy "$DEST" "$REMOTE" --include "*-$STAMP.*" && echo "Copied off-site to $REMOTE"
fi
