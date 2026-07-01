<?php

declare(strict_types=1);

namespace Tests\Property;

use App\Controller\FileController;
use App\Model\StoredFile;
use App\Service\StorageService;
use DateTimeImmutable;
use DateTimeZone;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Property-based tests for FileController::download().
 *
 * Feature: url-to-pdf-api
 *
 * Covers:
 *   Property 9  — File download returns correct headers for all stored files
 *   Property 10 — Invalid filename format never touches the filesystem
 *   Property 11 — Expired files always return 410
 */
class FileControllerPropertyTest extends TestCase
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

    /** @var string[] Temp files created per test iteration; cleaned up in tearDown */
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
     * Create a small real temp PDF file and register it for cleanup.
     * Returns the absolute path to the file.
     */
    private function makeTempPdfFile(): string
    {
        $path = sys_get_temp_dir() . '/prop_test_' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($path, '%PDF-1.4 fake-pdf-content-for-property-test');
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * Build a GET PSR-7 request for the given filename path argument.
     */
    private function makeRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api/files/test.pdf');
    }

    /**
     * Build a FileController with the given mocked StorageService.
     */
    private function makeController(StorageService $storageService): FileController
    {
        return new FileController($storageService);
    }

    // -----------------------------------------------------------------------
    // Property 9: File download returns correct headers for all stored files
    //
    // For any PDF file stored in the storage directory that has not expired —
    // a GET request to /api/files/{filename} SHALL return HTTP 200,
    // Content-Type: application/pdf,
    // Content-Disposition: attachment; filename="{filename}",
    // and a Content-Length equal to the exact byte size of the file.
    //
    // // Feature: url-to-pdf-api, Property 9: File download returns correct headers for all stored files
    //
    // Validates: Requirements 7.1, 7.2
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 9: Valid non-expired stored file always returns 200 with correct headers
     *
     * For any valid stored file that has not expired, the controller must return:
     *  - HTTP 200
     *  - Content-Type: application/pdf
     *  - Content-Disposition: attachment; filename="{filename}"
     *  - Content-Length matching the exact byte size of the file
     *
     * // Feature: url-to-pdf-api, Property 9: File download returns correct headers for all stored files
     *
     * Validates: Requirements 7.1, 7.2
     */
    public function testValidNonExpiredFileReturnsCorrectHeaders(): void
    {
        // Generate valid hex filenames of varying lengths (32–64 hex chars)
        $this->forAll(
            Generators::choose(32, 64) // hex part length
        )
            ->withMaxSize(100)
            ->then(function (int $hexLen): void {
                // Build a valid filename: lowercase hex chars + .pdf
                $hexPart  = str_pad('', $hexLen, 'abcdef0123456789', STR_PAD_RIGHT);
                // Trim to exact length in case str_pad overshoots
                $hexPart  = substr($hexPart, 0, $hexLen);
                $filename = $hexPart . '.pdf';

                // Create a real temp file so filesize() and file_get_contents() work
                $tmpPath = $this->makeTempPdfFile();
                $expectedSize = (int) filesize($tmpPath);

                // Mock StorageService::find() to return a non-expired StoredFile
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

                $request  = $this->makeRequest();
                $response = (new ResponseFactory())->createResponse();

                $result = $controller->download($request, $response, ['filename' => $filename]);

                // HTTP 200
                $this->assertSame(200, $result->getStatusCode(),
                    "Valid non-expired file must return HTTP 200 for filename '{$filename}'");

                // Content-Type: application/pdf
                $this->assertStringContainsString(
                    'application/pdf',
                    $result->getHeaderLine('Content-Type'),
                    "Response must have Content-Type: application/pdf"
                );

                // Content-Disposition: attachment; filename="{filename}"
                $contentDisposition = $result->getHeaderLine('Content-Disposition');
                $this->assertStringContainsString(
                    'attachment',
                    $contentDisposition,
                    "Content-Disposition must contain 'attachment'"
                );
                $this->assertStringContainsString(
                    "filename=\"{$filename}\"",
                    $contentDisposition,
                    "Content-Disposition must contain filename=\"{$filename}\""
                );

                // Content-Length equals exact file size
                $this->assertSame(
                    (string) $expectedSize,
                    $result->getHeaderLine('Content-Length'),
                    "Content-Length must equal the exact byte size of the file ({$expectedSize})"
                );
            });
    }

    // -----------------------------------------------------------------------
    // Property 10: Invalid filename format never touches the filesystem
    //
    // For any string that does not match ^[0-9a-f]{32,}\.pdf$ — the API SHALL
    // return HTTP 400 without performing any filesystem access.
    //
    // // Feature: url-to-pdf-api, Property 10: Invalid filename format never touches the filesystem
    //
    // Validates: Requirement 7.3
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 10: Invalid filenames always return 400 and never call StorageService::find()
     *
     * For any string that does not match the valid filename pattern, the
     * controller must return HTTP 400 and StorageService::find() must NOT
     * be called (no filesystem access).
     *
     * // Feature: url-to-pdf-api, Property 10: Invalid filename format never touches the filesystem
     *
     * Validates: Requirement 7.3
     */
    public function testInvalidFilenameFormatReturns400WithoutFilesystemAccess(): void
    {
        // A representative pool of invalid filenames:
        //  - path traversal
        //  - too short (< 32 hex chars before .pdf)
        //  - wrong extension
        //  - uppercase hex (pattern requires lowercase)
        //  - empty string
        //  - no extension
        //  - spaces
        //  - special characters
        $invalidFilenames = [
            '../evil.pdf',                                    // path traversal
            'abc.pdf',                                        // too short (3 chars)
            'abcdef1234567890abcdef1234567.pdf',              // 29 hex chars — just under 32
            'abcdef1234567890abcdef1234567890.txt',           // wrong extension
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ12345678.pdf',         // uppercase (invalid)
            '',                                               // empty string
            '   ',                                            // whitespace only
            'abcdef1234567890abcdef1234567890.pdf.php',       // double extension
            '/etc/passwd',                                    // absolute path
            'abcdef1234567890abcdef1234567890',               // no extension
            'abc def 123 456 789 012 345 678 90.pdf',         // spaces in name
            'ABCDEF1234567890ABCDEF1234567890.pdf',           // mixed uppercase
        ];

        $this->forAll(
            Generators::elements($invalidFilenames)
        )
            ->withMaxSize(100)
            ->then(function (string $filename): void {
                // StorageService::find() must NEVER be called for invalid filenames
                $storageService = $this->createMock(StorageService::class);
                $storageService->expects($this->never())
                    ->method('find');

                $controller = $this->makeController($storageService);

                $request  = $this->makeRequest();
                $response = (new ResponseFactory())->createResponse();

                $result = $controller->download($request, $response, ['filename' => $filename]);

                // HTTP 400
                $this->assertSame(400, $result->getStatusCode(),
                    "Invalid filename '{$filename}' must return HTTP 400");

                // Response body must be JSON with a message field
                $body = (string) $result->getBody();
                $decoded = json_decode($body, true);

                $this->assertIsArray($decoded,
                    "400 response must have a JSON body for filename '{$filename}'");

                $this->assertArrayHasKey('message', $decoded,
                    "400 JSON response must contain 'message' field for filename '{$filename}'");

                $this->assertNotEmpty($decoded['message'],
                    "400 JSON response 'message' must not be empty for filename '{$filename}'");
            });
    }

    // -----------------------------------------------------------------------
    // Property 11: Expired files always return 410
    //
    // For any file whose recorded expiry timestamp is in the past — a GET
    // request for that file SHALL return HTTP 410.
    //
    // // Feature: url-to-pdf-api, Property 11: Expired files always return 410
    //
    // Validates: Requirement 7.5
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 11: Files with past expiry timestamp always return HTTP 410
     *
     * For any StoredFile whose expiresAt is in the past, the controller must
     * return HTTP 410 regardless of whether the file physically exists.
     *
     * // Feature: url-to-pdf-api, Property 11: Expired files always return 410
     *
     * Validates: Requirement 7.5
     */
    public function testExpiredFilesAlwaysReturn410(): void
    {
        // Generate how far in the past the file expired (1 second to 1 year ago)
        $this->forAll(
            Generators::choose(1, 365 * 24 * 3600) // seconds in the past
        )
            ->withMaxSize(100)
            ->then(function (int $secondsInPast): void {
                // Build a valid filename
                $filename = str_pad('', 32, 'abcdef0123456789', STR_PAD_RIGHT) . '.pdf';

                // expired: expiresAt is $secondsInPast seconds before now
                $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $expiresAt = $now->modify("-{$secondsInPast} seconds");
                $createdAt = $expiresAt->modify('-3600 seconds'); // created before expiry

                $stored = new StoredFile(
                    filename:    $filename,
                    path:        '/some/path/' . $filename, // path doesn't matter for expiry check
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

                $request  = $this->makeRequest();
                $response = (new ResponseFactory())->createResponse();

                $result = $controller->download($request, $response, ['filename' => $filename]);

                // HTTP 410 Gone
                $this->assertSame(410, $result->getStatusCode(),
                    "Expired file (expired {$secondsInPast}s ago) must return HTTP 410");

                // Response body must be JSON with a message field
                $body    = (string) $result->getBody();
                $decoded = json_decode($body, true);

                $this->assertIsArray($decoded,
                    "410 response must have a JSON body");

                $this->assertArrayHasKey('message', $decoded,
                    "410 JSON response must contain 'message' field");

                $this->assertNotEmpty($decoded['message'],
                    "410 JSON response 'message' must not be empty");
            });
    }
}
