<?php

declare(strict_types=1);

namespace App\Database;

use Async\Channel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * A DatabaseManager that hands each coroutine its own Connection.
 *
 * Stock DatabaseManager caches one Connection per name. Under a worker-per-
 * request server that is correct, because only one request is ever in flight on
 * a worker. Under coroutines it is not: several requests share the thread, so
 * they would share one PDO handle and interleave statements and transaction
 * state on it.
 *
 * The pool is bounded rather than one connection per coroutine, because a
 * connection is a Postgres backend and thousands of them would be worse than
 * waiting. recv() parks the coroutine when the pool is empty, which costs
 * nothing, so a full pool is backpressure rather than an error.
 */
final class PooledDatabaseManager extends DatabaseManager
{
    private ?Channel $idle = null;

    /** @var array<int,Connection> */
    private array $pool = [];

    /** @var array<int,int> coroutine id => pool index */
    private array $held = [];

    /**
     * Open every connection up front, in the worker's root scope.
     *
     * Connecting lazily inside a request coroutine would put a TCP connect and
     * an auth round trip on the critical path of whichever request was first.
     */
    public function warm(int $size): void
    {
        $this->idle = new Channel($size);

        $name = $this->getDefaultConnection();

        for ($i = 0; $i < $size; $i++) {
            $this->pool[$i] = $this->configure($this->makeConnection($name), 'write');
            $this->idle->send($i);
        }
    }

    /**
     * @param  string|null  $name
     */
    public function connection($name = null): Connection
    {
        if ($this->idle === null) {
            return parent::connection($name);
        }

        $coroutineId = $this->coroutineId();

        // Already holding one: the same request asking a second time. It must
        // get the same connection back, or a transaction would straddle two.
        if (isset($this->held[$coroutineId])) {
            return $this->pool[$this->held[$coroutineId]];
        }

        $index = $this->idle->recv();
        $this->held[$coroutineId] = $index;

        return $this->pool[$index];
    }

    /** Hand this coroutine's connection back. Safe to call when it holds none. */
    public function release(int $coroutineId): void
    {
        if ($this->idle === null || ! isset($this->held[$coroutineId])) {
            return;
        }

        $index = $this->held[$coroutineId];
        unset($this->held[$coroutineId]);

        $this->idle->send($index);
    }

    private function coroutineId(): int
    {
        $coroutine = \Async\current_coroutine();

        return $coroutine ? $coroutine->getId() : 0;
    }
}
