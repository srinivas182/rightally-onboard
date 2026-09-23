<?php

namespace Database\Seeders;

use App\Enums\AgentCountSource;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AgentCountLog;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\BusinessClock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Sample customers, agreements and invoices for trying the admin on a
 * staging server. Refuses to run in production.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }
        $this->call(ContractTemplateSeeder::class);
        $today = BusinessClock::today();

        $rows = [
            ['Sunline Realty Group', 'Maria', 'Alvarez', 'Miami', 'FL', CustomerStatus::AwaitingGoLive, 8, $today->copy()->addDays(5), 'NAR2026 link'],
            ['Harbor Point Realty', 'James', 'Okafor', 'Tampa', 'FL', CustomerStatus::AwaitingGoLive, 12, $today->copy()->addDays(2), 'facebook fall'],
            ['Keystone Homes', 'Dana', 'Pruitt', 'Austin', 'TX', CustomerStatus::PaymentFailed, 31, $today->copy()->subMonths(4), 'Direct'],
            ['Coastal Keys Brokerage', 'Liam', 'Chen', 'San Diego', 'CA', CustomerStatus::Live, 48, $today->copy()->subMonths(11), 'Referral'],
            ['Mesa Verde Properties', 'Ana', 'Ruiz', 'Phoenix', 'AZ', CustomerStatus::Suspended, 9, $today->copy()->subMonths(6), 'facebook spring'],
            ['Pine Hollow Realty', 'Owen', 'Grant', 'Denver', 'CO', CustomerStatus::ContractSigned, 6, $today->copy()->addDays(29), 'Direct'],
        ];

        $template = ContractTemplate::where('type', ContractType::Initial)->where('is_active', true)->firstOrFail();
        foreach ($rows as $n => [$company, $first, $last, $city, $state, $status, $agents, $goLive, $source]) {
            if (Customer::where('company_name', $company)->exists()) {
                continue;
            }
            $live = in_array($status, [CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::Suspended], true);
            $customer = new Customer([
                'status' => $status, 'first_name' => $first, 'last_name' => $last, 'title' => 'Broker/Owner', 'company_name' => $company,
                'email' => strtolower($first).'@'.str_replace(' ', '', strtolower($company)).'.com', 'phone_e164' => '+1305555'.str_pad((string) (100 + $n), 4, '0', STR_PAD_LEFT),
                'street' => (100 + $n * 7).' Main Street', 'city' => $city, 'state_code' => $state, 'zip' => '33131',
                'agent_count' => $agents, 'agent_count_entered' => $agents, 'go_live_date' => $goLive->toDateString(),
                'live_at' => $live ? $goLive->copy()->setTime(9, 0) : null, 'source' => $source, 'onboarding_started_at' => $goLive->copy()->subDays(30),
                'payment_method_type' => 'card', 'payment_method_label' => ['Visa ending 4242', 'Mastercard ending 4444', 'Chase ending 6789'][$n % 3],
                'suspended_at' => $status === CustomerStatus::Suspended ? now()->subDays(2) : null,
            ]);
            $customer->save();

            $contract = Contract::create([
                'number' => sprintf('RA-%s-%04d', $goLive->copy()->subDays(30)->format('Y'), 900 + $n), 'customer_id' => $customer->id, 'contract_template_id' => $template->id,
                'type' => ContractType::Initial, 'status' => ContractStatus::Signed, 'signed_at' => $goLive->copy()->subDays(30),
                'setup_fee_cents' => 300000, 'discount_percent' => 0, 'discount_cents' => 0, 'implementation_fee_cents' => 300000,
                'deposit_percent' => 10, 'deposit_cents' => 30000, 'balance_cents' => 270000, 'platform_fee_cents' => 50000, 'per_agent_fee_cents' => 2000,
                'min_agents' => 5, 'agent_count' => $agents, 'term_months' => 12, 'starts_on' => $goLive->toDateString(), 'ends_on' => $goLive->copy()->addYear()->subDay()->toDateString(),
                'company_legal_name' => 'Mayura Consultancy Services LLC', 'company_dba' => 'RightAlly', 'company_address' => '66 West Flagler Street, Suite 900, Miami, FL 33130',
                'company_signatory_name' => 'Srini', 'company_signatory_title' => 'Co-Founder', 'client_typed_name' => "{$first} {$last}", 'client_title' => 'Broker/Owner',
                'rendered_html' => '<p>Sample agreement for staging.</p>', 'signer_ip' => '203.0.113.'.(10 + $n), 'esign_consent_at' => $goLive->copy()->subDays(30),
            ]);
            AgentCountLog::create(['customer_id' => $customer->id, 'new_count' => $agents, 'source' => AgentCountSource::Onboarding]);

            if ($status === CustomerStatus::ContractSigned) {
                continue;
            }
            $this->invoice($customer, $contract, InvoiceType::Deposit, 30000, InvoiceStatus::Paid, $goLive->copy()->subDays(30));
            if (! $live) {
                continue;
            }
            $this->invoice($customer, $contract, InvoiceType::Balance, 270000, InvoiceStatus::Paid, $goLive->copy());
            $months = (int) $goLive->diffInMonths($today);
            for ($i = 1; $i < $months; $i++) {
                $date = $goLive->copy()->addDays(30)->addMonthsNoOverflow($i - 1);
                $failed = $i === $months - 1 && in_array($status, [CustomerStatus::PaymentFailed, CustomerStatus::Suspended], true);
                $this->invoice($customer, $contract, InvoiceType::Monthly, 50000 + $agents * 2000, $failed ? InvoiceStatus::Failed : InvoiceStatus::Paid, $date, $agents,
                    $status === CustomerStatus::Suspended ? now()->subDays(32) : now()->subDays(6));
            }
        }
    }

    private function invoice(Customer $c, Contract $k, InvoiceType $type, int $cents, InvoiceStatus $status, Carbon $date, ?int $agents = null, ?Carbon $failedAt = null): void
    {
        $inv = Invoice::create([
            'number' => 'TMP-'.bin2hex(random_bytes(5)), 'customer_id' => $c->id, 'contract_id' => $k->id, 'type' => $type, 'status' => $status,
            'amount_cents' => $cents, 'agents_billed' => $agents, 'due_on' => $date->toDateString(),
            'period_start' => $type === InvoiceType::Monthly ? $date->toDateString() : null, 'period_end' => $type === InvoiceType::Monthly ? $date->copy()->addMonth()->toDateString() : null,
            'paid_at' => $status === InvoiceStatus::Paid ? $date : null, 'failed_at' => $status === InvoiceStatus::Failed ? $failedAt : null,
            'failure_reason' => $status === InvoiceStatus::Failed ? 'Your card was declined.' : null,
            'hosted_invoice_url' => $status === InvoiceStatus::Failed ? 'https://invoice.stripe.com/i/demo' : null,
        ]);
        $inv->update(['number' => sprintf('INV-%s-%04d', $date->format('Y'), $inv->id)]);
        if ($status === InvoiceStatus::Paid) {
            Payment::create(['invoice_id' => $inv->id, 'customer_id' => $c->id, 'stripe_charge_id' => 'demo_'.$inv->id, 'method' => 'card',
                'method_label' => $c->payment_method_label, 'amount_cents' => $cents, 'fee_cents' => (int) round($cents * 0.029 + 30), 'status' => 'succeeded', 'settled_at' => $date]);
        }
    }
}
