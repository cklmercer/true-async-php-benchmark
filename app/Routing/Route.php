<?php

declare(strict_types=1);

namespace App\Routing;

use Closure;

/**
 * One registered route: what it matches, what runs, and how its parameters
 * turn into values.
 *
 * Laravel constructs one of these per definition at boot and keeps them in a
 * collection; a matched request carries the route it matched so later
 * middleware can ask about it. Notably SubstituteBindings, which needs the
 * route's declared bindings to have anything to resolve.
 */
final class Route
{
    /**
     * Parameter name => resolver, declared by bind().
     *
     * Laravel's implicit binding infers the resolver from the controller's
     * type hints via reflection. Declaring it is the same work without the
     * reflection, and reflection autowiring is a cost this benchmark states
     * plainly that it does not pay.
     *
     * @var array<string,Closure(string,\App\Http\Request):mixed>
     */
    public array $bindings = [];

    /** @var list<string> In the order they appear in the URI. */
    public readonly array $parameterNames;

    /** @param Closure(\App\Http\Request):\App\Http\Response $action */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly Closure $action,
    ) {
        preg_match_all('/\{(\w+)\}/', $uri, $found);

        $this->parameterNames = $found[1];
    }

    /** A route with no parameters can be matched by hash lookup instead of regex. */
    public function isStatic(): bool
    {
        return $this->parameterNames === [];
    }

    /** @param Closure(string,\App\Http\Request):mixed $resolver */
    public function bind(string $parameter, Closure $resolver): self
    {
        $this->bindings[$parameter] = $resolver;

        return $this;
    }
}
