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

    /** Insert a message, generating the fields the client did not send. */
    public function store(Request $request): JsonResponse
    {
        $username = $request->input('username');
        $body = $request->input('body');

        DB::table('messages')->insert([
            'workspace_id' => self::workspace($request),
            'username' => substr(is_string($username) ? $username : self::username(), 0, 64),
            'body' => substr(is_string($body) ? $body : self::sentence(), 0, 512),
            'created_at' => time(),
        ]);

        return response()->json(['ok' => true], 201);
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
