// One connection per VU means the load generator is bounded by its ephemeral
// port range, not by the server.
//
// Linux allows roughly 64.5k source ports toward a single (destination address,
// destination port) pair, and that is the whole tuple space when every VU talks
// to app:8080. A calibration sweep that allocates 75k VUs therefore runs out of
// source ports partway up the staircase and reports
//
//   connect: cannot assign requested address
//
// which looks exactly like a server refusing connections and is not one — the
// server never saw those requests. Raising ip_local_port_range and enabling
// tcp_tw_reuse (both already set on the k6 service) buys the top of that 64.5k
// and no more.
//
// The fix is to widen the tuple rather than the range: the server listens on a
// contiguous block of ports and each VU is pinned to one of them, so N ports
// multiply the available connections by N. Four gives ~258k, which is past
// anything this suite asks for.
import exec from 'k6/execution';

const TARGET = __ENV.TARGET || 'http://app:8080';
const PORTS = Math.max(1, Number(__ENV.TARGET_PORTS || 4));

const parsed = /^(https?:\/\/[^:/]+)(?::(\d+))?$/.exec(TARGET.replace(/\/+$/, ''));
const host = parsed ? parsed[1] : TARGET;
const base = parsed && parsed[2] ? Number(parsed[2]) : 80;

const URLS = Array.from({ length: PORTS }, (_, i) => `${host}:${base + i}`);

// Resolved once per VU, not once per request. k6 gives every VU its own module
// instance, so this caches VU-locally — and a VU that kept switching ports
// would churn a new connection per iteration, which is the opposite of the
// point.
let mine = null;

export function target() {
  if (mine === null) {
    mine = URLS[exec.vu.idInTest % PORTS];
  }

  return mine;
}

// Which workspace this VU belongs to.
//
// Workspace is the tenancy boundary: roughly ten to twenty users in each.
// Pinning a VU to one for the life of the run is what a real user does — they
// do not hop between workspaces per request — and it keeps each read on one
// tenant's slice of the index instead of every VU hammering the same hot
// range.
const WORKSPACES = Math.max(1, Number(__ENV.WORKSPACES || 512));

let myWorkspace = null;

export function workspace() {
  if (myWorkspace === null) {
    myWorkspace = exec.vu.idInTest % WORKSPACES;
  }

  return myWorkspace;
}
