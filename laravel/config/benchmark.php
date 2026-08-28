<?php

declare(strict_types=1);

/**
 * The knobs the other stack reads out of the environment, in the place a
 * Laravel app would keep them. Read through config() so they survive
 * config:cache instead of costing an env() lookup per request.
 */
return [
    'jitter_min_ms' => (int) env('JITTER_MIN_MS', 2),
    'jitter_max_ms' => (int) env('JITTER_MAX_MS', 120),
    'page_size' => (int) env('PAGE_SIZE', 25),
];
