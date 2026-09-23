<?php

namespace App\Services\Stripe;

use App\Models\StripeEvent;
use Throwable;

/**
 * Nightly safety net: lists the last few days of Stripe events and applies
 * any the webhook missed (server down, network error, misconfigured secret).
 */
final class Reconciler
{
    public function __construct(private readonly StripeClient $stripe, private readonly StripeEventHandler $handler) {}

    /** @return array{checked: int, replayed: int, failed: int} */
    public function run(int $days = 3): array
    {
        $checked = $replayed = $failed = 0;
        $after = null;

        do {
            $page = $this->stripe->get('events', array_filter([
                'types' => StripeEventHandler::TYPES,
                'created' => ['gte' => now()->subDays($days)->getTimestamp()],
                'limit' => 100,
                'starting_after' => $after,
            ]));
            foreach ($page['data'] ?? [] as $event) {
                $checked++;
                $after = $event['id'];
                if (StripeEvent::where('stripe_event_id', $event['id'])->whereNotNull('processed_at')->exists()) {
                    continue;
                }
                try {
                    $this->handler->handle($event);
                    $replayed++;
                } catch (Throwable $e) {
                    report($e);
                    $failed++;
                }
            }
        } while (! empty($page['has_more']) && $checked < 5000);

        return compact('checked', 'replayed', 'failed');
    }
}
