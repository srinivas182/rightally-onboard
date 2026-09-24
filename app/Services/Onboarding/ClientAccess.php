<?php

namespace App\Services\Onboarding;

use App\Models\Customer;
use App\Services\Email\EmailSender;
use Illuminate\Support\Facades\Password;

/**
 * The right email to get a client into their account: a link to continue
 * onboarding (not finished), a create-password link (no password yet), or a
 * sign-in link (password set).
 */
final class ClientAccess
{
    public function __construct(private readonly ResumeLinks $resume, private readonly EmailSender $email) {}

    /** @return string 'resume' | 'set_password' | 'sign_in' | 'throttled' */
    public function send(Customer $customer): string
    {
        if (ResumeLinks::isIncomplete($customer)) {
            return $this->resume->send($customer) ? 'resume' : 'throttled';
        }
        if (! $customer->hasPassword()) {
            return Password::broker('customers')->sendResetLink(['email' => $customer->email]) === Password::RESET_THROTTLED ? 'throttled' : 'set_password';
        }
        $this->email->toCustomer('account_link', $customer);

        return 'sign_in';
    }
}
