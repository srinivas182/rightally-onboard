<?php

namespace App\Services\Billing;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Services\Stripe\StripeClient;
use App\Support\BusinessClock;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Ending a customer's service, four ways:
 * - term_end:   bill until the agreement's term ends, then stop (no renewal)
 * - period_end: stop at the end of the current (already paid) billing period
 * - now_fee:    stop now and charge the rest of the term (early termination; second-admin approval)
 * - now_no_fee: stop now without a fee (second-admin approval)
 * Stripe is told first, so no further charge can happen.
 */
final class ServiceEnding
{
    public const TYPES = [
        'term_end' => 'At the end of the current term',
        'period_end' => 'At the end of the current billing period',
        'now_fee' => 'Now, with early termination fee',
        'now_no_fee' => 'Now, no fee',
    ];

    public const REASONS = [
        'price' => 'Price', 'not_using' => 'Not using it enough', 'switched' => 'Switched to another provider',
        'closed' => 'Closed or sold the brokerage', 'features' => 'Missing features', 'service' => 'Service or support', 'other' => 'Other',
    ];

    public function __construct(private readonly StripeClient $stripe, private readonly EmailSender $email, private readonly AuditLogger $audit) {}

    /** Schedules an end at the term or period end. The "now" types go through approvals instead. */
    public function schedule(Customer $customer, string $type, string $reason, ?string $notes, Admin $admin): Carbon
    {
        $contract = $customer->contracts()->where('status', ContractStatus::Signed)->latest('signed_at')->first();
        if ($type === 'term_end' && ! $contract?->ends_on) {
            throw ValidationException::withMessages(['type' => 'This customer is month to month (no term end). Choose “end of the current billing period”.']);
        }

        $endsAt = null;
        if ($customer->stripe_subscription_id) {
            if ($type === 'term_end') {
                $endsAt = Carbon::parse(BusinessClock::date($contract->ends_on)->toDateString().' 23:59', BusinessClock::timezone());
                $this->stripe->post('subscriptions/'.$customer->stripe_subscription_id, ['cancel_at' => $endsAt->getTimestamp(), 'proration_behavior' => 'none']);
            } else {
                $sub = $this->stripe->post('subscriptions/'.$customer->stripe_subscription_id, ['cancel_at_period_end' => 'true']);
                $endsAt = isset($sub['current_period_end']) ? Carbon::createFromTimestamp($sub['current_period_end']) : null;
            }
        }
        $endsOn = ($endsAt ?? ($type === 'term_end' ? BusinessClock::date($contract->ends_on) : BusinessClock::today()))->setTimezone(BusinessClock::timezone());

        $customer->update(['end_type' => $type, 'end_reason' => $reason, 'end_notes' => $notes, 'service_ends_on' => $endsOn->toDateString(), 'end_requested_at' => now()]);
        $this->audit->log('customer.end_scheduled', "Service for {$customer->company_name} set to end on {$endsOn->format('M j, Y')} (".self::TYPES[$type].')', $customer, ['reason' => $reason, 'notes' => $notes]);
        $this->email->toCustomer('service_ending', $customer->fresh(), null, [], [
            'service_end_date' => $endsOn->format('F j, Y'),
            'end_charges' => $type === 'term_end' ? 'Your subscription continues to be charged as usual until then.' : 'Your current billing period is already paid; no further charges will be made.',
        ]);

        return $endsOn;
    }

    /** Undo a scheduled end: the subscription continues as normal. */
    public function undo(Customer $customer): void
    {
        if ($customer->stripe_subscription_id) {
            $this->stripe->post('subscriptions/'.$customer->stripe_subscription_id, ['cancel_at_period_end' => 'false', 'cancel_at' => '']);
        }
        $customer->update(['end_type' => null, 'end_reason' => null, 'end_notes' => null, 'service_ends_on' => null, 'end_requested_at' => null]);
        $this->audit->log('customer.end_undone', "Scheduled end cancelled for {$customer->company_name}; service continues", $customer);
    }

    /** Ends service immediately without a fee (after second-admin approval). */
    public function endNow(Customer $customer, string $reason, ?string $notes): void
    {
        if ($customer->stripe_subscription_id) {
            $this->stripe->delete('subscriptions/'.$customer->stripe_subscription_id);
        }
        $customer->update(['end_type' => 'now_no_fee', 'end_reason' => $reason, 'end_notes' => $notes, 'service_ends_on' => BusinessClock::today()->toDateString(), 'end_requested_at' => now()]);
        $this->finalize($customer->fresh());
    }

    /** Marks the service ended (Stripe says the subscription is gone, or the end date passed). */
    public function finalize(Customer $customer): void
    {
        if (in_array($customer->status, [CustomerStatus::Cancelled, CustomerStatus::Expired], true)) {
            return;
        }
        $contract = $customer->contracts()->where('status', ContractStatus::Signed)->latest('signed_at')->first();
        $contract?->update(['status' => $customer->end_type === 'term_end' ? ContractStatus::Expired : ContractStatus::Terminated]);
        $customer->update(['status' => $customer->end_type === 'term_end' ? CustomerStatus::Expired : CustomerStatus::Cancelled, 'cancelled_at' => now()]);
        $this->audit->log('customer.service_ended', "Service ended for {$customer->company_name}", $customer, ['type' => $customer->end_type], 'system');
        $this->email->toCustomer('service_ended', $customer->fresh());
    }

    /** Daily safety net: scheduled ends whose date has passed (in case the Stripe notice was missed). */
    public function finalizeDue(): int
    {
        $due = Customer::whereNotNull('end_type')->whereIn('end_type', ['term_end', 'period_end'])
            ->whereDate('service_ends_on', '<', BusinessClock::today()->toDateString())
            ->whereNotIn('status', [CustomerStatus::Cancelled, CustomerStatus::Expired])->get();
        $due->each(function (Customer $c) {
            if ($c->stripe_subscription_id) {
                try {
                    $this->stripe->delete('subscriptions/'.$c->stripe_subscription_id);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            $this->finalize($c);
        });

        return $due->count();
    }

    /** Money received from a customer over their whole time with us (net of refunds and sales tax). */
    public static function revenueCents(Customer $customer): int
    {
        return (int) Payment::query()->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('payments.customer_id', $customer->id)->whereIn('payments.status', ['succeeded', 'refunded'])
            ->selectRaw('COALESCE(SUM(payments.amount_cents - payments.refunded_cents - invoices.tax_cents), 0) as net')->value('net');
    }

    public static function summary(Customer $c): string
    {
        return Money::format(self::revenueCents($c));
    }
}
