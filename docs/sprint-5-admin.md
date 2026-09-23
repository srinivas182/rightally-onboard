# Sprint 5: Admin back office

## Dashboard
- KPIs, each linking to the matching list: customers onboarded (and this month), revenue received (Today / Month / Year / custom range), failed payments (count and amount outstanding), go-lives in the next 7 days (count and balance to charge), suspended accounts, renewals due in the next 45 days and not yet signed.
- Revenue chart: settled payments by month for the last 12 months.
- Needs attention: failed payments, upcoming go-lives, signed agreements with no deposit after a day.

## Customers
- List: search (company, name, email, city), status filter, pagination, CSV export.
- Customer page:
  - Overview: go-live date (editable until 2 days before, client emailed), agents billed (minimum enforced, note, Stripe updated), live URL and host, agent API token (created or regenerated, shown once), billing summary and recent charges.
  - Invoices (with Stripe page and resend link), Agreements (PDF, signer, IP, PDF fingerprint), Agent count history, Emails (with delivery status), Activity (audit trail for the customer, its agreements and invoices).
  - Actions: resend welcome email and agreement, send a custom email, suspend / reactivate, early termination (shows months remaining, monthly fee and amount; unpaid invoices stay due; requires typing the company name).

## Invoices
- Paid: filter by type and period, CSV export with Stripe fees.
- Failed: days since failure (warns from day 25, suspension at 30), last link sent, resend Stripe's update-and-pay link. Bank payments in progress listed separately.
- Upcoming: go-live balances and monthly fees expected in the next 30 days.

## Contracts
- New Renewals tab: renewal agreements with offer and reminder dates, new monthly fee, status, resend offer, PDF once signed.

## Staging data
`php artisan db:seed --class=DemoSeeder` creates six sample customers in different states (awaiting go-live, live, payment failed, suspended, signed without deposit) with agreements, invoices and payments. It refuses to run when `APP_ENV=production`.

## Tests
85 passing. New: dashboard ranges, customer filters and CSV, customer page, go-live change and lock, agents and live site, token shown once, suspend and reactivate, early termination confirmation, custom email, invoice tabs, resend link, CSV, menu permissions on the new screens.
