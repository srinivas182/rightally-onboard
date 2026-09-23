<?php

namespace App\Services\Billing;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Pause;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Services\Stripe\StripeClient;
use App\Support\BusinessClock;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Pausing a subscription: up to 3 months, once per contract year. No monthly
 * charges during the pause (Stripe voids them) and the minimum term is
 * extended by the pause length.
 */
final class PauseService
{
    public const MAX_MONTHS = 3;

    public function __construct(private readonly StripeClient $stripe, private readonly EmailSender $email, private readonly AuditLogger $audit) {}

    public function canPause(Customer $customer): ?string
    {
        if ($customer->status !== CustomerStatus::Live) {
            return 'Only live customers in good standing can be paused.';
        }
        $recent = Pause::where('customer_id', $customer->id)->where('starts_on', '>', BusinessClock::today()->subYear()->toDateString())->exists();

        return $recent ? 'This customer already had a pause in the last 12 months.' : null;
    }

    public function pause(Customer $customer, int $months, string $reason, Admin $admin): Pause
    {
        if ($problem = $this->canPause($customer)) {
            throw ValidationException::withMessages(['months' => $problem]);
        }
        if ($months < 1 || $months > self::MAX_MONTHS) {
            throw ValidationException::withMessages(['months' => 'Choose 1 to '.self::MAX_MONTHS.' months.']);
        }
        $contract = $customer->contracts()->where('status', ContractStatus::Signed)->latest('signed_at')->firstOrFail();
        $start = BusinessClock::today();
        $end = $start->copy()->addMonthsNoOverflow($months);

        if ($customer->stripe_subscription_id) {
            $this->stripe->post('subscriptions/'.$customer->stripe_subscription_id, ['pause_collection' => [
                'behavior' => 'void',
                'resumes_at' => Carbon::parse($end->toDateString().' 09:00', BusinessClock::timezone())->getTimestamp(),
            ]]);
        }

        $pause = Pause::create(['customer_id' => $customer->id, 'contract_id' => $contract->id, 'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(), 'months' => $months, 'reason' => $reason, 'created_by' => $admin->id]);
        $contract->update([
            'paused_months' => $contract->paused_months + $months,
            'ends_on' => BusinessClock::date($contract->ends_on)->addMonthsNoOverflow($months)->toDateString(),
        ]);
        $customer->update(['status' => CustomerStatus::Paused, 'paused_until' => $end->toDateString()]);
        $this->audit->log('customer.paused', "Paused {$customer->company_name} for {$months} ".str('month')->plural($months).": {$reason}", $customer, ['until' => $end->toDateString()]);
        $this->email->toCustomer('subscription_paused', $customer->fresh(), null, [], [
            'pause_until' => $end->format('F j, Y'), 'term_end_date' => BusinessClock::date($contract->fresh()->ends_on)->format('F j, Y'),
        ]);

        return $pause;
    }

    public function resume(Customer $customer, ?Admin $admin = null): void
    {
        if ($customer->status !== CustomerStatus::Paused) {
            return;
        }
        if ($customer->stripe_subscription_id && $admin) {
            $this->stripe->post('subscriptions/'.$customer->stripe_subscription_id, ['pause_collection' => '']); // early resume
        }
        Pause::where('customer_id', $customer->id)->whereNull('resumed_at')->update(['resumed_at' => now()]);
        $customer->update(['status' => CustomerStatus::Live, 'paused_until' => null]);
        $this->audit->log('customer.resumed', "Resumed {$customer->company_name}".($admin ? ' early' : ''), $customer, null, $admin ? 'admin' : 'system');
        $this->email->toCustomer('subscription_resumed', $customer->fresh());
    }

    /** Daily: pauses whose end date has arrived (Stripe resumes billing itself). */
    public function resumeDue(): int
    {
        $due = Customer::where('status', CustomerStatus::Paused)->whereDate('paused_until', '<=', BusinessClock::today()->toDateString())->get();
        $due->each(fn (Customer $c) => $this->resume($c));

        return $due->count();
    }
}
