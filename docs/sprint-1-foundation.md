# Sprint 1: Foundation

## Delivered
- Laravel 13 application with Bootstrap 5 theme compiled by Vite.
- Full database from the approved design (money in cents, contract price snapshots, audit tables).
- Admin sign-in: password, then authenticator-app two-factor (forced at first sign-in), recovery codes (single use), password reset by email, admin invitations (signed link, 72 hours).
- Roles with menu access: Super admin (system, full access) plus editable Billing, Sales, Viewer starter roles. Only super admins can grant Super admin; admins can't change their own access.
- Settings tabs: Company, Pricing, Renewal pricing (blank = original rates), Signature (font or uploaded PNG, private storage), Stripe (test/live keys encrypted, live mode blocked until all live keys exist), Email (Brevo key encrypted, team CC), Tax (off).
- My account: change password (signs out other devices), regenerate recovery codes.
- Audit trail of sign-ins, admin changes, role access and settings changes (secret values never logged).
- Security headers with Content Security Policy, login and two-factor rate limiting, encrypted sessions.
- Feature tests for authentication, permissions and settings; GitHub Actions CI.

## Decisions
| Decision | Why | Alternative considered |
|---|---|---|
| Own roles table (menu = permission) instead of spatie/laravel-permission | You asked for roles by menu; a one-role-per-admin model is simpler to reason about and has no package upgrade risk | spatie/laravel-permission: more flexible, more moving parts than needed |
| Own audit log instead of spatie/activitylog | Current activitylog release needs PHP 8.4; our log is one table and one service | Upgrade PHP to 8.4 |
| Settings in one table, schema in code (`SettingsSchema`) | Form, validation and defaults can't drift apart; secrets encrypted per field | spatie/laravel-settings |
| Money in cents | No floating-point rounding on invoices | Decimal columns |
| Two-factor mandatory for every admin | The panel controls billing and card-on-file charges | Optional two-factor |

## Not in this sprint
Customers, Contracts, Invoices, Coupons and Email templates menus show as "Soon" until their sprint.

## Takeover review (Sep 23, 2026)
Sprint 1 was built in a separate session and taken over for sign-off.

| Finding | Fix | Commit |
|---|---|---|
| CI failed: `phpunit.xml` expects `tests/Unit`, which didn't exist | Added `tests/Unit/MoneyTest.php` (formatting, dollar-to-cent conversion, approved agreement math) | 8e8ac92 |
| Negative amounts formatted as `$-450.00` | Now `-$450.00` | 8e8ac92 |
| Two-factor attempts were limited per session, so a new session reset the counter | Limited per admin: 5 per minute, 20 per hour, with a test | b0a816a |
| App trusted every proxy (`*`), so a visitor could fake the IP recorded on signed agreements and in the audit log | Trusts only `TRUSTED_PROXIES` (default localhost) | b0a816a |
| CI failures weren't readable without the Actions UI | CI prints failures as an annotation | 8cb654c |

Open item: set `TRUSTED_PROXIES` to Cloudflare's ranges at deployment if Cloudflare proxies the site.
