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
