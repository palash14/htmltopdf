<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when request input fails validation.
 *
 * HTTP status is set by the thrower:
 *   - 400 when a required field is missing
 *   - 422 when the field is present but semantically invalid
 */
class ValidationException extends \RuntimeException
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
