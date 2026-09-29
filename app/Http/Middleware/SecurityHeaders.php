<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for every response, including a Content Security Policy.
 * Stripe and Turnstile origins are added in the sprints that use them.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        $dev = app()->isLocal() ? ' http://localhost:5173 ws://localhost:5173 http://127.0.0.1:5173 ws://127.0.0.1:5173' : '';

        $csp = implode('; ', [
            "default-src 'self'",
            // Cloudflare Turnstile on the onboarding form.
            "script-src 'self' 'nonce-{$nonce}' https://challenges.cloudflare.com https://js.stripe.com https://link.msgsndr.com{$dev}",
            'frame-src https://challenges.cloudflare.com https://js.stripe.com https://hooks.stripe.com https://*.stripe.com https://api.leadconnectorhq.com https://*.leadconnectorhq.com https://link.msgsndr.com', // GoHighLevel booking calendar
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com{$dev}",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob: https://*.stripe.com",
            "connect-src 'self' https://api.stripe.com https://*.stripe.com{$dev}",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
