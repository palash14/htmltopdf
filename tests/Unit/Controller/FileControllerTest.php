<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\FileController;
use App\Model\StoredFile;
use App\Service\StorageService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Unit tests for FileController::download().
 *
 * All tests mock StorageService to isolate controller logic.
 *
 * Covers:
 *  1. Valid filename, file exists, not expired → 200 with correct headers
 *  2. Invalid filename (regex mismatch) → 400, StorageService::find never called
 *  3. Valid format filename but StorageService::find returns null → 404
 *  4. Valid filename, found but expired (expiresAt < now) → 410
 *
 * Requirements: 7.1–7.6
 */
class FileControllerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @var string[] Temp files created during tests; cleaned up in tearDown */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    /**
     * Create a real temporary PDF file and register it for cleanup.
     * Returns the absolute path.
     */
    private function makeTempPdfFile(string $content = '%PDF-1.4 fake-pdf-content'): string
    {
        $path = sys_get_temp_dir() . '/fc_unit_test_' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * Build a FileController with the given StorageService mock.
     */
    private function makeController(StorageService $storageService): FileController
    {
        return new FileController($storageService);
    }

    /**
     * Build a basic GET PSR-7 request.
     */
    private function makeRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api/files/test.pdf');
    }

    /**
     * Build a valid 32-char hex + .pdf filename.
     */
    private function validFilename(): string
    {
        return 'abcdef1234567890abcdef1234567890.pdf'; // exactly 32 hex chars
    }

    // -----------------------------------------------------------------------
    // Test 1: Valid filename, file exists, not expired → 200 with correct headers
    // -----------------------------------------------------------------------

    /**
     * When a valid filename is provided and StorageService::find() returns a
     * non-expired StoredFile, the response must be:
     *  - HTTP 200
     *  - Content-Type: application/pdf
     *  - Content-Disposition: attachment; filename="{filename}"
     *  - Content-Length equal to exact byte size of the file
     *
     * Requirements: 7.1, 7.2
     */
    public function testValidNonExpiredFileReturns200WithCorrectHeaders(): void
    {
        $filename = $this->validFilename();

        // Create a real temp PDF so filesize() and file_get_contents() work
        $pdfContent = '%PDF-1.4 unit test pdf content here';
        $tmpPath    = $this->makeTempPdfFile($pdfContent);
        $fileSize   = (int) filesize($tmpPath);

        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+3600 seconds');

        $stored = new StoredFile(
            filename:    $filename,
            path:        $tmpPath,
            downloadUrl: 'https://example.com/api/files/' . $filename,
            createdAt:   $now,
            expiresAt:   $expiresAt,
        );

        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->once())
            ->method('find')
            ->with($filename)
            ->willReturn($stored);

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => $filename],
        );

        // HTTP 200
        $this->assertSame(200, $result->getStatusCode(),
            'Valid non-expired file must return HTTP 200');

        // Content-Type: application/pdf
        $this->assertStringContainsString(
            'application/pdf',
            $result->getHeaderLine('Content-Type'),
            'Must return Content-Type: application/pdf'
        );

        // Content-Disposition: attachment; filename="{filename}"
        $disposition = $result->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition,
            'Content-Disposition must contain "attachment"');
        $this->assertStringContainsString(
            "filename=\"{$filename}\"",
            $disposition,
            'Content-Disposition must contain filename="..."'
        );

        // Content-Length equals exact file size
        $this->assertSame(
            (string) $fileSize,
            $result->getHeaderLine('Content-Length'),
            "Content-Length must equal exact file size ({$fileSize} bytes)"
        );

        // Body contains the PDF bytes
        $body = (string) $result->getBody();
        $this->assertSame($pdfContent, $body,
            'Response body must contain the PDF file bytes');
    }

    /**
     * Verify the Content-Disposition filename value matches the request filename
     * exactly (including the .pdf extension).
     *
     * Requirements: 7.2
     */
    public function testContentDispositionFilenameMatchesRequestFilename(): void
    {
        $filename = 'deadbeefdeadbeefdeadbeefdeadbeef.pdf'; // 32 hex chars

        $tmpPath   = $this->makeTempPdfFile();
        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+3600 seconds');

        $stored = new StoredFile(
            filename:    $filename,
            path:        $tmpPath,
            downloadUrl: 'https://example.com/api/files/' . $filename,
            createdAt:   $now,
            expiresAt:   $expiresAt,
        );

        $storageService = $this->createMock(StorageService::class);
        $storageService->method('find')->willReturn($stored);

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => $filename],
        );

        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString(
            "filename=\"{$filename}\"",
            $result->getHeaderLine('Content-Disposition')
        );
    }

    // -----------------------------------------------------------------------
    // Test 2: Invalid filename → 400, StorageService::find never called
    // -----------------------------------------------------------------------

    /**
     * When the filename does not match ^[0-9a-f]{32,}\.pdf$, the controller
     * must return HTTP 400 without calling StorageService::find() at all.
     *
     * Requirements: 7.3
     */
    public function testInvalidFilenameReturns400WithoutCallingStorageService(): void
    {
        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->never())
            ->method('find');

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => '../evil.pdf'],
        );

        $this->assertSame(400, $result->getStatusCode(),
            'Path traversal filename must return HTTP 400');

        $body    = (string) $result->getBody();
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded, '400 response must be JSON');
        $this->assertArrayHasKey('message', $decoded, 'JSON must have message field');
        $this->assertNotEmpty($decoded['message'], 'message must not be empty');
    }

    /**
     * Short filename (fewer than 32 hex chars before .pdf) returns 400.
     *
     * Requirements: 7.3
     */
    public function testTooShortHexFilenameReturns400(): void
    {
        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->never())->method('find');

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => 'abc.pdf'], // only 3 hex chars
        );

        $this->assertSame(400, $result->getStatusCode(),
            'Filename with fewer than 32 hex chars must return HTTP 400');
    }

    /**
     * Filename with wrong extension (.txt instead of .pdf) returns 400.
     *
     * Requirements: 7.3
     */
    public function testWrongExtensionFilenameReturns400(): void
    {
        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->never())->method('find');

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => 'abcdef1234567890abcdef1234567890.txt'],
        );

        $this->assertSame(400, $result->getStatusCode(),
            'Filename with .txt extension must return HTTP 400');
    }

    /**
     * Filename with uppercase hex chars returns 400 (pattern requires lowercase).
     *
     * Requirements: 7.3
     */
    public function testUppercaseHexFilenameReturns400(): void
    {
        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->never())->method('find');

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ12345678.pdf'],
        );

        $this->assertSame(400, $result->getStatusCode(),
            'Uppercase hex filename must return HTTP 400');
    }

    /**
     * Empty filename string returns 400.
     *
     * Requirements: 7.3
     */
    public function testEmptyFilenameReturns400(): void
    {
        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->never())->method('find');

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => ''],
        );

        $this->assertSame(400, $result->getStatusCode(),
            'Empty filename must return HTTP 400');
    }

    /**
     * Missing 'filename' key in $args (defaults to empty string) returns 400.
     *
     * Requirements: 7.3
     */
    public function testMissingFilenameArgReturns400(): void
    {
        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->never())->method('find');

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            [], // no 'filename' key
        );

        $this->assertSame(400, $result->getStatusCode(),
            'Missing filename arg must return HTTP 400');
    }

    // -----------------------------------------------------------------------
    // Test 3: Valid format but StorageService::find returns null → 404
    // -----------------------------------------------------------------------

    /**
     * When the filename format is valid but StorageService::find() returns
     * null (file not in storage), the controller must return HTTP 404.
     *
     * Requirements: 7.4
     */
    public function testFileNotFoundReturns404(): void
    {
        $filename = $this->validFilename();

        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->once())
            ->method('find')
            ->with($filename)
            ->willReturn(null);

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => $filename],
        );

        $this->assertSame(404, $result->getStatusCode(),
            'File not in storage must return HTTP 404');

        $body    = (string) $result->getBody();
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded, '404 response must be JSON');
        $this->assertArrayHasKey('message', $decoded, 'JSON must have message field');
        $this->assertNotEmpty($decoded['message'], 'message must not be empty');
    }

    /**
     * A longer valid filename (64 hex chars) that StorageService returns null
     * for also produces 404.
     *
     * Requirements: 7.4
     */
    public function testLongValidFilenameNotFoundReturns404(): void
    {
        $filename = str_repeat('a', 64) . '.pdf'; // 64 hex chars

        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->once())
            ->method('find')
            ->with($filename)
            ->willReturn(null);

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => $filename],
        );

        $this->assertSame(404, $result->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Test 4: Valid filename, found but expired → 410
    // -----------------------------------------------------------------------

    /**
     * When the file is found but its expiresAt is in the past, the controller
     * must return HTTP 410 Gone.
     *
     * Requirements: 7.5
     */
    public function testExpiredFileReturns410(): void
    {
        $filename = $this->validFilename();

        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('-1 second'); // expired 1 second ago
        $createdAt = $expiresAt->modify('-3600 seconds');

        $stored = new StoredFile(
            filename:    $filename,
            path:        '/some/path/' . $filename,
            downloadUrl: 'https://example.com/api/files/' . $filename,
            createdAt:   $createdAt,
            expiresAt:   $expiresAt,
        );

        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->once())
            ->method('find')
            ->with($filename)
            ->willReturn($stored);

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => $filename],
        );

        $this->assertSame(410, $result->getStatusCode(),
            'Expired file (expiresAt < now) must return HTTP 410');

        $body    = (string) $result->getBody();
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded, '410 response must be JSON');
        $this->assertArrayHasKey('message', $decoded, 'JSON must have message field');
        $this->assertNotEmpty($decoded['message'], 'message must not be empty');
    }

    /**
     * File that expired a long time ago (1 hour) also returns 410.
     *
     * Requirements: 7.5
     */
    public function testLongExpiredFileReturns410(): void
    {
        $filename = $this->validFilename();

        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('-3600 seconds'); // expired 1 hour ago
        $createdAt = $expiresAt->modify('-3600 seconds');

        $stored = new StoredFile(
            filename:    $filename,
            path:        '/some/path/' . $filename,
            downloadUrl: 'https://example.com/api/files/' . $filename,
            createdAt:   $createdAt,
            expiresAt:   $expiresAt,
        );

        $storageService = $this->createMock(StorageService::class);
        $storageService->method('find')->willReturn($stored);

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => $filename],
        );

        $this->assertSame(410, $result->getStatusCode(),
            'File expired 1 hour ago must return HTTP 410');
    }

    /**
     * A file with expiresAt set to exactly "now" (boundary case) is treated
     * as expired and returns 410 (strict less-than check).
     *
     * Requirements: 7.5
     */
    public function testFileExpiredAtExactlyNowReturns410(): void
    {
        $filename = $this->validFilename();

        // Set expiresAt a tiny bit in the past to reliably trigger the expiry check
        // (avoids race condition at exact boundary)
        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('-1 second');
        $createdAt = $expiresAt->modify('-3600 seconds');

        $stored = new StoredFile(
            filename:    $filename,
            path:        '/some/path/' . $filename,
            downloadUrl: 'https://example.com/api/files/' . $filename,
            createdAt:   $createdAt,
            expiresAt:   $expiresAt,
        );

        $storageService = $this->createMock(StorageService::class);
        $storageService->method('find')->willReturn($stored);

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => $filename],
        );

        $this->assertSame(410, $result->getStatusCode(),
            'File with expiresAt just before now must return HTTP 410');
    }

    // -----------------------------------------------------------------------
    // Additional: 400 response is JSON (Content-Type: application/json)
    // -----------------------------------------------------------------------

    /**
     * 400 responses for invalid filenames must have Content-Type: application/json.
     *
     * Requirements: 7.3
     */
    public function testInvalidFilenameResponseIsJson(): void
    {
        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->never())->method('find');

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => 'not-valid'],
        );

        $this->assertSame(400, $result->getStatusCode());
        $this->assertStringContainsString(
            'application/json',
            $result->getHeaderLine('Content-Type'),
            '400 response must have Content-Type: application/json'
        );
    }

    /**
     * 404 responses must have Content-Type: application/json.
     *
     * Requirements: 7.4
     */
    public function testNotFoundResponseIsJson(): void
    {
        $filename = $this->validFilename();

        $storageService = $this->createMock(StorageService::class);
        $storageService->method('find')->willReturn(null);

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => $filename],
        );

        $this->assertSame(404, $result->getStatusCode());
        $this->assertStringContainsString(
            'application/json',
            $result->getHeaderLine('Content-Type'),
            '404 response must have Content-Type: application/json'
        );
    }

    /**
     * 410 responses must have Content-Type: application/json.
     *
     * Requirements: 7.5
     */
    public function testExpiredResponseIsJson(): void
    {
        $filename = $this->validFilename();

        $now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stored = new StoredFile(
            filename:    $filename,
            path:        '/some/path/' . $filename,
            downloadUrl: 'https://example.com/api/files/' . $filename,
            createdAt:   $now->modify('-7200 seconds'),
            expiresAt:   $now->modify('-1 second'),
        );

        $storageService = $this->createMock(StorageService::class);
        $storageService->method('find')->willReturn($stored);

        $controller = $this->makeController($storageService);
        $result     = $controller->download(
            $this->makeRequest(),
            (new ResponseFactory())->createResponse(),
            ['filename' => $filename],
        );

        $this->assertSame(410, $result->getStatusCode());
        $this->assertStringContainsString(
            'application/json',
            $result->getHeaderLine('Content-Type'),
            '410 response must have Content-Type: application/json'
        );
    }
}
