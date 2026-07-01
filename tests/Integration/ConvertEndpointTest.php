<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\ConvertController;
use App\Controller\FileController;
use App\Exception\ConcurrencyException;
use App\Model\Config;
use App\Service\ConcurrencyGuard;
use App\Service\InputValidator;
use App\Service\RendererService;
use App\Service\SsrfGuard;
use App\Service\StorageService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Integration tests for the full convert → download pipeline.
 *
 * These tests wire real service implementations together (StorageService,
 * InputValidator, ConcurrencyGuard), using:
 *  - An IntegrationTestRenderer that replaces RendererService's command with
 *    a PHP script that writes a fake PDF, so no real wkhtmltopdf is needed.
 *  - A TestableSsrfGuard that always passes (skips DNS resolution) so tests
 *    work offline and without specific DNS records.
 *  - A real temp storage directory, cleaned up in tearDown.
 *
 * Requirements: 1.2, 4.1, 4.2, 4.3, 7.1, 7.2
 */

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * TestableSsrfGuard bypasses DNS resolution so the integration pipeline can
 * run with any URL without requiring real DNS access.
 */
class TestableSsrfGuard extends SsrfGuard
{
    /**
     * Always returns a fake public IP so the SSRF check passes.
     *
     * @return string[]
     */
    protected function resolveHostname(string $hostname): array
    {
        return ['93.184.216.34']; // example.com's public IP — always passes private-IP check
    }
}

/**
 * IntegrationTestRenderer subclasses RendererService and overrides:
 *  - isExecutable() → always returns true (no real binary needed)
 *  - buildCommand() → runs a PHP script that writes a fake PDF to $outputPath
 *
 * This allows the full controller → renderer → storage pipeline to execute
 * without wkhtmltopdf being installed.
 */
class IntegrationTestRenderer extends RendererService
{
    protected function isExecutable(string $path): bool
    {
        return true;
    }

    protected function buildCommand(string $url, string $outputPath): array
    {
        // Inline PHP script written as a temporary file.
        // It writes a minimal fake PDF header so filesize() > 0.
        $script = sys_get_temp_dir() . '/integration_stub_' . getmypid() . '.php';
        $escapedOut = var_export($outputPath, true);
        file_put_contents(
            $script,
            "<?php\nfile_put_contents({$escapedOut}, '%PDF-1.4 fake-integration-pdf-content');\nexit(0);\n"
        );
        return [PHP_BINARY, $script];
    }
}

/**
 * TestableConcurrencyGuard (same as the one in ConcurrencyGuardTest) — an
 * in-memory ConcurrencyGuard that does not require System V semaphores or APCu.
 * Used by the integration pipeline so the tests run on Windows.
 */
class IntegrationConcurrencyGuard extends ConcurrencyGuard
{
    private const MAX_QUEUE_SIZE  = 20;
    private const POLL_INTERVAL_MS = 10;

    private int $slots;
    private int $depth = 0;

    public function __construct(Config $config)
    {
        parent::__construct($config);
        $this->slots = $config->maxConcurrentRenderers;
    }

    public function acquire(int $timeoutSeconds = 60): void
    {
        $this->depth++;

        if ($this->depth > self::MAX_QUEUE_SIZE) {
            $this->depth--;
            throw new ConcurrencyException('queue full');
        }

        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if ($this->slots > 0) {
                $this->slots--;
                return;
            }
            usleep(self::POLL_INTERVAL_MS * 1_000);
        } while (microtime(true) < $deadline);

        $this->depth--;
        throw new ConcurrencyException('queue timeout');
    }

    public function release(): void
    {
        $this->slots++;
        if ($this->depth > 0) {
            $this->depth--;
        }
    }

    public function getAvailableSlots(): int
    {
        return $this->slots;
    }

    public function getCurrentQueueDepth(): int
    {
        return $this->depth;
    }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------

class ConvertEndpointTest extends TestCase
{
    /** Temp directory used as the storage dir for all tests in this class. */
    private string $storageDir;

