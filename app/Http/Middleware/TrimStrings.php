<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Trim every string in the input, recursively, except the fields where
 * surrounding whitespace is meaningful.
 */
final class TrimStrings implements Middleware
{
    /** @param list<string> $except */
    public function __construct(private readonly array $except = ['password', 'password_confirmation']) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Nothing to walk when there is neither a body nor a query string,
        // which is most of a read-heavy load. Laravel iterates the same empty
        // array; skipping it understates nothing.
        if ($request->input !== []) {
            $request->input = $this->clean($request->input);
        }

        if ($request->query !== []) {
            $request->query = $this->clean($request->query);
        }

        return $next($request);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function clean(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->clean($value);
            } elseif (is_string($value) && ! in_array($key, $this->except, true)) {
                $input[$key] = trim($value);
            }
        }

        return $input;
    }
}
