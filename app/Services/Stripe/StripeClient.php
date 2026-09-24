<?php

namespace App\Services\Stripe;

use App\Services\Settings\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Stripe REST client over Laravel's HTTP client.
 * Keys come from Settings > Stripe for the selected mode (test or live).
 * Using HTTP directly keeps us independent of SDK upgrades and makes every
 * call easy to fake in tests.
 */
final class StripeClient
{
    public const API_VERSION = '2024-06-20';

    private const BASE = 'https://api.stripe.com/v1/';

    public function __construct(private readonly SettingsService $settings) {}

    public function mode(): string
    {
        return $this->settings->get('stripe', 'mode') === 'live' ? 'live' : 'test';
    }

    public function isConfigured(): bool
    {
        return filled($this->secretKey()) && filled($this->publishableKey());
    }

    public function publishableKey(): ?string
    {
        return $this->settings->get('stripe', $this->mode().'_publishable_key');
    }

    public function webhookSecret(): ?string
    {
        return $this->settings->get('stripe', $this->mode().'_webhook_secret');
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        return $this->send('post', $path, $params, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function delete(string $path): array
    {
        return $this->send('delete', $path, []);
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $path, array $data, ?string $idempotencyKey = null): array
    {
        $secret = $this->secretKey();
        if (blank($secret)) {
            throw new StripeException('Stripe secret key is not set for '.$this->mode().' mode.', 'Online payment isn’t available right now. Please try again later.');
        }

        $request = Http::withToken($secret)
            // The key includes a fingerprint of the request, so an identical retry is de-duplicated
            // but a corrected request (e.g. after fixing an email Stripe rejected) is treated as new.
            ->withHeaders(array_filter(['Stripe-Version' => self::API_VERSION, 'Idempotency-Key' => $idempotencyKey
                ? $this->keyPrefix().'-'.$idempotencyKey.'-'.substr(sha1(json_encode($data)), 0, 12) : null]))
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false);

        try {
            $response = match ($method) {
                'get' => $request->get(self::BASE.$path, $data),
                'delete' => $request->delete(self::BASE.$path),
                default => $request->asForm()->post(self::BASE.$path, $data),
            };
        } catch (ConnectionException $e) {
            $this->rememberError('Could not reach Stripe: '.$e->getMessage(), $path);
            throw new StripeException('Could not reach Stripe: '.$e->getMessage(), 'We couldn’t reach our payment provider. Please try again in a minute.');
        }

        $json = (array) $response->json();
        if ($response->failed()) {
            $err = (array) ($json['error'] ?? []);
            // Card errors carry a message written for the cardholder; others don't.
            $userMessage = ($err['type'] ?? null) === 'card_error' ? ($err['message'] ?? null) : null;
            $this->rememberError('Stripe '.$response->status().': '.($err['message'] ?? 'unknown error'), $path);
            throw new StripeException('Stripe '.$response->status().': '.($err['message'] ?? 'unknown error'), $userMessage, $err['code'] ?? null);
        }

        return $json;
    }

    private function secretKey(): ?string
    {
        return $this->settings->get('stripe', $this->mode().'_secret_key');
    }

    /** Last Stripe error, shown to super admins in System status so problems are visible without server logs. */
    private function rememberError(string $message, string $path): void
    {
        Cache::put('health:stripe_last_error', ['message' => mb_substr($message, 0, 500), 'path' => $path, 'at' => now()], now()->addDays(7));
    }

    /**
     * Idempotency keys are built from local record numbers, which restart after a
     * test-data reset. A per-installation prefix (renewed on reset) keeps them unique,
     * so Stripe never mistakes a new request for an old one.
     */
    private function keyPrefix(): string
    {
        $prefix = (string) $this->settings->get('stripe', 'idempotency_prefix');
        if ($prefix === '') {
            $prefix = self::newKeyPrefix($this->settings);
        }

        return $prefix;
    }

    public static function newKeyPrefix(SettingsService $settings): string
    {
        $prefix = substr(bin2hex(random_bytes(6)), 0, 10);
        $settings->setMany('stripe', ['idempotency_prefix' => $prefix]);

        return $prefix;
    }
}
