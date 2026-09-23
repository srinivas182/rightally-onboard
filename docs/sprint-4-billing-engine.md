# Sprint 4: Billing engine

## Daily run (`billing:daily`, 9:00 AM Miami time)
Safe to run more than once a day; every step checks what it already did.
1. **Go-live balance**: customers awaiting go-live whose date has arrived are charged the remaining implementation fee through a Stripe invoice on their saved card or bank. Paid: customer goes **Live**, the monthly subscription starts, "Balance paid" email. Declined: **Balance failed**, "Balance failed" email with Stripe's secure page to update the payment method and pay.
2. **Balance reminder** 3 days before the go-live date, once per date (sent again if the date moves).
3. **Renewals**: offer at 45 days (renewal agreement drafted and emailed), reminder at 15 days, renewal comes into force on its start date (new prices applied to the subscription), agreement **Expired** and subscription cancelled if the term ends unsigned.
4. **Suspension**: a balance or monthly payment still unpaid 30 days after it failed suspends the account, with an email. Paying restores the account automatically.

`agents:sync` (6:00 AM) pulls agent counts from customers' RightAlly instances.

## Monthly subscription
- Starts when the balance is paid. Two items: platform fee (quantity 1) and per-agent fee (quantity = billed agents, never below the minimum).
- No charge before go-live + 30 days (Stripe trial until then, 9:00 AM Miami time). Then monthly, charged by Stripe; Stripe's automatic retries apply to failures.
- Results arrive by webhook: `invoice.paid` records the invoice and payment and emails a receipt; `invoice.payment_failed` marks **Payment failed** and emails the update-payment link once per invoice.
- Prices are created in Stripe once per amount and reused (lookup keys `rightally_platform_monthly_50000`, `rightally_agent_monthly_2000`).

## Go-live date
`GoLiveService` moves the date (the admin screen arrives in Sprint 5). Allowed until 2 days before the current date; the new date must be at least 2 days away. Moves the agreement's term dates and emails the client.

## Agent counts
- Set by an admin, pushed by the customer's instance, or pulled daily. Billed count never below the agreement minimum. Every change logged. Applies from the next monthly charge (no pro-rating).
- Push API: `POST /api/v1/agent-count`, header `Authorization: Bearer <token>`, body `{"agents": 42}`. Response `{"agents_reported": 42, "agents_billed": 42}`. Rate limited.
- Pull: `GET {live_url}/api/rightally/agent-count` with the same token; expects `{"agents": 42}`.
- Tokens are generated per customer (admin screen in Sprint 5), stored hashed for lookup and encrypted for the daily pull.

## Early termination
`EarlyTerminationService` (admin action in Sprint 5): remaining months = 12 minus monthly invoices already issued under the agreement, at the current monthly fee. Subscription cancelled immediately, agreement marked terminated, invoice charged to the saved method, email with the invoice link.

## Renewal agreement
- Template "Renewal Agreement v1.0" (Contracts > Templates), editable like the initial agreement.
- Prices: Settings > Renewal pricing, or the customer's original rates where blank. No set-up fee.
- Signed from the emailed link (`/renew/{id}`, signed URL valid until the renewal's start date) with the same consent, typed name and drawn signature; PDF generated.

## Also fixed
- Renewal and go-live date checks compared dates across time zones, so a term could expire on its own last day. All stored dates are now compared as Miami calendar days.
- "Subscription started" email removed: "Balance paid" already tells the client their first monthly date.

## Stripe webhook events to enable
`payment_intent.succeeded`, `payment_intent.processing`, `payment_intent.payment_failed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.deleted`.

## Tests
75 passing. New: balance on the date (and not before, not twice), subscription parameters and first charge time, declined balance then paid by link, reminder once, go-live change and 2-day lock, monthly receipt, $0 trial invoice ignored, failed monthly with retries (one email), suspension at 30 days and restore on payment, agent minimum and Stripe quantity, push API with token, daily pull, early termination amount, renewal offer/reminder/sign/activate with renewal pricing, expiry when unsigned.
