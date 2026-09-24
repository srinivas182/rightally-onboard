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
use App\Services\Onboarding\ResumeLinks;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\BillingDates;
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
        $data = $request->validate(['email' => ['required', 'email:rfc,filter', 'regex:/^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/', 'max:160']]);
        Customer::where('email', strtolower($data['email']))->get()->each(fn (Customer $c) => ResumeLinks::isIncomplete($c)
            ? app(ResumeLinks::class)->send($c)   // not finished: link to continue where they stopped
            : $email->toCustomer('account_link', $c));

        return back()->with('status', __('If that email belongs to a RightAlly customer, we’ve sent a link to continue your set-up or open your account. It works for 7 days.'));
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

            return redirect()->route('account.show', $customer)->with('warning', $e->userMessage ?? __('We couldn’t start the update. Please try again in a minute.'));
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
            return redirect()->route('account.show', $customer)->with('warning', __('We couldn’t confirm the update. Please try again.'));
        }

        if (($si['status'] ?? '') === 'succeeded' && is_array($si['payment_method'] ?? null)) {
            $methods->makeDefault($customer, $si['payment_method']);
            $audit->log('customer.payment_method_updated', "{$customer->company_name} updated their payment method ({$customer->fresh()->payment_method_label})", $customer, null, 'client');
            $email->toCustomer('payment_method_updated', $customer->fresh());
            $hasFailed = $customer->invoices()->where('status', InvoiceStatus::Failed)->whereNotNull('hosted_invoice_url')->exists();

            return redirect()->route('account.show', $customer)->with('success', __('Payment method updated to :method. Future charges use it.', ['method' => $customer->fresh()->payment_method_label])
                .($hasFailed ? ' '.__('You still have an unpaid invoice below: use Pay now to settle it.') : ''));
        }
        if (($si['status'] ?? '') === 'processing') {
            return redirect()->route('account.show', $customer)->with('status', __('Your bank account is being verified. We’ll switch to it once your bank confirms.'));
        }

        return redirect()->route('account.payment-method', $customer)->with('warning', $si['last_setup_error']['message'] ?? __('The update didn’t go through. Please try again.'));
    }

    /** @return array{date: Carbon, amount: int, label: string}|null */
    private function nextCharge(Customer $customer, ?Contract $contract): ?array
    {
        if (! $contract || ! $customer->go_live_date) {
            return null;
        }
        if (in_array($customer->status, [CustomerStatus::ContractSigned, CustomerStatus::AwaitingGoLive], true)) {
            return ['date' => BusinessClock::date($customer->go_live_date), 'amount' => $contract->balance_cents, 'label' => __('Implementation balance, on your go-live date')];
        }
        if (in_array($customer->status, [CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::Suspended], true)) {
            return ['date' => BillingDates::nextCharge($customer, $contract), 'amount' => $contract->recurringFeeCents($customer->agent_count),
                'label' => __($contract->isAnnual() ? 'Yearly fee, :n agents' : 'Monthly fee, :n agents', ['n' => $customer->agent_count])];
        }
        if ($customer->status === CustomerStatus::Paused && $customer->paused_until) {
            return ['date' => BillingDates::nextCharge($customer, $contract, BusinessClock::date($customer->paused_until)), 'amount' => $contract->recurringFeeCents($customer->agent_count),
                'label' => __('First charge after your pause')];
        }

        return null;
    }
}
