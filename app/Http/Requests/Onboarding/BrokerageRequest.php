<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Validation\Validator;

/** Screen 1b "Your brokerage": agents, business address and billing choice. */
class BrokerageRequest extends DetailsRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['state_code' => strtoupper((string) $this->input('state_code'))]);
    }

    public function rules(): array
    {
        return array_intersect_key(parent::rules(), array_flip(['agents', 'street', 'city', 'state_code', 'zip', 'billing']));
    }

    public function after(): array
    {
        return [fn (Validator $v) => null]; // no phone on this screen
    }
}
