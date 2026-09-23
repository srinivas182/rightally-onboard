<?php

namespace App\Http\Controllers;

use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeEventHandler;
use App\Services\Stripe\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/** Receives Stripe webhooks: verify the signature, then hand the event to StripeEventHandler. */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeClient $stripe, StripeEventHandler $handler): Response
    {
        $payload = $request->getContent();
        if (! WebhookSignature::verify($payload, $request->header('Stripe-Signature'), $stripe->webhookSecret())) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response('Invalid payload', 400);
        }

        try {
            $result = $handler->handle($event);
        } catch (Throwable $e) {
            report($e);

            return response('Error, will retry', 500); // Stripe retries with backoff
        }

        return response($result === 'duplicate' ? 'Already processed' : 'OK', 200);
    }
}
