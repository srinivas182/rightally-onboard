<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeClient;

/**
 * Sales tax through Stripe Tax, when switched on in Settings > Tax.
 * Fees are tax-exclusive (agreement Section 2e): tax is added on top.
 * Tax code txcd_10103001 = SaaS, business use.
 */
final class TaxService
{
    public const TAX_CODE = 'txcd_10103001';

    public function __construct(private readonly SettingsService $settings, private readonly StripeClient $stripe) {}

    public function enabled(): bool
    {
        return (string) $this->settings->get('tax', 'enabled') === '1';
    }

    /** Parameters to add to Stripe invoices and subscriptions. */
    public function automaticTax(): array
    {
        return $this->enabled() ? ['automatic_tax' => ['enabled' => 'true']] : [];
    }

    /**
     * Tax on a one-off amount (the deposit), calculated from the customer's address.
     *
     * @return array{tax_cents: int, calculation_id: ?string}
     */
    public function calculate(Customer $customer, int $amountCents, string $reference): array
    {
        if (! $this->enabled()) {
            return ['tax_cents' => 0, 'calculation_id' => null];
        }

        $calc = $this->stripe->post('tax/calculations', [
            'currency' => 'usd',
            'line_items' => [['amount' => $amountCents, 'reference' => $reference, 'tax_behavior' => 'exclusive', 'tax_code' => self::TAX_CODE]],
            'customer_details' => [
                'address' => ['line1' => $customer->street, 'city' => $customer->city, 'state' => $customer->state_code, 'postal_code' => $customer->zip, 'country' => 'US'],
                'address_source' => 'billing',
            ],
        ]);

        return ['tax_cents' => (int) ($calc['tax_amount_exclusive'] ?? 0), 'calculation_id' => $calc['id'] ?? null];
    }

    /** Records the tax as collected once the deposit is paid (needed for Stripe Tax reports and filing). */
    public function record(?string $calculationId, string $reference): void
    {
        if ($calculationId) {
            $this->stripe->post('tax/transactions/create_from_calculation', ['calculation' => $calculationId, 'reference' => $reference], "taxtx-{$reference}");
        }
    }
}
