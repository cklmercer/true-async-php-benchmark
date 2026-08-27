// k6 gives each iteration a fresh cookie jar, so anything that must outlive an
// iteration — a session, and the CSRF token bound to it — has to be carried
// explicitly. These helpers keep a VU-scoped cookie string and replay it as a
// Cookie header on every request.
//
// This is not just bookkeeping for the write path. Without it the server never
// receives a session cookie, so it skips decryption and mints a fresh session
// every time — measuring the cheapest possible path through the middleware
// instead of the real one.
//
// This file runs once per request in goja, k6's interpreted JS runtime, on a
// box where k6 was measured using 7.7 cores to the server's 2.2. The previous
// version split the carried cookie string, rebuilt a merge object, mapped it
// and joined it again on every iteration. The server sets both cookies on every
// response, so there is nothing to merge: the two values are read directly and
// the header is concatenated. No Object.keys, no split, no map, no join.
const SESSION = 'bench_session';
const XSRF = 'XSRF-TOKEN';

export function State() {
  return { cookie: null, token: null, session: null, xsrf: null };
}

/**
 * Carry forward whatever this response set.
 *
 * Once the VU holds both cookies there is nothing left to learn: the server
 * re-issues them on every response, but the session payload and the CSRF token
 * behind them do not change, so the value replayed next iteration is equivalent
 * to the one just received. Reading res.cookies materialises the whole jar out
 * of the response headers, per request, in goja — the single most expensive
 * thing this file used to do, on a generator that is the bottleneck. The server
 * still sets, encrypts and parses both cookies either way; only the generator's
 * bookkeeping is skipped.
 */
export function remember(state, res) {
  if (state.cookie !== null && state.token !== null) {
    return res;
  }

  const jar = res.cookies;

  if (!jar) {
    return res;
  }

  const session = jar[SESSION];
  const xsrf = jar[XSRF];

  if (session !== undefined) {
    state.session = session[0].value;
  }

  if (xsrf !== undefined) {
    state.xsrf = xsrf[0].value;
    // The cookie is encrypted and URL-encoded, and the server wants the decoded
    // value back in the X-XSRF-TOKEN header — the same round trip a browser XHR
    // client makes.
    state.token = decodeURIComponent(state.xsrf);
  }

  if (state.session !== null) {
    state.cookie = state.xsrf === null
      ? SESSION + '=' + state.session
      : SESSION + '=' + state.session + '; ' + XSRF + '=' + state.xsrf;
  }

  return res;
}

/** Request params carrying the session, plus the CSRF header when writing. */
export function params(state, extra = {}, write = false) {
  const headers = { ...(extra.headers || {}) };

  if (state.cookie !== null) {
    headers['Cookie'] = state.cookie;
  }

  if (write && state.token !== null) {
    headers['X-XSRF-TOKEN'] = state.token;
  }

  return { ...extra, headers };
}
