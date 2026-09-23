<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Services\Stripe\StripeClient;

/**
 * Saving a customer's card or bank account as the one every future charge
 * uses: our record (label, card expiry) plus Stripe's defaults on the
 * customer and the subscription.
 */
final class PaymentMethods
{
    public function __construct(private readonly StripeClient $stripe) {}

    /** @return array{0: string, 1: string} [card|us_bank_account, "Visa ending 4242"] */
    public function describe(?array $pm, string $fallbackType = 'card'): array
    {
        if (! $pm) {
            return [$fallbackType, 'your saved payment method'];
        }
        if (($pm['type'] ?? '') === 'us_bank_account') {
            $b = $pm['us_bank_account'] ?? [];

            return ['us_bank_account', trim(($b['bank_name'] ?? 'Bank account').' ending '.($b['last4'] ?? ''))];
        }
        $c = $pm['card'] ?? [];

        return ['card', ucfirst((string) ($c['brand'] ?? 'Card')).' ending '.($c['last4'] ?? '')];
    }

    /** @param array<string, mixed> $pm Stripe PaymentMethod */
    public function makeDefault(Customer $customer, array $pm): void
    {
        [$type, $label] = $this->describe($pm);
        $card = $type === 'card' ? ($pm['card'] ?? []) : [];

        $customer->forceFill([
            'stripe_payment_method_id' => $pm['id'],
            'payment_method_type' => $type,
            'payment_method_label' => $label,
            'card_exp_month' => $card['exp_month'] ?? null,
            'card_exp_year' => $card['exp_year'] ?? null,
            'card_expiry_warned_for' => null,
        ])->save();

        if ($customer->stripe_customer_id) {
            $this->stripe->post('customers/'.$customer->stripe_customer_id, ['invoice_settings' => ['default_payment_method' => $pm['id']]]);
        }
        if ($customer->stripe_subscription_id) {
            $this->stripe->post('subscriptions/'.$customer->stripe_subscription_id, ['default_payment_method' => $pm['id']]);
        }
    }
}
