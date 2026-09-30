<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeClient;
use App\Support\BusinessClock;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Custom quotes: negotiated pricing as a one-time onboarding link. */
class QuoteController extends Controller
{
    public const DEFAULT_DAYS = 14;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(SettingsService $settings): View
    {
        return view('admin.quotes.index', [
            'quotes' => Quote::with('customer', 'creator')->latest('id')->paginate(25),
            'defaults' => $settings->group('pricing'),
            'defaultExpiry' => BusinessClock::today()->addDays(self::DEFAULT_DAYS)->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'setup_fee' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'deposit_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'platform_fee' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'per_agent_fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'min_agents' => ['required', 'integer', 'min:1', 'max:10000'],
            'go_live_days' => ['required', 'integer', 'min:1', 'max:365'],
            'expires_on' => ['required', 'date', 'after_or_equal:'.BusinessClock::today()->toDateString()],
            'note' => ['nullable', 'string', 'max:500'],
            'is_existing_client' => ['nullable', 'boolean'],
            'term_months' => ['nullable', 'integer', 'min:0', 'max:60'],
            'first_charge_on' => ['nullable', 'date'],
            'old_stripe_subscription_id' => ['nullable', 'string', 'max:80', 'regex:/^sub_[A-Za-z0-9]+$/'],
        ], ['expires_on.after_or_equal' => 'The expiry date can’t be in the past.', 'old_stripe_subscription_id.regex' => 'Stripe subscription IDs start with sub_.']);

        // Existing clients: no set-up fee; their Stripe customer and first charge date come from the old subscription.
        $existing = $request->boolean('is_existing_client');
        $stripeCustomer = null;
        if ($existing) {
            $d['setup_fee'] = 0;
            $d['deposit_percent'] = 0;
            if (! empty($d['old_stripe_subscription_id'])) {
                try {
                    $old = app(StripeClient::class)->get('subscriptions/'.$d['old_stripe_subscription_id']);
                    $stripeCustomer = $old['customer'] ?? null;
                    if (empty($d['first_charge_on']) && ! empty($old['current_period_end'])) {
                        $d['first_charge_on'] = Carbon::createFromTimestamp($old['current_period_end'])->setTimezone(BusinessClock::timezone())->toDateString();
                    }
                } catch (\Throwable $e) {
                    return back()->withInput()->withErrors(['old_stripe_subscription_id' => 'Couldn’t find that subscription in Stripe ('.$e->getMessage().').']);
                }
            }
            if (empty($d['first_charge_on'])) {
                return back()->withInput()->withErrors(['first_charge_on' => 'Choose the first charge date (or enter their current Stripe subscription ID to use its renewal date).']);
            }
        }

        $quote = Quote::create([
            'label' => $d['label'], 'company_name' => $d['company_name'] ?? null, 'email' => isset($d['email']) ? strtolower($d['email']) : null,
            'setup_fee_cents' => Money::toCents($d['setup_fee']), 'deposit_percent' => $d['deposit_percent'],
            'platform_fee_cents' => Money::toCents($d['platform_fee']), 'per_agent_fee_cents' => Money::toCents($d['per_agent_fee']),
            'min_agents' => $d['min_agents'], 'go_live_days' => $d['go_live_days'], 'note' => $d['note'] ?? null,
            'is_existing_client' => $existing,
            'term_months' => $existing ? (int) ($d['term_months'] ?? 12) : null,
            'first_charge_on' => $existing ? $d['first_charge_on'] : null,
            'old_stripe_subscription_id' => $existing ? ($d['old_stripe_subscription_id'] ?? null) : null,
            'stripe_customer_id' => $stripeCustomer,
            'expires_at' => Carbon::parse($d['expires_on'], BusinessClock::timezone())->endOfDay(),
            'created_by' => $request->user('admin')->id,
        ]);
        $this->audit->log('quote.created', "Created custom quote “{$quote->label}”", $quote, [
            'setup_fee' => $d['setup_fee'], 'platform_fee' => $d['platform_fee'], 'per_agent_fee' => $d['per_agent_fee'], 'min_agents' => $d['min_agents'],
        ]);

        return redirect()->route('admin.quotes.index')->with('success', "Quote for {$quote->label} created. Copy its link and send it to the client.")->with('new_quote', $quote->id);
    }

    public function void(Quote $quote): RedirectResponse
    {
        abort_if($quote->used_at, 400, 'This quote has already been used.');
        $quote->update(['voided_at' => now()]);
        $this->audit->log('quote.voided', "Voided custom quote “{$quote->label}”", $quote);

        return back()->with('success', "Quote for {$quote->label} voided. Its link no longer works.");
    }
}
