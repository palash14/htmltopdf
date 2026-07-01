<?php

declare(strict_types=1);

namespace Tests\Unit\Job;

use App\Job\CleanupJob;
use App\Model\CleanupResult;
use App\Model\Config;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CleanupJob.
 *
 * Requirements: 8.2, 8.3, 8.7
 */
class CleanupJobTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @var string[] Temp directories to clean up after each test */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tmpDirs = [];
        parent::tearDown();
    }

    /**
     * Create a fresh writable temp directory and register it for cleanup.
     */
    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/cleanup_unit_' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /**
     * Recursively delete a directory, restoring permissions as needed.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, 0777);
        $items = glob($dir . '/*');
        if ($items !== false) {
            foreach ($items as $item) {
                if (is_dir($item)) {
                    $this->removeDir($item);
                } else {
                    @chmod($item, 0666);
                    @unlink($item);
                }
            }
        }
        @rmdir($dir);
    }

    /**
     * Build a Config pointing at the given storage directory.
     */
    private function makeConfig(string $storageDir): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: ['key'],
            storageDir: $storageDir,
            baseUrl: 'https://example.com',
        );
    }

    /**
     * Create a Monolog Logger backed by a TestHandler; return both.
     *
     * @return array{Logger, TestHandler}
     */
    private function makeLogger(): array
    {
        $handler = new TestHandler();
        $logger  = new Logger('test', [$handler]);
        return [$logger, $handler];
    }

    /**
     * Write a PDF file and its companion sidecar JSON to $dir.
     *
     * @param string             $dir       Target storage directory
     * @param string             $filename  PDF filename (must end in .pdf)
     * @param \DateTimeImmutable $expiresAt Expiry timestamp to embed in the sidecar
     * @param int                $pdfSize   Number of bytes to write into the fake PDF
     * @return string                       Absolute path to the created PDF
     */
    private function createStoredFile(
        string $dir,
        string $filename,
        \DateTimeImmutable $expiresAt,
        int $pdfSize = 16,
    ): string {
        $pdfPath     = $dir . '/' . $filename;
        $sidecarPath = $dir . '/' . substr($filename, 0, -4) . '.json';
        $utc         = new \DateTimeZone('UTC');
        $now         = new \DateTimeImmutable('now', $utc);

        file_put_contents($pdfPath, str_repeat('X', $pdfSize));
        $sidecar = [
            'created_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
            'source_url' => 'https://example.com',
        ];
        file_put_contents($sidecarPath, json_encode($sidecar));

        return $pdfPath;
    }

    /**
     * Generate a valid hex-based PDF filename.
     */
    private function makePdfFilename(): string
    {
        return bin2hex(random_bytes(20)) . '.pdf';
    }

    // -----------------------------------------------------------------------
    // Test: run() on an empty storage directory
    // -----------------------------------------------------------------------

    /**
     * run() on an empty directory returns a zero-count CleanupResult and emits
     * a summary log entry with zero metrics.
     *
     * Requirements: 8.2, 8.3
     */
    public function testRunOnEmptyDirectoryReturnsZeroResult(): void
    {
        $dir = $this->makeTempDir();
        [$logger, $handler] = $this->makeLogger();

        $job    = new CleanupJob($this->makeConfig($dir), $logger);
        $result = $job->run();

        $this->assertInstanceOf(CleanupResult::class, $result);
        $this->assertSame(0, $result->deletedCount,    'deletedCount must be 0 for empty dir');
        $this->assertSame(0, $result->reclaimedBytes,  'reclaimedBytes must be 0 for empty dir');
        $this->assertSame(0, $result->errorCount,      'errorCount must be 0 for empty dir');

        // A summary log entry must still be written
        $this->assertTrue(
            $handler->hasInfoThatContains('Cleanup run'),
            '"Cleanup run" log entry must be written even when nothing was deleted'
        );
    }

    // -----------------------------------------------------------------------
    // Test: Expired files are deleted
    // -----------------------------------------------------------------------

    /**
     * Files with a past expiry timestamp (both PDF and sidecar) are removed
     * from the filesystem after run().
     *
     * Requirements: 8.2
     */
    public function testExpiredFilesAreDeleted(): void
    {
        $dir = $this->makeTempDir();
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);

        $filename    = $this->makePdfFilename();
        $expiry      = $now->modify('-1 hour');
        $pdfPath     = $this->createStoredFile($dir, $filename, $expiry);
        $sidecarPath = $dir . '/' . substr($filename, 0, -4) . '.json';

        [$logger] = $this->makeLogger();
        $job    = new CleanupJob($this->makeConfig($dir), $logger);
        $result = $job->run();

        $this->assertFileDoesNotExist($pdfPath,     'Expired PDF must be deleted');
        $this->assertFileDoesNotExist($sidecarPath, 'Expired sidecar JSON must be deleted');
        $this->assertSame(1, $result->deletedCount, 'deletedCount must be 1');
    }

    /**
     * Multiple expired file pairs are all deleted; deletedCount and
     * reclaimedBytes reflect every deleted pair.
     *
     * Requirements: 8.2, 8.3
     */
    public function testMultipleExpiredFilesAreAllDeleted(): void
    {
        $dir = $this->makeTempDir();
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);

        $pdfSize  = 50;
        $pdfPaths = [];
        for ($i = 0; $i < 3; $i++) {
            $filename   = $this->makePdfFilename();
            $expiry     = $now->modify('-2 hours');
            $pdfPaths[] = $this->createStoredFile($dir, $filename, $expiry, $pdfSize);
        }

        [$logger] = $this->makeLogger();
        $result = (new CleanupJob($this->makeConfig($dir), $logger))->run();

        foreach ($pdfPaths as $pdfPath) {
            $this->assertFileDoesNotExist($pdfPath, 'Each expired PDF must be deleted');
        }
        $this->assertSame(3, $result->deletedCount,    'deletedCount must be 3');
        $this->assertSame(150, $result->reclaimedBytes, 'reclaimedBytes must equal 3 × 50 bytes');
    }

    // -----------------------------------------------------------------------
    // Test: Non-expired files are untouched
    // -----------------------------------------------------------------------

    /**
     * Files with a future expiry timestamp must remain on the filesystem after
     * run() and must NOT be counted as deleted.
     *
     * Requirements: 8.2
     */
    public function testNonExpiredFilesAreUntouched(): void
    {
        $dir = $this->makeTempDir();
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);

        $filename    = $this->makePdfFilename();
        $expiry      = $now->modify('+1 hour');
        $pdfPath     = $this->createStoredFile($dir, $filename, $expiry);
        $sidecarPath = $dir . '/' . substr($filename, 0, -4) . '.json';

        [$logger] = $this->makeLogger();
        $result = (new CleanupJob($this->makeConfig($dir), $logger))->run();

        $this->assertFileExists($pdfPath,     'Non-expired PDF must NOT be deleted');
        $this->assertFileExists($sidecarPath, 'Non-expired sidecar must NOT be deleted');
        $this->assertSame(0, $result->deletedCount, 'deletedCount must be 0');
        $this->assertSame(0, $result->reclaimedBytes);
    }

    /**
     * Mixed scenario: expired files are deleted, non-expired files remain.
     *
     * Requirements: 8.2
     */
    public function testMixedExpiryScenario(): void
    {
        $dir = $this->makeTempDir();
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);

        // One expired pair
        $expiredFilename = $this->makePdfFilename();
        $expiredPdfPath  = $this->createStoredFile($dir, $expiredFilename, $now->modify('-1 hour'));

        // One fresh pair
        $freshFilename    = $this->makePdfFilename();
        $freshPdfPath     = $this->createStoredFile($dir, $freshFilename, $now->modify('+1 hour'));
        $freshSidecarPath = $dir . '/' . substr($freshFilename, 0, -4) . '.json';

        [$logger] = $this->makeLogger();
        $result = (new CleanupJob($this->makeConfig($dir), $logger))->run();

        $this->assertFileDoesNotExist($expiredPdfPath, 'Expired PDF must be deleted');
        $this->assertFileExists($freshPdfPath,         'Fresh PDF must remain');
        $this->assertFileExists($freshSidecarPath,     'Fresh sidecar must remain');
        $this->assertSame(1, $result->deletedCount);
    }

    // -----------------------------------------------------------------------
    // Test: I/O error on one file does not stop others
    // -----------------------------------------------------------------------

    /**
     * When an I/O error occurs on one expired file (simulated by placing a
     * directory where the PDF should be), the cleanup job continues and still
     * deletes all other expired files.
     *
     * Requirements: 8.7
     */
    public function testIoErrorOnOneFileDoesNotStopOthers(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped(
                'Directory-as-file I/O error simulation is not reliable on Windows.'
            );
        }

        $dir = $this->makeTempDir();
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);

        // File 1: will cause an I/O error (directory instead of PDF)
        $blockedFilename = $this->makePdfFilename();
        $sidecarPath1    = $dir . '/' . substr($blockedFilename, 0, -4) . '.json';
        $sidecar         = [
            'created_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->modify('-1 hour')->format('Y-m-d\TH:i:s\Z'),
            'source_url' => 'https://example.com',
        ];
        file_put_contents($sidecarPath1, json_encode($sidecar));
        $pdfDirPath = $dir . '/' . $blockedFilename;
        mkdir($pdfDirPath, 0777, true);

        // File 2: normal expired file — must be deleted despite file 1's error
        $normalFilename = $this->makePdfFilename();
        $normalPdfPath  = $this->createStoredFile($dir, $normalFilename, $now->modify('-1 hour'), 32);
        $normalJsonPath = $dir . '/' . substr($normalFilename, 0, -4) . '.json';

        [$logger, $handler] = $this->makeLogger();
        $result = (new CleanupJob($this->makeConfig($dir), $logger))->run();

        // The normal file must be gone
        $this->assertFileDoesNotExist($normalPdfPath,  'Normal expired PDF must be deleted');
        $this->assertFileDoesNotExist($normalJsonPath,  'Normal expired sidecar must be deleted');

        // The blocked entry must NOT count as a successful deletion
        $this->assertSame(1, $result->deletedCount,
            'deletedCount must be 1 (only the successfully deleted normal file)');

        // There must be a warning log about the blocked file
        $this->assertTrue(
            $handler->hasWarningThatContains($blockedFilename),
            "A warning log entry must reference the blocked filename '{$blockedFilename}'"
        );

        $this->assertGreaterThan(0, $result->errorCount,
            'errorCount must be > 0 when an I/O error occurred');

        // Cleanup the directory stub
        @rmdir($pdfDirPath);
        @unlink($sidecarPath1);
    }

    /**
     * When one file fails with an I/O error, the cleanup job logs the filename
     * and the error, and errorCount is incremented for each failure.
     *
     * Requirements: 8.7
     */
    public function testIoErrorIsLoggedWithFilenameAndError(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped(
                'Directory-as-file I/O error simulation is not reliable on Windows.'
            );
        }

        $dir = $this->makeTempDir();
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);

        // Set up a blocked expired file (directory as PDF)
        $filename = $this->makePdfFilename();
        $sidecar  = [
            'created_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->modify('-1 hour')->format('Y-m-d\TH:i:s\Z'),
            'source_url' => 'https://example.com',
        ];
        file_put_contents($dir . '/' . substr($filename, 0, -4) . '.json', json_encode($sidecar));
        mkdir($dir . '/' . $filename, 0777, true);

        [$logger, $handler] = $this->makeLogger();
        $result = (new CleanupJob($this->makeConfig($dir), $logger))->run();

        // There must be a warning log entry
        $warnings = array_filter(
            $handler->getRecords(),
            fn($r) => $r['level_name'] === 'WARNING'
        );
        $this->assertNotEmpty($warnings, 'A WARNING log entry must be emitted on I/O error');

        // The warning must mention the filename
        $found = false;
        foreach ($warnings as $record) {
            if (str_contains(json_encode($record['context'] ?? []), $filename)
                || str_contains((string) $record['message'], $filename)
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found,
            "The warning log must reference the blocked filename '{$filename}'");

        $this->assertGreaterThan(0, $result->errorCount,
            'errorCount must be incremented for the I/O error');

        // Cleanup
        @rmdir($dir . '/' . $filename);
        @unlink($dir . '/' . substr($filename, 0, -4) . '.json');
    }

    // -----------------------------------------------------------------------
    // Test: Log fields match actual counts / bytes
    // -----------------------------------------------------------------------

    /**
     * The "Cleanup run" log entry's context fields (deleted_count,
     * reclaimed_bytes) match the values in the returned CleanupResult.
     *
     * Requirements: 8.3
     */
    public function testLogFieldsMatchActualCountsAndBytes(): void
    {
        $dir = $this->makeTempDir();
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);

        // Two expired files of 40 bytes each
        for ($i = 0; $i < 2; $i++) {
            $this->createStoredFile($dir, $this->makePdfFilename(), $now->modify('-1 hour'), 40);
        }
        // One fresh file (must be ignored)
        $this->createStoredFile($dir, $this->makePdfFilename(), $now->modify('+1 hour'), 100);

        [$logger, $handler] = $this->makeLogger();
        $result = (new CleanupJob($this->makeConfig($dir), $logger))->run();

        // Find the "Cleanup run" log entry
        $summaryRecord = null;
        foreach ($handler->getRecords() as $record) {
            if (str_contains((string) $record['message'], 'Cleanup run')) {
                $summaryRecord = $record;
                break;
            }
        }
        $this->assertNotNull($summaryRecord, '"Cleanup run" log entry must exist');

        $ctx = $summaryRecord['context'];
        $this->assertSame(
            $result->deletedCount,
            $ctx['deleted_count'],
            'Log deleted_count must match CleanupResult::deletedCount'
        );
        $this->assertSame(
            $result->reclaimedBytes,
            $ctx['reclaimed_bytes'],
            'Log reclaimed_bytes must match CleanupResult::reclaimedBytes'
        );

        // Verify the expected absolute values too
        $this->assertSame(2, $result->deletedCount);
        $this->assertSame(80, $result->reclaimedBytes);
    }

    /**
     * reclaimedBytes matches the sum of PDF file sizes of the deleted files
     * (not including the sidecar JSON sizes).
     *
     * Requirements: 8.3
     */
    public function testReclaimedBytesCountsOnlyPdfSizes(): void
    {
        $dir = $this->makeTempDir();
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);

        // Create one expired file with a known PDF size
        $pdfSize  = 73;
        $filename = $this->makePdfFilename();
        $this->createStoredFile($dir, $filename, $now->modify('-1 hour'), $pdfSize);

        [$logger] = $this->makeLogger();
        $result = (new CleanupJob($this->makeConfig($dir), $logger))->run();

        $this->assertSame($pdfSize, $result->reclaimedBytes,
            "reclaimedBytes must equal the PDF size ({$pdfSize}), not include the sidecar");
    }

    // -----------------------------------------------------------------------
    // Test: Sidecar-only entries (PDF already absent) are handled gracefully
    // -----------------------------------------------------------------------

    /**
     * If only the sidecar JSON is present (PDF already deleted by another
     * process), the cleanup job still processes the entry without errors.
     *
     * Requirements: 8.7
     */
    public function testSidecarOnlyEntryIsHandledWithoutError(): void
    {
        $dir = $this->makeTempDir();
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);

        // Write only the sidecar, not the PDF
        $filename = $this->makePdfFilename();
        $sidecar  = [
            'created_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->modify('-1 hour')->format('Y-m-d\TH:i:s\Z'),
            'source_url' => 'https://example.com',
        ];
        $sidecarPath = $dir . '/' . substr($filename, 0, -4) . '.json';
        file_put_contents($sidecarPath, json_encode($sidecar));
        // Intentionally no PDF file

        [$logger, $handler] = $this->makeLogger();
        $result = (new CleanupJob($this->makeConfig($dir), $logger))->run();

        // No errors should be emitted since the PDF simply didn't exist
        $this->assertFalse(
            $handler->hasWarnings(),
            'No warnings should be logged when the PDF was already absent'
        );
        $this->assertFileDoesNotExist($sidecarPath, 'Orphan sidecar must be cleaned up');
    }

    // -----------------------------------------------------------------------
    // Test: Malformed or unreadable sidecar is silently skipped
    // -----------------------------------------------------------------------

    /**
     * A sidecar file that contains invalid JSON is silently skipped; the
     * file remains on disk and the cleanup result is not affected.
     *
     * Requirements: 8.2
     */
    public function testMalformedSidecarIsSkipped(): void
    {
        $dir = $this->makeTempDir();

        // Write a malformed sidecar
        $basename    = bin2hex(random_bytes(10));
        $sidecarPath = $dir . '/' . $basename . '.json';
        file_put_contents($sidecarPath, 'NOT VALID JSON {{{{');

        [$logger] = $this->makeLogger();
        $result = (new CleanupJob($this->makeConfig($dir), $logger))->run();

        // The malformed sidecar must not be deleted and must not cause errors
        $this->assertFileExists($sidecarPath,
            'Malformed sidecar must remain; job must skip it gracefully');
        $this->assertSame(0, $result->deletedCount);
        $this->assertSame(0, $result->errorCount);
    }

    // -----------------------------------------------------------------------
    // Test: Invalid expires_at format is skipped
    // -----------------------------------------------------------------------

    /**
     * A sidecar whose expires_at field contains a date string that does not
     * match the expected ISO-8601-UTC format causes DateTimeImmutable::createFromFormat
     * to return false, and the job silently skips that entry.
     *
     * This covers the `if ($expiresAt === false) { continue; }` branch in CleanupJob::run().
     *
     * Requirements: 8.2
     */
    public function testInvalidExpiresAtFormatIsSkipped(): void
    {
        $dir = $this->makeTempDir();

        $basename    = bin2hex(random_bytes(10));
        $pdfPath     = $dir . '/' . $basename . '.pdf';
        $sidecarPath = $dir . '/' . $basename . '.json';

        file_put_contents($pdfPath, 'fake pdf content');
        file_put_contents($sidecarPath, json_encode([
            'created_at' => '2024-01-01T00:00:00Z',
            'expires_at' => 'not-a-valid-date-format',
            'source_url' => 'https://example.com',
        ]));

        [$logger] = $this->makeLogger();
        $result = (new CleanupJob($this->makeConfig($dir), $logger))->run();

        $this->assertFileExists($pdfPath,
            'PDF must NOT be deleted when expires_at is unparseable');
        $this->assertFileExists($sidecarPath,
            'Sidecar must NOT be deleted when expires_at is unparseable');
        $this->assertSame(0, $result->deletedCount,
            'deletedCount must be 0 for unparseable sidecar');
        $this->assertSame(0, $result->errorCount,
            'errorCount must be 0 for unparseable sidecar');
    }
}
