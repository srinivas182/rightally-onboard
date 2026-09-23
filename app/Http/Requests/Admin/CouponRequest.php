<?php

namespace App\Http\Requests\Admin;

use App\Models\Coupon;
use App\Support\BusinessClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind can:menu.coupons
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'never_expires' => $this->boolean('never_expires'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        /** @var ?Coupon $coupon */
        $coupon = $this->route('coupon');
        $codeLocked = $coupon?->hasBeenUsed();

        return [
            'code' => $codeLocked ? ['nullable'] : ['required', 'regex:/^[A-Z0-9_-]{3,40}$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'name' => ['required', 'string', 'max:120'],
            'percent_off' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'never_expires' => ['boolean'],
            'expires_on' => $this->boolean('never_expires')
                ? ['nullable']
                : ['required', 'date', $coupon ? 'after_or_equal:2000-01-01' : 'after_or_equal:'.BusinessClock::today()->toDateString()],
            'max_uses' => ['nullable', 'integer', 'min:'.max(1, (int) $coupon?->times_used), 'max:1000000'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Use 3 to 40 letters, numbers, dashes or underscores, e.g. SPRING20.',
            'code.unique' => 'That code is already in use. Choose another.',
            'expires_on.required' => 'Choose an expiry date, or tick “Never expires”.',
            'expires_on.after_or_equal' => 'The expiry date can’t be in the past.',
            'percent_off.max' => 'The discount can’t be more than 100%.',
            'max_uses.min' => 'The limit can’t be lower than the number of times it has been used.',
        ];
    }

    /** @return array<string, mixed> */
    public function couponData(): array
    {
        $data = [
            'name' => $this->validated('name'),
            'percent_off' => $this->validated('percent_off'),
            'expires_on' => $this->boolean('never_expires') ? null : $this->validated('expires_on'),
            'max_uses' => $this->validated('max_uses'),
            'is_active' => $this->boolean('is_active'),
        ];
        if (! $this->route('coupon')?->hasBeenUsed()) {
            $data['code'] = $this->validated('code');
        }

        return $data;
    }
}
