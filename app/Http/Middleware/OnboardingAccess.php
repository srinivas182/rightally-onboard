<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A client can only open their own onboarding pages: either from the browser
 * session that started it, or through a signed link we emailed them (which
 * then adds the customer to that browser's session).
 */
class OnboardingAccess
{
    public const SESSION_KEY = 'onboarding.customers';

    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->route('customer');
        $uuid = $customer instanceof Customer ? $customer->uuid : (string) $customer;
        $allowed = (array) $request->session()->get(self::SESSION_KEY, []);

        if (! in_array($uuid, $allowed, true)) {
            if (! $request->hasValidSignature()) {
                abort(403, __('This onboarding link has expired. Open the latest link from your email, or start again.'));
            }
            self::remember($request, $uuid);
        }

        return $next($request);
    }

    public static function remember(Request $request, string $uuid): void
    {
        $list = array_values(array_unique(array_merge((array) $request->session()->get(self::SESSION_KEY, []), [$uuid])));
        $request->session()->put(self::SESSION_KEY, array_slice($list, -10));
    }
}
