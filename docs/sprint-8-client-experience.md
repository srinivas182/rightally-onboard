# Sprint 8: Client experience and trust

## 1. Privacy Policy and Terms of Use
- Draft texts (for attorney review) at `/privacy` and `/terms`, filled with the company details from Settings.
- Linked in the footer of every onboarding, account, renewal and legal page and every email; the details form says continuing means agreeing to both.
- Editable in Admin > Settings > Legal pages (HTML sanitised on save; saving publishes).

## 2. PDF invoices and receipts
- Branded PDF for every charge: company and client details, agreement number, lines (monthly shows platform fee and agents × rate), subtotal, sales tax, total, paid date and method or amount due with the pay link, refunds.
- Attached automatically to: welcome (deposit receipt), deposit receipt, balance paid, monthly receipt, balance failed, payment failed, early termination.
- Download from Admin > Invoices, the customer page, and the client account page.

## 3. Client account page
- `/account`: the client enters their email and gets a link (same answer whether or not the email is known; 5 requests per 10 minutes).
- The link opens their page for 7 days: status, unpaid invoices with Pay now, next charge (date, amount, what it is), payment method and expiry, agreements (PDF), invoices and receipts (PDF).
- Update payment method: Stripe Payment Element in setup mode (card or bank, nothing charged). The new method becomes the default on the Stripe customer and the subscription; confirmation email. Stripe setups for another customer are rejected.
- Card expiring: email 30 days before the saved card's last valid day, once per card (daily run).
- New placeholder `{account_link}` for any email template.

## 4. Agent count change email
Client is told the old and new count, the new monthly fee and when it applies. Admin changes always email; automatic syncs (push or daily pull) email at most once a day. Not sent before the deposit is paid.

## 5. Someone else signs
On the agreement page: "Send it to someone else to sign" (name, title, email). Their details go on the agreement; they get a 7-day signing link. Receipts stay with the account email. The signer's email and the request time are in the PDF's electronic signature record; both the signer and the account email receive the signed copy.

## New email templates
Account link, Card expiring, Payment method updated, Agent count changed, Signature request.

## Bug caught by the new tests
Delegated signing crashed while generating the PDF (a date wasn't typed as a date). Fixed before release.

## Tests
110 passing.
