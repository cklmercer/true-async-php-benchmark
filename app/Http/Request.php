<?php

declare(strict_types=1);

namespace App\Http;

use App\Routing\Route;
use TrueAsync\HttpRequest;

/**
 * A mutable request, because Laravel's middleware mutate one.
 *
 * TrueAsync\HttpRequest is a read-only view of what the server parsed.
 * TrimStrings rewrites input, EncryptCookies replaces the cookie jar with
 * decrypted values, TrustProxies rewrites the client address, StartSession
 * attaches a session, the router attaches a route — none of which can be
 * expressed against the raw object. This carries the mutable state the stack
 * needs and defers to the raw request for the rest.
 *
 * Method and path are properties rather than accessors: between the middleware
 * stack, the router and the response, they are read half a dozen times per
 * request, and each read was a call into the raw object.
 */
final class Request
{
    public readonly string $method;

    public readonly string $path;

    /**
     * Read once. ValidatePostSize wants it and the body parser wants it, and
     * each was a separate call into the raw request.
     */
    public readonly ?int $contentLength;

    /** @var array<string,mixed> */
    public array $query;

    /** @var array<string,mixed> Parsed body: form fields or a JSON object. */
    public array $input;

    /** @var array<string,string> Plaintext by the time the stack sees them. */
    public array $cookies = [];

    /** @var array<string,mixed> Where middleware leave things for later middleware. */
    public array $attributes = [];

    /** @var array<string,mixed>|null Attached by StartSession. */
    public ?array $session = null;

    /** @var list<array{name:string,value:string,options:string,raw:bool}> Queued by middleware, emitted on the way out. */
    public array $queuedCookies = [];

    /** Attached by the router once a route matches. */
    public ?Route $route = null;

    /** @var array<string,mixed> Route parameters: strings from the router, values after SubstituteBindings. */
    public array $parameters = [];

    /**
     * The client address, resolved only if something asks for it.
     *
     * TrustProxies is the only reader, and it only looks when a trusted proxy
     * is configured — so on a deployment with none, populating this eagerly was
     * a call into the raw request and a string allocation per request, for a
     * value nothing went on to read.
     */
    public ?string $ip = null;

    public bool $secure = false;

    public function __construct(public readonly HttpRequest $raw)
    {
        $this->method = $raw->getMethod();
        $this->path = $raw->getPath();
        $this->query = $raw->getQuery();
        $this->contentLength = $raw->getContentLength();
        $this->input = self::parse($raw, $this->method, $this->contentLength);
    }

    public function header(string $name): ?string
    {
        return $this->raw->getHeader($name);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * A body is either a form or JSON. Laravel decodes both, and the cost of
     * doing so is part of what this benchmark measures — but it decodes a body
     * that is there. A GET with a query string and no body used to pay for a
     * getPost() call on the hottest endpoint in the suite.
     *
     * @return array<string,mixed>
     */
    private static function parse(HttpRequest $raw, string $method, ?int $length): array
    {
        if ($method === 'GET' || $method === 'HEAD' || $length === 0) {
            return [];
        }

        $type = $raw->getContentType() ?? '';

        if (str_contains($type, 'json')) {
            $decoded = json_decode($raw->getBody(), true);

            return is_array($decoded) ? $decoded : [];
        }

        return $raw->getPost();
    }

    /** Queue a cookie for the response, the way Laravel's CookieJar does. */
    public function queueCookie(string $name, string $value, string $options = 'Path=/; SameSite=Lax', bool $raw = false): void
    {
        $this->queuedCookies[] = ['name' => $name, 'value' => $value, 'options' => $options, 'raw' => $raw];
    }
}
