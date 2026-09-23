# Sprint 10: Operations and safety

## 11. Refunds and credits from the admin
- **Refund** (customer page > Invoices > Refund): any amount up to what's left on the payment, with a reason the client sees. Goes through Stripe to the original method; the client gets a **Refund issued** email; revenue reports drop it.
- **Credit** (customer page > Actions > Add a credit): added to the Stripe customer balance and taken off the next charge automatically; **Credit issued** email.

## 12. Second-admin approval
- Early terminations always, and refunds or credits over **$500** (`config/rightally.php` > `approval_threshold_cents`), become requests.
- The team gets an "Approval needed" alert; requests show on the dashboard, the customer page and **Invoices > Approvals**.
- Any other admin with the Invoices menu approves (the action runs at once) or rejects with a reason. Requesters can't approve their own. Outcomes and errors are recorded.

## 13. Pause a subscription
- Customer page > Actions > Pause subscription: 1 to 3 months, once in any 12 months, live customers only.
- Stripe pauses collection (monthly invoices are voided), the minimum term and end date move out by the pause length, status becomes **Paused**, the client is emailed, and their RightAlly site gets `customer.paused` (access `paused`).
- Resumes automatically on the end date (daily run) or early from Actions; **Subscription resumed** email; site gets `customer.resumed`.
- Early termination during or after a pause counts the added months.
- The agreement template now includes the pause clause (Section 5). **Attorney review needed.**

## 14. Automatic deploys (`.github/workflows/deploy.yml`)
Push a tag like `v1.4.0`: tests run, then staging deploys, then production waits for your approval in GitHub. Inactive until you add the secrets listed at the top of the file and a "production" environment with yourself as reviewer. Manual runs: Actions > Deploy > Run workflow.

## 15. Email delivery tracking (Brevo)
- Settings > Email shows a private webhook URL to add in Brevo (Transactional > Settings > Webhook).
- Email statuses update to delivered, opened, deferred, bounced, complained or failed (never downgraded by late events).
- Hard bounces, invalid addresses and blocks flag the customer: a red banner on their page and a dashboard item. Fix the address in the new **Contact** panel (also updates Stripe); the flag clears.

## Also
- New customer status **Paused**; included in renewals and early termination, excluded from recurring revenue.
- New email templates: Refund issued, Credit issued, Subscription paused, Subscription resumed.

## Tests
127 passing.