    /** Scripts created during the test run that need cleanup. */
    private array $tempScripts = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create a fresh temp storage directory for this test run.
        $this->storageDir = sys_get_temp_dir() . '/pdf_integration_' . bin2hex(random_bytes(8));
        mkdir($this->storageDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Remove all files in the temp storage directory.
        $files = glob($this->storageDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->storageDir);

        // Remove any leftover renderer stub scripts.
        $stubs = glob(sys_get_temp_dir() . '/integration_stub_*.php');
        if ($stubs !== false) {
            foreach ($stubs as $stub) {
                @unlink($stub);
            }
        }

        foreach ($this->tempScripts as $s) {
            @unlink($s);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeConfig(int $ttlSeconds = 3600): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '',          // not used — IntegrationTestRenderer overrides buildCommand()
            apiKeys: ['integration-key'],
            storageDir: $this->storageDir,
            baseUrl: 'https://example.com',
            ttlSeconds: $ttlSeconds,
            maxConcurrentRenderers: 5,
        );
    }

    /**
     * Build the full pipeline with real services, except:
     *  - IntegrationTestRenderer for the renderer (no real wkhtmltopdf)
     *  - TestableSsrfGuard for DNS resolution (no real DNS calls)
     *  - IntegrationConcurrencyGuard for concurrency (no SysV semaphores)
     *
     * @return array{ConvertController, FileController, Config}
     */
    private function makePipeline(int $ttlSeconds = 3600): array
    {
        $config = $this->makeConfig($ttlSeconds);
        $logger = new \Psr\Log\NullLogger();

        $inputValidator   = new InputValidator();
        $ssrfGuard        = new TestableSsrfGuard();
        $storageService   = new StorageService($config);
        $rendererService  = new IntegrationTestRenderer($config, $logger);
        $concurrencyGuard = new IntegrationConcurrencyGuard($config);

        $convertController = new ConvertController(
            inputValidator:   $inputValidator,
            ssrfGuard:        $ssrfGuard,
            storageService:   $storageService,
            rendererService:  $rendererService,
            concurrencyGuard: $concurrencyGuard,
            config:           $config,
        );

        $fileController = new FileController($storageService);

        return [$convertController, $fileController, $config];
    }

    private function makeConvertRequest(string $url = 'https://public.example.com/page'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://example.com/api/convert')
            ->withParsedBody(['url' => $url])
            ->withHeader('Content-Type', 'application/json');
    }

