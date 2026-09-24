<?php

namespace App\Services\Auth;

use App\Models\Admin;
use App\Services\Email\EmailSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One-time sign-in codes by email: used when an admin has no authenticator
 * app set up, or chooses email instead. 6 digits, valid 10 minutes, 5 tries,
 * at most one email a minute and 6 an hour.
 */
final class EmailLoginCode
{
    public const MINUTES = 10;

    public function __construct(private readonly EmailSender $email) {}

    /** @return string|null error message when the send is refused */
    public function send(Admin $admin): ?string
    {
        $minute = "admin-otp-min:{$admin->id}";
        $hour = "admin-otp-hour:{$admin->id}";
        if (RateLimiter::tooManyAttempts($minute, 1)) {
            return 'A code was just sent. Check your inbox, or wait '.RateLimiter::availableIn($minute).' seconds to send another.';
        }
        if (RateLimiter::tooManyAttempts($hour, 6)) {
            return 'Too many codes requested. Try again later, or use your authenticator app.';
        }
        RateLimiter::hit($minute, 60);
        RateLimiter::hit($hour, 3600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->key($admin), ['hash' => Hash::make($code), 'tries' => 0], now()->addMinutes(self::MINUTES));
        $this->email->toAdmin('admin_login_code', $admin, ['login_code' => $code, 'code_minutes' => (string) self::MINUTES], now: true);

        return null;
    }

    public function verify(Admin $admin, string $code): bool
    {
        $entry = Cache::get($this->key($admin));
        if (! $entry || $entry['tries'] >= 5) {
            return false;
        }
        if (Hash::check(preg_replace('/\D/', '', $code), $entry['hash'])) {
            Cache::forget($this->key($admin));

            return true;
        }
        $entry['tries']++;
        Cache::put($this->key($admin), $entry, now()->addMinutes(self::MINUTES));

        return false;
    }

    private function key(Admin $admin): string
    {
        return "admin-otp:{$admin->id}";
    }
}
