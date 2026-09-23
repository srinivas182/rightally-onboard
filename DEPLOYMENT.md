# Deployment guide

Target: one Ubuntu 24.04 VPS running Nginx, PHP 8.3-FPM, MySQL 8, a queue worker (Supervisor) and cron.
Examples use `onboard.rightally.io`; replace with your domain.

## 1. Before you start
- Point the domain's DNS **A record** at the VPS IP. If you use Cloudflare, set it to "DNS only" (grey cloud) until the HTTPS certificate is issued.
- You need: SSH access with sudo, the GitHub repo access token, and an email address for certificate notices.

## 2. Server set-up (once, as root)
```bash
git clone https://github.com/srinivas182/rightally-onboard.git /tmp/rightally-onboard
sudo bash /tmp/rightally-onboard/deploy/server-setup.sh onboard.rightally.io srini@rightally.io
```
This installs everything, creates the `rightally` user, the MySQL database, the Nginx site, the queue worker, cron (scheduler every minute and backups at 2:30 AM), the firewall and the HTTPS certificate. **Copy the database password it prints.**

## 3. First deployment (as the app user)
```bash
sudo -iu rightally
git clone https://github.com/srinivas182/rightally-onboard.git /var/www/rightally-onboard
cd /var/www/rightally-onboard
cp .env.production.example .env
nano .env        # DB_PASSWORD, APP_URL, SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD (12+ chars, mixed case, number, symbol)
composer install --no-dev --optimize-autoloader
php artisan key:generate
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
chmod -R ug+rw storage bootstrap/cache
exit
sudo supervisorctl restart rightally-queue:*
```
Then:
1. **Back up `APP_KEY`** from `.env` to your password manager. Without it, saved Stripe/Brevo keys and everyone's two-factor setup can't be decrypted.
2. Open `https://onboard.rightally.io/admin`, sign in, set up two-factor with an authenticator app, and save the recovery codes.
3. Remove `SUPER_ADMIN_PASSWORD` from `.env`.
4. Work through `GO_LIVE_CHECKLIST.md`.

## 4. Updating
```bash
sudo -iu rightally
cd /var/www/rightally-onboard && ./deploy/deploy.sh          # or ./deploy/deploy.sh v1.0.0
```
The script backs up first, pulls the code, installs, builds, migrates in maintenance mode, caches, restarts the queue worker and checks `/up`.

## 5. Monitoring
- **Admin dashboard > System status** (super admins): database, cron, daily billing run, email queue, Stripe keys and webhooks, Brevo.
- **`/health`** returns 200 when all of those are fine and 503 otherwise. Point an uptime monitor (UptimeRobot, Better Stack) at it.
- Logs: `storage/logs/laravel-YYYY-MM-DD.log` (30 days), queue worker `storage/logs/queue.log`.
- Failed email jobs: `php artisan queue:failed`, retry with `php artisan queue:retry all`.

## 6. Scheduled jobs (cron runs `schedule:run` every minute)
| Time (Miami) | Job |
|---|---|
| 9:00 AM daily | `billing:daily`: go-live charges, reminders, renewals, expiries, suspensions |
| 6:00 AM daily | `agents:sync`: pull agent counts from live sites |
| every 5 min | scheduler heartbeat (for System status) |
| weekly | prune failed jobs older than 30 days |
| 2:30 AM (server) | `deploy/backup.sh` |

Run the billing job by hand at any time; it's safe to repeat: `php artisan billing:daily`.

## 7. Cloudflare (optional)
After the certificate is issued you can switch Cloudflare to "Proxied". Then:
1. Set SSL/TLS mode to **Full (strict)**.
2. Put Cloudflare's IP ranges (https://www.cloudflare.com/ips/) in `TRUSTED_PROXIES` in `.env`, then `php artisan optimize`. This keeps the IP recorded on signed agreements accurate.
3. Certbot renewals keep working (HTTP-01 through the proxy).

## 8. Backups and disaster recovery
**What's backed up** nightly and before every deploy, in `/var/backups/rightally` (kept 14 days):
- `db-*.sql.gz`: the whole database.
- `files-*.tar.gz`: signed agreement PDFs, drawn signatures, the company signature image, and `.env` (which holds `APP_KEY`).

**Off-site copies (recommended)**: install `rclone`, configure a remote (Backblaze B2, S3, Google Drive), and set `BACKUP_RCLONE_REMOTE` in `.env`.

**Restore on a new server**
1. Run steps 2 and 3 above, but stop before `key:generate` and `migrate`.
2. Copy the latest backup files to the server.
3. Restore files and key: `tar -xzf files-XXXX.tar.gz -C /var/www/rightally-onboard` (restores `storage/app/private` and `.env`; update `DB_PASSWORD` in `.env` if the new server's differs).
4. Restore the database: `gunzip < db-XXXX.sql.gz | mysql -u rightally -p rightally_onboard`
5. `php artisan optimize && sudo supervisorctl restart rightally-queue:*`
6. Check `/health`, sign in, open a signed agreement PDF.
7. Stripe keeps working because keys are in the restored database. If the domain changed, update the webhook URL in Stripe.

**Rolling back a bad deploy**: `./deploy/deploy.sh <previous tag or commit>`. If a migration changed data, restore the pre-deploy database backup first (step 4).

**Test the restore** on a spare server every quarter.
