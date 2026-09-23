<?php

namespace Tests\Feature\Onboarding;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Models\Contract;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\EmailLog;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-09-23 14:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function details(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Maria', 'last_name' => 'Alvarez', 'title' => 'Managing Broker',
            'company_name' => 'Sunline Realty Group', 'email' => 'Maria@SunlineRealty.com', 'phone' => '(305) 555-0148',
            'agents' => 8, 'street' => '1200 Brickell Ave, Suite 410', 'city' => 'Miami', 'state_code' => 'FL', 'zip' => '33131',
            'coupon' => '',
        ], $overrides);
    }

    /** A real 300x100 PNG with a line on it, as the signature pad would send. */
    private function signaturePng(): string
    {
        $img = imagecreatetruecolor(300, 100);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagesetthickness($img, 3);
        imageline($img, 20, 70, 280, 30, imagecolorallocate($img, 8, 57, 178));
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function start(array $overrides = []): Customer
    {
        $this->post('/start', $this->details($overrides))->assertRedirect();

        return Customer::latest('id')->firstOrFail();
    }

    public function test_landing_page_prefills_and_applies_a_coupon_from_the_link(): void
    {
        Coupon::create(['code' => 'NAR2026', 'name' => 'NAR', 'percent_off' => 15, 'expires_on' => '2026-11-15', 'is_active' => true]);

        $this->get('/?coupon=nar2026')
            ->assertOk()
            ->assertSee('value="NAR2026"', false)
            ->assertSee('NAR2026 applied from your link')
            ->assertSee('$255.00')   // deposit after 15% off
            ->assertSee('District of Columbia');
    }

    public function test_details_create_customer_and_draft_agreement_with_exact_prices(): void
    {
        Coupon::create(['code' => 'NAR2026', 'name' => 'NAR', 'percent_off' => 15, 'expires_on' => null, 'is_active' => true]);

        $customer = $this->start(['coupon' => 'nar2026']);

        $this->assertSame(CustomerStatus::Draft, $customer->status);
        $this->assertSame('maria@sunlinerealty.com', $customer->email);
        $this->assertSame('+13055550148', $customer->phone_e164);
        $this->assertSame(8, $customer->agent_count);

        $contract = $customer->contracts()->firstOrFail();
        $this->assertSame(ContractStatus::Draft, $contract->status);
        $this->assertMatchesRegularExpression('/^RA-2026-\d{4}$/', $contract->number);
        $this->assertSame(300000, $contract->setup_fee_cents);
        $this->assertSame(45000, $contract->discount_cents);
        $this->assertSame(255000, $contract->implementation_fee_cents);
        $this->assertSame(25500, $contract->deposit_cents);
        $this->assertSame(229500, $contract->balance_cents);
        $this->assertSame(66000, $contract->monthlyFeeCents());
        $this->assertSame('NAR2026', $contract->coupon_code);
    }

    public function test_fewer_agents_than_the_minimum_are_billed_at_the_minimum(): void
    {
        $customer = $this->start(['agents' => 3]);

        $this->assertSame(3, $customer->agent_count_entered);
        $this->assertSame(5, $customer->agent_count);
        $this->assertSame(60000, $customer->contracts()->first()->monthlyFeeCents());
    }

    public function test_invalid_details_are_rejected_with_clear_messages(): void
    {
        $this->post('/start', $this->details(['phone' => '123', 'zip' => 'ABCDE', 'state_code' => 'ZZ', 'agents' => 0]))
            ->assertSessionHasErrors(['phone', 'zip', 'state_code', 'agents']);
        $this->assertSame(0, Customer::count());
    }

    public function test_expired_or_unknown_coupons_are_refused(): void
    {
        Coupon::create(['code' => 'SUMMER25', 'name' => 'Summer', 'percent_off' => 25, 'expires_on' => '2026-08-31', 'is_active' => true]);

        $this->post('/start', $this->details(['coupon' => 'SUMMER25']))->assertSessionHasErrors(['coupon' => 'SUMMER25 expired on Aug 31, 2026. You can continue without a coupon.']);
        $this->post('/start', $this->details(['coupon' => 'NOPE']))->assertSessionHasErrors('coupon');
        $this->assertSame(0, Customer::count());
    }

    public function test_coupon_is_valid_through_its_whole_expiry_day(): void
    {
        Coupon::create(['code' => 'LASTDAY', 'name' => 'Last day', 'percent_off' => 10, 'expires_on' => '2026-09-23', 'is_active' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-23 23:30:00', 'America/New_York'));

        $this->postJson('/coupon/check', ['code' => 'lastday'])->assertOk()->assertJson(['valid' => true, 'percent' => 10]);

        Carbon::setTestNow(Carbon::parse('2026-09-24 00:30:00', 'America/New_York'));
        $this->postJson('/coupon/check', ['code' => 'lastday'])->assertJson(['valid' => false]);
    }

    public function test_client_signs_and_gets_a_pdf_with_evidence(): void
    {
        $coupon = Coupon::create(['code' => 'NAR2026', 'name' => 'NAR', 'percent_off' => 15, 'is_active' => true]);
        $customer = $this->start(['coupon' => 'NAR2026']);

        $this->get("/onboard/{$customer->uuid}/agreement")->assertOk()
            ->assertSee('Review and sign your agreement')
            ->assertSee('Sunline Realty Group')
            ->assertSee('October 23, 2026');   // target go-live: signing + 30 days

        $this->post("/onboard/{$customer->uuid}/agreement/sign", [
            'consent' => '1', 'typed_name' => 'Maria Alvarez', 'signature' => $this->signaturePng(),
        ], ['User-Agent' => 'TestBrowser/1.0'])->assertRedirect("/onboard/{$customer->uuid}/agreement");

        $contract = Contract::firstOrFail();
        $this->assertSame(ContractStatus::Signed, $contract->status);
        $this->assertSame('Maria Alvarez', $contract->client_typed_name);
        $this->assertSame('TestBrowser/1.0', $contract->signer_user_agent);
        $this->assertNotNull($contract->esign_consent_at);
        $this->assertSame('2026-10-23', $contract->starts_on->toDateString());
        $this->assertSame('2027-10-22', $contract->ends_on->toDateString());
        $this->assertStringContainsString('Sunline Realty Group', $contract->rendered_html);
        Storage::disk('local')->assertExists([$contract->pdf_path, $contract->client_signature_path]);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($contract->pdf_path)), $contract->document_sha256);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($contract->pdf_path));

        $customer->refresh();
        $this->assertSame(CustomerStatus::ContractSigned, $customer->status);
        $this->assertSame('2026-10-23', $customer->go_live_date->toDateString());
        $this->assertSame(1, $coupon->fresh()->times_used);
        $this->assertSame(1, $customer->agentCountLogs()->count());

        $this->get("/onboard/{$customer->uuid}/agreement/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // Their signed copy is emailed straight away, in case they stop before paying.
        $this->assertSame(1, EmailLog::where('template_key', 'signed_payment_pending')->where('to_email', 'maria@sunlinerealty.com')->count());
        $this->get("/onboard/{$customer->uuid}/schedule")->assertOk()->assertSee('$2,295.00')->assertSee('Nov 22, 2026');
    }

    public function test_signing_requires_consent_name_and_a_drawn_signature(): void
    {
        $customer = $this->start();

        $this->post("/onboard/{$customer->uuid}/agreement/sign", ['typed_name' => '', 'signature' => 'data:image/png;base64,AAAA'])
            ->assertSessionHasErrors(['consent', 'typed_name', 'signature']);
        $this->assertSame(ContractStatus::Draft, Contract::first()->status);
    }

    public function test_an_agreement_can_only_be_signed_once_and_details_lock_after_signing(): void
    {
        $customer = $this->start();
        $sign = fn () => $this->post("/onboard/{$customer->uuid}/agreement/sign", ['consent' => '1', 'typed_name' => 'Maria Alvarez', 'signature' => $this->signaturePng()]);

        $sign();
        $firstHash = Contract::first()->document_sha256;
        $sign()->assertRedirect("/onboard/{$customer->uuid}/agreement");

        $this->assertSame(1, Contract::count());
        $this->assertSame($firstHash, Contract::first()->document_sha256);
        $this->get("/onboard/{$customer->uuid}/details")->assertRedirect("/onboard/{$customer->uuid}/agreement");
    }

    public function test_details_can_be_changed_before_signing_and_the_agreement_is_repriced(): void
    {
        $customer = $this->start(['agents' => 8]);

        $this->put("/onboard/{$customer->uuid}/details", $this->details(['agents' => 20]))->assertRedirect();

        $this->assertSame(20, $customer->fresh()->agent_count);
        $this->assertSame(90000, Contract::first()->monthlyFeeCents());
        $this->assertSame(1, Contract::count());
    }

    public function test_another_browser_cannot_open_someone_elses_onboarding(): void
    {
        $customer = $this->start();

        $this->flushSession();
        $this->get("/onboard/{$customer->uuid}/agreement")->assertForbidden();
        $this->get("/onboard/{$customer->uuid}/agreement/pdf")->assertForbidden();

        // A signed link (as emailed in Sprint 3) grants access.
        $url = \URL::temporarySignedRoute('onboarding.agreement', now()->addDay(), ['customer' => $customer->uuid]);
        $this->get($url)->assertOk();
        $this->get("/onboard/{$customer->uuid}/agreement")->assertOk();
    }

    public function test_schedule_and_payment_wait_until_the_agreement_is_signed(): void
    {
        $customer = $this->start();

        $this->get("/onboard/{$customer->uuid}/schedule")->assertRedirect("/onboard/{$customer->uuid}/agreement");
        $this->get("/onboard/{$customer->uuid}/payment")->assertRedirect("/onboard/{$customer->uuid}/agreement");
    }
}
