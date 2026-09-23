<?php

namespace Tests\Feature\Billing;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StripeEvent;
use App\Services\Admin\DashboardStats;
use App\Services\Billing\DepositService;
use App\Services\Settings\SettingsService;
use App\Support\BusinessClock;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesBilling;
use Tests\TestCase;

class RobustnessTest extends TestCase
{
    use FakesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-10-23 09:00', 'America/New_York'));
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paidInvoice(Customer $c, int $amount = 66000, int $tax = 0): Payment
    {
        $inv = Invoice::create(['number' => 'INV-2026-0100', 'customer_id' => $c->id, 'type' => InvoiceType::Monthly, 'status' => InvoiceStatus::Paid,
            'amount_cents' => $amount, 'tax_cents' => $tax, 'due_on' => '2026-10-20', 'paid_at' => now(), 'stripe_invoice_id' => 'in_m']);

        return Payment::create(['invoice_id' => $inv->id, 'customer_id' => $c->id, 'stripe_charge_id' => 'ch_1', 'method' => 'card',
            'amount_cents' => $amount + $tax, 'status' => 'succeeded', 'settled_at' => now()]);
    }

    private function revenue(): int
    {
        $s = app(DashboardStats::class);

        return $s->revenueCents(BusinessClock::now()->startOfMonth(), BusinessClock::now()->endOfDay());
    }

    // ---- Tax ------------------------------------------------------------

