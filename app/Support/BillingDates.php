<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\Customer;
use Illuminate\Support\Carbon;

/** Subscription charge dates: first at go-live + 30 days, then monthly or yearly. */
final class BillingDates
{
    public static function firstCharge(Customer $customer): ?Carbon
    {
        return $customer->go_live_date ? BusinessClock::date($customer->go_live_date)->addDays(30) : null;
    }

    /** Next subscription charge on or after $from (default today). */
    public static function nextCharge(Customer $customer, ?Contract $contract, ?Carbon $from = null): ?Carbon
    {
        $next = self::firstCharge($customer);
        if (! $next) {
            return null;
        }
        $from ??= BusinessClock::today();
        $yearly = $contract?->isAnnual() ?? false;
        while ($next->lt($from)) {
            $yearly ? $next->addYearNoOverflow() : $next->addMonthNoOverflow();
        }

        return $next;
    }
}
