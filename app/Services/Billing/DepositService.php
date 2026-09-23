<?php

namespace App\Services\Billing;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Services\Stripe\StripeClient;
use App\Support\BusinessClock;
use App\Support\UsStates;
use Illuminate\Support\Facades\DB;

/**
 * The deposit charged at the end of onboarding (step 4).
 *
 * One Stripe PaymentIntent per deposit invoice, created with
 * setup_future_usage=off_session so the card or bank account is saved for
 * the go-live balance and monthly charges.
 */
final class DepositService
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly EmailSender $email,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Returns the deposit invoice and the client secret for Stripe's Payment Element.
     *
     * @return array{invoice: Invoice, client_secret: string}
     */
    public function prepare(Customer $customer, Contract $contract): array
    {
        $customerId = $this->ensureStripeCustomer($customer);
        $invoice = $this->depositInvoice($customer, $contract);

        if ($invoice->stripe_payment_intent_id) {
            $pi = $this->stripe->get('payment_intents/'.$invoice->stripe_payment_intent_id);
            if (in_array($pi['status'], ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                return ['invoice' => $invoice, 'client_secret' => $pi['client_secret']];
            }
        }

        $pi = $this->stripe->post('payment_intents', [
            'amount' => $invoice->amount_cents,
            'currency' => 'usd',
            'customer' => $customerId,
            'setup_future_usage' => 'off_session',
            'payment_method_types' => ['card', 'us_bank_account'],
            'payment_method_options' => ['us_bank_account' => ['verification_method' => 'automatic']],
            'description' => "RightAlly deposit, agreement {$contract->number}",
            'receipt_email' => $customer->email,
            'metadata' => ['invoice' => $invoice->number, 'customer_uuid' => $customer->uuid, 'type' => 'deposit'],
        ], "deposit-{$invoice->id}-".($invoice->attempt_count + 1));

        $invoice->update(['stripe_payment_intent_id' => $pi['id'], 'attempt_count' => $invoice->attempt_count + 1]);

        return ['invoice' => $invoice, 'client_secret' => $pi['client_secret']];
    }

    /** Re-reads a PaymentIntent from Stripe and applies it (return URL and webhooks). */
    public function syncFromStripe(string $paymentIntentId): ?Invoice
    {
        $pi = $this->stripe->get('payment_intents/'.$paymentIntentId, ['expand' => ['payment_method', 'latest_charge.balance_transaction']]);

        return $this->apply($pi);
    }

    /** @param array<string, mixed> $pi */
    public function apply(array $pi): ?Invoice
    {
        $invoice = Invoice::where('stripe_payment_intent_id', $pi['id'] ?? '')->where('type', InvoiceType::Deposit)->first();
        if (! $invoice) {
            return null;
        }

        return match ($pi['status'] ?? null) {
            'succeeded' => $this->markPaid($invoice, $pi),
            'processing' => $this->markProcessing($invoice, $pi),
            'requires_payment_method', 'canceled' => $this->markFailed($invoice, $pi),
            default => $invoice,
        };
    }

    // ---- state changes ------------------------------------------------

    private function markPaid(Invoice $invoice, array $pi): Invoice
    {
        $sendWelcome = false;
        $sendReceipt = false;

        DB::transaction(function () use ($invoice, $pi, &$sendWelcome, &$sendReceipt) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            if ($invoice->status === InvoiceStatus::Paid) {
                return; // already applied (webhook and return URL can both arrive)
            }
            $wasProcessing = $invoice->status === InvoiceStatus::Processing;
            $customer = $invoice->customer;
            [$method, $label] = $this->methodLabel($pi);

            $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now(), 'failed_at' => null, 'failure_reason' => null]);
            $this->recordPayment($invoice, $pi, 'succeeded', $method, $label);
            $this->saveDefaultPaymentMethod($customer, $pi, $method, $label);
            if (in_array($customer->status, [CustomerStatus::Draft, CustomerStatus::ContractSigned], true)) {
                $customer->update(['status' => CustomerStatus::AwaitingGoLive]);
            }
            $this->audit->log('payment.deposit_paid', "{$customer->company_name} paid the deposit ({$label})", $invoice, null, 'system');

            // Card: welcome email now. Bank: welcome went out when the debit started; send the receipt now it has cleared.
            $sendWelcome = ! $wasProcessing;
            $sendReceipt = $wasProcessing;
        });

        $invoice->refresh();
        if ($sendWelcome) {
            $this->sendWelcome($invoice);
        }
        if ($sendReceipt) {
            $this->email->toCustomer('deposit_receipt', $invoice->customer, $invoice);
        }

        return $invoice;
    }

    private function markProcessing(Invoice $invoice, array $pi): Invoice
    {
        $changed = DB::transaction(function () use ($invoice, $pi) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            if (in_array($invoice->status, [InvoiceStatus::Processing, InvoiceStatus::Paid], true)) {
                return false;
            }
            [$method, $label] = $this->methodLabel($pi);
            $invoice->update(['status' => InvoiceStatus::Processing]);
            $this->recordPayment($invoice, $pi, 'processing', $method, $label);
            $this->saveDefaultPaymentMethod($invoice->customer, $pi, $method, $label);
            $this->audit->log('payment.deposit_processing', "{$invoice->customer->company_name} started a bank payment for the deposit", $invoice, null, 'system');

            return true;
        });

        $invoice->refresh();
        if ($changed) {
            $this->sendWelcome($invoice);
        }

        return $invoice;
    }

    private function markFailed(Invoice $invoice, array $pi): Invoice
    {
        if ($invoice->status === InvoiceStatus::Paid) {
            return $invoice;
        }
        $reason = $pi['last_payment_error']['message'] ?? null;
        if (! $reason && ($pi['status'] ?? '') !== 'canceled' && $invoice->status !== InvoiceStatus::Processing) {
            return $invoice; // not attempted yet: nothing failed
        }
        $invoice->update(['status' => InvoiceStatus::Failed, 'failed_at' => now(), 'failure_reason' => $reason ? mb_substr($reason, 0, 250) : 'Payment was not completed']);
        Payment::where('invoice_id', $invoice->id)->where('status', 'processing')->update(['status' => 'failed']);
        $this->audit->log('payment.deposit_failed', "Deposit payment failed for {$invoice->customer->company_name}", $invoice, ['reason' => $reason], 'system');

        return $invoice;
    }

    // ---- helpers ------------------------------------------------------

    private function sendWelcome(Invoice $invoice): void
    {
        $contract = $invoice->contract;
        $attachments = $contract?->pdf_path ? [['path' => $contract->pdf_path, 'name' => "RightAlly-Agreement-{$contract->number}.pdf"]] : [];
        $this->email->toCustomer('agreement_signed', $invoice->customer, $invoice, $attachments);
    }

    private function depositInvoice(Customer $customer, Contract $contract): Invoice
    {
        $invoice = Invoice::firstOrCreate(
            ['customer_id' => $customer->id, 'contract_id' => $contract->id, 'type' => InvoiceType::Deposit],
            ['number' => 'TMP-'.bin2hex(random_bytes(6)), 'status' => InvoiceStatus::Scheduled, 'amount_cents' => $contract->deposit_cents, 'due_on' => BusinessClock::today()->toDateString()],
        );
        if (str_starts_with($invoice->number, 'TMP-')) {
            $invoice->update(['number' => sprintf('INV-%s-%04d', now()->format('Y'), $invoice->id)]);
        }

        return $invoice;
    }

    private function ensureStripeCustomer(Customer $customer): string
    {
        if ($customer->stripe_customer_id) {
            return $customer->stripe_customer_id;
        }

        $sc = $this->stripe->post('customers', [
            'email' => $customer->email,
            'name' => $customer->company_name,
            'phone' => $customer->phone_e164,
            'description' => $customer->fullName().', '.$customer->title,
            'address' => ['line1' => $customer->street, 'city' => $customer->city, 'state' => $customer->state_code, 'postal_code' => $customer->zip, 'country' => 'US'],
            'metadata' => ['customer_uuid' => $customer->uuid, 'state' => UsStates::name($customer->state_code)],
        ], "customer-{$customer->uuid}");

        $customer->forceFill(['stripe_customer_id' => $sc['id']])->save();

        return $sc['id'];
    }

    /** @return array{0: string, 1: string} [card|us_bank_account, "Visa ending 4242"] */
    private function methodLabel(array $pi): array
    {
        $pm = $pi['payment_method'] ?? null;
        if (! is_array($pm)) {
            return [$pi['payment_method_types'][0] ?? 'card', 'your saved payment method'];
        }
        if (($pm['type'] ?? '') === 'us_bank_account') {
            $b = $pm['us_bank_account'] ?? [];

            return ['us_bank_account', trim(($b['bank_name'] ?? 'Bank account').' ending '.($b['last4'] ?? ''))];
        }
        $c = $pm['card'] ?? [];

        return ['card', ucfirst((string) ($c['brand'] ?? 'Card')).' ending '.($c['last4'] ?? '')];
    }

    private function recordPayment(Invoice $invoice, array $pi, string $status, string $method, string $label): void
    {
        $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : null;
        $fee = is_array($charge['balance_transaction'] ?? null) ? ($charge['balance_transaction']['fee'] ?? null) : null;

        Payment::updateOrCreate(
            ['invoice_id' => $invoice->id, 'stripe_charge_id' => $charge['id'] ?? ('pi:'.$pi['id'])],
            [
                'customer_id' => $invoice->customer_id,
                'method' => $method,
                'method_label' => $label,
                'amount_cents' => (int) ($pi['amount_received'] ?? 0) ?: $invoice->amount_cents,
                'fee_cents' => $fee,
                'status' => $status,
                'settled_at' => $status === 'succeeded' ? now() : null,
            ],
        );
    }

    private function saveDefaultPaymentMethod(Customer $customer, array $pi, string $method, string $label): void
    {
        $pmId = is_array($pi['payment_method'] ?? null) ? $pi['payment_method']['id'] : ($pi['payment_method'] ?? null);
        if (! $pmId || $customer->stripe_payment_method_id === $pmId) {
            return;
        }
        $customer->forceFill(['stripe_payment_method_id' => $pmId, 'payment_method_type' => $method, 'payment_method_label' => $label])->save();

        if ($customer->stripe_customer_id) {
            // Future invoices (balance, monthly) charge this method automatically.
            $this->stripe->post('customers/'.$customer->stripe_customer_id, ['invoice_settings' => ['default_payment_method' => $pmId]]);
        }
    }
}
