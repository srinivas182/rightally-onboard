<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\EmailLog;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesBilling;
use Tests\TestCase;

class ClientSignInTest extends TestCase
{
    use FakesBilling, RefreshDatabase;

    private Customer $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
        $this->fakeBilling();
        $this->client = $this->billedCustomer(CustomerStatus::Live);
    }

    public function test_first_time_client_gets_a_create_password_email_then_signs_in(): void
    {
        $this->get("/account/{$this->client->uuid}")->assertRedirect('/account');
        $this->post('/account', ['email' => $this->client->email])->assertSessionHas('status');
        $this->assertSame(1, EmailLog::where('template_key', 'customer_set_password')->where('to_email', $this->client->email)->count());

        $token = Password::broker('customers')->createToken($this->client);
        $this->get("/account/reset/{$token}?email={$this->client->email}")->assertOk()->assertSee('Create your password');
        $this->post('/account/reset', ['token' => $token, 'email' => $this->client->email, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/account/reset', ['token' => $token, 'email' => $this->client->email, 'password' => 'Brokerage2026x', 'password_confirmation' => 'Brokerage2026x'])
            ->assertRedirect("/account/{$this->client->uuid}");
        $this->assertTrue($this->client->fresh()->hasPassword());
        $this->assertAuthenticatedAs($this->client->fresh(), 'customer');

        // Intended page after signing in again.
        $this->post('/account/logout');
        $this->get("/account/{$this->client->uuid}?tab=invoices");
        $this->post('/account', ['email' => $this->client->email])->assertRedirect('/account/password');
        $this->post('/account/password', ['password' => 'wrong-password1'])->assertSessionHasErrors('password');
        $this->post('/account/password', ['password' => 'Brokerage2026x'])->assertRedirect("/account/{$this->client->uuid}?tab=invoices");
    }

    public function test_unknown_email_is_told_there_is_no_account(): void
    {
        $this->post('/account', ['email' => 'nobody@example.com'])->assertSessionHasErrors('email');
        $this->assertStringContainsString('couldn’t find a RightAlly account', session('errors')->first('email'));
    }

    public function test_client_can_only_see_their_own_account(): void
    {
        $other = new Customer(['status' => CustomerStatus::Live, 'first_name' => 'O', 'last_name' => 'P', 'title' => 'T', 'company_name' => 'Other Realty',
            'email' => 'other@example.com', 'phone_e164' => '+13055550199', 'street' => 'S', 'city' => 'C', 'state_code' => 'FL', 'zip' => '33131']);
        $other->save();
        $this->actingAs($this->client, 'customer');

        $this->get("/account/{$this->client->uuid}")->assertOk()->assertSee('Sign out');
        $this->get("/account/{$other->uuid}")->assertForbidden()->assertSee('doesn’t have access to this account');
    }

    public function test_forgot_password_sends_a_reset_email(): void
    {
        $this->client->forceFill(['password' => 'Brokerage2026x', 'password_set_at' => now()])->save();
        $this->post('/account', ['email' => $this->client->email])->assertRedirect('/account/password');
        $this->post('/account/forgot')->assertSessionHas('status');
        $this->assertSame(1, EmailLog::where('template_key', 'customer_reset_password')->count());
    }

    public function test_repeated_wrong_passwords_are_slowed_down(): void
    {
        $this->client->forceFill(['password' => 'Brokerage2026x'])->save();
        $this->post('/account', ['email' => $this->client->email]);
        foreach (range(1, 5) as $_) {
            $this->post('/account/password', ['password' => 'nope-nope-1']);
        }
        $this->post('/account/password', ['password' => 'Brokerage2026x'])->assertSessionHasErrors('password');
        $this->assertGuest('customer');
    }
}
