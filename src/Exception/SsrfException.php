<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when a submitted URL resolves to a private, loopback,
 * or link-local IP address (SSRF protection).
 *
 * Always maps to HTTP 422.
 */
class SsrfException extends \RuntimeException
{
    public function __construct(
        string $message = 'URL resolves to a disallowed IP address',
        \Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
