<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A failure that already knows the status code it should become. */
final class HttpException extends RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Nothing is served at that path.'): self
    {
        return new self(404, $message);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(
            405,
            'That method is not allowed here.',
            // A 405 without Allow is a dead end for the client; both Laravel
            // and Symfony send it.
            ['Allow' => implode(', ', $allowed)],
        );
    }
}
