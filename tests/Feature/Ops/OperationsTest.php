<?php

namespace Tests\Feature\Ops;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\StripeEvent;
use App\Services\Admin\SystemHealth;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_problems_without_secrets(): void
    {
        $res = $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.database', true)->assertJsonPath('checks.stripe', false);
        $this->assertStringNotContainsString('sk_', $res->getContent());
    }

    public function test_health_is_ok_when_everything_is_configured_and_running(): void
    {
        app(SettingsService::class)->setMany('stripe', ['test_publishable_key' => 'pk_test_x', 'test_secret_key' => 'sk_test_x', 'test_webhook_secret' => 'whsec_x']);
        app(SettingsService::class)->setMany('email', ['brevo_api_key' => 'xkeysib-x']);
        StripeEvent::create(['stripe_event_id' => 'evt_1', 'type' => 'invoice.paid', 'payload' => [], 'processed_at' => now()]);
        Cache::forever(SystemHealth::HEARTBEAT_KEY, now());
        Cache::forever(SystemHealth::BILLING_RUN_KEY, now()->subHours(3));

        $this->getJson('/health')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_super_admin_sees_system_status_on_the_dashboard(): void
    {
        $this->seed(RoleSeeder::class);
        $this->actingAs(Admin::factory()->superAdmin()->withTwoFactor()->create(), 'admin')
            ->get('/admin')->assertOk()->assertSee('System status')->assertSee('Keys missing in Settings');
    }

    public function test_expired_links_show_a_branded_page(): void
    {
        $customer = new Customer(['status' => 'draft', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'T', 'company_name' => 'C', 'email' => 'a@b.com',
            'phone_e164' => '+13055550148', 'street' => 'S', 'city' => 'C', 'state_code' => 'FL', 'zip' => '33131']);
        $customer->save();
        $this->get("/onboard/{$customer->uuid}/agreement")->assertForbidden()->assertSee('This onboarding link has expired')->assertSee('RightAlly');
        $this->get('/no-such-page')->assertNotFound()->assertSee('Page not found');
    }

    public function test_robots_keep_private_pages_out_of_search(): void
    {
        $this->assertStringContainsString('Disallow: /admin', file_get_contents(public_path('robots.txt')));
    }
}
