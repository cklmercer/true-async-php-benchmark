# TrueAsync PHP Benchmark

A reproduction of the load tests in
[K6 Load Testing on Laravel Cloud](https://laravel.com/blog/k6-load-testing-on-laravel-cloud),
on a different stack: PHP 8.6 TrueAsync with a native coroutine `HttpServer`,
one box, k6 in a container beside it.

Two tests, same middleware stack on both — Laravel's global stack and `web`
group, in Laravel's order, with real cookie encryption, sessions, CSRF and
compiled routing.

- **jitter** — full middleware, no database, 2-120ms of simulated work
- **postgres** — a chat log on Postgres: six statements per request, four
  chained keyset pages and two inserts, answering with every row it read

Both run against any of three servers, and `STACK` picks which one answers.
Same tests, same generator, same database.

| `STACK` | server | application |
|---|---|---|
| `async` | TrueAsync coroutine `HttpServer` | hand-written, this repo |
| `laravel` | Octane on FrankenPHP | stock Laravel 12 |
| `laravel-async` | TrueAsync FrankenPHP worker | stock Laravel 12 |

The first two differ in both columns at once, so on their own they cannot say
whether a gap is the concurrency model or the framework. The third holds the
application fixed and changes only the server underneath it — its image builds
from `./laravel`, so it is the same routes, controllers and middleware, not a
copy of them. What separates it from the control is coroutines; what separates
it from `async` is Laravel.

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

Against one of the Laravel stacks instead:

```sh
make benchmark STACK=laravel
make jitter STACK=laravel-async
```

No two servers are ever up at once — starting one stops the others, because
sharing the box between them would measure the scheduler rather than any of
them.

`laravel-async` needs its image built first, and that one is not like the other
two: it compiles PHP from the `true-async` branch with the async extension in
tree, then builds FrankenPHP from its own `true-async` branch against it. Tens
of minutes on a cold cache, and it is kept out of `make build` for that reason.

```sh
make build-laravel-async
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
make jitter STACK=laravel OCTANE_WORKERS=32
make postgres STACK=laravel-async ASYNC_THREADS=8 PG_POOL=8
make postgres READS_PER_REQUEST=8 WRITES_PER_REQUEST=4   # statements per request
make postgres READS_PER_REQUEST=0 WRITES_PER_REQUEST=1   # one plain insert, as it was
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
benchmark/database.js      the chat log: one chat turn per request
benchmark/target.js        spreads VUs across listener ports, pins each to a workspace
benchmark/session.js       carries the session cookie across iterations
benchmark/summary.js       the per-run report

laravel/                   the Octane control: routes, controllers, one middleware
laravel-async/Dockerfile   PHP true-async, FrankenPHP true-async, then the app
laravel-async/Caddyfile    the async worker block; whole URI passed as a header
laravel-async/public/      the worker entrypoint, in place of Octane's driver
laravel-async/app/         a DatabaseManager that pools a connection per coroutine
```

Only the two files under `laravel-async/public` and `laravel-async/app` are
application code, and both exist solely because of coroutines. Everything the
request actually runs through comes from `laravel/`.

## Results

Ryzen 7 9700X (8 cores, 16 threads), 64 GB, 6 worker threads, k6 on the same
machine. 3 minutes per test: 30s ramp, 2m15s hold, 15s ramp-down. Rate and
latency are both over the hold.

| test | VUs | requests | rps | avg | med | p95 | p99 | failed |
|---|---|---|---|---|---|---|---|---|
| jitter | 4,500 | 8.73M | **64,600** | 66.7ms | 66.0ms | 122.8ms | 154.6ms | 0.00% |
| postgres | 1,000 | 9.91M | **73,400** | 2.0ms | 1.7ms | 4.4ms | 6.5ms | 0.00% |

18.6M requests, zero failures.

Those two rows predate the current workload: `postgres` was one keyset page and
one insert per request then, against six statements and a much larger response
now, so the number is not comparable with anything below and is kept as the
author reported it.

### All three stacks, side by side

A shorter shape on a different machine, so these numbers are comparable with
each other and not with the table above. Apple M4 (10 cores: 4 performance, 6
efficiency), 16 GB, in Docker Desktop, 6 worker threads, k6 on the same box.
15s ramp / 45s hold / 10s ramp-down, 512 workspaces x 9,766 rows (5.0M). Rate
and latency are over the hold.

**jitter**, 4,500 VUs — no database, 2-120ms of simulated work per request:

| stack | rps | med | p95 | failed |
|---|---|---|---|---|
| `async` | **47,614** | 82.4ms | 190.2ms | 0.00% |
| `laravel` | 278 | 15,454.2ms | 15,669.2ms | 0.00% |
| `laravel-async` | *unstable — see below* | | | 0-100% |

**postgres**, 1,000 VUs — six statements per request: four chained keyset pages
of 25-100 rows, interleaved with two inserts, and the response carries every row
read. rps is requests, so the statement rate is six times it.

| stack | rps | statements/s | response | med | p95 | failed |
|---|---|---|---|---|---|---|
| `async` | **7,569** | **45,414** | 344 MB/s | 129.0ms | 176.7ms | 0.00% |
| `laravel` | 3,303 | 19,818 | 151 MB/s | 291.2ms | 354.4ms | 0.00% |
| `laravel-async` | 2,224 | 13,344 | 101 MB/s | 53.3ms | 1,693.8ms | 0.00% |

A turn answers with 100-400 rows, so a response is tens of kilobytes and the
fastest stack is moving a third of a gigabyte a second. Building and writing
that is a real part of what a request costs here, which is the point — an
endpoint that read four pages and replied with a count would be measuring
something no application does.

`laravel-async`'s median is far below its p95 because it borrows a database
handle per statement, so a request has to win a free handle six times rather
than once: most sail through, and the unlucky ones queue repeatedly.

### What happens when the database is not instant

Everything above runs Postgres in a container on the same machine, warm, hitting
an index, answering in well under a millisecond. That is the least realistic
thing in this benchmark, and it turns out to be the whole reason the coroutine
stacks look unremarkable on `postgres`.

`READ_COST_MS` adds that missing latency to each read, as a `pg_sleep` the
planner runs once per query rather than once per row. It costs Postgres no CPU,
so it does not distort the comparison by competing with PHP for the box — it
only makes a read take as long as a read against a database on another machine
takes. Sustained rps, 1,000 VUs, otherwise the shape above:

| read cost | `async` | `laravel` | `laravel-async` |
|---|---|---|---|
| 0ms | **7,564** | 3,333 | 2,257 |
| 2ms | **7,464** | 1,259 | 1,795 |
| 10ms | **7,687** | 385 | 730 |
| 25ms | **7,683** | 160 | 310 |

Three things fall out of it.

**The coroutine server does not notice.** Four reads at 25ms is 100ms of pure
waiting added to every request, and `async` finishes the sweep 2% faster than it
started. Not because the wait is free, but because it was already at its ceiling
on CPU and on the generator: adding wait converted queueing time into sleeping
time and left throughput alone. A parked coroutine costs a thread nothing.

**Octane falls off a cliff.** 3,333 to 160, a 21x collapse. It blocks a worker
for the whole of a request, so with ten workers and 100ms of sleep its ceiling
is arithmetic — about a hundred requests a second, which is what it does.

**The crossover is almost immediately.** `laravel-async` is behind the control
at 0ms and ahead of it by 2ms, then roughly doubles it from there. The same
application, the same framework, the same queries — the only question is whether
the runtime can do something else while it waits.

So the earlier result, that coroutines bought nothing on `postgres`, is true only
of a database on the same box as the application. Move the database anywhere
real and the ranking inverts at the first millisecond.

Read the two tables together, because they disagree.

On **jitter** the concurrency model is the whole story. Octane blocks a worker
for the length of a request, so its ceiling is the worker count: 4,500 VUs queue
behind ten workers and every one of them waits 15 seconds. The same application
on coroutines serves 15x the requests at a hundredth of the median, because a
`usleep` parks a coroutine and the thread takes the next request — verified, not
assumed: a coroutine sleeping 500ms does not stop a second coroutine ticking
every 100ms on that thread.

On **postgres** it is not the story at all. `laravel-async` is *slower* than the
Octane control it is meant to beat. Once a request does real work rather than
sleeping, the cost is Laravel's per-request work — the container, the middleware
stack, the query builder — and coroutines do not make any of that cheaper. They
only stop a thread idling during I/O, and with `PG_POOL` connections per thread
there was not much idling left to recover. The 3.4x gap to `async` on the same
test is the framework, not the runtime.

Two things it is *not*, both checked rather than assumed. It is not the pool:
sweeping `PG_POOL` at 6, 12 and 24 gives 1,814, 1,823 and 1,761 rps, flat then
worse. It is not the thread count either, though this stack runs six worker
threads against Octane's ten: 6, 10 and 14 threads give 1,847, 1,879 and 1,914
rps. Neither knob is the ceiling. Laravel's per-request CPU work is, which is
also why borrowing a handle per statement rather than per request costs
throughput instead of buying it.

And the workers do interleave — that part works exactly as advertised. At 1,000
VUs the run holds about a thousand requests in flight across six threads, which
a server that blocked a thread per request could not do at all; its ceiling
would be six. The trouble is that interleaving only recovers time a thread
spends idle, and there is almost none to recover here. The Octane control is
the proof: it blocks an entire worker for a whole request, database waits
included, and still serves 3,303 rps from ten workers — which puts the whole
six-statement request at about 3ms of occupancy. A request cannot have been
waiting on Postgres for long if blocking through all of it costs only 3ms.

That is the question the third stack exists to answer, and the answer is that
most of the headline gap is Laravel rather than TrueAsync.

### Known limits of the `laravel-async` stack

This one is an experiment, and it is reported as measured rather than tidied up.

**The three stacks do not run the same PHP.** `laravel` runs a released 8.5.9
from `dunglas/frankenphp`; `async` and `laravel-async` both run 8.6.0-dev from
the `true-async` branch, and not even the same build of it — `async` takes the
project's prebuilt image and `laravel-async` compiles its own. So a difference
between the control and either coroutine stack is a difference of interpreter
version as well as of concurrency model. There is no way around this while the
extension only exists on a development branch, but it should be read as a
caveat on every number here rather than as a detail.

**Laravel's singletons assume one request per process.** Two of them had to be
replaced before the stack was correct at all, and both are in this repo:

- `db` — `DatabaseManager` caches one `Connection` per name, so every coroutine
  on a thread would share one PDO handle and interleave statements on it.
  `PooledDatabaseManager` hands each coroutine its own for the life of a request.
- `session` — `Manager::driver()` caches one `Store`, and
  `StartSession::getSession()` calls `setId()` on it per request. Concurrent
  coroutines overwrote each other's session id and payload. Reproducible without
  load: 120 concurrent requests sharing a cookie returned 3 `RuntimeException:
  Session store not set on request.` before `CoroutineAwareSessionManager`, and
  0 after.

Those two were found because they fail loudly. There is no reason to think they
are the only two, and a benchmark that had not checked would have reported a
throughput number for a server that was quietly serving the wrong session.

**It is not stable on `jitter` at these concurrencies, and the number in the
table above is deliberately missing because there is no honest single value to
put there.** Five runs of the identical test at 4,500 VUs:

| hold | rps | failed |
|---|---|---|
| 30s | 3,993 | 0.00% |
| 30s (8,000 VUs) | 4,946 | 0.00% |
| 45s | 4,273 | 47.66% |
| 45s | 240 | 16.74% |
| 45s | 46,065 | 100.00% — the server had stopped accepting |

Short runs pass cleanly; longer ones degrade, and one wedged the server
altogether. Two failure modes are named in its logs, 754 and 3,396 times in a
single eight-minute window:

- `RuntimeException: Session store not set on request.` — the same error
  `CoroutineAwareSessionManager` was written to fix. Giving each coroutine its
  own `Store` is evidently necessary and not sufficient; something still hands a
  request to `ShareErrorsFromSession` with no session attached.
- `Writing to the log file failed` — Laravel's own logging falling over.

Earlier runs also produced `file(): Stream error operation depth exceeded
(1000)`, a TrueAsync limit on in-flight stream operations, which the `file`
session driver reaches easily because it puts a read and a write on every
request. The container is never OOM-killed and never restarts, so this is the
application degrading rather than the box running out.

`postgres` is unaffected and runs clean at 0.00% on every stack, because
`PG_POOL` bounds how many requests per thread are working at once and caps the
concurrent file I/O as a side effect. `jitter` has no such bound — every VU sits
in a `usleep` holding an open session.

`async` does not have this problem because it does not use PHP streams for
sessions.

The honest reading is that this stack is a working experiment rather than
something to run: it is correct enough to benchmark for tens of seconds, and it
falls over under sustained load in ways that have not been fully traced.
