<?php

namespace Tests\Feature\Billing;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Admin\ReportStats;
use App\Services\Billing\EarlyTerminationService;
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

class AnnualBillingTest extends TestCase
{
    use FakesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-09-23 10:00', 'America/New_York'));
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_client_can_choose_yearly_billing_and_the_agreement_says_so(): void
    {
        $this->get('/')->assertSee('Subscription billing')->assertSee('Save 10%');

        $this->post('/start', ['first_name' => 'Maria', 'last_name' => 'Alvarez', 'title' => 'Owner', 'company_name' => 'Sunline Realty Group',
            'email' => 'maria@sunlinerealty.com', 'phone' => '3055550148', 'agents' => 8, 'street' => '1 Main St', 'city' => 'Miami',
            'state_code' => 'FL', 'zip' => '33131', 'billing' => 'year'])->assertRedirect();

        $contract = Contract::firstOrFail();
        $this->assertSame('year', $contract->billing_interval);
        $this->assertSame(540000, $contract->platformYearCents());        // $500 × 12 less 10%
        $this->assertSame(21600, $contract->perAgentYearCents());         // $20 × 12 less 10%
        $this->assertSame(540000 + 8 * 21600, $contract->annualFeeCents()); // $7,128
        $this->assertSame(59400, $contract->monthlyEquivalentCents());

        $customer = Customer::firstOrFail();
        $this->get("/onboard/{$customer->uuid}/agreement")->assertOk()
            ->assertSee('Client has chosen annual billing')->assertSee('$7,128.00')->assertSee('Billed yearly in advance');
    }

    public function test_yearly_subscription_uses_yearly_prices_and_invoices(): void
    {
        $this->fakeBilling();
        Carbon::setTestNow(Carbon::parse('2026-10-23 09:00', 'America/New_York'));
        $customer = $this->billedCustomer();
        Contract::first()->update(['billing_interval' => 'year', 'annual_discount_percent' => 10]);

        $this->artisan('billing:daily')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/prices') && $r['recurring']['interval'] === 'year' && $r['unit_amount'] == 540000);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/prices') && $r['lookup_key'] === 'rightally_agent_yearly_21600');
        $this->assertSame(CustomerStatus::Live, $customer->fresh()->status);

        // Stripe bills the first year 30 days after go-live.
        $this->stripeWebhook(['id' => 'evt_y', 'type' => 'invoice.paid', 'data' => ['object' => [
            'id' => 'in_year', 'subscription' => 'sub_1', 'amount_due' => 712800, 'amount_paid' => 712800, 'created' => now()->getTimestamp(),
            'charge' => 'ch_y', 'lines' => ['data' => [['period' => ['start' => Carbon::parse('2026-11-22')->getTimestamp(), 'end' => Carbon::parse('2027-11-22')->getTimestamp()]]]],
        ]]])->assertOk();

        $invoice = Invoice::where('stripe_invoice_id', 'in_year')->firstOrFail();
        $this->assertSame(InvoiceType::Annual, $invoice->type);
        $this->assertSame(0, app(EarlyTerminationService::class)->quote($customer->fresh())['months']); // the whole term is prepaid
        $this->assertSame(59400, app(ReportStats::class)->mrr()['mrr']);
    }

    public function test_yearly_option_can_be_turned_off(): void
    {
        app(\App\Services\Settings\SettingsService::class)->setMany('pricing', ['annual_enabled' => '0']);
        $this->get('/')->assertDontSee('Subscription billing');
    }
}
