<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Response;
use Throwable;

/**
 * Turn a thrown thing into a response.
 *
 * Laravel's handler renders inside the routing pipeline rather than outside it,
 * so the response still unwinds back out through the middleware that had
 * already run — the session is still written, cookies are still encrypted. That
 * is why Pipeline holds one of these instead of the server catching at the edge.
 *
 * There is no report() step. Laravel's default handler writes to a log, which
 * is disk I/O on the failure path; a benchmark whose second test is largely
 * database failures would be measuring the logger.
 */
final class Handler
{
    public function render(Throwable $error): Response
    {
        if ($error instanceof HttpException) {
            $response = Response::json(['ok' => false, 'error' => $error->getMessage()], $error->status);

            foreach ($error->headers as $name => $value) {
                $response->header($name, $value);
            }

            return $response;
        }

        // A failing database must surface as a failed request rather than a
        // hung one — the original benchmark's second test was almost entirely
        // database timeouts, and they only mean something if k6 sees them.
        return Response::json(['ok' => false, 'error' => $error->getMessage()], 503);
    }
}
