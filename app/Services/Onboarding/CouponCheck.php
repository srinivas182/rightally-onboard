<?php

namespace App\Services\Onboarding;

use App\Models\Coupon;
use App\Services\Settings\SettingsService;

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
        // Invitation only: there's no "continue without a coupon"; point them to a call instead.
        $invite = (string) app(SettingsService::class)->get('pricing', 'require_coupon') === '1';
        $next = $invite ? __('Ask the person who referred you for a current code, or book a call with us.') : __('You can continue without a coupon.');

        if (! $coupon || ! $coupon->is_active) {
            return ['coupon' => null, 'message' => __(':code isn’t a valid code. Check the spelling.', ['code' => $code]).' '.($invite ? __('No code? Book a call with us.') : __('Or continue without a coupon.'))];
        }
        if ($coupon->isExpired()) {
            return ['coupon' => null, 'message' => __(':code expired on :date.', ['code' => $code, 'date' => $coupon->expires_on->translatedFormat('M j, Y')]).' '.$next];
        }
        if (! $coupon->isUsable()) {
            return ['coupon' => null, 'message' => __(':code has reached its limit.', ['code' => $code]).' '.$next];
        }

        return ['coupon' => $coupon, 'message' => null];
    }
}
