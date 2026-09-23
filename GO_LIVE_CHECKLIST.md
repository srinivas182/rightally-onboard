# Go-live checklist

Tick each item before the first real client signs.

## Legal and business
- [ ] Mayura Consultancy Services LLC name change filed and active on Sunbiz.
- [ ] Fictitious name "RightAlly" registered on Sunbiz for that LLC.
- [ ] A Florida attorney has reviewed the agreement and renewal templates (Admin > Contracts > Templates). If they change wording: create a new version, edit, publish.
- [ ] Settings > Company: legal name, DBA, address, phone and support email are correct.
- [ ] Settings > Pricing and Renewal pricing are correct.
- [ ] Settings > Signature: name, title, and signature style or image.

## Email (Brevo)
- [ ] `rightally.io` authenticated in Brevo (SPF, DKIM and DMARC DNS records added and verified).
- [ ] Settings > Email: Brevo API key, from name/email, team CC addresses.
- [ ] Email templates: read each automatic email; send yourself a test of "Agreement signed (welcome)", "Balance failed" and "Payment failed".
- [ ] Settings > Security: Cloudflare Turnstile site and secret keys (bot check on the onboarding form).

## Stripe, test mode first
- [ ] Settings > Stripe: test publishable and secret keys.
- [ ] Stripe dashboard > Developers > Webhooks: add `https://onboard.rightally.io/stripe/webhook` with **all 10 events** listed on the Stripe settings tab (including refunds and disputes); paste the signing secret.
- [ ] Stripe > Settings > Billing > Subscriptions and emails: turn on **Smart Retries** (retry failed payments), and turn **off** Stripe's own customer emails if you want only RightAlly's.
- [ ] Stripe > Settings > Payment methods: **ACH Direct Debit** enabled; **Financial Connections** enabled.
- [ ] Admin dashboard > System status: everything green.

- [ ] Tax decision: leave Settings > Tax off, or add registrations in Stripe Tax first and confirm with your accountant.
- [ ] Email templates: read the four new ones (Agreement signed, payment pending; Deposit reminder; Payment needs confirmation; Chargeback alert).

## End-to-end test in test mode
1. [ ] Create a coupon (e.g. `TEST50`, 50%). Open `https://onboard.rightally.io/?coupon=TEST50`.
2. [ ] Complete details, sign, download the PDF, pay with card `4242 4242 4242 4242`.
3. [ ] Welcome email arrives with the PDF attached, CC to the team.
4. [ ] Admin > Customers: the customer is "Awaiting go-live". Move the go-live date to 2 days from now: the client email arrives.
5. [ ] Repeat onboarding with **US bank account** ("Test Institution", "Success" account): status "processing", receipt email when it clears (Stripe test mode clears in minutes).
6. [ ] Declined card: onboarding with `4000 0000 0000 0002` shows the decline and lets you retry.
7. [ ] Go-live charge: set a test customer's go-live date to today with `php artisan tinker --execute="App\Models\Customer::where('email','you@example.com')->update(['go_live_date' => now()->toDateString()]);"`, then `php artisan billing:daily`. The customer becomes Live, and a subscription appears in Stripe with a trial until go-live + 30 days.
8. [ ] Monthly charge: in the Stripe dashboard open that subscription, choose **Update subscription > Trial end: now**. Stripe charges the first month at once; the invoice appears in Admin > Invoices > Paid and the receipt email arrives.
9. [ ] Monthly failure: on a second live test customer, in Stripe set the customer's default payment method to card `4000 0000 0000 0341` (attaches, then declines), then end the trial as above. Expect the "Payment failed" email, status "Payment failed", and a working Resend link. Paying through the link restores "Live".
10. [ ] Early termination from the customer page on a test customer.
    - [ ] Refund part of a test payment in Stripe: revenue on the dashboard drops.
    - [ ] Chargeback: pay with `4000 0000 0000 0259` (creates a dispute): the team gets the Chargeback alert and it shows under Needs attention.
    - [ ] Bank confirmation: set a live test customer's card to `4000 0027 6000 3184` in Stripe, end the trial: the Payment needs confirmation email arrives.
11. [ ] Delete test data before go-live (or start the live database fresh).

## Switch to live
- [ ] Settings > Stripe: live keys and live webhook secret (a separate endpoint in Stripe's live mode), then switch mode to **Live**.
- [ ] One real low-value transaction (e.g. your own brokerage with a 99% coupon), then refund it in Stripe.
- [ ] Uptime monitor on `/health`.
- [ ] Off-site backups configured and one restore tested.
- [ ] Revoke the GitHub token used during the build and issue a new one for the server (read-only is enough for deploys).
- [ ] Invite Sunil and set his role in Admins and roles.
