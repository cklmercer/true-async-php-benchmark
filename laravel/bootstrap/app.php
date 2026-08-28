<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/**
 * The stock Laravel 12 bootstrap, with one middleware appended.
 *
 * The global stack and the `web` group are left exactly as the skeleton ships
 * them — that stack is the thing under test, so editing it would defeat the
 * point. ResolveUser is appended because the other stack runs an Authenticate
 * middleware in its `web` group and this one does not; without it the two
 * pipelines differ by a guard resolution per request.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            App\Http\Middleware\ResolveUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
