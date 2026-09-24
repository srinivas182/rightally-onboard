<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client account pages: the signed-in client (their own account only), or a
 * signed-in admin with the Customers menu (read-only view). Everyone else signs in first.
 */
class ClientAccountAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->route('customer');
        $admin = auth('admin')->user();
        if ($admin && $admin->can('menu.customers')) {
            $request->attributes->set('viewing_as_admin', true);

            return $next($request);
        }

        $client = auth('customer')->user();
        if ($client && $customer instanceof Customer && $client->is($customer)) {
            return $next($request);
        }
        if ($client) {
            abort(403, __('You’re signed in as :email, which doesn’t have access to this account. Sign out and sign in with the right email.', ['email' => $client->email]));
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('account.login');
    }
}
