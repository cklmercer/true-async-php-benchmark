<?php

declare(strict_types=1);

namespace App\Database;

use Async\Channel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;

/**
 * A DatabaseManager that gives each coroutine its own Connection, drawing on
 * one bounded pool of PDO handles.
 *
 * Stock DatabaseManager caches one Connection per name. Under a worker-per-
 * request server that is correct, because only one request is ever in flight on
 * a worker. Under coroutines it is not: several requests share the thread, so
 * they would share one PDO handle and interleave statements and transaction
 * state on it.
 *
 * The two things are pooled separately because only one of them is scarce. A
 * handle is a Postgres backend, so there are a bounded number and a coroutine
 * that cannot get one parks until it can — recv() makes a full pool into
 * backpressure rather than an error. A Connection is an object, so each
 * coroutine simply has one, and PooledConnection borrows a handle for the
 * length of a statement rather than the length of a request.
 */
final class PooledDatabaseManager extends DatabaseManager
{
    private ?Channel $idle = null;

    /** @var array<int,PDO> */
    private array $handles = [];

    // Not $connections: DatabaseManager already has one of those, keyed by
    // connection name, and shadowing it would break the parent.
    /** @var array<int,PooledConnection> coroutine id => connection */
    private array $perCoroutine = [];

    private ?Connection $template = null;

    /**
     * Open every handle up front, in the worker's root scope.
     *
     * Connecting lazily inside a request coroutine would put a TCP connect and
     * an auth round trip on the critical path of whichever request was first.
     */
    public function warm(int $size): void
    {
        $this->idle = new Channel($size);

        $name = $this->getDefaultConnection();

        for ($i = 0; $i < $size; $i++) {
            // A real configured connection is how a correctly built PDO is
            // obtained; the handle is kept and the connection around it is
            // only a template for the ones handed to coroutines.
            $connection = $this->configure($this->makeConnection($name), 'write');

            $this->template ??= $connection;
            $this->handles[$i] = $connection->getPdo();

            $this->idle->send($i);
        }
    }

    /**
     * @param  string|null  $name
     */
    public function connection($name = null): Connection
    {
        if ($this->idle === null || $this->template === null) {
            return parent::connection($name);
        }

        return $this->perCoroutine[$this->coroutineId()] ??= new PooledConnection(
            $this,
            $this->template->getDatabaseName(),
            $this->template->getTablePrefix(),
            $this->template->getConfig(),
        );
    }

    /** Park until a handle is free, and take it. */
    public function acquire(): int
    {
        return $this->idle->recv();
    }

    public function handle(int $index): PDO
    {
        return $this->handles[$index];
    }

    public function giveBack(int $index): void
    {
        $this->idle->send($index);
    }

    /**
     * Drop this coroutine's connection at the end of its request.
     *
     * The handle is normally back in the pool already — PooledConnection
     * returns it after each statement — so this is about the connection object
     * and about the request that ended holding one anyway.
     */
    public function release(int $coroutineId): void
    {
        $connection = $this->perCoroutine[$coroutineId] ?? null;

        if ($connection === null) {
            return;
        }

        unset($this->perCoroutine[$coroutineId]);

        $connection->surrender();
    }

    private function coroutineId(): int
    {
        $coroutine = \Async\current_coroutine();

        return $coroutine ? $coroutine->getId() : 0;
    }
}
