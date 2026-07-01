<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when wkhtmltopdf exceeds the configured rendering timeout.
 * Takes precedence over RendererException (502) for the same request.
 *
 * Always maps to HTTP 504.
 */
class RendererTimeoutException extends \RuntimeException
{
    public function __construct(
        string $message = 'PDF rendering timed out',
        \Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 504;
    }
}
