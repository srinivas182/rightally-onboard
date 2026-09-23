<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Setting;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = Admin::factory()->superAdmin()->withTwoFactor()->create();
        $this->actingAs($this->admin, 'admin');
    }

    public function test_defaults_are_used_until_changed(): void
    {
        $settings = app(SettingsService::class);

        $this->assertSame('3000.00', $settings->get('pricing', 'setup_fee'));
        $this->assertSame('Mayura Consultancy Services LLC', $settings->get('company', 'legal_name'));
        $this->get('/admin/settings')->assertOk()->assertSee('Mayura Consultancy Services LLC');
    }

    public function test_stripe_secret_is_encrypted_at_rest_and_never_shown(): void
    {
        $secret = 'sk_test_'.str_repeat('a', 20).'WXYZ';

        $this->put('/admin/settings/stripe', ['mode' => 'test', 'test_secret_key' => $secret])->assertSessionHasNoErrors();

        $row = Setting::where(['group' => 'stripe', 'key' => 'test_secret_key'])->first();
        $this->assertTrue($row->is_encrypted);
        $this->assertStringNotContainsString($secret, $row->value);
        $this->assertSame($secret, app(SettingsService::class)->get('stripe', 'test_secret_key'));

        $this->get('/admin/settings')->assertDontSee($secret)->assertSee('WXYZ');
        $this->assertDatabaseMissing('activity_logs', ['changes' => json_encode(['test_secret_key' => $secret])]);
    }

    public function test_blank_secret_keeps_the_saved_value(): void
    {
        $secret = 'sk_test_'.str_repeat('b', 24);
        $this->put('/admin/settings/stripe', ['mode' => 'test', 'test_secret_key' => $secret]);
        $this->put('/admin/settings/stripe', ['mode' => 'test', 'test_secret_key' => '']);

        $this->assertSame($secret, app(SettingsService::class)->get('stripe', 'test_secret_key'));
    }

    public function test_wrong_kind_of_key_is_rejected(): void
    {
        $this->put('/admin/settings/stripe', ['mode' => 'test', 'test_secret_key' => 'sk_live_oops'])
            ->assertSessionHasErrorsIn('stripe', 'test_secret_key');
    }

    public function test_live_mode_needs_all_live_keys(): void
    {
        $this->put('/admin/settings/stripe', ['mode' => 'live'])->assertSessionHasErrors('mode');
        $this->assertSame('test', app(SettingsService::class)->get('stripe', 'mode'));
    }

    public function test_pricing_validation_and_audit(): void
    {
        $this->put('/admin/settings/pricing', [
            'setup_fee' => '-1', 'deposit_percent' => '10', 'go_live_days' => '30',
            'platform_fee' => '500', 'per_agent_fee' => '20', 'min_agents' => '5',
        ])->assertSessionHasErrorsIn('pricing', 'setup_fee');

        $this->put('/admin/settings/pricing', [
            'setup_fee' => '3500.00', 'deposit_percent' => '10', 'go_live_days' => '30',
            'platform_fee' => '500.00', 'per_agent_fee' => '20.00', 'min_agents' => '5',
        ])->assertSessionHasNoErrors();

        $this->assertSame('3500.00', app(SettingsService::class)->get('pricing', 'setup_fee'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.pricing']);
    }
}
