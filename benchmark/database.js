// A chat log, partitioned by workspace. GETs keyset-paginate, POSTs insert,
// 80/20 — the split is stated because the original never did.
//
// The 80/20 is a share of requests, not of iterations. A read iteration issues
// READS pages and a write iteration issues one, so the two are not the same
// number once READS is above one; see WRITE_CHANCE.
import http from 'k6/http';
import { report, holdThresholds, holding, ms } from './summary.js';
import { State, remember, params } from './session.js';
import { target, workspace } from './target.js';

const VUS = Number(__ENV.VUS || 20000);
const RAMP = __ENV.RAMP || '15s';
const HOLD = __ENV.HOLD || '2m';
const DOWN = __ENV.DOWN || '15s';
const RAMP_MS = ms(RAMP);
const HOLD_MS = ms(HOLD);
// Rows in one workspace's log, which is what a cursor ranges over now —
// not the total row count across every database.
const MAX_ID = Number(__ENV.MAX_ID || 1000);
const WRITE_RATIO = Number(__ENV.WRITE_RATIO || 0.2);
// A read iteration issues this many pages, each a fresh keyset query.
const READS = Number(__ENV.READS_PER_ITERATION || 3);
// Rows per page, drawn per query. The controller clamps limit to 100, so that
// is the ceiling here too — asking for more would silently return 100.
const MIN_ROWS = Number(__ENV.MIN_ROWS || 25);
const MAX_ROWS = Number(__ENV.MAX_ROWS || 100);
const ROW_SPREAD = MAX_ROWS - MIN_ROWS + 1;

// The per-iteration coin flip that lands WRITE_RATIO of *requests* on the write
// path. A read iteration issues READS requests and a write iteration issues
// one, so flipping at WRITE_RATIO directly would under-weight writes — at
// READS=3 it gives 8% of requests, not 20%.
//
//   p / ((1 - p) * READS + p) = WRITE_RATIO
//
// solved for p. Reduces to WRITE_RATIO when READS is 1, so the single-page
// behaviour is unchanged.
const WRITE_CHANCE = (WRITE_RATIO * READS) / (1 - WRITE_RATIO + WRITE_RATIO * READS);

export const options = {
  // Nothing here reads a response body — the checks are on status alone —
  // and k6 was measured using 7.7 cores to the server's 2.2. Not
  // allocating 6 GB of bodies per run is free throughput on the
  // generator side, which is the side that is actually saturated.
  discardResponseBodies: true,
  scenarios: {
    chat: {
      executor: 'ramping-vus',
      startVUs: 0,
      gracefulRampDown: '10s',
      stages: [
        { target: VUS, duration: RAMP },
        { target: VUS, duration: HOLD },
        { target: 0, duration: DOWN },
      ],
    },
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  thresholds: {
    http_req_failed: [{ threshold: 'rate<0.01', abortOnFail: false }],
    http_req_duration: [{ threshold: 'p(95)<1000', abortOnFail: false }],
    ...holdThresholds,
  },
};

const state = State();

// The cursor makes every read a distinct URL, and k6 tags requests by URL by
// default — which would open a metric time series per cursor value. Naming the
// request collapses them into one.
const JSON_HEADERS = { 'Content-Type': 'application/json' };
const READ = { tags: { name: 'GET /messages' } };
const READ_HOLD = { tags: { name: 'GET /messages', phase: 'hold' } };
const WRITE = { tags: { name: 'POST /messages' }, headers: JSON_HEADERS };
const WRITE_HOLD = { tags: { name: 'POST /messages', phase: 'hold' }, headers: JSON_HEADERS };

// Everything constant about this VU's URLs, resolved once. target() and
// workspace() are both VU-local and never change after the first call, so
// re-interpolating them per iteration is pure generator overhead. A read's
// limit and cursor both vary per query now, so only the prefix is cached.
let base = null;
let writeUrl = null;
// The first id this VU's workspace owns, less one. See the cursor note below.
let block = 0;

// The body only has to differ between writes, not be a timestamp; a counter is
// a cheaper unique than Date.now().
let seq = 0;

export default function () {
  if (base === null) {
    const ws = workspace();

    base = `${target()}/messages?workspace=${ws}`;
    writeUrl = base;
    block = ws * MAX_ID;
  }

  // The tag is what puts this request in the hold-window submetrics; every
  // variant is prebuilt, so choosing between them costs a comparison.
  const hold = holding(RAMP_MS, HOLD_MS);

  // A write needs a session to hold the CSRF token, which is what a browser
  // would have picked up on its first page load.
  if (state.token === null) {
    remember(state, http.get(`${base}&limit=1`, params(state, hold ? READ_HOLD : READ)));

    return;
  }

  if (Math.random() < WRITE_CHANCE) {
    const res = remember(state, http.post(
      writeUrl,
      `{"body":"message ${seq++}"}`,
      params(state, hold ? WRITE_HOLD : WRITE, true),
    ));

    // A rotated or rejected session must not wedge this VU into a write loop
    // that can never succeed.
    if (res.status === 419) {
      state.token = null;
      state.cookie = null;
    }
  } else {
    const tags = hold ? READ_HOLD : READ;

    // Several pages per iteration, each its own keyset query at its own depth
    // and its own page size — a client paging through a log rather than asking
    // the same question repeatedly.
    for (let i = 0; i < READS; i++) {
      const limit = MIN_ROWS + Math.floor(Math.random() * ROW_SPREAD);

      // Start from a random point in the log so reads are not all served from
      // the same hot tail of the index.
      //
      // Offset by the workspace's block. id is one global sequence and the
      // seeder fills workspaces in order, so workspace w owns ids w*MAX_ID+1
      // through (w+1)*MAX_ID. A cursor drawn from 1..MAX_ID therefore sits
      // below every id that any workspace but 0 owns, and `id < cursor` matches
      // nothing — 511 of 512 reads used to return an empty page.
      //
      // The floor is the page size, not a constant, so the cursor always has a
      // whole page beneath it however many rows this query asked for.
      const cursor = block + limit + 1 + Math.floor(Math.random() * Math.max(1, MAX_ID - limit));

      remember(state, http.get(`${base}&limit=${limit}&cursor=${cursor}`, params(state, tags)));
    }
  }
}

export const handleSummary = report(__ENV.RUN_NAME || 'database', HOLD_MS);
