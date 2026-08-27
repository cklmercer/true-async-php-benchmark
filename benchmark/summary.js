// Shared reporting: every run prints its numbers, and that is the whole
// artefact. Nothing is written to disk — a run you have to go read a file to
// interpret is a run you will misinterpret.
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.2/index.js';
import exec from 'k6/execution';

// Every metric here is reported twice: once over the whole run, and once over
// the hold alone.
//
// The whole-run figures are the misleading ones. During a 30s ramp to N the
// pool holds ~N/2 VUs, so a third of the wall clock is spent at half load or
// less — which drags the rate down and, because a half-loaded server is a fast
// one, drags the latency percentiles down with it. Neither describes the
// server at the load the ramp exists to reach.
//
// Requests made during the hold carry `phase:hold`, and the thresholds each
// script declares on that tag are what make k6 keep the submetric.
export const HOLD_TAG = 'phase:hold';

/** Thresholds that exist only to materialise the hold-window submetrics. */
export const holdThresholds = {
  'http_reqs{phase:hold}': [{ threshold: 'count>=0', abortOnFail: false }],
  'http_req_failed{phase:hold}': [{ threshold: 'rate<0.01', abortOnFail: false }],
  'http_req_duration{phase:hold}': [{ threshold: 'p(95)<1000', abortOnFail: false }],
};

const UNITS = { h: 3600000, m: 60000, s: 1000 };

/** '2m15s' -> 135000. k6 accepts these in stages, so the scripts speak them too. */
export function ms(duration) {
  let total = 0;

  for (const [, value, unit] of String(duration).matchAll(/(\d+(?:\.\d+)?)([hms])/g)) {
    total += Number(value) * UNITS[unit];
  }

  return total;
}

/** True once the ramp is done and before the ramp-down starts. */
export function holding(rampMs, holdMs) {
  const elapsed = exec.instance.currentTestRunDuration;

  return elapsed >= rampMs && elapsed < rampMs + holdMs;
}

const n = (v, digits = 1) => (v == null ? '-' : Number(v).toFixed(digits));
// Not toLocaleString(): k6's JS runtime is goja, whose Number.prototype
// .toLocaleString takes a radix, so 'en-US' throws RangeError — which
// killed handleSummary outright and made k6 fall back to its default
// summary, silently, on every run in this suite.
const int = (v) => (v == null ? '-' : String(Math.round(v)).replace(/\B(?=(\d{3})+(?!\d))/g, ','));

export function report(name, holdMs) {
  return (data) => {
    const m = data.metrics;
    const pick = (metric, stat) => (m[metric] && m[metric].values[stat] != null ? m[metric].values[stat] : null);

    const dropped = pick('dropped_iterations', 'count') || 0;
    const seconds = holdMs / 1000;
    const heldReqs = pick('http_reqs{phase:hold}', 'count');
    const sustained = heldReqs != null && seconds > 0 ? heldReqs / seconds : null;
    const hold = (stat) => pick('http_req_duration{phase:hold}', stat);

    const lines = [
      '',
      `  ${name}  →  ${__ENV.TARGET || 'http://app:8080'}`,
      '',
      `  HOLD        ${int(heldReqs)} requests over ${n(seconds, 0)}s`,
      `  sustained   ${n(sustained)} rps`,
      `  duration    avg ${n(hold('avg'))}  med ${n(hold('med'))}  p90 ${n(hold('p(90)'))}`
        + `  p95 ${n(hold('p(95)'))}  p99 ${n(hold('p(99)'))}  max ${n(hold('max'))}   (ms)`,
      `  failed      ${n((pick('http_req_failed{phase:hold}', 'rate') || 0) * 100, 2)}%    vus max ${int(pick('vus_max', 'value'))}`,
      '',
      `  whole run   ${int(pick('http_reqs', 'count'))} requests    rps ${n(pick('http_reqs', 'rate'))}`
        + `    med ${n(pick('http_req_duration', 'med'))}  p95 ${n(pick('http_req_duration', 'p(95)'))}   (ms)`,
      `  waiting     avg ${n(pick('http_req_waiting', 'avg'))}  p95 ${n(pick('http_req_waiting', 'p(95)'))}   (ms)`,
      `  connecting  avg ${n(pick('http_req_connecting', 'avg'))}  p95 ${n(pick('http_req_connecting', 'p(95)'))}   (ms)`,
      `  failures    ${int((pick('http_req_failed', 'rate') || 0) * (pick('http_reqs', 'count') || 0))} non-2xx/3xx responses, whole run`,
      // Dropped iterations mean k6 could not keep its own schedule, which
      // looks exactly like a slow server and is not one.
      dropped > 0 ? `  WARNING     ${int(dropped)} dropped iterations — raise MAX_VUS` : '',
      '',
    ].filter((line) => line !== '');

    return { stdout: textSummary(data, { indent: ' ', enableColors: false }) + '\n' + lines.join('\n') + '\n' };
  };
}
