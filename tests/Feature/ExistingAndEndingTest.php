<?php

namespace Tests\Feature;

use App\Enums\ContractType;
use App\Enums\CustomerStatus;
use App\Models\Admin;
use App\Models\Approval;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
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

class ExistingAndEndingTest extends TestCase
{
    use FakesBilling, RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-09-29 10:00', 'America/New_York'));
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
        $this->admin = Admin::factory()->superAdmin()->withTwoFactor()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sign(Customer $c): void
    {
        $img = imagecreatetruecolor(300, 100);
        imageline($img, 10, 50, 290, 40, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);
        $this->post("/onboard/{$c->uuid}/agreement/sign", ['consent' => '1', 'typed_name' => $c->fullName(), 'signature' => 'data:image/png;base64,'.base64_encode((string) ob_get_clean())])->assertRedirect();
    }

    public function test_existing_client_signs_keeps_their_card_and_billing_moves_over_without_double_charging(): void
    {
        $oldEnd = Carbon::parse('2026-10-15 12:00', 'UTC')->getTimestamp();
        $this->stripe['GET subscriptions'] = ['id' => 'sub_old', 'customer' => 'cus_old', 'status' => 'active', 'current_period_end' => $oldEnd];
        $this->stripe['GET customers'] = ['id' => 'cus_old', 'invoice_settings' => ['default_payment_method' => ['id' => 'pm_old', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '1111', 'exp_month' => 1, 'exp_year' => 2030]]]];
        $this->fakeBilling();

        // Admin creates an existing-client quote: per-agent only, month to month.
        $this->actingAs($this->admin, 'admin')->post('/admin/quotes', [
            'label' => 'Legacy: Bay Realty', 'company_name' => 'Bay Realty', 'email' => 'owner@bayrealty.com',
            'setup_fee' => '3000', 'deposit_percent' => '10', 'platform_fee' => '0', 'per_agent_fee' => '18', 'min_agents' => 1, 'go_live_days' => 30,
            'expires_on' => '2026-10-20', 'is_existing_client' => '1', 'old_stripe_subscription_id' => 'sub_old', 'term_months' => 0,
        ])->assertSessionHas('success');
        $quote = Quote::firstOrFail();
        $this->assertTrue($quote->is_existing_client);
        $this->assertSame(0, $quote->setup_fee_cents);
        $this->assertSame('cus_old', $quote->stripe_customer_id);
        $this->assertSame('2026-10-15', $quote->first_charge_on->toDateString()); // their current renewal date
        auth('admin')->logout();

        // Client: details, agreement (subscription only), schedule, confirm saved card.
        $this->get('/?quote='.$quote->token)->assertSee('$0.00');
        $this->post('/start', ['first_name' => 'Pat', 'last_name' => 'Lee', 'title' => 'Owner', 'company_name' => 'Bay Realty', 'email' => 'owner@bayrealty.com',
            'phone' => '3055550111', 'agents' => 40, 'street' => '1 Bay St', 'city' => 'Miami', 'state_code' => 'FL', 'zip' => '33131'])->assertRedirect();
        $customer = Customer::firstOrFail();
        $contract = Contract::firstOrFail();
        $this->assertSame(ContractType::Existing, $contract->type);
        $this->get("/onboard/{$customer->uuid}/agreement")->assertSee('Platform Subscription Agreement')->assertSee('month to month')->assertSee('October 15, 2026')->assertDontSee('Deposit on signing');
        $this->sign($customer);
        $this->get("/onboard/{$customer->uuid}/schedule")->assertSee('Nothing is charged');
        $this->get("/onboard/{$customer->uuid}/payment")->assertOk()->assertSee('Visa ending 1111')->assertSee('Nothing is charged today');
        $this->post("/onboard/{$customer->uuid}/existing/activate")->assertRedirect("/onboard/{$customer->uuid}/done");

