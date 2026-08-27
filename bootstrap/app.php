<?php

declare(strict_types=1);

/**
 * Wire the application and hand it back, the way Laravel's bootstrap/app.php
 * does.
 *
 * This runs inside a worker thread's root scope, not in the parent process:
 * Turso's connection pool is an Async\Channel, and a channel created inside a
 * request coroutine dies with that coroutine.
 */

require __DIR__.'/autoload.php';

return App\Http\Kernel::boot(dirname(__DIR__));
