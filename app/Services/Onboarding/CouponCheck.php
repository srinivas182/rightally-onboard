<?php

namespace App\Services\Onboarding;

use App\Models\Coupon;

/**
 * Looks up a coupon code and explains, in client-facing words, why it can't
 * be used when that's the case.
 */
final class CouponCheck
{
    /** @return array{coupon: ?Coupon, message: ?string} */
    public function check(?string $code): array
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return ['coupon' => null, 'message' => null];
        }

        $coupon = Coupon::where('code', $code)->first();

        if (! $coupon || ! $coupon->is_active) {
            return ['coupon' => null, 'message' => "{$code} isn’t a valid code. Check the spelling, or continue without a coupon."];
        }
        if ($coupon->isExpired()) {
            return ['coupon' => null, 'message' => "{$code} expired on {$coupon->expires_on->format('M j, Y')}. You can continue without a coupon."];
        }
        if (! $coupon->isUsable()) {
            return ['coupon' => null, 'message' => "{$code} has reached its limit. You can continue without a coupon."];
        }

        return ['coupon' => $coupon, 'message' => null];
    }
}
