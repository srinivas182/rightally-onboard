<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Admin;
use App\Models\Approval;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Billing\EarlyTerminationService;
use App\Services\Settings\SettingsService;
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

class OperationsSafetyTest extends TestCase
{
    use FakesBilling, RefreshDatabase;

    private Admin $admin;

    private Admin $second;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00', 'America/New_York'));
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
        $this->admin = Admin::factory()->superAdmin()->withTwoFactor()->create();
        $this->second = Admin::factory()->superAdmin()->withTwoFactor()->create();
        $this->stripe['POST refunds'] = ['id' => 're_1'];
        $this->stripe['POST customers'] = fn (Request $r) => str_contains($r->url(), 'balance_transactions') ? ['id' => 'cbtxn_1'] : ['id' => 'cus_1'];
        $this->fakeBilling();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paid(Customer $c, int $cents = 66000): Invoice
    {
        $i = Invoice::create(['number' => 'INV-2026-0300', 'customer_id' => $c->id, 'contract_id' => Contract::first()->id, 'type' => InvoiceType::Monthly,
            'status' => InvoiceStatus::Paid, 'amount_cents' => $cents, 'due_on' => '2026-11-22', 'paid_at' => now(), 'stripe_invoice_id' => 'in_300']);
        Payment::create(['invoice_id' => $i->id, 'customer_id' => $c->id, 'stripe_charge_id' => 'ch_300', 'method' => 'card', 'amount_cents' => $cents, 'status' => 'succeeded', 'settled_at' => now()]);

        return $i;
    }

    // ---- 11/12. Refunds, credits and approvals -------------------------

    public function test_small_refund_goes_straight_to_stripe_and_emails_the_client(): void
    {
        $c = $this->billedCustomer(CustomerStatus::Live, goLive: '2026-10-23');
        $inv = $this->paid($c);

        $this->actingAs($this->admin, 'admin')->post("/admin/customers/{$c->uuid}/invoices/{$inv->id}/refund", ['amount' => '120', 'reason' => 'Billed 6 extra agents'])->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/refunds') && $r['charge'] === 'ch_300' && $r['amount'] == 12000);
        $this->assertSame(12000, Payment::first()->refunded_cents);
        $this->assertSame(1, EmailLog::where('template_key', 'refund_issued')->count());
        $this->assertSame(0, Approval::count());
    }

