<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Models\Admin;
use App\Models\CallBooking;
use App\Models\EmailLog;
use App\Services\Settings\SettingsService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesBilling;
use Tests\TestCase;

class CallsTest extends TestCase
{
    use FakesBilling, RefreshDatabase;

    private string $hook;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
        app(SettingsService::class)->setMany('calls', ['ghl_webhook_secret' => 'sec_123']);
        $this->hook = '/api/v1/ghl/appointments/sec_123';
    }

    /** Roughly what a GoHighLevel workflow webhook sends, plus the recommended Custom Data keys. */
    private function payload(array $o = []): array
    {
        return array_replace_recursive([
            'contact_id' => 'c_1', 'first_name' => 'James', 'last_name' => 'Okafor', 'full_name' => 'James Okafor',
            'email' => 'James@HarborPoint.com', 'phone' => '+18135550100', 'company_name' => 'Harbor Point Realty',
            'calendar' => ['id' => 'epg292VHDOxISDnnRa4m', 'appointmentId' => 'apt_1', 'startTime' => '2026-10-02T14:00:00-04:00',
                'endTime' => '2026-10-02T14:30:00-04:00', 'status' => 'booked', 'selectedTimezone' => 'America/New_York', 'address' => 'https://meet.google.com/abc-defg-hij'],
            'customData' => ['coupon' => 'nar2026', 'utm_campaign' => 'fall-fb'],
        ], $o);
    }

    public function test_booking_page_embeds_the_calendar_with_coupon_and_campaign(): void
    {
        $this->get('/book-a-call?coupon=nar2026&utm_campaign=fall')->assertOk()
            ->assertSee('api.leadconnectorhq.com/widget/booking/epg292VHDOxISDnnRa4m?', false)
            ->assertSee('coupon_code=NAR2026', false)->assertSee('utm_campaign=fall', false)
            ->assertSee('You were referred by NAR2026')
            ->assertHeader('Content-Security-Policy');
        $csp = $this->get('/book-a-call')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://link.msgsndr.com', $csp);
        $this->assertStringContainsString('https://api.leadconnectorhq.com', $csp);

        // Coupon remembered from the onboarding link.
        $this->get('/?coupon=SPRING');
        $this->get('/book-a-call')->assertSee('coupon_code=SPRING', false);
        $this->get('/')->assertSee('Book a call');
        $this->get('/book-a-call?coupon=christopher-jones')->assertSee('You were referred by Christopher Jones')->assertSee('coupon_code=CHRISTOPHER-JONES', false)->assertSee('brand/logo-white.png');
    }

    public function test_webhook_records_the_booking_and_later_status_changes(): void
    {
        $this->postJson('/api/v1/ghl/appointments/wrong', $this->payload())->assertForbidden();
        $this->postJson($this->hook, $this->payload())->assertOk()->assertJson(['ok' => true]);

        $call = CallBooking::firstOrFail();
        $this->assertSame('apt_1', $call->ghl_appointment_id);
        $this->assertSame('james@harborpoint.com', $call->email);
        $this->assertSame('NAR2026', $call->coupon_code);
        $this->assertSame('fall-fb', $call->source);
        $this->assertSame('2026-10-02 18:00:00', $call->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $call->status);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $call->meeting_link);

        $this->postJson($this->hook, $this->payload(['calendar' => ['status' => 'showed']]))->assertOk();
        $this->assertSame(1, CallBooking::count());
        $this->assertSame('completed', $call->fresh()->status);
    }

    public function test_call_becomes_onboarded_when_the_same_email_pays_the_deposit(): void
    {
        $this->fakeBilling();
        $this->postJson($this->hook, $this->payload(['email' => 'maria@sunlinerealty.com']))->assertOk();
        $customer = $this->billedCustomer(CustomerStatus::ContractSigned);

        $customer->update(['status' => CustomerStatus::AwaitingGoLive]); // deposit paid
        $call = CallBooking::firstOrFail();
        $this->assertSame('onboarded', $call->status);
        $this->assertSame($customer->id, $call->customer_id);
    }

    public function test_admin_sees_calls_by_coupon_and_can_send_an_onboarding_link(): void
    {
        $this->postJson($this->hook, $this->payload())->assertOk();
        $this->postJson($this->hook, $this->payload(['calendar' => ['appointmentId' => 'apt_2'], 'email' => 'b@x.com', 'customData' => ['coupon' => 'OTHER']]))->assertOk();
        $this->actingAs(Admin::factory()->superAdmin()->withTwoFactor()->create(), 'admin');

        Carbon::setTestNow('2026-09-29 10:00');
        $this->get('/admin/calls')->assertOk()->assertSee('By coupon')->assertSee('NAR2026')->assertSee('OTHER')->assertSee('b@x.com');
        $this->get('/admin/calls?coupon=NAR2026')->assertSee('James Okafor')->assertDontSee('b@x.com');

        $call = CallBooking::firstWhere('ghl_appointment_id', 'apt_1');
        $this->post("/admin/calls/{$call->id}/onboarding-link")->assertSessionHas('success');
        $mail = EmailLog::where('template_key', 'call_onboarding_link')->firstOrFail();
        $this->assertSame('james@harborpoint.com', $mail->to_email);
        $this->put("/admin/calls/{$call->id}", ['status' => 'no_show', 'admin_notes' => 'Didn’t join'])->assertSessionHas('success');
        $this->assertSame('no_show', $call->fresh()->status);

        $this->get('/admin/settings')->assertSee('/api/v1/ghl/appointments/sec_123');
        Carbon::setTestNow();
    }
}
