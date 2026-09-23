<?php

namespace App\Http\Controllers\Onboarding;

use App\Enums\ContractStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\OnboardingAccess;
use App\Http\Requests\Onboarding\DetailsRequest;
use App\Http\Requests\Onboarding\SignRequest;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\DepositService;
use App\Services\Contracts\ContractRenderer;
use App\Services\Contracts\ContractSigner;
use App\Services\Email\EmailSender;
use App\Services\Onboarding\CouponCheck;
use App\Services\Onboarding\OnboardingService;
use App\Services\Pricing\QuoteCalculator;
use App\Services\Security\Turnstile;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\BusinessClock;
use App\Support\UsPhone;
use App\Support\UsStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The client onboarding flow:
 *   1 details  ->  2 review and sign  ->  3 payment schedule  ->  4 payment (Sprint 3)  ->  5 done
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly QuoteCalculator $quotes,
        private readonly CouponCheck $coupons,
        private readonly Turnstile $turnstile,
        private readonly SettingsService $settings,
        private readonly ContractRenderer $renderer,
    ) {}

    // ---- Step 1: details ------------------------------------------------

    public function start(Request $request): View
    {
        $this->captureTracking($request);
        $custom = $this->sessionQuote($request);
        $quoteProblem = $request->filled('quote') && ! $custom
            ? 'This quote link has expired or has already been used. You can continue at our standard pricing, or reply to your quote email for a new link.'
            : null;
        $code = $custom ? '' : strtoupper(trim((string) ($request->query('coupon') ?? old('coupon', ''))));
        $check = $this->coupons->check($code);

        return view('onboarding.details', $this->detailsViewData(null, $code, $check, $custom) + [
            'action' => route('onboarding.store'),
            'method' => 'post',
            'fromLink' => $request->has('coupon'),
            'quoteProblem' => $quoteProblem,
        ]);
    }

    public function store(DetailsRequest $request): RedirectResponse
    {
        if (! $this->turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            throw ValidationException::withMessages(['turnstile' => 'Please confirm you’re not a robot, then try again.']);
        }

        $custom = $this->sessionQuote($request);
        $check = $custom ? ['coupon' => null, 'message' => null] : $this->coupons->check($request->validated('coupon'));
        if ($check['message']) {
            throw ValidationException::withMessages(['coupon' => $check['message']]);
        }

        $tracking = $request->session()->get('onboarding.tracking', []);
        $customer = $this->onboarding->start($request->validated() + ['phone_e164' => $request->input('phone_e164')], $check['coupon'], [
            'source' => $custom ? "Custom quote: {$custom->label}" : $this->sourceLabel($tracking, $check['coupon']?->code),
            'utm' => $tracking['utm'] ?? null,
        ], $custom);

        OnboardingAccess::remember($request, $customer->uuid);

        return redirect()->route('onboarding.agreement', $customer);
    }

    public function editDetails(Customer $customer): View|RedirectResponse
    {
        if (! $this->onboarding->draftContract($customer)) {
            return redirect()->route('onboarding.agreement', $customer);
        }
        $code = old('coupon', $customer->coupon?->code ?? '');

        $custom = $customer->quote_id ? \App\Models\Quote::find($customer->quote_id) : null;

        return view('onboarding.details', $this->detailsViewData($customer, $custom ? '' : $code, $custom ? ['coupon' => null, 'message' => null] : $this->coupons->check($code), $custom) + [
            'action' => route('onboarding.details.update', $customer),
            'method' => 'put',
            'fromLink' => false,
        ]);
    }

    public function updateDetails(DetailsRequest $request, Customer $customer): RedirectResponse
    {
        $check = $this->coupons->check($request->validated('coupon'));
        if ($check['message']) {
            throw ValidationException::withMessages(['coupon' => $check['message']]);
        }

        try {
            $this->onboarding->update($customer, $request->validated() + ['phone_e164' => $request->input('phone_e164')], $check['coupon']);
        } catch (RuntimeException) {
            return redirect()->route('onboarding.agreement', $customer);
        }

        return redirect()->route('onboarding.agreement', $customer);
    }

    // ---- Step 2: review and sign ---------------------------------------

    public function agreement(Customer $customer): View
    {
        $contract = $this->onboarding->currentContract($customer);
        abort_unless($contract, 404);
        $contract->loadMissing('template', 'customer');

        return view('onboarding.agreement', [
            'customer' => $customer,
            'contract' => $contract,
            'body' => $contract->isSigned() ? $contract->rendered_html : $this->renderer->body($contract),
            'companySignature' => $this->renderer->companySignatureDataUri($contract),
            'step' => 2,
        ] + $this->ledger($contract));
    }

    public function sign(SignRequest $request, Customer $customer, ContractSigner $signer): RedirectResponse
    {
        $contract = $this->onboarding->draftContract($customer);
        if (! $contract) {
            return redirect()->route('onboarding.agreement', $customer);
        }

        $contract = $signer->sign($contract, trim($request->validated('typed_name')), (string) $request->signaturePng(), $request->ip(), $request->userAgent());
        // Their signed copy by email, with a link back to payment in case they stop here.
        $pdf = [['path' => $contract->pdf_path, 'name' => "RightAlly-Agreement-{$contract->number}.pdf"]];
        $mailer = app(EmailSender::class);
        $mailer->toCustomer('signed_payment_pending', $customer->fresh(), null, $pdf);
        if ($contract->signer_email && $contract->signer_email !== $customer->email) {
            // The person who signed on someone else's request gets their own copy too.
            $mailer->toPerson('signed_payment_pending', $customer->fresh(), $contract->signer_email, $customer->fullName(), [], $pdf);
        }

        return redirect()->route('onboarding.agreement', $customer)->with('signed', true);
    }

    /** "Someone else will sign": the signer's details go on the agreement and they get a link by email. */
    public function delegate(Request $request, Customer $customer, EmailSender $email, AuditLogger $audit): RedirectResponse
    {
        $contract = $this->onboarding->draftContract($customer);
        if (! $contract) {
            return redirect()->route('onboarding.agreement', $customer);
        }
        $data = $request->validateWithBag('delegate', [
            'signer_first_name' => ['required', 'string', 'max:80'],
            'signer_last_name' => ['required', 'string', 'max:80'],
            'signer_title' => ['required', 'string', 'max:80'],
            'signer_email' => ['required', 'email:rfc', 'max:160'],
        ]);
        $requestedBy = $customer->fullName();

        $customer->update(['first_name' => $data['signer_first_name'], 'last_name' => $data['signer_last_name'], 'title' => $data['signer_title']]);
        $contract->forceFill(['signer_email' => strtolower($data['signer_email']), 'signature_requested_at' => now()])->save();

        $email->toPerson('signature_request', $customer, strtolower($data['signer_email']), $customer->fullName(), [
            'signer_name' => $data['signer_first_name'],
            'requested_by' => $requestedBy,
            'signing_link' => URL::temporarySignedRoute('onboarding.agreement', now()->addDays(7), ['customer' => $customer->uuid]),
        ]);
        $audit->log('contract.signature_requested', "{$requestedBy} asked {$customer->fullName()} ({$data['signer_email']}) to sign {$contract->number}", $contract, null, 'client');

        return redirect()->route('onboarding.agreement', $customer)->with('delegated', "We’ve emailed {$data['signer_first_name']} at {$data['signer_email']} a link to review and sign. Receipts will still go to {$customer->email}.");
    }

    public function pdf(Customer $customer): StreamedResponse
    {
        $contract = $this->onboarding->currentContract($customer);
        abort_unless($contract && $contract->isSigned() && $contract->pdf_path, 404);

        return Storage::disk('local')->download($contract->pdf_path, "RightAlly-Agreement-{$contract->number}.pdf", [
            'Content-Type' => 'application/pdf',
        ]);
    }

    // ---- Step 3: schedule; Step 4: payment -----------------------------

    public function schedule(Customer $customer): View|RedirectResponse
    {
        $contract = $this->signedContractOrNull($customer);
        if (! $contract) {
            return redirect()->route('onboarding.agreement', $customer);
        }

        return view('onboarding.schedule', ['customer' => $customer, 'contract' => $contract, 'step' => 3] + $this->ledger($contract));
    }

    public function payment(Customer $customer, DepositService $deposits, StripeClient $stripe): View|RedirectResponse
    {
        $contract = $this->signedContractOrNull($customer);
        if (! $contract) {
            return redirect()->route('onboarding.agreement', $customer);
        }
        $existing = $this->depositInvoice($customer);
        if ($existing && in_array($existing->status, [InvoiceStatus::Paid, InvoiceStatus::Processing], true)) {
            return redirect()->route('onboarding.done', $customer);
        }

        $clientSecret = null;
        $error = null;
        if (! $stripe->isConfigured()) {
            $error = 'Online payment isn’t available right now. Your signed agreement is saved; we’ll email you a payment link shortly.';
        } else {
            try {
                $clientSecret = $deposits->prepare($customer, $contract)['client_secret'];
            } catch (StripeException $e) {
                report($e);
                $error = $e->userMessage ?? 'We couldn’t start the payment. Please try again in a minute.';
            }
        }

        return view('onboarding.payment', [
            'customer' => $customer,
            'contract' => $contract,
            'step' => 4,
            'clientSecret' => $clientSecret,
            'publishableKey' => $stripe->publishableKey(),
            'paymentError' => $error ?? ($existing?->status === InvoiceStatus::Failed ? ($existing->failure_reason ?: 'Your last payment didn’t go through.').' Try again or use a different method.' : null),
        ] + $this->ledger($contract));
    }

    /** Stripe sends the client back here after confirming (including bank redirects). */
    public function paymentReturn(Request $request, Customer $customer, DepositService $deposits): RedirectResponse
    {
        $invoice = $this->depositInvoice($customer);
        $piId = (string) $request->query('payment_intent');
        if (! $invoice || $piId === '' || $piId !== $invoice->stripe_payment_intent_id) {
            return redirect()->route('onboarding.payment', $customer);
        }

        try {
            $invoice = $deposits->syncFromStripe($piId) ?? $invoice;
        } catch (StripeException $e) {
            report($e); // the webhook will apply the result
        }

        return in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Processing], true)
            ? redirect()->route('onboarding.done', $customer)
            : redirect()->route('onboarding.payment', $customer);
    }

    public function done(Customer $customer): View|RedirectResponse
    {
        $contract = $this->signedContractOrNull($customer);
        $invoice = $this->depositInvoice($customer);
        if (! $contract || ! $invoice || ! in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Processing], true)) {
            return redirect()->route('onboarding.payment', $customer);
        }

        return view('onboarding.done', ['customer' => $customer, 'contract' => $contract, 'invoice' => $invoice, 'step' => 5] + $this->ledger($contract));
    }

    private function depositInvoice(Customer $customer): ?Invoice
    {
        return $customer->invoices()->where('type', InvoiceType::Deposit)->latest('id')->first();
    }

    // ---- helpers ------------------------------------------------------

    private function signedContractOrNull(Customer $customer): ?Contract
    {
        $contract = $this->onboarding->currentContract($customer);

        return $contract && $contract->status === ContractStatus::Signed ? $contract : null;
    }

    /** Values for the "Your agreement" panel. */
    private function ledger(Contract $contract): array
    {
        return [
            'ledger' => [
                'setup' => $contract->setup_fee_cents,
                'code' => $contract->coupon_code,
                'discount' => $contract->discount_cents,
                'implementation' => $contract->implementation_fee_cents,
                'deposit' => $contract->deposit_cents,
                'balance' => $contract->balance_cents,
                'monthly' => $contract->monthlyFeeCents(),
                'agents' => $contract->agent_count,
            ],
            'goLive' => $contract->starts_on ?? BusinessClock::today()->addDays((int) ($contract->go_live_days ?? $this->settings->get('pricing', 'go_live_days'))),
        ];
    }

    private function detailsViewData(?Customer $customer, string $code, array $check, ?\App\Models\Quote $custom = null): array
    {
        $agents = (int) old('agents', $customer?->agent_count_entered ?? ($custom?->min_agents ?? $this->settings->get('pricing', 'min_agents')));
        $quote = $this->quotes->quote(max(1, $agents), $check['coupon'], $custom);

        return [
            'customer' => $customer,
            'customQuote' => $custom,
            'quoteProblem' => null,
            'prefill' => $custom ? ['company_name' => $custom->company_name, 'email' => $custom->email] : [],
            'states' => UsStates::ALL,
            'quote' => $quote,
            'couponCode' => $code,
            'couponMessage' => $check['message'],
            'agents' => $agents,
            'phone' => $customer ? UsPhone::format($customer->phone_e164) : '',
            'turnstileSiteKey' => $this->turnstile->enabled() ? $this->turnstile->siteKey() : null,
            'step' => 1,
            'ledger' => [
                'setup' => $quote->setupFeeCents,
                'code' => $quote->coupon?->code,
                'discount' => $quote->discountCents,
                'implementation' => $quote->implementationFeeCents,
                'deposit' => $quote->depositCents,
                'balance' => $quote->balanceCents,
                'monthly' => $quote->monthlyFeeCents(),
                'agents' => $quote->agentsBilled,
            ],
            'goLive' => BusinessClock::today()->addDays($quote->goLiveDays),
        ];
    }

    private function captureTracking(Request $request): void
    {
        $utm = array_filter($request->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']));
        $tracking = $request->session()->get('onboarding.tracking', []);
        if ($utm) {
            $tracking['utm'] = array_map(fn ($v) => mb_substr((string) $v, 0, 120), $utm);
        }
        if ($request->filled('coupon')) {
            $tracking['coupon_link'] = strtoupper(mb_substr((string) $request->query('coupon'), 0, 40));
        }
        $ref = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);
        if ($ref && $ref !== $request->getHost() && empty($tracking['referrer'])) {
            $tracking['referrer'] = mb_substr($ref, 0, 120);
        }
        $request->session()->put('onboarding.tracking', $tracking);
    }

    /** A usable custom quote from ?quote= (remembered in the session for the rest of step 1). */
    private function sessionQuote(Request $request): ?\App\Models\Quote
    {
        if ($request->filled('quote')) {
            $request->session()->put('onboarding.quote', (string) $request->query('quote'));
        }
        $token = $request->session()->get('onboarding.quote');
        $quote = $token && \Illuminate\Support\Str::isUuid($token) ? \App\Models\Quote::where('token', $token)->first() : null;

        return $quote?->isUsable() ? $quote : null;
    }

    private function sourceLabel(array $tracking, ?string $appliedCode): ?string
    {
        return match (true) {
            ! empty($tracking['coupon_link']) && $tracking['coupon_link'] === $appliedCode => "{$appliedCode} link",
            ! empty($tracking['utm']['utm_source']) => trim($tracking['utm']['utm_source'].' '.($tracking['utm']['utm_campaign'] ?? '')),
            ! empty($tracking['referrer']) => $tracking['referrer'],
            default => 'Direct',
        };
    }
}
