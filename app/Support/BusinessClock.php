<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * "Today" and dates as seen from RightAlly's business time zone (Miami).
 * Use this for anything date-based in billing: go-live, coupon expiry,
 * monthly charges, renewals.
 */
final class BusinessClock
{
    public static function timezone(): string
    {
        return (string) config('rightally.business_timezone', 'America/New_York');
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }
}
