<?php

namespace App\Services\Billing;

use App\Models\Admin;
use App\Models\Credit;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Services\Stripe\StripeClient;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/** Credit taken off the client's next charge (Stripe customer balance). */
final class CreditService
{
    public function __construct(private readonly StripeClient $stripe, private readonly EmailSender $email, private readonly AuditLogger $audit) {}

    public function credit(Customer $customer, int $cents, string $reason, Admin $admin, ?int $approvalId = null): Credit
    {
        if (! $customer->stripe_customer_id || $cents < 1) {
            throw ValidationException::withMessages(['amount' => 'This customer has no Stripe account to credit yet.']);
        }
        $txn = $this->stripe->post("customers/{$customer->stripe_customer_id}/balance_transactions", [
            'amount' => -$cents, 'currency' => 'usd', 'description' => mb_substr($reason, 0, 300),
        ], "credit-{$customer->id}-".now()->format('YmdHis')."-{$cents}");

        $credit = Credit::create(['customer_id' => $customer->id, 'amount_cents' => $cents, 'reason' => $reason,
            'stripe_balance_transaction_id' => $txn['id'] ?? null, 'created_by' => $admin->id, 'approval_id' => $approvalId]);
        $this->audit->log('customer.credit_issued', 'Credit of '.Money::format($cents)." for {$customer->company_name}: {$reason}", $customer, ['amount_cents' => $cents]);
        $this->email->toCustomer('credit_issued', $customer, null, [], ['credit_amount' => Money::format($cents), 'credit_reason' => $reason]);

        return $credit;
    }
}
