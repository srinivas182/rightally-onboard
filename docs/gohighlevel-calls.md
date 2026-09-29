# Calls through GoHighLevel

Prospects book at **https://onboard.rightally.io/book-a-call** (your GoHighLevel calendar embedded on our site).
GoHighLevel handles availability, Google Calendar, Google Meet and reminders. Each booking reaches
**Admin > Calls** through a GoHighLevel workflow webhook, with the coupon and campaign.

## Links
| Share | Result |
|---|---|
| `/book-a-call` | Plain booking |
| `/book-a-call?coupon=NAR2026` | Coupon passed to the booking form |
| `/book-a-call?coupon=NAR2026&utm_campaign=fall` | Coupon and campaign |
| “Not ready yet? Book a call” on the first onboarding page | Keeps the coupon/campaign the visitor arrived with |

## One-time set-up in GoHighLevel
Menu names can differ slightly between GoHighLevel versions.

1. **Coupon field**: Settings > Custom Fields > Add field. Type *Single line*, object *Contact*, name **Coupon code**. Its key should be `contact.coupon_code`.
2. **Booking form**: in the form your calendar uses, add the **Coupon code** field. Set it to *Hidden* and allow it to be **populated by query string** with the parameter `coupon_code`. The booking page passes it automatically.
3. **Meeting**: Calendar settings > Location/Meeting: **Google Meet** (needs Google connected under Settings > Integrations).
4. **After booking** (optional): Calendar settings > Confirmation > Redirect to `https://onboard.rightally.io/book-a-call/thanks`.
5. **Workflow**: Automation > Workflows > New workflow.
   - Triggers: **Customer Booked Appointment** and **Appointment Status** (filter both to this calendar).
   - Action: **Webhook** (Custom Webhook), method **POST**, URL = the one shown in **Admin > Settings > Calls** (it contains a secret key; keep it private).
   - Custom Data: add key `coupon` with value `{{contact.coupon_code}}` (use the merge-field picker). Optionally `utm_campaign` with the contact’s campaign field.
   - Save and **Publish**.
6. **Test**: book a test call on `/book-a-call?coupon=TEST`. Within a minute it appears in **Admin > Calls** with coupon TEST. Cancel it in GoHighLevel and the status changes to Cancelled.

## What the app records
Time (and the client’s time zone), name, email, phone, brokerage, coupon, source/campaign, Meet link, status
(Booked, Completed = “showed”, No-show, Cancelled, **Onboarded** when the same email pays the onboarding deposit).
If a webhook can’t be read, GoHighLevel shows the error on the workflow’s execution log; the last payload is saved on the call for troubleshooting.
