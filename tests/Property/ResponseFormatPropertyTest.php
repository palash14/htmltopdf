<?php

declare(strict_types=1);

namespace Tests\Property;

use App\Controller\ConvertController;
use App\Exception\AuthException;
use App\Exception\ConcurrencyException;
use App\Exception\RendererException;
use App\Exception\RendererTimeoutException;
use App\Exception\StorageFullException;
use App\Exception\ValidationException;
use App\Handler\JsonErrorHandler;
use App\Middleware\RequestLogMiddleware;
use App\Model\Config;
use App\Model\StoredFile;
use App\Service\ConcurrencyGuard;
use App\Service\InputValidator;
use App\Service\RendererService;
use App\Service\SsrfGuard;
use App\Service\StorageService;
use DateTimeImmutable;
use Eris\Generators;
use Eris\TestTrait;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Property-based tests for response format invariants.
 *
 * Feature: url-to-pdf-api
 *
 * Covers:
 *   Property 4 — Successful response always has correct shape
 *   Property 5 — Error responses always have consistent shape
 *
 * Validates: Requirements 4.1–4.4
 */
class ResponseFormatPropertyTest extends TestCase
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

    /**
     * Build a Config value object with the given baseUrl and TTL.
     */
    private function makeConfig(string $baseUrl = 'https://example.com', int $ttlSeconds = 3600): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: ['test-key'],
            storageDir: sys_get_temp_dir(),
            baseUrl: $baseUrl,
            ttlSeconds: $ttlSeconds,
        );
    }

    /**
     * Build a null logger (discards all records).
     */
    private function makeLogger(): Logger
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());
        return $logger;
    }

    /**
     * Build a ConvertController wired with all-mocked services where the
     * renderer and storage succeed, returning the given StoredFile.
     */
    private function makeSuccessController(StoredFile $storedFile, Config $config): ConvertController
    {
        $inputValidator = $this->createMock(InputValidator::class);
        $inputValidator->method('validateConvertRequest')->willReturn('https://example.com/page');

        $ssrfGuard = $this->createMock(SsrfGuard::class);
        $ssrfGuard->method('check');

        $storageService = $this->createMock(StorageService::class);
        // storageSizeBytes() returns 0 so the storage-full check is never triggered
        $storageService->method('storageSizeBytes')->willReturn(0);
        $storageService->method('save')->willReturn($storedFile);

        $rendererService = $this->createMock(RendererService::class);
        $rendererService->method('render');

        $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);
        $concurrencyGuard->method('acquire');
        $concurrencyGuard->method('release');

        return new ConvertController(
            inputValidator:   $inputValidator,
            ssrfGuard:        $ssrfGuard,
            storageService:   $storageService,
            rendererService:  $rendererService,
            concurrencyGuard: $concurrencyGuard,
            config:           $config,
        );
    }

    /**
     * Build a PSR-7 JSON request with a url field in the body.
     */
    private function makeJsonRequest(string $url = 'https://example.com/page'): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', 'https://example.com/api/convert');

        // Use withParsedBody to feed decoded body since the controller also
        // accepts a pre-parsed body from Slim's middleware.
        return $request->withParsedBody(['url' => $url])
                       ->withHeader('Content-Type', 'application/json');
    }

    // -----------------------------------------------------------------------
    // Property 4: Successful response always has correct shape
    //
    // For any successful PDF conversion, the JSON response SHALL contain a
    // `download_url` field (a string starting with the configured base URL
    // and ending with `.pdf`) and an `expires_at` field (a valid ISO 8601
    // UTC datetime string), with `Content-Type: application/json` and HTTP
    // status 200.
    //
    // // Feature: url-to-pdf-api, Property 4: Successful response always has correct shape
    //
    // Validates: Requirements 4.1, 4.2, 4.3
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 4: Successful PDF conversion always returns correct shape
     *
     * Varies:
     *  - TTL values (60–86400)
     *  - Base URL patterns (different hosts)
     *  - Filename lengths (32–64 hex chars)
     *
     * // Feature: url-to-pdf-api, Property 4: Successful response always has correct shape
     *
     * Validates: Requirements 4.1, 4.2, 4.3
     */
    public function testSuccessfulResponseAlwaysHasCorrectShape(): void
    {
        // Generate varying base URL hosts and TTL values
        $baseUrls = [
            'https://example.com',
            'https://api.myservice.io',
            'https://pdf.company.org',
            'http://localhost:8080',
            'https://subdomain.example.co.uk',
        ];

        $this->forAll(
            Generators::choose(60, 86400),        // TTL seconds
            Generators::elements($baseUrls),      // base URL
            Generators::choose(32, 64)            // hex filename length (before .pdf)
        )
            ->withMaxSize(100)
            ->then(function (int $ttl, string $baseUrl, int $hexLen): void {
                $config = $this->makeConfig($baseUrl, $ttl);

                // Build a hex filename of the requested length
                $hexPart  = str_repeat('a', $hexLen);
                $filename = $hexPart . '.pdf';

                $now       = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $expiresAt = $now->modify("+{$ttl} seconds");

                $storedFile = new StoredFile(
                    filename:    $filename,
                    path:        '/tmp/' . $filename,
                    downloadUrl: $baseUrl . '/api/files/' . $filename,
                    createdAt:   $now,
                    expiresAt:   $expiresAt,
                );

                $controller = $this->makeSuccessController($storedFile, $config);

                $request  = $this->makeJsonRequest();
                $response = (new ResponseFactory())->createResponse();

                $result = $controller->handle($request, $response);

                // --- Assert HTTP status 200 ---
                $this->assertSame(
                    200,
                    $result->getStatusCode(),
                    "Successful conversion must return HTTP 200"
                );

                // --- Assert Content-Type: application/json ---
                $contentType = $result->getHeaderLine('Content-Type');
                $this->assertStringContainsString(
                    'application/json',
                    $contentType,
                    "Successful response must have Content-Type: application/json"
                );

                // --- Decode JSON body ---
                $body = (string) $result->getBody();
                $decoded = json_decode($body, true);

                $this->assertIsArray($decoded, "Response body must be valid JSON");

                // --- Assert download_url present ---
                $this->assertArrayHasKey(
                    'download_url',
                    $decoded,
                    "Response must contain 'download_url' field"
                );

                $downloadUrl = $decoded['download_url'];
                $this->assertIsString($downloadUrl, "'download_url' must be a string");

                // Must start with the configured base URL
                $this->assertStringStartsWith(
                    $baseUrl,
                    $downloadUrl,
                    "'download_url' must start with configured base URL '{$baseUrl}'"
                );

                // Must end with .pdf
                $this->assertStringEndsWith(
                    '.pdf',
                    $downloadUrl,
                    "'download_url' must end with '.pdf'"
                );

                // --- Assert expires_at present and valid ISO 8601 UTC ---
                $this->assertArrayHasKey(
                    'expires_at',
                    $decoded,
                    "Response must contain 'expires_at' field"
                );

                $expiresAtStr = $decoded['expires_at'];
                $this->assertIsString($expiresAtStr, "'expires_at' must be a string");

                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
                    $expiresAtStr,
                    "'expires_at' must be a valid ISO 8601 UTC datetime (YYYY-MM-DDTHH:MM:SSZ), got '{$expiresAtStr}'"
                );
            });
    }

    // -----------------------------------------------------------------------
    // Property 5: Error responses always have consistent shape
    //
    // For any request that results in an HTTP 4xx or 5xx response, the
    // response SHALL have `Content-Type: application/json` and a JSON body
    // containing a non-empty `message` field.
    //
    // // Feature: url-to-pdf-api, Property 5: Error responses always have consistent shape
    //
    // Validates: Requirement 4.4
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 5: JsonErrorHandler always returns application/json with non-empty message
     *
     * Tests the JsonErrorHandler with the full exception hierarchy used by the
     * application to ensure every error type produces a consistent shape.
     *
     * // Feature: url-to-pdf-api, Property 5: Error responses always have consistent shape
     *
     * Validates: Requirements 4.4
     */
    public function testErrorResponsesAlwaysHaveConsistentShape(): void
    {
        // Sample exceptions from the full application hierarchy
        $exceptionFactories = [
            // ValidationException (400)
            fn () => new ValidationException(400, 'Missing required field: url'),
            // ValidationException (422)
            fn () => new ValidationException(422, 'Invalid URL format'),
            // AuthException (401)
            fn () => new \App\Exception\AuthException(401, 'Missing authorization header'),
            // AuthException (403)
            fn () => new \App\Exception\AuthException(403, 'Invalid token'),
            // StorageFullException (507)
            fn () => new StorageFullException('Storage capacity exceeded'),
            // ConcurrencyException (503)
            fn () => new ConcurrencyException('Service temporarily unavailable'),
            // RendererException (502)
            fn () => new RendererException('PDF rendering failed'),
            // RendererTimeoutException (504)
            fn () => new RendererTimeoutException('PDF rendering timed out'),
            // RendererUnavailableException (500)
            fn () => new \App\Exception\RendererUnavailableException('wkhtmltopdf not found'),
            // Generic RuntimeException (500)
            fn () => new \RuntimeException('Unexpected error'),
            // RateLimitException (429)
            fn () => new \App\Exception\RateLimitException('Rate limit exceeded'),
            // SsrfException (422)
            fn () => new \App\Exception\SsrfException('SSRF attempt blocked'),
        ];

        $this->forAll(
            Generators::elements($exceptionFactories), // exception factory to invoke
            Generators::elements([true, false])        // displayErrorDetails flag
        )
            ->withMaxSize(100)
            ->then(function (callable $exceptionFactory, bool $displayErrorDetails): void {
                $exception       = $exceptionFactory();
                $exceptionClass  = get_class($exception);
                $responseFactory = new ResponseFactory();
                $logger          = $this->makeLogger();

                $handler = new JsonErrorHandler($responseFactory, $logger);

                $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://example.com/api/convert');

                $result = $handler(
                    $request,
                    $exception,
                    $displayErrorDetails,
                    logErrors: false,
                    logErrorDetails: false,
                );

                // --- Assert Content-Type: application/json ---
                $contentType = $result->getHeaderLine('Content-Type');
                $this->assertStringContainsString(
                    'application/json',
                    $contentType,
                    sprintf(
                        "Error handler must return Content-Type: application/json for %s; got '%s'",
                        $exceptionClass,
                        $contentType
                    )
                );

                // --- Assert status is 4xx or 5xx ---
                $status = $result->getStatusCode();
                $this->assertGreaterThanOrEqual(400, $status,
                    "Error response status must be >= 400, got {$status}");
                $this->assertLessThan(600, $status,
                    "Error response status must be < 600, got {$status}");

                // --- Decode JSON body ---
                $body    = (string) $result->getBody();
                $decoded = json_decode($body, true);

                $this->assertIsArray($decoded,
                    "Error response body must be valid JSON for {$exceptionClass}; got: {$body}");

                // --- Assert non-empty message field ---
                $this->assertArrayHasKey('message', $decoded,
                    "Error response body must contain 'message' field for {$exceptionClass}");

                $this->assertIsString($decoded['message'],
                    "'message' field must be a string for {$exceptionClass}");

                $this->assertNotEmpty($decoded['message'],
                    "'message' field must not be empty for {$exceptionClass}");
            });
    }

    /**
     * @test
     * Property 5 (exception with empty message): Error handler fills in a default message
     *
     * Verifies that even when the exception has an empty message string, the
     * handler returns a non-empty `message` in the JSON body.
     *
     * // Feature: url-to-pdf-api, Property 5: Error responses always have consistent shape
     *
     * Validates: Requirements 4.4
     */
    public function testErrorHandlerFillsDefaultMessageWhenExceptionMessageIsEmpty(): void
    {
        // Exceptions constructed with empty messages
        $exceptions = [
            new StorageFullException(''),
            new ConcurrencyException(''),
            new RendererException(''),
            new RendererTimeoutException(''),
        ];

        $this->forAll(
            Generators::elements($exceptions)
        )
            ->withMaxSize(100)
            ->then(function (\Throwable $exception): void {
                $exceptionClass  = get_class($exception);
                $responseFactory = new ResponseFactory();
                $logger          = $this->makeLogger();

                $handler = new JsonErrorHandler($responseFactory, $logger);
                $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://example.com/api/convert');

                $result = $handler($request, $exception, false, false, false);

                $body    = (string) $result->getBody();
                $decoded = json_decode($body, true);

                $this->assertIsArray($decoded,
                    "Response body must be valid JSON even when exception message is empty");

                $this->assertArrayHasKey('message', $decoded,
                    "Response must always have 'message' key");

                $this->assertNotEmpty($decoded['message'],
                    "Handler must supply a non-empty default message when exception message is empty; exception: {$exceptionClass}");
            });
    }

    // -----------------------------------------------------------------------
    // Property 18: Storage full causes 507 for all new conversion requests
    //
    // For any new POST to /api/convert when the current storage directory
    // size meets or exceeds the configured maximum, the response SHALL be
    // HTTP 507 (StorageFullException).
    //
    // // Feature: url-to-pdf-api, Property 18: Storage full causes 507 for all new conversion requests
    //
    // Validates: Requirement 8.5
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 18: Storage full causes 507 for all new conversion requests
     *
     * Varies:
     *  - maxStorageMb values (1–100)
     *  - excess bytes above the limit (0 to 1 MB over)
     *
     * For every combination where storageSizeBytes() >= maxStorageMb * 1024 * 1024,
     * ConvertController::handle() MUST throw StorageFullException with status 507.
     *
     * // Feature: url-to-pdf-api, Property 18: Storage full causes 507 for all new conversion requests
     *
     * Validates: Requirement 8.5
     */
    public function testStorageFullCauses507ForAllNewConversionRequests(): void
    {
        $this->forAll(
            Generators::choose(1, 100),       // maxStorageMb (keep small to avoid overflow)
            Generators::choose(0, 1024 * 1024) // excess bytes above the limit (0 = exactly at limit)
        )
            ->withMaxSize(100)
            ->then(function (int $maxStorageMb, int $excessBytes): void {
                $limitBytes   = $maxStorageMb * 1024 * 1024;
                $currentBytes = $limitBytes + $excessBytes; // >= limit

                // Build a config with maxStorageMb set
                $config = new Config(
                    port:            8080,
                    wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
                    apiKeys:         ['test-key'],
                    storageDir:      sys_get_temp_dir(),
                    baseUrl:         'https://example.com',
                    ttlSeconds:      3600,
                    maxStorageMb:    $maxStorageMb,
                );

                // Mock services
                $inputValidator = $this->createMock(InputValidator::class);
                $inputValidator->method('validateConvertRequest')
                               ->willReturn('https://example.com/page');

                $ssrfGuard = $this->createMock(SsrfGuard::class);
                $ssrfGuard->method('check');

                $storageService = $this->createMock(StorageService::class);
                // Return current bytes at or above the limit
                $storageService->method('storageSizeBytes')->willReturn($currentBytes);

                $rendererService  = $this->createMock(RendererService::class);
                $concurrencyGuard = $this->createMock(ConcurrencyGuard::class);

                $controller = new ConvertController(
                    inputValidator:   $inputValidator,
                    ssrfGuard:        $ssrfGuard,
                    storageService:   $storageService,
                    rendererService:  $rendererService,
                    concurrencyGuard: $concurrencyGuard,
                    config:           $config,
                );

                $request  = $this->makeJsonRequest();
                $response = (new ResponseFactory())->createResponse();

                $thrown = null;
                try {
                    $controller->handle($request, $response);
                } catch (StorageFullException $e) {
                    $thrown = $e;
                }

                $this->assertInstanceOf(
                    StorageFullException::class,
                    $thrown,
                    "Expected StorageFullException when storage ({$currentBytes} bytes) "
                    . ">= limit ({$limitBytes} bytes, maxStorageMb={$maxStorageMb})"
                );

                $this->assertSame(
                    507,
                    $thrown->getStatusCode(),
                    "StorageFullException must report HTTP status 507"
                );
            });
    }

    // -----------------------------------------------------------------------
    // Property 17: Request log entries contain all required fields
    //
    // For any completed request, the corresponding log entry SHALL contain
    // all of: `timestamp`, `method`, `path`, `status`, and `elapsed_ms`.
    //
    // // Feature: url-to-pdf-api, Property 17: Request log entries contain all required fields
    //
    // Validates: Requirement 9.1
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 17: RequestLogMiddleware always emits a log entry with all required fields
     *
     * Varies:
     *  - HTTP method (GET, POST, PUT, DELETE, PATCH)
     *  - Request path (/api/convert, /api/files/abc.pdf, /health, /)
     *  - Response status code (200–599)
     *
     * For each combination the middleware MUST write exactly one 'Request' log
     * record whose context contains: timestamp, method, path, status, elapsed_ms.
     *
     * // Feature: url-to-pdf-api, Property 17: Request log entries contain all required fields
     *
     * Validates: Requirement 9.1
     */
    public function testRequestLogEntriesContainAllRequiredFields(): void
    {
        $this->forAll(
            Generators::elements(['GET', 'POST', 'PUT', 'DELETE', 'PATCH']),
            Generators::elements(['/api/convert', '/api/files/abc.pdf', '/health', '/']),
            Generators::choose(200, 599)
        )
            ->withMaxSize(100)
            ->then(function (string $method, string $path, int $statusCode): void {
                // --- Arrange: wire middleware with a capturing TestHandler ---
                $testHandler = new TestHandler();
                $logger      = new Logger('test', [$testHandler]);
                $middleware  = new RequestLogMiddleware($logger);

                // Build the request
                $request = (new ServerRequestFactory())
                    ->createServerRequest($method, "https://example.com{$path}");

                // Build a pass-through handler that returns the desired status
                $handler = new class($statusCode) implements RequestHandlerInterface {
                    public function __construct(private readonly int $status) {}

                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return (new ResponseFactory())->createResponse($this->status);
                    }
                };

                // --- Act: process the request through the middleware ---
                $middleware->process($request, $handler);

                // --- Assert: exactly one 'Request' log record was written ---
                // Monolog 3 returns LogRecord objects from getRecords().
                $records = $testHandler->getRecords();

                $requestRecords = array_values(array_filter(
                    $records,
                    static fn (\Monolog\LogRecord $r): bool => $r->message === 'Request',
                ));

                $this->assertCount(
                    1,
                    $requestRecords,
                    "Middleware must write exactly one 'Request' log entry; "
                    . "found " . count($requestRecords) . " for {$method} {$path} → {$statusCode}"
                );

                // LogRecord::$context is an array<string, mixed>
                $context = $requestRecords[0]->context;

                // --- Assert all 5 required fields are present ---
                foreach (['timestamp', 'method', 'path', 'status', 'elapsed_ms'] as $field) {
                    $this->assertArrayHasKey(
                        $field,
                        $context,
                        "Log entry context must contain '{$field}' field "
                        . "for {$method} {$path} → {$statusCode}"
                    );
                }

                // --- Assert field values are sensible ---
                $this->assertNotEmpty(
                    $context['timestamp'],
                    "Log 'timestamp' must not be empty"
                );

                $this->assertSame(
                    $method,
                    $context['method'],
                    "Log 'method' must match the request method"
                );

                $this->assertSame(
                    $path,
                    $context['path'],
                    "Log 'path' must match the request URI path"
                );

                $this->assertSame(
                    $statusCode,
                    $context['status'],
                    "Log 'status' must match the response status code"
                );

                $this->assertIsFloat($context['elapsed_ms'],
                    "Log 'elapsed_ms' must be a float");

                $this->assertGreaterThanOrEqual(
                    0.0,
                    $context['elapsed_ms'],
                    "Log 'elapsed_ms' must be non-negative"
                );
            });
    }
}