    public function test_refund_can_not_exceed_what_was_paid(): void
    {
        $c = $this->billedCustomer(CustomerStatus::Live, goLive: '2026-10-23');
        $inv = $this->paid($c);
        $this->actingAs($this->admin, 'admin')->post("/admin/customers/{$c->uuid}/invoices/{$inv->id}/refund", ['amount' => '700', 'reason' => 'x'])->assertSessionHasErrors('amount', null, 'refund');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'refunds'));
    }

    public function test_large_refund_waits_for_a_second_admin(): void
    {
        $c = $this->billedCustomer(CustomerStatus::Live, goLive: '2026-10-23');
        $inv = $this->paid($c);

        $this->actingAs($this->admin, 'admin')->post("/admin/customers/{$c->uuid}/invoices/{$inv->id}/refund", ['amount' => '600', 'reason' => 'Goodwill'])->assertSessionHas('success');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'refunds'));
        $approval = Approval::firstOrFail();
        $this->assertSame(60000, $approval->amount_cents);
        $this->assertStringStartsWith('[RightAlly] Approval needed', EmailLog::where('template_key', 'team_alert')->value('subject'));

        $this->get('/admin/approvals')->assertOk()->assertSee('Waiting for another admin');
        $this->actingAs($this->second, 'admin')->get('/admin/approvals')->assertSee('Approve');
        $this->post("/admin/approvals/{$approval->id}/approve")->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/refunds') && $r['amount'] == 60000);
        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertStringContainsString('Refunded $600.00', $approval->fresh()->result);
    }

    public function test_rejected_request_does_nothing(): void
    {
        $c = $this->billedCustomer(CustomerStatus::Live, goLive: '2026-10-23');
        $this->actingAs($this->admin, 'admin')->post("/admin/customers/{$c->uuid}/credit", ['amount' => '900', 'reason' => 'Outage']);
        $approval = Approval::firstOrFail();

        $this->actingAs($this->second, 'admin')->post("/admin/approvals/{$approval->id}/reject", ['note' => 'Too much'])->assertSessionHas('success');
        $this->assertSame('rejected', $approval->fresh()->status);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'balance_transactions'));
    }

    public function test_small_credit_comes_off_the_next_charge(): void
    {
        $c = $this->billedCustomer(CustomerStatus::Live, goLive: '2026-10-23');
        $this->actingAs($this->admin, 'admin')->post("/admin/customers/{$c->uuid}/credit", ['amount' => '100', 'reason' => 'Outage on Nov 3'])->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'customers/cus_1/balance_transactions') && $r['amount'] == -10000);
        $this->assertDatabaseHas('credits', ['customer_id' => $c->id, 'amount_cents' => 10000]);
        $this->assertSame(1, EmailLog::where('template_key', 'credit_issued')->count());
    }

    // ---- 13. Pause ------------------------------------------------------

    public function test_pause_stops_charges_extends_the_term_and_resumes(): void
    {
        $c = $this->billedCustomer(CustomerStatus::Live, goLive: '2026-10-23');
        $contract = Contract::first();
        $contract->update(['starts_on' => '2026-10-23', 'ends_on' => '2027-10-22']);

        $this->actingAs($this->admin, 'admin')->post("/admin/customers/{$c->uuid}/pause", ['months' => 2, 'reason' => 'Seasonal closure'])->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'subscriptions/sub_1') && $r['pause_collection']['behavior'] === 'void'
            && (int) $r['pause_collection']['resumes_at'] === Carbon::parse('2027-02-01 09:00', 'America/New_York')->getTimestamp());
        $c->refresh();
        $this->assertSame(CustomerStatus::Paused, $c->status);
        $this->assertSame('2027-02-01', $c->paused_until->toDateString());
        $this->assertSame('2027-12-22', $contract->fresh()->ends_on->toDateString());
        $this->assertSame(2, $contract->fresh()->paused_months);
        $this->assertSame(1, EmailLog::where('template_key', 'subscription_paused')->count());

        // Early termination during/after a pause counts the extra months.
        $this->assertSame(14, app(EarlyTerminationService::class)->quote($c)['months']);

        // Second pause within 12 months is refused.
        Carbon::setTestNow(Carbon::parse('2027-02-01 09:00', 'America/New_York'));
        $this->artisan('billing:daily');
        $this->assertSame(CustomerStatus::Live, $c->fresh()->status);
        $this->assertSame(1, EmailLog::where('template_key', 'subscription_resumed')->count());
        $this->post("/admin/customers/{$c->uuid}/pause", ['months' => 1, 'reason' => 'Again'])->assertSessionHasErrors('months');
    }

    // ---- 15. Brevo delivery tracking -----------------------------------

    public function test_brevo_events_update_delivery_status_and_flag_bounces(): void
    {
        app(SettingsService::class)->setMany('email', ['brevo_webhook_token' => 'tok_abc']);
        $c = $this->billedCustomer(CustomerStatus::Live);
        $log = EmailLog::create(['customer_id' => $c->id, 'template_key' => 'payment_failed', 'to_email' => $c->email, 'subject' => 'x', 'status' => 'sent', 'provider_message_id' => '<m1@brevo>']);

        $this->postJson('/brevo/webhook/wrong', ['event' => 'delivered', 'message-id' => '<m1@brevo>'])->assertForbidden();
        $this->postJson('/brevo/webhook/tok_abc', ['event' => 'opened', 'message-id' => '<m1@brevo>'])->assertOk();
        $this->postJson('/brevo/webhook/tok_abc', ['event' => 'delivered', 'message-id' => '<m1@brevo>'])->assertOk(); // late, doesn't downgrade
        $this->assertSame('opened', $log->fresh()->status);

        $this->postJson('/brevo/webhook/tok_abc', ['event' => 'hard_bounce', 'message-id' => '<m1@brevo>', 'email' => $c->email, 'reason' => 'mailbox does not exist'])->assertOk();
        $this->assertSame('bounced', $log->fresh()->status);
        $this->assertSame('mailbox does not exist', $c->fresh()->email_bounce_reason);

        $this->actingAs($this->admin, 'admin')->get("/admin/customers/{$c->uuid}")->assertSee('are bouncing');
        $this->put("/admin/customers/{$c->uuid}/contact", ['email' => 'maria.new@sunlinerealty.com', 'phone' => '(305) 555-0148'])->assertSessionHas('success');
        $this->assertNull($c->fresh()->email_bounced_at);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'customers/cus_1') && $r['email'] === 'maria.new@sunlinerealty.com');
    }

    public function test_settings_show_the_brevo_webhook_address(): void
    {
        $this->actingAs($this->admin, 'admin')->get('/admin/settings')->assertOk()->assertSee('/brevo/webhook/');
        $this->assertNotEmpty(app(SettingsService::class)->get('email', 'brevo_webhook_token'));
    }
}
