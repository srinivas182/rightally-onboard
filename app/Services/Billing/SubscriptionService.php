<?php

namespace App\Services\Billing;

use App\Models\Contract;
use App\Models\Customer;
use App\Services\Stripe\StripeClient;
use App\Support\BusinessClock;
use Illuminate\Support\Carbon;

/**
 * The monthly Stripe subscription: a platform-fee item plus a per-agent item
 * whose quantity is the billed agent count. Starts when the balance is paid;
 * the first charge is 30 days after the go-live date.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StripeBilling $billing,
        private readonly TaxService $tax,
    ) {}

    public function start(Customer $customer, Contract $contract): void
    {
        if ($customer->stripe_subscription_id) {
            return;
        }

        $platformPrice = $this->billing->monthlyPrice('platform', $contract->platform_fee_cents);
        $agentPrice = $this->billing->monthlyPrice('agent', $contract->per_agent_fee_cents);
        $firstCharge = $this->firstChargeAt($customer);

        $sub = $this->stripe->post('subscriptions', array_filter([
            'customer' => $customer->stripe_customer_id,
            'default_payment_method' => $customer->stripe_payment_method_id,
            'items' => [
                ['price' => $platformPrice, 'quantity' => 1],
                ['price' => $agentPrice, 'quantity' => max($contract->min_agents, $customer->agent_count)],
            ],
            // Trial until the first charge date: nothing is billed before go-live + 30 days.
            'trial_end' => $firstCharge->isFuture() ? $firstCharge->getTimestamp() : null,
            'proration_behavior' => 'none',
            'off_session' => 'true',
            'metadata' => ['customer_uuid' => $customer->uuid, 'agreement' => $contract->number],
        ] + $this->tax->automaticTax()), "subscription-{$customer->uuid}");

        $agentItem = collect($sub['items']['data'] ?? [])->first(fn ($i) => ($i['price']['id'] ?? null) === $agentPrice);
        $customer->forceFill(['stripe_subscription_id' => $sub['id'], 'stripe_agent_item_id' => $agentItem['id'] ?? null])->save();
    }

    /** New agent count takes effect from the next monthly charge, without pro-rating. */
    public function setAgents(Customer $customer, int $billed): void
    {
        if (! $customer->stripe_agent_item_id) {
            return;
        }
        $this->stripe->post('subscription_items/'.$customer->stripe_agent_item_id, ['quantity' => $billed, 'proration_behavior' => 'none']);
    }

    /** Switches the subscription to new monthly prices (used when a renewal with different rates starts). */
    public function changePrices(Customer $customer, Contract $contract): void
    {
        if (! $customer->stripe_subscription_id) {
            return;
        }
        $sub = $this->stripe->get('subscriptions/'.$customer->stripe_subscription_id);
        $items = $sub['items']['data'] ?? [];
        $platformItem = collect($items)->first(fn ($i) => $i['id'] !== $customer->stripe_agent_item_id);
        $agentPrice = $this->billing->monthlyPrice('agent', $contract->per_agent_fee_cents);

        $this->stripe->post('subscriptions/'.$customer->stripe_subscription_id, [
            'items' => array_values(array_filter([
                $platformItem ? ['id' => $platformItem['id'], 'price' => $this->billing->monthlyPrice('platform', $contract->platform_fee_cents), 'quantity' => 1] : null,
                ['id' => $customer->stripe_agent_item_id, 'price' => $agentPrice, 'quantity' => max($contract->min_agents, $customer->agent_count)],
            ])),
            'proration_behavior' => 'none',
        ]);
    }

    public function cancelNow(Customer $customer): void
    {
        if ($customer->stripe_subscription_id) {
            $this->stripe->delete('subscriptions/'.$customer->stripe_subscription_id);
        }
    }

    /** 30 days after the go-live date, 9:00 AM Miami time. */
    public function firstChargeAt(Customer $customer): Carbon
    {
        return Carbon::parse($customer->go_live_date->toDateString().' 09:00', BusinessClock::timezone())->addDays(30);
    }
}
