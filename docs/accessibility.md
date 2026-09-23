# Accessibility

Target: **WCAG 2.1 level AA** for all client pages (onboarding, account, renewal, legal pages) and the admin.

## Last audit (Sprint 11)
Automated audit with axe-core (WCAG 2.1 A and AA rules plus best practices) on 14 pages: onboarding details (empty and with errors), agreement, client account, account link request, privacy policy, 404, admin sign-in and two-factor, dashboard, customers list, customer page, invoices and settings. **Result: no violations.**

Fixed in this pass:
- Button and label colours below 4.5:1 contrast (outline buttons, admin menu group labels, error-page code).
- Coupon field had only a placeholder: now has a label for screen readers.
- Empty table headers on action columns: now announced as "Actions".
- Legal pages skipped a heading level.
- "Skip to main content" link on every page (visible on keyboard focus).

Beyond the automated checks:
- **Signing without a mouse**: the agreement and renewal pages offer "Use my typed name as my signature instead of drawing", so keyboard and screen-reader users can sign. The PDF's signature record states whether the signature was drawn or adopted from the typed name.
- Keyboard-only run-through of onboarding (details to signed agreement) confirmed.
- Pages declare their language; form errors are shown next to each field and summarised at the top; live price updates are announced politely.

## Re-running the audit
`tools/a11y/audit.py` (instructions at the top of the file). Run it before releases that change page layouts.

## Known limits
- The Stripe payment form is Stripe's own component (Stripe maintains its accessibility).
- The Cloudflare bot check, when switched on, is Cloudflare's widget.
- A manual screen-reader review (VoiceOver, NVDA) is recommended once a year.
