<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Resolve the real client from the forwarding headers, when the immediate peer
 * is a proxy we trust.
 *
 * Nothing in this benchmark sits behind a proxy, so the headers are absent and
 * the work is the check itself — which is also true of most production requests.
 */
final class TrustProxies implements Middleware
{
    /** @param list<string> $proxies CIDR-less exact addresses, as in a simple deployment */
    public function __construct(private readonly array $proxies = []) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Nothing to compare against when no proxy is trusted, which is this
        // deployment and most single-container ones — and the peer address is
        // only worth resolving once there is something to compare it to.
        if ($this->proxies === []) {
            return $next($request);
        }

        $request->ip ??= $request->raw->getRemoteAddress();

        if ($request->ip !== null && in_array($request->ip, $this->proxies, true)) {
            $forwarded = $request->header('X-Forwarded-For');

            if ($forwarded !== null) {
                // Leftmost entry is the original client.
                $request->ip = trim(explode(',', $forwarded)[0]);
            }

            $request->secure = strtolower($request->header('X-Forwarded-Proto') ?? '') === 'https';
        }

        return $next($request);
    }
}
