<?php

namespace App\Services\Billing;

use App\Models\Admin;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Services\Stripe\StripeClient;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/** Refunds from the admin, through Stripe, with an email to the client. */
final class RefundService
{
    public function __construct(private readonly StripeClient $stripe, private readonly EmailSender $email, private readonly AuditLogger $audit) {}

    public function refundable(Invoice $invoice): int
    {
        $p = $this->payment($invoice);

        return $p ? max(0, $p->amount_cents - $p->refunded_cents) : 0;
    }

    public function refund(Invoice $invoice, int $cents, string $reason, Admin $admin): Payment
    {
        $payment = $this->payment($invoice);
        if (! $payment || $cents < 1 || $cents > $this->refundable($invoice)) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount up to '.Money::format($this->refundable($invoice)).'.']);
        }

        $this->stripe->post('refunds', [
            'charge' => $this->chargeId($invoice, $payment),
            'amount' => $cents,
            'reason' => 'requested_by_customer',
            'metadata' => ['invoice' => $invoice->number, 'admin' => $admin->email, 'note' => mb_substr($reason, 0, 200)],
        ], "refund-{$payment->id}-{$payment->refunded_cents}-{$cents}");

        $refunded = $payment->refunded_cents + $cents;
        $payment->update(['refunded_cents' => $refunded, 'status' => $refunded >= $payment->amount_cents ? 'refunded' : $payment->status]);
        $this->audit->log('payment.refund_issued', 'Refunded '.Money::format($cents)." on {$invoice->number}: {$reason}", $invoice, ['amount_cents' => $cents, 'reason' => $reason]);
        $this->email->toCustomer('refund_issued', $invoice->customer, $invoice, [], ['refund_amount' => Money::format($cents), 'refund_reason' => $reason]);

        return $payment->fresh();
    }

    private function payment(Invoice $invoice): ?Payment
    {
        return $invoice->payments()->whereIn('status', ['succeeded', 'refunded'])->latest('id')->first();
    }

    /** Stripe charge id: stored on the payment, or looked up from the Stripe invoice. */
    private function chargeId(Invoice $invoice, Payment $payment): string
    {
        if (str_starts_with((string) $payment->stripe_charge_id, 'ch_') || str_starts_with((string) $payment->stripe_charge_id, 'py_')) {
            return $payment->stripe_charge_id;
        }
        if ($invoice->stripe_invoice_id) {
            $si = $this->stripe->get('invoices/'.$invoice->stripe_invoice_id);
            if (! empty($si['charge'])) {
                return $si['charge'];
            }
        }
        throw ValidationException::withMessages(['amount' => 'This payment can’t be refunded from here. Refund it in the Stripe dashboard.']);
    }
}
