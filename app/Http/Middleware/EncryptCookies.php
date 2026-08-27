<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Support\Crypto;
use Closure;

/**
 * Decrypt every incoming cookie and encrypt every outgoing one.
 *
 * Sits outside AddQueuedCookiesToResponse, so by the time this unwinds the
 * queued cookies are already attached to the response and can be encrypted.
 *
 * Every cookie, not just the session — that is what Laravel does, and it is
 * why a request carrying several cookies costs more than one carrying a single
 * cookie. A cookie that fails its MAC is dropped rather than trusted, which is
 * how a tampered or stale cookie looks.
 */
final class EncryptCookies implements Middleware
{
    /** @param list<string> $except cookies read by client-side code, left readable */
    public function __construct(
        private readonly Crypto $crypto,
        private readonly array $except = [],
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->cookies = $this->decrypt($request->raw->getHeader('Cookie'));

        $response = $next($request);

        foreach ($response->pendingCookies as $cookie) {
            $value = $cookie['raw'] || ($this->except !== [] && in_array($cookie['name'], $this->except, true))
                ? $cookie['value']
                : $this->crypto->encrypt($cookie['value']);

            $response->cookies[] = $cookie['name'].'='.rawurlencode($value).'; '.$cookie['options'];
        }

        return $response;
    }

    /**
     * Split on the first `=` per pair rather than exploding and padding. A
     * request here carries two cookies and both are on the critical path of
     * every load test in the suite.
     *
     * @return array<string,string>
     */
    private function decrypt(?string $header): array
    {
        if ($header === null || $header === '') {
            return [];
        }

        $cookies = [];
        $except = $this->except;

        foreach (explode(';', $header) as $pair) {
            $at = strpos($pair, '=');

            if ($at === false) {
                continue;
            }

            $name = trim(substr($pair, 0, $at));

            if ($name === '') {
                continue;
            }

            $value = rawurldecode(substr($pair, $at + 1));

            if ($except !== [] && in_array($name, $except, true)) {
                $cookies[$name] = $value;

                continue;
            }

            // A cookie that fails its MAC is dropped rather than trusted,
            // which is how a tampered or stale cookie looks.
            $plain = $this->crypto->decrypt($value);

            if ($plain !== null) {
                $cookies[$name] = $plain;
            }
        }

        return $cookies;
    }
}
