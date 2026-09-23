<?php

namespace Tests\Feature\Billing;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StripeEvent;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\WebhookSignature;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DepositPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const WHSEC = 'whsec_test_secret';

    /** Current state of the fake PaymentIntent. */
    private array $pi = ['id' => 'pi_123', 'status' => 'requires_payment_method', 'client_secret' => 'pi_123_secret_abc', 'amount_received' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-09-23 14:00:00', 'America/New_York'));
        $this->seed([ContractTemplateSeeder::class, EmailTemplateSeeder::class]);

        $settings = app(SettingsService::class);
        $settings->setMany('stripe', ['mode' => 'test', 'test_publishable_key' => 'pk_test_x', 'test_secret_key' => 'sk_test_x', 'test_webhook_secret' => self::WHSEC]);
        $settings->setMany('email', ['brevo_api_key' => 'xkeysib-test']);

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'api.brevo.com') => Http::response(['messageId' => '<msg-1@brevo>'], 201),
                str_ends_with($url, '/v1/customers') => Http::response(['id' => 'cus_123'], 200),
                str_contains($url, '/v1/customers/') => Http::response(['id' => 'cus_123'], 200),
                str_ends_with($url, '/v1/payment_intents') && $request->method() === 'POST' => Http::response($this->pi, 200),
                str_contains($url, '/v1/payment_intents/') => Http::response($this->pi, 200),
                default => Http::response(['error' => ['message' => 'unexpected '.$url]], 500),
            };
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signedCustomer(): Customer
    {
        $this->post('/start', [
            'first_name' => 'Maria', 'last_name' => 'Alvarez', 'title' => 'Managing Broker', 'company_name' => 'Sunline Realty Group',
            'email' => 'maria@sunlinerealty.com', 'phone' => '3055550148', 'agents' => 8,
            'street' => '1200 Brickell Ave', 'city' => 'Miami', 'state_code' => 'FL', 'zip' => '33131',
        ]);
        $customer = Customer::firstOrFail();
        $img = imagecreatetruecolor(300, 100);
        imageline($img, 10, 50, 290, 40, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);
        $this->post("/onboard/{$customer->uuid}/agreement/sign", ['consent' => '1', 'typed_name' => 'Maria Alvarez', 'signature' => 'data:image/png;base64,'.base64_encode((string) ob_get_clean())]);

        return $customer->fresh();
    }

    private function succeeded(string $type = 'card'): array
    {
        $pm = $type === 'card'
            ? ['id' => 'pm_card', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']]
            : ['id' => 'pm_bank', 'type' => 'us_bank_account', 'us_bank_account' => ['bank_name' => 'Chase', 'last4' => '6789']];

        return ['id' => 'pi_123', 'status' => 'succeeded', 'client_secret' => 'pi_123_secret_abc', 'amount_received' => 30000,
            'payment_method' => $pm, 'latest_charge' => ['id' => 'ch_1', 'balance_transaction' => ['fee' => 900]]];
    }

    private function webhook(array $event)
    {
        $payload = json_encode($event);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::sign($payload, self::WHSEC),
        ], $payload);
    }

    public function test_payment_page_creates_the_stripe_customer_and_a_payment_intent_for_the_deposit(): void
    {
        $customer = $this->signedCustomer();

        $this->get("/onboard/{$customer->uuid}/payment")->assertOk()
            ->assertSee('data-secret="pi_123_secret_abc"', false)
            ->assertSee('Pay $300.00 now');

        $invoice = Invoice::firstOrFail();
        $this->assertSame(30000, $invoice->amount_cents);
        $this->assertMatchesRegularExpression('/^INV-2026-\d{4}$/', $invoice->number);
        $this->assertSame('pi_123', $invoice->stripe_payment_intent_id);
        $this->assertSame('cus_123', $customer->fresh()->stripe_customer_id);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/payment_intents')
            && $r['amount'] == 30000 && $r['setup_future_usage'] === 'off_session'
            && $r['payment_method_types'] === ['card', 'us_bank_account'] && $r['customer'] === 'cus_123'
            && $r->hasHeader('Idempotency-Key'));

        // Reloading the page reuses the same PaymentIntent.
        $this->get("/onboard/{$customer->uuid}/payment")->assertOk();
        // Stripe: create customer, create PaymentIntent, then (on reload) retrieve it.
        $this->assertCount(3, Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.stripe.com')));
    }

    public function test_card_payment_marks_deposit_paid_saves_the_card_and_sends_the_welcome_email(): void
    {
        $customer = $this->signedCustomer();
        $this->get("/onboard/{$customer->uuid}/payment");
        $this->pi = $this->succeeded();

        $this->get("/onboard/{$customer->uuid}/payment/return?payment_intent=pi_123")->assertRedirect("/onboard/{$customer->uuid}/done");
        $this->get("/onboard/{$customer->uuid}/done")->assertOk()->assertSee('Welcome to RightAlly, Maria');

        $invoice = Invoice::firstOrFail();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $customer->refresh();
        $this->assertSame(CustomerStatus::AwaitingGoLive, $customer->status);
        $this->assertSame('Visa ending 4242', $customer->payment_method_label);
        $this->assertSame('pm_card', $customer->stripe_payment_method_id);

        $payment = Payment::firstOrFail();
        $this->assertSame('succeeded', $payment->status);
        $this->assertSame(900, $payment->fee_cents);
        $this->assertNotNull($payment->settled_at);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/customers/cus_123') && $r['invoice_settings']['default_payment_method'] === 'pm_card');

        $mail = EmailLog::where('template_key', 'agreement_signed')->firstOrFail();
        $this->assertSame('sent', $mail->status);
        $this->assertSame(['m.sunil@rightally.io', 'srini@rightally.io'], $mail->cc);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.brevo.com')
            && $r['subject'] === 'Your RightAlly agreement is signed'
            && str_contains($r['htmlContent'], 'deposit of $300.00 is paid')
            && count($r['attachment']) === 1 && str_ends_with($r['attachment'][0]['name'], '.pdf'));
    }

    public function test_webhook_and_return_url_together_apply_the_payment_once(): void
    {
        $customer = $this->signedCustomer();
        $this->get("/onboard/{$customer->uuid}/payment");
        $this->pi = $this->succeeded();
        $event = ['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'livemode' => false, 'data' => ['object' => ['id' => 'pi_123', 'metadata' => ['type' => 'deposit']]]];

        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk()->assertSee('Already processed');
        $this->get("/onboard/{$customer->uuid}/payment/return?payment_intent=pi_123");

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, EmailLog::where('template_key', 'agreement_signed')->count());
        $this->assertNotNull(StripeEvent::firstWhere('stripe_event_id', 'evt_1')->processed_at);
    }

    public function test_webhook_with_a_bad_signature_is_rejected(): void
    {
        $this->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad'], '{"id":"evt_x","type":"payment_intent.succeeded"}')
            ->assertStatus(400);
        $this->assertSame(0, StripeEvent::count());
    }

    public function test_bank_payment_is_processing_then_clears_with_a_receipt(): void
    {
        $customer = $this->signedCustomer();
        $this->get("/onboard/{$customer->uuid}/payment");
        $this->pi = ['status' => 'processing'] + $this->succeeded('bank');

        $this->get("/onboard/{$customer->uuid}/payment/return?payment_intent=pi_123")->assertRedirect("/onboard/{$customer->uuid}/done");
        $this->get("/onboard/{$customer->uuid}/done")->assertSee('bank payment');
        $this->assertSame(InvoiceStatus::Processing, Invoice::first()->status);
        $this->assertSame(CustomerStatus::ContractSigned, $customer->fresh()->status);
        $this->assertStringContainsString('being processed by your bank', (string) Http::recorded()->last()[0]['htmlContent']);

        $this->pi = $this->succeeded('bank');
        $this->webhook(['id' => 'evt_2', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_123', 'metadata' => ['type' => 'deposit']]]])->assertOk();

        $this->assertSame(InvoiceStatus::Paid, Invoice::first()->status);
        $this->assertSame(CustomerStatus::AwaitingGoLive, $customer->fresh()->status);
        $this->assertSame('Chase ending 6789', $customer->fresh()->payment_method_label);
        $this->assertSame(1, EmailLog::where('template_key', 'agreement_signed')->count());
        $this->assertSame(1, EmailLog::where('template_key', 'deposit_receipt')->count());
    }

    public function test_failed_payment_lets_the_client_try_again(): void
    {
        $customer = $this->signedCustomer();
        $this->get("/onboard/{$customer->uuid}/payment");
        $this->pi = ['status' => 'requires_payment_method', 'last_payment_error' => ['message' => 'Your card was declined.']] + $this->pi;

        $this->get("/onboard/{$customer->uuid}/payment/return?payment_intent=pi_123")->assertRedirect("/onboard/{$customer->uuid}/payment");
        $this->assertSame(InvoiceStatus::Failed, Invoice::first()->status);
        $this->get("/onboard/{$customer->uuid}/payment")->assertOk()->assertSee('Your card was declined.');
        $this->get("/onboard/{$customer->uuid}/done")->assertRedirect("/onboard/{$customer->uuid}/payment");
    }

    public function test_payment_page_explains_when_stripe_is_not_set_up(): void
    {
        app(SettingsService::class)->setMany('stripe', ['test_secret_key' => null]);
        app(SettingsService::class)->forget('stripe', 'test_secret_key');
        $customer = $this->signedCustomer();

        $this->get("/onboard/{$customer->uuid}/payment")->assertOk()->assertSee('Online payment isn’t available right now');
        $this->assertSame(0, Invoice::count());
    }

    public function test_return_url_ignores_a_payment_intent_that_isnt_this_customers(): void
    {
        $customer = $this->signedCustomer();
        $this->get("/onboard/{$customer->uuid}/payment");

        $this->get("/onboard/{$customer->uuid}/payment/return?payment_intent=pi_someone_else")->assertRedirect("/onboard/{$customer->uuid}/payment");
        $this->assertSame(InvoiceStatus::Scheduled, Invoice::first()->status);
    }
}
