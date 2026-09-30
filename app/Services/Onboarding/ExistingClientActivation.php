<?php

namespace App\Services\Onboarding;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\CustomerStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Quote;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\DepositService;
use App\Services\Billing\PaymentMethods;
use App\Services\Billing\SubscriptionService;
use App\Services\Email\EmailSender;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\BusinessClock;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Existing clients (already billed in Stripe before this app): after signing, they confirm the
 * payment method already on file (or add one). Nothing is charged today; the new subscription
 * starts on the first charge date and the old Stripe subscription stops renewing, so there is
 * no double charge.
 */
final class ExistingClientActivation
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly DepositService $deposits,
        private readonly PaymentMethods $methods,
        private readonly SubscriptionService $subscriptions,
        private readonly EmailSender $email,
        private readonly AuditLogger $audit,
    ) {}

    /** Links the client's existing Stripe customer (from the quote) and returns the card or bank account on file, if any. */
    public function savedMethod(Customer $customer): ?array
    {
        $quote = $customer->quote_id ? Quote::find($customer->quote_id) : null;
        if (! $customer->stripe_customer_id && $quote?->stripe_customer_id) {
            $customer->forceFill(['stripe_customer_id' => $quote->stripe_customer_id])->save();
        }
        if (! $customer->stripe_customer_id) {
            $this->deposits->ensureStripeCustomer($customer);

            return null;
        }
        try {
            $c = $this->stripe->get('customers/'.$customer->stripe_customer_id, ['expand' => ['invoice_settings.default_payment_method']]);
            $pm = $c['invoice_settings']['default_payment_method'] ?? null;
            if (! is_array($pm)) {
                $list = $this->stripe->get('payment_methods', ['customer' => $customer->stripe_customer_id, 'limit' => 1]);
                $pm = $list['data'][0] ?? null;
            }

            return is_array($pm) ? $pm : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function setupIntent(Customer $customer): string
    {
        $this->deposits->ensureStripeCustomer($customer);
        $params = ['customer' => $customer->stripe_customer_id, 'usage' => 'off_session', 'payment_method_types' => ['card', 'us_bank_account'],
            'payment_method_options' => ['us_bank_account' => ['verification_method' => 'automatic']], 'metadata' => ['customer_uuid' => $customer->uuid, 'type' => 'existing_client']];
        try {
            $si = $this->stripe->post('setup_intents', $params);
        } catch (StripeException $e) {
            if (! str_contains($e->getMessage(), 'us_bank_account')) {
                throw $e;
            }
            unset($params['payment_method_options']);
            $si = $this->stripe->post('setup_intents', ['payment_method_types' => ['card']] + $params);
        }

        return (string) $si['client_secret'];
    }

    public function activate(Customer $customer, Contract $contract, array $paymentMethod): void
    {
        if ($customer->status === CustomerStatus::Live) {
            return;
        }
        $this->methods->makeDefault($customer, $paymentMethod);
        $quote = $customer->quote_id ? Quote::find($customer->quote_id) : null;
        $oldSub = $quote?->old_stripe_subscription_id;

        // Never charge twice: if the old subscription's paid period runs past the first charge date, start after it.
        if ($oldSub) {
            try {
                $old = $this->stripe->get('subscriptions/'.$oldSub);
                $oldEnd = isset($old['current_period_end']) ? Carbon::createFromTimestamp($old['current_period_end']) : null;
                if ($oldEnd && $contract->first_charge_on && $oldEnd->gt(Carbon::parse($contract->first_charge_on->toDateString().' 09:00', BusinessClock::timezone()))) {
                    $contract->update(['first_charge_on' => $oldEnd->setTimezone(BusinessClock::timezone())->toDateString()]);
                }
                if (($old['status'] ?? '') !== 'canceled') {
                    $this->stripe->post('subscriptions/'.$oldSub, ['cancel_at_period_end' => 'true', 'metadata' => ['replaced_by' => 'rightally-onboard']]);
                    $this->audit->log('customer.old_subscription_stopped', "Old Stripe subscription {$oldSub} for {$customer->company_name} set to stop at the end of its paid period", $customer, null, 'system');
                }
            } catch (Throwable $e) {
                report($e);
                // Tell the team so the old subscription is stopped by hand; the new one still starts.
                $this->email->toTeam('team_alert', [
                    'alert_title' => "Check old Stripe subscription: {$customer->company_name}",
                    'alert_text' => "{$customer->company_name} moved to their new agreement, but the old subscription {$oldSub} couldn’t be set to stop automatically ({$e->getMessage()}). In Stripe, open it and choose Cancel subscription > At end of current period, so they aren’t charged twice.",
                    'alert_link' => route('admin.customers.show', $customer),
                ]);
                $this->audit->log('customer.old_subscription_not_stopped', "Old Stripe subscription {$oldSub} for {$customer->company_name} could not be stopped automatically", $customer, ['error' => $e->getMessage()], 'system');
            }
        }

        $customer->update(['status' => CustomerStatus::Live, 'live_at' => now(), 'go_live_date' => BusinessClock::today()->toDateString(), 'old_stripe_subscription_id' => $oldSub]);
        $this->subscriptions->start($customer->fresh(), $contract->fresh());

        $this->audit->log('customer.existing_activated', "{$customer->company_name} moved to a signed agreement; billing starts ".$contract->fresh()->first_charge_on?->format('M j, Y'), $customer, ['old_subscription' => $oldSub], 'client');
        $pdf = $contract->pdf_path ? [['path' => $contract->pdf_path, 'name' => "RightAlly-Agreement-{$contract->number}.pdf"]] : [];
        $this->email->toCustomer('existing_client_welcome', $customer->fresh(), null, $pdf, [
            'first_charge_date' => $contract->fresh()->first_charge_on?->format('F j, Y') ?? '',
        ]);
    }

    /** Label for a Stripe payment method ("Visa ending 4242"). */
    public function label(array $pm): string
    {
        return $this->methods->describe($pm)[1];
    }

    public static function isExisting(?Contract $contract): bool
    {
        return $contract?->type === ContractType::Existing && $contract->status === ContractStatus::Signed;
    }
}
