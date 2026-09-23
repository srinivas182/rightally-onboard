<?php

namespace App\Services\Security;

use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile bot check on the public onboarding form.
 * Switched off until both keys are saved in Settings > Security.
 */
final class Turnstile
{
    public function __construct(private readonly SettingsService $settings) {}

    public function enabled(): bool
    {
        return filled($this->siteKey()) && filled($this->settings->get('security', 'turnstile_secret_key'));
    }

    public function siteKey(): ?string
    {
        return $this->settings->get('security', 'turnstile_site_key');
    }

    public function verify(?string $token, ?string $ip): bool
    {
        if (! $this->enabled()) {
            return true;
        }
        if (blank($token)) {
            return false;
        }

        try {
            $res = Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $this->settings->get('security', 'turnstile_secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]);

            return (bool) $res->json('success');
        } catch (Throwable $e) {
            Log::warning('Turnstile verification failed to run', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
