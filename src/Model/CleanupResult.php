<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Immutable value object representing the outcome of a single cleanup run.
 */
final class CleanupResult
{
    /**
     * @param int $deletedCount   Number of PDF/sidecar pairs successfully deleted.
     * @param int $reclaimedBytes Total bytes freed from deleted PDF files.
     * @param int $errorCount     Number of files that could not be deleted due to I/O errors.
     */
    public function __construct(
        public readonly int $deletedCount,
        public readonly int $reclaimedBytes,
        public readonly int $errorCount,
    ) {}
}
