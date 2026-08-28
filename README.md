# TrueAsync PHP Benchmark

A reproduction of the load tests in
[K6 Load Testing on Laravel Cloud](https://laravel.com/blog/k6-load-testing-on-laravel-cloud),
on a different stack: PHP 8.6 TrueAsync with a native coroutine `HttpServer`,
one box, k6 in a container beside it.

Two tests, same middleware stack on both — Laravel's global stack and `web`
group, in Laravel's order, with real cookie encryption, sessions, CSRF and
compiled routing.

- **jitter** — full middleware, no database, 2-120ms of simulated work
- **postgres** — a chat log on Postgres: keyset reads and inserts, 80/20

The chat log is keyed by workspace, the tenancy boundary, at ten to twenty
users each. `WORKSPACES` defaults to 512, and each VU is pinned to one for the
run, so a read is a range scan over one tenant's slice of the index.

## Usage

```sh
make benchmark
```

That is the whole thing: it starts the server, seeds, runs both, prints
numbers. Individually:

```sh
make jitter
make postgres
```

k6 is the limit on one box, so it can be run from another machine instead —
`make serve` here, and there:

```sh
make jitter TARGET=http://<this-machine>:8080
```

Common overrides:

```sh
make postgres WORKSPACES=1024 ROWS_PER_WORKSPACE=5000
make jitter VUS=6000 HOLD=1m
make postgres VUS=400
make stats          # what the server thinks it is doing
make clean          # stop everything, drop the data
```

## Layout

```
bin/server                 coroutine server; kernel built in the worker bootloader
bin/seed                   one message log per workspace
bootstrap/                 PSR-4 autoloader, wiring
routes/web.php             the route table
app/Http/Kernel.php        the two stacks, dispatch, store selection, /health, /stats
app/Http/Middleware/       the fifteen middleware, in Laravel's order
app/Http/                  mutable Request, buffered Response, Pipeline
app/Routing/               compiled route table, marked-regex matcher, bindings
app/Database/              Store, and the Postgres implementation
app/Support/Crypto.php     AES-256-CBC + HMAC cookie envelope
app/Exceptions/            HttpException and the handler the pipeline renders through
benchmark/jitter.js        no database on the path
benchmark/database.js      the chat log: keyset reads and inserts
benchmark/target.js        spreads VUs across listener ports, pins each to a workspace
benchmark/session.js       carries the session cookie across iterations
benchmark/summary.js       the per-run report
```

## Results

Ryzen 7 9700X (8 cores, 16 threads), 64 GB, 6 worker threads, k6 on the same
machine. 3 minutes per test: 30s ramp, 2m15s hold, 15s ramp-down. Rate and
latency are both over the hold.

| test | VUs | requests | rps | avg | med | p95 | p99 | failed |
|---|---|---|---|---|---|---|---|---|
| jitter | 4,500 | 8.73M | **64,600** | 66.7ms | 66.0ms | 122.8ms | 154.6ms | 0.00% |
| postgres | 1,000 | 9.91M | **73,400** | 2.0ms | 1.7ms | 4.4ms | 6.5ms | 0.00% |

18.6M requests, zero failures.
