<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/** An empty form field means "absent", not "empty string". */
final class ConvertEmptyStringsToNull implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->input !== []) {
            $request->input = $this->convert($request->input);
        }

        if ($request->query !== []) {
            $request->query = $this->convert($request->query);
        }

        return $next($request);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function convert(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->convert($value);
            } elseif ($value === '') {
                $input[$key] = null;
            }
        }

        return $input;
    }
}
