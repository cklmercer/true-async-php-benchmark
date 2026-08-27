<?php

declare(strict_types=1);

namespace App\Http;

use App\Contracts\Middleware;
use App\Exceptions\Handler;
use Closure;
use Throwable;

/**
 * Laravel's middleware onion: each layer may act before the next, after it, or
 * short-circuit it entirely.
 *
 * Built once and reused. Laravel rebuilds this closure chain per request and
 * resolves each middleware out of the container as it goes; composing it once
 * per worker is a deliberate deviation, and it is stated in the README rather
 * than hidden here.
 *
 * Every layer is wrapped in the exception handler, which is what
 * Illuminate\Routing\Pipeline does: an exception thrown deep in the stack is
 * rendered where it is thrown, so the response unwinds back out through the
 * layers that had already run. The session still gets written and the cookies
 * still get encrypted on a failed request — which is the behaviour the write
 * test depends on when the database starts timing out.
 */
final class Pipeline
{
    private readonly Closure $chain;

    /**
     * @param  list<Middleware>  $layers  outermost first
     * @param  Closure(Request):Response  $destination
     */
    public function __construct(array $layers, Closure $destination, Handler $handler)
    {
        $chain = $destination;

        foreach (array_reverse($layers) as $layer) {
            $next = $chain;

            $chain = static function (Request $request) use ($layer, $next, $handler): Response {
                try {
                    return $layer->handle($request, $next);
                } catch (Throwable $error) {
                    return $handler->render($error);
                }
            };
        }

        $this->chain = $chain;
    }

    public function handle(Request $request): Response
    {
        return ($this->chain)($request);
    }
}
