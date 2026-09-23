<?php

namespace App\Services\Pricing;

use App\Models\Coupon;
use App\Services\Settings\SettingsService;
use App\Support\Money;

/**
 * The single place agreement prices are calculated. The onboarding page's
 * JavaScript mirrors this for live display; the server always recalculates.
 */
final class QuoteCalculator
{
    public function __construct(private readonly SettingsService $settings) {}

    public function quote(int $agentsEntered, ?Coupon $coupon = null): Quote
    {
        $p = $this->settings->group('pricing');

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
        );
    }
}
