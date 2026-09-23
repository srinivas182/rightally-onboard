<?php

namespace App\Support;

/**
 * Money is stored and calculated in integer cents to avoid rounding errors.
 */
final class Money
{
    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, 2);
    }

    public static function toCents(string|float|int $dollars): int
    {
        return (int) round(((float) $dollars) * 100);
    }

    public static function toDollars(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** Percentage of an amount, rounded to the nearest cent. */
    public static function percentOf(int $cents, float|string $percent): int
    {
        return (int) round($cents * ((float) $percent) / 100);
    }
}
