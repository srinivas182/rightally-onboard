<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Role;
use App\Services\Settings\SettingsService;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, EmailTemplateSeeder::class]);
        $this->admin = Admin::factory()->superAdmin()->withTwoFactor()->create(['email' => 'srini@rightally.io']);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<m@brevo>'], 201)]);
    }

    public function test_list_and_edit_an_automatic_email_with_preview(): void
    {
        $t = EmailTemplate::firstWhere('key', 'agreement_signed');
        $this->actingAs($this->admin, 'admin')->get('/admin/email-templates')->assertOk()->assertSee('Agreement signed');
        $this->get("/admin/email-templates/{$t->id}/edit")->assertOk()->assertSee('Welcome aboard, Maria')->assertSee('Download your agreement');

        $this->put("/admin/email-templates/{$t->id}", ['subject' => 'Welcome to RightAlly', 'body' => "Hello {first_name}\n\nThanks!", 'cc_team' => '0', 'is_enabled' => '1'])
            ->assertRedirect();
        $t->refresh();
        $this->assertSame('Welcome to RightAlly', $t->subject);
        $this->assertFalse($t->cc_team);

        $this->post("/admin/email-templates/{$t->id}/reset")->assertRedirect();
        $this->assertSame('Your RightAlly agreement is signed', $t->fresh()->subject);
        $this->assertTrue($t->fresh()->cc_team);
    }

    public function test_automatic_emails_cannot_be_deleted_but_custom_ones_can(): void
    {
        $system = EmailTemplate::firstWhere('key', 'payment_failed');
        $this->actingAs($this->admin, 'admin')->delete("/admin/email-templates/{$system->id}")->assertForbidden();

        $this->post('/admin/email-templates', ['name' => 'Kick-off call', 'subject' => 'Let’s schedule your kick-off', 'body' => "Hi {first_name},\n\nPick a time.", 'is_enabled' => '1'])->assertRedirect();
        $custom = EmailTemplate::where('is_system', false)->firstOrFail();
        $this->assertStringStartsWith('custom-kick-off-call-', $custom->key);

        $this->delete("/admin/email-templates/{$custom->id}")->assertRedirect();
        $this->assertNull($custom->fresh());
    }

    public function test_send_test_email_goes_to_the_signed_in_admin_through_brevo(): void
    {
        app(SettingsService::class)->setMany('email', ['brevo_api_key' => 'xkeysib-test']);
        $t = EmailTemplate::firstWhere('key', 'balance_failed');

        $this->actingAs($this->admin, 'admin')->post("/admin/email-templates/{$t->id}/test")->assertSessionHas('success', 'Test email sent to srini@rightally.io.');

        Http::assertSent(fn (Request $r) => $r['to'][0]['email'] === 'srini@rightally.io'
            && str_starts_with($r['subject'], '[Test]') && ! isset($r['cc'])
            && str_contains($r['htmlContent'], 'Update payment method'));
    }

    public function test_without_brevo_emails_fall_back_to_the_app_mailer(): void
    {
        $t = EmailTemplate::firstWhere('key', 'balance_failed');
        config(['mail.default' => 'array']);

        $this->actingAs($this->admin, 'admin')->post("/admin/email-templates/{$t->id}/test")->assertSessionHas('success');
        $this->assertSame('sent', EmailLog::firstOrFail()->status);
        Http::assertNothingSent();
    }

    public function test_admin_invitation_uses_the_editable_template(): void
    {
        app(SettingsService::class)->setMany('email', ['brevo_api_key' => 'xkeysib-test']);
        $role = Role::firstWhere('name', 'Billing');

        $this->actingAs($this->admin, 'admin')->post('/admin/admins', ['name' => 'Sunil', 'email' => 'm.sunil@rightally.io', 'role_id' => $role->id])->assertSessionHasNoErrors()->assertRedirect();

        Http::assertSent(fn (Request $r) => $r['to'][0]['email'] === 'm.sunil@rightally.io'
            && $r['subject'] === 'You’re invited to the RightAlly admin'
            && str_contains($r['htmlContent'], e($this->admin->name).' has invited you') && str_contains($r['htmlContent'], 'Set your password'));
    }
}
