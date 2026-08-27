<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Resolve the guard and look for a logged-in user.
 *
 * Deliberately stops at the lookup. Laravel reads the user's identifier out of
 * the session and only then hits the user provider; with no identifier present
 * it never reaches the database and returns a guest. That early exit is the
 * common path for anonymous traffic, and it is the path taken here — so the
 * benchmark pays the guard and session work without a per-request user query
 * standing in for one this workload does not otherwise make.
 *
 * Requests continue as guests rather than being rejected: every endpoint here
 * is public, and a 401 would leave nothing to measure.
 */
final class Authenticate implements Middleware
{
    /** Laravel namespaces the session key by guard and provider class. */
    private const KEY = 'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d';

    public function handle(Request $request, Closure $next): Response
    {
        $identifier = $request->session[self::KEY] ?? null;

        $request->attributes['user'] = null;

        if ($identifier !== null) {
            // Where retrieveById() would run. Unreached by anonymous traffic,
            // which is what this benchmark generates.
            $request->attributes['user'] = ['id' => $identifier];
        }

        return $next($request);
    }
}
