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

    // How many statements one chat turn issues, and how wide each read is.
    // Four and two is six per request; 0 and 1 gives back a plain single-row
    // insert endpoint.
    'reads_per_request' => (int) env('READS_PER_REQUEST', 4),
    'writes_per_request' => (int) env('WRITES_PER_REQUEST', 2),
    'min_page_size' => (int) env('MIN_PAGE_SIZE', 25),
    'max_page_size' => (int) env('MAX_PAGE_SIZE', 100),

    // Milliseconds of simulated cost on each read, for modelling a query that
    // is not a warm index lookup against a database on the same machine.
    'read_cost_ms' => (float) env('READ_COST_MS', 0),
];
