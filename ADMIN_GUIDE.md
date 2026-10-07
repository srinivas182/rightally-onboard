# Admin guide

For everyone who uses the RightAlly onboarding admin at `/admin`.

## Signing in
Email and password, then a 6-digit code from your authenticator app (Google Authenticator, Microsoft Authenticator, 1Password). Lost your phone? Use a recovery code, then set up two-factor again under My account. A super admin can also reset your two-factor.

## Daily routine (5 minutes)
1. **Dashboard > Needs attention**: failed payments, go-lives this week, clients who signed but didn’t pay.
2. **Invoices > Failed**: resend the payment link to anyone who hasn’t paid. Accounts suspend automatically 30 days after a failure.
3. **System status** (super admins): everything should be green.

## Onboarding a client
Send them `https://onboard.rightally.io`, or a coupon link from Coupons (Copy link). They enter their details and agent count, sign the agreement, and pay the deposit. You get CC’d on the welcome email.

## The customer page
- **Go-live date**: change it if implementation slips. Allowed until 2 days before the current date; the new date must be at least 2 days away. The client is emailed and the balance charge moves.
- **Agents billed**: change when the client’s team changes. Never below 5. Takes effect from the next monthly charge.
- **Live site and agent API**: once live, enter the live URL and host, then create the API token and give it to whoever configures their RightAlly site so agent counts sync automatically.
- **Actions**: resend the welcome email, send a custom email, suspend/reactivate, early termination.

## What happens automatically
| When | What |
|---|---|
| Client signs | Signed agreement emailed with a link to finish payment (also to the signer, if someone else signed) |
| Agents billed change | Client told the new count and monthly fee (automatic syncs at most daily) |
| 30 days before the saved card expires | Card expiring email with the account link |
| 24 hours and 3 days after signing, if unpaid | Deposit reminder |
| Client pays deposit | Welcome email with signed agreement (CC team) |
| 3 days before go-live | Reminder email |
| Go-live date, 9 AM Miami | Balance charged; client goes live; monthly subscription starts |
| Go-live + 30 days, then monthly | Stripe charges $500 + $20 per agent; receipt email |
| Payment fails | Email with a link to update the card and pay (CC team); Stripe retries |
| Bank asks the client to confirm a charge | Email with Stripe’s confirm link (CC team) |
| Client disputes a charge (chargeback) | Alert to the team with the deadline; shown under Needs attention. Respond in Stripe with the signed agreement PDF as evidence |
| 30 days unpaid | Account suspended, email |
| 45 / 15 days before term end | Renewal agreement, then reminder |
| Term ends unsigned | Subscription ends, account expired, email |

## Client self-service
Clients can open their own account at `https://onboard.rightally.io/account` (they enter their email and get a link). There they see their agreement, invoices and receipts (PDF), what’s charged next, and can update their card or bank account. Point clients there before resending documents by hand.

## Yearly billing
Clients can choose to pay yearly in advance for a discount (Settings > Pricing sets the discount or switches the option off). Yearly clients show “Yearly” on their customer page; agent-count changes apply at their next yearly charge.

## Calls
Prospects who aren’t ready to onboard book a call at `/book-a-call` (your GoHighLevel calendar). Each booking appears in **Calls** with the coupon and campaign they came from. Filter by coupon, mark no-shows, add notes, and email an onboarding link (with their coupon) after the call. Calls turn **Onboarded** automatically when that email pays the deposit. Set-up: `docs/gohighlevel-calls.md`.

## Leads, invitation-only and follow-ups
Onboarding step 1 has two screens: **About you** (name, title, email, phone, brokerage, referral code) and **Your brokerage** (agents, address, billing). Clicking *Next* on the first screen saves the person as a lead:
- **Invitation only** (Settings > Pricing): a valid coupon or custom-quote link is needed to start. Switch it off to accept anyone.
- **Lead email** goes to the addresses in Settings > Alerts (*New-lead email goes to*), with everything they entered.
- **GoHighLevel**: with a Private Integration token and Location ID in Settings > Alerts, each lead becomes a GHL contact tagged `rightally-lead` and `coupon:<code>`, then `rightally-signed`, `rightally-paid`, `rightally-live` or `rightally-ended` as they progress.
- **Customers > Not completed** shows *Stopped at* (Brokerage details, Agreement, Payment); resume links open that exact screen.
- **Follow-ups**: four emails (about 1 hour, 1 day, 3 days and 7 days after they stopped), 8am–8pm Eastern only. They stop when the person signs, books a call or clicks the stop link. Edit them in Email templates (*Follow-up 1–4*); switch them off in Settings > Alerts.
- **Address suggestions**: add a Google Maps key in Settings > Alerts (enable *Maps JavaScript API* and *Places API (New)*, restrict the key to `onboard.rightally.io/*`). Without a key, people type the address.

## Moving existing clients over
For clients who were live before this app (already billed in Stripe): **Custom quotes > New quote > tick “Existing client”**. Enter their current Stripe subscription ID (Stripe > Customers > their subscription), per-agent fee, platform fee (0 if they only pay per agent), minimum agents and term (month to month, 6, 12 or 24 months). The first charge date defaults to their current renewal date. Send them the link: they sign a subscription-only agreement and confirm the card already on file (or add a new one). Nothing is charged that day; their old subscription stops renewing and the new one starts on the first charge date, so they’re never charged twice.

## Ending a customer’s service
Customer page > Actions > **End service**: at the end of the term (keeps billing until then), at the end of the current billing period (nothing more charged), or now without a fee (needs a second admin). To stop now and charge the rest of the term, use **Early termination (with fee)**. Stripe is updated immediately; the client is emailed; a scheduled end can be undone. Ended customers move to **Former customers**: when they onboarded and ended, months active, reason, revenue to date, last payment, CSV export and a win-back email.

## Custom quotes
When you agree different pricing with a brokerage, create a quote (Custom quotes > New quote) and send them its link. It shows their pricing, can’t be combined with a coupon, and works once. Void it if the deal changes.

## Reports
Onboarding funnel shows where prospects drop off, by source, coupon, campaign or month. Revenue shows monthly recurring revenue, growth and churn, and the next 3 months’ expected charges.

## Alerts and integrations
Settings > Alerts and integrations: choose which alerts the team gets by email or Slack, and add webhooks to send events to your CRM (for example through Zapier).

## Refunds, credits and approvals
Refund from the Invoices tab on the customer page; add a credit from Actions. Anything over $500, and every early termination, goes to Invoices > Approvals for a second admin. You can’t approve your own requests.

## Pausing
Actions > Pause subscription, for 1 to 3 months, once a year. No charges during the pause; the term is extended; the client and their site are notified. It resumes by itself, or use Resume subscription now.

## Bounced emails
A red banner on the customer page means our emails aren’t reaching them. Call the client and update the email in the Contact panel.

## Coupons
Percentage off the implementation fee only. Can expire or never expire, and can have a usage limit. Once a code has been used it can’t be renamed; switch it off instead of deleting.

## Agreements and templates
Contracts > Signed lists every signed agreement with its PDF. To change the agreement wording: Contracts > Templates > New version, edit the draft, preview, publish. Published versions can’t be edited, so every signed agreement matches its template exactly. Have wording changes reviewed by your attorney.

## Email templates
Edit wording, CC, or switch any automatic email off. Use “Send test to me” after changes. Custom emails are reusable one-off messages you send from a customer’s page.

## Roles
Admins and roles: each role is a set of menus. Only super admins can create super admins. Every admin must use two-factor.
