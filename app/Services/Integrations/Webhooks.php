<?php

namespace App\Services\Integrations;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Jobs\DeliverWebhook;
use App\Models\Customer;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\UsPhone;
use Illuminate\Support\Str;

/**
 * Outgoing webhooks: customer lifecycle events to configured endpoints (CRM,
 * Zapier), and account-status changes to the customer's own RightAlly site so
 * it can suspend or restore access.
 *
 * Every request is signed: header X-RightAlly-Signature "t=<unix>,v1=<hex>"
 * where v1 = HMAC-SHA256(secret, "<t>.<raw body>").
 */
final class Webhooks
{
    public const EVENTS = [
        'customer.lead' => 'New lead (finished “About you”)',
        'customer.signed' => 'Agreement signed',
        'customer.deposit_paid' => 'Deposit paid (or bank payment started)',
        'customer.live' => 'Went live (balance paid)',
        'payment.failed' => 'A payment failed',
        'customer.suspended' => 'Account suspended',
        'customer.paused' => 'Subscription paused',
        'customer.resumed' => 'Subscription resumed after a pause',
        'customer.reactivated' => 'Account back in good standing',
        'customer.cancelled' => 'Cancelled (early termination)',
        'customer.expired' => 'Agreement ended without renewal',
        'agents.changed' => 'Agents billed changed',
    ];

    /** Events the customer's own RightAlly site receives (to allow or block access). */
    public const SITE_EVENTS = ['customer.live', 'customer.suspended', 'customer.reactivated', 'customer.paused', 'customer.resumed', 'customer.cancelled', 'customer.expired'];

    public const SITE_PATH = '/api/rightally/account-status';

    public function emit(string $event, Customer $customer, array $extra = []): int
    {
        $payload = $this->payload($event, $customer, $extra);
        $count = 0;

        foreach (WebhookEndpoint::where('is_active', true)->get() as $endpoint) {
            if ($endpoint->wants($event)) {
                $this->queue($payload, $endpoint->url, $endpoint->id, $customer->id);
                $count++;
            }
        }

        if (in_array($event, self::SITE_EVENTS, true) && $customer->live_url && $customer->agent_api_token) {
            $this->queue($payload, rtrim($customer->live_url, '/').self::SITE_PATH, null, $customer->id);
            $count++;
        }

        return $count;
    }

    /** Sends a sample event to one endpoint, to test the connection. */
    public function test(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $payload = ['id' => (string) Str::uuid(), 'type' => 'test.ping', 'created' => now()->toIso8601String(), 'data' => ['message' => 'Test from RightAlly onboarding']];

        return $this->queue($payload, $endpoint->url, $endpoint->id, null);
    }

    /** "active" | "suspended" | "ended": what the customer's site should allow. */
    public static function access(Customer $customer): string
    {
        return match ($customer->status) {
            CustomerStatus::Suspended => 'suspended',
            CustomerStatus::Paused => 'paused',
            CustomerStatus::Cancelled, CustomerStatus::Expired => 'ended',
            default => 'active',
        };
    }

    private function queue(array $payload, string $url, ?int $endpointId, ?int $customerId): WebhookDelivery
    {
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpointId, 'customer_id' => $customerId, 'event' => $payload['type'],
            'url' => $url, 'payload' => $payload, 'status' => 'pending',
        ]);
        DeliverWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }

    private function payload(string $event, Customer $customer, array $extra): array
    {
        $contract = $customer->contracts()->where('status', ContractStatus::Signed)->latest('signed_at')->first();

        return [
            'id' => (string) Str::uuid(),
            'type' => $event,
            'created' => now()->toIso8601String(),
            'data' => [
                'customer' => [
                    'id' => $customer->uuid,
                    'company_name' => $customer->company_name,
                    'contact_name' => $customer->fullName(),
                    'contact_title' => $customer->title,
                    'email' => $customer->email,
                    'phone' => UsPhone::format($customer->phone_e164),
                    'city' => $customer->city,
                    'state' => $customer->state_code,
                    'status' => $customer->status->value,
                    'access' => self::access($customer),
                    'agents_billed' => $customer->agent_count,
                    'monthly_fee' => $contract ? round($contract->monthlyFeeCents($customer->agent_count) / 100, 2) : null,
                    'billing_interval' => $contract?->billing_interval,
                    'subscription_fee' => $contract ? round($contract->recurringFeeCents($customer->agent_count) / 100, 2) : null,
                    'go_live_date' => $customer->go_live_date?->toDateString(),
                    'live_url' => $customer->live_url,
                    'source' => $customer->source,
                    'agreement_number' => $contract?->number,
                    'admin_url' => route('admin.customers.show', $customer),
                ],
            ] + $extra,
        ];
    }
}
