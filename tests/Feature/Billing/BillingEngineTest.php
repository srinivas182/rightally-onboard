<?php

namespace Tests\Feature\Billing;

use App\Enums\AgentCountSource;
use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Services\Billing\AgentCountService;
use App\Services\Billing\EarlyTerminationService;
use App\Services\Billing\GoLiveService;
use App\Services\Billing\RenewalService;
use App\Services\Settings\SettingsService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakesBilling;
use Tests\TestCase;

class BillingEngineTest extends TestCase
{
    use FakesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
        $this->travelToMiami('2026-10-23 09:00');
        $this->fakeBilling();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function travelToMiami(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when, 'America/New_York'));
    }

    private function emails(string $key): int
    {
        return EmailLog::where('template_key', $key)->count();
    }

    // ---- Go-live balance -------------------------------------------------

    public function test_balance_is_charged_on_the_go_live_date_and_the_subscription_starts(): void
    {
        $customer = $this->billedCustomer();

        $this->artisan('billing:daily')->assertSuccessful();

        $invoice = Invoice::where('type', InvoiceType::Balance)->firstOrFail();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(229500, $invoice->amount_cents);
        $customer->refresh();
        $this->assertSame(CustomerStatus::Live, $customer->status);
        $this->assertNotNull($customer->live_at);
        $this->assertSame('sub_1', $customer->stripe_subscription_id);
        $this->assertSame('si_agent', $customer->stripe_agent_item_id);
        $this->assertSame(1, $this->emails('balance_paid'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/invoiceitems') && $r['amount'] == 229500);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/subscriptions')
            && $r['items'][1]['quantity'] == 8
            && (int) $r['trial_end'] === Carbon::parse('2026-11-22 09:00', 'America/New_York')->getTimestamp()
            && $r['proration_behavior'] === 'none');

        // Running again the same day charges nothing more.
        $this->artisan('billing:daily')->assertSuccessful();
        $this->assertSame(1, Invoice::where('type', InvoiceType::Balance)->count());
    }

    public function test_balance_is_not_charged_before_the_go_live_date(): void
    {
        $this->billedCustomer(goLive: '2026-10-24');
        $this->artisan('billing:daily')->assertSuccessful();
        $this->assertSame(0, Invoice::count());
    }

    public function test_declined_balance_marks_the_customer_and_emails_a_payment_link(): void
    {
        $this->stripe['POST invoices/in_1/pay'] = ['__status' => 402, 'json' => ['error' => ['type' => 'card_error', 'message' => 'Your card was declined.']]];
        $customer = $this->billedCustomer();

        $this->artisan('billing:daily')->assertSuccessful();

        $invoice = Invoice::firstOrFail();
        $this->assertSame(InvoiceStatus::Failed, $invoice->status);
        $this->assertSame('Your card was declined.', $invoice->failure_reason);
        $this->assertSame(CustomerStatus::BalanceFailed, $customer->fresh()->status);
        $this->assertSame(1, $this->emails('balance_failed'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'brevo') && str_contains($r['htmlContent'], 'https://invoice.stripe.com/i/in_1'));

        // Client pays on Stripe's page later: webhook makes them live.
        $this->stripeWebhook(['id' => 'evt_p', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_1', 'amount_paid' => 229500, 'charge' => 'ch_9']]])->assertOk();
        $this->assertSame(CustomerStatus::Live, $customer->fresh()->status);
        $this->assertSame('sub_1', $customer->fresh()->stripe_subscription_id);
    }

    public function test_balance_reminder_goes_out_three_days_before_and_only_once(): void
    {
        $this->travelToMiami('2026-10-20 09:00');
        $customer = $this->billedCustomer();

        $this->artisan('billing:daily');
        $this->artisan('billing:daily');
        $this->assertSame(1, $this->emails('balance_reminder'));
        $this->assertSame('2026-10-23', $customer->fresh()->balance_reminder_for->toDateString());
    }

    // ---- Go-live date changes --------------------------------------------

    public function test_go_live_date_can_move_until_two_days_before(): void
    {
        $this->travelToMiami('2026-10-15 10:00');
        $customer = $this->billedCustomer();

        app(GoLiveService::class)->change($customer, '2026-11-02');

        $customer->refresh();
        $this->assertSame('2026-11-02', $customer->go_live_date->toDateString());
        $contract = Contract::first();
        $this->assertSame('2026-11-02', $contract->starts_on->toDateString());
        $this->assertSame('2027-11-01', $contract->ends_on->toDateString());
        $this->assertSame(1, $this->emails('go_live_changed'));

        $this->travelToMiami('2026-10-31 10:00'); // two days before Nov 2: locked
        $this->expectException(ValidationException::class);
        app(GoLiveService::class)->change($customer->fresh(), '2026-11-10');
    }

    public function test_new_go_live_date_needs_two_days_notice(): void
    {
        $this->travelToMiami('2026-10-15 10:00');
        $customer = $this->billedCustomer();

        $this->expectException(ValidationException::class);
        app(GoLiveService::class)->change($customer, '2026-10-16');
    }

    // ---- Monthly charges, failures, suspension ---------------------------

    private function monthlyInvoice(string $id = 'in_m1', int $amount = 66000): array
    {
        return ['id' => $id, 'subscription' => 'sub_1', 'amount_due' => $amount, 'amount_paid' => $amount, 'created' => now()->getTimestamp(),
            'hosted_invoice_url' => "https://invoice.stripe.com/i/{$id}", 'charge' => "ch_{$id}", 'attempt_count' => 1,
            'lines' => ['data' => [
                ['subscription_item' => 'si_platform', 'quantity' => 1, 'period' => ['start' => strtotime('2026-11-22'), 'end' => strtotime('2026-12-22')]],
                ['subscription_item' => 'si_agent', 'quantity' => 8, 'period' => ['start' => strtotime('2026-11-22'), 'end' => strtotime('2026-12-22')]],
            ]]];
    }

    public function test_monthly_payment_records_an_invoice_and_emails_a_receipt(): void
    {
        $this->billedCustomer(CustomerStatus::Live);

        $this->stripeWebhook(['id' => 'evt_m', 'type' => 'invoice.paid', 'data' => ['object' => $this->monthlyInvoice()]])->assertOk();

        $invoice = Invoice::where('type', InvoiceType::Monthly)->firstOrFail();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(66000, $invoice->amount_cents);
        $this->assertSame(8, $invoice->agents_billed);
        $this->assertSame('2026-11-22', $invoice->period_start->toDateString());
        $this->assertSame(1, $this->emails('monthly_receipt'));
    }

    public function test_zero_amount_trial_invoice_is_ignored(): void
    {
        $this->billedCustomer(CustomerStatus::Live);
        $this->stripeWebhook(['id' => 'evt_0', 'type' => 'invoice.paid', 'data' => ['object' => ['amount_due' => 0] + $this->monthlyInvoice('in_0')]])->assertOk();
        $this->assertSame(0, Invoice::count());
    }

    public function test_failed_monthly_payment_emails_once_suspends_after_30_days_and_restores_when_paid(): void
    {
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $failed = ['id' => 'evt_f1', 'type' => 'invoice.payment_failed', 'data' => ['object' => $this->monthlyInvoice()]];

        $this->stripeWebhook($failed)->assertOk();
        $this->stripeWebhook(['id' => 'evt_f2'] + $failed)->assertOk(); // Stripe's retry fails again

        $this->assertSame(CustomerStatus::PaymentFailed, $customer->fresh()->status);
        $this->assertSame(1, $this->emails('payment_failed'));

        $this->travelToMiami('2026-11-21 09:00'); // 29 days
        $this->artisan('billing:daily');
        $this->assertSame(CustomerStatus::PaymentFailed, $customer->fresh()->status);

        $this->travelToMiami('2026-11-23 09:00'); // 31 days
        $this->artisan('billing:daily');
        $this->assertSame(CustomerStatus::Suspended, $customer->fresh()->status);
        $this->assertSame(1, $this->emails('account_suspended'));

        $this->stripeWebhook(['id' => 'evt_ok', 'type' => 'invoice.paid', 'data' => ['object' => $this->monthlyInvoice()]])->assertOk();
        $customer->refresh();
        $this->assertSame(CustomerStatus::Live, $customer->status);
        $this->assertNull($customer->suspended_at);
    }

    // ---- Agent counts ----------------------------------------------------

    public function test_agent_count_never_goes_below_the_minimum_and_updates_stripe(): void
    {
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $service = app(AgentCountService::class);

        $this->assertSame(5, $service->set($customer, 3, AgentCountSource::Admin, Admin::factory()->create()));
        $this->assertSame(12, $service->set($customer->fresh(), 12, AgentCountSource::Admin));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'subscription_items/si_agent') && $r['quantity'] == 12 && $r['proration_behavior'] === 'none');
        $this->assertSame(2, $customer->agentCountLogs()->count());
    }

    public function test_customer_instance_can_report_agents_with_its_token(): void
    {
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $token = app(AgentCountService::class)->newToken($customer);

        $this->postJson('/api/v1/agent-count', ['agents' => 20], ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJson(['agents_reported' => 20, 'agents_billed' => 20]);
        $this->assertSame(20, $customer->fresh()->agent_count);

        $this->postJson('/api/v1/agent-count', ['agents' => 30], ['Authorization' => 'Bearer wrong'])->assertStatus(401);
        $this->assertSame(20, $customer->fresh()->agent_count);
        $this->assertNotSame($token, $customer->fresh()->getRawOriginal('agent_api_token')); // stored encrypted
    }

    public function test_daily_pull_reads_the_count_from_the_customer_instance(): void
    {
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $customer->update(['live_url' => 'https://sunline.rightally.io']);
        app(AgentCountService::class)->newToken($customer);
        Http::fake(['sunline.rightally.io/*' => Http::response(['agents' => 15])]);

        $this->artisan('agents:sync')->assertSuccessful();
        $this->assertSame(15, $customer->fresh()->agent_count);
    }

    // ---- Early termination ----------------------------------------------

    public function test_early_termination_charges_the_remaining_months_and_ends_the_subscription(): void
    {
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $this->stripeWebhook(['id' => 'evt_m', 'type' => 'invoice.paid', 'data' => ['object' => $this->monthlyInvoice()]]);
        $this->stripeWebhook(['id' => 'evt_m2', 'type' => 'invoice.paid', 'data' => ['object' => $this->monthlyInvoice('in_m2')]]);

        $quote = app(EarlyTerminationService::class)->quote($customer->fresh());
        $this->assertSame(['months' => 10, 'amount_cents' => 660000, 'monthly_cents' => 66000, 'unpaid_cents' => 0], $quote);

        $invoice = app(EarlyTerminationService::class)->terminate($customer->fresh(), Admin::factory()->create(), 'Client closing the brokerage');

        $this->assertSame(InvoiceType::EarlyTermination, $invoice->type);
        $this->assertSame(660000, $invoice->amount_cents);
        $this->assertSame(CustomerStatus::Cancelled, $customer->fresh()->status);
        $this->assertSame(ContractStatus::Terminated, Contract::first()->status);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'subscriptions/sub_1'));
        $this->assertSame(1, $this->emails('early_termination'));
    }

    // ---- Renewals --------------------------------------------------------

    public function test_renewal_is_offered_at_45_days_reminded_at_15_and_signed_from_the_link(): void
    {
        app(SettingsService::class)->setMany('renewal', ['platform_fee' => '550.00', 'per_agent_fee' => null]);
        $customer = $this->billedCustomer(CustomerStatus::Live, goLive: '2026-10-23'); // term ends 2027-10-22

        $this->travelToMiami('2027-09-06 09:00'); // 46 days before
        $this->artisan('billing:daily');
        $this->assertSame(0, $this->emails('renewal_offer'));

        $this->travelToMiami('2027-09-07 09:00'); // 45 days before
        $this->artisan('billing:daily');
        $this->artisan('billing:daily');
        $this->assertSame(1, $this->emails('renewal_offer'));

        $renewal = Contract::where('type', 'renewal')->firstOrFail();
        $this->assertSame(55000, $renewal->platform_fee_cents);    // from Settings > Renewal pricing
        $this->assertSame(2000, $renewal->per_agent_fee_cents);    // blank: original rate
        $this->assertSame(0, $renewal->setup_fee_cents);
        $this->assertSame('2027-10-23', $renewal->starts_on->toDateString());
        $this->assertSame('2028-10-22', $renewal->ends_on->toDateString());

        $this->travelToMiami('2027-10-07 09:00'); // 15 days before
        $this->artisan('billing:daily');
        $this->assertSame(1, $this->emails('renewal_reminder'));

        $link = app(RenewalService::class)->renewalLink($renewal);
        $this->get($link)->assertOk()->assertSee('Renew your RightAlly agreement')->assertSee('$550.00')->assertSee('RA-2026-0001');
        $this->get("/renew/{$renewal->uuid}")->assertForbidden(); // unsigned link

        $img = imagecreatetruecolor(300, 100);
        imageline($img, 10, 50, 290, 40, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);
        $png = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
        $signUrl = \URL::temporarySignedRoute('renewal.sign', now()->addHour(), ['contract' => $renewal->uuid]);
        $this->post($signUrl, ['consent' => '1', 'typed_name' => 'Maria Alvarez', 'signature' => $png])->assertRedirect();
        $this->assertSame(ContractStatus::Signed, $renewal->fresh()->status);
        $this->assertSame(CustomerStatus::Live, $customer->fresh()->status);

        // Term end passes: signed renewal means nothing expires; on its start date the prices switch.
        $this->stripe['GET subscriptions'] = ['id' => 'sub_1', 'items' => ['data' => [['id' => 'si_platform'], ['id' => 'si_agent']]]];
        $this->travelToMiami('2027-10-23 09:00');
        $this->artisan('billing:daily');
        $this->assertSame(CustomerStatus::Live, $customer->fresh()->status);
        $this->assertSame(ContractStatus::Superseded, Contract::where('type', 'initial')->first()->status);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), 'subscriptions/sub_1') && $r['items'][0]['price'] === 'price_rightally_platform_monthly_55000');
    }

    public function test_agreement_expires_when_the_renewal_is_not_signed(): void
    {
        $customer = $this->billedCustomer(CustomerStatus::Live, goLive: '2026-10-23');

        $this->travelToMiami('2027-09-07 09:00');
        $this->artisan('billing:daily');
        $this->travelToMiami('2027-10-22 09:00'); // last day of the term
        $this->artisan('billing:daily');
        $this->assertSame(CustomerStatus::Live, $customer->fresh()->status);

        $this->travelToMiami('2027-10-23 09:00');
        $this->artisan('billing:daily');

        $this->assertSame(CustomerStatus::Expired, $customer->fresh()->status);
        $this->assertSame(ContractStatus::Expired, Contract::where('type', 'initial')->first()->status);
        $this->assertSame(1, $this->emails('agreement_expired'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'subscriptions/sub_1'));
    }
}