    public function test_with_tax_on_the_deposit_includes_stripe_tax_and_is_recorded_when_paid(): void
    {
        $this->stripe['POST tax/calculations'] = ['id' => 'taxcalc_1', 'tax_amount_exclusive' => 1650];
        $this->stripe['POST tax/transactions/create_from_calculation'] = ['id' => 'tax_tx_1'];
        $this->stripe['POST customers'] = ['id' => 'cus_1'];
        $pi = ['id' => 'pi_1', 'status' => 'requires_payment_method', 'client_secret' => 'pi_1_secret', 'amount' => 27150];
        $this->stripe['POST payment_intents'] = $pi;
        $this->fakeBilling();
        app(SettingsService::class)->setMany('tax', ['enabled' => '1']);

        $customer = $this->billedCustomer(CustomerStatus::ContractSigned);
        $contract = Contract::first();
        $prepared = app(DepositService::class)->prepare($customer, $contract);

        $this->assertSame(1650, $prepared['invoice']->fresh()->tax_cents);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/payment_intents') && $r['amount'] == 25500 + 1650);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'tax/calculations') && $r['customer_details']['address']['state'] === 'FL' && $r['line_items'][0]['tax_code'] === 'txcd_10103001');

        app(DepositService::class)->apply(['id' => 'pi_1', 'status' => 'succeeded', 'amount_received' => 27150,
            'payment_method' => ['id' => 'pm_1', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']], 'latest_charge' => ['id' => 'ch_dep']]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'tax/transactions/create_from_calculation') && $r['calculation'] === 'taxcalc_1');
        $this->assertSame(25500, $this->revenue()); // tax isn't revenue
    }

    public function test_with_tax_on_balance_invoices_and_subscriptions_use_automatic_tax(): void
    {
        $this->fakeBilling();
        app(SettingsService::class)->setMany('tax', ['enabled' => '1']);
        $this->billedCustomer();

        $this->artisan('billing:daily')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/invoices') && $r['automatic_tax']['enabled'] === 'true');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/subscriptions') && $r['automatic_tax']['enabled'] === 'true');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/prices') && $r['tax_behavior'] === 'exclusive');
    }

    public function test_without_tax_no_tax_parameters_are_sent(): void
    {
        $this->fakeBilling();
        $this->billedCustomer();
        $this->artisan('billing:daily');
        Http::assertNotSent(fn (Request $r) => isset($r['automatic_tax']));
    }

    // ---- Bank confirmation (3-D Secure) --------------------------------

    public function test_payment_needing_bank_confirmation_emails_a_confirm_link_once(): void
    {
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $event = fn ($id) => ['id' => $id, 'type' => 'invoice.payment_action_required', 'data' => ['object' => [
            'id' => 'in_sca', 'subscription' => 'sub_1', 'amount_due' => 66000, 'created' => now()->getTimestamp(),
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/in_sca', 'lines' => ['data' => []]]]];

        $this->stripeWebhook($event('evt_a1'))->assertOk();
        $this->stripeWebhook($event('evt_a2'))->assertOk();

        $invoice = Invoice::where('stripe_invoice_id', 'in_sca')->firstOrFail();
        $this->assertSame(InvoiceStatus::Failed, $invoice->status);
        $this->assertSame('Your bank needs you to confirm this payment', $invoice->failure_reason);
        $this->assertSame(CustomerStatus::PaymentFailed, $customer->fresh()->status);
        $this->assertSame(1, EmailLog::where('template_key', 'payment_action_required')->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'brevo') && str_contains($r['htmlContent'], 'https://invoice.stripe.com/i/in_sca'));
    }

    // ---- Refunds and chargebacks ----------------------------------------

    public function test_refund_reduces_revenue(): void
    {
        $this->fakeBilling();
        $payment = $this->paidInvoice($this->billedCustomer(CustomerStatus::Live));
        $this->assertSame(66000, $this->revenue());

        $this->stripeWebhook(['id' => 'evt_r', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1', 'amount_refunded' => 16000]]])->assertOk();

        $this->assertSame(16000, $payment->fresh()->refunded_cents);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame(50000, $this->revenue());
    }

    public function test_chargeback_alerts_the_team_and_shows_on_the_dashboard(): void
    {
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $payment = $this->paidInvoice($customer);
        $dispute = ['id' => 'dp_1', 'charge' => 'ch_1', 'amount' => 66000, 'reason' => 'product_not_received', 'status' => 'needs_response',
            'evidence_details' => ['due_by' => Carbon::parse('2026-11-05')->getTimestamp()]];

        $this->stripeWebhook(['id' => 'evt_d', 'type' => 'charge.dispute.created', 'data' => ['object' => $dispute]])->assertOk();

        $payment->refresh();
        $this->assertSame('disputed', $payment->status);
        $this->assertSame(0, $this->revenue());
        $alert = EmailLog::where('template_key', 'dispute_alert')->firstOrFail();
        $this->assertSame('m.sunil@rightally.io', $alert->to_email);
        $this->assertSame(['srini@rightally.io'], $alert->cc);
        $this->assertSame('Chargeback: Sunline Realty Group disputed $660.00', $alert->subject);
        $this->assertStringContainsString('Chargeback on INV-2026-0100', app(DashboardStats::class)->attention()->pluck('text')->implode('|'));

        $this->stripeWebhook(['id' => 'evt_dc', 'type' => 'charge.dispute.closed', 'data' => ['object' => ['status' => 'lost'] + $dispute]])->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(66000, $payment->fresh()->refunded_cents);
    }

    public function test_won_chargeback_counts_as_revenue_again(): void
    {
        $this->fakeBilling();
        $payment = $this->paidInvoice($this->billedCustomer(CustomerStatus::Live));
        $dispute = ['id' => 'dp_2', 'charge' => 'ch_1', 'amount' => 66000, 'status' => 'needs_response'];
        $this->stripeWebhook(['id' => 'evt_d2', 'type' => 'charge.dispute.created', 'data' => ['object' => $dispute]]);
        $this->stripeWebhook(['id' => 'evt_dw', 'type' => 'charge.dispute.closed', 'data' => ['object' => ['status' => 'won'] + $dispute]]);

        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame(66000, $this->revenue());
    }

    // ---- Reconciliation --------------------------------------------------

    public function test_nightly_reconciliation_applies_events_the_webhook_missed(): void
    {
        $this->stripe['GET events'] = ['has_more' => false, 'data' => [
            ['id' => 'evt_missed', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1', 'amount_refunded' => 66000]]],
            ['id' => 'evt_seen', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1', 'amount_refunded' => 1]]],
        ]];
        $this->fakeBilling();
        $payment = $this->paidInvoice($this->billedCustomer(CustomerStatus::Live));
        StripeEvent::create(['stripe_event_id' => 'evt_seen', 'type' => 'charge.refunded', 'payload' => [], 'processed_at' => now()]);

        $this->artisan('billing:reconcile')->expectsOutputToContain('applied now: 1')->assertSuccessful();

        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertNotNull(StripeEvent::firstWhere('stripe_event_id', 'evt_missed')->processed_at);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/events') && str_contains(urldecode($r->url()), 'types[0]=payment_intent.succeeded'));
    }

    // ---- Signed but unpaid ----------------------------------------------

    public function test_clients_who_signed_but_did_not_pay_get_two_reminders(): void
    {
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::ContractSigned);
        Contract::first()->forceFill(['signed_at' => now()])->save();

        Carbon::setTestNow(now()->addHours(23));
        $this->artisan('onboarding:reminders');
        $this->assertSame(0, EmailLog::where('template_key', 'deposit_reminder')->count());

        Carbon::setTestNow(now()->addHours(2));  // 25h
        $this->artisan('onboarding:reminders');
        $this->artisan('onboarding:reminders');
        $this->assertSame(1, EmailLog::where('template_key', 'deposit_reminder')->count());

        Carbon::setTestNow(now()->addHours(48)); // 73h
        $this->artisan('onboarding:reminders');
        Carbon::setTestNow(now()->addDays(10));
        $this->artisan('onboarding:reminders');
        $this->assertSame(2, EmailLog::where('template_key', 'deposit_reminder')->count());
        $this->assertSame(2, $customer->fresh()->deposit_reminders_sent);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'brevo') && str_contains($r['htmlContent'], '/payment?expires='));
    }

    public function test_paid_clients_get_no_deposit_reminders(): void
    {
        $this->fakeBilling();
        $this->billedCustomer(CustomerStatus::AwaitingGoLive);
        Carbon::setTestNow(now()->addDays(4));
        $this->artisan('onboarding:reminders');
        $this->assertSame(0, EmailLog::where('template_key', 'deposit_reminder')->count());
    }
}
