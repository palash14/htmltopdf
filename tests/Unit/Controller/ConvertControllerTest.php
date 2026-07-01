<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\ConvertController;
use App\Exception\ConcurrencyException;
use App\Exception\RendererException;
use App\Exception\RendererTimeoutException;
use App\Exception\StorageFullException;
use App\Exception\ValidationException;
use App\Model\Config;
use App\Model\StoredFile;
use App\Service\ConcurrencyGuard;
use App\Service\InputValidator;
use App\Service\RendererService;
use App\Service\SsrfGuard;
use App\Service\StorageService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Unit tests for ConvertController::handle().
 *
 * All five service dependencies are fully mocked so this suite tests only
 * the controller's orchestration logic.
 *
 * Covers:
 *  - Happy path (all services succeed) → 200 JSON with download_url and expires_at
 *  - ValidationException(400) from InputValidator → exception propagates
 *  - ValidationException(422) from SsrfGuard → exception propagates
 *  - StorageFullException → exception propagates with status 507
 *  - ConcurrencyException → exception propagates with status 503
 *  - RendererException → exception propagates; concurrencyGuard::release() called in finally
 *  - RendererTimeoutException → exception propagates; concurrencyGuard::release() called in finally
 *
 * Requirements: 1.1, 1.2, 4.1–4.4, 8.5
 */
class ConvertControllerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a Config with minimal required fields and optional maxStorageMb.
     */
    private function makeConfig(?int $maxStorageMb = null): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: ['test-key'],
            storageDir: sys_get_temp_dir(),
            baseUrl: 'https://example.com',
            maxStorageMb: $maxStorageMb,
        );
    }

    /**
     * Build a PSR-7 server request with the given parsed body.
     *
     * @param array<string, mixed> $parsedBody
     */
    private function makeRequest(array $parsedBody = ['url' => 'https://public.example.com/page']): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://example.com/api/convert')
            ->withParsedBody($parsedBody)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Build a ConvertController with all five services replaced by mock objects.
     *
     * @param InputValidator   $inputValidator
     * @param SsrfGuard        $ssrfGuard
     * @param StorageService   $storageService
     * @param RendererService  $rendererService
     * @param ConcurrencyGuard $concurrencyGuard
     * @param Config|null      $config
     */
    private function makeController(
        InputValidator   $inputValidator,
        SsrfGuard        $ssrfGuard,
        StorageService   $storageService,
        RendererService  $rendererService,
        ConcurrencyGuard $concurrencyGuard,
        ?Config          $config = null,
    ): ConvertController {
        return new ConvertController(
            inputValidator:   $inputValidator,
            ssrfGuard:        $ssrfGuard,
            storageService:   $storageService,
            rendererService:  $rendererService,
            concurrencyGuard: $concurrencyGuard,
            config:           $config ?? $this->makeConfig(),
        );
    }

    /**
     * Build a StoredFile with the given download URL and expires_at.
     */
    private function makeStoredFile(
        string $filename = 'abc123def456abc123def456abc123de.pdf',
        string $baseUrl  = 'https://example.com',
        int    $ttlOffset = 3600,
    ): StoredFile {
        $now       = new DateTimeImmutable();
        $expiresAt = new DateTimeImmutable('+' . $ttlOffset . ' seconds');

        return new StoredFile(
            filename:    $filename,
            path:        '/tmp/' . $filename,
            downloadUrl: $baseUrl . '/api/files/' . $filename,
            createdAt:   $now,
            expiresAt:   $expiresAt,
        );
    }

    // -----------------------------------------------------------------------
    // Test 1: Happy path — all services succeed → 200 JSON
    // -----------------------------------------------------------------------

    /**
     * When all five mocked services succeed, handle() must return HTTP 200
     * with Content-Type: application/json, a `download_url` field that starts
     * with the base URL and ends with `.pdf`, and an `expires_at` field that
     * matches the ISO 8601 UTC pattern.
     *
     * Requirements: 1.1, 1.2, 4.1, 4.2, 4.3
     */
    public function testHappyPathReturns200JsonWithCorrectShape(): void
    {
        $storedFile = new StoredFile(
            filename:    'abc123.pdf',
            path:        '/tmp/abc123.pdf',
            downloadUrl: 'https://example.com/api/files/abc123.pdf',
            createdAt:   new DateTimeImmutable(),
            expiresAt:   new DateTimeImmutable('+1 hour'),
        );

        $inputValidator = $this->createMock(InputValidator::class);
        $inputValidator->expects($this->once())
            ->method('validateConvertRequest')
            ->willReturn('https://public.example.com/page');

        $ssrfGuard = $this->createMock(SsrfGuard::class);
        $ssrfGuard->expects($this->once())->method('check');

        $storageService = $this->createMock(StorageService::class);
        $storageService->method('storageSizeBytes')->willReturn(0);
        $storageService->expects($this->once())->method('save')->willReturn($storedFile);

        $rendererService = $this->createMock(RendererService::class);
        $rendererService->expects($this->once())->method('render');

        $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);
        $concurrencyGuard->expects($this->once())->method('acquire');
        $concurrencyGuard->expects($this->once())->method('release');

        $controller = $this->makeController(
            $inputValidator,
            $ssrfGuard,
            $storageService,
            $rendererService,
            $concurrencyGuard,
        );

        $result = $controller->handle($this->makeRequest(), (new ResponseFactory())->createResponse());

        // --- HTTP status 200 ---
        $this->assertSame(200, $result->getStatusCode(),
            'Happy path must return HTTP 200');

        // --- Content-Type: application/json ---
        $this->assertStringContainsString(
            'application/json',
            $result->getHeaderLine('Content-Type'),
            'Happy path must return Content-Type: application/json'
        );

        // --- Decode body ---
        $body    = (string) $result->getBody();
        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded, 'Response body must be valid JSON');

        // --- download_url field ---
        $this->assertArrayHasKey('download_url', $decoded,
            'Response must contain download_url');
        $this->assertStringStartsWith('https://example.com', $decoded['download_url'],
            'download_url must start with base URL');
        $this->assertStringEndsWith('.pdf', $decoded['download_url'],
            'download_url must end with .pdf');

        // --- expires_at field (ISO 8601 UTC) ---
        $this->assertArrayHasKey('expires_at', $decoded,
            'Response must contain expires_at');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $decoded['expires_at'],
            'expires_at must be a valid ISO 8601 UTC datetime'
        );
    }

    // -----------------------------------------------------------------------
    // Test 2: ValidationException(400) from InputValidator → propagates
    // -----------------------------------------------------------------------

    /**
     * When InputValidator throws ValidationException(400) the exception must
     * propagate out of handle() without touching any other service.
     *
     * Requirements: 1.1, 4.4
     */
    public function testValidationException400FromInputValidatorPropagates(): void
    {
        $inputValidator = $this->createMock(InputValidator::class);
        $inputValidator->method('validateConvertRequest')
            ->willThrowException(new ValidationException(400, 'Missing required field: url'));

        $ssrfGuard        = $this->createMock(SsrfGuard::class);
        $ssrfGuard->expects($this->never())->method('check');

        $storageService   = $this->createMock(StorageService::class);
        $storageService->expects($this->never())->method('save');

        $rendererService  = $this->createMock(RendererService::class);
        $rendererService->expects($this->never())->method('render');

        $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);
        $concurrencyGuard->expects($this->never())->method('acquire');
        $concurrencyGuard->expects($this->never())->method('release');

        $controller = $this->makeController(
            $inputValidator, $ssrfGuard, $storageService, $rendererService, $concurrencyGuard
        );

        $this->expectException(ValidationException::class);

        $thrown = null;
        try {
            $controller->handle($this->makeRequest(), (new ResponseFactory())->createResponse());
        } catch (ValidationException $e) {
            $thrown = $e;
            throw $e;
        }
    }

    /**
     * The propagated ValidationException from InputValidator must carry the
     * correct 400 status code.
     *
     * Requirements: 1.1
     */
    public function testValidationException400CarriesCorrectStatusCode(): void
    {
        $exception = new ValidationException(400, 'Missing required field: url');

        $inputValidator = $this->createMock(InputValidator::class);
        $inputValidator->method('validateConvertRequest')->willThrowException($exception);

        $controller = $this->makeController(
            $inputValidator,
            $this->createMock(SsrfGuard::class),
            $this->createMock(StorageService::class),
            $this->createMock(RendererService::class),
            $this->createMock(ConcurrencyGuard::class),
        );

        $caught = null;
        try {
            $controller->handle($this->makeRequest(), (new ResponseFactory())->createResponse());
        } catch (ValidationException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'ValidationException must propagate');
        $this->assertSame(400, $caught->getStatusCode(),
            'Exception from InputValidator must have status 400');
    }

    // -----------------------------------------------------------------------
    // Test 3: ValidationException(422) from SsrfGuard → propagates
    // -----------------------------------------------------------------------

    /**
     * When SsrfGuard throws ValidationException(422) it must propagate out of
     * handle() and no rendering or storage work should be done.
     *
     * Requirements: 1.2, 4.4
     */
    public function testValidationException422FromSsrfGuardPropagates(): void
    {
        $inputValidator = $this->createMock(InputValidator::class);
        $inputValidator->method('validateConvertRequest')
            ->willReturn('https://internal.corp/page');

        $ssrfGuard = $this->createMock(SsrfGuard::class);
        $ssrfGuard->method('check')
            ->willThrowException(new ValidationException(422, 'URL resolves to a private IP'));

        $storageService = $this->createMock(StorageService::class);
        $storageService->expects($this->never())->method('save');

        $rendererService = $this->createMock(RendererService::class);
        $rendererService->expects($this->never())->method('render');

        $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);
        $concurrencyGuard->expects($this->never())->method('acquire');
        $concurrencyGuard->expects($this->never())->method('release');

        $controller = $this->makeController(
            $inputValidator, $ssrfGuard, $storageService, $rendererService, $concurrencyGuard
        );

        $caught = null;
        try {
            $controller->handle($this->makeRequest(), (new ResponseFactory())->createResponse());
        } catch (ValidationException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'ValidationException from SsrfGuard must propagate');
        $this->assertSame(422, $caught->getStatusCode(),
            'SsrfGuard exception must carry status 422');
    }

    // -----------------------------------------------------------------------
    // Test 4: StorageFullException → propagates with status 507
    // -----------------------------------------------------------------------

    /**
     * When the storage is at or above the configured maximum the controller
     * must throw StorageFullException (507) before any concurrency or
     * rendering work is attempted.
     *
     * Requirements: 8.5, 4.4
     */
    public function testStorageFullExceptionPropagatesWith507(): void
    {
        // maxStorageMb = 1 MB; storageSizeBytes returns exactly 1 MB (== limit)
        $config = $this->makeConfig(maxStorageMb: 1);

        $inputValidator = $this->createMock(InputValidator::class);
        $inputValidator->method('validateConvertRequest')
            ->willReturn('https://public.example.com/page');

        $ssrfGuard = $this->createMock(SsrfGuard::class);
        $ssrfGuard->method('check');

        $storageService = $this->createMock(StorageService::class);
        // 1 MB exactly — equals the limit so StorageFullException must be thrown
        $storageService->method('storageSizeBytes')->willReturn(1 * 1024 * 1024);
        $storageService->expects($this->never())->method('save');

        $rendererService = $this->createMock(RendererService::class);
        $rendererService->expects($this->never())->method('render');

        $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);
        $concurrencyGuard->expects($this->never())->method('acquire');

        $controller = $this->makeController(
            $inputValidator,
            $ssrfGuard,
            $storageService,
            $rendererService,
            $concurrencyGuard,
            $config,
        );

        $caught = null;
        try {
            $controller->handle($this->makeRequest(), (new ResponseFactory())->createResponse());
        } catch (StorageFullException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'StorageFullException must be thrown when storage is full');
        $this->assertSame(507, $caught->getStatusCode(),
            'StorageFullException must carry HTTP status 507');
    }

    /**
     * When maxStorageMb is null the storage-full check is disabled; requests
     * must proceed even when storageSizeBytes() returns a very large value.
     *
     * Requirements: 8.6
     */
    public function testStorageFullCheckSkippedWhenMaxStorageMbIsNull(): void
    {
        // maxStorageMb = null → disabled
        $config = $this->makeConfig(maxStorageMb: null);

        $storedFile = new StoredFile(
            filename:    'abc123.pdf',
            path:        '/tmp/abc123.pdf',
            downloadUrl: 'https://example.com/api/files/abc123.pdf',
            createdAt:   new DateTimeImmutable(),
            expiresAt:   new DateTimeImmutable('+1 hour'),
        );

        $inputValidator = $this->createMock(InputValidator::class);
        $inputValidator->method('validateConvertRequest')
            ->willReturn('https://public.example.com/page');

        $ssrfGuard = $this->createMock(SsrfGuard::class);
        $ssrfGuard->method('check');

        $storageService = $this->createMock(StorageService::class);
        // Return a huge size — should be ignored when maxStorageMb is null
        $storageService->method('storageSizeBytes')->willReturn(PHP_INT_MAX);
        $storageService->method('save')->willReturn($storedFile);

        $rendererService = $this->createMock(RendererService::class);
        $rendererService->method('render');

        $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);
        $concurrencyGuard->method('acquire');
        $concurrencyGuard->method('release');

        $controller = $this->makeController(
            $inputValidator,
            $ssrfGuard,
            $storageService,
            $rendererService,
            $concurrencyGuard,
            $config,
        );

        // Must not throw StorageFullException
        $result = $controller->handle($this->makeRequest(), (new ResponseFactory())->createResponse());

        $this->assertSame(200, $result->getStatusCode(),
            'Requests must succeed when maxStorageMb is null (storage check disabled)');
    }

    // -----------------------------------------------------------------------
    // Test 5: ConcurrencyException → propagates with status 503
    // -----------------------------------------------------------------------

    /**
     * When ConcurrencyGuard::acquire() throws ConcurrencyException(503), the
     * exception must propagate. No rendering or storage should occur.
     *
     * Requirements: 6.3, 6.4, 4.4
     */
    public function testConcurrencyExceptionPropagatesWith503(): void
    {
        $inputValidator = $this->createMock(InputValidator::class);
        $inputValidator->method('validateConvertRequest')
            ->willReturn('https://public.example.com/page');

        $ssrfGuard = $this->createMock(SsrfGuard::class);
        $ssrfGuard->method('check');

        $storageService = $this->createMock(StorageService::class);
        $storageService->method('storageSizeBytes')->willReturn(0);
        $storageService->expects($this->never())->method('save');

        $rendererService = $this->createMock(RendererService::class);
        $rendererService->expects($this->never())->method('render');

        $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);
        $concurrencyGuard->method('acquire')
            ->willThrowException(new ConcurrencyException('queue full'));
        // release() must NOT be called because acquire() threw before the try block
        $concurrencyGuard->expects($this->never())->method('release');

        $controller = $this->makeController(
            $inputValidator, $ssrfGuard, $storageService, $rendererService, $concurrencyGuard
        );

        $caught = null;
        try {
            $controller->handle($this->makeRequest(), (new ResponseFactory())->createResponse());
        } catch (ConcurrencyException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'ConcurrencyException must propagate');
        $this->assertSame(503, $caught->getStatusCode(),
            'ConcurrencyException must carry HTTP status 503');
    }

    // -----------------------------------------------------------------------
    // Test 6: RendererException → propagates; release() called in finally
    // -----------------------------------------------------------------------

    /**
     * When RendererService::render() throws RendererException, the exception
     * must propagate out of handle(). ConcurrencyGuard::release() must still
     * be called exactly once because it is in a finally block.
     *
     * Requirements: 2.4, 8.5 (finally release)
     */
    public function testRendererExceptionPropagatesAndReleaseCalledInFinally(): void
    {
        $inputValidator = $this->createMock(InputValidator::class);
        $inputValidator->method('validateConvertRequest')
            ->willReturn('https://public.example.com/page');

        $ssrfGuard = $this->createMock(SsrfGuard::class);
        $ssrfGuard->method('check');

        $storageService = $this->createMock(StorageService::class);
        $storageService->method('storageSizeBytes')->willReturn(0);
        $storageService->expects($this->never())->method('save');

        $rendererService = $this->createMock(RendererService::class);
        $rendererService->method('render')
            ->willThrowException(new RendererException('wkhtmltopdf exited with code 1'));

        $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);
        $concurrencyGuard->expects($this->once())->method('acquire');
        // release() MUST be called even though render() threw (finally block)
        $concurrencyGuard->expects($this->once())->method('release');

        $controller = $this->makeController(
            $inputValidator, $ssrfGuard, $storageService, $rendererService, $concurrencyGuard
        );

        $caught = null;
        try {
            $controller->handle($this->makeRequest(), (new ResponseFactory())->createResponse());
        } catch (RendererException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught,
            'RendererException must propagate out of handle()');
        $this->assertSame(502, $caught->getStatusCode(),
            'RendererException must carry HTTP status 502');

        // PHPUnit already verified release() was called once via the mock expectation above.
    }

    // -----------------------------------------------------------------------
    // Test 7: RendererTimeoutException → propagates; release() called in finally
    // -----------------------------------------------------------------------

    /**
     * When RendererService::render() throws RendererTimeoutException, the
     * exception must propagate out of handle(). ConcurrencyGuard::release()
     * must still be called once (finally block).
     *
     * Requirements: 2.5, 8.5 (finally release)
     */
    public function testRendererTimeoutExceptionPropagatesAndReleaseCalledInFinally(): void
    {
        $inputValidator = $this->createMock(InputValidator::class);
        $inputValidator->method('validateConvertRequest')
            ->willReturn('https://public.example.com/page');

        $ssrfGuard = $this->createMock(SsrfGuard::class);
        $ssrfGuard->method('check');

        $storageService = $this->createMock(StorageService::class);
        $storageService->method('storageSizeBytes')->willReturn(0);
        $storageService->expects($this->never())->method('save');

        $rendererService = $this->createMock(RendererService::class);
        $rendererService->method('render')
            ->willThrowException(new RendererTimeoutException('PDF rendering timed out after 30 second(s)'));

        $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);
        $concurrencyGuard->expects($this->once())->method('acquire');
        // release() MUST be called even when a timeout occurs (finally block)
        $concurrencyGuard->expects($this->once())->method('release');

        $controller = $this->makeController(
            $inputValidator, $ssrfGuard, $storageService, $rendererService, $concurrencyGuard
        );

        $caught = null;
        try {
            $controller->handle($this->makeRequest(), (new ResponseFactory())->createResponse());
        } catch (RendererTimeoutException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught,
            'RendererTimeoutException must propagate out of handle()');
        $this->assertSame(504, $caught->getStatusCode(),
            'RendererTimeoutException must carry HTTP status 504');

        // PHPUnit already verified release() was called once via the mock expectation above.
    }

    // -----------------------------------------------------------------------
    // Test 8: Happy path with StoredFile built directly (as spec instructs)
    // -----------------------------------------------------------------------

    /**
     * Verify happy path using the exact StoredFile construction pattern
     * specified in the task description.
     *
     * Requirements: 4.1, 4.2, 4.3
     */
    public function testHappyPathWithDirectlyConstructedStoredFile(): void
    {
        $storedFile = new StoredFile(
            filename:    'abc123.pdf',
            path:        '/tmp/abc123.pdf',
            downloadUrl: 'https://example.com/api/files/abc123.pdf',
            createdAt:   new DateTimeImmutable(),
            expiresAt:   new DateTimeImmutable('+1 hour'),
        );

        $inputValidator   = $this->createMock(InputValidator::class);
        $inputValidator->method('validateConvertRequest')->willReturn('https://public.example.com/page');

        $ssrfGuard        = $this->createMock(SsrfGuard::class);

        $storageService   = $this->createMock(StorageService::class);
        $storageService->method('storageSizeBytes')->willReturn(0);
        $storageService->method('save')->willReturn($storedFile);

        $rendererService  = $this->createMock(RendererService::class);

        $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);

        $controller = $this->makeController(
            $inputValidator, $ssrfGuard, $storageService, $rendererService, $concurrencyGuard
        );

        $result  = $controller->handle($this->makeRequest(), (new ResponseFactory())->createResponse());
        $decoded = json_decode((string) $result->getBody(), true);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('https://example.com/api/files/abc123.pdf', $decoded['download_url']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $decoded['expires_at']);
    }

    // -----------------------------------------------------------------------
    // Test 9: Request with raw JSON body (no parsed body) is handled
    // -----------------------------------------------------------------------

    /**
     * The controller falls back to parsing the raw body when getParsedBody()
     * returns null. This exercises the json_decode fallback path.
     *
     * Requirements: 1.1, 1.5
     */
    public function testRawJsonBodyFallbackIsHandled(): void
    {
        $storedFile = new StoredFile(
            filename:    'rawbody123.pdf',
            path:        '/tmp/rawbody123.pdf',
            downloadUrl: 'https://example.com/api/files/rawbody123.pdf',
            createdAt:   new DateTimeImmutable(),
            expiresAt:   new DateTimeImmutable('+1 hour'),
        );

        $inputValidator = $this->createMock(InputValidator::class);
        $inputValidator->method('validateConvertRequest')
            ->willReturn('https://public.example.com/page');

        $ssrfGuard        = $this->createMock(SsrfGuard::class);
        $storageService   = $this->createMock(StorageService::class);
        $storageService->method('storageSizeBytes')->willReturn(0);
        $storageService->method('save')->willReturn($storedFile);
        $rendererService  = $this->createMock(RendererService::class);
        $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);

        $controller = $this->makeController(
            $inputValidator, $ssrfGuard, $storageService, $rendererService, $concurrencyGuard
        );

        // Build a request with a raw body but no parsed body
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', 'https://example.com/api/convert');
        // Write raw JSON into the body
        $request->getBody()->write(json_encode(['url' => 'https://public.example.com/page']));

        $result = $controller->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(200, $result->getStatusCode(),
            'Controller must handle raw JSON body fallback correctly');
    }
}
