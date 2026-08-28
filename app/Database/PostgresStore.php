<?php

declare(strict_types=1);

namespace App\Database;

use Async\Channel;
use PDO;
use PDOStatement;

/**
 * The chat log: one table keyed by workspace.
 *
 * Workspace is the tenancy boundary, and keeping it as an index prefix rather
 * than a separate database is what a multi-tenant Postgres app actually does.
 * The composite index on (workspace_id, id DESC) makes a keyset page within a
 * workspace an index-only range scan — it never touches the heap to find rows
 * and never sorts.
 *
 * Under TrueAsync, pdo_pgsql's socket I/O suspends the coroutine rather than the
 * worker thread — verified, not assumed: a coroutine sitting in pg_sleep(0.5)
 * let another coroutine tick twice on the same thread. So a pool of connections
 * per worker gives real concurrency, and the pool exists to bound how many
 * backends the server is asked to hold open, not to stop the thread blocking.
 */
final class PostgresStore implements Store
{
    private const COLUMNS = 'id, username, body, created_at';

    /** @var list<PDO> */
    private array $pool = [];

    /** @var list<array<string,PDOStatement>> statements, per connection */
    private array $statements = [];

    /** Hands out connection indices; recv() parks the coroutine when empty. */
    private readonly Channel $idle;

    /**
     * A read's FROM clause, which carries the simulated query cost.
     *
     * Built once because the cost is fixed at boot: a cross join against a
     * one-row subquery, which the planner evaluates once per query rather than
     * once per row (EXPLAIN shows the sleep node at loops=1). Empty when the
     * cost is zero, so the default query is exactly what it always was.
     */
    private readonly string $readFrom;

    public function __construct(
        private readonly string $dsn,
        private readonly string $username,
        private readonly string $password,
        int $poolSize = 16,
        float $readCostMs = 0.0,
    ) {
        // Interpolated rather than bound: it is a boot-time number cast to
        // float, not anything a request supplies, and a bound parameter here
        // would make the planner treat the sleep as a per-row filter.
        $this->readFrom = $readCostMs > 0
            ? ' FROM messages, (SELECT pg_sleep('.($readCostMs / 1000).')) AS _cost'
            : ' FROM messages';

        $this->idle = new Channel($poolSize);

        // Opened eagerly, in the worker's root scope. Connecting lazily inside a
        // request coroutine would put a TCP connect and an auth round trip on
        // the critical path of whichever request happened to be first.
        for ($i = 0; $i < $poolSize; $i++) {
            $this->pool[$i] = $this->connect();
            $this->statements[$i] = [];
            $this->idle->send($i);
        }
    }

    public function driver(): string
    {
        return 'postgres:'.count($this->pool).' connections/worker';
    }

    private function connect(): PDO
    {
        return new PDO($this->dsn, $this->username, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Server-side prepare: the plan is reused across executions on this
            // connection instead of the statement being parsed every time.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Check out a connection, run one statement on it, hand it back.
     *
     * @param  list<mixed>  $args
     * @return list<array<string,mixed>>
     */
    private function run(string $kind, string $sql, array $args, bool $wantsRows): array
    {
        $i = $this->idle->recv();

        try {
            $statement = $this->statements[$i][$kind] ??= $this->pool[$i]->prepare($sql);
            $statement->execute($args);

            if (! $wantsRows) {
                return [];
            }

            $rows = $statement->fetchAll();
            $statement->closeCursor();

            return $rows;
        } finally {
            $this->idle->send($i);
        }
    }

    public function page(int $workspace, int $cursor, int $limit): array
    {
        return $cursor > 0
            ? $this->run(
                'page',
                'SELECT '.self::COLUMNS.$this->readFrom.' WHERE workspace_id = ? AND id < ? ORDER BY id DESC LIMIT ?',
                [$workspace, $cursor, $limit],
                true,
            )
            : $this->run(
                'head',
                'SELECT '.self::COLUMNS.$this->readFrom.' WHERE workspace_id = ? ORDER BY id DESC LIMIT ?',
                [$workspace, $limit],
                true,
            );
    }

    public function append(int $workspace, string $username, string $body, int $at): void
    {
        $this->run(
            'append',
            'INSERT INTO messages (workspace_id, username, body, created_at) VALUES (?, ?, ?, ?)',
            [$workspace, $username, $body, $at],
            false,
        );
    }

    public function find(int $workspace, int $id): ?array
    {
        $rows = $this->run(
            'find',
            'SELECT '.self::COLUMNS.' FROM messages WHERE workspace_id = ? AND id = ? LIMIT 1',
            [$workspace, $id],
            true,
        );

        return $rows[0] ?? null;
    }
}
