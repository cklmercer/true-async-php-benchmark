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
make jitter STACK=laravel OCTANE_WORKERS=160
make postgres STACK=laravel-async ASYNC_THREADS=8 PG_POOL=8
make postgres READ_COST_MS=0        # a database on this machine, instead of the 2ms default
make postgres READ_COST_MS=25       # a database several network hops away
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
efficiency), 16 GB, in Docker Desktop, k6 on the same box. 30s ramp / 60s hold /
15s ramp-down, 512 workspaces x 9,766 rows (5.0M), reseeded before every run.
Rate and latency are over the hold.

Every stack is given the same machine and has its concurrency knobs set past the
point where raising them stops helping, so none of them is being held back by a
setting rather than by its own design. What that means per stack, and the sweeps
that found each number, are in [Equal footing](#equal-footing) below. Reads cost
2ms, the default, because a database that answers instantly is the one condition
under which none of this matters.

**jitter**, 4,500 VUs — no database, 2-120ms of simulated work per request:

| stack | rps | med | p95 | failed |
|---|---|---|---|---|
| `async` | **45,119** | 87.0ms | 192.5ms | 0.00% |
| `laravel` | 1,256 | 3,583.6ms | 3,655.3ms | 0.00% |
| `laravel-async` | 6,198 | 108.1ms | 4,086.4ms | **34.95%** |

`laravel-async`'s failures are a defect in that stack, not backpressure — see
[Known limits](#known-limits-of-the-laravel-async-stack). Its rate is reported
for completeness and should not be read as throughput.

**postgres**, 1,000 VUs — six statements per request: four chained keyset pages
of 25-100 rows, interleaved with two inserts, and the response carries every row
read. rps is requests, so the statement rate is six times it.

| stack | rps | statements/s | response | med | p95 | backends | failed |
|---|---|---|---|---|---|---|---|
| `async` | **5,630** | **33,780** | 252 MB/s | 173.1ms | 247.8ms | 240 | 0.00% |
| `laravel` | 2,432 | 14,592 | 111 MB/s | 402.4ms | 476.9ms | 80 | 0.00% |
| `laravel-async` | 1,988 | 11,928 | 87 MB/s | 401.8ms | 1,256.0ms | 240 | 0.00% |

Run to run these move a few percent — repeats of this exact point landed at
5,190-5,630, 2,423-2,432 and 1,988-2,034 rps — so read the gaps between stacks
rather than the last digit of any one of them.

A turn answers with 100-400 rows, so a response is tens of kilobytes and the
fastest stack is moving a quarter of a gigabyte a second. Building and writing
that is a real part of what a request costs here, which is the point — an
endpoint that read four pages and replied with a count would be measuring
something no application does.

The `backends` column is the interesting one. `laravel` reaches its number with
80 Postgres connections because a connection belongs to a worker and a worker is
held for the whole request; the coroutine stacks hold 240 and could hold more.
That is the same fact as the worker count: the blocking model buys concurrency
with processes and connections, and the coroutine model does not have to.

`laravel-async`'s median matches the control almost exactly while its p95 is 2.6
times worse. Ten threads scheduling a thousand requests means most are picked up
promptly and the rest queue behind them. That spread is CPU contention and not
the connection pool, which is worth stating because the pool is the obvious
suspect: it is set at 24 per thread, past the point where more stops helping
(6 -> 2,019, 12 -> 2,199, 24 -> 2,190, 48 -> 2,121 rps), and the tail does not
improve anywhere along that sweep.

### What happens when the database is not instant

A database in a container on the same machine, warm and hitting an index,
answers in well under a millisecond. Almost nothing in production does, and it
is the one condition under which a runtime whose whole trick is doing something
else while it waits has nothing to do.

`READ_COST_MS` adds the missing latency to each read, as a `pg_sleep` the planner
runs once per query rather than once per row. It costs Postgres no CPU, so it
does not distort the comparison by competing with PHP for the box — it only
makes a read take as long as a read against a database on another machine takes.
It defaults to 2ms. Sustained rps, 1,000 VUs, otherwise the shape above:

| read cost | `async` | `laravel` | `laravel-async` |
|---|---|---|---|
| 0ms | **6,819** | 2,989 | 2,173 |
| 2ms | **5,190** | 2,423 | 2,034 |
| 10ms | **4,834** | 1,577 | **2,070** |
| 25ms | **2,235** | 701 | **1,888** |

Three things fall out of it.

**The coroutine stacks barely notice; the blocking one does.** A hundred
milliseconds of pure waiting is added to every request between the first column
and the last. `laravel-async` gives up 13% of its throughput over that range and
`laravel` gives up 77%. A parked coroutine costs its thread nothing, so the wait
comes out of idle time; a blocked Octane worker is unavailable for the duration,
so the wait comes out of throughput.

**The crossover is at about 5ms, not at the first millisecond.** `laravel-async`
is behind the control at 0ms and 2ms and ahead of it by 10ms, after which the
gap widens quickly — 2.7x by 25ms. So the coroutine runtime does win on real
database latency, but not immediately, and an earlier version of this table said
otherwise only because the control was capped at about twenty workers. Given the
workers it needs, Octane holds the lead through the first few milliseconds.

**`async` is not immune either, and the reason is the pool rather than the
runtime.** It is flat-ish to 10ms and then halves. At 25ms each request holds a
connection through 100ms of sleep, and 240 connections divided by that is close
to the 2,235 rps it reaches — the pool, not PHP, is what runs out. Raising it
further means more Postgres backends, and that is a real limit of the deployment
rather than a knob worth turning: a coroutine runtime can hold a hundred thousand
requests in flight, and Postgres cannot give it a hundred thousand connections.

So the earlier result — that coroutines bought nothing on `postgres` — holds for
a database on the same box, and for the first few milliseconds after that. Past
that, the ranking does invert, and the further the database is the wider it gets.

Read the two tables together, because they disagree.

On **jitter** the concurrency model is the whole story. Octane blocks a worker
for the length of a request, so its ceiling is the worker count: 4,500 VUs queue
behind 80 workers and every one of them waits three and a half seconds. The same
application on coroutines answers in a tenth of a second at the median, because a
`usleep` parks a coroutine and the thread takes the next request — verified, not
assumed: a coroutine sleeping 500ms does not stop a second coroutine ticking
every 100ms on that thread. That it does so while failing a third of its requests
is a separate defect, and it is why the rate in that row is not a result.

On **postgres** it is not the story at all. `laravel-async` is *slower* than the
Octane control it is meant to beat, at least until reads cost about 5ms. Once a
request does real work rather than sleeping, the cost is Laravel's per-request
work — the container, the middleware stack, the query builder — and coroutines do
not make any of that cheaper. They only stop a thread idling during I/O, and
against a database on the same machine there is almost no idling to recover. The
2.8x gap to `async` on the same test is the framework, not the runtime.

Two things it is *not*, both swept rather than assumed. It is not the pool: 6,
12, 24 and 48 connections per thread give 2,019, 2,199, 2,190 and 2,121 rps, and
no improvement in the tail anywhere along it. It is not the thread count either:
6, 10 and 14 threads give 1,847, 1,879 and 1,914 rps at no read cost. Neither
knob is the ceiling. Laravel's per-request CPU work is.

What the third stack costs to run is the part worth keeping. Octane reaches its
number with 80 processes, each holding its own booted Laravel, and it needs them:
at `auto` — about twice the core count — it manages 1,258 rps instead of 2,351,
because a worker blocked on a read is a worker that is not serving. The coroutine
stack gets within 20% of it from 10 threads, and the gap closes and then reverses
as soon as the database is not on the same machine.

And the interleaving works exactly as advertised. At 1,000 VUs the run holds
about a thousand requests in flight across ten threads, which a server that
blocked a thread per request could not do at all; its ceiling would be ten. The
trouble is that interleaving only recovers time a thread spends idle, and against
a local database there is little to recover — which is precisely why the ranking
changes once there is.

That is the question the third stack exists to answer, and the answer is that on
a local database most of the headline gap is Laravel rather than TrueAsync, and
that the runtime starts paying for itself at the first few milliseconds of real
database latency.

### Equal footing

Three stacks with different concurrency models do not have knobs that mean the
same thing, so setting them all to the same number is not fairness — it is a
different distortion. What is applied instead is one rule: *every stack gets the
same machine, and every knob is raised until raising it stops helping.* Then no
number is a measurement of somebody's configuration.

Finding those points changed three defaults, and each one had been quietly
holding a different stack back.

| knob | was | is | why |
|---|---|---|---|
| `PG_POOL` | 6 | **24** | per thread, both coroutine stacks |
| `OCTANE_WORKERS` | `auto` | **80** | `auto` is ~2x cores, far too few here |
| `WORKERS` | 6 | **10** | one per core, matching `ASYNC_THREADS` |
| `LISTEN_PORTS` | 4 | **1** | `async` had four listeners to the others' one |

**The pool was throttling `async`.** Six connections per worker was measured
against a database that answered instantly, where a handle is held for
microseconds. At the 2ms default a read holds one for the whole of its cost, and
six became the ceiling: 6 -> 2,707, 12 -> 5,104, 24 -> 5,504, 48 -> 5,354 rps.

**`auto` was throttling the control, and worse.** An Octane worker is blocked for
the whole of a request, so its worker count is not a core count — it is the
concurrency ceiling of the entire stack, and it has to exceed the cores by a lot
once reads cost anything: 10 -> 592, 20 -> 1,258, 40 -> 2,166, 80 -> 2,351,
160 -> 2,340 rps. `auto` resolves to about twenty here, which was reporting the
control at half of what it can do. Eighty processes each holding a booted Laravel
is what the model costs, and it is the honest number to compare against.

**`async` was serving on four ports to the others' one.** The listener block
exists for the generator rather than the server — a VU holds a connection, so
tens of thousands of them exhaust the client's ephemeral ports long before the
server minds. At 1,000 VUs it is worth 1.7%, inside run-to-run noise: 5,580 rps
on four listeners against 5,483 on one. Set to one anyway. Raise `LISTEN_PORTS`
again before any run that pushes VUs into the tens of thousands.

Two differences are left in place, because they are properties of the models
rather than settings:

- **Connection counts differ.** The coroutine stacks hold 240 backends and Octane
  80, because Octane cannot hold more than one per worker. Giving the coroutine
  stacks only 80 would be measuring the blocking model's constraint twice.
- **`laravel-async` holds a connection for a whole request** where `async` returns
  one after each statement. It costs nothing now that the pool is past its knee;
  see [Known limits](#known-limits-of-the-laravel-async-stack).

### Known limits of the `laravel-async` stack

This one is an experiment, and it is reported as measured rather than tidied up.

**A connection is held for the whole request, not per statement.** The
hand-written stack does the opposite — `PostgresStore::run()` checks a handle
out, runs one statement and hands it straight back — so the two occupy the pool
differently, and this stack holds a Postgres backend through five more
statements and the serialisation of up to four hundred rows.

Per-statement borrowing was built and measured, and it is not worth it here. It
costs about a quarter of the throughput because a request has to win a free
handle six times rather than once, and it does not buy a better tail in exchange:
the tail is threads and not handles. It also no longer costs anything to leave
alone — the pool is now 24 per thread, past the point where more stops helping,
so holding one handle for a whole request never makes a second request wait. The
asymmetry with `async` is real and is left in place knowingly; closing it made
the number worse without making anything more accurate.

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

**It is not stable on `jitter` at these concurrencies.** The row in the table
above carries its failure rate for that reason: 6,198 rps at 34.95% non-2xx is
not a throughput result, and there is no honest single value to put there. Six
runs of the identical test at 4,500 VUs:

| hold | rps | failed |
|---|---|---|
| 30s | 3,993 | 0.00% |
| 30s (8,000 VUs) | 4,946 | 0.00% |
| 45s | 4,273 | 47.66% |
| 45s | 240 | 16.74% |
| 45s | 46,065 | 100.00% — the server had stopped accepting |
| 60s | 6,198 | 34.95% |

Short runs pass cleanly; longer ones degrade, and one wedged the server
altogether. `postgres` is unaffected at 0.00% on every stack and every read cost
in this document, so the defect is on the jitter path rather than in the stack as
a whole. Two failure modes are named in its logs, 754 and 3,396 times in a
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
