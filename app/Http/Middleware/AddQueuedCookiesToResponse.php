<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Move cookies queued during the request onto the response.
 *
 * Separate from encryption on purpose, because Laravel separates them: this
 * runs inside EncryptCookies, which then encrypts whatever it finds attached.
 */
final class AddQueuedCookiesToResponse implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Appended rather than spread into a new array: the queue holds two
        // cookies on a normal request and the spread allocated twice to move them.
        foreach ($request->queuedCookies as $cookie) {
            $response->pendingCookies[] = $cookie;
        }

        return $response;
    }
}
