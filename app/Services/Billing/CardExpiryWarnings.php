<?php

namespace App\Services\Billing;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\Email\EmailSender;
use App\Support\BusinessClock;
use Illuminate\Support\Carbon;

/** Emails clients 30 days before their saved card expires (once per card expiry). */
final class CardExpiryWarnings
{
    public const DAYS_BEFORE = 30;

    public function __construct(private readonly EmailSender $email) {}

    public function send(): int
    {
        $today = BusinessClock::today();
        $sent = 0;

        Customer::whereIn('status', [CustomerStatus::AwaitingGoLive, CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::BalanceFailed])
            ->where('payment_method_type', 'card')->whereNotNull('card_exp_year')->get()
            ->each(function (Customer $c) use ($today, &$sent) {
                $expiry = sprintf('%04d-%02d', $c->card_exp_year, $c->card_exp_month);
                $lastValidDay = Carbon::create($c->card_exp_year, $c->card_exp_month, 1, 0, 0, 0, BusinessClock::timezone())->endOfMonth()->startOfDay();
                if ($c->card_expiry_warned_for === $expiry || $today->lt($lastValidDay->copy()->subDays(self::DAYS_BEFORE))) {
                    return;
                }
                $this->email->toCustomer('card_expiring', $c, null, [], ['card_expiry' => sprintf('%02d/%d', $c->card_exp_month, $c->card_exp_year)]);
                $c->update(['card_expiry_warned_for' => $expiry]);
                $sent++;
            });

        return $sent;
    }
}
