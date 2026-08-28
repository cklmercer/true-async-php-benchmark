<?php

declare(strict_types=1);

namespace App\Session;

use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;

/**
 * A SessionManager that hands each coroutine its own Store.
 *
 * The same problem PooledDatabaseManager solves for the database, in the place
 * it bites second. Manager::driver() caches one instance per driver name, and
 * StartSession::getSession() calls it on every request:
 *
 *     return tap($this->manager->driver(), function ($session) use ($request) {
 *         $session->setId($request->cookies->get($session->getName()));
 *     });
 *
 * Under a worker-per-request server that is safe, because only one request is
 * ever in flight. Under coroutines every request on the thread calls setId()
 * and start() on one shared Store: they overwrite each other's session id,
 * read each other's payload, and a save() by one leaves the others holding a
 * store that no longer matches the request that is using it. Observed as
 * `RuntimeException: Session store not set on request.` from
 * ShareErrorsFromSession, at about 6% of requests at 4,500 VUs.
 *
 * A Store is cheap — it is an id, an array and a handler — so unlike a database
 * connection there is no reason to pool a bounded number of them. One per
 * in-flight coroutine, discarded when the request ends.
 */
final class CoroutineAwareSessionManager extends SessionManager
{
    /** @var array<int,array<string,Store>> coroutine id => driver name => store */
    private array $stores = [];

    /**
     * @param  string|null  $driver
     */
    public function driver($driver = null): Store
    {
        $name = $driver ?: $this->getDefaultDriver();
        $coroutineId = $this->coroutineId();

        return $this->stores[$coroutineId][$name] ??= $this->createDriver($name);
    }

    /**
     * Drop this coroutine's stores.
     *
     * Coroutine ids are never reused, so without this the map grows by one
     * entry per request for the life of the worker.
     */
    public function release(int $coroutineId): void
    {
        unset($this->stores[$coroutineId]);
    }

    private function coroutineId(): int
    {
        $coroutine = \Async\current_coroutine();

        return $coroutine ? $coroutine->getId() : 0;
    }
}
