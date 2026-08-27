<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;

/**
 * Test one: response-time jitter with no database on the path.
 */
final class LoadTestController
{
    public function __construct(
        private readonly int $jitterMin,
        private readonly int $jitterMax,
    ) {}

    public function show(Request $request): Response
    {
        // mt_rand, not random_int: the delay is a load shape, not a secret, and
        // random_int draws from the CSPRNG on the hottest path in the suite.
        // Session and CSRF tokens still use random_bytes.
        $delay = mt_rand($this->jitterMin, $this->jitterMax);

        \Async\delay($delay);

        return Response::json([
            'ok' => true,
            'delay_ms' => $delay,
            'hits' => $request->session['hits'] ?? 0,
        ]);
    }
}
