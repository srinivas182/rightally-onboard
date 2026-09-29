<?php

namespace Tests\Feature;

use App\Enums\AgentCountSource;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\LegalPage;
use App\Services\Billing\AgentCountService;
use App\Services\Billing\InvoiceEvents;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\LegalPageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakesBilling;
use Tests\TestCase;

class ClientExperienceTest extends TestCase
{
    use FakesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00', 'America/New_York'));
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class, LegalPageSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function accountUrl(Customer $c): string
    {
        return URL::temporarySignedRoute('account.show', now()->addDays(7), ['customer' => $c->uuid]);
    }

    private function monthly(Customer $c, InvoiceStatus $status = InvoiceStatus::Paid): Invoice
    {
        return Invoice::create(['number' => 'INV-2026-0200', 'customer_id' => $c->id, 'contract_id' => Contract::first()->id, 'type' => InvoiceType::Monthly,
            'status' => $status, 'amount_cents' => 66000, 'agents_billed' => 8, 'period_start' => '2026-09-22', 'period_end' => '2026-10-22',
            'due_on' => '2026-09-22', 'paid_at' => $status === InvoiceStatus::Paid ? now() : null, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/x']);
    }

    // ---- 1. Legal pages -------------------------------------------------

    public function test_privacy_and_terms_are_public_filled_from_settings_and_linked(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy')->assertSee('Global MLM Software LLC')->assertSee('info@rightally.io')->assertDontSee('{{ company_legal_name }}', false);
        $this->get('/terms')->assertOk()->assertSee('Fla. Stat. § 668.50');
        $this->get('/')->assertSee(route('legal', 'terms'))->assertSee(route('legal', 'privacy'));
    }

    public function test_admin_edits_a_legal_page_and_scripts_are_removed(): void
    {
        $page = LegalPage::firstWhere('slug', 'privacy');
        $this->actingAs(Admin::factory()->superAdmin()->withTwoFactor()->create(), 'admin')
            ->put("/admin/legal/{$page->id}", ['title' => 'Privacy Policy', 'body_html' => '<p>Final wording</p><script>alert(1)</script>'])->assertSessionHas('success');
        $this->get('/privacy')->assertSee('Final wording')->assertDontSee('alert(1)');
    }

    // ---- 2. PDF invoices ------------------------------------------------

    public function test_receipt_pdf_is_attached_to_the_monthly_receipt_and_downloadable_in_admin(): void
    {
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $invoice = $this->monthly($customer, InvoiceStatus::Scheduled);

        app(InvoiceEvents::class)->paid($invoice, ['id' => 'in_x', 'amount_paid' => 66000]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'brevo') && $r['subject'] === 'Your RightAlly receipt for September 2026'
            && ($r['attachment'][0]['name'] ?? '') === 'Receipt-INV-2026-0200.pdf' && str_starts_with(base64_decode($r['attachment'][0]['content']), '%PDF'));

        $this->actingAs(Admin::factory()->superAdmin()->withTwoFactor()->create(), 'admin')
            ->get("/admin/invoices/{$invoice->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    // ---- 3. Client account ---------------------------------------------

    public function test_account_page_shows_agreement_invoices_and_next_charge_and_guards_access(): void
    {
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live, goLive: '2026-08-20');
        $invoice = $this->monthly($customer);

        $this->get("/account/{$customer->uuid}")->assertRedirect('/account'); // must sign in
        $this->actingAs($customer, 'customer');
        $this->get("/account/{$customer->uuid}")->assertOk()
            ->assertSee('Sunline Realty Group')->assertSee('RA-2026-0001')->assertSee('INV-2026-0200')
            ->assertSee('$660.00')->assertSee('October 19, 2026')->assertSee('Visa ending 4242');
        $this->get("/account/{$customer->uuid}/invoices/{$invoice->id}/pdf")->assertOk();

        Contract::first()->update(['number' => 'RA-2026-0009']);
        $customer->forceFill(['stripe_customer_id' => 'cus_A', 'stripe_subscription_id' => 'sub_A'])->save();
        $other = $this->billedCustomer(CustomerStatus::Live);
        $otherInvoice = Invoice::create(['number' => 'INV-X', 'customer_id' => $other->id, 'type' => InvoiceType::Monthly, 'status' => InvoiceStatus::Paid, 'amount_cents' => 1, 'due_on' => '2026-10-01']);
        $this->get("/account/{$customer->uuid}/invoices/{$otherInvoice->id}/pdf")->assertNotFound();
    }

    public function test_client_updates_payment_method_and_stripe_defaults_follow(): void
    {
        $this->stripe['POST setup_intents'] = ['id' => 'seti_1', 'client_secret' => 'seti_1_secret'];
        $this->stripe['GET setup_intents'] = ['id' => 'seti_1', 'status' => 'succeeded', 'customer' => 'cus_1',
            'payment_method' => ['id' => 'pm_new', 'type' => 'card', 'card' => ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 5, 'exp_year' => 2030]]];
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live);

        $this->actingAs($customer, 'customer');
        $this->get("/account/{$customer->uuid}/payment-method")->assertOk()->assertSee('data-secret="seti_1_secret"', false)->assertSee('data-mode="setup"', false);
        $this->get("/account/{$customer->uuid}/payment-method/return?setup_intent=seti_1")->assertRedirect("/account/{$customer->uuid}");

        $customer->refresh();
        $this->assertSame('Mastercard ending 4444', $customer->payment_method_label);
        $this->assertSame(2030, $customer->card_exp_year);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'customers/cus_1') && $r['invoice_settings']['default_payment_method'] === 'pm_new');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'subscriptions/sub_1') && $r['default_payment_method'] === 'pm_new');
        $this->assertSame(1, EmailLog::where('template_key', 'payment_method_updated')->count());
    }

    public function test_setup_intent_of_another_stripe_customer_is_rejected(): void
    {
        $this->stripe['GET setup_intents'] = ['id' => 'seti_2', 'status' => 'succeeded', 'customer' => 'cus_OTHER', 'payment_method' => ['id' => 'pm_x', 'type' => 'card']];
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $this->actingAs($customer, 'customer');

        $this->get("/account/{$customer->uuid}/payment-method/return?setup_intent=seti_2")->assertSessionHas('warning');
        $this->assertSame('pm_1', $customer->fresh()->stripe_payment_method_id);
    }

    public function test_card_expiring_warning_is_sent_once_30_days_before(): void
    {
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $customer->forceFill(['card_exp_month' => 10, 'card_exp_year' => 2026])->save(); // valid through Oct 31

        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00', 'America/New_York'));
        $this->artisan('billing:daily');
        $this->assertSame(0, EmailLog::where('template_key', 'card_expiring')->count());

        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00', 'America/New_York'));
        $this->artisan('billing:daily');
        $this->artisan('billing:daily');
        $this->assertSame(1, EmailLog::where('template_key', 'card_expiring')->count());
    }

    // ---- 4. Agent count changes ------------------------------------------

    public function test_client_is_told_when_agents_change_and_syncs_email_at_most_daily(): void
    {
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live, goLive: '2026-08-20');
        $agents = app(AgentCountService::class);

        $agents->set($customer, 12, AgentCountSource::ApiPull);
        $agents->set($customer->fresh(), 14, AgentCountSource::ApiPull);   // same day: no second email
        $agents->set($customer->fresh(), 20, AgentCountSource::Admin);     // admin change: always

        $this->assertSame(2, EmailLog::where('template_key', 'agents_changed')->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'brevo') && $r['subject'] === 'Your RightAlly agent count is now 12'
            && str_contains($r['htmlContent'], 'from 8 to 12') && str_contains($r['htmlContent'], '$740.00') && str_contains($r['htmlContent'], 'October 19, 2026'));
    }

    // ---- 5. Someone else signs ------------------------------------------

    public function test_filler_can_send_the_agreement_to_someone_else_to_sign(): void
    {
        $this->fakeBilling();
        $this->post('/start', ['first_name' => 'Maria', 'last_name' => 'Alvarez', 'title' => 'Office Manager', 'company_name' => 'Sunline Realty Group',
            'email' => 'maria@sunlinerealty.com', 'phone' => '3055550148', 'agents' => 8, 'street' => '1 Main St', 'city' => 'Miami', 'state_code' => 'FL', 'zip' => '33131']);
        $customer = Customer::latest('id')->first();

        $this->post("/onboard/{$customer->uuid}/agreement/delegate", ['signer_first_name' => 'Robert', 'signer_last_name' => 'Alvarez', 'signer_title' => 'Owner', 'signer_email' => 'Robert@sunlinerealty.com'])
            ->assertRedirect("/onboard/{$customer->uuid}/agreement");

        $customer->refresh();
        $this->assertSame('Robert Alvarez', $customer->fullName());
        $this->assertSame('maria@sunlinerealty.com', $customer->email); // receipts stay with the account email
        $request = EmailLog::where('template_key', 'signature_request')->firstOrFail();
        $this->assertSame('robert@sunlinerealty.com', $request->to_email);
        $this->assertSame('Maria Alvarez asked you to sign the RightAlly agreement', $request->subject);

        // The signer opens the link in another browser and signs.
        $this->flushSession();
        $link = URL::temporarySignedRoute('onboarding.agreement', now()->addDays(7), ['customer' => $customer->uuid]);
        $this->get($link)->assertOk()->assertSee('Robert Alvarez');
        $img = imagecreatetruecolor(300, 100);
        imageline($img, 10, 50, 290, 40, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);
        $this->post("/onboard/{$customer->uuid}/agreement/sign", ['consent' => '1', 'typed_name' => 'Robert Alvarez', 'signature' => 'data:image/png;base64,'.base64_encode((string) ob_get_clean())])->assertRedirect();

        $contract = Contract::latest('id')->first();
        $this->assertSame('robert@sunlinerealty.com', $contract->signer_email);
        $this->assertSame(2, EmailLog::where('template_key', 'signed_payment_pending')->count()); // account email and signer
    }
}
