<?php

namespace App\Services\Onboarding;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\Email\EmailSender;

/**
 * Clients who signed but didn't pay the deposit get a reminder 24 hours and
 * 3 days after signing, then no more (the team sees them on the dashboard).
 */
final class DepositReminders
{
    /** Hours after signing for reminder 1, 2. */
    public const SCHEDULE = [24, 72];

    public function __construct(private readonly EmailSender $email) {}

    public function send(): int
    {
        $sent = 0;
        Customer::where('status', CustomerStatus::ContractSigned)
            ->where('deposit_reminders_sent', '<', count(self::SCHEDULE))
            ->with(['contracts' => fn ($q) => $q->where('status', ContractStatus::Signed)])
            ->get()
            ->each(function (Customer $c) use (&$sent) {
                $signedAt = $c->contracts->max('signed_at');
                $due = self::SCHEDULE[$c->deposit_reminders_sent] ?? null;
                if (! $signedAt || $due === null || now()->lt($signedAt->copy()->addHours($due))) {
                    return;
                }
                $this->email->toCustomer('deposit_reminder', $c);
                $c->update(['deposit_reminders_sent' => $c->deposit_reminders_sent + 1, 'deposit_reminded_at' => now()]);
                $sent++;
            });

        return $sent;
    }
}
