# Sprint 7: Revenue protection and robustness

## Sales tax (Settings > Tax)
The switch now works. When on, Stripe Tax adds tax on top of every charge (fees are tax-exclusive, agreement Section 2e), from the client's address, tax code `txcd_10103001` (SaaS, business use):
- Deposit: Stripe Tax calculation before payment; the payment page shows the tax; the tax transaction is recorded once paid (for Stripe's tax reports).
- Go-live balance, early termination: Stripe invoices with automatic tax.
- Monthly: subscriptions created with automatic tax; prices are tax-exclusive.
Invoices store `tax_cents` separately; revenue excludes tax. Before switching on, add registrations in Stripe (Tax > Registrations) and confirm with your accountant.

## Bank confirmation on automatic charges
`invoice.payment_action_required` (e.g. 3-D Secure on a monthly or go-live charge): invoice marked "needs confirmation", customer flagged, new **Payment needs confirmation** email with Stripe's confirm link (CC team). Counts toward suspension like a failure.

## Refunds and chargebacks
- `charge.refunded`: refunded amount stored on the payment; revenue reduced.
- `charge.dispute.created`: payment marked disputed (excluded from revenue), **Chargeback alert** email to the team with the amount, reason, response deadline and links, and a "Needs attention" item on the dashboard.
- `charge.dispute.closed`: won restores the payment; lost marks it refunded.

## Nightly reconciliation
`billing:reconcile` (5:00 AM Miami) lists the last 3 days of relevant Stripe events and applies any the webhook missed. Result shown in System status. Run by hand: `php artisan billing:reconcile --days=7`.

## Signed but unpaid
- The signed agreement is emailed right after signing (**Agreement signed, payment pending**), with a 30-day link back to payment.
- `onboarding:reminders` (hourly): **Deposit reminder** at 24 hours and 3 days after signing, then stops.

## Internal
Webhook handling moved to `StripeEventHandler`, shared by the webhook and the reconciliation. The webhook event list on Settings > Stripe comes from that class, so it can't drift.

## Webhook events to enable in Stripe (updated)
`payment_intent.succeeded`, `payment_intent.processing`, `payment_intent.payment_failed`, `invoice.paid`, `invoice.payment_failed`, `invoice.payment_action_required`, `customer.subscription.deleted`, `charge.refunded`, `charge.dispute.created`, `charge.dispute.closed`.

## Tests
100 passing. New: deposit tax calculation, amount and recording; automatic tax on invoices, subscriptions and prices; no tax parameters when off; bank confirmation email once; refund and revenue; chargeback alert, dashboard, lost and won; reconciliation replays only missed events; reminders at 24h and 72h and not for paid clients; signed copy emailed at signing.
