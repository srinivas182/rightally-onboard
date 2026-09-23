# Sprint 0: Brand and design system

## Colours (sampled from the RightAlly logo)
| Token | Hex | Use |
|---|---|---|
| Blue | #1457EC | Primary buttons, links, active states (top of icon gradient) |
| Blue deep | #0839B2 | Gradient end, hover, signature ink |
| Navy | #041527 | Headings, body text, onboarding rail, admin sidebar |
| Mist | #F3F6FB | App background |
| Line | #DFE5EF | Borders and dividers |
| Slate | #56627A | Secondary text |

Status colours: success #0E8F63, danger #D1343F, warning #C98200.

## Type
- Instrument Sans for all interface text.
- Source Serif 4 for the agreement text, on screen and in the PDF, so the contract reads as a document.
- Mrs Saint Delafield only for rendering typed signatures.

## Layout
- Onboarding: navy rail with steps and a live "Your agreement" fee ledger that updates as coupons apply and stays visible through every step. On mobile the ledger becomes a bottom bar with a pull-up sheet.
- Admin: navy sidebar (offcanvas on mobile), KPI tiles that link to filtered lists, tables that scroll inside their panel.
- Emails: 600 px single column, logo header, fee table, one primary button, legal footer.
- PDF: US Letter, logo header, fee summary box, signature blocks, electronic signature audit record.

## Files
- `resources/scss/rightally.scss`: Bootstrap 5.3 theme (compile with Vite in Laravel).
- `design/prototype.html`: clickable prototype (self-contained).
- `design/prototype.src.html`: prototype source with theme placeholders.
- `public/brand/`: logo, icon.
