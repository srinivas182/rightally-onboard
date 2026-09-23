<?php

namespace App\Services\Billing;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;

/** Suspends accounts with a balance or monthly payment unpaid for 30 days (agreement Section 6). */
final class SuspensionService
{
    public function __construct(private readonly EmailSender $email, private readonly AuditLogger $audit) {}

    public function run(): int
    {
        $cutoff = now()->subDays((int) config('rightally.suspend_after_days'));
        $count = 0;

        Invoice::with('customer')
            ->whereIn('type', [InvoiceType::Balance, InvoiceType::Monthly, InvoiceType::Annual])
            ->where('status', InvoiceStatus::Failed)
            ->where('failed_at', '<=', $cutoff)
            ->get()
            ->groupBy('customer_id')
            ->each(function ($invoices) use (&$count) {
                /** @var Customer $customer */
                $customer = $invoices->first()->customer;
                if (in_array($customer->status, [CustomerStatus::Suspended, CustomerStatus::Cancelled, CustomerStatus::Expired], true)) {
                    return;
                }
                $customer->update(['status' => CustomerStatus::Suspended, 'suspended_at' => now()]);
                $this->audit->log('customer.suspended', "{$customer->company_name} suspended: payment unpaid for 30 days", $customer, ['invoices' => $invoices->pluck('number')->all()], 'system');
                $this->email->toCustomer('account_suspended', $customer, $invoices->sortBy('failed_at')->first());
                $count++;
            });

        return $count;
    }
}
