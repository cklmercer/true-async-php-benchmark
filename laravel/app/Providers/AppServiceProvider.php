<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * The route binding behind `/messages/{message}`.
     *
     * Explicit rather than implicit: the chat log is a table, not an Eloquent
     * model, and the lookup has to stay scoped to the workspace the way the
     * other stack's binding is.
     *
     * It lives here rather than in routes/web.php because route:cache loads the
     * cached route file instead of web.php, so a binder registered there would
     * never run once the routes were cached.
     */
    public function boot(): void
    {
        Route::bind('message', MessageController::resolve(...));
    }
}
