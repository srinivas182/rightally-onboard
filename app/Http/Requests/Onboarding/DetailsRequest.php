<?php

namespace App\Http\Requests\Onboarding;

use App\Support\UsPhone;
use App\Support\UsStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'state_code' => strtoupper((string) $this->input('state_code')),
            'coupon' => strtoupper(trim((string) $this->input('coupon'))),
            'phone_e164' => UsPhone::toE164($this->input('phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:80'],
            'company_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc,filter', 'regex:/^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/', 'max:160'],
            'phone' => ['required', 'string', 'max:30'],
            'phone_e164' => ['nullable'],
            'agents' => ['required', 'integer', 'min:1', 'max:5000'],
            'street' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state_code' => ['required', Rule::in(UsStates::codes())],
            'zip' => ['required', 'regex:/^\d{5}(-\d{4})?$/'],
            'coupon' => ['nullable', 'string', 'max:40'],
            'billing' => ['nullable', 'in:month,year'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if (! $v->errors()->has('phone') && ! $this->input('phone_e164')) {
                $v->errors()->add('phone', 'Enter a 10-digit US phone number, for example (305) 555-0148.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'state_code.required' => __('Choose your state.'),
            'state_code.in' => __('Choose your state.'),
            'zip.regex' => __('Enter a 5-digit ZIP code, for example 33131.'),
            'agents.min' => __('Enter at least 1 agent.'),
            'agents.required' => __('Enter how many agents will use RightAlly.'),
            'email.regex' => __('Enter a valid email address, for example name@brokerage.com.'),
            'email.email' => __('Enter a valid email address, for example name@brokerage.com.'),
        ];
    }

    public function attributes(): array
    {
        return ['company_name' => __('brokerage or company name'), 'state_code' => __('state'), 'zip' => __('ZIP code')];
    }
}
