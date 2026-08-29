<?php

declare(strict_types=1);

namespace App\Http;

use App\Database\PostgresStore;
use App\Database\Store;
use App\Exceptions\Handler;
use App\Http\Controllers\LoadTestController;
use App\Http\Controllers\MessageController;
use App\Http\Middleware\AddQueuedCookiesToResponse;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\ConvertEmptyStringsToNull;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\HandleCors;
use App\Http\Middleware\InvokeDeferredCallbacks;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\StartSession;
use App\Http\Middleware\SubstituteBindings;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidatePathEncoding;
use App\Http\Middleware\ValidatePostSize;
use App\Http\Middleware\VerifyCsrfToken;
use App\Routing\Router;
use App\Support\Crypto;
use RuntimeException;
use Throwable;
use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;

/**
 * The HTTP kernel: the middleware stack, and the path a request takes through
 * it.
 *
 * The shape is Laravel's. A request runs the global stack, and only then is a
 * route found; the route's group — `web` here — runs next, and the controller
 * runs at the bottom. That ordering is not cosmetic: SubstituteBindings lives
 * in the `web` group and resolves the route's bindings, which means the route
 * has to be known before the group starts.
 *
 * One instance per worker thread, built by the bootloader in the worker's root
 * scope, so nothing is shared across threads.
 */
final class Kernel
{
    private static ?self $instance = null;

    /** Named on /stats so a run's numbers can be attributed to a backend. */
    private static string $driver = 'unknown';

    /** Global stack, ending in route dispatch. */
    private readonly Pipeline $global;

    /** The `web` group, ending in the matched route's action. */
    private readonly Pipeline $web;

    public function __construct(
        private readonly Router $router,
        private readonly Handler $handler,
        Crypto $crypto,
    ) {
        // Composed once per worker. Rebuilding the closure chain per request
        // would be measuring Pipeline rather than the middleware in it.
        $this->web = new Pipeline($this->group($crypto), $this->run(...), $handler);
        $this->global = new Pipeline($this->stack(), $this->dispatchToRoute(...), $handler);
    }

    /**
     * Hand the worker its kernel.
     *
     * The Postgres pool is an Async\Channel, and a channel created inside a
     * request coroutine is disposed along with that coroutine — so building this
     * lazily on the first request kills every request after it. bootstrap/app.php
     * runs in the worker's root scope, which is where it has to happen.
     */
    public static function install(self $kernel): void
    {
        self::$instance ??= $kernel;
    }

    public static function instance(): self
    {
        return self::$instance ?? throw new RuntimeException('No kernel was installed for this worker.');
    }

    /** Wire the application: database, crypto, routes. */
    public static function boot(string $basePath): self
    {
        $db = self::store();

        self::$driver = $db->driver();

        $router = new Router();

        (require $basePath.'/routes/web.php')(
            $router,
            new LoadTestController(
                // Read once. These sat in the request path and cost two
                // getenv() calls on every hit of the hottest endpoint here.
                jitterMin: (int) (getenv('JITTER_MIN_MS') ?: 2),
                jitterMax: (int) (getenv('JITTER_MAX_MS') ?: 120),
            ),
            new MessageController(
                $db,
                pageSize: (int) (getenv('PAGE_SIZE') ?: 25),
                minPageSize: (int) (getenv('MIN_PAGE_SIZE') ?: 25),
                maxPageSize: (int) (getenv('MAX_PAGE_SIZE') ?: 100),
                // count(), not `?:`, because zero is a value here — a turn
                // of six reads and no writes is a thing you would ask for, and
                // "0" is falsy, so `?:` would hand back the default instead.
                readsPerRequest: self::count('READS_PER_REQUEST', 4),
                writesPerRequest: self::count('WRITES_PER_REQUEST', 2),
            ),
        );

        $router->compile();

        return new self($router, new Handler(), Crypto::fromEnv());
    }

    /** An environment integer that is allowed to be zero. */
    private static function count(string $key, int $default): int
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : (int) $value;
    }

    /**
     * The store this build talks to.
     *
     * One table keyed by workspace, with a composite index that makes a keyset
     * page within a workspace an index-only range scan.
     */
    public static function store(): Store
    {
        // Not `?:` like its neighbours: the default is no longer zero, and an
        // explicit READ_COST_MS=0 is falsy, so `?:` would quietly turn the one
        // setting that removes the cost into the one that adds it.
        $readCost = getenv('READ_COST_MS');

        return new PostgresStore(
            dsn: getenv('PG_DSN') ?: 'pgsql:host=postgres;port=5432;dbname=bench',
            username: getenv('PG_USER') ?: 'bench',
            password: getenv('PG_PASSWORD') ?: 'bench',
            poolSize: max(1, (int) (getenv('PG_POOL') ?: 16)),
            readCostMs: (float) ($readCost === false || $readCost === '' ? 2 : $readCost),
        );
    }

    /**
     * Laravel's global middleware, in Laravel's order.
     *
     * @return list<\App\Contracts\Middleware>
     */
    private function stack(): array
    {
        return [
            new ValidatePathEncoding(),
            new InvokeDeferredCallbacks(),
            new TrustProxies(),
            new HandleCors(),
            new PreventRequestsDuringMaintenance(),
            new ValidatePostSize(),
            new TrimStrings(),
            new ConvertEmptyStringsToNull(),
        ];
    }

    /**
     * The `web` group, in Laravel's order.
     *
     * @return list<\App\Contracts\Middleware>
     */
    private function group(Crypto $crypto): array
    {
        return [
            new EncryptCookies($crypto),
            new AddQueuedCookiesToResponse(),
            new StartSession(),
            new ShareErrorsFromSession(),
            new Authenticate(),
            new VerifyCsrfToken($crypto),
            new SubstituteBindings(),
        ];
    }

    public function handle(HttpRequest $incoming, HttpResponse $outgoing): void
    {
        // Built first so the path is read once. Liveness and telemetry then sit
        // outside the stack: they are instrumentation, and paying session
        // crypto here would show up in the very numbers they exist to report.
        // They pay for a Request object instead, which nothing measures.
        $request = new Request($incoming);

        if ($request->path === '/health') {
            $outgoing->json(['ok' => true]);

            return;
        }

        if ($request->path === '/stats') {
            $this->stats($outgoing);

            return;
        }

        try {
            $this->global->handle($request)->prepare($request)->send($outgoing);
        } catch (Throwable $error) {
            // The pipeline renders anything thrown inside it, so reaching here
            // means the failure was in prepare(), send(), or the handler itself.
            if (! $outgoing->isHeadersSent()) {
                $outgoing->json(['ok' => false, 'error' => $error->getMessage()], 503);
            }
        }
    }

    /** Between the global stack and the group: find the route. */
    private function dispatchToRoute(Request $request): Response
    {
        $this->router->match($request);

        return $this->web->handle($request);
    }

    /** The bottom of the stack: the matched route's action. */
    private function run(Request $request): Response
    {
        return ($request->route->action)($request);
    }

    /**
     * What the server thinks is happening, so a plateau in the k6 numbers can
     * be attributed to the server, the database, or neither.
     */
    private function stats(HttpResponse $outgoing): void
    {
        $outgoing->json([
            'driver' => self::$driver,
            'coroutines' => count(\Async\get_coroutines()),
            'runtime' => \Async\runtime_stats(),
            'cpu' => \Async\cpu_usage(),
            'memory_mb' => round(memory_get_usage(true) / 1048576, 1),
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ]);
    }
}
