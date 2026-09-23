<?php

namespace App\Http\Controllers\Onboarding;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\InvoicePdf;
use App\Services\Billing\PaymentMethods;
use App\Services\Email\EmailSender;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\BusinessClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The client's account page: no password, opened from an emailed link
 * (valid 7 days). Agreement, invoices and receipts, next charge, and
 * updating the card or bank account.
 */
class AccountController extends Controller
{
    public const LINK_DAYS = 7;

    public function request(): View
    {
        return view('account.request');
    }

    /** Emails an account link. Same answer whether or not the email is known. */
    public function sendLink(Request $request, EmailSender $email): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:160']]);
        Customer::where('email', strtolower($data['email']))->where('status', '!=', CustomerStatus::Draft)->get()
            ->each(fn (Customer $c) => $email->toCustomer('account_link', $c));

        return back()->with('status', 'If that email belongs to a RightAlly customer, we’ve sent a link to open your account. It works for 7 days.');
    }

    public function show(Customer $customer, StripeClient $stripe): View
    {
        $customer->load(['invoices' => fn ($q) => $q->where('status', '!=', InvoiceStatus::Scheduled)->latest('id'), 'contracts' => fn ($q) => $q->whereNotNull('signed_at')->latest('signed_at')]);
        $contract = $customer->contracts->firstWhere('status', ContractStatus::Signed);

        return view('account.show', [
            'customer' => $customer,
            'contract' => $contract,
            'next' => $this->nextCharge($customer, $contract),
            'canUpdateMethod' => $stripe->isConfigured() && (bool) $customer->stripe_customer_id,
        ]);
    }

    public function invoicePdf(Customer $customer, Invoice $invoice, InvoicePdf $pdf): Response
    {
        abort_unless($invoice->customer_id === $customer->id, 404);

        return response($pdf->render($invoice), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$pdf->filename($invoice).'"']);
    }

    public function agreementPdf(Customer $customer, Contract $contract): StreamedResponse
    {
        abort_unless($contract->customer_id === $customer->id && $contract->pdf_path, 404);

        return Storage::disk('local')->download($contract->pdf_path, "RightAlly-Agreement-{$contract->number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    /** Start updating the payment method: a Stripe SetupIntent for the Payment Element. */
    public function paymentMethod(Customer $customer, StripeClient $stripe): View|RedirectResponse
    {
        if (! $stripe->isConfigured() || ! $customer->stripe_customer_id) {
            return redirect()->route('account.show', $customer);
        }
        try {
            $si = $stripe->post('setup_intents', [
                'customer' => $customer->stripe_customer_id,
                'usage' => 'off_session',
                'payment_method_types' => ['card', 'us_bank_account'],
                'payment_method_options' => ['us_bank_account' => ['verification_method' => 'automatic']],
                'metadata' => ['customer_uuid' => $customer->uuid, 'type' => 'update_payment_method'],
            ]);
        } catch (StripeException $e) {
            report($e);

            return redirect()->route('account.show', $customer)->with('warning', $e->userMessage ?? 'We couldn’t start the update. Please try again in a minute.');
        }

        return view('account.payment-method', ['customer' => $customer, 'clientSecret' => $si['client_secret'], 'publishableKey' => $stripe->publishableKey()]);
    }

    public function paymentMethodReturn(Request $request, Customer $customer, StripeClient $stripe, PaymentMethods $methods, EmailSender $email, AuditLogger $audit): RedirectResponse
    {
        $id = (string) $request->query('setup_intent');
        try {
            $si = $id !== '' ? $stripe->get('setup_intents/'.$id, ['expand' => ['payment_method']]) : null;
        } catch (StripeException $e) {
            report($e);
            $si = null;
        }
        if (! $si || ($si['customer'] ?? null) !== $customer->stripe_customer_id) {
            return redirect()->route('account.show', $customer)->with('warning', 'We couldn’t confirm the update. Please try again.');
        }

        if (($si['status'] ?? '') === 'succeeded' && is_array($si['payment_method'] ?? null)) {
            $methods->makeDefault($customer, $si['payment_method']);
            $audit->log('customer.payment_method_updated', "{$customer->company_name} updated their payment method ({$customer->fresh()->payment_method_label})", $customer, null, 'client');
            $email->toCustomer('payment_method_updated', $customer->fresh());
            $hasFailed = $customer->invoices()->where('status', InvoiceStatus::Failed)->whereNotNull('hosted_invoice_url')->exists();

            return redirect()->route('account.show', $customer)->with('success', 'Payment method updated to '.$customer->fresh()->payment_method_label.'. Future charges use it.'
                .($hasFailed ? ' You still have an unpaid invoice below: use Pay now to settle it.' : ''));
        }
        if (($si['status'] ?? '') === 'processing') {
            return redirect()->route('account.show', $customer)->with('status', 'Your bank account is being verified. We’ll switch to it once your bank confirms.');
        }

        return redirect()->route('account.payment-method', $customer)->with('warning', $si['last_setup_error']['message'] ?? 'The update didn’t go through. Please try again.');
    }

    /** @return array{date: Carbon, amount: int, label: string}|null */
    private function nextCharge(Customer $customer, ?Contract $contract): ?array
    {
        if (! $contract || ! $customer->go_live_date) {
            return null;
        }
        if (in_array($customer->status, [CustomerStatus::ContractSigned, CustomerStatus::AwaitingGoLive], true)) {
            return ['date' => BusinessClock::date($customer->go_live_date), 'amount' => $contract->balance_cents, 'label' => 'Implementation balance, on your go-live date'];
        }
        if (in_array($customer->status, [CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::Suspended], true)) {
            $next = BusinessClock::date($customer->go_live_date)->addDays(30);
            while ($next->lt(BusinessClock::today())) {
                $next->addMonthNoOverflow();
            }

            return ['date' => $next, 'amount' => $contract->monthlyFeeCents($customer->agent_count), 'label' => "Monthly fee, {$customer->agent_count} agents"];
        }

        return null;
    }
}
