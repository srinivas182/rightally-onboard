<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Models\CallBooking;
use App\Models\Coupon;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Percentage coupons on the implementation fee. Coupons are switched off
 * rather than deleted, because signed agreements refer to them.
 */
class CouponController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.coupons.index', [
            'coupons' => Coupon::orderByDesc('is_active')->orderByDesc('created_at')->get(),
            // Calls booked with each code (GoHighLevel calendar) and clients who went on to pay the deposit.
            'calls' => CallBooking::whereNotNull('coupon_code')->where('status', '!=', 'cancelled')
                ->selectRaw('coupon_code, count(*) as n')->groupBy('coupon_code')->pluck('n', 'coupon_code'),
            'onboarded' => Customer::whereNotNull('coupon_id')
                ->whereNotIn('status', [CustomerStatus::Draft, CustomerStatus::ContractSigned])
                ->selectRaw('coupon_id, count(*) as n')->groupBy('coupon_id')->pluck('n', 'coupon_id'),
        ]);
    }

    public function store(CouponRequest $request): RedirectResponse
    {
        $coupon = Coupon::create($request->couponData() + ['created_by' => $request->user('admin')->id]);
        $this->audit->log('coupon.created', "Created coupon {$coupon->code}", $coupon, $this->summary($coupon));

        return redirect()->route('admin.coupons.index')->with('success', "Coupon {$coupon->code} created.");
    }

    public function update(CouponRequest $request, Coupon $coupon): RedirectResponse
    {
        $coupon->fill($request->couponData());
        $changed = array_keys($coupon->getDirty());
        $coupon->save();

        if ($changed) {
            $this->audit->log('coupon.updated', "Updated coupon {$coupon->code}", $coupon, $this->summary($coupon) + ['changed' => $changed]);
        }

        return redirect()->route('admin.coupons.index')->with('success', $changed ? "Coupon {$coupon->code} saved." : 'No changes to save.');
    }

    /** @return array<string, mixed> */
    private function summary(Coupon $coupon): array
    {
        return [
            'percent_off' => (string) $coupon->percent_off,
            'expires_on' => $coupon->expires_on?->toDateString() ?? 'never',
            'max_uses' => $coupon->max_uses,
            'is_active' => $coupon->is_active,
        ];
    }
}
