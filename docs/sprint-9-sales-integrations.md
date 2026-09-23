# Sprint 9: Sales, reporting and integrations

## 6. Custom quotes (Admin > Custom quotes)
Negotiated set-up fee, deposit %, platform fee, per-agent fee, minimum agents and go-live days, optional pre-filled brokerage and email, a note shown to the client, and an expiry (default 14 days). The link (`/?quote=…`) shows the pricing with a banner, hides the coupon field (no stacking), and is used up when the client signs. Void anytime before. The agreement records the quote's prices and go-live days; the customer's source shows "Custom quote: <name>".

## 7. Onboarding funnel (Admin > Reports)
Clients who started in a date range, grouped by source, coupon, ad campaign or month: started, signed, paid deposit, live, and conversion between each step. CSV export.

## 8. Revenue (Admin > Reports > Revenue)
Monthly recurring revenue (live, payment-failed and suspended customers at current agent counts), annual run rate, new and churned MRR this month, net revenue by month and charge type for 12 months (less refunds and tax), and expected charges for the next 3 months. CSV export.

## 9. Webhooks
- **To CRMs/Zapier** (Settings > Alerts and integrations): add a URL and choose events: `customer.signed`, `customer.deposit_paid`, `customer.live`, `payment.failed`, `customer.suspended`, `customer.reactivated`, `customer.cancelled`, `customer.expired`, `agents.changed`. Each endpoint gets its own signing secret (shown once). Send test, recent deliveries, retry.
- **To each customer's RightAlly site**: live, suspended, reactivated, cancelled and ended are sent to `{live_url}/api/rightally/account-status` with the customer's API token, so the site can block or restore access. Spec for the platform team: `docs/integration-rightally-sites.md`.
- Signed like Stripe (`X-RightAlly-Signature: t=…,v1=HMAC-SHA256`), retried for about 9 hours (1m, 5m, 30m, 2h, 6h).
- Triggered from customer status changes in one place, so every path (onboarding, billing, admin actions) notifies.

## 10. Team alerts (Settings > Alerts and integrations)
Email (team CC addresses) and/or Slack (incoming webhook URL; placeholder until you add one). Switch each on or off: new signing, payment failures, daily go-live summary (after the 9 AM run: who was charged and whether it succeeded), chargebacks (Slack; the chargeback email always goes out).

## Menus and roles
New menus: Custom quotes, Reports. Starter roles on new installs: Sales gets quotes and reports; Billing gets reports. Existing roles: add them in Admins and roles.

## Tests
119 passing. New: quote pricing, go-live days, single use and expiry; funnel and revenue pages and CSVs; signed webhook with verifiable signature; customer-site suspend/restore with token; failed delivery kept for retry; team alert email and Slack; alerts off; placeholder Slack ignored; daily go-live summary.
