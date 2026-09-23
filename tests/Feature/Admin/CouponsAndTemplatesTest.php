<?php

namespace Tests\Feature\Admin;

use App\Enums\ContractType;
use App\Models\Admin;
use App\Models\ContractTemplate;
use App\Models\Coupon;
use App\Models\Role;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponsAndTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class]);
        $this->admin = Admin::factory()->superAdmin()->withTwoFactor()->create();
    }

    public function test_admin_creates_a_coupon_that_never_expires(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/coupons', [
            'code' => 'spring20', 'name' => 'Spring', 'percent_off' => 20, 'never_expires' => '1', 'is_active' => '1',
        ])->assertRedirect('/admin/coupons');

        $coupon = Coupon::firstOrFail();
        $this->assertSame('SPRING20', $coupon->code);
        $this->assertNull($coupon->expires_on);
        $this->assertTrue($coupon->isUsable());
    }

    public function test_coupon_needs_an_expiry_date_unless_it_never_expires(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/coupons', ['code' => 'X100', 'name' => 'X', 'percent_off' => 10, 'is_active' => '1'])
            ->assertSessionHasErrors('expires_on');
    }

    public function test_used_coupon_can_be_extended_but_not_renamed(): void
    {
        $coupon = Coupon::create(['code' => 'NAR2026', 'name' => 'NAR', 'percent_off' => 15, 'expires_on' => '2026-11-15', 'is_active' => true]);
        $coupon->forceFill(['times_used' => 3])->save();

        $this->actingAs($this->admin, 'admin')->put("/admin/coupons/{$coupon->id}", [
            'code' => 'RENAMED', 'name' => 'NAR', 'percent_off' => 15, 'expires_on' => '2027-01-31', 'is_active' => '1',
        ])->assertRedirect();

        $coupon->refresh();
        $this->assertSame('NAR2026', $coupon->code);
        $this->assertSame('2027-01-31', $coupon->expires_on->toDateString());
    }

    public function test_coupons_menu_needs_permission(): void
    {
        $viewer = Admin::factory()->withTwoFactor()->create(['role_id' => Role::firstWhere('name', 'Viewer')->id]);

        $this->actingAs($viewer, 'admin')->get('/admin/coupons')->assertForbidden();
        $this->get('/admin/contracts')->assertForbidden();
    }

    public function test_published_template_is_read_only_and_new_versions_start_as_drafts(): void
    {
        $v1 = ContractTemplate::firstOrFail();
        $this->actingAs($this->admin, 'admin');

        $this->put("/admin/contract-templates/{$v1->id}", ['version' => '1.0', 'title' => 'X', 'body_html' => '<p>X</p>'])->assertForbidden();

        $this->post("/admin/contract-templates/{$v1->id}/new-version")->assertRedirect();
        $draft = ContractTemplate::whereNull('published_at')->firstOrFail();
        $this->assertSame('1.1', $draft->version);
        $this->assertFalse($draft->is_active);

        $this->put("/admin/contract-templates/{$draft->id}", [
            'version' => '1.1', 'title' => 'Updated agreement',
            'body_html' => '<p onclick="alert(1)">New terms {{ client_company }}</p><script>alert(2)</script>',
        ])->assertRedirect();
        $draft->refresh();
        $this->assertStringNotContainsString('script', $draft->body_html);
        $this->assertStringNotContainsString('onclick', $draft->body_html);
        $this->assertStringContainsString('{{ client_company }}', $draft->body_html);

        $this->post("/admin/contract-templates/{$draft->id}/publish")->assertRedirect();
        $this->assertTrue($draft->fresh()->is_active);
        $this->assertFalse($v1->fresh()->is_active);
        $this->assertSame(1, ContractTemplate::where('type', ContractType::Initial)->where('is_active', true)->count());

        $this->get("/admin/contract-templates/{$draft->id}")->assertOk()->assertSee('Sunline Realty Group');
    }
}
