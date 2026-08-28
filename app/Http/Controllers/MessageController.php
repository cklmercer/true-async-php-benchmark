<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Database\Store;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;

/**
 * Test two: the chat log, read and written per workspace.
 *
 * Every request names a workspace, because that is the tenancy boundary —
 * roughly ten to twenty users in each. Pinning traffic to a workspace is what
 * keeps a read a range scan over one tenant's slice of the index rather than a
 * walk of the whole table.
 */
final class MessageController
{
    private const WORDS = [
        'ship', 'deploy', 'queue', 'cache', 'index', 'replica', 'latency', 'throughput',
        'coroutine', 'socket', 'pipeline', 'cursor', 'rollback', 'migration', 'benchmark',
    ];

    /**
     * The order the queries of one turn go out in, built once per worker
     * rather than per request. See steps().
     *
     * @var list<bool> true for a write
     */
    private readonly array $steps;

    public function __construct(
        private readonly Store $store,
        private readonly int $pageSize,
        private readonly int $minPageSize,
        private readonly int $maxPageSize,
        int $readsPerRequest,
        int $writesPerRequest,
    ) {
        $this->steps = self::steps($readsPerRequest, $writesPerRequest);
    }

    /**
     * Keyset pagination over one workspace's log.
     *
     * Keyset rather than OFFSET on purpose — OFFSET degrades with depth and
     * would turn a database benchmark into a measurement of how deep the cursor
     * happened to wander.
     */
    public function index(Request $request): Response
    {
        $limit = max(1, min(100, (int) ($request->query['limit'] ?? $this->pageSize)));
        $cursor = (int) ($request->query['cursor'] ?? 0);

        $rows = $this->store->page(self::workspace($request), $cursor, $limit);

        return Response::json([
            'ok' => true,
            'data' => $rows,
            'next_cursor' => $rows === [] ? null : $rows[array_key_last($rows)]['id'],
        ]);
    }

    /** One message, reached through a bound route parameter. */
    public function show(Request $request): Response
    {
        return Response::json(['ok' => true, 'data' => $request->parameters['message']]);
    }

    /**
     * The route binding behind `/messages/{message}`.
     *
     * This is the query Laravel's implicit binding makes before the controller
     * is reached, and a miss is a 404 from the middleware rather than from the
     * action — same as Laravel.
     *
     * @return array<string,mixed>
     */
    public function resolve(string $id, Request $request): array
    {
        return $this->store->find(self::workspace($request), (int) $id)
            ?? throw HttpException::notFound('No such message.');
    }

    /**
     * One chat turn: several pages of history and a couple of messages, in a
     * single request.
     *
     * The unit under test is the request, not the query, and a request that
     * issues one statement is not what an application endpoint does — it opens
     * a view, which reads what it needs and writes what the user did. Six
     * statements per request is that shape: four keyset pages and two inserts,
     * by default, and READS_PER_REQUEST / WRITES_PER_REQUEST set it (0 and 1
     * gives back the single-insert endpoint this replaced).
     *
     * The reads are chained rather than independent — each page starts where
     * the last one ended — because that is what paginating backwards through a
     * log actually is, and it makes the four statements a dependent sequence
     * rather than four copies of one query the planner has already cached.
     *
     * The rows the turn read are returned, all of them. Serialising up to four
     * hundred of them is a large part of what this request costs, and an
     * endpoint that read them and then answered with a number would be
     * measuring something no application does.
     */
    public function turn(Request $request): Response
    {
        $workspace = self::workspace($request);
        $cursor = (int) ($request->query['cursor'] ?? 0);

        $data = [];
        $written = 0;

        foreach ($this->steps as $isWrite) {
            if ($isWrite) {
                $username = $request->input('username');
                $body = $request->input('body');

                $this->store->append(
                    $workspace,
                    substr(is_string($username) ? $username : self::username(), 0, 64),
                    substr(is_string($body) ? $body : self::sentence(), 0, 512),
                    time(),
                );

                $written++;

                continue;
            }

            $rows = $this->store->page($workspace, $cursor, mt_rand($this->minPageSize, $this->maxPageSize));

            // A page off the bottom of the log leaves the cursor where it is:
            // there is nothing below it to page to, and moving it to 0 would
            // silently restart the walk at the newest row.
            if ($rows === []) {
                continue;
            }

            $cursor = $rows[array_key_last($rows)]['id'];

            foreach ($rows as $row) {
                $data[] = $row;
            }
        }

        return Response::json([
            'ok' => true,
            'data' => $data,
            'written' => $written,
            'next_cursor' => $cursor,
        ], 201);
    }

    /**
     * Where the writes fall among the reads.
     *
     * Interleaved rather than trailing: batching the inserts at the end of the
     * turn would group their WAL writes and index updates in a way a client
     * working through a conversation never does. A write lands wherever the
     * number due by that point exceeds the number already placed, which spreads
     * any two counts as evenly as they allow — four and two gives R R W R R W.
     *
     * @return list<bool>
     */
    private static function steps(int $reads, int $writes): array
    {
        $total = max(1, $reads + $writes);
        $steps = [];
        $placed = 0;

        for ($i = 0; $i < $total; $i++) {
            $due = intdiv(($i + 1) * $writes, $total);
            $isWrite = $due > $placed;

            $steps[] = $isWrite;

            if ($isWrite) {
                $placed++;
            }
        }

        return $steps;
    }

    /**
     * Which workspace this request belongs to.
     *
     * Absent means workspace 0 rather than an error: a client that has not been
     * told about workspaces still gets a working log, and the store folds an
     * out-of-range id back into range rather than opening a database that the
     * seeder never created.
     */
    private static function workspace(Request $request): int
    {
        return (int) ($request->query['workspace'] ?? $request->input['workspace'] ?? 0);
    }

    private static function username(): string
    {
        return self::WORDS[array_rand(self::WORDS)].'_'.mt_rand(1000, 999999);
    }

    private static function sentence(): string
    {
        $words = [];

        for ($i = mt_rand(6, 20); $i > 0; $i--) {
            $words[] = self::WORDS[array_rand(self::WORDS)];
        }

        return ucfirst(implode(' ', $words)).'.';
    }
}
