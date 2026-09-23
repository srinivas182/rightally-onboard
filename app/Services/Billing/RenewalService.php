<?php

namespace App\Services\Billing;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\CustomerStatus;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\RenewalNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Services\Settings\SettingsService;
use App\Support\BusinessClock;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Agreements don't renew automatically (Section 5). 45 days before the term
 * ends we send a renewal agreement, remind at 15 days, and end the
 * subscription if it isn't signed by the end of the term.
 */
final class RenewalService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SubscriptionService $subscriptions,
        private readonly EmailSender $email,
        private readonly AuditLogger $audit,
    ) {}

    private const ACTIVE = [CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::Suspended, CustomerStatus::Paused];

    /** The agreement currently in force for each customer that is live. */
    private function currentAgreements()
    {
        return Contract::with('customer')
            ->where('status', ContractStatus::Signed)
            ->whereHas('customer', fn ($q) => $q->whereIn('status', self::ACTIVE))
            ->get()
            ->filter(fn (Contract $c) => ! Contract::where('previous_contract_id', $c->id)->where('status', ContractStatus::Signed)->exists());
    }

    public function sendOffers(): int
    {
        [$offerDays] = config('rightally.renewal_notice_days');
        $today = BusinessClock::today();
        $sent = 0;

        foreach ($this->currentAgreements() as $contract) {
            if (! $contract->ends_on || $today->lt(BusinessClock::date($contract->ends_on)->subDays($offerDays)) || $today->gt(BusinessClock::date($contract->ends_on))) {
                continue;
            }
            if (RenewalNotice::where('contract_id', $contract->id)->where('kind', 'offer')->exists()) {
                continue;
            }
            $renewal = $this->draftRenewal($contract);
            RenewalNotice::create(['contract_id' => $contract->id, 'kind' => 'offer', 'sent_at' => now()]);
            $this->email->toCustomer('renewal_offer', $contract->customer, null, [], $this->emailValues($renewal));
            $this->audit->log('contract.renewal_offered', "Renewal {$renewal->number} offered to {$contract->customer->company_name}", $renewal, null, 'system');
            $sent++;
        }

        return $sent;
    }

    public function sendReminders(): int
    {
        [, $reminderDays] = config('rightally.renewal_notice_days');
        $today = BusinessClock::today();
        $sent = 0;

        foreach ($this->currentAgreements() as $contract) {
            if (! $contract->ends_on || $today->lt(BusinessClock::date($contract->ends_on)->subDays($reminderDays)) || $today->gt(BusinessClock::date($contract->ends_on))) {
                continue;
            }
            $renewal = $this->pendingRenewal($contract);
            if (! $renewal || RenewalNotice::where('contract_id', $contract->id)->where('kind', 'reminder')->exists()) {
                continue;
            }
            RenewalNotice::create(['contract_id' => $contract->id, 'kind' => 'reminder', 'sent_at' => now()]);
            $this->email->toCustomer('renewal_reminder', $contract->customer, null, [], $this->emailValues($renewal));
            $sent++;
        }

        return $sent;
    }

    /** Ends agreements whose term is over without a signed renewal. */
    public function expire(): int
    {
        $today = BusinessClock::today();
        $count = 0;

        foreach ($this->currentAgreements() as $contract) {
            if (! $contract->ends_on || ! $today->gt(BusinessClock::date($contract->ends_on))) {
                continue;
            }
            $customer = $contract->customer;
            $this->subscriptions->cancelNow($customer);
            $contract->update(['status' => ContractStatus::Expired]);
            $this->pendingRenewal($contract)?->update(['status' => ContractStatus::Expired]);
            $customer->update(['status' => CustomerStatus::Expired, 'cancelled_at' => now(), 'cancel_reason' => 'Agreement ended without renewal']);
            $this->email->toCustomer('agreement_expired', $customer->fresh(), null, [], ['term_end_date' => $contract->ends_on->format('F j, Y')]);
            $this->audit->log('contract.expired', "Agreement {$contract->number} ended without renewal", $contract, null, 'system');
            $count++;
        }

        return $count;
    }

    /** On a renewal's start date: the old agreement is superseded and new prices apply. */
    public function activate(): int
    {
        $count = 0;
        Contract::with('customer', 'previous')
            ->where('type', ContractType::Renewal)->where('status', ContractStatus::Signed)
            ->whereDate('starts_on', '<=', BusinessClock::today()->toDateString())
            ->whereHas('previous', fn ($q) => $q->where('status', ContractStatus::Signed))
            ->get()
            ->each(function (Contract $renewal) use (&$count) {
                $previous = $renewal->previous;
                $previous->update(['status' => ContractStatus::Superseded]);
                if ($previous->platform_fee_cents !== $renewal->platform_fee_cents || $previous->per_agent_fee_cents !== $renewal->per_agent_fee_cents) {
                    $this->subscriptions->changePrices($renewal->customer, $renewal);
                }
                $this->audit->log('contract.renewal_started', "Renewal {$renewal->number} is now in force", $renewal, null, 'system');
                $count++;
            });

        return $count;
    }

    public function pendingRenewal(Contract $contract): ?Contract
    {
        return Contract::where('previous_contract_id', $contract->id)->where('status', ContractStatus::Draft)->latest('id')->first();
    }

    public function renewalLink(Contract $renewal): string
    {
        return URL::temporarySignedRoute('renewal.show', Carbon::parse($renewal->starts_on)->endOfDay(), ['contract' => $renewal->uuid]);
    }

    /** Renewal at Settings > Renewal pricing, or the original rates where those are blank. No set-up fee. */
    private function draftRenewal(Contract $previous): Contract
    {
        $existing = $this->pendingRenewal($previous);
        if ($existing) {
            return $existing;
        }
        $customer = $previous->customer;
        $renewalPlatform = $this->settings->get('renewal', 'platform_fee');
        $renewalAgent = $this->settings->get('renewal', 'per_agent_fee');
        $template = ContractTemplate::where('type', ContractType::Renewal)->where('is_active', true)->whereNotNull('published_at')->latest('published_at')->firstOrFail();
        $starts = $previous->ends_on->copy()->addDay();
        $company = $this->settings->group('company');
        $sig = $this->settings->group('signature');

        $renewal = Contract::create([
            'number' => 'TMP-'.Str::random(12),
            'customer_id' => $customer->id,
            'contract_template_id' => $template->id,
            'previous_contract_id' => $previous->id,
            'type' => ContractType::Renewal,
            'status' => ContractStatus::Draft,
            'setup_fee_cents' => 0, 'discount_percent' => 0, 'discount_cents' => 0, 'implementation_fee_cents' => 0,
            'deposit_percent' => 0, 'deposit_cents' => 0, 'balance_cents' => 0,
            'platform_fee_cents' => filled($renewalPlatform) ? Money::toCents($renewalPlatform) : $previous->platform_fee_cents,
            'per_agent_fee_cents' => filled($renewalAgent) ? Money::toCents($renewalAgent) : $previous->per_agent_fee_cents,
            'min_agents' => $previous->min_agents,
            'agent_count' => max($previous->min_agents, $customer->agent_count),
            'term_months' => 12,
            'starts_on' => $starts->toDateString(),
            'ends_on' => $starts->copy()->addMonthsNoOverflow(12)->subDay()->toDateString(),
            'company_legal_name' => $company['legal_name'], 'company_dba' => $company['dba'], 'company_address' => $company['address'],
            'company_signatory_name' => $sig['signatory_name'], 'company_signatory_title' => $sig['signatory_title'],
        ]);
        $renewal->update(['number' => sprintf('RA-%s-%04d', now()->format('Y'), $renewal->id)]);

        return $renewal;
    }

    /** @return array<string, string> */
    private function emailValues(Contract $renewal): array
    {
        return [
            'renewal_link' => $this->renewalLink($renewal),
            'term_end_date' => $renewal->previous->ends_on->format('F j, Y'),
        ];
    }
}
