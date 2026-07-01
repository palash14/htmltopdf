<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Model\Config;
use App\Model\StoredFile;
use App\Service\StorageService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StorageService.
 *
 * Requirements: 4.2, 4.3, 8.1, 8.8
 */
class StorageServiceTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @var string[] Temp directories registered for cleanup */
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
        $dir = sys_get_temp_dir() . '/storage_unit_' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /**
     * Recursively remove a directory and all its contents.
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
     * Build a Config with sane defaults; override storageDir and ttlSeconds.
     */
    private function makeConfig(string $storageDir, int $ttlSeconds = 3600): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: ['test-key'],
            storageDir: $storageDir,
            baseUrl: 'https://example.com',
            ttlSeconds: $ttlSeconds,
        );
    }

    /**
     * Write a small fake PDF to $dir and return its path.
     */
    private function makeFakePdf(string $dir): string
    {
        $path = $dir . '/source_' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($path, '%PDF-1.4 fake content for test');
        return $path;
    }

    // -----------------------------------------------------------------------
    // 1. save() + find() round-trip
    // -----------------------------------------------------------------------

    /**
     * save() moves the PDF into storage and find() retrieves a StoredFile with
     * all expected fields populated.
     *
     * Requirements: 4.2, 4.3, 8.1
     */
    public function testSaveAndFindRoundTrip(): void
    {
        $storageDir = $this->makeTempDir();
        $sourceDir  = $this->makeTempDir();
        $service    = new StorageService($this->makeConfig($storageDir, 3600));

        $sourceFile = $this->makeFakePdf($sourceDir);
        $stored     = $service->save($sourceFile, 'https://example.com/page');

        // save() must return a populated StoredFile.
        $this->assertInstanceOf(StoredFile::class, $stored);
        $this->assertNotEmpty($stored->filename);
        $this->assertStringEndsWith('.pdf', $stored->filename);
        $this->assertFileExists($stored->path);
        $this->assertSame($storageDir . '/' . $stored->filename, $stored->path);
        $this->assertStringStartsWith('https://example.com/api/files/', $stored->downloadUrl);
        $this->assertStringEndsWith($stored->filename, $stored->downloadUrl);

        // find() must return an equivalent StoredFile.
        $found = $service->find($stored->filename);
        $this->assertNotNull($found, 'find() must return a StoredFile for a saved file');
        $this->assertSame($stored->filename, $found->filename);
        $this->assertSame($stored->path, $found->path);
        $this->assertSame($stored->downloadUrl, $found->downloadUrl);

        // Timestamps must agree within 1 second (round-trip through ISO 8601).
        $this->assertEqualsWithDelta(
            $stored->createdAt->getTimestamp(),
            $found->createdAt->getTimestamp(),
            1,
            'createdAt must survive sidecar round-trip'
        );
        $this->assertEqualsWithDelta(
            $stored->expiresAt->getTimestamp(),
            $found->expiresAt->getTimestamp(),
            1,
            'expiresAt must survive sidecar round-trip'
        );
    }

    // -----------------------------------------------------------------------
    // 2. Sidecar JSON written correctly
    // -----------------------------------------------------------------------

    /**
     * After save(), a companion .json sidecar exists alongside the PDF and
     * contains the required fields: created_at, expires_at, source_url.
     *
     * Requirements: 8.1
     */
    public function testSidecarJsonWrittenCorrectly(): void
    {
        $storageDir = $this->makeTempDir();
        $sourceDir  = $this->makeTempDir();
        $service    = new StorageService($this->makeConfig($storageDir, 1800));

        $stored     = $service->save($this->makeFakePdf($sourceDir), 'https://example.com/test');

        // Derive expected sidecar path.
        $sidecarPath = substr($stored->path, 0, -4) . '.json';
        $this->assertFileExists($sidecarPath, 'Sidecar .json file must be written alongside the PDF');

        $raw  = file_get_contents($sidecarPath);
        $data = json_decode($raw, true);

        $this->assertIsArray($data, 'Sidecar must contain valid JSON');
        $this->assertArrayHasKey('created_at', $data, 'Sidecar must contain "created_at"');
        $this->assertArrayHasKey('expires_at', $data, 'Sidecar must contain "expires_at"');
        $this->assertArrayHasKey('source_url', $data, 'Sidecar must contain "source_url"');

        // Timestamps must be parseable ISO 8601 UTC strings.
        $createdAt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $data['created_at'], new \DateTimeZone('UTC'));
        $expiresAt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $data['expires_at'], new \DateTimeZone('UTC'));
        $this->assertNotFalse($createdAt, 'created_at must be a valid ISO 8601 UTC string');
        $this->assertNotFalse($expiresAt, 'expires_at must be a valid ISO 8601 UTC string');

        // expires_at - created_at must equal the configured TTL (1800 s).
        $diff = $expiresAt->getTimestamp() - $createdAt->getTimestamp();
        $this->assertSame(1800, $diff, 'expires_at must be exactly created_at + TTL');
    }

    // -----------------------------------------------------------------------
    // 3. api_key query parameter is redacted from source_url
    // -----------------------------------------------------------------------

    /**
     * When save() is called with a URL that contains an api_key query
     * parameter, the stored source_url must not contain the api_key value.
     *
     * Requirements: 4.3
     */
    public function testApiKeyQueryParamIsRedactedFromSourceUrl(): void
    {
        $storageDir = $this->makeTempDir();
        $sourceDir  = $this->makeTempDir();
        $service    = new StorageService($this->makeConfig($storageDir));

        $secretKey  = 'super-secret-key-abc123';
        $sourceUrl  = "https://example.com/?api_key={$secretKey}&other=1";

        $stored     = $service->save($this->makeFakePdf($sourceDir), $sourceUrl);

        $sidecarPath = substr($stored->path, 0, -4) . '.json';
        $data        = json_decode(file_get_contents($sidecarPath), true);

        $this->assertArrayHasKey('source_url', $data);
        $this->assertStringNotContainsString(
            $secretKey,
            $data['source_url'],
            'The api_key value must be stripped from source_url before it is stored'
        );

        // The other query param must still be present.
        $this->assertStringContainsString('other=1', $data['source_url'],
            'Non-api_key query params must be preserved in source_url');
    }

    /**
     * A URL without any api_key parameter is stored unchanged.
     *
     * Requirements: 4.3
     */
    public function testUrlWithoutApiKeyIsStoredUnchanged(): void
    {
        $storageDir = $this->makeTempDir();
        $sourceDir  = $this->makeTempDir();
        $service    = new StorageService($this->makeConfig($storageDir));

        $originalUrl = 'https://example.com/page?foo=bar&baz=qux';
        $stored      = $service->save($this->makeFakePdf($sourceDir), $originalUrl);

        $sidecarPath = substr($stored->path, 0, -4) . '.json';
        $data        = json_decode(file_get_contents($sidecarPath), true);

        $this->assertStringContainsString('foo=bar', $data['source_url'],
            'source_url must retain params when no api_key is present');
        $this->assertStringContainsString('baz=qux', $data['source_url'],
            'source_url must retain params when no api_key is present');
    }

    // -----------------------------------------------------------------------
    // 4. Missing sidecar returns null
    // -----------------------------------------------------------------------

    /**
     * find() returns null when the sidecar .json file does not exist,
     * even if the filename format is valid.
     *
     * Requirements: 4.2
     */
    public function testMissingSidecarReturnsNull(): void
    {
        $storageDir = $this->makeTempDir();
        $service    = new StorageService($this->makeConfig($storageDir));

        // Create a valid-format filename and place a PDF file but NO sidecar.
        $hexPart  = bin2hex(random_bytes(20)); // 40 hex chars
        $filename = $hexPart . '.pdf';
        file_put_contents($storageDir . '/' . $filename, '%PDF-1.4');

        $result = $service->find($filename);
        $this->assertNull($result, 'find() must return null when the sidecar .json is missing');
    }

    // -----------------------------------------------------------------------
    // 5. Invalid filename format returns null (no filesystem access)
    // -----------------------------------------------------------------------

    /**
     * find() returns null immediately for filenames that do not match the
     * expected pattern, without touching the filesystem.
     *
     * Requirements: 4.2, 8.8
     */
    public function testInvalidFilenameFormatReturnsNull(): void
    {
        $storageDir = $this->makeTempDir();
        $service    = new StorageService($this->makeConfig($storageDir));

        $invalidFilenames = [
            '../evil.pdf',               // path traversal
            '../../etc/passwd',          // path traversal (no .pdf)
            'foo.txt',                   // wrong extension
            'short.pdf',                 // hex part too short (< 32 chars)
            'abc123.pdf',                // way too short
            '',                          // empty string
            '.pdf',                      // no hex part
            'ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEF.pdf', // uppercase hex — not matching /^[0-9a-f]{32,}/
            'gggggggggggggggggggggggggggggggg.pdf',  // invalid hex chars
            'a3f9b2c1d4e5f6a7b8c9d0e1f2a3b4c5/evil.pdf', // path separator in name
        ];

        foreach ($invalidFilenames as $filename) {
            $result = $service->find($filename);
            $this->assertNull(
                $result,
                "find('{$filename}') must return null for an invalid filename format"
            );
        }
    }

    /**
     * A valid 32-char hex + .pdf filename returns null when no sidecar exists
     * (this also verifies the format check passes for minimal-length names).
     *
     * Requirements: 8.8
     */
    public function testValidFormatFilenameWithoutSidecarReturnsNull(): void
    {
        $storageDir = $this->makeTempDir();
        $service    = new StorageService($this->makeConfig($storageDir));

        // 32 lowercase hex chars + .pdf — minimum valid format.
        $filename = str_repeat('a', 32) . '.pdf';
        $result   = $service->find($filename);

        $this->assertNull($result,
            'find() must return null for a valid-format filename that has no sidecar');
    }

    // -----------------------------------------------------------------------
    // 6. storageSizeBytes() returns correct sum
    // -----------------------------------------------------------------------

    /**
     * storageSizeBytes() sums all files (PDFs and sidecars) in the storage dir.
     *
     * Requirements: 8.1
     */
    public function testStorageSizeBytesReturnsCorrectSum(): void
    {
        $storageDir = $this->makeTempDir();
        $sourceDir  = $this->makeTempDir();
        $service    = new StorageService($this->makeConfig($storageDir));

        // Empty storage: should be 0.
        $this->assertSame(0, $service->storageSizeBytes(),
            'Empty storage directory must report 0 bytes');

        // Write two known-size blobs directly (bypass save() to control exact sizes).
        $blob1 = str_repeat('A', 100);
        $blob2 = str_repeat('B', 200);
        file_put_contents($storageDir . '/file1.bin', $blob1);
        file_put_contents($storageDir . '/file2.bin', $blob2);

        $this->assertSame(300, $service->storageSizeBytes(),
            'storageSizeBytes() must sum all files in the storage directory');
    }

    /**
     * After save(), storageSizeBytes() reflects the sizes of the PDF and the
     * companion sidecar file.
     *
     * Requirements: 8.1
     */
    public function testStorageSizeBytesAfterSave(): void
    {
        $storageDir = $this->makeTempDir();
        $sourceDir  = $this->makeTempDir();
        $service    = new StorageService($this->makeConfig($storageDir));

        $this->assertSame(0, $service->storageSizeBytes(), 'Must start at 0');

        $service->save($this->makeFakePdf($sourceDir), 'https://example.com/a');

        $size = $service->storageSizeBytes();
        $this->assertGreaterThan(0, $size,
            'storageSizeBytes() must be > 0 after saving a file');
    }

    // -----------------------------------------------------------------------
    // 7. generateFilename() format
    // -----------------------------------------------------------------------

    /**
     * generateFilename() returns a string matching /^[0-9a-f]{32,}\.pdf$/.
     *
     * Requirements: 8.8
     */
    public function testGenerateFilenameHasCorrectFormat(): void
    {
        $storageDir = $this->makeTempDir();
        $service    = new StorageService($this->makeConfig($storageDir));

        for ($i = 0; $i < 10; $i++) {
            $filename = $service->generateFilename();
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{32,}\.pdf$/',
                $filename,
                "generateFilename() result '{$filename}' does not match required format"
            );
        }
    }

    /**
     * generateFilename() produces unique filenames across multiple calls.
     *
     * Requirements: 8.8
     */
    public function testGenerateFilenameProducesUniqueNames(): void
    {
        $storageDir = $this->makeTempDir();
        $service    = new StorageService($this->makeConfig($storageDir));

        $names = [];
        for ($i = 0; $i < 100; $i++) {
            $names[] = $service->generateFilename();
        }

        $this->assertCount(100, array_unique($names),
            'generateFilename() must produce unique names across 100 calls');
    }

    // -----------------------------------------------------------------------
    // 8. StorageService constructor rejects non-writable directory
    // -----------------------------------------------------------------------

    /**
     * Constructing StorageService with a non-existent or non-writable directory
     * throws a RuntimeException.
     *
     * Requirements: 8.1
     */
    public function testConstructorThrowsForNonWritableDirectory(): void
    {
        $this->expectException(\RuntimeException::class);

        new StorageService($this->makeConfig('/does/not/exist/path_' . uniqid()));
    }
}
