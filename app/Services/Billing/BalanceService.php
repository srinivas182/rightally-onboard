<?php

namespace App\Services\Billing;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Customer;
use App\Services\Email\EmailSender;
use App\Support\BusinessClock;
use Illuminate\Support\Collection;

/** The remaining implementation fee, charged automatically on the go-live date. */
final class BalanceService
{
    public const REMINDER_DAYS = 3;

    public function __construct(
        private readonly StripeBilling $billing,
        private readonly InvoiceEvents $events,
        private readonly EmailSender $email,
    ) {}

    /** Customers whose go-live date has arrived and who haven't been charged yet. */
    public function due(): Collection
    {
        return Customer::where('status', CustomerStatus::AwaitingGoLive)
            ->whereDate('go_live_date', '<=', BusinessClock::today()->toDateString())
            ->whereDoesntHave('invoices', fn ($q) => $q->where('type', InvoiceType::Balance))
            ->get();
    }

    public function charge(Customer $customer): void
    {
        $contract = $customer->contracts()->where('status', ContractStatus::Signed)->latest('signed_at')->firstOrFail();

        $invoice = $this->billing->chargeOneOff(
            $customer,
            InvoiceType::Balance,
            $contract->balance_cents,
            "RightAlly implementation balance, agreement {$contract->number}",
            $contract->id,
        );

        match ($invoice->status) {
            InvoiceStatus::Paid => $this->events->paid($invoice),
            InvoiceStatus::Failed => $this->events->failed($invoice),
            default => null, // bank debit processing: the webhook finishes it
        };
    }

    /** "You go live on …" three days before the charge. Sent once per go-live date. */
    public function sendReminders(): int
    {
        $target = BusinessClock::today()->addDays(self::REMINDER_DAYS)->toDateString();
        $sent = 0;

        Customer::where('status', CustomerStatus::AwaitingGoLive)->whereDate('go_live_date', $target)->get()
            ->filter(fn (Customer $c) => $c->balance_reminder_for?->toDateString() !== $target)
            ->each(function (Customer $c) use ($target, &$sent) {
                $this->email->toCustomer('balance_reminder', $c);
                $c->update(['balance_reminder_for' => $target]);
                $sent++;
            });

        return $sent;
    }
}
