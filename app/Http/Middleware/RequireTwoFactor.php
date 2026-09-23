<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every admin must have two-factor authentication set up before using the
 * back office. Admins who already have it pass the challenge at sign-in,
 * so a signed-in admin with 2FA enabled has always passed it.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin && ! $admin->is_active) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();

            return redirect()->route('admin.login')->withErrors(['email' => 'Your account has been deactivated.']);
        }

        if ($admin && ! $admin->hasTwoFactorEnabled()) {
            return redirect()->route('admin.two-factor.setup');
        }

        return $next($request);
    }
}
