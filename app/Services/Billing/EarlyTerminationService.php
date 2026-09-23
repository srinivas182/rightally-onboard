<?php

namespace App\Services\Billing;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use Illuminate\Validation\ValidationException;

/**
 * Early termination (agreement Section 7): the monthly fees for the rest of
 * the minimum term become due at once, at the current agent count, and the
 * subscription ends.
 */
final class EarlyTerminationService
{
    public function __construct(
        private readonly StripeBilling $billing,
        private readonly SubscriptionService $subscriptions,
        private readonly InvoiceEvents $events,
        private readonly EmailSender $email,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{months: int, amount_cents: int, monthly_cents: int, unpaid_cents: int} */
    public function quote(Customer $customer): array
    {
        $contract = $this->contract($customer);
        $issued = $customer->invoices()->where('type', InvoiceType::Monthly)->where('contract_id', $contract->id)->count();
        $months = max(0, $contract->term_months - $issued);
        $monthly = $contract->monthlyFeeCents($customer->agent_count);

        // Monthly invoices already issued but unpaid stay due separately.
        $unpaid = (int) $customer->invoices()->where('status', InvoiceStatus::Failed)->sum('amount_cents');

        return ['months' => $months, 'amount_cents' => $months * $monthly, 'monthly_cents' => $monthly, 'unpaid_cents' => $unpaid];
    }

    public function terminate(Customer $customer, Admin $admin, string $reason): ?Invoice
    {
        if (! in_array($customer->status, [CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::Suspended], true)) {
            throw ValidationException::withMessages(['status' => 'Early termination applies to customers who are live.']);
        }
        $contract = $this->contract($customer);
        $quote = $this->quote($customer);

        $this->subscriptions->cancelNow($customer);
        $customer->update(['status' => CustomerStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 250)]);
        $contract->update(['status' => ContractStatus::Terminated]);
        $this->audit->log('customer.terminated', "Early termination for {$customer->company_name}: {$quote['months']} months due", $customer, $quote + ['reason' => $reason], 'admin');

        if ($quote['amount_cents'] === 0) {
            return null;
        }

        $invoice = $this->billing->chargeOneOff($customer, InvoiceType::EarlyTermination, $quote['amount_cents'],
            "RightAlly early termination: {$quote['months']} remaining months, agreement {$contract->number}", $contract->id);
        if ($invoice->status === InvoiceStatus::Paid) {
            $this->events->paid($invoice);
        }
        $this->email->toCustomer('early_termination', $customer->fresh(), $invoice);

        return $invoice;
    }

    private function contract(Customer $customer): Contract
    {
        return $customer->contracts()->where('status', ContractStatus::Signed)->latest('signed_at')->firstOrFail();
    }
}
