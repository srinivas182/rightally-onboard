<?php

namespace App\Services\Billing;

use App\Enums\AgentCountSource;
use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Models\Admin;
use App\Models\AgentCountLog;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Services\Stripe\StripeException;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The number of agents billed each month: set by an admin, pushed by the
 * customer's RightAlly instance, or pulled from it daily. Never below the
 * agreement's minimum. Changes apply from the next monthly charge.
 */
final class AgentCountService
{
    public function __construct(private readonly SubscriptionService $subscriptions, private readonly AuditLogger $audit, private readonly EmailSender $email) {}

    /** @return int the count now billed */
    public function set(Customer $customer, int $reported, AgentCountSource $source, ?Admin $admin = null, ?string $note = null): int
    {
        $min = (int) ($customer->contracts()->where('status', ContractStatus::Signed)->latest('signed_at')->value('min_agents') ?? 5);
        $billed = max($min, $reported);
        $old = $customer->agent_count;

        $customer->forceFill(['agent_count_synced_at' => in_array($source, [AgentCountSource::ApiPush, AgentCountSource::ApiPull], true) ? now() : $customer->agent_count_synced_at]);
        if ($billed === $old) {
            $customer->save();

            return $billed;
        }

        $customer->agent_count = $billed;
        $customer->save();
        AgentCountLog::create([
            'customer_id' => $customer->id, 'old_count' => $old, 'new_count' => $billed, 'source' => $source,
            'admin_id' => $admin?->id, 'note' => $note ?? ($reported < $min ? "Reported {$reported}; billed at the {$min}-agent minimum" : null),
        ]);
        $this->audit->log('customer.agents_changed', "Agents billed for {$customer->company_name}: {$old} to {$billed}", $customer, ['source' => $source->value], $admin ? 'admin' : 'system');

        $this->notifyClient($customer, $old, $source);

        try {
            $this->subscriptions->setAgents($customer, $billed);
        } catch (StripeException $e) {
            report($e); // our record is right; the next change or a retry updates Stripe
        }

        return $billed;
    }

    /** Creates a new API token for the customer's RightAlly instance. Returned once in plain text. */
    public function newToken(Customer $customer): string
    {
        $token = 'ra_'.Str::random(40);
        $customer->forceFill(['agent_api_token_hash' => hash('sha256', $token), 'agent_api_token' => $token])->save();

        return $token;
    }

    public function findByToken(?string $token): ?Customer
    {
        return $token ? Customer::where('agent_api_token_hash', hash('sha256', $token))->first() : null;
    }

    /**
     * Daily pull: GET {live_url}/api/rightally/agent-count with the customer's
     * token; expects JSON {"agents": 42}.
     */
    public function pull(Customer $customer): ?int
    {
        if (! $customer->live_url || ! $customer->agent_api_token) {
            return null;
        }
        try {
            $res = Http::withToken($customer->agent_api_token)->acceptJson()->timeout(15)
                ->get(rtrim($customer->live_url, '/').'/api/rightally/agent-count');
            $agents = $res->json('agents');
            if (! $res->successful() || ! is_int($agents) || $agents < 0 || $agents > 100000) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $this->set($customer, $agents, AgentCountSource::ApiPull);
    }

    /**
     * Tells the client their new count and monthly fee before it's charged.
     * Admin changes always email; automatic syncs at most once a day.
     */
    private function notifyClient(Customer $customer, int $old, AgentCountSource $source): void
    {
        if (! in_array($customer->status, [CustomerStatus::AwaitingGoLive, CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::Suspended], true)) {
            return;
        }
        $automatic = in_array($source, [AgentCountSource::ApiPush, AgentCountSource::ApiPull], true);
        if ($automatic && $customer->agents_notified_at && $customer->agents_notified_at->gt(now()->subDay())) {
            return;
        }
        $next = $customer->go_live_date ? BusinessClock::date($customer->go_live_date)->addDays(30) : null;
        while ($next && $next->lt(BusinessClock::today())) {
            $next->addMonthNoOverflow();
        }
        $this->email->toCustomer('agents_changed', $customer, null, [], [
            'old_agent_count' => (string) $old,
            'effective_date' => $next?->format('F j, Y') ?? 'your next monthly charge',
        ]);
        $customer->forceFill(['agents_notified_at' => now()])->save();
    }
}
