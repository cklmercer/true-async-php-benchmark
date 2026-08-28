<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Test one: response-time jitter with no database on the path.
 *
 * usleep, where the TrueAsync build calls Async\delay. That is the whole
 * difference between the two stacks on this endpoint: there the sleep parks a
 * coroutine and the worker thread picks up the next request, here it holds an
 * Octane worker until it returns.
 */
final class LoadTestController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // mt_rand, not random_int: the delay is a load shape, not a secret, and
        // random_int draws from the CSPRNG on the hottest path in the suite.
        $delay = mt_rand(
            (int) config('benchmark.jitter_min_ms'),
            (int) config('benchmark.jitter_max_ms'),
        );

        usleep($delay * 1000);

        return response()->json([
            'ok' => true,
            'delay_ms' => $delay,
            'hits' => $request->session()->get('hits', 0),
        ]);
    }
}
