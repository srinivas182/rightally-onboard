<?php

namespace App\Services\Pricing;

use App\Models\Coupon;
use App\Models\Quote as CustomQuote;
use App\Services\Settings\SettingsService;
use App\Support\Money;

/**
 * The single place agreement prices are calculated. The onboarding page's
 * JavaScript mirrors this for live display; the server always recalculates.
 */
final class QuoteCalculator
{
    public function __construct(private readonly SettingsService $settings) {}

    public function quote(int $agentsEntered, ?Coupon $coupon = null, ?CustomQuote $custom = null, string $billing = 'month'): Quote
    {
        $p = $this->settings->group('pricing');
        $annualAvailable = (string) ($p['annual_enabled'] ?? '1') === '1' && (float) ($p['annual_discount_percent'] ?? 0) >= 0;
        $annualDiscount = (float) ($p['annual_discount_percent'] ?? 0);
        if ($custom) {
            // Negotiated pricing replaces Settings; coupons don't stack on custom quotes.
            $coupon = null;
            $p = [
                'setup_fee' => Money::toDollars((int) $custom->setup_fee_cents), 'deposit_percent' => (string) $custom->deposit_percent,
                'platform_fee' => Money::toDollars((int) $custom->platform_fee_cents), 'per_agent_fee' => Money::toDollars((int) $custom->per_agent_fee_cents),
                'min_agents' => (string) $custom->min_agents, 'go_live_days' => (string) $custom->go_live_days,
            ];
        }

        $setup = Money::toCents($p['setup_fee']);
        $percent = $coupon ? (float) $coupon->percent_off : 0.0;
        $discount = Money::percentOf($setup, $percent);
        $implementation = $setup - $discount;
        $depositPercent = (float) $p['deposit_percent'];
        $deposit = Money::percentOf($implementation, $depositPercent);
        $minAgents = (int) $p['min_agents'];
        $agentsEntered = max(1, $agentsEntered);

        return new Quote(
            setupFeeCents: $setup,
            coupon: $coupon,
            discountPercent: $percent,
            discountCents: $discount,
            implementationFeeCents: $implementation,
            depositPercent: $depositPercent,
            depositCents: $deposit,
            balanceCents: $implementation - $deposit,
            platformFeeCents: Money::toCents($p['platform_fee']),
            perAgentFeeCents: Money::toCents($p['per_agent_fee']),
            minAgents: $minAgents,
            agentsEntered: $agentsEntered,
            agentsBilled: max($minAgents, $agentsEntered),
            goLiveDays: (int) $p['go_live_days'],
            billingInterval: $billing === 'year' && $annualAvailable ? 'year' : 'month',
            annualDiscountPercent: $annualDiscount,
            annualAvailable: $annualAvailable,
        );
    }
}
