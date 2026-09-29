<?php

namespace App\Http\Controllers;

use App\Services\Calls\GhlAppointments;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Receives GoHighLevel workflow webhooks for calendar appointments. The secret is part of the URL. */
class GhlWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, SettingsService $settings, GhlAppointments $appointments): JsonResponse
    {
        $expected = (string) $settings->get('calls', 'ghl_webhook_secret');
        if ($expected === '' || ! hash_equals($expected, $secret)) {
            return response()->json(['ok' => false], 403);
        }
        try {
            $booking = $appointments->record($request->all());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => 'Could not read this appointment'], 422);
        }

        return response()->json(['ok' => true, 'id' => $booking->id, 'status' => $booking->status]);
    }
}
