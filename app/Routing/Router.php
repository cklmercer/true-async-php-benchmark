<?php

declare(strict_types=1);

namespace App\Routing;

use App\Exceptions\HttpException;
use App\Http\Request;
use Closure;

/**
 * The route table, compiled once per worker.
 *
 * This is the shape `php artisan route:cache` leaves behind, which is what a
 * deployed Laravel app runs: static routes collapse into a map keyed by path,
 * and every route with parameters joins one combined regex whose alternatives
 * are tagged with PCRE marks, so a single preg_match identifies which route
 * matched and hands back its parameters. Symfony's compiled matcher — the one
 * underneath Laravel's CompiledRouteCollection — does exactly this.
 */
final class Router
{
    /** @var array<string,array<string,Route>> path => method => route */
    private array $static = [];

    /** @var array<string,array<string,Route>> uri pattern => method => route */
    private array $dynamic = [];

    /** @var array<int,array{params:list<string>,routes:array<string,Route>}> */
    private array $marks = [];

    private ?string $regex = null;

    /** @param Closure(Request):\App\Http\Response $action */
    public function get(string $uri, Closure $action): Route
    {
        return $this->add('GET', $uri, $action);
    }

    /** @param Closure(Request):\App\Http\Response $action */
    public function post(string $uri, Closure $action): Route
    {
        return $this->add('POST', $uri, $action);
    }

    /** @param Closure(Request):\App\Http\Response $action */
    public function add(string $method, string $uri, Closure $action): Route
    {
        $route = new Route($method, $uri, $action);

        if ($route->isStatic()) {
            $this->static[$uri][$method] = $route;
        } else {
            $this->dynamic[$uri][$method] = $route;
        }

        return $route;
    }

    /**
     * Fold the dynamic routes into one marked regex.
     *
     * Called once at boot. Matching before this runs would still work for
     * static paths and silently 404 everything else, so it is not optional.
     */
    public function compile(): void
    {
        if ($this->dynamic === []) {
            $this->regex = null;

            return;
        }

        $alternatives = [];
        $mark = 0;

        foreach ($this->dynamic as $uri => $routes) {
            $this->marks[$mark] = [
                // Every method on one URI shares its parameter list.
                'params' => reset($routes)->parameterNames,
                'routes' => $routes,
            ];

            $alternatives[] = self::pattern($uri).'(*:'.$mark.')';

            $mark++;
        }

        // (?| resets group numbering per alternative, so the captures of
        // whichever branch matched are always $matches[1..n].
        $this->regex = '#^(?|'.implode('|', $alternatives).')$#sD';
    }

    /**
     * Find the route for this request, attach it, and fill its parameters.
     *
     * Throws rather than returning null, the way Laravel's router raises
     * NotFoundHttpException and MethodNotAllowedHttpException — the exception
     * handler turns both into responses, and 405 carries the Allow header a
     * client needs to correct itself.
     */
    public function match(Request $request): Route
    {
        $candidates = $this->static[$request->path] ?? null;

        if ($candidates === null) {
            if ($this->regex === null || preg_match($this->regex, $request->path, $found) !== 1) {
                throw HttpException::notFound();
            }

            $matched = $this->marks[(int) $found['MARK']];
            $candidates = $matched['routes'];

            // Laravel's Route::bind(): positional captures become named
            // parameters. Resolving them into models is SubstituteBindings'
            // job, further down the stack.
            foreach ($matched['params'] as $position => $name) {
                $request->parameters[$name] = $found[$position + 1];
            }
        }

        // HEAD is served by the GET route, as it is in Laravel and Symfony;
        // Response::prepare() is what drops the body.
        $route = $candidates[$request->method]
            ?? ($request->method === 'HEAD' ? ($candidates['GET'] ?? null) : null);

        if ($route === null) {
            throw HttpException::methodNotAllowed(array_keys($candidates));
        }

        $request->route = $route;

        return $route;
    }

    /** Literal segments are quoted; `{name}` becomes one greedy non-slash capture. */
    private static function pattern(string $uri): string
    {
        $pattern = '';

        foreach (preg_split('/(\{\w+\})/', $uri, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            $pattern .= $part[0] === '{' ? '([^/]++)' : preg_quote($part, '#');
        }

        return $pattern;
    }
}
