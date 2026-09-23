<?php

namespace Tests\Feature\Admin;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Models\Admin;
use App\Models\Approval;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Role;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesBilling;
use Tests\TestCase;

class BackOfficeTest extends TestCase
{
    use FakesBilling, RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-09-23 10:00', 'America/New_York'));
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class, DemoSeeder::class]);
        $this->fakeBilling();
        $this->admin = Admin::factory()->superAdmin()->withTwoFactor()->create();
        $this->actingAs($this->admin, 'admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(string $company): Customer
    {
        return Customer::where('company_name', $company)->firstOrFail();
    }

    public function test_dashboard_shows_kpis_revenue_ranges_and_attention(): void
    {
        $this->get('/admin')->assertOk()->assertSee('Customers onboarded')->assertSee('Keystone Homes')->assertSee('Goes live');
        $this->get('/admin?range=year')->assertOk()->assertSee('2026 so far');
        $this->get('/admin?range=custom&from=2026-01-01&to=2026-06-30')->assertOk()->assertSee('Jan 1, 2026 to Jun 30, 2026');
    }

    public function test_customers_list_filters_and_exports(): void
    {
        $this->get('/admin/customers')->assertOk()->assertSee('Coastal Keys Brokerage');
        $this->get('/admin/customers?status=suspended')->assertOk()->assertSee('Mesa Verde Properties')->assertDontSee('Coastal Keys Brokerage');
        $this->get('/admin/customers?q=austin')->assertOk()->assertSee('Keystone Homes')->assertDontSee('Mesa Verde');

        $csv = $this->get('/admin/customers/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Company,Signer', $csv);
        $this->assertStringContainsString('Keystone Homes', $csv);
    }

    public function test_customer_page_shows_every_tab(): void
    {
        $this->get('/admin/customers/'.$this->customer('Keystone Homes')->uuid)->assertOk()
            ->assertSee('Go-live date')->assertSee('Agents billed')->assertSee('Agent count history')->assertSee('Early termination');
    }

    public function test_admin_changes_go_live_date_and_it_is_refused_inside_the_lock(): void
    {
        $sunline = $this->customer('Sunline Realty Group'); // goes live in 5 days
        $this->put("/admin/customers/{$sunline->uuid}/go-live", ['go_live_date' => '2026-10-05'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-05', $sunline->fresh()->go_live_date->toDateString());

        $harbor = $this->customer('Harbor Point Realty'); // goes live in 2 days: locked
        $this->put("/admin/customers/{$harbor->uuid}/go-live", ['go_live_date' => '2026-10-10'])->assertSessionHasErrors('go_live_date');
    }

    public function test_admin_sets_agents_and_live_site_and_creates_a_token_shown_once(): void
    {
        $c = $this->customer('Coastal Keys Brokerage');
        $c->forceFill(['stripe_agent_item_id' => 'si_agent'])->save();

        $this->put("/admin/customers/{$c->uuid}/agents", ['agent_count' => 52, 'note' => 'Confirmed by phone'])->assertSessionHas('success');
        $this->assertSame(52, $c->fresh()->agent_count);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'subscription_items/si_agent') && $r['quantity'] == 52);

        $this->put("/admin/customers/{$c->uuid}/live", ['live_url' => 'http://insecure.example.com'])->assertSessionHasErrors('live_url');
        $this->put("/admin/customers/{$c->uuid}/live", ['live_url' => 'https://coastal.rightally.io', 'live_host' => 'coastal.rightally.io'])->assertSessionHasNoErrors();

        $res = $this->post("/admin/customers/{$c->uuid}/token");
        $token = session('agent_token');
        $this->assertStringStartsWith('ra_', $token);
        $this->followRedirects($res)->assertSee($token);
        $this->get('/admin/customers/'.$c->uuid)->assertDontSee($token);
    }

    public function test_suspend_and_reactivate(): void
    {
        $c = $this->customer('Coastal Keys Brokerage');
        $this->post("/admin/customers/{$c->uuid}/suspend", ['reason' => 'Chargeback dispute'])->assertSessionHas('success');
        $this->assertSame(CustomerStatus::Suspended, $c->fresh()->status);
        $this->post("/admin/customers/{$c->uuid}/reactivate")->assertSessionHas('success');
        $this->assertSame(CustomerStatus::Live, $c->fresh()->status);
    }

    public function test_early_termination_needs_the_company_name_typed_exactly(): void
    {
        $c = $this->customer('Coastal Keys Brokerage');
        $c->forceFill(['stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1', 'stripe_payment_method_id' => 'pm_1'])->save();

        $this->post("/admin/customers/{$c->uuid}/terminate", ['reason' => 'Closing', 'confirm' => 'coastal keys'])->assertSessionHasErrors('confirm');
        $this->assertSame(CustomerStatus::Live, $c->fresh()->status);

        $this->post("/admin/customers/{$c->uuid}/terminate", ['reason' => 'Closing', 'confirm' => 'Coastal Keys Brokerage'])->assertSessionHas('success');
        $this->assertSame(CustomerStatus::Live, $c->fresh()->status); // waits for a second admin
        $approval = Approval::firstOrFail();

        $this->post("/admin/approvals/{$approval->id}/approve")->assertSessionHasErrors('approval'); // not your own request
        $second = Admin::factory()->superAdmin()->withTwoFactor()->create();
        $this->actingAs($second, 'admin')->post("/admin/approvals/{$approval->id}/approve")->assertSessionHas('success');
        $this->assertSame(CustomerStatus::Cancelled, $c->fresh()->status);
        $this->assertSame('approved', $approval->fresh()->status);
    }

    public function test_custom_email_can_be_sent_from_the_customer_page(): void
    {
        $t = EmailTemplate::create(['key' => 'custom-kickoff', 'is_system' => false, 'name' => 'Kick-off', 'subject' => 'Kick-off for {company_name}', 'body' => "Hi {first_name},\n\nLet’s talk.", 'is_enabled' => true]);
        $c = $this->customer('Sunline Realty Group');

        $this->post("/admin/customers/{$c->uuid}/send-email", ['template_id' => $t->id])->assertRedirect();
        $this->assertSame('Kick-off for Sunline Realty Group', EmailLog::where('template_key', 'custom-kickoff')->value('subject'));
    }

    public function test_invoices_tabs_and_resend_payment_link(): void
    {
        $this->get('/admin/invoices?tab=failed')->assertOk()->assertSee('Keystone Homes')->assertSee('Resend link');
        $this->get('/admin/invoices?tab=upcoming')->assertOk()->assertSee('Balance at go-live')->assertSee('Harbor Point Realty');
        $this->get('/admin/invoices?tab=paid&period=all')->assertOk()->assertSee('INV-');
        $this->assertStringContainsString('Invoice,Customer', $this->get('/admin/invoices/export?period=all')->streamedContent());

        $failed = Invoice::where('status', InvoiceStatus::Failed)->firstOrFail();
        $this->post("/admin/invoices/{$failed->id}/resend")->assertSessionHas('success');
        $this->assertNotNull($failed->fresh()->payment_link_sent_at);
        $this->assertSame(1, EmailLog::where('template_key', 'payment_failed')->count());
    }

    public function test_menus_follow_permissions(): void
    {
        $sales = Admin::factory()->withTwoFactor()->create(['role_id' => Role::firstWhere('name', 'Sales')->id]);
        Role::firstWhere('name', 'Sales')->syncPermissions(['dashboard', 'customers']);

        $this->actingAs($sales, 'admin')->get('/admin/customers')->assertOk();
        $this->get('/admin/invoices')->assertForbidden();
        $c = $this->customer('Keystone Homes');
        $this->get('/admin/customers/'.$c->uuid)->assertOk()->assertDontSee('Resend link');
    }
}
