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

    public function __construct(
        private readonly Store $store,
        private readonly int $pageSize,
    ) {}

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

    /** Insert a message, generating the fields the client did not send. */
    public function store(Request $request): Response
    {
        $username = $request->input('username');
        $body = $request->input('body');

        $this->store->append(
            self::workspace($request),
            substr(is_string($username) ? $username : self::username(), 0, 64),
            substr(is_string($body) ? $body : self::sentence(), 0, 512),
            time(),
        );

        return Response::json(['ok' => true], 201);
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
