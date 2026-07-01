<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\CleanupResult;
use App\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Scans the storage directory for expired PDF/sidecar pairs and deletes them.
 *
 * Intended to be invoked from a cron job via bin/cleanup.php.
 */
class CleanupJob
{
    /** ISO 8601 UTC format used by sidecar files. */
    private const DATETIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly Config          $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Run one cleanup cycle.
     *
     * Steps:
     *  1. Glob all *.json sidecar files in the storage directory.
     *  2. For each sidecar, parse expires_at; skip if not expired.
     *  3. For expired sidecars: record PDF file size, attempt to unlink both
     *     the PDF and the sidecar. Log + count any I/O failures.
     *  4. Log a summary of the run.
     *  5. Return a CleanupResult value object.
     *
     * @return CleanupResult
     */
    public function run(): CleanupResult
    {
        $deletedCount   = 0;
        $reclaimedBytes = 0;
        $errorCount     = 0;

        $utc     = new \DateTimeZone('UTC');
        $now     = new \DateTimeImmutable('now', $utc);
        $pattern = $this->config->storageDir . '/*.json';
        $sidecars = glob($pattern);

        if ($sidecars === false) {
            $sidecars = [];
        }

        foreach ($sidecars as $sidecarPath) {
            $raw = @file_get_contents($sidecarPath);
            if ($raw === false) {
                // Cannot read sidecar — skip silently (not an expired-file scenario)
                continue;
            }

            $data = json_decode($raw, true);
            if (!\is_array($data) || !isset($data['expires_at'])) {
                continue;
            }

            $expiresAt = \DateTimeImmutable::createFromFormat(
                self::DATETIME_FORMAT,
                $data['expires_at'],
                $utc
            );

            if ($expiresAt === false) {
                continue;
            }

            // Only process files whose TTL has elapsed.
            if ($expiresAt >= $now) {
                continue;
            }

            // Derive the companion PDF path by replacing .json with .pdf.
            $pdfPath = \substr($sidecarPath, 0, -5) . '.pdf';

            // Record the PDF size before deletion (0 if file already absent).
            $pdfSize = is_file($pdfPath) ? (int) @\filesize($pdfPath) : 0;
            if ($pdfSize < 0) {
                $pdfSize = 0;
            }

            $pdfDeleted     = $this->unlinkFile($pdfPath,     $errorCount);
            $sidecarDeleted = $this->unlinkFile($sidecarPath, $errorCount);

            // Only count as a successful deletion when both files are gone.
            if ($pdfDeleted && $sidecarDeleted) {
                $deletedCount++;
                $reclaimedBytes += $pdfSize;
            }
        }

        $result = new CleanupResult($deletedCount, $reclaimedBytes, $errorCount);

        $this->logger->info('Cleanup run', [
            'deleted_count'    => $result->deletedCount,
            'reclaimed_bytes'  => $result->reclaimedBytes,
        ]);

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Attempt to unlink a file, logging a warning on failure.
     *
     * Uses the error-suppression operator so PHP does not emit an E_WARNING
     * to the output stream; the boolean return value is used to detect failures.
     *
     * @param string $path       Absolute path of the file to delete.
     * @param int    &$errorCount Reference counter incremented on failure.
     * @return bool True when the file was deleted (or did not exist); false on error.
     */
    private function unlinkFile(string $path, int &$errorCount): bool
    {
        // If the file does not exist treat it as already deleted — not an error.
        if (!\file_exists($path)) {
            return true;
        }

        $ok = @\unlink($path);

        if (!$ok) {
            $errorCount++;
            $this->logger->warning('Cleanup I/O error', [
                'filename' => \basename($path),
                'error'    => 'unlink failed',
            ]);
        }

        return $ok;
    }
}
