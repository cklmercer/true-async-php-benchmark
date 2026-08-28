<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Test two: the chat log, read and written per workspace.
 *
 * The query builder rather than Eloquent, and the same four statements the
 * other stack issues — same columns, same predicates, same order and limit —
 * so what separates the two numbers is the framework and the runtime, not the
 * SQL.
 *
 * Every request names a workspace, because that is the tenancy boundary:
 * roughly ten to twenty users in each. Pinning traffic to a workspace is what
 * keeps a read a range scan over one tenant's slice of the index rather than a
 * walk of the whole table.
 */
final class MessageController extends Controller
{
    private const COLUMNS = ['id', 'username', 'body', 'created_at'];

    private const WORDS = [
        'ship', 'deploy', 'queue', 'cache', 'index', 'replica', 'latency', 'throughput',
        'coroutine', 'socket', 'pipeline', 'cursor', 'rollback', 'migration', 'benchmark',
    ];

    /**
     * Keyset pagination over one workspace's log.
     *
     * Keyset rather than OFFSET on purpose — OFFSET degrades with depth and
     * would turn a database benchmark into a measurement of how deep the cursor
     * happened to wander.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', (string) config('benchmark.page_size'))));
        $cursor = (int) $request->query('cursor', '0');

        $query = DB::table('messages')
            ->select(self::COLUMNS)
            ->where('workspace_id', self::workspace($request));

        if ($cursor > 0) {
            $query->where('id', '<', $cursor);
        }

        $rows = $query->orderByDesc('id')->limit($limit)->get();

        return response()->json([
            'ok' => true,
            'data' => $rows,
            'next_cursor' => $rows->isEmpty() ? null : $rows->last()->id,
        ]);
    }

    /** One message, reached through a bound route parameter. */
    public function show(Request $request, object $message): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $message]);
    }

    /**
     * The binder behind `/messages/{message}`, registered in AppServiceProvider.
     *
     * This is the query an implicit binding would make before the controller is
     * reached, and a miss is a 404 from the router rather than from the action —
     * same as Laravel, because it is Laravel.
     */
    public static function resolve(string $id): object
    {
        return DB::table('messages')
            ->select(self::COLUMNS)
            ->where('workspace_id', self::workspace(request()))
            ->where('id', (int) $id)
            ->first() ?? abort(404, 'No such message.');
    }

    /**
     * One chat turn: several pages of history and a couple of messages, in a
     * single request.
     *
     * The mirror of the other stack's turn(), statement for statement, so the
     * two numbers differ by the framework and the runtime rather than by the
     * work asked of Postgres. See that one for why the request rather than the
     * query is the unit, why the reads are chained, and why the rows are
     * counted instead of returned.
     */
    public function turn(Request $request): JsonResponse
    {
        $workspace = self::workspace($request);
        $cursor = (int) $request->query('cursor', '0');

        $minRows = (int) config('benchmark.min_page_size');
        $maxRows = (int) config('benchmark.max_page_size');

        $read = 0;
        $written = 0;

        foreach (self::steps() as $isWrite) {
            if ($isWrite) {
                $username = $request->input('username');
                $body = $request->input('body');

                DB::table('messages')->insert([
                    'workspace_id' => $workspace,
                    'username' => substr(is_string($username) ? $username : self::username(), 0, 64),
                    'body' => substr(is_string($body) ? $body : self::sentence(), 0, 512),
                    'created_at' => time(),
                ]);

                $written++;

                continue;
            }

            $query = DB::table('messages')
                ->select(self::COLUMNS)
                ->where('workspace_id', $workspace);

            if ($cursor > 0) {
                $query->where('id', '<', $cursor);
            }

            $rows = $query->orderByDesc('id')->limit(mt_rand($minRows, $maxRows))->get();

            // A page off the bottom of the log leaves the cursor where it is:
            // there is nothing below it to page to, and moving it to 0 would
            // silently restart the walk at the newest row.
            if ($rows->isEmpty()) {
                continue;
            }

            $read += $rows->count();
            $cursor = $rows->last()->id;
        }

        return response()->json([
            'ok' => true,
            'read' => $read,
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
     * Cached in a static because config() is cheap but not free, and this is
     * the hottest endpoint in the suite.
     *
     * @return list<bool>
     */
    private static function steps(): array
    {
        static $steps = null;

        if ($steps !== null) {
            return $steps;
        }

        $writes = (int) config('benchmark.writes_per_request');
        $total = max(1, (int) config('benchmark.reads_per_request') + $writes);
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
     * told about workspaces still gets a working log.
     */
    private static function workspace(Request $request): int
    {
        return (int) $request->input('workspace', $request->query('workspace', '0'));
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
