<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\CallBooking;
use App\Models\Contract;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Services\Onboarding\LeadFollowUps;
use App\Services\Onboarding\ResumeLinks;
use App\Services\Settings\SettingsService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TwoPartOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'America/New_York'));
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
        app(SettingsService::class)->setMany('pricing', ['require_coupon' => '1']);
        Coupon::create(['code' => 'CHRISTOPHER-JONES', 'name' => 'Referral', 'percent_off' => 50, 'is_active' => true]);
        Http::fake(['services.leadconnectorhq.com/*' => Http::response(['contact' => ['id' => 'ghl_c1']]), '*' => Http::response([])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function about(array $o = []): array
    {
        return $o + ['first_name' => 'James', 'last_name' => 'Okafor', 'title' => 'Owner', 'company_name' => 'Harbor Point Realty',
            'email' => 'james@harborpoint.com', 'phone' => '8135550100', 'coupon' => 'christopher-jones'];
    }

    private function brokerage(array $o = []): array
    {
        return $o + ['agents' => 12, 'street' => '400 N Ashley Dr', 'city' => 'Tampa', 'state_code' => 'FL', 'zip' => '33602', 'billing' => 'month'];
    }

    public function test_invitation_only_needs_a_valid_code_to_start(): void
    {
        // No code: the invitation page, not the form.
        $this->get('/')->assertOk()->assertSee('RightAlly is currently by invitation')->assertSee('Book a call with us')->assertDontSee('First name');
        $this->get('/?coupon=nope')->assertOk()->assertSee('is-invalid', false)->assertDontSee('First name');
        // Valid code: the form.
        $this->get('/?coupon=christopher-jones')->assertOk()->assertSee('Referral code')->assertSee('part 1 of 2')->assertSee('First name')
            ->assertDontSee('Number of agents');
        $this->post('/start/about', $this->about(['coupon' => '']))->assertSessionHasErrors('coupon');
        $this->assertStringContainsString('by invitation', session('errors')->first('coupon'));
        $this->post('/start/about', $this->about(['coupon' => 'NOPE']))->assertSessionHasErrors('coupon');
        $this->assertSame(0, Customer::count());

        // Switched off: open to anyone.
        app(SettingsService::class)->setMany('pricing', ['require_coupon' => '0']);
        $this->post('/start/about', $this->about(['coupon' => '']))->assertRedirect();
        $this->assertSame(1, Customer::count());
    }

    public function test_about_you_saves_a_lead_alerts_the_team_and_syncs_gohighlevel(): void
    {
        app(SettingsService::class)->setMany('alerts', ['ghl_token' => 'pit-123', 'ghl_location_id' => 'loc_1']);
        $this->get('/?coupon=christopher-jones')->assertSee('You were referred by Christopher Jones');

        $res = $this->post('/start/about', $this->about());
        $c = Customer::firstOrFail();
        $res->assertRedirect("/onboard/{$c->uuid}/brokerage");
        $this->assertSame(CustomerStatus::Draft, $c->status);
        $this->assertSame('brokerage', $c->onboarding_stage);
        $this->assertNull($c->street);
        $this->assertSame(0, Contract::count());
        $this->assertSame('CHRISTOPHER-JONES', $c->coupon->code);

        $alerts = EmailLog::where('template_key', 'lead_alert')->pluck('to_email')->sort()->values()->all();
        $this->assertSame(['m.sunil@mayuraconsultancy.com', 'srinivas@mayuraconsultancy.com'], $alerts);
        $this->assertStringContainsString('Harbor Point Realty', EmailLog::where('template_key', 'lead_alert')->first()->subject);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/contacts/upsert') && $r['locationId'] === 'loc_1' && $r['email'] === 'james@harborpoint.com'
            && in_array('rightally-lead', $r['tags'], true) && in_array('coupon:christopher-jones', $r['tags'], true) && $r->hasHeader('Version', '2021-07-28'));
        $this->assertSame('ghl_c1', $c->fresh()->ghl_contact_id);
    }

    public function test_your_brokerage_completes_the_details_and_back_keeps_everything(): void
    {
        $this->post('/start/about', $this->about());
        $c = Customer::firstOrFail();

        $this->get("/onboard/{$c->uuid}/agreement")->assertRedirect("/onboard/{$c->uuid}/brokerage");
        $this->get("/onboard/{$c->uuid}/brokerage")->assertOk()->assertSee('Your brokerage')->assertSee('part 2 of 2')
            ->assertSee('Number of agents')->assertSee('District of Columbia')->assertSee('Subscription billing')
            ->assertSee('CHRISTOPHER-JONES applied')->assertDontSee('data-maps-key', false);
        $this->put("/onboard/{$c->uuid}/brokerage", $this->brokerage(['zip' => 'abc']))->assertSessionHasErrors('zip');
        $this->put("/onboard/{$c->uuid}/brokerage", $this->brokerage())->assertRedirect("/onboard/{$c->uuid}/agreement");

        $c->refresh();
        $this->assertSame('agreement', $c->onboarding_stage);
        $this->assertSame('Tampa', $c->city);
        $contract = Contract::firstOrFail();
        $this->assertSame(150000, $contract->implementation_fee_cents); // 50% off $3,000
        $this->assertSame(12, $contract->agent_count);

        // Back to "About you": prefilled; saving returns to 1b.
        $this->get("/onboard/{$c->uuid}/details")->assertOk()->assertSee('value="Harbor Point Realty"', false);
        $this->put("/onboard/{$c->uuid}/details", $this->about(['company_name' => 'Harbor Point Realty Group']))->assertRedirect("/onboard/{$c->uuid}/brokerage");
        $this->assertSame('Harbor Point Realty Group', $c->fresh()->company_name);
        $this->assertSame(1, Contract::count());
    }

    public function test_address_suggestions_load_only_with_a_google_key(): void
    {
        app(SettingsService::class)->setMany('alerts', ['google_maps_key' => 'AIzaTestKey123']);
        $this->post('/start/about', $this->about());
        $c = Customer::firstOrFail();
        $res = $this->get("/onboard/{$c->uuid}/brokerage")->assertSee('data-maps-key="AIzaTestKey123"', false);
        $this->assertStringContainsString('https://places.googleapis.com', $res->headers->get('Content-Security-Policy'));
        $this->get('/?coupon=christopher-jones')->assertDontSee('data-maps-key', false); // not on "About you"
    }

    public function test_admin_sees_where_people_stopped_and_resume_links_open_that_screen(): void
    {
        $this->post('/start/about', $this->about());
        $c = Customer::firstOrFail();
        $this->assertSame('onboarding.brokerage', ResumeLinks::nextStep($c)['route']);

        $this->actingAs(Admin::factory()->superAdmin()->withTwoFactor()->create(), 'admin');
        $this->get('/admin/customers?status=incomplete')->assertOk()->assertSee('Stopped at')->assertSee('Brokerage details');
        $page = $this->get("/admin/customers/{$c->uuid}");
        $page->assertSee('Stopped at: <b>Brokerage details</b>', false)->assertSee('/brokerage?expires=', false)->assertSee('Follow-up emails: 0 of 4 sent');
        $this->get('/admin/reports')->assertOk()->assertSee('Details done');
    }

    public function test_follow_up_sequence_timing_quiet_hours_and_stopping(): void
    {
        $this->post('/start/about', $this->about());
        $c = Customer::firstOrFail();
        $f = app(LeadFollowUps::class);
        $sent = fn () => EmailLog::where('template_key', 'like', 'follow_up_%')->orderBy('id')->pluck('template_key')->all();

        $this->assertSame(0, $f->run()); // too soon
        Carbon::setTestNow(now()->addMinutes(70));
        $this->assertSame(1, $f->run());
        $this->assertSame(['follow_up_1'], $sent());
        $this->assertSame(0, $f->run()); // not twice

        // Day 1 at 10pm Eastern: quiet hours; it goes out at 8am.
        Carbon::setTestNow(Carbon::parse('2026-10-08 22:00', 'America/New_York'));
        $this->assertSame(0, $f->run());
        Carbon::setTestNow(Carbon::parse('2026-10-09 08:05', 'America/New_York'));
        $this->assertSame(1, $f->run());
        $this->assertSame(['follow_up_1', 'follow_up_2'], $sent());
        $mail = EmailLog::where('template_key', 'follow_up_2')->first();
        $this->assertSame('james@harborpoint.com', $mail->to_email);

        // One click stops the rest.
        $link = $f->stopLink($c->fresh());
        $this->get($link)->assertOk()->assertSee('Reminders stopped');
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00', 'America/New_York'));
        $this->assertSame(0, $f->run());
        $this->get('/follow-ups/stop/'.$c->uuid)->assertForbidden(); // must be the signed link
    }

    public function test_follow_ups_stop_when_they_book_a_call_or_sign(): void
    {
        $this->post('/start/about', $this->about());
        $this->post('/start/about', $this->about(['email' => 'pat@bay.com', 'company_name' => 'Bay Realty']));
        CallBooking::create(['email' => 'james@harborpoint.com', 'status' => 'scheduled', 'starts_at' => now()->addDay()]);
        Customer::where('email', 'pat@bay.com')->first()->update(['status' => CustomerStatus::ContractSigned]);

        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(0, app(LeadFollowUps::class)->run());
        $this->assertSame('payment', Customer::where('email', 'pat@bay.com')->value('onboarding_stage'));
    }

    public function test_activity_records_every_onboarding_step_and_the_emails(): void
    {
        $this->post('/start/about', $this->about());
        $c = Customer::firstOrFail();
        $this->put("/onboard/{$c->uuid}/brokerage", $this->brokerage());
        $this->get("/onboard/{$c->uuid}/agreement");
        $this->get("/onboard/{$c->uuid}/agreement"); // second view: not logged again
        $this->put("/onboard/{$c->uuid}/details", $this->about(['title' => 'Broker/Owner']));

        $actions = ActivityLog::where('subject_id', $c->id)->pluck('action')->all();
        foreach (['onboarding.started', 'lead.alert_sent', 'onboarding.brokerage_added', 'onboarding.agreement_opened', 'onboarding.about_updated'] as $a) {
            $this->assertContains($a, $actions);
        }
        $this->assertSame(1, collect($actions)->filter(fn ($a) => $a === 'onboarding.agreement_opened')->count());

        $this->actingAs(Admin::factory()->superAdmin()->withTwoFactor()->create(), 'admin');
        $this->get("/admin/customers/{$c->uuid}")
            ->assertSee('started onboarding for Harbor Point Realty: About you completed, referral code CHRISTOPHER-JONES')
            ->assertSee('Client added brokerage details: 12 agents, 400 N Ashley Dr, Tampa FL 33602, monthly billing')
            ->assertSee('Client opened agreement')->assertSee('Client updated About you: title');
    }
}
