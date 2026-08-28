<?php

declare(strict_types=1);

use App\Http\Controllers\LoadTestController;
use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

/**
 * The same four routes the TrueAsync stack serves, declared the way Laravel
 * declares them. Being in routes/web.php is what puts them behind the `web`
 * middleware group — cookies, session, CSRF, bindings.
 */
Route::get('/loadtest', [LoadTestController::class, 'show']);

Route::get('/messages', [MessageController::class, 'index']);
Route::post('/messages', [MessageController::class, 'store']);

// The only route with a parameter, so it is the one that exercises the
// compiled route regex and a binding.
Route::get('/messages/{message}', [MessageController::class, 'show']);
