<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Reject a path that is not valid UTF-8 before anything tries to route on it.
 *
 * An empty pattern with the /u modifier is a UTF-8 validity check and nothing
 * else — which is exactly how Laravel's own ValidatePathEncoding does it, and
 * it beats a call into mbstring.
 */
final class ValidatePathEncoding implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (preg_match('//u', $request->path) !== 1) {
            return Response::json(['ok' => false, 'error' => 'Malformed path.'], 400);
        }

        return $next($request);
    }
}
