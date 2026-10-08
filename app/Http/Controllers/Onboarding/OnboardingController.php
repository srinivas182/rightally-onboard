<?php

namespace App\Http\Controllers\Onboarding;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\OnboardingAccess;
use App\Http\Requests\Onboarding\AboutRequest;
use App\Http\Requests\Onboarding\BrokerageRequest;
use App\Http\Requests\Onboarding\DetailsRequest;
use App\Http\Requests\Onboarding\SignRequest;
use App\Models\Contract;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\DepositService;
use App\Services\Contracts\ContractRenderer;
use App\Services\Contracts\ContractSigner;
use App\Services\Email\EmailSender;
use App\Services\Onboarding\ClientAccess;
use App\Services\Onboarding\CouponCheck;
use App\Services\Onboarding\ExistingClientActivation;
use App\Services\Onboarding\LeadEvents;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\ResumeLinks;
use App\Services\Pricing\QuoteCalculator;
use App\Services\Security\Turnstile;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\BusinessClock;
use App\Support\ClientLinks;
use App\Support\UsPhone;
use App\Support\UsStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
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

    // ---- Step 1a: About you ---------------------------------------------

    public function start(Request $request): View
    {
        $this->captureTracking($request);
        $custom = $this->sessionQuote($request);
        $quoteProblem = $request->filled('quote') && ! $custom
            ? __('This quote link has expired or has already been used. Reply to your quote email for a new link.')
            : null;
        $code = $custom ? '' : strtoupper(trim((string) ($request->query('coupon') ?? old('coupon', $request->session()->get('onboarding.tracking.coupon_link', '')))));
        $check = $this->coupons->check($code);

        // Invitation only: without a valid code (or custom quote) show the invitation page, not the form.
        if (! $custom && (string) $this->settings->get('pricing', 'require_coupon') === '1' && ($code === '' || ! $check['coupon'])) {
            return view('onboarding.invite', [
                'code' => $code,
                'error' => $code !== '' ? ($check['message'] ?: __('That code isn’t valid.')) : null,
                'quoteProblem' => $quoteProblem,
            ]);
        }

        return view('onboarding.details', $this->detailsViewData(null, $code, $check, $custom) + [
            'part' => 'about',
            'action' => route('onboarding.lead'),
            'method' => 'post',
            'fromLink' => $request->has('coupon'),
            'quoteProblem' => $quoteProblem,
        ]);
    }

    /** 1a "Next": checks the invitation code, saves the person as a lead, and moves to 1b. */
    public function storeLead(AboutRequest $request): RedirectResponse
    {
        if (! $this->turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            throw ValidationException::withMessages(['turnstile' => __('Please confirm you’re not a robot, then try again.')]);
        }
        if ($existing = $this->existingAccount($request, (string) $request->validated('email'))) {
            return $existing;
        }
        $custom = $this->sessionQuote($request);
        $coupon = $this->invitation($custom, (string) $request->validated('coupon'));

        $tracking = $request->session()->get('onboarding.tracking', []);
        $customer = $this->onboarding->startLead($request->validated() + ['phone_e164' => $request->input('phone_e164')], $coupon, [
            'source' => $custom ? "Custom quote: {$custom->label}" : $this->sourceLabel($tracking, $coupon?->code),
            'utm' => $tracking['utm'] ?? null,
        ], $custom);
        OnboardingAccess::remember($request, $customer->uuid);
        app(LeadEvents::class)->created($customer);

        return redirect()->route('onboarding.brokerage', $customer);
    }

    /**
     * Single-step submission of every detail (older links and integrations): same checks, then straight to the agreement.
     */
    public function store(DetailsRequest $request): RedirectResponse
    {
        if (! $this->turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            throw ValidationException::withMessages(['turnstile' => __('Please confirm you’re not a robot, then try again.')]);
        }
        if ($existing = $this->existingAccount($request, (string) $request->validated('email'))) {
            return $existing;
        }
        $custom = $this->sessionQuote($request);
        $coupon = $this->invitation($custom, (string) $request->validated('coupon'));

        $tracking = $request->session()->get('onboarding.tracking', []);
        $customer = $this->onboarding->start($request->validated() + ['phone_e164' => $request->input('phone_e164')], $coupon, [
            'source' => $custom ? "Custom quote: {$custom->label}" : $this->sourceLabel($tracking, $coupon?->code),
            'utm' => $tracking['utm'] ?? null,
        ], $custom);
        OnboardingAccess::remember($request, $customer->uuid);
        app(LeadEvents::class)->created($customer);

        return redirect()->route('onboarding.agreement', $customer);
    }

    /** Back to 1a for a saved lead (before signing). */
    public function editDetails(Customer $customer): View|RedirectResponse
    {
        if (! ResumeLinks::isIncomplete($customer) || $customer->status !== CustomerStatus::Draft) {
            return redirect()->route('onboarding.agreement', $customer);
        }
        $custom = $customer->quote_id ? Quote::find($customer->quote_id) : null;
        $code = $custom ? '' : (string) old('coupon', $customer->coupon?->code ?? '');

        return view('onboarding.details', $this->detailsViewData($customer, $code, $custom ? ['coupon' => null, 'message' => null] : $this->coupons->check($code), $custom) + [
            'part' => 'about',
            'action' => route('onboarding.details.update', $customer),
            'method' => 'put',
            'fromLink' => false,
        ]);
    }

    public function updateDetails(Request $request, Customer $customer): RedirectResponse
    {
        if ($customer->status !== CustomerStatus::Draft) {
            return redirect()->route('onboarding.agreement', $customer);
        }
        $data = app(AboutRequest::class)->validated();
        if (Customer::where('email', strtolower((string) $data['email']))->whereKeyNot($customer->id)->exists()) {
            throw ValidationException::withMessages(['email' => __('Another account already uses this email. Use a different email, or contact us.')]);
        }
        $custom = $customer->quote_id ? Quote::find($customer->quote_id) : null;
        $coupon = $this->invitation($custom, (string) ($data['coupon'] ?? ''));
        $this->onboarding->updateAbout($customer, $data + ['phone_e164' => $request->input('phone_e164')], $coupon);
        if ($request->filled('street')) { // every detail in one go (older form): finish 1b too
            $this->onboarding->saveBrokerage($customer->fresh(), app(BrokerageRequest::class)->validated(), $coupon);

            return redirect()->route('onboarding.agreement', $customer);
        }

        return redirect()->route('onboarding.brokerage', $customer);
    }

    // ---- Step 1b: Your brokerage ----------------------------------------

    public function brokerage(Customer $customer): View|RedirectResponse
    {
        if ($customer->status !== CustomerStatus::Draft) {
            return redirect()->route('onboarding.agreement', $customer);
        }
        $custom = $customer->quote_id ? Quote::find($customer->quote_id) : null;
        $code = $custom ? '' : (string) ($customer->coupon?->code ?? '');

        return view('onboarding.details', $this->detailsViewData($customer, $code, $custom ? ['coupon' => null, 'message' => null] : $this->coupons->check($code), $custom) + [
            'part' => 'brokerage',
            'action' => route('onboarding.brokerage.save', $customer),
            'method' => 'put',
            'fromLink' => false,
        ]);
    }

    public function saveBrokerage(BrokerageRequest $request, Customer $customer): RedirectResponse
    {
        if ($customer->status !== CustomerStatus::Draft) {
            return redirect()->route('onboarding.agreement', $customer);
        }
        $custom = $customer->quote_id ? Quote::find($customer->quote_id) : null;
        $coupon = null;
        if (! $custom && $customer->coupon) {
            $check = $this->coupons->check($customer->coupon->code);
            if ($check['message']) {
                // The code expired or ran out since 1a: send them back to fix it.
                return redirect()->route('onboarding.details', $customer)->withErrors(['coupon' => $check['message']]);
            }
            $coupon = $check['coupon'];
        }
        $this->onboarding->saveBrokerage($customer, $request->validated(), $coupon);

        return redirect()->route('onboarding.agreement', $customer);
    }

    /**
     * Invitation check: with "Require a valid coupon" on (Settings > Pricing), a valid coupon
     * or custom quote is needed to continue. With it off, a coupon is optional but must be valid.
     */
    private function invitation(?Quote $custom, string $code): ?Coupon
    {
        if ($custom) {
            return null;
        }
        $code = strtoupper(trim($code));
        if ($code === '' && (string) $this->settings->get('pricing', 'require_coupon') === '1') {
            throw ValidationException::withMessages(['coupon' => __('RightAlly is currently by invitation. Enter your referral code, or book a call and we’ll get you set up.')]);
        }
        $check = $this->coupons->check($code);
        if ($check['message']) {
            throw ValidationException::withMessages(['coupon' => $check['message']]);
        }

        return $check['coupon'];
    }

    /** One email, one account: carry on in the same browser, otherwise email the person a link. */
    private function existingAccount(Request $request, string $email): ?RedirectResponse
    {
        $existing = Customer::where('email', strtolower($email))->first();
        if (! $existing) {
            return null;
        }
        $allowed = (array) $request->session()->get(OnboardingAccess::SESSION_KEY, []);
        if (in_array($existing->uuid, $allowed, true) && ResumeLinks::isIncomplete($existing)) {
            return redirect()->route(ResumeLinks::nextStep($existing)['route'], $existing);
        }
        $sent = app(ClientAccess::class)->send($existing);
        $message = $sent === 'resume'
            ? __('You’ve already started setting up RightAlly with this email. We’ve emailed you a link to continue where you left off.')
            : __('An account already exists for this email. Sign in at :url, or contact us if you need a second account.', ['url' => route('account.login')]);
        throw ValidationException::withMessages(['email' => $message]);
    }

    // ---- Step 2: review and sign ---------------------------------------

    public function agreement(Customer $customer): View|RedirectResponse
    {
        $contract = $this->onboarding->currentContract($customer);
        if (! $contract && $customer->status === CustomerStatus::Draft) {
            return redirect()->route('onboarding.brokerage', $customer); // "Your brokerage" not done yet
        }
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

        $contract = $signer->sign($contract, trim($request->validated('typed_name')), (string) $request->signaturePng(), $request->ip(), $request->userAgent(), (string) $request->input('signature_method', 'drawn'));
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
            'signer_email' => ['required', 'email:rfc,filter', 'regex:/^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/', 'max:160'],
        ]);
        $requestedBy = $customer->fullName();

        $customer->update(['first_name' => $data['signer_first_name'], 'last_name' => $data['signer_last_name'], 'title' => $data['signer_title']]);
        $contract->forceFill(['signer_email' => strtolower($data['signer_email']), 'signature_requested_at' => now()])->save();

        $email->toPerson('signature_request', $customer, strtolower($data['signer_email']), $customer->fullName(), [
            'signer_name' => $data['signer_first_name'],
            'requested_by' => $requestedBy,
            'signing_link' => URL::temporarySignedRoute('onboarding.agreement', now()->addDays(ClientLinks::days()), ['customer' => $customer->uuid]),
        ]);
        $audit->log('contract.signature_requested', "{$requestedBy} asked {$customer->fullName()} ({$data['signer_email']}) to sign {$contract->number}", $contract, null, 'client');

        return redirect()->route('onboarding.agreement', $customer)->with('delegated', __('We’ve emailed :name at :email a link to review and sign. Receipts will still go to :account.', ['name' => $data['signer_first_name'], 'email' => $data['signer_email'], 'account' => $customer->email]));
    }

    /** Corrects the client's email on the payment step (e.g. an incomplete address saved before stricter checks). */
    public function updateEmail(Request $request, Customer $customer, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc,filter', 'regex:/^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/', 'max:160']],
            ['email.regex' => __('Enter a valid email address, for example name@brokerage.com.')]);
        $email = strtolower($data['email']);
        if (Customer::where('email', $email)->whereKeyNot($customer->id)->exists()) {
            throw ValidationException::withMessages(['email' => __('Another account already uses this email. Use a different email, or contact us.')]);
        }
        $old = $customer->email;
        $customer->update(['email' => $email]);
        $audit->log('customer.email_corrected', "Client corrected their email from {$old} to {$email}", $customer, null, 'client');

        return redirect()->route('onboarding.payment', $customer);
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
        if (ExistingClientActivation::isExisting($contract)) {
            return $this->existingPayment($customer, $contract);
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
                $error = str_contains($e->getMessage(), 'Invalid email')
                    // e.g. an address saved before stricter checks, like name@gmail with no .com
                    ? __('Your email address :email looks incomplete, so the payment can’t be set up. Enter your full email below.', ['email' => $customer->email])
                    : ($e->userMessage ?? __('We couldn’t start the payment. Please try again in a minute.'));
                $fixEmail = str_contains($e->getMessage(), 'Invalid email');
            }
        }

        return view('onboarding.payment', [
            'fixEmail' => $fixEmail ?? false,
            'customer' => $customer,
            'contract' => $contract,
            'step' => 4,
            'clientSecret' => $clientSecret,
            'publishableKey' => $stripe->publishableKey(),
            'paymentError' => $error ?? ($existing?->status === InvoiceStatus::Failed ? ($existing->failure_reason ?: __('Your last payment didn’t go through.')).' Try again or use a different method.' : null),
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
        if (ExistingClientActivation::isExisting($contract)) {
            return $customer->status === CustomerStatus::Live
                ? view('onboarding.done-existing', ['customer' => $customer, 'contract' => $contract, 'step' => 5] + $this->ledger($contract))
                : redirect()->route('onboarding.payment', $customer);
        }
        $invoice = $this->depositInvoice($customer);
        if (! $contract || ! $invoice || ! in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Processing], true)) {
            return redirect()->route('onboarding.payment', $customer);
        }

        return view('onboarding.done', ['customer' => $customer, 'contract' => $contract, 'invoice' => $invoice, 'step' => 5] + $this->ledger($contract));
    }

    // ---- Existing clients: confirm the payment method on file; nothing charged today ----

    private function existingPayment(Customer $customer, Contract $contract): View|RedirectResponse
    {
        if ($customer->status === CustomerStatus::Live) {
            return redirect()->route('onboarding.done', $customer);
        }
        $activation = app(ExistingClientActivation::class);
        $stripe = app(StripeClient::class);
        $saved = null;
        $clientSecret = null;
        $error = null;
        if (! $stripe->isConfigured()) {
            $error = __('Online payments aren’t switched on yet. Please contact us to finish.');
        } else {
            try {
                $saved = request()->boolean('new') ? null : $activation->savedMethod($customer);
                if (! $saved) {
                    $clientSecret = $activation->setupIntent($customer->fresh());
                }
            } catch (StripeException $e) {
                report($e);
                $error = $e->userMessage ?? __('We couldn’t load your payment details. Please try again in a minute.');
            }
        }

        return view('onboarding.payment-existing', [
            'customer' => $customer, 'contract' => $contract, 'step' => 4,
            'savedLabel' => $saved ? $activation->label($saved) : null,
            'clientSecret' => $clientSecret, 'publishableKey' => $stripe->publishableKey(), 'paymentError' => $error,
        ] + $this->ledger($contract));
    }

    public function existingActivate(Customer $customer): RedirectResponse
    {
        $contract = $this->signedContractOrNull($customer);
        abort_unless(ExistingClientActivation::isExisting($contract), 404);
        $activation = app(ExistingClientActivation::class);
        $saved = $activation->savedMethod($customer);
        if (! $saved) {
            return redirect()->route('onboarding.payment', $customer);
        }
        $activation->activate($customer, $contract, $saved);

        return redirect()->route('onboarding.done', $customer);
    }

    public function existingReturn(Request $request, Customer $customer, StripeClient $stripe): RedirectResponse
    {
        $contract = $this->signedContractOrNull($customer);
        abort_unless(ExistingClientActivation::isExisting($contract), 404);
        try {
            $si = $stripe->get('setup_intents/'.(string) $request->query('setup_intent'), ['expand' => ['payment_method']]);
        } catch (StripeException $e) {
            report($e);

            return redirect()->route('onboarding.payment', $customer)->with('warning', __('We couldn’t confirm your payment method. Please try again.'));
        }
        if (($si['customer'] ?? null) !== $customer->stripe_customer_id) {
            abort(403);
        }
        if (($si['status'] ?? '') === 'succeeded' && is_array($si['payment_method'] ?? null)) {
            app(ExistingClientActivation::class)->activate($customer, $contract, $si['payment_method']);

            return redirect()->route('onboarding.done', $customer);
        }
        if (($si['status'] ?? '') === 'processing') {
            return redirect()->route('onboarding.payment', $customer)->with('status', __('Your bank account is being verified. We’ll finish as soon as your bank confirms.'));
        }

        return redirect()->route('onboarding.payment', ['customer' => $customer, 'new' => 1])->with('warning', $si['last_setup_error']['message'] ?? __('That didn’t go through. Please try again.'));
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
                'monthly' => $contract->recurringFeeCents(),
                'period' => $contract->isAnnual() ? 'Yearly fee' : 'Monthly fee',
                'agents' => max($contract->min_agents, $contract->agent_count),
                'platform' => $contract->isAnnual() ? $contract->platformYearCents() : $contract->platform_fee_cents,
                'perAgent' => $contract->isAnnual() ? $contract->perAgentYearCents() : $contract->per_agent_fee_cents,
                'atMinimum' => $contract->agent_count < $contract->min_agents,
            ],
            'goLive' => $contract->starts_on ?? BusinessClock::today()->addDays((int) ($contract->go_live_days ?? $this->settings->get('pricing', 'go_live_days'))),
        ];
    }

    private function detailsViewData(?Customer $customer, string $code, array $check, ?Quote $custom = null): array
    {
        $agents = (int) old('agents', $customer?->agent_count_entered ?? ($custom?->min_agents ?? $this->settings->get('pricing', 'min_agents')));
        $billing = (string) old('billing', $customer ? ($this->onboarding->draftContract($customer)?->billing_interval ?? 'month') : 'month');
        $quote = $this->quotes->quote(max(1, $agents), $check['coupon'], $custom, $billing);

        return [
            'customer' => $customer,
            'customQuote' => $custom,
            'prefill' => $custom ? ['company_name' => $custom->company_name, 'email' => $custom->email] : [],
            'states' => UsStates::ALL,
            'quote' => $quote,
            'couponCode' => $code,
            'couponMessage' => $check['message'],
            'agents' => $agents,
            'phone' => $customer ? UsPhone::format($customer->phone_e164) : '',
            'turnstileSiteKey' => $this->turnstile->enabled() ? $this->turnstile->siteKey() : null,
            'mapsKey' => (string) $this->settings->get('alerts', 'google_maps_key') ?: null,
            'step' => 1,
            'ledger' => [
                'setup' => $quote->setupFeeCents,
                'code' => $quote->coupon?->code,
                'discount' => $quote->discountCents,
                'implementation' => $quote->implementationFeeCents,
                'deposit' => $quote->depositCents,
                'balance' => $quote->balanceCents,
                'monthly' => $quote->billingInterval === 'year' ? $quote->annualFeeCents() : $quote->monthlyFeeCents(),
                'period' => $quote->billingInterval === 'year' ? 'Yearly fee' : 'Monthly fee',
                'agents' => $quote->agentsBilled,
                'platform' => $quote->billingInterval === 'year' ? (int) round($quote->platformFeeCents * 12 * (1 - $quote->annualDiscountPercent / 100)) : $quote->platformFeeCents,
                'perAgent' => $quote->billingInterval === 'year' ? (int) round($quote->perAgentFeeCents * 12 * (1 - $quote->annualDiscountPercent / 100)) : $quote->perAgentFeeCents,
                'atMinimum' => $quote->agentsEntered < $quote->minAgents,
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
    private function sessionQuote(Request $request): ?Quote
    {
        if ($request->filled('quote')) {
            $request->session()->put('onboarding.quote', (string) $request->query('quote'));
        }
        $token = $request->session()->get('onboarding.quote');
        $quote = $token && Str::isUuid($token) ? Quote::where('token', $token)->first() : null;

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
