<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\StripeEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\DepositService;
use App\Services\Billing\InvoiceEvents;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Receives Stripe webhooks. Each event is verified, stored once (Stripe may
 * send the same event more than once), then applied.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeClient $stripe, DepositService $deposits, InvoiceEvents $invoices): Response
    {
        $payload = $request->getContent();
        if (! WebhookSignature::verify($payload, $request->header('Stripe-Signature'), $stripe->webhookSecret())) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response('Invalid payload', 400);
        }

        $stored = StripeEvent::firstOrCreate(
            ['stripe_event_id' => $event['id']],
            ['type' => $event['type'], 'livemode' => (bool) ($event['livemode'] ?? false), 'payload' => $event],
        );
        if ($stored->processed_at) {
            return response('Already processed', 200);
        }

        try {
            $object = $event['data']['object'] ?? [];
            match (true) {
                str_starts_with($event['type'], 'payment_intent.') && ($object['metadata']['type'] ?? null) === 'deposit' => $deposits->syncFromStripe($object['id']),
                in_array($event['type'], ['invoice.paid', 'invoice.payment_failed'], true) => $this->invoiceEvent($event['type'], $object, $invoices),
                $event['type'] === 'customer.subscription.deleted' => $this->subscriptionEnded($object),
                default => null,
            };
            $stored->update(['processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            report($e);
            $stored->update(['error' => mb_substr($e->getMessage(), 0, 2000)]);

            return response('Error, will retry', 500); // Stripe retries with backoff
        }

        return response('OK', 200);
    }

    /** @param array<string, mixed> $si Stripe invoice */
    private function invoiceEvent(string $type, array $si, InvoiceEvents $events): void
    {
        $invoice = Invoice::where('stripe_invoice_id', $si['id'] ?? '')->first();

        if (! $invoice && ! empty($si['subscription'])) {
            $customer = Customer::where('stripe_subscription_id', $si['subscription'])->first();
            $invoice = $customer ? $events->monthlyFromStripe($customer, $si) : null;
        }
        if (! $invoice) {
            return; // not ours, or the $0 trial invoice
        }

        $type === 'invoice.paid' ? $events->paid($invoice, $si) : $events->failed($invoice, $si);
    }

    /** @param array<string, mixed> $sub */
    private function subscriptionEnded(array $sub): void
    {
        $customer = Customer::where('stripe_subscription_id', $sub['id'] ?? '')->first();
        if ($customer) {
            app(AuditLogger::class)->log('subscription.ended', "Stripe subscription ended for {$customer->company_name}", $customer, ['status' => $sub['status'] ?? null], 'system');
        }
    }
}
