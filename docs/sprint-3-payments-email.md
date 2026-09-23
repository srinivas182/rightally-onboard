# Sprint 3: Stripe payments and email

## Delivered
**Deposit payment (onboarding step 4)**
- Stripe Payment Element with card and US bank account (ACH, instant verification). Styled in RightAlly colours, light and dark.
- Creates the Stripe customer (company, signer, address) and one PaymentIntent per deposit invoice (`INV-YYYY-NNNN`), with `setup_future_usage=off_session` so the same card or bank account is charged automatically for the go-live balance and monthly fees.
- Authorization text for recurring charges shown above the Pay button.
- Card: paid at once, customer moves to "Awaiting go-live", payment method saved as the Stripe default, Stripe fee recorded.
- Bank: shown as processing (up to 4 business days); confirmed by webhook; receipt emailed when it clears.
- Failed payments return to step 4 with Stripe's reason; the client can retry with another method.
- Step 5 "All set" page with next steps and the agreement download.

**Stripe webhooks** (`POST /stripe/webhook`)
- Signature verified (5-minute tolerance), every event stored once in `stripe_events`, safe to receive twice, failures return 500 so Stripe retries.
- Settings > Stripe shows the webhook URL and the events to subscribe to.

**Email (Brevo)**
- Branded HTML layout with plain-text version, sent from a queue with 3 attempts, every email recorded in `email_logs`.
- Team CC per template. Signed agreement PDF attached to the welcome email.
- Until a Brevo key is saved, emails go through the mailer in `.env` (`MAIL_MAILER`; `log` writes them to `storage/logs`) and show as "Logged".
- Admin invitation and password reset emails now use the editable templates too.

**Admin: Email templates**
- Automatic emails (16): edit subject and message, CC team on/off, switch off, reset to default, send a test to yourself, live preview with sample data. Not deletable.
- Custom emails: create, edit, delete (sending from a customer page arrives with the Customers screen in Sprint 5).
- Recently sent list with delivery status and errors.

## Decisions
| Decision | Why |
|---|---|
| Stripe REST API through Laravel's HTTP client, not the Stripe SDK | No SDK upgrade coupling; every call fakeable in tests; pinned API version `2024-06-20` |
| One PaymentIntent per deposit, reused on reload | No duplicate charges if a client refreshes; idempotency keys on every create |
| Welcome email on card success or bank start; receipt when bank payment clears | One clear email per event, never "paid" before the money is confirmed |
| Coupon use still counted at signing | Unchanged from Sprint 2 |

## Server set-up for this sprint
1. Queue worker must run: `php artisan queue:work --tries=3` (Supervisor config comes in Sprint 6). `QUEUE_CONNECTION=database`.
2. Settings > Stripe: test keys, then add the webhook endpoint in Stripe and paste its signing secret.
3. Settings > Email: Brevo API key; verify `rightally.io` as a sender domain in Brevo.
4. Test card `4242 4242 4242 4242`, any future date, any CVC. Test bank: choose "Test Institution" in the bank window.

## Tests
60 passing. New: PaymentIntent parameters, reuse on reload, card success (status, saved method, fee, welcome email with PDF and CC), webhook plus return URL applied once, bad signature rejected, bank processing then cleared with receipt, declined card retry, Stripe not configured, foreign PaymentIntent ignored, email template edit/reset/delete rules, test send, fallback mailer, admin invitation through template.

Not testable from the build environment (no access to Stripe or Brevo): the real Payment Element in a browser and real delivery. Verify on the server with the test keys.
