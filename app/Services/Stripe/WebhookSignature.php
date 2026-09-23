<?php

namespace App\Services\Stripe;

/**
 * Verifies the Stripe-Signature header (HMAC-SHA256 of "timestamp.payload").
 * https://docs.stripe.com/webhooks#verify-manually
 */
final class WebhookSignature
{
    public const TOLERANCE_SECONDS = 300;

    public static function verify(string $payload, ?string $header, ?string $secret, ?int $now = null): bool
    {
        if (blank($header) || blank($secret)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1' && $v) {
                $signatures[] = $v;
            }
        }
        if (! $timestamp || ! $signatures || abs(($now ?? time()) - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    /** Builds a header, for tests. */
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, $secret);
    }
}
