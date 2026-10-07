<?php

namespace App\Services\Onboarding;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\CustomerStatus;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Coupon;
use App\Models\Customer;
use App\Services\Pricing\Quote;
use App\Services\Pricing\QuoteCalculator;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Step 1 of onboarding: create (or update) the customer and a draft
 * agreement holding the price snapshot the client will sign.
 */
final class OnboardingService
{
    public function __construct(
        private readonly QuoteCalculator $quotes,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $details  validated details (see DetailsRequest)
     * @param  array<string, mixed>  $tracking  source + utm
     */
    public function start(array $details, ?Coupon $coupon, array $tracking = [], ?\App\Models\Quote $custom = null): Customer
    {
        return DB::transaction(function () use ($details, $coupon, $tracking, $custom) {
            $quote = $this->quotes->quote((int) $details['agents'], $coupon, $custom, (string) ($details['billing'] ?? 'month'));

            $customer = Customer::create($this->customerAttributes($details, $quote) + [
                'status' => CustomerStatus::Draft,
                'source' => $tracking['source'] ?? null,
                'utm' => $tracking['utm'] ?? null,
                'onboarding_started_at' => now(),
                'quote_id' => $custom?->id,
                'onboarding_stage' => 'agreement',
            ]);

            $this->createDraftContract($customer, $quote, $custom);

            return $customer;
        });
    }

    /**
     * Screen 1a: saves the person as a lead (no address, agents or agreement yet).
     *
     * @param  array<string, mixed>  $about  validated AboutRequest data
     */
    public function startLead(array $about, ?Coupon $coupon, array $tracking = [], ?\App\Models\Quote $custom = null): Customer
    {
        $quote = $this->quotes->quote(1, $coupon, $custom);

        return Customer::create($this->aboutAttributes($about) + [
            'status' => CustomerStatus::Draft,
            'onboarding_stage' => 'brokerage',
            'source' => $tracking['source'] ?? null,
            'utm' => $tracking['utm'] ?? null,
            'onboarding_started_at' => now(),
            'quote_id' => $custom?->id,
            'coupon_id' => $custom ? null : $coupon?->id,
            'agent_count' => $quote->minAgents,
        ]);
    }

    /** Screen 1a again (going back): updates the person; re-prices the draft agreement if there is one. */
    public function updateAbout(Customer $customer, array $about, ?Coupon $coupon): Customer
    {
        return DB::transaction(function () use ($customer, $about, $coupon) {
            $customer->update($this->aboutAttributes($about) + ['coupon_id' => $customer->quote_id ? null : $coupon?->id]);
            $contract = $this->draftContract($customer);
            if ($contract) {
                $custom = $customer->quote_id ? \App\Models\Quote::find($customer->quote_id) : null;
                $quote = $this->quotes->quote((int) ($customer->agent_count_entered ?? $contract->agent_count), $custom ? null : $coupon, $custom, $contract->billing_interval);
                $contract->update($this->snapshot($quote) + $this->kind($custom));
            }

            return $customer->fresh();
        });
    }

    /**
     * Screen 1b: agents, address and billing. Creates (or re-prices) the draft agreement.
     *
     * @param  array<string, mixed>  $b  validated BrokerageRequest data
     */
    public function saveBrokerage(Customer $customer, array $b, ?Coupon $coupon): Customer
    {
        return DB::transaction(function () use ($customer, $b, $coupon) {
            $custom = $customer->quote_id ? \App\Models\Quote::find($customer->quote_id) : null;
            $quote = $this->quotes->quote((int) $b['agents'], $custom ? null : $coupon, $custom, (string) ($b['billing'] ?? 'month'));
            $customer->update([
                'street' => $b['street'], 'city' => $b['city'], 'state_code' => $b['state_code'], 'zip' => $b['zip'], 'country_code' => 'US',
                'agent_count' => $quote->agentsBilled, 'agent_count_entered' => $quote->agentsEntered,
                'onboarding_stage' => 'agreement',
            ]);
            $contract = $this->draftContract($customer);
            $contract
                ? $contract->update($this->snapshot($quote) + $this->kind($custom))
                : $this->createDraftContract($customer, $quote, $custom);

            return $customer->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function aboutAttributes(array $d): array
    {
        return [
            'first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'title' => $d['title'], 'company_name' => $d['company_name'],
            'email' => strtolower($d['email']), 'phone_e164' => $d['phone_e164'],
        ];
    }

    /** Update details before signing; the draft agreement is re-priced. */
    public function update(Customer $customer, array $details, ?Coupon $coupon): Customer
    {
        $contract = $this->draftContract($customer);
        if (! $contract) {
            throw new RuntimeException('This agreement has already been signed.');
        }

        return DB::transaction(function () use ($customer, $details, $coupon, $contract) {
            $custom = $customer->quote_id ? \App\Models\Quote::find($customer->quote_id) : null;
            $quote = $this->quotes->quote((int) $details['agents'], $custom ? null : $coupon, $custom, (string) ($details['billing'] ?? 'month'));
            $customer->update($this->customerAttributes($details, $quote));
            $contract->update($this->snapshot($quote) + $this->kind($custom));

            return $customer->fresh();
        });
    }

    public function draftContract(Customer $customer): ?Contract
    {
        return $customer->contracts()->where('status', ContractStatus::Draft)->latest('id')->first();
    }

    public function currentContract(Customer $customer): ?Contract
    {
        return $customer->contracts()->whereIn('type', [ContractType::Initial, ContractType::Existing])->latest('id')->first();
    }

    public function activeTemplate(ContractType $type = ContractType::Initial): ContractTemplate
    {
        return ContractTemplate::where('type', $type)->where('is_active', true)->whereNotNull('published_at')->latest('published_at')->first()
            ?? throw new RuntimeException('No published agreement template. Publish one in Contracts > Templates.');
    }

    /**
     * Agreement type, template and term: existing clients (custom quote marked "existing client")
     * get the subscription-only agreement with their own term and first charge date.
     *
     * @return array<string, mixed>
     */
    private function kind(?\App\Models\Quote $custom): array
    {
        if ($custom?->is_existing_client) {
            return [
                'type' => ContractType::Existing,
                'contract_template_id' => $this->activeTemplate(ContractType::Existing)->id,
                'term_months' => (int) ($custom->term_months ?? 12),
                'first_charge_on' => $custom->first_charge_on?->toDateString(),
            ];
        }

        return ['type' => ContractType::Initial, 'contract_template_id' => $this->activeTemplate()->id, 'term_months' => 12, 'first_charge_on' => null];
    }

    private function createDraftContract(Customer $customer, Quote $quote, ?\App\Models\Quote $custom = null): Contract
    {
        $contract = Contract::create($this->snapshot($quote) + $this->kind($custom) + [
            'number' => 'TMP-'.Str::random(12),
            'customer_id' => $customer->id,
            'status' => ContractStatus::Draft,
        ]);
        // Numbers come from the row id so two sign-ups can never collide.
        $contract->update(['number' => sprintf('RA-%s-%04d', now()->format('Y'), $contract->id)]);

        return $contract;
    }

    /** @return array<string, mixed> */
    private function snapshot(Quote $quote): array
    {
        $company = $this->settings->group('company');
        $sig = $this->settings->group('signature');

        return [
            'setup_fee_cents' => $quote->setupFeeCents,
            'coupon_id' => $quote->coupon?->id,
            'coupon_code' => $quote->coupon?->code,
            'discount_percent' => $quote->discountPercent,
            'discount_cents' => $quote->discountCents,
            'implementation_fee_cents' => $quote->implementationFeeCents,
            'deposit_percent' => $quote->depositPercent,
            'deposit_cents' => $quote->depositCents,
            'balance_cents' => $quote->balanceCents,
            'platform_fee_cents' => $quote->platformFeeCents,
            'per_agent_fee_cents' => $quote->perAgentFeeCents,
            'min_agents' => $quote->minAgents,
            'agent_count' => $quote->agentsBilled,
            'go_live_days' => $quote->goLiveDays,
            'billing_interval' => $quote->billingInterval,
            'annual_discount_percent' => $quote->billingInterval === 'year' ? $quote->annualDiscountPercent : 0,
            'company_legal_name' => $company['legal_name'],
            'company_dba' => $company['dba'],
            'company_address' => $company['address'],
            'company_signatory_name' => $sig['signatory_name'],
            'company_signatory_title' => $sig['signatory_title'],
        ];
    }

    /** @return array<string, mixed> */
    private function customerAttributes(array $d, Quote $quote): array
    {
        return [
            'first_name' => $d['first_name'],
            'last_name' => $d['last_name'],
            'title' => $d['title'],
            'company_name' => $d['company_name'],
            'email' => strtolower($d['email']),
            'phone_e164' => $d['phone_e164'],
            'street' => $d['street'],
            'city' => $d['city'],
            'state_code' => $d['state_code'],
            'zip' => $d['zip'],
            'country_code' => 'US',
            'agent_count' => $quote->agentsBilled,
            'agent_count_entered' => $quote->agentsEntered,
            'coupon_id' => $quote->coupon?->id,
        ];
    }
}
