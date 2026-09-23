<?php

namespace App\Support;

/**
 * US phone numbers: accept what people type, store E.164 (+1XXXXXXXXXX).
 */
final class UsPhone
{
    /** Returns +1XXXXXXXXXX, or null if the input isn't a valid 10-digit US number. */
    public static function toE164(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        // Area code and exchange can't start with 0 or 1 (NANP).
        if (! preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits)) {
            return null;
        }

        return '+1'.$digits;
    }

    /** +13055550148 -> (305) 555-0148 */
    public static function format(?string $e164): string
    {
        $d = substr(preg_replace('/\D+/', '', (string) $e164), -10);

        return strlen($d) === 10 ? sprintf('(%s) %s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6)) : (string) $e164;
    }
}
