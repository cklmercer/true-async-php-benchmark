<?php

declare(strict_types=1);

/**
 * Laravel on the TrueAsync FrankenPHP worker.
 *
 * Octane cannot drive this branch: its FrankenPHP driver goes through
 * frankenphp_handle_request() and the SAPI superglobals, and the async worker
 * deliberately has neither — request data arrives from Go over CGO so that
 * concurrent coroutines cannot see each other's request. So this file is what
 * Octane's driver would otherwise be.
 *
 * The application is booted once per worker thread and then shared by every
 * coroutine on it, which is the whole point and also the whole problem.
 */

use App\Database\PooledDatabaseManager;
use App\Session\CoroutineAwareSessionManager;
use FrankenPHP\HttpServer;
use FrankenPHP\Request as AsyncRequest;
use FrankenPHP\Response as AsyncResponse;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Session\FileSessionHandler;

set_time_limit(0);

// This file lives in public/, so the application root is one level up.
$base = dirname(__DIR__);

require $base.'/vendor/autoload.php';

$app = require $base.'/bootstrap/app.php';

/** @var HttpKernel $kernel */
$kernel = $app->make(HttpKernel::class);

// Laravel 12's FileSessionHandler does not declare create_sid(). On this
// interpreter that is an E_WARNING at class-declaration time, and the ini only
// filters E_DEPRECATED. Declaring it here means PHP's own handler logs it once
// at boot; left to the first request it is declared inside HandleExceptions,
// which turns it into an ErrorException — a 500 on every request that touches
// the session, which is all of them.
class_exists(FileSessionHandler::class);

// Before the rebind below, not after: db.factory is registered by
// DatabaseServiceProvider, and bootstrap() is what registers the providers.
// Booting here also keeps the first request from paying for it.
$kernel->bootstrap();

// Put back what the ini asked for. HandleExceptions calls error_reporting(-1)
// while bootstrapping, which overrides it, and on this interpreter Laravel's
// own Container trips a spl_object_hash() deprecation several times per
// request. Each one is routed through the error handler and written to stderr:
// 219,649 log lines in a 30-second run against the Octane control's 13, and
// 16% of this stack's throughput.
//
// Not a thumb on the scale. The control runs a released PHP that does not
// deprecate that function at all, so leaving it on would be charging this
// stack for the interpreter being a development branch rather than for
// anything about coroutines.
error_reporting(E_ALL & ~E_DEPRECATED);

// Laravel's DatabaseManager caches one Connection per name, so every coroutine
// on this thread would share a single PDO handle — and interleave statements
// and transaction state on it. Swapped for a pool that hands each coroutine its
// own connection for the life of a request. This is the piece that has no
// equivalent in stock Laravel.
$app->singleton('db', function ($app) {
    return new PooledDatabaseManager($app, $app['db.factory']);
});

// The same fix for the session, which shares a single Store the way the
// database shares a single Connection. StartSession resolves it from `session`
// on every request, so rebinding the manager is enough to reach every caller.
$app->singleton('session', function ($app) {
    return new CoroutineAwareSessionManager($app);
});

// bind, not singleton: the skeleton registers this one as a singleton, which
// would pin one coroutine's store for the life of the worker. Resolving it per
// call keeps the `session()` helper agreeing with $request->session().
$app->bind('session.store', function ($app) {
    return $app->make('session')->driver();
});

/** @var PooledDatabaseManager $db */
$db = $app->make('db');
$db->warm((int) (getenv('PG_POOL') ?: 6));

/** @var CoroutineAwareSessionManager $session */
$session = $app->make('session');

HttpServer::onRequest(static function (AsyncRequest $request, AsyncResponse $response) use ($kernel, $db, $session): void {
    $coroutine = \Async\current_coroutine();
    $coroutineId = $coroutine ? $coroutine->getId() : 0;

    $laravelRequest = null;
    $laravelResponse = null;

    try {
        $laravelRequest = build_request($request);

        $laravelResponse = $kernel->handle($laravelRequest);

        $response->setStatus($laravelResponse->getStatusCode());

        foreach ($laravelResponse->headers->allPreserveCase() as $name => $values) {
            // setHeader() replaces. A Laravel response carries two Set-Cookie
            // values — the session and the CSRF token — so setting each in turn
            // keeps only the last, and the client never receives XSRF-TOKEN.
            // addHeader() appends, which is what the header was built for.
            $first = true;

            foreach ($values as $value) {
                if ($first) {
                    $response->setHeader($name, $value);
                    $first = false;
                } else {
                    $response->addHeader($name, $value);
                }
            }
        }

        $response->write($laravelResponse->getContent());
        $response->end();
    } catch (\Throwable $e) {
        error_log('[async] '.$e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());

        $response->setStatus(500);
        $response->setHeader('Content-Type', 'application/json');
        $response->write('{"ok":false}');
        $response->end();
    } finally {
        if ($laravelRequest !== null && $laravelResponse !== null) {
            $kernel->terminate($laravelRequest, $laravelResponse);
        }

        // Back to the pool before the coroutine dies, or the pool drains and
        // every later request parks forever waiting for a connection.
        $db->release($coroutineId);
        $session->release($coroutineId);
    }
});

/**
 * Build a Laravel request without touching a superglobal.
 *
 * The demo in true-async/laravel-test assigns $_GET/$_REQUEST and calls
 * Request::capture(). That relies on the interpreter's global-isolation work to
 * keep two coroutines apart. Passing everything explicitly does not, so it is
 * correct on a plain async build as well.
 */
function build_request(AsyncRequest $request): LaravelRequest
{
    // Caddy rewrites the path to the worker file, so getUri() reports
    // /async_entrypoint.php. The Caddyfile stashes the real one here.
    $uri = $request->getHeader('X-Original-Uri') ?? $request->getUri();
    $method = $request->getMethod();

    $server = [
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'SERVER_PROTOCOL' => $request->getProtocolVersion(),
        'REMOTE_ADDR' => explode(':', $request->getRemoteAddr())[0] ?? '127.0.0.1',
        'HTTP_HOST' => $request->getHost(),
        'HTTPS' => $request->getScheme() === 'https' ? 'on' : 'off',
    ];

    foreach ($request->getHeaders() as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    // Symfony reads these two unprefixed, not as HTTP_*. Without CONTENT_TYPE
    // the request never reports isJson(), so input() looks in an empty form
    // body and every POST fails validation.
    foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $key) {
        if (isset($server['HTTP_'.$key])) {
            $server[$key] = $server['HTTP_'.$key];
        }
    }

    $body = $request->getBody();

    // Parameters are left empty on purpose: $uri already carries the original
    // query string, and Request::create() parses it. Passing getQueryParams()
    // as well would be the rewritten (empty) one.
    return LaravelRequest::create(
        $request->getScheme().'://'.$request->getHost().$uri,
        $method,
        [],
        $request->getCookies(),
        [],
        $server,
        $body === '' ? null : $body,
    );
}
