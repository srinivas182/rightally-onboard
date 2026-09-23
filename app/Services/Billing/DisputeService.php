<?php

namespace App\Services\Billing;

use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Services\Integrations\TeamAlerts;
use App\Support\BusinessClock;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Refunds and chargebacks reported by Stripe. Revenue excludes refunded
 * amounts and payments under an open dispute. New disputes alert the team
 * at once, because banks give a fixed deadline to respond.
 */
final class DisputeService
{
    public function __construct(private readonly EmailSender $email, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $charge */
    public function refunded(array $charge): void
    {
        $payment = $this->payment($charge['id'] ?? null, $charge['payment_intent'] ?? null);
        if (! $payment) {
            return;
        }
        $refunded = (int) ($charge['amount_refunded'] ?? 0);
        $payment->update([
            'refunded_cents' => min($refunded, $payment->amount_cents),
            'status' => $refunded >= $payment->amount_cents ? 'refunded' : $payment->status,
        ]);
        $this->audit->log('payment.refunded', 'Refund of '.Money::format($refunded)." on {$payment->invoice->number} ({$payment->customer->company_name})", $payment->invoice, ['refunded_cents' => $refunded], 'system');
    }

    /** @param array<string, mixed> $dispute */
    public function opened(array $dispute): void
    {
        $payment = $this->payment($dispute['charge'] ?? null, $dispute['payment_intent'] ?? null);
        if (! $payment || $payment->stripe_dispute_id === ($dispute['id'] ?? null)) {
            return;
        }
        $due = isset($dispute['evidence_details']['due_by']) ? Carbon::createFromTimestamp($dispute['evidence_details']['due_by']) : null;
        $payment->update(['status' => 'disputed', 'stripe_dispute_id' => $dispute['id'], 'dispute_status' => $dispute['status'] ?? 'needs_response', 'dispute_due_by' => $due]);

        $customer = $payment->customer;
        $this->audit->log('payment.disputed', "Chargeback opened by {$customer->company_name} on {$payment->invoice->number}", $payment->invoice, ['reason' => $dispute['reason'] ?? null], 'system');
        $this->email->toTeam('dispute_alert', [
            'company_name' => $customer->company_name,
            'amount' => Money::format((int) ($dispute['amount'] ?? $payment->amount_cents)),
            'invoice_number' => $payment->invoice->number,
            'dispute_reason' => str_replace('_', ' ', (string) ($dispute['reason'] ?? 'not given')),
            'respond_by' => $due ? $due->setTimezone(BusinessClock::timezone())->format('F j, Y') : 'as soon as possible',
            'stripe_link' => 'https://dashboard.stripe.com/'.(($dispute['livemode'] ?? false) ? '' : 'test/').'disputes/'.$dispute['id'],
            'customer_link' => route('admin.customers.show', $customer),
        ]);
        // Slack too, if set up (the email above always goes out).
        app(TeamAlerts::class)->send('chargebacks', "Chargeback: {$customer->company_name}",
            'Disputed '.Money::format((int) ($dispute['amount'] ?? $payment->amount_cents))." on {$payment->invoice->number}. Respond in Stripe".($due ? ' by '.$due->format('M j') : '').'.',
            route('admin.customers.show', $customer), email: false);
    }

    /** @param array<string, mixed> $dispute */
    public function closed(array $dispute): void
    {
        $payment = Payment::where('stripe_dispute_id', $dispute['id'] ?? '')->first();
        if (! $payment) {
            return;
        }
        $won = ($dispute['status'] ?? '') === 'won';
        $payment->update([
            'dispute_status' => $dispute['status'] ?? 'closed',
            'status' => $won ? 'succeeded' : 'refunded',
            'refunded_cents' => $won ? $payment->refunded_cents : $payment->amount_cents,
        ]);
        $this->audit->log('payment.dispute_closed', "Chargeback on {$payment->invoice->number} ".($won ? 'won' : 'lost'), $payment->invoice, null, 'system');
    }

    private function payment(?string $chargeId, ?string $paymentIntentId): ?Payment
    {
        return Payment::with('invoice', 'customer')->where('stripe_charge_id', $chargeId ?? '__none')->first()
            ?? ($paymentIntentId ? Payment::with('invoice', 'customer')->whereHas('invoice', fn ($q) => $q->where('stripe_payment_intent_id', $paymentIntentId))->first() : null);
    }
}
