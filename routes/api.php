<?php

use App\Http\Controllers\Api\AgentCountController;
use Illuminate\Support\Facades\Route;

/*
| Machine-to-machine API (prefix /api). Authenticated per customer with the
| agent API token shown once in the admin Customers screen.
*/
Route::prefix('v1')->middleware('throttle:agent-api')->group(function () {
    Route::post('agent-count', AgentCountController::class)->name('api.agent-count');
});
