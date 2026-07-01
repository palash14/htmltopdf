<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when the rendering queue is full or a queued request times out
 * waiting for a renderer slot.
 *
 * Always maps to HTTP 503.
 */
class ConcurrencyException extends \RuntimeException
{
    public function __construct(
        string $message = 'Service temporarily unavailable',
        \Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 503;
    }
}
