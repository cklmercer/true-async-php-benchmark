<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Resolve route parameters into the values the controller receives.
 *
 * This is route-model binding: the router left the raw captures on the request,
 * and each one the route declares a binding for is exchanged for the thing it
 * names — which for `/messages/{message}` means a query, before the controller
 * is reached. A route whose parameters are scalars walks the list and finds
 * nothing to resolve, which is the common case and costs the walk.
 *
 * It runs here rather than earlier because it needs the matched route, and the
 * route is only known once the global stack has finished.
 */
final class SubstituteBindings implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach ($request->route?->bindings ?? [] as $name => $resolver) {
            if (isset($request->parameters[$name])) {
                // The request goes with it: a binding here is scoped to the
                // workspace on the request, the way Laravel's scoped bindings
                // resolve a child through its parent.
                $request->parameters[$name] = $resolver($request->parameters[$name], $request);
            }
        }

        return $next($request);
    }
}
