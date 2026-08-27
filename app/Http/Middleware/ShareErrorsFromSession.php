<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Make validation errors from the previous request available to this one.
 *
 * Laravel builds a ViewErrorBag unconditionally, even when there are no errors,
 * so an empty bag is the normal cost rather than a shortcut.
 */
final class ShareErrorsFromSession implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes['errors'] = $request->session['errors'] ?? ['default' => []];

        return $next($request);
    }
}
