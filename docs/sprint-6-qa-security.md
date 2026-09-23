# Sprint 6: QA, security and go-live kit

## Added
- **System status** on the dashboard (super admins) and **`/health`** (200/503, no secrets): database, cron heartbeat, daily billing run, email queue, Stripe keys and webhook, last webhook result, Brevo.
- Scheduler heartbeat every 5 minutes; weekly pruning of failed jobs; daily clean-up of expired password resets.
- Branded error pages (403, 404, 419, 429, 500, 503), with a clear message for expired onboarding links and admin pages outside your role.
- `robots.txt` keeps admin, onboarding, renewal and API pages out of search engines.
- CI now fails on known vulnerabilities in PHP or JavaScript dependencies (`composer audit`, `npm audit`).
- Deployment kit in `deploy/`: server set-up for Ubuntu 24.04, Nginx site, Supervisor queue worker, deploy script (backup, build, migrate, cache, restart, health check), backup script (database, private files and `.env`, 14 days, optional off-site with rclone).
- `.env.production.example`, `DEPLOYMENT.md` (including disaster recovery), `GO_LIVE_CHECKLIST.md`, `ADMIN_GUIDE.md`.

## Security review
| Area | Status |
|---|---|
| Admin sign-in | Password plus mandatory authenticator 2FA; recovery codes single-use; login limited per email+IP and per IP; 2FA limited per admin (5/min, 20/hour); invitations by signed link, 72 hours |
| Authorization | Every admin route behind a menu permission; only super admins grant super admin; admins can’t change their own access |
| Client pages | Onboarding pages bound to the browser session or a signed link; renewal pages by signed link only; agreements sign once |
| Output | All user data escaped; agreement templates sanitised to a safe tag list on save; email preview in a sandboxed frame |
| Headers | CSP with nonces (Stripe and Turnstile allowed explicitly), HSTS over HTTPS, X-Frame-Options DENY, nosniff, referrer and permissions policies |
| Secrets | Stripe, Brevo and Turnstile secrets encrypted in the database and never shown again; agent API tokens hashed for lookup, encrypted for the daily pull; `.env` never served |
| Payments | Card and bank details only ever go to Stripe (Payment Element); webhooks verified by signature with a 5-minute window and stored once; idempotency keys on every Stripe create |
| Evidence | Signer IP only trusted from configured proxies; consent and signing times, browser, terms fingerprint and PDF SHA-256 stored |
| Abuse | Rate limits on onboarding, coupon checks, signing, agent API, test emails and resend links; optional Turnstile bot check |
| Files | Signed PDFs and signatures on the private disk, downloaded only through authorised routes; signature uploads limited to PNG, 1 MB |
| Data at rest | Sessions encrypted; database and file backups with owner-only permissions |

## Known limits
- Real Stripe and Brevo calls are simulated in tests (the build environment can’t reach them). The go-live checklist includes the end-to-end run in Stripe test mode.
- Static analysis (PHPStan/Larastan) isn’t set up yet; adding it needs a `composer.lock` refresh through the `deps-lock` branch.

## Tests
90 passing.
