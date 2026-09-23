# Sprint 2: Client onboarding and agreement signing

## Delivered
**Client onboarding** (`/`, Bootstrap 5, mobile first)
1. Details: signer, title, brokerage, email, US phone, number of agents, address (50 states + DC, country locked to US), coupon. Live "Your agreement" panel recalculates as the agent count or coupon changes. `/?coupon=CODE` pre-fills and applies a coupon. utm tags, coupon links and referrer are stored as the customer's source.
2. Review and sign: agreement rendered from the active template with the client's details and prices. E-SIGN consent, typed legal name and drawn signature are all required. On signing, prices are frozen, the drawn signature is cropped and stored privately, the PDF is generated, and IP, browser, consent and signing times and SHA-256 fingerprints are recorded.
3. Payment schedule with real dates (deposit today, balance on go-live, first monthly charge 30 days after go-live).
4. Payment method: holding message until Stripe is connected in Sprint 3.

Pages after step 1 open only in the browser that started onboarding, or through a signed link (used by Sprint 3 emails).

**Admin**
- Coupons: create, edit, extend, never expires, usage limit, switch off, copy share link. Used codes can't be renamed.
- Contracts: searchable list of signed agreements, PDF download (logged in the audit trail).
- Agreement templates: new version (draft copy), edit with placeholders, preview with sample data, publish (retires the previous version), delete unused drafts. Published versions are read-only. Template HTML is sanitised on save.
- Settings > Security: Cloudflare Turnstile keys (bot check on the onboarding form; off until both keys are set).

**PDF**: US Letter, bundled fonts (Source Serif 4, Mrs Saint Delafield for signatures; both SIL Open Font License), logo header, fee summary, both signatures, electronic signature record, page numbers.

## Decisions
| Decision | Why |
|---|---|
| dompdf/dompdf directly, not a Laravel wrapper | No framework-version coupling; we only need HTML to PDF |
| Agreement number from the row id (`RA-YYYY-NNNN`) | Two simultaneous sign-ups can never get the same number |
| Coupon use counted at signing | That's when the discount becomes contractual |
| PDF shows a fingerprint of the terms; the database stores the PDF file's SHA-256 | A PDF can't contain its own hash |
| `composer.lock` committed | Servers install exactly the tested versions |

## Also fixed
- Coupons were treated as expired during their last day (time-zone mismatch). Now valid through the whole expiry day, Miami time.
- Admin pages widened on phones when a table was wide. Tables now scroll inside their panel.

## Tests
47 passing: pricing snapshot and NAR2026 example, agent minimum, validation messages, coupon expiry boundary, signing evidence and PDF hash, sign-once, details lock after signing, access from another browser, coupon admin rules, template read-only and sanitising.

## Refreshing composer.lock
This environment can't reach Packagist. To add or update PHP packages, change `composer.json` on the `deps-lock` branch and push; its workflow commits a new `composer.lock` there.
