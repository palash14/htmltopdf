<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when wkhtmltopdf exits with a non-zero code or produces
 * a zero-byte / missing output file.
 *
 * Always maps to HTTP 502.
 */
class RendererException extends \RuntimeException
{
    public function __construct(
        string $message = 'PDF rendering failed',
        \Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 502;
    }
}
