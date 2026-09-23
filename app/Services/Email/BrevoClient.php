<?php

namespace App\Services\Email;

use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Sends one transactional email through the Brevo API. */
final class BrevoClient
{
    public function __construct(private readonly SettingsService $settings) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->get('email', 'brevo_api_key'));
    }

    /**
     * @param  array<int, string>  $cc
     * @param  array<int, array{name: string, content: string}>  $attachments  content is raw bytes
     * @return string Brevo message id
     */
    public function send(string $to, string $toName, array $cc, string $subject, string $html, string $text, array $attachments = [], array $tags = []): string
    {
        $key = $this->settings->get('email', 'brevo_api_key');
        if (blank($key)) {
            throw new RuntimeException('Brevo API key is not set (Settings > Email).');
        }

        $payload = array_filter([
            'sender' => ['name' => $this->settings->get('email', 'from_name'), 'email' => $this->settings->get('email', 'from_email')],
            'replyTo' => ['email' => $this->settings->get('company', 'support_email')],
            'to' => [['email' => $to, 'name' => $toName]],
            'cc' => array_map(fn ($e) => ['email' => $e], array_values(array_diff($cc, [$to]))) ?: null,
            'subject' => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
            'attachment' => array_map(fn ($a) => ['name' => $a['name'], 'content' => base64_encode($a['content'])], $attachments) ?: null,
            'tags' => $tags ?: null,
        ]);

        $response = Http::withHeaders(['api-key' => $key])->acceptJson()->timeout(20)
            ->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Brevo '.$response->status().': '.($response->json('message') ?? $response->body()));
        }

        return (string) $response->json('messageId');
    }
}
