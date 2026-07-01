<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when the storage directory meets or exceeds the configured
 * maximum size and cannot accept new PDF files.
 *
 * Always maps to HTTP 507 (Insufficient Storage).
 */
class StorageFullException extends \RuntimeException
{
    public function __construct(
        string $message = 'Storage capacity exceeded',
        \Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 507;
    }
}
