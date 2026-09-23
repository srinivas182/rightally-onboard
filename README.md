# RightAlly Onboarding

Client onboarding, electronic agreement signing and Stripe billing for RightAlly
(Mayura Consultancy Services LLC d/b/a RightAlly).

**Stack:** Laravel 13, PHP 8.3, MySQL 8, Bootstrap 5.3 (Sass, Vite), Stripe, Brevo.

## Sprints

| Sprint | Scope | Status |
|---|---|---|
| 0 | Brand, design system, clickable prototype | Approved |
| 1 | Foundation: database, admin sign-in with two-factor, roles, settings | Done |
| 2 | Onboarding, agreement signing and PDF, coupons, agreement templates | Done |
| 3 | Payments (Stripe deposit, webhooks) and email (Brevo, editable templates) | Done |
| 4 | Billing engine: go-live charge, subscriptions, agent counts, suspension, early termination, renewals | Done |
| 5 | Admin back office: dashboard, customers, invoices, renewals | Done |
| 6 | QA, security hardening, deployment kit, guides | Done |
| 7 | Tax, bank confirmation, refunds and chargebacks, Stripe reconciliation, deposit reminders | Done |

## Guides
- `DEPLOYMENT.md`: server set-up, updates, monitoring, backups and disaster recovery
- `GO_LIVE_CHECKLIST.md`: everything to check before the first real client
- `ADMIN_GUIDE.md`: day-to-day use of the admin
- `docs/`: design decisions per sprint

## Local setup

Requirements: PHP 8.3 with `mbstring`, `pdo_mysql`, `gd`, `intl`, `bcmath`; Composer 2; Node 22; MySQL 8.

```bash
git clone https://github.com/srinivas182/rightally-onboard.git
cd rightally-onboard
composer install
cp .env.example .env
php artisan key:generate
# edit .env: DB_* and SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD (12+ characters)
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Open `http://localhost:8000/admin`, sign in with the super admin from `.env`, and set up
two-factor with an authenticator app. Then remove `SUPER_ADMIN_PASSWORD` from `.env`.

Run tests with `php artisan test` (uses in-memory SQLite). GitHub Actions runs them on every push.

## Where things live

| Path | What |
|---|---|
| `config/rightally.php` | Menus (= permissions), business time zone, billing constants |
| `app/Services/Settings` | Admin-editable settings; secrets encrypted with `APP_KEY` |
| `app/Services/Auth/TwoFactorService.php` | Authenticator-app two-factor and recovery codes |
| `app/Services/Audit/AuditLogger.php` | Audit trail (`activity_logs`) |
| `routes/admin.php` | Back office routes (`/admin`) |
| `resources/scss` | Bootstrap theme from the RightAlly logo |
| `docs/` | Design system and sprint notes |
| `design/prototype.html` | Approved clickable prototype |

## Important

- `APP_KEY` encrypts Stripe and Brevo keys and two-factor secrets. Back it up; losing it means re-entering keys and resetting two-factor for every admin.
- Money is stored in integer cents.
- Signed agreements keep the prices they were signed with; changing Settings affects new agreements only.
