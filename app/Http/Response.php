<?php

declare(strict_types=1);

namespace App\Http;

use TrueAsync\HttpResponse;

/**
 * A buffered response.
 *
 * Middleware run as an onion: the outer layers touch the response on the way
 * back out, so nothing can be written to the socket until the whole stack has
 * unwound. Buffering here is what makes that possible.
 */
final class Response
{
    /** Statuses that must not carry a body, per RFC 9110 — Symfony strips them too. */
    private const BODYLESS = [100 => true, 101 => true, 102 => true, 103 => true, 204 => true, 304 => true];

    /** @var array<string,string> */
    public array $headers = [];

    /** @var list<string> Complete Set-Cookie lines. */
    public array $cookies = [];

    /**
     * Cookies attached but not yet serialised, so the outer EncryptCookies
     * layer can still encrypt them on the way out.
     *
     * @var list<array{name:string,value:string,options:string,raw:bool}>
     */
    public array $pendingCookies = [];

    /** Formatted Date header, and the second it was formatted for. */
    private static int $dateSecond = 0;

    private static string $dateHeader = '';

    public function __construct(
        public string $body = '',
        public int $status = 200,
    ) {}

    public static function json(mixed $data, int $status = 200): self
    {
        $response = new self(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status);

        $response->headers['Content-Type'] = 'application/json';

        return $response;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function cookie(string $line): self
    {
        $this->cookies[] = $line;

        return $this;
    }

    /**
     * Symfony's Response::prepare(), which every Laravel response goes through
     * before it reaches the wire, and which this stack previously skipped.
     *
     * It is not ceremony: Content-Length is what lets a keep-alive connection
     * find the end of the body without chunking, HEAD must answer with the
     * headers of the GET and none of its body, and a 204 or 304 carrying a body
     * is malformed. Laravel also defaults uncached responses to `no-cache,
     * private` rather than letting a proxy decide.
     */
    public function prepare(Request $request): self
    {
        if (isset(self::BODYLESS[$this->status])) {
            $this->body = '';

            unset($this->headers['Content-Type'], $this->headers['Content-Length']);
        } else {
            if (! isset($this->headers['Cache-Control'])) {
                $this->headers['Cache-Control'] = 'no-cache, private';
            }

            $this->headers['Content-Length'] = (string) strlen($this->body);

            // The length stays: a HEAD tells the client how big the GET would be.
            if ($request->method === 'HEAD') {
                $this->body = '';
            }
        }

        $this->headers['Date'] ??= self::date();

        return $this;
    }

    public function send(HttpResponse $out): void
    {
        $out->setStatusCode($this->status);

        foreach ($this->headers as $name => $value) {
            $out->setHeader($name, $value);
        }

        // Repeated Set-Cookie lines, not one joined value.
        foreach ($this->cookies as $line) {
            $out->addHeader('Set-Cookie', $line);
        }

        $out->end($this->body);
    }

    /**
     * A Date header is required on every response and is only accurate to the
     * second, so it is formatted once per second per worker rather than once
     * per request. Real servers do the same; gmdate() on the hot path is a
     * measurable slice of a two-microsecond handler.
     */
    private static function date(): string
    {
        $now = time();

        if ($now !== self::$dateSecond) {
            self::$dateSecond = $now;
            self::$dateHeader = gmdate('D, d M Y H:i:s', $now).' GMT';
        }

        return self::$dateHeader;
    }
}
