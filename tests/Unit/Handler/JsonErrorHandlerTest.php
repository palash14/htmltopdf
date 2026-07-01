<?php

declare(strict_types=1);

namespace Tests\Unit\Handler;

use App\Exception\AuthException;
use App\Exception\ValidationException;
use App\Handler\JsonErrorHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Unit tests for JsonErrorHandler.
 *
 * Exercises the logging paths (logErrors on/off, logErrorDetails, 4xx vs 5xx),
 * the defaultMessageForStatus() fallbacks, and the JSON response contract.
 */
class JsonErrorHandlerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build the handler under test with a Monolog TestHandler logger so that
     * log records can be inspected after invocation.
     *
     * @return array{JsonErrorHandler, TestHandler}
     */
    private function makeHandler(): array
    {
        $testHandler = new TestHandler();
        $logger      = new Logger('test', [$testHandler]);
        $handler     = new JsonErrorHandler(new ResponseFactory(), $logger);

        return [$handler, $testHandler];
    }

    /**
     * Invoke the error handler with the given exception and options.
     *
     * @return array{int, string, string}  [statusCode, contentType, bodyJson]
     */
    private function invoke(
        JsonErrorHandler $handler,
        \Throwable $exception,
        bool $displayErrorDetails = false,
        bool $logErrors           = false,
        bool $logErrorDetails     = false,
    ): array {
        $request  = (new ServerRequestFactory())->createServerRequest('GET', 'https://example.com/');
        $response = $handler->__invoke(
            $request,
            $exception,
            $displayErrorDetails,
            $logErrors,
            $logErrorDetails,
        );

        $statusCode  = $response->getStatusCode();
        $contentType = $response->getHeaderLine('Content-Type');
        $body        = (string) $response->getBody();

        return [$statusCode, $contentType, $body];
    }

    // -----------------------------------------------------------------------
    // 1. Default 500 for unknown exception
    // -----------------------------------------------------------------------

    /**
     * A generic RuntimeException (no getStatusCode()) produces HTTP 500,
     * Content-Type application/json, and a JSON body with a "message" key.
     */
    public function testReturns500WithJsonBodyForUnknownException(): void
    {
        [$handler] = $this->makeHandler();
        [$status, $ct, $body] = $this->invoke($handler, new \RuntimeException('something went wrong'));

        $this->assertSame(500, $status);
        $this->assertSame('application/json', $ct);

        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('message', $decoded);
    }

    // -----------------------------------------------------------------------
    // 2. getStatusCode() present → use it
    // -----------------------------------------------------------------------

    /**
     * When the exception implements getStatusCode(), its value is used as the
     * HTTP response status.
     */
    public function testUsesGetStatusCodeWhenPresent(): void
    {
        [$handler] = $this->makeHandler();
        [$status, , $body] = $this->invoke($handler, new ValidationException(422, 'test msg'));

        $this->assertSame(422, $status);

        $decoded = json_decode($body, true);
        $this->assertSame('test msg', $decoded['message']);
    }

    // -----------------------------------------------------------------------
    // 3. logErrors=true, 5xx → logs at error level
    // -----------------------------------------------------------------------

    /**
     * With logErrors=true and a 5xx exception, the logger receives an
     * error-level record mentioning 'Unhandled exception'.
     */
    public function testLogErrorsTrueLogsToLogger5xx(): void
    {
        [$handler, $testHandler] = $this->makeHandler();
        $this->invoke($handler, new \RuntimeException('boom'), false, true, false);

        $this->assertTrue(
            $testHandler->hasErrorThatContains('Unhandled exception'),
            'Expected an error-level log record containing "Unhandled exception"'
        );
    }

    // -----------------------------------------------------------------------
    // 4. logErrors=true, 4xx → logs at info level
    // -----------------------------------------------------------------------

    /**
     * With logErrors=true and a 4xx exception (AuthException 401), the logger
     * receives an info-level record mentioning 'Request error'.
     */
    public function testLogErrorsTrueLogsToLogger4xx(): void
    {
        [$handler, $testHandler] = $this->makeHandler();
        // AuthException with status 401, empty message → default "Unauthorized"
        $this->invoke($handler, new AuthException(401, 'not authorized'), false, true, false);

        $this->assertTrue(
            $testHandler->hasInfoThatContains('Request error'),
            'Expected an info-level log record containing "Request error"'
        );
        // Must NOT have logged at error level for a 4xx
        $this->assertFalse(
            $testHandler->hasErrorRecords(),
            'A 4xx exception must not produce an error-level log record'
        );
    }

    // -----------------------------------------------------------------------
    // 5. logErrorDetails=true → context contains file, line, trace
    // -----------------------------------------------------------------------

    /**
     * When logErrorDetails=true the log context must include 'file', 'line',
     * and 'trace' keys populated from the exception.
     */
    public function testLogErrorDetailsAddsTraceToContext(): void
    {
        [$handler, $testHandler] = $this->makeHandler();
        $this->invoke($handler, new \RuntimeException('trace test'), false, true, true);

        // There must be at least one error-level record
        $this->assertTrue($testHandler->hasErrorRecords());

        $records = $testHandler->getRecords();
        $found   = false;
        foreach ($records as $record) {
            if (str_contains((string) $record['message'], 'Unhandled exception')) {
                $ctx   = $record['context'];
                $this->assertArrayHasKey('file',  $ctx, 'Context must contain "file"');
                $this->assertArrayHasKey('line',  $ctx, 'Context must contain "line"');
                $this->assertArrayHasKey('trace', $ctx, 'Context must contain "trace"');
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'No "Unhandled exception" record found in logs');
    }

    // -----------------------------------------------------------------------
    // 6. Empty message → defaultMessageForStatus (503)
    // -----------------------------------------------------------------------

    /**
     * When getMessage() returns '' and getStatusCode() returns 503, the body
     * message must be the default 'Service unavailable'.
     */
    public function testEmptyMessageUsesDefaultForStatus(): void
    {
        [$handler] = $this->makeHandler();

        // Use ValidationException with an empty message and status 503
        $exception = new ValidationException(503, '');
        [, , $body] = $this->invoke($handler, $exception);

        $decoded = json_decode($body, true);
        $this->assertSame('Service unavailable', $decoded['message']);
    }

    // -----------------------------------------------------------------------
    // 7. Unknown status code → 'An error occurred'
    // -----------------------------------------------------------------------

    /**
     * For an unrecognised but valid HTTP status code (e.g. 599) with an empty
     * message, the default fallback message must be 'An error occurred'.
     *
     * Status 599 is a valid HTTP code (used by some network tools for timeouts)
     * but is not in JsonErrorHandler's explicit match arms.
     */
    public function testDefaultMessageForUnknownStatus(): void
    {
        [$handler] = $this->makeHandler();

        $exception = new ValidationException(599, '');
        [$status, , $body] = $this->invoke($handler, $exception);

        $this->assertSame(599, $status);
        $decoded = json_decode($body, true);
        $this->assertSame('An error occurred', $decoded['message']);
    }

    // -----------------------------------------------------------------------
    // 8. Content-Type is always application/json
    // -----------------------------------------------------------------------

    /**
     * Whether the exception is a 5xx or a 4xx, the Content-Type header must
     * always be 'application/json'.
     */
    public function testContentTypeIsAlwaysApplicationJson(): void
    {
        [$handler] = $this->makeHandler();

        // 5xx path
        [, $ct5xx] = $this->invoke($handler, new \RuntimeException('server error'));
        $this->assertSame('application/json', $ct5xx, 'Content-Type must be application/json for 5xx');

        // 4xx path
        [, $ct4xx] = $this->invoke($handler, new AuthException(403, 'forbidden'));
        $this->assertSame('application/json', $ct4xx, 'Content-Type must be application/json for 4xx');
    }

    // -----------------------------------------------------------------------
    // 9. Cover remaining defaultMessageForStatus arms
    // -----------------------------------------------------------------------

    /**
     * Verify several defaultMessageForStatus() match arms produce the correct
     * string when the exception message is empty.
     *
     * @dataProvider defaultMessageProvider
     */
    public function testDefaultMessagesForKnownStatuses(int $status, string $expected): void
    {
        [$handler] = $this->makeHandler();
        [, , $body] = $this->invoke($handler, new ValidationException($status, ''));

        $decoded = json_decode($body, true);
        $this->assertSame($expected, $decoded['message'], "Unexpected default message for status {$status}");
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function defaultMessageProvider(): array
    {
        return [
            '400' => [400, 'Bad request'],
            '401' => [401, 'Unauthorized'],
            '403' => [403, 'Forbidden'],
            '404' => [404, 'Not found'],
            '405' => [405, 'Method not allowed'],
            '410' => [410, 'Gone'],
            '422' => [422, 'Unprocessable entity'],
            '429' => [429, 'Too many requests'],
            '500' => [500, 'Internal server error'],
            '502' => [502, 'Bad gateway'],
            '503' => [503, 'Service unavailable'],
            '504' => [504, 'Gateway timeout'],
            '507' => [507, 'Insufficient storage'],
        ];
    }
}
