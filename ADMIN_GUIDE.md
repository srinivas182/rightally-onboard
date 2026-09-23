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

## Custom quotes
When you agree different pricing with a brokerage, create a quote (Custom quotes > New quote) and send them its link. It shows their pricing, can’t be combined with a coupon, and works once. Void it if the deal changes.

## Reports
Onboarding funnel shows where prospects drop off, by source, coupon, campaign or month. Revenue shows monthly recurring revenue, growth and churn, and the next 3 months’ expected charges.

## Alerts and integrations
Settings > Alerts and integrations: choose which alerts the team gets by email or Slack, and add webhooks to send events to your CRM (for example through Zapier).

## Coupons
Percentage off the implementation fee only. Can expire or never expire, and can have a usage limit. Once a code has been used it can’t be renamed; switch it off instead of deleting.

## Agreements and templates
Contracts > Signed lists every signed agreement with its PDF. To change the agreement wording: Contracts > Templates > New version, edit the draft, preview, publish. Published versions can’t be edited, so every signed agreement matches its template exactly. Have wording changes reviewed by your attorney.

## Email templates
Edit wording, CC, or switch any automatic email off. Use “Send test to me” after changes. Custom emails are reusable one-off messages you send from a customer’s page.

## Roles
Admins and roles: each role is a set of menus. Only super admins can create super admins. Every admin must use two-factor.
