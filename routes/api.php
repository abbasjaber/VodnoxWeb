<?php

use App\Http\Controllers\Api\V1\HealthController;
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
 * Authenticated API routes will live in this group once token-based auth
 * (Laravel Sanctum or Passport) is introduced for the Flutter client.
 */
// Route::middleware('auth:sanctum')->group(function () {
//     // Future business endpoints...
// });
