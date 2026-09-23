<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\BusinessClock;
use App\Support\Money;

/**
 * Stripe building blocks for billing after the deposit: one-off invoices
 * (go-live balance, early termination) and recurring prices.
 *
 * One-off charges use Stripe Invoices rather than bare PaymentIntents so a
 * failed charge comes with Stripe's hosted page where the client can update
 * their card or bank and pay, and Stripe's automatic retries apply.
 */
final class StripeBilling
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * Creates our invoice and a matching Stripe invoice, then tries to charge
     * the saved payment method. Returns our invoice with its status.
     */
    public function chargeOneOff(Customer $customer, InvoiceType $type, int $amountCents, string $description, ?int $contractId = null): Invoice
    {
        $invoice = Invoice::create([
            'number' => 'TMP-'.bin2hex(random_bytes(6)),
            'customer_id' => $customer->id,
            'contract_id' => $contractId,
            'type' => $type,
            'status' => InvoiceStatus::Scheduled,
            'amount_cents' => $amountCents,
            'due_on' => BusinessClock::today()->toDateString(),
            'agents_billed' => $type === InvoiceType::EarlyTermination ? $customer->agent_count : null,
        ]);
        $invoice->update(['number' => sprintf('INV-%s-%04d', now()->format('Y'), $invoice->id)]);

        $si = $this->stripe->post('invoices', [
            'customer' => $customer->stripe_customer_id,
            'collection_method' => 'charge_automatically',
            'auto_advance' => 'true',
            'default_payment_method' => $customer->stripe_payment_method_id,
            'description' => $description,
            'metadata' => ['invoice' => $invoice->number, 'type' => $type->value, 'customer_uuid' => $customer->uuid],
        ], "invoice-{$invoice->number}");

        $this->stripe->post('invoiceitems', [
            'customer' => $customer->stripe_customer_id,
            'invoice' => $si['id'],
            'amount' => $amountCents,
            'currency' => 'usd',
            'description' => $description,
        ], "invoiceitem-{$invoice->number}");

        $si = $this->stripe->post("invoices/{$si['id']}/finalize", [], "finalize-{$invoice->number}");
        $invoice->update(['stripe_invoice_id' => $si['id'], 'hosted_invoice_url' => $si['hosted_invoice_url'] ?? null, 'attempt_count' => 1]);

        try {
            $si = $this->stripe->post("invoices/{$si['id']}/pay", ['off_session' => 'true', 'expand' => ['payment_intent']], "pay-{$invoice->number}-1");
            $this->applyStripeInvoiceStatus($invoice, $si);
        } catch (StripeException $e) {
            // Declines come back as errors; the invoice stays open on Stripe for the client to pay.
            // failed_at stays empty: InvoiceEvents::failed() records it and sends the one email.
            $invoice->update(['status' => InvoiceStatus::Failed, 'failure_reason' => mb_substr($e->userMessage ?? $e->getMessage(), 0, 250)]);
        }

        return $invoice->fresh();
    }

    /** @param array<string, mixed> $si Stripe invoice */
    public function applyStripeInvoiceStatus(Invoice $invoice, array $si): void
    {
        $status = match (true) {
            ($si['status'] ?? null) === 'paid' => InvoiceStatus::Paid,
            ($si['status'] ?? null) === 'void' => InvoiceStatus::Void,
            (bool) ($si['payment_intent']['status'] ?? null) && $si['payment_intent']['status'] === 'processing' => InvoiceStatus::Processing,
            default => $invoice->status,
        };
        $invoice->update(array_filter([
            'status' => $status,
            'paid_at' => $status === InvoiceStatus::Paid ? now() : null,
            'hosted_invoice_url' => $si['hosted_invoice_url'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /** A monthly recurring Stripe price for an amount, created once and found again by lookup key. */
    public function monthlyPrice(string $kind, int $amountCents): string
    {
        $key = "rightally_{$kind}_monthly_{$amountCents}";
        $found = $this->stripe->get('prices', ['lookup_keys' => [$key], 'active' => 'true', 'limit' => 1]);
        if (! empty($found['data'][0]['id'])) {
            return $found['data'][0]['id'];
        }

        $name = $kind === 'agent' ? 'RightAlly per agent ('.Money::format($amountCents).'/agent/month)' : 'RightAlly platform fee ('.Money::format($amountCents).'/month)';
        $price = $this->stripe->post('prices', [
            'currency' => 'usd',
            'unit_amount' => $amountCents,
            'recurring' => ['interval' => 'month'],
            'product_data' => ['name' => $name],
            'lookup_key' => $key,
            'nickname' => $name,
        ], "price-{$key}");

        return $price['id'];
    }
}
