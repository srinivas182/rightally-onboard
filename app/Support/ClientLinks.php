<?php

namespace App\Support;

use App\Services\Settings\SettingsService;

/** How long emailed and shared client links stay valid (Settings > Security; default 90 days). */
final class ClientLinks
{
    public const DEFAULT_DAYS = 90;

    public static function days(): int
    {
        $days = (int) app(SettingsService::class)->get('security', 'client_link_days');

        return $days >= 1 && $days <= 365 ? $days : self::DEFAULT_DAYS;
    }
}
