<?php

namespace App\Services\Billing;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What happens when an invoice after the deposit is paid or fails, whether we
 * learn it from the immediate charge or from a Stripe webhook. Every method
 * is safe to call more than once for the same event.
 */
final class InvoiceEvents
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly EmailSender $email,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $si Stripe invoice (optional) */
    public function paid(Invoice $invoice, array $si = []): void
    {
        $applied = DB::transaction(function () use ($invoice, $si) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            if ($invoice->status === InvoiceStatus::Paid && $invoice->payments()->where('status', 'succeeded')->exists()) {
                return false;
            }
            $customer = $invoice->customer;
            $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => $invoice->paid_at ?? now(), 'failed_at' => null, 'failure_reason' => null]);
            Payment::updateOrCreate(
                ['invoice_id' => $invoice->id, 'stripe_charge_id' => $si['charge'] ?? ('in:'.($si['id'] ?? $invoice->number))],
                ['customer_id' => $customer->id, 'method' => $customer->payment_method_type ?? 'card', 'method_label' => $customer->payment_method_label,
                    'amount_cents' => (int) ($si['amount_paid'] ?? $invoice->amount_cents), 'status' => 'succeeded', 'settled_at' => now()],
            );
            $this->audit->log('payment.'.$invoice->type->value.'_paid', "{$customer->company_name} paid {$invoice->number}", $invoice, null, 'system');

            return true;
        });
        if (! $applied) {
            return;
        }

        $invoice->refresh();
        $customer = $invoice->customer;

        match ($invoice->type) {
            InvoiceType::Balance => $this->balancePaid($customer, $invoice),
            InvoiceType::Monthly => $this->email->toCustomer('monthly_receipt', $customer, $invoice),
            default => null,
        };
        $this->restoreIfNothingOverdue($customer->fresh());
    }

    /** @param array<string, mixed> $si */
    public function failed(Invoice $invoice, array $si = []): void
    {
        $first = DB::transaction(function () use ($invoice, $si) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            if ($invoice->status === InvoiceStatus::Paid) {
                return false;
            }
            $isFirst = $invoice->failed_at === null;
            $reason = $si['last_finalization_error']['message'] ?? $invoice->failure_reason ?? 'The payment was declined';
            $invoice->update([
                'status' => InvoiceStatus::Failed,
                'failed_at' => $invoice->failed_at ?? now(),
                'failure_reason' => mb_substr((string) $reason, 0, 250),
                'hosted_invoice_url' => $si['hosted_invoice_url'] ?? $invoice->hosted_invoice_url,
                'attempt_count' => max($invoice->attempt_count, (int) ($si['attempt_count'] ?? 1)),
            ]);

            return $isFirst;
        });
        if (! $first) {
            return; // Stripe retries; we email once per invoice
        }

        $invoice->refresh();
        $customer = $invoice->customer;
        $this->audit->log('payment.'.$invoice->type->value.'_failed', "Payment failed for {$customer->company_name} ({$invoice->number})", $invoice, ['reason' => $invoice->failure_reason], 'system');

        if ($invoice->type === InvoiceType::Balance) {
            $customer->update(['status' => CustomerStatus::BalanceFailed]);
            $this->email->toCustomer('balance_failed', $customer, $invoice);
        } elseif ($invoice->type === InvoiceType::Monthly) {
            if ($customer->status !== CustomerStatus::Suspended) {
                $customer->update(['status' => CustomerStatus::PaymentFailed]);
            }
            $this->email->toCustomer('payment_failed', $customer, $invoice);
        }
        $invoice->update(['payment_link_sent_at' => now()]);
    }

    /**
     * The bank asked the cardholder to confirm an automatic charge (e.g. 3-D Secure).
     * Treated like a failure for follow-up and suspension, with its own email and link.
     *
     * @param  array<string, mixed>  $si
     */
    public function actionRequired(Invoice $invoice, array $si = []): void
    {
        $first = DB::transaction(function () use ($invoice, $si) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            if ($invoice->status === InvoiceStatus::Paid || $invoice->failed_at !== null) {
                return false;
            }
            $invoice->update([
                'status' => InvoiceStatus::Failed, 'failed_at' => now(),
                'failure_reason' => 'Your bank needs you to confirm this payment',
                'hosted_invoice_url' => $si['hosted_invoice_url'] ?? $invoice->hosted_invoice_url,
                'payment_link_sent_at' => now(),
            ]);

            return true;
        });
        if (! $first) {
            return;
        }
        $invoice->refresh();
        $customer = $invoice->customer;
        if ($invoice->type === InvoiceType::Balance) {
            $customer->update(['status' => CustomerStatus::BalanceFailed]);
        } elseif ($invoice->type === InvoiceType::Monthly && $customer->status !== CustomerStatus::Suspended) {
            $customer->update(['status' => CustomerStatus::PaymentFailed]);
        }
        $this->audit->log('payment.action_required', "Bank confirmation needed for {$invoice->number} ({$customer->company_name})", $invoice, null, 'system');
        $this->email->toCustomer('payment_action_required', $customer, $invoice);
    }

    private function balancePaid(Customer $customer, Invoice $invoice): void
    {
        $contract = $invoice->contract ?? $customer->contracts()->where('status', ContractStatus::Signed)->latest('signed_at')->first();
        $customer->update(['status' => CustomerStatus::Live, 'live_at' => $customer->live_at ?? now()]);
        if ($contract) {
            $this->subscriptions->start($customer->fresh(), $contract);
        }
        $this->email->toCustomer('balance_paid', $customer->fresh(), $invoice);
    }

    /** Back to normal once no balance or monthly invoice is still unpaid. */
    private function restoreIfNothingOverdue(Customer $customer): void
    {
        if (! in_array($customer->status, [CustomerStatus::PaymentFailed, CustomerStatus::Suspended, CustomerStatus::BalanceFailed], true)) {
            return;
        }
        $stillOpen = $customer->invoices()->whereIn('type', [InvoiceType::Balance, InvoiceType::Monthly])->where('status', InvoiceStatus::Failed)->exists();
        if ($stillOpen) {
            return;
        }
        $status = $customer->live_at ? CustomerStatus::Live : CustomerStatus::AwaitingGoLive;
        $customer->update(['status' => $status, 'suspended_at' => null]);
        $this->audit->log('customer.restored', "{$customer->company_name} is back in good standing", $customer, null, 'system');
    }

    /**
     * A paid or failed Stripe invoice from the monthly subscription. Creates
     * our matching invoice the first time we hear about it.
     *
     * @param  array<string, mixed>  $si
     */
    public function monthlyFromStripe(Customer $customer, array $si): ?Invoice
    {
        $amount = (int) ($si['amount_due'] ?? 0);
        if ($amount === 0) {
            return null; // the $0 trial invoice Stripe creates when the subscription starts
        }
        $lines = $si['lines']['data'] ?? [];
        $agentLine = collect($lines)->first(fn ($l) => ($l['subscription_item'] ?? null) === $customer->stripe_agent_item_id);
        $period = $lines[0]['period'] ?? null;

        $tax = (int) ($si['tax'] ?? 0);
        $invoice = Invoice::firstOrCreate(['stripe_invoice_id' => $si['id']], [
            'tax_cents' => $tax,
            'number' => 'TMP-'.bin2hex(random_bytes(6)),
            'customer_id' => $customer->id,
            'contract_id' => $customer->contracts()->where('status', ContractStatus::Signed)->latest('signed_at')->value('id'),
            'type' => InvoiceType::Monthly,
            'status' => InvoiceStatus::Scheduled,
            'amount_cents' => $amount - $tax,
            'agents_billed' => $agentLine['quantity'] ?? $customer->agent_count,
            'period_start' => $period ? Carbon::createFromTimestamp($period['start'])->toDateString() : null,
            'period_end' => $period ? Carbon::createFromTimestamp($period['end'])->toDateString() : null,
            'due_on' => Carbon::createFromTimestamp($si['created'] ?? time())->toDateString(),
            'hosted_invoice_url' => $si['hosted_invoice_url'] ?? null,
        ]);
        if (str_starts_with($invoice->number, 'TMP-')) {
            $invoice->update(['number' => sprintf('INV-%s-%04d', now()->format('Y'), $invoice->id)]);
        }

        return $invoice;
    }
}
