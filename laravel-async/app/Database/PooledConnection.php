<?php

declare(strict_types=1);

namespace App\Database;

use Closure;
use Illuminate\Database\PostgresConnection;
use RuntimeException;

/**
 * A Connection that borrows a PDO handle for one statement at a time.
 *
 * The handle is the scarce thing — it is a Postgres backend — and the
 * Connection object is not, so each coroutine keeps its own Connection and they
 * all draw from one bounded pool of handles.
 *
 * Holding a handle for the whole request is what the first version of this did,
 * and it is what the hand-written stack does not do: PostgresStore::run() checks
 * one out, runs a statement, and hands it straight back. A request here issues
 * six statements and then serialises up to four hundred rows, so a handle held
 * from the first query to the response was occupied for all of that — including
 * the work that has nothing to do with the database. Borrowing per statement
 * makes the two stacks occupy the pool the same way, which is what makes the
 * comparison between them mean something.
 *
 * A transaction is the exception, and it has to be: every statement in one must
 * run on the same backend, so the handle is held from the BEGIN to the COMMIT
 * rather than released underneath it.
 */
final class PooledConnection extends PostgresConnection
{
    /** The pool index this connection is holding, or null between statements. */
    private ?int $held = null;

    /**
     * @param  array<string,mixed>  $config
     */
    public function __construct(
        private readonly PooledDatabaseManager $pool,
        string $database,
        string $prefix,
        array $config,
    ) {
        parent::__construct(null, $database, $prefix, $config);
    }

    /**
     * Every statement goes through here — select, insert, update, statement.
     *
     * @param  string  $query
     * @param  array<int,mixed>  $bindings
     */
    protected function run($query, $bindings, Closure $callback): mixed
    {
        // Already inside a transaction, so the handle is held and must not be
        // swapped for another one partway through.
        if ($this->held !== null) {
            return parent::run($query, $bindings, $callback);
        }

        $index = $this->pool->acquire();

        // setPdo() resets the transaction depth to zero, which is correct here
        // and is why it is never called while a transaction is open.
        $this->setPdo($this->pool->handle($index));

        try {
            return parent::run($query, $bindings, $callback);
        } finally {
            if ($this->transactionLevel() > 0) {
                // The statement opened a transaction. Keep the handle until it
                // is resolved.
                $this->held = $index;
            } else {
                $this->setPdo(null);
                $this->pool->giveBack($index);
            }
        }
    }

    public function beginTransaction(): void
    {
        if ($this->held === null) {
            $this->held = $this->pool->acquire();
            $this->setPdo($this->pool->handle($this->held));
        }

        parent::beginTransaction();
    }

    public function commit(): void
    {
        parent::commit();

        $this->releaseWhenSettled();
    }

    /**
     * @param  int|null  $toLevel
     */
    public function rollBack($toLevel = null): void
    {
        parent::rollBack($toLevel);

        $this->releaseWhenSettled();
    }

    /**
     * Give the handle back whatever state the request ended in.
     *
     * The safety net for a coroutine that died holding one — without it a
     * request that threw inside a transaction would take a backend out of the
     * pool for the life of the worker.
     */
    public function surrender(): void
    {
        if ($this->held === null) {
            return;
        }

        if ($this->transactionLevel() > 0) {
            try {
                parent::rollBack(0);
            } catch (\Throwable) {
                // The handle is going back to the pool either way; a failed
                // rollback must not stop that.
            }
        }

        $index = $this->held;
        $this->held = null;

        $this->setPdo(null);
        $this->pool->giveBack($index);
    }

    /**
     * Never open a connection of this connection's own accord.
     *
     * Connection::reconnectIfMissingConnection() calls this when the PDO is
     * null, which between statements it always is. Reconnecting there would
     * quietly open an unbounded number of backends outside the pool, so a
     * statement that reaches it is a bug and should say so.
     */
    public function reconnect(): void
    {
        throw new RuntimeException(
            'PooledConnection ran a statement without a pooled handle; it must go through run().'
        );
    }

    private function releaseWhenSettled(): void
    {
        if ($this->transactionLevel() === 0) {
            $this->surrender();
        }
    }
}
