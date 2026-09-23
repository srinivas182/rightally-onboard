# Sprint 11: Yearly prepay and accessibility

Spanish was scoped and then dropped at your request: the site stays English only. (Page text is written through Laravel's translation helper, which simply returns the English text; that makes adding a language later straightforward.)

## Yearly prepay
- Details page: **Monthly** or **Yearly (save X%)**. Settings > Pricing: switch the yearly option on or off and set the discount (default 10%).
- The agreement records the choice; yearly agreements add an annual billing paragraph to Section 2(d) and a yearly line in the fee summary. **Attorney review needed for that paragraph.**
- Stripe: yearly prices (12 months less the discount) and a yearly subscription; first charge 30 days after go-live, then yearly. Agent changes apply at the next yearly charge.
- New invoice type **Annual subscription** (receipts, PDF, failure emails and suspension as for monthly).
- Early termination: a yearly client has prepaid the term, so nothing further is due.
- Renewals keep yearly billing. Reports count yearly plans at 1/12 in monthly recurring revenue. Payment schedule, account page, upcoming charges and forecasts use yearly dates and amounts.
- Emails use a new `{subscription_amount}` placeholder ("$660.00 a month" / "$7,128.00 a year").

## Accessibility
See `docs/accessibility.md`: axe audit clean on 14 pages, contrast fixes, labels, skip links, and signing with the typed name instead of drawing.

## Tests
132 passing.
