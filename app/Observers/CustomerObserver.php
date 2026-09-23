<?php

namespace App\Observers;

use App\Enums\CustomerStatus as S;
use App\Models\Customer;
use App\Services\Integrations\TeamAlerts;
use App\Services\Integrations\Webhooks;

/**
 * Turns customer status changes into outgoing webhooks and team alerts, so
 * every path that changes a status (onboarding, billing, admin actions)
 * notifies consistently.
 */
class CustomerObserver
{
    public function __construct(private readonly Webhooks $webhooks, private readonly TeamAlerts $alerts) {}

    public function updated(Customer $customer): void
    {
        if ($customer->wasChanged('agent_count')) {
            $this->webhooks->emit('agents.changed', $customer, ['previous_agents_billed' => (int) $customer->getOriginal('agent_count')]);
        }
        if (! $customer->wasChanged('status')) {
            return;
        }

        $from = $customer->getOriginal('status');
        $from = $from instanceof S ? $from : S::tryFrom((string) $from);
        $to = $customer->status;

        $event = match (true) {
            $to === S::ContractSigned && $from === S::Draft => 'customer.signed',
            $to === S::AwaitingGoLive && $from === S::ContractSigned => 'customer.deposit_paid',
            $to === S::Live && in_array($from, [S::AwaitingGoLive, S::BalanceFailed], true) => 'customer.live',
            in_array($to, [S::PaymentFailed, S::BalanceFailed], true) => 'payment.failed',
            $to === S::Suspended => 'customer.suspended',
            $to === S::Paused => 'customer.paused',
            $from === S::Paused && $to === S::Live => 'customer.resumed',
            $from === S::Suspended || ($from === S::PaymentFailed && $to === S::Live) => 'customer.reactivated',
            $to === S::Cancelled => 'customer.cancelled',
            $to === S::Expired => 'customer.expired',
            default => null,
        };
        if (! $event) {
            return;
        }

        $this->webhooks->emit($event, $customer);
        $this->alerts->forCustomerEvent($event, $customer);
    }
}
