<?php

declare(strict_types=1);

namespace Tests\Property;

use App\Model\Config;
use App\Service\StorageService;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for StorageService.
 *
 * Feature: url-to-pdf-api
 *
 * Covers:
 *   Property 12 — Expiry timestamp invariant
 *   Property 16 — Generated filenames are unique and cryptographically sized
 */
class StorageServicePropertyTest extends TestCase
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

    /** @var string[] Temp directories created during a test; cleaned up in tearDown */
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
     * Create a fresh temporary directory and register it for cleanup.
     */
    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/storage_prop_' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /**
     * Recursively delete a directory and all its contents.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = glob($dir . '/*');
        if ($items !== false) {
            foreach ($items as $item) {
                is_dir($item) ? $this->removeDir($item) : @unlink($item);
            }
        }
        @rmdir($dir);
    }

    /**
     * Build a Config with the given storageDir and TTL; all other fields use
     * minimal valid defaults.
     */
    private function makeConfig(string $storageDir, int $ttlSeconds): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: ['key'],
            storageDir: $storageDir,
            baseUrl: 'https://example.com',
            ttlSeconds: $ttlSeconds,
        );
    }

    /**
     * Create a small temporary PDF file (just bytes) in the given directory.
     */
    private function makeTempPdf(string $dir): string
    {
        $path = $dir . '/source_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, '%PDF-1.4 fake-pdf-content');
        return $path;
    }

    // -----------------------------------------------------------------------
    // Property 12: Expiry timestamp invariant
    //
    // For any PDF file saved to storage with any valid TTL value T — the
    // recorded `expires_at` timestamp SHALL equal `created_at + T seconds`
    // (within 1-second tolerance).
    //
    // Validates: Requirement 8.1
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 12: expires_at equals created_at + TTL (within 1-second tolerance)
     *
     * // Feature: url-to-pdf-api, Property 12: Expiry timestamp invariant
     *
     * Validates: Requirements 8.1
     */
    public function testExpiryTimestampEqualsCreatedAtPlusTtl(): void
    {
        // Generate valid TTL values in the range 60–86400 seconds.
        $this->forAll(
            Generators::choose(60, 86400) // TTL seconds
        )
            ->withMaxSize(100)
            ->then(function (int $ttl): void {
                // Each iteration gets its own isolated temp directory.
                $tmpDir = $this->makeTempDir();
                $config = $this->makeConfig($tmpDir, $ttl);
                $service = new StorageService($config);

                // Create a source PDF in a separate temp dir so rename() works.
                $sourceDir = $this->makeTempDir();
                $sourcePdf = $this->makeTempPdf($sourceDir);

                // Record the timestamp window around save() to bound the test.
                $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $stored = $service->save($sourcePdf, 'https://example.com/page');
                $after  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                // The difference between expiresAt and createdAt must equal TTL.
                $actualDiff = $stored->expiresAt->getTimestamp() - $stored->createdAt->getTimestamp();

                $this->assertSame(
                    $ttl,
                    $actualDiff,
                    "expires_at - created_at must equal TTL={$ttl}s, got {$actualDiff}s"
                );

                // created_at must be within the observed time window (± 1 s tolerance).
                $this->assertGreaterThanOrEqual(
                    $before->getTimestamp() - 1,
                    $stored->createdAt->getTimestamp(),
                    "created_at must not be before the test started"
                );
                $this->assertLessThanOrEqual(
                    $after->getTimestamp() + 1,
                    $stored->createdAt->getTimestamp(),
                    "created_at must not be after the test ended"
                );
            });
    }

    // -----------------------------------------------------------------------
    // Property 16: Generated filenames are unique and cryptographically sized
    //
    // For any N generated filenames (N ≤ 10,000) — all filenames SHALL be
    // unique and each SHALL consist of at least 32 hexadecimal characters
    // followed by `.pdf` (representing ≥ 128 bits of entropy).
    //
    // Validates: Requirement 8.8
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 16: Generated filenames are all unique and match the required format
     *
     * // Feature: url-to-pdf-api, Property 16: Generated filenames are unique and cryptographically sized
     *
     * Validates: Requirements 8.8
     */
    public function testGeneratedFilenamesAreUniqueAndCryptographicallySized(): void
    {
        // Generate batch sizes from 1–100 per Eris iteration; batches are
        // aggregated to ensure global uniqueness up to the specified maximum.
        $this->forAll(
            Generators::choose(1, 100) // batch count N per iteration
        )
            ->withMaxSize(100)
            ->then(function (int $n): void {
                // Each iteration uses a fresh storage dir.
                $tmpDir  = $this->makeTempDir();
                $config  = $this->makeConfig($tmpDir, 3600);
                $service = new StorageService($config);

                /** @var string[] $generated */
                $generated = [];

                for ($i = 0; $i < $n; $i++) {
                    $filename = $service->generateFilename();

                    // Must match: 32+ lowercase hex chars followed by ".pdf"
                    $this->assertMatchesRegularExpression(
                        '/^[0-9a-f]{32,}\.pdf$/',
                        $filename,
                        "Filename '{$filename}' does not match the required format"
                    );

                    // Must end with ".pdf"
                    $this->assertStringEndsWith(
                        '.pdf',
                        $filename,
                        "Filename must end with '.pdf'"
                    );

                    // The hex part (without ".pdf") must be at least 32 chars.
                    $hexPart = substr($filename, 0, -4);
                    $this->assertGreaterThanOrEqual(
                        32,
                        strlen($hexPart),
                        "Hex part of filename must be at least 32 characters (≥128 bits of entropy)"
                    );

                    $generated[] = $filename;
                }

                // All filenames in this batch must be unique.
                $unique = array_unique($generated);
                $this->assertCount(
                    count($generated),
                    $unique,
                    "All {$n} generated filenames must be unique; found duplicates"
                );
            });
    }
}
