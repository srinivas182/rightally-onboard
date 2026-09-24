<?php

namespace Tests\Feature\Billing;

use App\Enums\CustomerStatus;
use App\Models\Contract;
use App\Services\Billing\DepositService;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesBilling;
use Tests\TestCase;

/** Stripe accounts without bank payments (ACH) switched on still take card deposits. */
class CardOnlyFallbackTest extends TestCase
{
    use FakesBilling, RefreshDatabase;

    public function test_deposit_falls_back_to_card_only_when_bank_payments_are_not_enabled(): void
    {
        Storage::fake('local');
        $this->seed([RoleSeeder::class, ContractTemplateSeeder::class, EmailTemplateSeeder::class]);
        $this->stripe['POST customers'] = ['id' => 'cus_1'];
        $this->stripe['POST payment_intents'] = fn (Request $r) => in_array('us_bank_account', (array) $r['payment_method_types'], true)
            ? ['__status' => 400, 'json' => ['error' => ['type' => 'invalid_request_error', 'message' => 'The payment method type "us_bank_account" is invalid. Please ensure the provided type is activated in your dashboard.']]]
            : ['id' => 'pi_card', 'status' => 'requires_payment_method', 'client_secret' => 'pi_card_secret', 'amount' => 30000];
        $this->fakeBilling();

        $customer = $this->billedCustomer(CustomerStatus::ContractSigned);
        $result = app(DepositService::class)->prepare($customer, Contract::firstOrFail());

        $this->assertSame('pi_card_secret', $result['client_secret']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/payment_intents') && $r['payment_method_types'] === ['card']);
    }
}
