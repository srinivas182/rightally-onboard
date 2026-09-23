# Integration spec for RightAlly platform sites

For the team that builds the RightAlly platform (each client's site, e.g. `https://sunline.rightally.io`).
The onboarding app talks to each site in two ways. Both use the **customer's agent API token**, created in
Admin > Customers > (customer) > Live site and agent API. Store it in the site's configuration as a secret.

## 1. Account status: onboarding app → your site
Sent when a customer goes live, is suspended, is reactivated, is paused or resumed, cancels, or their agreement ends.

```
POST {live_url}/api/rightally/account-status
Authorization: Bearer <agent API token>
Content-Type: application/json
X-RightAlly-Event: customer.suspended
X-RightAlly-Delivery: 1234
X-RightAlly-Signature: t=1790000000,v1=<hex>
```
Body (abridged):
```json
{
  "id": "5f0c…", "type": "customer.suspended", "created": "2026-10-23T13:00:00+00:00",
  "data": { "customer": {
    "id": "01a0…", "company_name": "Sunline Realty Group", "status": "suspended",
    "access": "suspended", "agents_billed": 8, "go_live_date": "2026-10-23" } }
}
```
**What the site must do**
- Verify the request: the Bearer token matches, and `v1 == HMAC_SHA256(token, t + "." + raw_body)`, with `t` within 5 minutes of now. Reject otherwise (401).
- Act on `data.customer.access`:
  - `active`: normal access.
  - `paused`: the client paused their subscription by agreement (up to 3 months). Block sign-in like `suspended`, with the message "Account paused until <date>". Keep all data.
  - `suspended`: block sign-in for agents and staff (show "Account suspended: contact your broker" and the broker a "Pay now" message). Keep all data.
  - `ended`: read-only or no access per your policy; keep data for export for 30 days (Agreement Section 10).
- Return 2xx quickly. Anything else is retried: 1 min, 5 min, 30 min, 2 h, 6 h.
- Deliveries can repeat: use `id` to ignore duplicates. Always apply the latest `created`.

## 2. Agent count: your site ↔ onboarding app
Either (or both):
- **Push**: `POST https://onboard.rightally.io/api/v1/agent-count`, `Authorization: Bearer <token>`, body `{"agents": 42}`. Response `{"agents_reported": 42, "agents_billed": 42}`. Rate limit 30/min. Send when the count changes, or daily.
- **Pull**: we call `GET {live_url}/api/rightally/agent-count` daily at 6:00 AM Miami time with the same Bearer token; reply `{"agents": 42}`.

"Agents" = active agent seats that should be billed. Billing never goes below the agreement minimum (usually 5).

## Webhooks to CRMs (for reference)
Admin > Settings > Alerts and integrations sends the same signed event format to any URL (Zapier, GoHighLevel inbound webhook). Events: `customer.signed`, `customer.deposit_paid`, `customer.live`, `payment.failed`, `customer.suspended`, `customer.reactivated`, `customer.cancelled`, `customer.expired`, `agents.changed`. Those endpoints are signed with their own secret (shown once when added).
