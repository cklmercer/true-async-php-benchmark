<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/** Refuse a body larger than the runtime would accept, before reading it. */
final class ValidatePostSize implements Middleware
{
    public function __construct(private readonly int $max = 8 * 1024 * 1024) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->contentLength !== null && $request->contentLength > $this->max) {
            return Response::json(['ok' => false, 'error' => 'Payload too large.'], 413);
        }

        return $next($request);
    }
}
