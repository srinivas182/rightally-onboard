<?php

namespace Tests\Support;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\CustomerStatus;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\WebhookSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A fake Stripe for billing tests. Set $this->stripe[<path fragment>] to the
 * JSON to return (or a closure receiving the request). Brevo always accepts.
 */
trait FakesBilling
{
    /** @var array<string, array|\Closure> */
    protected array $stripe = [];

    protected function fakeBilling(): void
    {
        $settings = app(SettingsService::class);
        $settings->setMany('stripe', ['mode' => 'test', 'test_publishable_key' => 'pk_test_x', 'test_secret_key' => 'sk_test_x', 'test_webhook_secret' => 'whsec_test']);
        $settings->setMany('email', ['brevo_api_key' => 'xkeysib-test']);

        $this->stripe += [
            'POST invoices/in_1/finalize' => ['id' => 'in_1', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/in_1'],
            'POST invoices/in_1/pay' => ['id' => 'in_1', 'status' => 'paid', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/in_1', 'payment_intent' => ['status' => 'succeeded']],
            'POST invoiceitems' => ['id' => 'ii_1'],
            'POST customers' => ['id' => 'cus_1'],
            'POST invoices' => ['id' => 'in_1', 'status' => 'draft'],
            'GET prices' => fn (Request $r) => ['data' => []],
            'POST prices' => fn (Request $r) => ['id' => 'price_'.$r['lookup_key']],
            'POST subscriptions' => fn (Request $r) => ! isset($r['items']) ? ['id' => 'sub_1'] : ['id' => 'sub_1', 'items' => ['data' => [
                ['id' => 'si_platform', 'price' => ['id' => $r['items'][0]['price']]],
                ['id' => 'si_agent', 'price' => ['id' => $r['items'][1]['price']]],
            ]]],
            'POST subscription_items' => ['id' => 'si_agent'],
            'DELETE subscriptions' => ['id' => 'sub_1', 'status' => 'canceled'],
        ];

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.brevo.com')) {
                return Http::response(['messageId' => '<m@brevo>'], 201);
            }
            if (! str_contains($request->url(), 'api.stripe.com')) {
                return null; // let other fakes in the test answer
            }
            $path = $request->method().' '.Str::after(parse_url($request->url(), PHP_URL_PATH), '/v1/');
            // Longest matching key wins, so "POST invoices/in_1/pay" beats "POST invoices".
            $keys = array_keys($this->stripe);
            usort($keys, fn ($a, $b) => strlen($b) <=> strlen($a));
            foreach ($keys as $key) {
                if ($path === $key || str_starts_with($path, $key.'/') || ($path === $key)) {
                    $v = $this->stripe[$key];
                    $body = $v instanceof \Closure ? $v($request) : $v;

                    return isset($body['__status']) ? Http::response($body['json'], $body['__status']) : Http::response($body, 200);
                }
            }

            return Http::response(['error' => ['message' => "No fake for {$path}"]], 500);
        });
    }

    /** A signed customer with a saved card, in the given state. */
    protected function billedCustomer(CustomerStatus $status = CustomerStatus::AwaitingGoLive, string $goLive = '2026-10-23', int $agents = 8): Customer
    {
        $customer = new Customer([
            'status' => $status, 'first_name' => 'Maria', 'last_name' => 'Alvarez', 'title' => 'Managing Broker',
            'company_name' => 'Sunline Realty Group', 'email' => 'maria@sunlinerealty.com', 'phone_e164' => '+13055550148',
            'street' => '1200 Brickell Ave', 'city' => 'Miami', 'state_code' => 'FL', 'zip' => '33131',
            'agent_count' => $agents, 'agent_count_entered' => $agents, 'go_live_date' => $goLive,
            'payment_method_type' => 'card', 'payment_method_label' => 'Visa ending 4242',
            'live_at' => $status === CustomerStatus::Live ? now() : null,
        ]);
        $customer->forceFill(['stripe_customer_id' => 'cus_1', 'stripe_payment_method_id' => 'pm_1'])->save();
        if ($status === CustomerStatus::Live) {
            $customer->forceFill(['stripe_subscription_id' => 'sub_1', 'stripe_agent_item_id' => 'si_agent'])->save();
        }

        $template = ContractTemplate::where('type', ContractType::Initial)->firstOrFail();
        $contract = Contract::create([
            'number' => 'RA-2026-0001', 'customer_id' => $customer->id, 'contract_template_id' => $template->id,
            'type' => ContractType::Initial, 'status' => ContractStatus::Signed, 'signed_at' => now()->subDays(30),
            'setup_fee_cents' => 300000, 'implementation_fee_cents' => 255000, 'discount_cents' => 45000, 'discount_percent' => 15,
            'deposit_percent' => 10, 'deposit_cents' => 25500, 'balance_cents' => 229500,
            'platform_fee_cents' => 50000, 'per_agent_fee_cents' => 2000, 'min_agents' => 5, 'agent_count' => $agents,
            'term_months' => 12, 'starts_on' => $goLive, 'ends_on' => Carbon::parse($goLive)->addMonthsNoOverflow(12)->subDay()->toDateString(),
            'company_legal_name' => 'Mayura Consultancy Services LLC', 'company_dba' => 'RightAlly', 'company_address' => '66 West Flagler Street, Suite 900, Miami, FL 33130',
            'company_signatory_name' => 'Srini', 'company_signatory_title' => 'Co-Founder', 'client_typed_name' => 'Maria Alvarez', 'client_title' => 'Managing Broker',
            'rendered_html' => '<p>Terms</p>',
        ]);

        return $customer->fresh();
    }

    protected function stripeWebhook(array $event)
    {
        $payload = json_encode($event + ['livemode' => false]);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::sign($payload, 'whsec_test'),
        ], $payload);
    }
}
