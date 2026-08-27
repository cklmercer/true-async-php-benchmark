<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Answer preflights and mark cross-origin responses.
 *
 * A same-origin request pays only the Origin check, which is the common case
 * and the one being measured.
 */
final class HandleCors implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');

        if ($origin === null) {
            return $next($request);
        }

        if ($request->method === 'OPTIONS') {
            return new Response('', 204)
                ->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', $request->header('Access-Control-Request-Headers') ?? '*')
                ->header('Access-Control-Max-Age', '3600');
        }

        return $next($request)
            ->header('Access-Control-Allow-Origin', $origin)
            ->header('Vary', 'Origin');
    }
}
