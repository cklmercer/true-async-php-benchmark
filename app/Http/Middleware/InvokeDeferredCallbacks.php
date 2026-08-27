<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Laravel runs work deferred during the request once the response is ready.
 * Nothing here defers anything, so this drains an empty queue — which is what
 * it costs in a request that does not defer either.
 */
final class InvokeDeferredCallbacks implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($request->attributes['deferred'] ?? [] as $callback) {
            $callback();
        }

        return $response;
    }
}