        $customer->refresh();
        $this->assertSame(CustomerStatus::Live, $customer->status);
        $this->assertSame('cus_old', $customer->stripe_customer_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'subscriptions/sub_old') && $r->method() === 'POST' && $r['cancel_at_period_end'] === 'true');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/subscriptions') && count($r['items']) === 1 // no $0 platform line
            && (int) $r['trial_end'] === Carbon::parse('2026-10-15 09:00', 'America/New_York')->getTimestamp());
        $this->assertSame(0, Invoice::count()); // nothing charged today
        $this->assertSame(1, EmailLog::where('template_key', 'existing_client_welcome')->count());
        $this->get("/onboard/{$customer->uuid}/done")->assertSee('You’re all set');
    }

    public function test_end_service_at_period_end_can_be_undone_and_finishes_when_stripe_ends_the_subscription(): void
    {
        $this->stripe['POST subscriptions'] = fn (Request $r) => ['id' => 'sub_1', 'current_period_end' => Carbon::parse('2026-10-22 13:00', 'UTC')->getTimestamp(), 'items' => ['data' => []]];
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $this->actingAs($this->admin, 'admin');

        $this->post("/admin/customers/{$customer->uuid}/end-service", ['type' => 'period_end', 'reason' => 'price', 'notes' => 'Budget cuts'])->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'subscriptions/sub_1') && $r['cancel_at_period_end'] === 'true');
        $customer->refresh();
        $this->assertSame('2026-10-22', $customer->service_ends_on->toDateString());
        $this->get("/admin/customers/{$customer->uuid}")->assertSee('Service ends on Oct 22, 2026');
        $this->assertSame(1, EmailLog::where('template_key', 'service_ending')->count());

        $this->post("/admin/customers/{$customer->uuid}/end-service/undo")->assertSessionHas('success');
        $this->assertNull($customer->fresh()->end_type);

        $this->post("/admin/customers/{$customer->uuid}/end-service", ['type' => 'period_end', 'reason' => 'closed']);
        $this->stripeWebhook(['id' => 'evt_del', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_1', 'status' => 'canceled']]])->assertOk();
        $this->assertSame(CustomerStatus::Cancelled, $customer->fresh()->status);
        $this->assertSame(1, EmailLog::where('template_key', 'service_ended')->count());
    }

    public function test_end_now_without_fee_needs_approval_and_former_customers_shows_revenue(): void
    {
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live, goLive: '2026-06-01');
        $customer->forceFill(['live_at' => Carbon::parse('2026-06-01')])->save();
        $inv = Invoice::create(['number' => 'INV-1', 'customer_id' => $customer->id, 'contract_id' => Contract::first()->id, 'type' => 'monthly', 'status' => 'paid', 'amount_cents' => 66000, 'due_on' => '2026-07-01', 'paid_at' => now()]);
        Payment::create(['invoice_id' => $inv->id, 'customer_id' => $customer->id, 'stripe_charge_id' => 'ch_1', 'method' => 'card', 'amount_cents' => 66000, 'status' => 'succeeded', 'settled_at' => Carbon::parse('2026-07-01 16:00', 'UTC')]);
        $this->actingAs($this->admin, 'admin');

        $this->post("/admin/customers/{$customer->uuid}/end-service", ['type' => 'now_no_fee', 'reason' => 'switched', 'notes' => 'Moved to another platform'])->assertSessionHas('success');
        $this->assertSame(CustomerStatus::Live, $customer->fresh()->status);
        $approval = Approval::firstOrFail();

        $second = Admin::factory()->superAdmin()->withTwoFactor()->create();
        $this->actingAs($second, 'admin')->post("/admin/approvals/{$approval->id}/approve")->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), 'subscriptions/sub_1'));
        $this->assertSame(CustomerStatus::Cancelled, $customer->fresh()->status);

        $this->get('/admin/former-customers')->assertOk()->assertSee('Sunline Realty Group')->assertSee('Switched to another provider')->assertSee('$660.00')->assertSee('Jul 1, 2026');
        $this->assertStringContainsString('Sunline Realty Group', $this->get('/admin/former-customers/export')->streamedContent());
        $this->post("/admin/former-customers/{$customer->uuid}/win-back")->assertSessionHas('success');
        $this->assertSame(1, EmailLog::where('template_key', 'win_back')->count());
    }
}
