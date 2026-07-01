<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when an API key exceeds its configured request-per-minute limit.
 *
 * Always maps to HTTP 429.
 */
class RateLimitException extends \RuntimeException
{
    public function __construct(
        string $message = 'Rate limit exceeded',
        \Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 429;
    }
}
