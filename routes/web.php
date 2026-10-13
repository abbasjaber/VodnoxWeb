<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Session-based web routes. Public user registration is intentionally
| disabled in this foundational phase. Login/logout return JSON responses
| so the backend can be verified without shipping any UI.
|
*/

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'VODNOX',
        'data' => null,
        'errors' => null,
    ]);
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});
