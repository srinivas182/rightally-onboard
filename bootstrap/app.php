<?php

use App\Http\Middleware\OnboardingAccess;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->prefix('admin')->name('admin.')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SecurityHeaders::class]);
        // Stripe calls this directly; it is verified by its signature instead.
        $middleware->preventRequestForgery(except: ['stripe/webhook']);
        $middleware->alias(['admin.2fa' => RequireTwoFactor::class, 'onboarding.access' => OnboardingAccess::class]);
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));
        // Only trust forwarding headers from our own reverse proxy. Trusting
        // every proxy would let a visitor fake the IP recorded on signed
        // agreements and in the audit log. Set TRUSTED_PROXIES on the server
        // (e.g. Cloudflare ranges) if a CDN sits in front of Nginx.
        $middleware->trustProxies(at: array_filter(explode(',', (string) (getenv('TRUSTED_PROXIES') ?: '127.0.0.1,::1'))));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
