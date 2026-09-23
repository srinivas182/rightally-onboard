<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\CouponCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Live coupon check for the onboarding form. */
class CouponCheckController extends Controller
{
    public function __invoke(Request $request, CouponCheck $check): JsonResponse
    {
        $code = strtoupper(trim((string) $request->input('code')));
        $result = $check->check($code);

        return response()->json([
            'valid' => (bool) $result['coupon'],
            'code' => $result['coupon']?->code,
            'percent' => $result['coupon'] ? (float) $result['coupon']->percent_off : 0,
            'message' => $result['message'] ?? ($result['coupon'] ? "{$code} applied. ".rtrim(rtrim(number_format((float) $result['coupon']->percent_off, 2), '0'), '.').'% off your implementation fee.' : 'Enter a coupon code, or leave this blank.'),
        ]);
    }
}
