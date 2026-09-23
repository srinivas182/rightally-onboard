<?php

namespace App\Services\Pricing;

use App\Models\Coupon;

/**
 * Everything a client pays under a new agreement, in cents.
 * Built by QuoteCalculator from current Settings, the coupon and agent count.
 */
final readonly class Quote
{
    public function __construct(
        public int $setupFeeCents,
        public ?Coupon $coupon,
        public float $discountPercent,
        public int $discountCents,
        public int $implementationFeeCents,
        public float $depositPercent,
        public int $depositCents,
        public int $balanceCents,
        public int $platformFeeCents,
        public int $perAgentFeeCents,
        public int $minAgents,
        public int $agentsEntered,
        public int $agentsBilled,
        public int $goLiveDays,
    ) {}

    public function monthlyFeeCents(): int
    {
        return $this->platformFeeCents + $this->agentsBilled * $this->perAgentFeeCents;
    }

    /** Values the onboarding page's JavaScript needs to recalculate live. */
    public function forBrowser(): array
    {
        return [
            'setup' => $this->setupFeeCents,
            'discountPercent' => $this->discountPercent,
            'depositPercent' => $this->depositPercent,
            'platform' => $this->platformFeeCents,
            'perAgent' => $this->perAgentFeeCents,
            'minAgents' => $this->minAgents,
        ];
    }
}
