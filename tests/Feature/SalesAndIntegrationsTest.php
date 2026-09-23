<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Quote;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Billing\AgentCountService;
use App\Services\Settings\SettingsService;
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

class SalesAndIntegrationsTest extends TestCase
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
        return $o + ['first_name' => 'James', 'last_name' => 'Okafor', 'title' => 'Owner', 'company_name' => 'Harbor Point Realty',
            'email' => 'james@harborpoint.com', 'phone' => '8135550100', 'agents' => 12, 'street' => '1 Bay St', 'city' => 'Tampa', 'state_code' => 'FL', 'zip' => '33602'];
    }

    private function sign(Customer $c): void
    {
        $img = imagecreatetruecolor(300, 100);
        imageline($img, 10, 50, 290, 40, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);
        $this->post("/onboard/{$c->uuid}/agreement/sign", ['consent' => '1', 'typed_name' => $c->fullName(), 'signature' => 'data:image/png;base64,'.base64_encode((string) ob_get_clean())]);
    }

    // ---- 6. Custom quotes ----------------------------------------------

    public function test_custom_quote_link_applies_negotiated_pricing_once(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/quotes', [
            'label' => 'Harbor Point deal', 'company_name' => 'Harbor Point Realty', 'email' => 'james@harborpoint.com',
            'setup_fee' => '2000', 'deposit_percent' => '20', 'platform_fee' => '400', 'per_agent_fee' => '15', 'min_agents' => 10,
            'go_live_days' => 45, 'expires_on' => '2026-10-07', 'note' => 'As agreed on our call',
        ])->assertSessionHas('success');
        $quote = Quote::firstOrFail();
        auth('admin')->logout();

        $this->get('/?quote='.$quote->token)->assertOk()
            ->assertSee('Your custom quote is applied')->assertSee('As agreed on our call')
            ->assertSee('value="Harbor Point Realty"', false)->assertDontSee('id="coupon"', false)
            ->assertSee('$400.00'); // deposit 20% of $2,000

        $this->post('/start', $this->details(['coupon' => 'IGNORED']))->assertRedirect();
        $customer = Customer::firstOrFail();
        $contract = Contract::firstOrFail();
        $this->assertSame($quote->id, $customer->quote_id);
        $this->assertSame(200000, $contract->setup_fee_cents);
        $this->assertSame(40000, $contract->deposit_cents);
        $this->assertSame(40000 + 12 * 1500, $contract->monthlyFeeCents());
        $this->assertSame(10, $contract->min_agents);
        $this->assertSame(45, $contract->go_live_days);
        $this->assertStringStartsWith('Custom quote:', $customer->source);

        $this->sign($customer);
        $this->assertSame('2026-11-07', $customer->fresh()->go_live_date->toDateString()); // 45 days
        $this->assertNotNull($quote->fresh()->used_at);

        $this->flushSession();
        $this->get('/?quote='.$quote->token)->assertSee('This quote link has expired or has already been used');
    }

    public function test_voided_or_expired_quotes_fall_back_to_standard_pricing(): void
    {
        $q = Quote::create(['label' => 'X', 'setup_fee_cents' => 100, 'deposit_percent' => 10, 'platform_fee_cents' => 100, 'per_agent_fee_cents' => 1,
            'min_agents' => 1, 'go_live_days' => 10, 'expires_at' => now()->subDay()]);
        $this->get('/?quote='.$q->token)->assertSee('has expired')->assertSee('$300.00');
    }

    // ---- 7/8. Reports --------------------------------------------------

    public function test_funnel_and_revenue_reports(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAs($this->admin, 'admin');

        $this->get('/admin/reports?from=2025-01-01&to=2026-12-31&group=source')->assertOk()
            ->assertSee('Onboarding funnel')->assertSee('facebook spring')->assertSee('Monthly recurring revenue')->assertSee('Next 3 months');
        $csv = $this->get('/admin/reports/funnel.csv?from=2025-01-01&to=2026-12-31')->streamedContent();
        $this->assertStringContainsString('Source,Started,Signed,"Paid deposit",Live', $csv);
        $this->assertStringContainsString('Month,Deposits', $this->get('/admin/reports/revenue.csv')->streamedContent());
    }

    // ---- 9. Webhooks ---------------------------------------------------

    public function test_signed_webhook_goes_to_subscribed_endpoints(): void
    {
        Http::fake(['hooks.zapier.com/*' => Http::response('ok', 200)]);
        $this->actingAs($this->admin, 'admin')->post('/admin/webhooks', ['name' => 'Zapier to GHL', 'url' => 'https://hooks.zapier.com/hooks/catch/1/abc', 'events' => ['customer.signed']])
            ->assertSessionHas('success');
        $endpoint = WebhookEndpoint::firstOrFail();
        auth('admin')->logout();

        $this->post('/start', $this->details());
        $this->sign(Customer::firstOrFail());

        $delivery = WebhookDelivery::where('event', 'customer.signed')->firstOrFail();
        $this->assertSame('delivered', $delivery->status);
        Http::assertSent(function (Request $r) use ($endpoint) {
            if (! str_contains($r->url(), 'hooks.zapier.com')) {
                return false;
            }
            [$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $r->header('X-RightAlly-Signature')[0]));

            return $r['type'] === 'customer.signed' && $r['data']['customer']['company_name'] === 'Harbor Point Realty'
                && hash_equals(hash_hmac('sha256', $t.'.'.$r->body(), $endpoint->secret), $v1);
        });
    }

    public function test_customer_site_is_told_to_suspend_and_restore_access(): void
    {
        $this->fakeBilling();
        Http::fake(['sunline.rightally.io/*' => Http::response(['ok' => true])]);
        $customer = $this->billedCustomer(CustomerStatus::Live);
        $customer->update(['live_url' => 'https://sunline.rightally.io']);
        $token = app(AgentCountService::class)->newToken($customer);

        $customer->update(['status' => CustomerStatus::Suspended]);
        $customer->update(['status' => CustomerStatus::Live]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://sunline.rightally.io/api/rightally/account-status'
            && $r->hasHeader('Authorization', 'Bearer '.$token) && $r['data']['customer']['access'] === 'suspended');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'account-status') && $r['type'] === 'customer.reactivated' && $r['data']['customer']['access'] === 'active');
    }

    public function test_failed_delivery_is_kept_for_retry(): void
    {
        Http::fake(['example.com/*' => Http::response('nope', 500)]);
        $endpoint = WebhookEndpoint::create(['name' => 'Down', 'url' => 'https://example.com/hook', 'secret' => 'whsec_x', 'events' => ['customer.signed'], 'is_active' => true]);

        $this->actingAs($this->admin, 'admin')->post("/admin/webhooks/{$endpoint->id}/test")->assertSessionHas('warning');
        $d = WebhookDelivery::firstOrFail();
        $this->assertSame(1, $d->attempts);
        $this->assertStringContainsString('HTTP 500', $d->last_error);
    }

    // ---- 10. Team alerts -----------------------------------------------

    public function test_new_signing_alerts_the_team_by_email_and_slack(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        app(SettingsService::class)->setMany('alerts', ['slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X']);

        $this->post('/start', $this->details());
        $this->sign(Customer::firstOrFail());

        $mail = EmailLog::where('template_key', 'team_alert')->firstOrFail();
        $this->assertSame('[RightAlly] New signing: Harbor Point Realty', $mail->subject);
        $this->assertSame('m.sunil@rightally.io', $mail->to_email);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'hooks.slack.com') && str_contains($r['text'], 'New signing: Harbor Point Realty'));
    }

    public function test_alerts_can_be_switched_off_and_placeholder_slack_urls_are_ignored(): void
    {
        Http::fake();
        app(SettingsService::class)->setMany('alerts', ['new_signing' => '0']);
        $this->post('/start', $this->details());
        $this->sign(Customer::firstOrFail());
        $this->assertSame(0, EmailLog::where('template_key', 'team_alert')->count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'slack'));
    }

    public function test_daily_run_sends_a_go_live_summary(): void
    {
        $this->fakeBilling();
        Carbon::setTestNow(Carbon::parse('2026-10-23 09:00', 'America/New_York'));
        $this->billedCustomer();

        $this->artisan('billing:daily')->assertSuccessful();

        $mail = EmailLog::where('template_key', 'team_alert')->where('subject', 'like', '%go-lives%')->firstOrFail();
        $this->assertSame('[RightAlly] Today’s go-lives (1)', $mail->subject);
    }
}
