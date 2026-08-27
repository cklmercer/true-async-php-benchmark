// Response-time jitter through the full middleware stack, with no database
// on the path. This is the runtime and the middleware, and nothing else.
//
// Deviations from the original, both deliberate:
//   * No 15% / 33-second slow path. That existed to provoke Cloud's
//     autoscaler. There is no autoscaler here, and a 33s hold would cap a
//     fixed VU pool's throughput arithmetically, so it would measure the load
//     generator rather than the server.
//   * Minutes, not an hour. The plateau is reached in under a minute on a
//     single machine and does not move.
import http from 'k6/http';
import { report, holdThresholds, holding, ms } from './summary.js';
import { State, remember, params } from './session.js';
import { target } from './target.js';

const VUS = Number(__ENV.VUS || 20000);
const RAMP = __ENV.RAMP || '15s';
const HOLD = __ENV.HOLD || '2m';
const DOWN = __ENV.DOWN || '15s';
const RAMP_MS = ms(RAMP);
const HOLD_MS = ms(HOLD);

export const options = {
  // Nothing here reads a response body — the checks are on status alone —
  // and k6 was measured using 7.7 cores to the server's 2.2. Not
  // allocating 6 GB of bodies per run is free throughput on the
  // generator side, which is the side that is actually saturated.
  discardResponseBodies: true,
  scenarios: {
    jitter: {
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
    // Recorded, not enforced: aborting would throw away the measurement of
    // exactly the condition worth reporting.
    http_req_failed: [{ threshold: 'rate<0.01', abortOnFail: false }],
    http_req_duration: [{ threshold: 'p(95)<1000', abortOnFail: false }],
    ...holdThresholds,
  },
};

const state = State();

// Built once per VU, not once per iteration. target() is VU-local and the tag
// object is constant, so rebuilding either per request only burns generator
// CPU — which is the side that saturates first.
let url = null;
const TAGS = { tags: { name: 'GET /loadtest' } };
const TAGS_HOLD = { tags: { name: 'GET /loadtest', phase: 'hold' } };

export default function () {
  if (url === null) {
    url = `${target()}/loadtest`;
  }

  // The tag is what puts this request in the hold-window submetrics; both
  // objects are prebuilt, so choosing between them costs a comparison.
  const tags = holding(RAMP_MS, HOLD_MS) ? TAGS_HOLD : TAGS;

  // No check(): a check is a metric sample per request on a generator that is
  // the bottleneck, and it asserts what http_req_failed already tracks — k6
  // marks any non-2xx/3xx failed, and the summary reports that rate.
  remember(state, http.get(url, params(state, tags)));
}

export const handleSummary = report(__ENV.RUN_NAME || 'jitter', HOLD_MS);
