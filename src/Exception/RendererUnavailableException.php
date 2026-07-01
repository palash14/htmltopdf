<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when wkhtmltopdf is not found or is not executable on the server.
 *
 * Always maps to HTTP 500.
 */
class RendererUnavailableException extends \RuntimeException
{
    public function __construct(
        string $message = 'PDF renderer is unavailable',
        \Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 500;
    }
}
