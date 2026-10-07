<?php

namespace App\Http\Requests\Onboarding;

/** Screen 1a "About you": who is signing, how to reach them, the brokerage name and the referral code. */
class AboutRequest extends DetailsRequest
{
    public function rules(): array
    {
        return array_intersect_key(parent::rules(), array_flip(['first_name', 'last_name', 'title', 'company_name', 'email', 'phone', 'phone_e164', 'coupon']));
    }
}
