<?php

namespace App\Services\Billing;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Models\Admin;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Support\BusinessClock;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Moving a customer's go-live date. The agreement allows RightAlly to extend
 * it with at least two days' notice, so the date locks two days before.
 */
final class GoLiveService
{
    public function __construct(private readonly EmailSender $email, private readonly AuditLogger $audit) {}

    public function lockDate(Customer $customer): ?Carbon
    {
        return $customer->go_live_date ? BusinessClock::date($customer->go_live_date)->subDays((int) config('rightally.go_live_lock_days')) : null;
    }

    public function canChange(Customer $customer): bool
    {
        return in_array($customer->status, [CustomerStatus::ContractSigned, CustomerStatus::AwaitingGoLive], true)
            && $customer->go_live_date
            && BusinessClock::today()->lt($this->lockDate($customer));
    }

    public function change(Customer $customer, string $newDate, ?Admin $admin = null): void
    {
        $date = Carbon::parse($newDate, BusinessClock::timezone())->startOfDay();
        $lockDays = (int) config('rightally.go_live_lock_days');

        if (! $this->canChange($customer)) {
            throw ValidationException::withMessages(['go_live_date' => "The go-live date can only be changed until {$lockDays} days before it."]);
        }
        if ($date->lt(BusinessClock::today()->addDays($lockDays))) {
            throw ValidationException::withMessages(['go_live_date' => "Choose a date at least {$lockDays} days from today, so the client gets the notice the agreement requires."]);
        }

        $old = $customer->go_live_date->toDateString();
        $customer->update(['go_live_date' => $date->toDateString(), 'balance_reminder_for' => null]);

        $contract = $customer->contracts()->where('status', ContractStatus::Signed)->latest('signed_at')->first();
        $contract?->forceFill([
            'starts_on' => $date->toDateString(),
            'ends_on' => $date->copy()->addMonthsNoOverflow($contract->term_months)->subDay()->toDateString(),
        ])->save();

        $this->audit->log('customer.go_live_changed', "Go-live date for {$customer->company_name} moved from {$old} to {$date->toDateString()}", $customer, ['from' => $old, 'to' => $date->toDateString()]);
        $this->email->toCustomer('go_live_changed', $customer->fresh());
    }
}
