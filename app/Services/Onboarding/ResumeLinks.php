<?php

namespace App\Services\Onboarding;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\Email\EmailSender;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * "Continue where you left off": a signed link (7 days) to the next
 * unfinished onboarding step, for clients who stopped part-way.
 */
final class ResumeLinks
{
    public const DAYS = 7;

    public function __construct(private readonly EmailSender $email) {}

    public static function isIncomplete(Customer $customer): bool
    {
        return in_array($customer->status, [CustomerStatus::Draft, CustomerStatus::ContractSigned], true);
    }

    /** @return array{route: string, label: string} */
    public static function nextStep(Customer $customer): array
    {
        return $customer->status === CustomerStatus::ContractSigned
            ? ['route' => 'onboarding.payment', 'label' => 'add your payment method and pay the deposit']
            : ['route' => 'onboarding.agreement', 'label' => 'review and sign your agreement'];
    }

    public function link(Customer $customer): string
    {
        return URL::temporarySignedRoute(self::nextStep($customer)['route'], now()->addDays(self::DAYS), ['customer' => $customer->uuid]);
    }

    /** Emails the resume link. Limited to 3 an hour per customer so the form can't be used to spam. */
    public function send(Customer $customer): bool
    {
        $key = "resume-link:{$customer->id}";
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return false;
        }
        RateLimiter::hit($key, 3600);
        $this->email->toCustomer('resume_onboarding', $customer, null, [], [
            'resume_link' => $this->link($customer),
            'next_step' => self::nextStep($customer)['label'],
        ]);

        return true;
    }
}
