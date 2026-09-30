<?php

namespace App\Services\Stripe;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\StripeEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\DepositService;
use App\Services\Billing\DisputeService;
use App\Services\Billing\InvoiceEvents;
use App\Services\Billing\ServiceEnding;
use Throwable;

/**
 * Applies one Stripe event. Used by the webhook and by the nightly
 * reconciliation, which replays any event the webhook missed. Each event is
 * stored once and applied once.
 */
final class StripeEventHandler
{
    /** Event types we act on. Also the list to enable on the Stripe webhook. */
    public const TYPES = [
        'payment_intent.succeeded', 'payment_intent.processing', 'payment_intent.payment_failed',
        'invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required',
        'customer.subscription.deleted',
        'charge.refunded', 'charge.dispute.created', 'charge.dispute.closed',
    ];

    public function __construct(
        private readonly DepositService $deposits,
        private readonly InvoiceEvents $invoices,
        private readonly DisputeService $disputes,
        private readonly AuditLogger $audit,
    ) {}

    /** @return string 'processed' | 'duplicate' | 'ignored'. Throws when applying failed (so Stripe retries). */
    public function handle(array $event): string
    {
        $stored = StripeEvent::firstOrCreate(
            ['stripe_event_id' => $event['id']],
            ['type' => $event['type'], 'livemode' => (bool) ($event['livemode'] ?? false), 'payload' => $event],
        );
        if ($stored->processed_at) {
            return 'duplicate';
        }

        try {
            $this->apply($event['type'], (array) ($event['data']['object'] ?? []));
            $stored->update(['processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $stored->update(['error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }

        return in_array($event['type'], self::TYPES, true) ? 'processed' : 'ignored';
    }

    private function apply(string $type, array $object): void
    {
        match (true) {
            str_starts_with($type, 'payment_intent.') && ($object['metadata']['type'] ?? null) === 'deposit' => $this->deposits->syncFromStripe($object['id']),
            in_array($type, ['invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required'], true) => $this->invoiceEvent($type, $object),
            $type === 'customer.subscription.deleted' => $this->subscriptionEnded($object),
            $type === 'charge.refunded' => $this->disputes->refunded($object),
            $type === 'charge.dispute.created' => $this->disputes->opened($object),
            $type === 'charge.dispute.closed' => $this->disputes->closed($object),
            default => null,
        };
    }

    private function invoiceEvent(string $type, array $si): void
    {
        $invoice = Invoice::where('stripe_invoice_id', $si['id'] ?? '')->first();
        if (! $invoice && ! empty($si['subscription'])) {
            $customer = Customer::where('stripe_subscription_id', $si['subscription'])->first();
            $invoice = $customer ? $this->invoices->monthlyFromStripe($customer, $si) : null;
        }
        if (! $invoice) {
            return; // not ours, or the $0 trial invoice
        }

        match ($type) {
            'invoice.paid' => $this->invoices->paid($invoice, $si),
            'invoice.payment_action_required' => $this->invoices->actionRequired($invoice, $si),
            default => $this->invoices->failed($invoice, $si),
        };
    }

    private function subscriptionEnded(array $sub): void
    {
        $customer = Customer::where('stripe_subscription_id', $sub['id'] ?? '')->first();
        if ($customer) {
            $this->audit->log('subscription.ended', "Stripe subscription ended for {$customer->company_name}", $customer, ['status' => $sub['status'] ?? null], 'system');
            // Scheduled end reached, or cancelled directly in Stripe: the service ends here too.
            if (! $customer->end_type) {
                $customer->update(['end_type' => 'period_end', 'end_reason' => 'other', 'end_notes' => 'Subscription cancelled in Stripe', 'service_ends_on' => now()->toDateString()]);
            }
            app(ServiceEnding::class)->finalize($customer->fresh());
        }
    }
}
