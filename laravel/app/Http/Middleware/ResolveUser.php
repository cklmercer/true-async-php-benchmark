<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the guard and look for a logged-in user.
 *
 * The mirror of the other stack's Authenticate. Laravel reads the user's
 * identifier out of the session and only then hits the user provider; with no
 * identifier present it never reaches the database and returns a guest. That
 * early exit is the path anonymous traffic takes, and it is the path taken
 * here — the guard and session work is paid for, a user query is not.
 *
 * Requests continue as guests rather than being rejected: every endpoint here
 * is public, and a 401 would leave nothing to measure.
 */
final class ResolveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->user();

        return $next($request);
    }
}
