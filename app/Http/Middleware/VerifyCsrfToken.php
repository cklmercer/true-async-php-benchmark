<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Support\Crypto;
use Closure;

/**
 * Verify the CSRF token on unsafe methods, and hand the client a readable copy
 * for the next one.
 *
 * The token arrives as a form field, as X-CSRF-TOKEN in the clear, or as
 * X-XSRF-TOKEN carrying the encrypted cookie value — the last being what a
 * browser XHR client sends back, and what Laravel accepts.
 */
final class VerifyCsrfToken implements Middleware
{
    /** Keyed, not a list: this is checked on every request, safe or not. */
    private const READ_ONLY = ['GET' => true, 'HEAD' => true, 'OPTIONS' => true];

    public function __construct(private readonly Crypto $crypto) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! isset(self::READ_ONLY[$request->method]) && ! $this->passes($request)) {
            return Response::json(['ok' => false, 'error' => 'CSRF token mismatch.'], 419);
        }

        $response = $next($request);

        // Encrypted like every other cookie, as Laravel does; the client sends
        // the value straight back as X-XSRF-TOKEN without understanding it.
        $request->queueCookie('XSRF-TOKEN', (string) ($request->session['_token'] ?? ''), 'Path=/; SameSite=Lax');

        return $response;
    }

    private function passes(Request $request): bool
    {
        $expected = $request->session['_token'] ?? null;

        if (! is_string($expected)) {
            return false;
        }

        $presented = $request->input('_token')
            ?? $request->header('X-CSRF-TOKEN')
            ?? $this->fromEncryptedHeader($request);

        return is_string($presented) && hash_equals($expected, $presented);
    }

    private function fromEncryptedHeader(Request $request): ?string
    {
        $header = $request->header('X-XSRF-TOKEN');

        return $header === null ? null : $this->crypto->decrypt(rawurldecode($header));
    }
}
