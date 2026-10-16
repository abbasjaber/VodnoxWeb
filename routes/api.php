<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\TelephonyWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| All API routes are prefixed with "/api/v1" (configured in bootstrap/app.php).
| These routes are stateless and receive the "api" middleware group by default.
| A unified JSON response envelope is enforced through the ApiResponse trait
| and the exception handler configuration.
|
| Business module endpoints (tenants, users, calls, etc.) will be added here
| in later phases. Token-based authentication for the Flutter application is
| also deferred to a later phase.
|
*/

Route::middleware('throttle:api')->group(function () {
    Route::get('/health', HealthController::class)->name('api.health');
});

/*
 * Telephony webhook. Public but authenticated by an HMAC-SHA256 signature;
 * idempotent by event_id. Kept outside the api throttle group so provider
 * retries are never silently dropped.
 */
Route::post('/telephony/webhook', TelephonyWebhookController::class)
    ->name('api.telephony.webhook');

/*
 * Authenticated API routes will live in this group once token-based auth
 * (Laravel Sanctum or Passport) is introduced for the Flutter client.
 */
// Route::middleware('auth:sanctum')->group(function () {
//     // Future business endpoints...
// });
