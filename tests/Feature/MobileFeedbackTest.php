<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Http\Middleware\OnboardingAccess;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Invoice;
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

class MobileFeedbackTest extends TestCase
{
    use FakesBilling, RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-09-23 10:00', 'America/New_York'));
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
        $this->admin = Admin::factory()->superAdmin()->withTwoFactor()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function details(array $o = []): array
    {
        return $o + ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'title' => 'Owner', 'company_name' => 'Key Realty', 'email' => 'test@gmail.com',
            'phone' => '3055550148', 'agents' => 8, 'street' => '1 Main St', 'city' => 'Miami', 'state_code' => 'FL', 'zip' => '33131'];
    }

    public function test_email_must_have_a_real_domain(): void
    {
        $this->post('/start', $this->details(['email' => 'test@gmail']))->assertSessionHasErrors('email');
        $this->assertSame(0, Customer::count());
    }

    public function test_one_email_one_account_and_a_link_to_continue_is_sent(): void
    {
        $this->post('/start', $this->details())->assertRedirect();
        $customer = Customer::firstOrFail();

        // Same browser: straight back to where they were.
        $this->post('/start', $this->details())->assertRedirect("/onboard/{$customer->uuid}/agreement");

        // Another browser: no duplicate; a link to continue is emailed.
        $this->flushSession();
        $this->post('/start', $this->details(['email' => 'TEST@gmail.com']))->assertSessionHasErrors('email');
        $this->assertSame(1, Customer::count());
        $mail = EmailLog::where('template_key', 'resume_onboarding')->firstOrFail();
        $this->assertSame('test@gmail.com', $mail->to_email);
    }

    public function test_client_can_ask_for_a_link_to_continue(): void
    {
        $this->post('/start', $this->details());
        $this->flushSession();
        $this->get('/')->assertSee('Get a link to continue where you left off');
        $this->post('/account', ['email' => 'test@gmail.com'])->assertSessionHas('status');
        $this->assertSame(1, EmailLog::where('template_key', 'resume_onboarding')->count());
    }

    public function test_admin_sees_unfinished_clients_and_can_send_or_copy_a_link(): void
    {
        $this->post('/start', $this->details());
        $customer = Customer::firstOrFail();
        $this->actingAs($this->admin, 'admin');

        $this->get('/admin')->assertSee('Not finished');
        $this->get('/admin/customers?status=incomplete')->assertOk()->assertSee('Key Realty')->assertSee('Not completed (1)');
        $this->get("/admin/customers/{$customer->uuid}")->assertSee('Onboarding not finished')->assertSee('Copy link')->assertSee('/agreement?expires=', false);
        $this->post("/admin/customers/{$customer->uuid}/resume-link")->assertSessionHas('success');
        $this->assertSame(1, EmailLog::where('template_key', 'resume_onboarding')->count());
    }

    public function test_super_admin_deletes_a_customer_completely_and_the_email_can_sign_up_again(): void
    {
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live);
        Invoice::create(['number' => 'INV-X', 'customer_id' => $customer->id, 'contract_id' => Contract::first()->id, 'type' => 'monthly', 'status' => 'paid', 'amount_cents' => 1, 'due_on' => '2026-09-01']);
        $this->actingAs($this->admin, 'admin');

        $this->delete("/admin/customers/{$customer->uuid}", ['confirm' => 'wrong'])->assertSessionHasErrors('confirm', null, 'delete');
        $this->delete("/admin/customers/{$customer->uuid}", ['confirm' => $customer->company_name, 'stripe' => '1'])->assertRedirect('/admin/customers');

        $this->assertSame(0, Customer::withTrashed()->count());
        $this->assertSame(0, Contract::count());
        $this->assertSame(0, Invoice::count());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), 'subscriptions/sub_1'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), 'customers/cus_1'));

        auth('admin')->logout();
        $this->post('/start', $this->details(['email' => $customer->email]))->assertRedirect();
        $this->assertSame(1, Customer::count());
    }

    public function test_reset_all_client_data_only_in_test_mode_with_confirmation(): void
    {
        $this->post('/start', $this->details());
        $this->post('/start', $this->details(['email' => 'second@gmail.com']));
        $this->actingAs($this->admin, 'admin');

        $this->post('/admin/settings/reset-data', ['confirm' => 'reset', 'password' => 'password'])->assertSessionHasErrors('confirm', null, 'reset');
        $this->post('/admin/settings/reset-data', ['confirm' => 'RESET', 'password' => 'wrong'])->assertSessionHasErrors('password', null, 'reset');
        $this->post('/admin/settings/reset-data', ['confirm' => 'RESET', 'password' => 'password'])->assertSessionHas('success');
        $this->assertSame(0, Customer::withTrashed()->count());
        $this->assertSame(1, Admin::count());

        app(SettingsService::class)->setMany('stripe', ['mode' => 'live']);
        $this->post('/admin/settings/reset-data', ['confirm' => 'RESET', 'password' => 'password'])->assertSessionHasErrors('reset', null, 'reset');
    }

    public function test_pages_use_the_white_logo_coupon_can_be_removed_and_signed_page_continues(): void
    {
        $this->get('/')->assertSee('brand/logo-white.png')->assertSee('id="couponClear"', false);
        $this->post('/start', $this->details());
        $customer = Customer::firstOrFail();

        $img = imagecreatetruecolor(300, 100);
        imageline($img, 10, 50, 290, 40, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);
        $this->post("/onboard/{$customer->uuid}/agreement/sign", ['consent' => '1', 'typed_name' => 'Ana Ruiz', 'signature' => 'data:image/png;base64,'.base64_encode((string) ob_get_clean())]);
        $this->get("/onboard/{$customer->uuid}/agreement")->assertSee('Continue')->assertSee("/onboard/{$customer->uuid}/schedule", false)->assertDontSee('View agreement');
    }

    public function test_client_with_an_incomplete_saved_email_can_fix_it_on_the_payment_step(): void
    {
        $this->stripe['POST customers'] = fn (Request $r) => str_contains((string) $r['email'], '.')
            ? ['id' => 'cus_ok'] : ['__status' => 400, 'json' => ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid email address: '.$r['email']]]];
        $this->stripe['POST payment_intents'] = ['id' => 'pi_1', 'status' => 'requires_payment_method', 'client_secret' => 'pi_1_secret', 'amount' => 30000];
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::ContractSigned);
        $customer->forceFill(['email' => 'test@gmail', 'stripe_customer_id' => null])->save();
        $this->withSession([OnboardingAccess::SESSION_KEY => [$customer->uuid]]);

        $this->get("/onboard/{$customer->uuid}/payment")->assertOk()->assertSee('looks incomplete')->assertSee('Save and continue');
        $this->post("/onboard/{$customer->uuid}/email", ['email' => 'test@gmail'])->assertSessionHasErrors('email');
        $this->post("/onboard/{$customer->uuid}/email", ['email' => 'test@gmail.com'])->assertRedirect("/onboard/{$customer->uuid}/payment");
        $this->get("/onboard/{$customer->uuid}/payment")->assertOk()->assertSee('data-secret="pi_1_secret"', false);
    }

    public function test_expired_links_offer_a_new_one_and_admin_can_share_an_account_link(): void
    {
        $this->fakeBilling();
        $customer = $this->billedCustomer(CustomerStatus::Live);

        $this->get("/onboard/{$customer->uuid}/payment")->assertForbidden()->assertSee('Email me a new link')->assertSee('/account', false);

        $this->actingAs($this->admin, 'admin')->get("/admin/customers/{$customer->uuid}")->assertSee('Client account link')->assertSee('/account/'.$customer->uuid.'?expires=', false);
        $this->post("/admin/customers/{$customer->uuid}/account-link")->assertSessionHas('success');
        $this->assertSame(1, EmailLog::where('template_key', 'account_link')->count());
    }
}
