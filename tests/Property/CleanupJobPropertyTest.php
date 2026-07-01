<?php

declare(strict_types=1);

namespace Tests\Property;

use App\Job\CleanupJob;
use App\Model\Config;
use Eris\Generators;
use Eris\TestTrait;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for CleanupJob.
 *
 * Feature: url-to-pdf-api
 *
 * Covers:
 *   Property 13 — Cleanup removes all and only expired files
 *   Property 14 — Cleanup logs accurate deletion metrics
 *   Property 15 — Cleanup is resilient to per-file I/O errors
 */
class CleanupJobPropertyTest extends TestCase
{
    use TestTrait;

    // -----------------------------------------------------------------------
    // Eris / PHPUnit 10 compatibility shim
    // -----------------------------------------------------------------------

    /**
     * PHPUnit 10 removed getAnnotations() / PHPUnit\Util\Test::parseTestMethodAnnotations().
     * Override Eris's getTestCaseAnnotations() to return an empty structure so
     * Eris uses its defaults (100 iterations, rand method, 50% ratio).
     *
     * @return array<string, array<string, list<string>>>
     */
    public function getTestCaseAnnotations(): array
    {
        return ['method' => [], 'class' => []];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @var string[] Temp directories to remove during tearDown */
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
        $dir = sys_get_temp_dir() . '/cleanup_prop_' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /**
     * Recursively delete a directory and all its contents.
     * Restores file permissions before removing so we can clean up
     * directories created for I/O-error simulation.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        // Restore permissions so cleanup can proceed
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
     * Create a (PDF + sidecar JSON) pair in $dir and return the PDF path.
     *
     * @param string            $dir       Storage directory
     * @param string            $filename  PDF filename (e.g. "abc123.pdf")
     * @param \DateTimeImmutable $expiresAt Expiry to embed in the sidecar
     * @param int               $pdfSize   Number of bytes to write to the PDF
     */
    private function createStoredFile(
        string $dir,
        string $filename,
        \DateTimeImmutable $expiresAt,
        int $pdfSize = 16,
    ): string {
        $pdfPath     = $dir . '/' . $filename;
        $sidecarPath = $dir . '/' . substr($filename, 0, -4) . '.json';

        // Write fake PDF content
        file_put_contents($pdfPath, str_repeat('X', $pdfSize));

        // Write sidecar JSON
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        $sidecar = [
            'created_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
            'source_url' => 'https://example.com',
        ];
        file_put_contents($sidecarPath, json_encode($sidecar));

        return $pdfPath;
    }

    /**
     * Generate a hex-based PDF filename.
     */
    private function makePdfFilename(): string
    {
        return bin2hex(random_bytes(20)) . '.pdf';
    }

    /**
     * Build a Logger + TestHandler pair.
     *
     * @return array{Logger, TestHandler}
     */
    private function makeLogger(): array
    {
        $handler = new TestHandler();
        $logger  = new Logger('test', [$handler]);
        return [$logger, $handler];
    }

    // -----------------------------------------------------------------------
    // Property 13: Cleanup removes all and only expired files
    //
    // For any set of files with a mix of past and future expiry timestamps,
    // after one complete cleanup run, all files with past expiry SHALL be
    // deleted and all files with future expiry SHALL remain.
    //
    // Validates: Requirement 8.2
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 13: After one cleanup run, all expired files are deleted and
     * all non-expired files remain intact.
     *
     * // Feature: url-to-pdf-api, Property 13: Cleanup removes all and only expired files
     *
     * Validates: Requirements 8.2
     */
    public function testCleanupRemovesAllAndOnlyExpiredFiles(): void
    {
        $this->forAll(
            Generators::choose(0, 5),  // number of expired files
            Generators::choose(0, 5)   // number of non-expired files
        )
            ->withMaxSize(100)
            ->then(function (int $expiredCount, int $freshCount): void {
                // We need at least one file to test anything meaningful
                if ($expiredCount === 0 && $freshCount === 0) {
                    $expiredCount = 1;
                }

                $dir = $this->makeTempDir();
                $utc = new \DateTimeZone('UTC');
                $now = new \DateTimeImmutable('now', $utc);

                // Create expired files (expiry 1 hour in the past)
                $expiredPdfs  = [];
                $expiredJsons = [];
                for ($i = 0; $i < $expiredCount; $i++) {
                    $filename = $this->makePdfFilename();
                    $expiry   = $now->modify('-1 hour');
                    $this->createStoredFile($dir, $filename, $expiry);
                    $expiredPdfs[]  = $dir . '/' . $filename;
                    $expiredJsons[] = $dir . '/' . substr($filename, 0, -4) . '.json';
                }

                // Create non-expired files (expiry 1 hour in the future)
                $freshPdfs  = [];
                $freshJsons = [];
                for ($i = 0; $i < $freshCount; $i++) {
                    $filename = $this->makePdfFilename();
                    $expiry   = $now->modify('+1 hour');
                    $this->createStoredFile($dir, $filename, $expiry);
                    $freshPdfs[]  = $dir . '/' . $filename;
                    $freshJsons[] = $dir . '/' . substr($filename, 0, -4) . '.json';
                }

                [$logger] = $this->makeLogger();
                $job = new CleanupJob($this->makeConfig($dir), $logger);
                $job->run();

                // All expired files (PDF + sidecar) must be gone
                foreach ($expiredPdfs as $path) {
                    $this->assertFileDoesNotExist(
                        $path,
                        "Expired PDF '{$path}' must be deleted by cleanup"
                    );
                }
                foreach ($expiredJsons as $path) {
                    $this->assertFileDoesNotExist(
                        $path,
                        "Expired sidecar JSON '{$path}' must be deleted by cleanup"
                    );
                }

                // All non-expired files (PDF + sidecar) must still exist
                foreach ($freshPdfs as $path) {
                    $this->assertFileExists(
                        $path,
                        "Non-expired PDF '{$path}' must NOT be deleted by cleanup"
                    );
                }
                foreach ($freshJsons as $path) {
                    $this->assertFileExists(
                        $path,
                        "Non-expired sidecar JSON '{$path}' must NOT be deleted by cleanup"
                    );
                }
            });
    }

    // -----------------------------------------------------------------------
    // Property 14: Cleanup logs accurate deletion metrics
    //
    // For any cleanup run that deletes one or more files, the produced log
    // entry SHALL contain the exact count of deleted files and the exact
    // total bytes reclaimed.
    //
    // Validates: Requirement 8.3
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 14: Log entry contains the exact deleted_count and reclaimed_bytes
     * matching the actual files deleted.
     *
     * // Feature: url-to-pdf-api, Property 14: Cleanup logs accurate deletion metrics
     *
     * Validates: Requirements 8.3
     */
    public function testCleanupLogsAccurateDeletionMetrics(): void
    {
        $this->forAll(
            Generators::choose(1, 5),   // number of expired files
            Generators::choose(1, 100)  // PDF size in bytes per file
        )
            ->withMaxSize(100)
            ->then(function (int $expiredCount, int $pdfSize): void {
                $dir = $this->makeTempDir();
                $utc = new \DateTimeZone('UTC');
                $now = new \DateTimeImmutable('now', $utc);

                $totalExpectedBytes = 0;

                for ($i = 0; $i < $expiredCount; $i++) {
                    $filename = $this->makePdfFilename();
                    $expiry   = $now->modify('-1 hour');
                    $this->createStoredFile($dir, $filename, $expiry, $pdfSize);
                    $totalExpectedBytes += $pdfSize;
                }

                [$logger, $handler] = $this->makeLogger();
                $job    = new CleanupJob($this->makeConfig($dir), $logger);
                $result = $job->run();

                // Verify CleanupResult matches expected counts
                $this->assertSame(
                    $expiredCount,
                    $result->deletedCount,
                    "CleanupResult::deletedCount must equal the number of expired pairs deleted"
                );
                $this->assertSame(
                    $totalExpectedBytes,
                    $result->reclaimedBytes,
                    "CleanupResult::reclaimedBytes must equal the total bytes of deleted PDFs"
                );

                // Verify log entry has the correct context values
                $records = $handler->getRecords();
                $this->assertNotEmpty($records, 'At least one log record must be emitted');

                // Find the "Cleanup run" summary log entry
                $summaryRecord = null;
                foreach ($records as $record) {
                    if (str_contains((string) $record['message'], 'Cleanup run')) {
                        $summaryRecord = $record;
                        break;
                    }
                }
                $this->assertNotNull($summaryRecord, '"Cleanup run" log entry must exist');

                $context = $summaryRecord['context'];
                $this->assertArrayHasKey('deleted_count', $context,
                    'Log entry must contain deleted_count');
                $this->assertArrayHasKey('reclaimed_bytes', $context,
                    'Log entry must contain reclaimed_bytes');

                $this->assertSame(
                    $expiredCount,
                    $context['deleted_count'],
                    "Log deleted_count must equal {$expiredCount}, got {$context['deleted_count']}"
                );
                $this->assertSame(
                    $totalExpectedBytes,
                    $context['reclaimed_bytes'],
                    "Log reclaimed_bytes must equal {$totalExpectedBytes}, got {$context['reclaimed_bytes']}"
                );
            });
    }

    // -----------------------------------------------------------------------
    // Property 15: Cleanup is resilient to per-file I/O errors
    //
    // For any cleanup run where a subset of expired files produce I/O errors
    // on deletion, the cleanup job SHALL still delete all other expired files
    // and SHALL log each failing file individually.
    //
    // Validates: Requirement 8.7
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 15: I/O errors on some files do not prevent deletion of others;
     * each failing file is logged individually.
     *
     * // Feature: url-to-pdf-api, Property 15: Cleanup is resilient to per-file I/O errors
     *
     * Validates: Requirements 8.7
     *
     * I/O error simulation strategy: replace the expected PDF file path with a
     * directory of the same name. PHP's unlink() cannot remove a directory, so
     * it returns false — which CleanupJob treats as an I/O error and logs.
     * The sidecar JSON remains deletable, and the test verifies that other
     * (non-blocked) expired files are fully deleted.
     *
     * Note: This test is skipped on Windows because directory-as-file tricks
     * behave differently; the underlying resilience behaviour is covered by
     * the unit tests instead.
     */
    public function testCleanupIsResilientToPerFileIoErrors(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped(
                'Directory-as-file I/O error simulation is not supported on Windows. ' .
                'Resilience is verified by the unit tests instead.'
            );
        }

        $this->forAll(
            Generators::choose(1, 4),  // number of blocked (I/O error) expired files
            Generators::choose(1, 4)   // number of normal expired files
        )
            ->withMaxSize(100)
            ->then(function (int $blockedCount, int $normalCount): void {
                $dir = $this->makeTempDir();
                $utc = new \DateTimeZone('UTC');
                $now = new \DateTimeImmutable('now', $utc);

                // Create "blocked" expired files: put a directory where the PDF would be
                $blockedFilenames = [];
                for ($i = 0; $i < $blockedCount; $i++) {
                    $filename    = $this->makePdfFilename();
                    $expiry      = $now->modify('-1 hour');
                    $sidecarPath = $dir . '/' . substr($filename, 0, -4) . '.json';

                    // Write sidecar only — then put a directory in place of the PDF
                    $sidecar = [
                        'created_at' => $now->format('Y-m-d\TH:i:s\Z'),
                        'expires_at' => $expiry->format('Y-m-d\TH:i:s\Z'),
                        'source_url' => 'https://example.com',
                    ];
                    file_put_contents($sidecarPath, json_encode($sidecar));

                    // Create a directory with the PDF filename — unlink() will fail
                    $pdfDirPath = $dir . '/' . $filename;
                    mkdir($pdfDirPath, 0777, true);

                    $blockedFilenames[] = $filename;
                }

                // Create normal expired files (should be deleted successfully)
                $normalPdfs  = [];
                $normalJsons = [];
                for ($i = 0; $i < $normalCount; $i++) {
                    $filename = $this->makePdfFilename();
                    $expiry   = $now->modify('-1 hour');
                    $this->createStoredFile($dir, $filename, $expiry);
                    $normalPdfs[]  = $dir . '/' . $filename;
                    $normalJsons[] = $dir . '/' . substr($filename, 0, -4) . '.json';
                }

                [$logger, $handler] = $this->makeLogger();
                $job = new CleanupJob($this->makeConfig($dir), $logger);
                $job->run();

                // Normal expired files must all be deleted
                foreach ($normalPdfs as $path) {
                    $this->assertFileDoesNotExist(
                        $path,
                        "Normal expired PDF '{$path}' must be deleted despite other I/O errors"
                    );
                }
                foreach ($normalJsons as $path) {
                    $this->assertFileDoesNotExist(
                        $path,
                        "Normal expired sidecar '{$path}' must be deleted despite other I/O errors"
                    );
                }

                // Each blocked file must produce at least one warning log entry
                $warningRecords = array_filter(
                    $handler->getRecords(),
                    static fn($r) => $r['level'] === \Monolog\Level::Warning
                        || $r['level_name'] === 'WARNING'
                        || (isset($r['level']) && $r['level'] >= 300 && $r['level'] < 400)
                );

                // There must be warning log entries (one per blocked file's failure)
                $this->assertGreaterThanOrEqual(
                    $blockedCount,
                    count($warningRecords),
                    "Must have at least {$blockedCount} warning log entries for blocked files; " .
                    'got ' . count($warningRecords)
                );

                // Each blocked filename must appear in at least one warning
                foreach ($blockedFilenames as $blockedFilename) {
                    $found = false;
                    foreach ($warningRecords as $record) {
                        $contextStr = json_encode($record['context'] ?? []);
                        if (str_contains($contextStr, $blockedFilename)
                            || str_contains((string) $record['message'], $blockedFilename)
                        ) {
                            $found = true;
                            break;
                        }
                    }
                    $this->assertTrue(
                        $found,
                        "Filename '{$blockedFilename}' must appear in at least one warning log entry"
                    );
                }

                // Clean up: remove the directories we created as PDF stubs
                foreach ($blockedFilenames as $blockedFilename) {
                    $pdfDirPath = $dir . '/' . $blockedFilename;
                    if (is_dir($pdfDirPath)) {
                        @rmdir($pdfDirPath);
                    }
                    // Remove the sidecar if still present
                    $sidecarPath = $dir . '/' . substr($blockedFilename, 0, -4) . '.json';
                    @unlink($sidecarPath);
                }
            });
    }
}
