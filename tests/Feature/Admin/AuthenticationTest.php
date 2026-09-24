<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\EmailLog;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_login_page_renders_with_security_headers(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Sign in to the admin')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_wrong_password_shows_one_generic_message(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'The email or password is incorrect.']);
        $this->assertGuest('admin');
    }

    public function test_admin_without_authenticator_signs_in_with_an_emailed_code(): void
    {
        $this->seed(EmailTemplateSeeder::class);
        $admin = Admin::factory()->superAdmin()->create();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/admin/two-factor/challenge?method=email');
        $this->get('/admin')->assertRedirect('/admin/login'); // not signed in yet

        $mail = EmailLog::where('template_key', 'admin_login_code')->where('to_email', $admin->email)->firstOrFail();
        preg_match('/(\d{6})/', $mail->subject, $m);

        $this->post('/admin/two-factor/challenge', ['email_code' => '000000'])->assertSessionHasErrors('email_code');
        $this->post('/admin/two-factor/challenge', ['email_code' => $m[1]])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('set up an authenticator app');

        // The code works once.
        auth('admin')->logout();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password']);
        $this->post('/admin/two-factor/challenge', ['email_code' => $m[1]])->assertSessionHasErrors('email_code');
    }

    public function test_email_codes_are_rate_limited(): void
    {
        $this->seed(EmailTemplateSeeder::class);
        $admin = Admin::factory()->superAdmin()->withTwoFactor()->create();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin/two-factor/challenge');

        $this->post('/admin/two-factor/email-code')->assertSessionHas('status');
        $this->post('/admin/two-factor/email-code')->assertSessionHas('warning'); // one a minute
        $this->assertSame(1, EmailLog::where('template_key', 'admin_login_code')->count());
    }

    public function test_confirming_two_factor_setup_enables_it_and_shows_recovery_codes(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->actingAs($admin, 'admin')->get('/admin/two-factor/setup')->assertOk()->assertSee('Scan this QR code');
        $secret = session('admin.2fa_setup_secret');

        $this->post('/admin/two-factor/setup', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertRedirect('/admin/two-factor/recovery-codes');

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        $this->get('/admin/two-factor/recovery-codes')->assertOk()->assertSee('Save your recovery codes');
    }

    public function test_admin_with_two_factor_is_not_signed_in_until_code_is_verified(): void
    {
        $admin = Admin::factory()->superAdmin()->withTwoFactor(self::SECRET)->create();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/admin/two-factor/challenge');
        $this->assertGuest('admin');

        $this->post('/admin/two-factor/challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest('admin');

        $this->post('/admin/two-factor/challenge', ['code' => app(Google2FA::class)->getCurrentOtp(self::SECRET)])
            ->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_recovery_code_works_only_once(): void
    {
        $admin = Admin::factory()->superAdmin()->withTwoFactor(self::SECRET)->create();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password']);
        $this->post('/admin/two-factor/challenge', ['recovery_code' => 'aaaaa-bbbbb'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin, 'admin');

        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password']);
        $this->post('/admin/two-factor/challenge', ['recovery_code' => 'aaaaa-bbbbb'])->assertSessionHasErrors('recovery_code');
        $this->assertGuest('admin');
    }

    public function test_deactivated_admin_cannot_sign_in(): void
    {
        $admin = Admin::factory()->superAdmin()->create(['is_active' => false]);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_login_is_rate_limited(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong']);
        }
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])->assertStatus(429);
    }

    public function test_invited_admin_sets_password_through_signed_link(): void
    {
        $admin = Admin::factory()->superAdmin()->invited()->create();
        $url = \URL::temporarySignedRoute('admin.invitation.show', now()->addHour(), ['admin' => $admin->id]);

        $this->get($url)->assertOk()->assertSee('Set a password');
        $this->post($url, ['password' => 'Str0ng!Passw0rd#', 'password_confirmation' => 'Str0ng!Passw0rd#'])
            ->assertRedirect('/admin/two-factor/setup');
        $this->assertTrue($admin->fresh()->hasAcceptedInvite());

        // Tampered link is rejected.
        $this->post('/admin/logout');
        $this->get('/admin/invitation/'.$admin->id)->assertForbidden();
    }

    public function test_two_factor_attempts_are_limited_per_admin_even_with_a_new_session(): void
    {
        $admin = Admin::factory()->superAdmin()->withTwoFactor(self::SECRET)->create();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/two-factor/challenge', ['code' => '000000']);
        }

        // A fresh session must not reset the counter for this admin.
        $this->flushSession();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/admin/two-factor/challenge');
        $this->post('/admin/two-factor/challenge', ['code' => app(Google2FA::class)->getCurrentOtp(self::SECRET)])
            ->assertStatus(429);
        $this->assertGuest('admin');
    }
}
