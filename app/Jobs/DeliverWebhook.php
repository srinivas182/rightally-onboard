<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Delivers one webhook, retrying for about 9 hours (1m, 5m, 30m, 2h, 6h). */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 21600];
    }

    public function handle(): void
    {
        $d = WebhookDelivery::with('endpoint', 'customer')->find($this->deliveryId);
        if (! $d || $d->status === 'delivered') {
            return;
        }

        // Endpoints sign with their own secret; customer sites with the customer's agent API token.
        $secret = $d->endpoint?->secret ?? $d->customer?->agent_api_token;
        if (! $secret) {
            $d->update(['status' => 'failed', 'last_error' => 'No signing secret available']);

            return;
        }

        $body = json_encode($d->payload, JSON_UNESCAPED_SLASHES);
        $t = time();
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'RightAlly-Webhooks/1.0',
            'X-RightAlly-Event' => $d->event,
            'X-RightAlly-Delivery' => (string) $d->id,
            'X-RightAlly-Signature' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret),
        ];
        if (! $d->endpoint) {
            $headers['Authorization'] = 'Bearer '.$secret;
        }

        $d->increment('attempts');
        try {
            $res = Http::withHeaders($headers)->timeout(15)->withBody($body, 'application/json')->post($d->url);
            if ($res->successful()) {
                $d->update(['status' => 'delivered', 'response_code' => $res->status(), 'delivered_at' => now(), 'last_error' => null]);

                return;
            }
            $error = 'HTTP '.$res->status().': '.mb_substr($res->body(), 0, 300);
            $code = $res->status();
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 300);
            $code = null;
        }

        $final = $this->attempts() >= $this->tries;
        $d->update(['status' => $final ? 'failed' : 'pending', 'response_code' => $code, 'last_error' => $error]);
        if (! $final) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 21600);
        }
    }
}
