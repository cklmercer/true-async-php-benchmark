<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Rehydrate the session, and write it back on the way out.
 *
 * The payload rides in the cookie rather than a store. Workers are separate
 * threads with separate memory and the kernel balances accepts across them, so
 * an in-process session map would miss whenever a client landed on a different
 * worker. This is Laravel's `cookie` driver, it is stateless across workers,
 * and it costs strictly more crypto per request than the file driver — so it
 * understates nothing.
 */
final class StartSession implements Middleware
{
    public function __construct(
        private readonly string $cookie = 'bench_session',
        private readonly int $lifetime = 7200,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->session = $this->open($request->cookies[$this->cookie] ?? null);

        // Flash data written last request is readable this request and gone the
        // next, which is the whole contract of a flash bag.
        $request->session['_flash']['old'] = $request->session['_flash']['new'] ?? [];
        $request->session['_flash']['new'] = [];

        $response = $next($request);

        $request->session['_previous']['url'] = $request->raw->getUri();

        $request->queueCookie(
            $this->cookie,
            igbinary_serialize($request->session),
            sprintf('Path=/; Max-Age=%d; HttpOnly; SameSite=Lax', $this->lifetime),
        );

        return $response;
    }

    /**
     * Any failure — no cookie, a bad MAC, a corrupt payload — starts a new
     * session rather than erroring, which is what a first-time visitor is.
     *
     * @return array<string,mixed>
     */
    private function open(?string $payload): array
    {
        if ($payload !== null) {
            // igbinary, not PHP's serialize(): this payload is encrypted, HMAC'd,
            // base64'd and cookie'd on the way out and the reverse on the way in,
            // once per request, so its size is AES blocks and HMAC bytes on the
            // hottest path in the suite. 254 bytes becomes 178 for the session
            // this app carries, and the codec itself is faster both ways.
            //
            // igbinary has no allowed_classes equivalent, so the safety comes
            // from EncryptCookies instead: a payload that fails its MAC is
            // dropped and never reaches this function.
            $session = @igbinary_unserialize($payload);

            if (is_array($session) && isset($session['_token'], $session['id'])) {
                $session['hits'] = ($session['hits'] ?? 0) + 1;

                return $session;
            }
        }

        return [
            'id' => bin2hex(random_bytes(20)),
            '_token' => bin2hex(random_bytes(20)),
            'hits' => 1,
            '_flash' => ['old' => [], 'new' => []],
        ];
    }
}
