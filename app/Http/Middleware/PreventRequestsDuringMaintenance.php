<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Laravel signals maintenance mode with a file on disk and therefore stats that
 * file on every request. The stat is the cost, and it is charged here too —
 * clearstatcache() is deliberately not called, matching Laravel, so the OS
 * stat cache absorbs most of it exactly as it does in production.
 */
final class PreventRequestsDuringMaintenance implements Middleware
{
    public function __construct(private readonly string $sentinel = '/tmp/benchmark-down') {}

    public function handle(Request $request, Closure $next): Response
    {
        if (is_file($this->sentinel)) {
            return Response::json(['ok' => false, 'error' => 'Service unavailable.'], 503);
        }

        return $next($request);
    }
}
