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

    /** A stored calendar date (e.g. go_live_date, ends_on) as midnight in the business time zone. */
    public static function date(\DateTimeInterface|string $date): Carbon
    {
        $day = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10);

        return Carbon::parse($day, self::timezone())->startOfDay();
    }
}
