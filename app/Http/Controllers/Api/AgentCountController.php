<?php

namespace App\Http\Controllers\Api;

use App\Enums\AgentCountSource;
use App\Http\Controllers\Controller;
use App\Services\Billing\AgentCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/agent-count
 * Authorization: Bearer <customer's agent API token>
 * Body: {"agents": 42}
 *
 * Lets a customer's RightAlly instance report its agent count. The billed
 * count never goes below the agreement's minimum.
 */
class AgentCountController extends Controller
{
    public function __invoke(Request $request, AgentCountService $agents): JsonResponse
    {
        $customer = $agents->findByToken($request->bearerToken());
        if (! $customer) {
            return response()->json(['message' => 'Invalid or missing API token.'], 401);
        }

        $data = $request->validate(['agents' => ['required', 'integer', 'min:0', 'max:100000']]);
        $billed = $agents->set($customer, (int) $data['agents'], AgentCountSource::ApiPush);

        return response()->json(['agents_reported' => (int) $data['agents'], 'agents_billed' => $billed]);
    }
}
