// A chat log, partitioned by workspace. One request is one chat turn, and the
// server issues six statements for it: four chained keyset pages and two
// inserts, interleaved. See MessageController::turn.
//
// The read/write mix lives on the server now, not here, because it is a
// property of the endpoint rather than of the traffic. This file's job is to
// keep a session, pick a workspace, and hand over a cursor that has enough log
// beneath it for the server to page through.
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
// Mirrors the server's READS_PER_REQUEST and MAX_PAGE_SIZE. Not sent to it —
// they only size the cursor, so that four chained pages of up to a hundred rows
// all land inside this workspace's block instead of running off the bottom.
const READS = Number(__ENV.READS_PER_REQUEST || 4);
const MAX_ROWS = Number(__ENV.MAX_PAGE_SIZE || 100);
const FLOOR = READS * MAX_ROWS;

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
const TURN = { tags: { name: 'POST /messages' }, headers: JSON_HEADERS };
const TURN_HOLD = { tags: { name: 'POST /messages', phase: 'hold' }, headers: JSON_HEADERS };

// Everything constant about this VU's URLs, resolved once. target() and
// workspace() are both VU-local and never change after the first call, so
// re-interpolating them per iteration is pure generator overhead. Only the
// cursor varies per request now, so the rest is cached.
let base = null;
// The first id this VU's workspace owns, less one. See the cursor note below.
let block = 0;

// The body only has to differ between writes, not be a timestamp; a counter is
// a cheaper unique than Date.now().
let seq = 0;

export default function () {
  if (base === null) {
    const ws = workspace();

    base = `${target()}/messages?workspace=${ws}`;
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

  // Start the turn at a random point in the log so the pages are not all
  // served from the same hot tail of the index.
  //
  // Offset by the workspace's block. id is one global sequence and the seeder
  // fills workspaces in order, so workspace w owns ids w*MAX_ID+1 through
  // (w+1)*MAX_ID. A cursor drawn from 1..MAX_ID therefore sits below every id
  // that any workspace but 0 owns, and `id < cursor` matches nothing — 511 of
  // 512 reads used to return an empty page.
  //
  // The floor reserves a whole turn's worth of rows rather than one page's,
  // because the server chains its reads: with four pages of up to a hundred
  // rows, a cursor any lower would run out of log partway through the turn and
  // the last pages would come back empty.
  const cursor = block + FLOOR + 1 + Math.floor(Math.random() * Math.max(1, MAX_ID - FLOOR));

  const res = remember(state, http.post(
    `${base}&cursor=${cursor}`,
    `{"body":"message ${seq++}"}`,
    params(state, hold ? TURN_HOLD : TURN, true),
  ));

  // A rotated or rejected session must not wedge this VU into a loop of turns
  // that can never succeed.
  if (res.status === 419) {
    state.token = null;
    state.cookie = null;
  }
}

export const handleSummary = report(__ENV.RUN_NAME || 'database', HOLD_MS);