    private function makeDownloadRequest(string $filename): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.com/api/files/' . $filename);
    }

    // -----------------------------------------------------------------------
    // Task 16.1 — Full convert → download round-trip
    // -----------------------------------------------------------------------

    /**
     * POST /api/convert with a valid URL should return HTTP 200 with a JSON
     * body containing `download_url` and `expires_at`.
     *
     * Then GET the `download_url` path: should return HTTP 200 with
     * Content-Type: application/pdf and Content-Length matching the file size.
     *
     * Requirements: 1.2, 4.1, 4.2, 4.3, 7.1, 7.2
     */
    public function testFullConvertThenDownloadRoundTrip(): void
    {
        [$convertController, $fileController] = $this->makePipeline();

        // ------------------------------------------------------------------
        // Step 1: POST /api/convert
        // ------------------------------------------------------------------
        $convertRequest  = $this->makeConvertRequest('https://public.example.com/page');
        $convertResponse = $convertController->handle(
            $convertRequest,
            (new ResponseFactory())->createResponse()
        );

        // Assert HTTP 200
        $this->assertSame(200, $convertResponse->getStatusCode(),
            'POST /api/convert must return HTTP 200 for a valid URL');

        // Assert Content-Type: application/json
        $this->assertStringContainsString(
            'application/json',
            $convertResponse->getHeaderLine('Content-Type'),
            'POST /api/convert must return Content-Type: application/json'
        );

        // Decode and inspect JSON body
        $body    = (string) $convertResponse->getBody();
        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded, 'Response body must be valid JSON');

        // Assert download_url present and well-formed
        $this->assertArrayHasKey('download_url', $decoded,
            'Response must contain download_url');
        $downloadUrl = $decoded['download_url'];
        $this->assertIsString($downloadUrl);
        $this->assertStringStartsWith('https://example.com/api/files/', $downloadUrl,
            'download_url must start with the configured base URL + /api/files/');
        $this->assertStringEndsWith('.pdf', $downloadUrl,
            'download_url must end with .pdf');

        // Assert expires_at present and matches ISO 8601 UTC
        $this->assertArrayHasKey('expires_at', $decoded,
            'Response must contain expires_at');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $decoded['expires_at'],
            'expires_at must be a valid ISO 8601 UTC datetime string'
        );

        // ------------------------------------------------------------------
        // Step 2: GET /api/files/{filename}
        // ------------------------------------------------------------------
        // Extract filename from the download_url
        $filename = basename($downloadUrl);

        $downloadRequest  = $this->makeDownloadRequest($filename);
        $downloadResponse = $fileController->download(
            $downloadRequest,
            (new ResponseFactory())->createResponse(),
            ['filename' => $filename]
        );

        // Assert HTTP 200
        $this->assertSame(200, $downloadResponse->getStatusCode(),
            'GET /api/files/{filename} must return HTTP 200 for an existing, non-expired file');

        // Assert Content-Type: application/pdf
        $this->assertStringContainsString(
            'application/pdf',
            $downloadResponse->getHeaderLine('Content-Type'),
            'Download response must have Content-Type: application/pdf'
        );

        // Assert Content-Disposition header is set correctly
        $disposition = $downloadResponse->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition,
            'Content-Disposition must indicate attachment download');
        $this->assertStringContainsString($filename, $disposition,
            'Content-Disposition must reference the correct filename');

        // Assert Content-Length matches the actual file bytes in the response body
        $pdfBytes       = (string) $downloadResponse->getBody();
        $contentLength  = (int) $downloadResponse->getHeaderLine('Content-Length');

        $this->assertGreaterThan(0, strlen($pdfBytes),
            'Downloaded PDF must not be empty');
        $this->assertSame(strlen($pdfBytes), $contentLength,
            'Content-Length must match the actual byte size of the PDF body');

        // Sanity check: fake PDF starts with %PDF
        $this->assertStringStartsWith('%PDF', $pdfBytes,
            'Downloaded content must begin with %PDF header');
    }

    /**
     * The expires_at timestamp returned in the convert response must be
     * approximately now + TTL seconds (within 5 seconds tolerance).
     *
     * Requirements: 4.3, 8.1
     */
    public function testExpiresAtIsApproximatelyNowPlusTtl(): void
    {
        $ttl = 1800; // 30 minutes
        [$convertController] = $this->makePipeline(ttlSeconds: $ttl);

        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $response = $convertController->handle(
            $this->makeConvertRequest(),
            (new ResponseFactory())->createResponse()
        );

        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $decoded   = json_decode((string) $response->getBody(), true);
        $expiresAt = \DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s\Z',
            $decoded['expires_at'],
            new \DateTimeZone('UTC')
        );

        $this->assertNotFalse($expiresAt, 'expires_at must parse as a valid datetime');

        $minExpected = $before->modify("+{$ttl} seconds");
        $maxExpected = $after->modify("+{$ttl} seconds")->modify('+5 seconds');

        $this->assertGreaterThanOrEqual(
            $minExpected->getTimestamp(),
            $expiresAt->getTimestamp(),
            'expires_at must be at least now + TTL'
        );
        $this->assertLessThanOrEqual(
            $maxExpected->getTimestamp(),
            $expiresAt->getTimestamp(),
            'expires_at must be at most now + TTL + 5s tolerance'
        );
    }

    /**
     * The PDF file must be physically present in the storage directory after
     * a successful conversion, with its companion .json sidecar file.
     *
     * Requirements: 4.1, 8.1
     */
    public function testPdfAndSidecarFilesAreWrittenToStorageDir(): void
    {
        [$convertController] = $this->makePipeline();

        $response = $convertController->handle(
            $this->makeConvertRequest(),
            (new ResponseFactory())->createResponse()
        );

        $decoded      = json_decode((string) $response->getBody(), true);
        $filename     = basename($decoded['download_url']);
        $sidecarName  = substr($filename, 0, -4) . '.json'; // replace .pdf → .json

        $this->assertFileExists($this->storageDir . '/' . $filename,
            'PDF file must exist in the storage directory after conversion');
        $this->assertFileExists($this->storageDir . '/' . $sidecarName,
            'Sidecar .json file must exist alongside the PDF');

        $sidecarData = json_decode(
            file_get_contents($this->storageDir . '/' . $sidecarName),
            true
        );

        $this->assertIsArray($sidecarData);
        $this->assertArrayHasKey('created_at', $sidecarData);
        $this->assertArrayHasKey('expires_at', $sidecarData);
        $this->assertArrayHasKey('source_url', $sidecarData);
    }

    /**
     * A request for a non-existent file must return HTTP 404.
     *
     * Requirements: 7.4
     */
    public function testDownloadNonExistentFileReturns404(): void
    {
        [, $fileController] = $this->makePipeline();

        $fakeFilename = str_repeat('a', 40) . '.pdf'; // valid format, does not exist
        $response = $fileController->download(
            $this->makeDownloadRequest($fakeFilename),
            (new ResponseFactory())->createResponse(),
            ['filename' => $fakeFilename]
        );

        $this->assertSame(404, $response->getStatusCode(),
            'Download of a non-existent file must return HTTP 404');

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('message', $body,
            'Error response must contain a message field');
    }

    /**
     * A filename that does not match the expected hex pattern must return
     * HTTP 400 without touching the filesystem.
     *
     * Requirements: 7.3
     */
    public function testDownloadInvalidFilenameReturns400(): void
    {
        [, $fileController] = $this->makePipeline();

        $invalidFilename = '../etc/passwd';
        $response = $fileController->download(
            $this->makeDownloadRequest($invalidFilename),
            (new ResponseFactory())->createResponse(),
            ['filename' => $invalidFilename]
        );

        $this->assertSame(400, $response->getStatusCode(),
            'Invalid filename format must return HTTP 400');
    }

    /**
     * A file whose TTL has elapsed must return HTTP 410 Gone.
     *
     * Requirements: 7.5
     */
    public function testDownloadExpiredFileReturns410(): void
    {
        // Use a 1-second TTL so the file expires after the conversion.
        [$convertController, $fileController] = $this->makePipeline(ttlSeconds: 1);

        $convertResponse = $convertController->handle(
            $this->makeConvertRequest(),
            (new ResponseFactory())->createResponse()
        );

        $decoded  = json_decode((string) $convertResponse->getBody(), true);
        $filename = basename($decoded['download_url']);

        // Wait for the TTL to elapse (2 seconds to be safe).
        sleep(2);

        $downloadResponse = $fileController->download(
            $this->makeDownloadRequest($filename),
            (new ResponseFactory())->createResponse(),
            ['filename' => $filename]
        );

        $this->assertSame(410, $downloadResponse->getStatusCode(),
            'Downloading an expired file must return HTTP 410 Gone');
    }

    // -----------------------------------------------------------------------
    // Task 16.2 — Concurrency guard and semaphore behaviour
    // -----------------------------------------------------------------------

    /**
     * The concurrency guard must limit simultaneous renders to
     * maxConcurrentRenderers (5). Once all slots are consumed, a 6th acquire()
     * must throw ConcurrencyException (→ 503).
     *
     * Validates: Requirements 6.1, 6.4
     */
    public function testAtMostMaxConcurrentRenderersRunAtOnce(): void
    {
        $config = new Config(
            port: 8080,
            wkhtmltopdfPath: '',
            apiKeys: ['test-key'],
            storageDir: $this->storageDir,
            baseUrl: 'https://example.com',
            maxConcurrentRenderers: 5,
        );

        $guard = new IntegrationConcurrencyGuard($config);

        // All 5 slots should be available initially.
        $this->assertSame(5, $guard->getAvailableSlots(),
            'All slots must be available before any acquires');

        // Acquire all 5 slots without releasing them.
        for ($i = 0; $i < 5; $i++) {
            $guard->acquire(60);
        }

        $this->assertSame(0, $guard->getAvailableSlots(),
            'No slots must remain after maxConcurrentRenderers acquires');

        // A 6th acquire with a very short timeout must throw ConcurrencyException
        // because all slots are taken (Requirement 6.4: timeout → 503).
        $caught = null;
        try {
            $guard->acquire(0); // timeout immediately
        } catch (ConcurrencyException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught,
            'A 6th acquire when all slots are full must throw ConcurrencyException');
        $this->assertSame(503, $caught->getStatusCode(),
            'ConcurrencyException must map to HTTP 503');

        // Release all 5 slots.
        for ($i = 0; $i < 5; $i++) {
            $guard->release();
        }

        $this->assertSame(5, $guard->getAvailableSlots(),
            'All slots must be available again after releasing all acquires');
    }

    /**
     * When the slot pool is full, further acquire() calls with timeout=0 must
     * throw ConcurrencyException immediately (queue timeout → 503).
     * After releasing held slots, the guard recovers to its initial state.
     *
     * Validates: Requirements 6.2, 6.3
     */
    public function testExcessRequestsQueueOrReturn503(): void
    {
        $config = new Config(
            port: 8080,
            wkhtmltopdfPath: '',
            apiKeys: ['test-key'],
            storageDir: $this->storageDir,
            baseUrl: 'https://example.com',
            maxConcurrentRenderers: 2,
        );

        $guard = new IntegrationConcurrencyGuard($config);

        // Fill both slots.
        $guard->acquire(60);
        $guard->acquire(60);

        $this->assertSame(0, $guard->getAvailableSlots(),
            'Both slots must be consumed after 2 acquires');

        // Three more immediate-timeout acquires must all throw ConcurrencyException.
        $exceptionCount = 0;
        for ($i = 0; $i < 3; $i++) {
            try {
                $guard->acquire(0); // no wait — pool is full
            } catch (ConcurrencyException $e) {
                $exceptionCount++;
                $this->assertStringContainsString('queue timeout', $e->getMessage(),
                    'Timed-out acquire must report "queue timeout"');
                $this->assertSame(503, $e->getStatusCode(),
                    'ConcurrencyException must map to HTTP 503');
            }
        }

        $this->assertSame(3, $exceptionCount,
            'All 3 excess acquires must throw ConcurrencyException');

        // Release the 2 held slots and verify full recovery.
        $guard->release();
        $guard->release();

        $this->assertSame(2, $guard->getAvailableSlots(),
            'Both slots must be available again after releasing');
    }

    /**
     * When the in-flight queue depth exceeds MAX_QUEUE_SIZE (20), acquire()
     * must throw ConcurrencyException with message 'queue full' immediately,
     * without touching the available slots.
     *
     * Validates: Requirement 6.3
     */
    public function testQueueFullReturns503ImmediatelyWithoutWaiting(): void
    {
        $config = new Config(
            port: 8080,
            wkhtmltopdfPath: '',
            apiKeys: ['test-key'],
            storageDir: $this->storageDir,
            baseUrl: 'https://example.com',
            maxConcurrentRenderers: 5,
        );

        // Use an anonymous subclass that exposes a depth-forcing method via
        // Reflection so we can simulate 20 in-flight requests without actually
        // holding acquire() calls open.
        $guard = new class($config) extends IntegrationConcurrencyGuard {
            public function forceDepth(int $d): void
            {
                $ref = new \ReflectionProperty(IntegrationConcurrencyGuard::class, 'depth');
                $ref->setAccessible(true);
                $ref->setValue($this, $d);
            }
        };

        // Acquire one slot so there is one less slot available (depth = 1, slots = 4).
        $guard->acquire(60);
        $slotsAfterOneAcquire = $guard->getAvailableSlots();
        $this->assertSame(4, $slotsAfterOneAcquire,
            '4 slots must remain after 1 acquire');

        // Force the depth counter to MAX_QUEUE_SIZE (20) to simulate 20
        // requests already in flight.
        $guard->forceDepth(20);

        // The next acquire() must detect depth(21) > MAX_QUEUE_SIZE(20)
        // and throw ConcurrencyException('queue full') immediately.
        $caught = null;
        try {
            $guard->acquire(60); // timeout doesn't matter — must throw before polling
        } catch (ConcurrencyException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught,
            'acquire() must throw ConcurrencyException when queue depth exceeds MAX_QUEUE_SIZE');
        $this->assertStringContainsString('queue full', $caught->getMessage(),
            'Exception message must indicate "queue full"');
        $this->assertSame(503, $caught->getStatusCode(),
            'ConcurrencyException must map to HTTP 503');

        // Available slots must be unchanged — the guard returned early before
        // decrementing the slot counter.
        $this->assertSame(4, $guard->getAvailableSlots(),
            'Available slots must not change when the queue-full path is taken');
    }
}
