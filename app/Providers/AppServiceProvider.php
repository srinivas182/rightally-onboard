<?php

namespace App\Providers;

use App\Models\Admin;
use App\Models\Customer;
use App\Observers\CustomerObserver;
use App\Services\Settings\SettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();
        Customer::observe(CustomerObserver::class);

        // Password reset emails point at the admin reset screen.
        ResetPassword::createUrlUsing(fn (Admin $admin, string $token) => route('admin.password.reset', [
            'token' => $token,
            'email' => $admin->email,
        ]));

        // Strong passwords for admins in production.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(8));

        // One gate per admin menu. Super admins pass every gate.
        Gate::before(fn (Admin $admin) => $admin->isSuperAdmin() ? true : null);
        foreach (config('rightally.menus') as $menu => $cfg) {
            // Some menus share another menu's permission (e.g. Former customers uses Customers).
            $perm = $cfg['permission'] ?? $menu;
            Gate::define("menu.{$menu}", fn (Admin $admin) => $admin->hasMenu($perm));
        }

        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
        // Keyed by the admin being verified, not the session: starting a new
        // session must not reset the count of wrong codes for that admin.
        RateLimiter::for('admin-2fa', function (Request $request) {
            $key = '2fa|'.($request->session()->get('admin.2fa.id') ?? $request->user('admin')?->id ?? $request->ip());

            return [Limit::perMinute(5)->by($key), Limit::perHour(20)->by($key)];
        });

        RateLimiter::for('onboarding', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('agent-api', fn (Request $request) => Limit::perMinute(30)->by(substr((string) $request->bearerToken(), 0, 16) ?: $request->ip()));
        RateLimiter::for('coupon-check', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        View::composer('layouts.admin', function ($view) {
            $admin = auth('admin')->user();
            $menus = collect(config('rightally.menus'))
                ->filter(fn ($m, $key) => $admin?->can("menu.{$key}"));
            $view->with('navMenus', $menus);
        });
    }
}
