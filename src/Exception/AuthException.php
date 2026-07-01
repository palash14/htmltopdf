<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when a request fails authentication.
 *
 * HTTP status is set by the thrower:
 *   - 401 when the Authorization header is missing
 *   - 403 when the header is present but malformed or the token is invalid
 */
class AuthException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        \Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
