<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Structural accessibility checks (the full axe audit runs in a browser; see docs/accessibility.md). */
class AccessibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
    }

    private function start(): Customer
    {
        $this->post('/start', ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'title' => 'Owner', 'company_name' => 'Key Realty', 'email' => 'ana@key.com',
            'phone' => '3055550148', 'agents' => 8, 'street' => '1 Main St', 'city' => 'Miami', 'state_code' => 'FL', 'zip' => '33131']);

        return Customer::firstOrFail();
    }

    public function test_pages_have_skip_link_language_and_labelled_fields(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('href="#main"', false)->assertSee('id="main"', false)
            ->assertSee('<label class="visually-hidden" for="coupon">', false);
        $this->get('/admin/login')->assertSee('href="#main"', false);
    }

    public function test_client_can_sign_with_their_typed_name_instead_of_drawing(): void
    {
        $customer = $this->start();
        $this->get("/onboard/{$customer->uuid}/agreement")->assertSee('Use my typed name as my signature instead of drawing');

        $img = imagecreatetruecolor(900, 220);
        imagestring($img, 5, 30, 100, 'Ana Ruiz', imagecolorallocate($img, 8, 57, 178));
        ob_start();
        imagepng($img);
        $this->post("/onboard/{$customer->uuid}/agreement/sign", ['consent' => '1', 'typed_name' => 'Ana Ruiz', 'signature_method' => 'typed',
            'signature' => 'data:image/png;base64,'.base64_encode((string) ob_get_clean())])->assertRedirect();

        $contract = Contract::firstOrFail();
        $this->assertSame('typed', $contract->signature_method);
        $this->assertTrue($contract->isSigned());
    }
}
