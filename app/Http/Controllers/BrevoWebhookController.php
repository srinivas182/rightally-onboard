<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\EmailLog;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Delivery events from Brevo (delivered, opened, bounces, spam complaints,
 * blocks). Authenticated by the secret token in the URL shown in
 * Settings > Email. Hard bounces flag the customer so the team can fix the address.
 */
class BrevoWebhookController extends Controller
{
    private const STATUS = [
        'delivered' => 'delivered', 'opened' => 'opened', 'unique_opened' => 'opened', 'click' => 'opened',
        'hard_bounce' => 'bounced', 'soft_bounce' => 'deferred', 'blocked' => 'failed', 'invalid_email' => 'bounced',
        'spam' => 'complained', 'error' => 'failed', 'deferred' => 'deferred',
    ];

    public function __invoke(Request $request, string $token, SettingsService $settings): Response
    {
        $expected = (string) $settings->get('email', 'brevo_webhook_token');
        if ($expected === '' || ! hash_equals($expected, $token)) {
            return response('Forbidden', 403);
        }

        $events = array_is_list($request->all()) ? $request->all() : [$request->all()];
        foreach ($events as $e) {
            $event = (string) ($e['event'] ?? '');
            $messageId = (string) ($e['message-id'] ?? $e['messageId'] ?? '');
            $status = self::STATUS[$event] ?? null;
            if (! $status || $messageId === '') {
                continue;
            }
            $log = EmailLog::where('provider_message_id', $messageId)->first();
            if (! $log) {
                continue;
            }
            // Never downgrade: opened beats delivered; bounces and complaints always win.
            $rank = ['queued' => 0, 'sent' => 1, 'deferred' => 2, 'delivered' => 3, 'opened' => 4, 'bounced' => 5, 'complained' => 5, 'failed' => 5];
            if (($rank[$status] ?? 0) >= ($rank[$log->status] ?? 0)) {
                $log->update(['status' => $status, 'error' => in_array($status, ['bounced', 'failed', 'complained'], true) ? mb_substr((string) ($e['reason'] ?? $event), 0, 500) : $log->error]);
            }
            if (in_array($event, ['hard_bounce', 'invalid_email', 'blocked'], true) && $log->customer_id && strcasecmp($log->to_email, (string) ($e['email'] ?? $log->to_email)) === 0) {
                Customer::whereKey($log->customer_id)->where('email', $log->to_email)
                    ->update(['email_bounced_at' => now(), 'email_bounce_reason' => mb_substr((string) ($e['reason'] ?? str_replace('_', ' ', $event)), 0, 250)]);
            }
        }

        return response('OK', 200);
    }
}
