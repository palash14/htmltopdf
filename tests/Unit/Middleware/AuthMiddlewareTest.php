<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Exception\AuthException;
use App\Middleware\AuthMiddleware;
use App\Model\Config;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Unit tests for AuthMiddleware.
 *
 * Covers:
 *   - Missing Authorization header → AuthException with status 401
 *   - Non-Bearer scheme (e.g. "Basic abc") → AuthException with status 403
 *   - "Bearer" with no token → AuthException with status 403
 *   - "Bearer invalid-key" (token not in config) → AuthException with status 403
 *   - Valid Bearer token → handler called, response returned
 *   - Multiple valid keys in config — each passes
 *   - Mixed-case "bearer token" → must fail (case-sensitive Bearer)
 *
 * Requirements: 3.1–3.6
 */
class AuthMiddlewareTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a Config with the given API keys (and minimal required fields).
     *
     * @param string[] $apiKeys
     */
    private function makeConfig(array $apiKeys): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: $apiKeys,
            storageDir: '/tmp',
            baseUrl: 'https://example.com',
        );
    }

    /**
     * Build an AuthMiddleware wired with the given API keys.
     *
     * @param string[] $apiKeys
     */
    private function makeMiddleware(array $apiKeys): AuthMiddleware
    {
        return new AuthMiddleware($this->makeConfig($apiKeys));
    }

    /**
     * Create a PSR-7 server request (no Authorization header by default).
     */
    private function makeRequest(?string $authorizationHeader = null): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', 'https://example.com/api/convert');

        if ($authorizationHeader !== null) {
            $request = $request->withHeader('Authorization', $authorizationHeader);
        }

        return $request;
    }

    /**
     * Create a mock RequestHandlerInterface that returns a mock response.
     * Asserts that handle() is called exactly once when $expectCall is true.
     */
    private function makeHandler(bool $expectCall = false): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);

        if ($expectCall) {
            $response = $this->createMock(ResponseInterface::class);
            $handler->expects($this->once())
                    ->method('handle')
                    ->willReturn($response);
        } else {
            $handler->expects($this->never())
                    ->method('handle');
        }

        return $handler;
    }

    // -----------------------------------------------------------------------
    // 1. Missing Authorization header → 401
    // -----------------------------------------------------------------------

    /**
     * When the request carries no Authorization header at all the middleware
     * must throw AuthException with HTTP status 401.
     *
     * Requirements: 3.2
     */
    public function testMissingAuthorizationHeaderThrows401(): void
    {
        $middleware = $this->makeMiddleware(['secret-key']);
        $request    = $this->makeRequest(); // no header
        $handler    = $this->makeHandler(expectCall: false);

        $this->expectException(AuthException::class);

        try {
            $middleware->process($request, $handler);
        } catch (AuthException $e) {
            self::assertSame(401, $e->getStatusCode(), 'Missing header must produce status 401');
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // 2. Non-Bearer scheme ("Basic abc") → 403
    // -----------------------------------------------------------------------

    /**
     * An Authorization header using the Basic scheme (not Bearer) is malformed
     * for this API and must result in AuthException with HTTP status 403.
     *
     * Requirements: 3.3
     */
    public function testBasicSchemeThrows403(): void
    {
        $middleware = $this->makeMiddleware(['secret-key']);
        $request    = $this->makeRequest('Basic abc123');
        $handler    = $this->makeHandler(expectCall: false);

        $this->expectException(AuthException::class);

        try {
            $middleware->process($request, $handler);
        } catch (AuthException $e) {
            self::assertSame(403, $e->getStatusCode(), 'Non-Bearer scheme must produce status 403');
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // 3. "Bearer" with no token → 403
    // -----------------------------------------------------------------------

    /**
     * An Authorization header containing only "Bearer" with no following token
     * is malformed and must result in AuthException with HTTP status 403.
     *
     * Requirements: 3.3
     */
    public function testBearerWithNoTokenThrows403(): void
    {
        $middleware = $this->makeMiddleware(['secret-key']);
        $request    = $this->makeRequest('Bearer');
        $handler    = $this->makeHandler(expectCall: false);

        $this->expectException(AuthException::class);

        try {
            $middleware->process($request, $handler);
        } catch (AuthException $e) {
            self::assertSame(403, $e->getStatusCode(), '"Bearer" with no token must produce status 403');
            throw $e;
        }
    }

    /**
     * An Authorization header containing "Bearer " (Bearer followed by only
     * whitespace) also has no usable token and must produce status 403.
     *
     * Requirements: 3.3
     */
    public function testBearerWithOnlyWhitespaceThrows403(): void
    {
        $middleware = $this->makeMiddleware(['secret-key']);
        $request    = $this->makeRequest('Bearer   ');
        $handler    = $this->makeHandler(expectCall: false);

        $this->expectException(AuthException::class);

        try {
            $middleware->process($request, $handler);
        } catch (AuthException $e) {
            self::assertSame(403, $e->getStatusCode(), '"Bearer   " (whitespace only) must produce status 403');
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // 4. Valid format but wrong token → 403
    // -----------------------------------------------------------------------

    /**
     * A well-formed "Bearer <token>" header where the token does not match any
     * configured API key must result in AuthException with HTTP status 403.
     *
     * Requirements: 3.4
     */
    public function testInvalidTokenThrows403(): void
    {
        $middleware = $this->makeMiddleware(['correct-key']);
        $request    = $this->makeRequest('Bearer wrong-key');
        $handler    = $this->makeHandler(expectCall: false);

        $this->expectException(AuthException::class);

        try {
            $middleware->process($request, $handler);
        } catch (AuthException $e) {
            self::assertSame(403, $e->getStatusCode(), 'Unrecognised token must produce status 403');
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // 5. Valid token → handler called, response returned
    // -----------------------------------------------------------------------

    /**
     * When the Authorization header contains a valid Bearer token that matches
     * a configured API key, process() must call the handler and return its
     * response without throwing.
     *
     * Requirements: 3.1, 3.6
     */
    public function testValidTokenCallsHandlerAndReturnsResponse(): void
    {
        $middleware = $this->makeMiddleware(['my-valid-key']);
        $request    = $this->makeRequest('Bearer my-valid-key');
        $handler    = $this->makeHandler(expectCall: true);

        // Must not throw
        $response = $middleware->process($request, $handler);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    // -----------------------------------------------------------------------
    // 6. Multiple valid keys — each one passes
    // -----------------------------------------------------------------------

    /**
     * When Config holds multiple API keys, each one must independently allow
     * the request through. The handler must be called for every valid key.
     *
     * Requirements: 3.1, 3.4, 10.5
     */
    public function testEachValidKeyPassesAuthentication(): void
    {
        $keys = ['key1', 'key2', 'key3'];
        $middleware = $this->makeMiddleware($keys);

        foreach ($keys as $key) {
            $response = $this->createMock(ResponseInterface::class);
            $handler  = $this->createMock(RequestHandlerInterface::class);
            $handler->expects($this->once())
                    ->method('handle')
                    ->willReturn($response);

            $request = $this->makeRequest("Bearer {$key}");

            $result = $middleware->process($request, $handler);

            self::assertInstanceOf(
                ResponseInterface::class,
                $result,
                "Key '{$key}' should be accepted and handler response returned"
            );
        }
    }

    /**
     * When a token matches one key in a multi-key config but not another, only
     * the matching key should pass and a non-matching token should still fail.
     *
     * Requirements: 3.4
     */
    public function testNonMatchingTokenFailsEvenWithMultipleKeys(): void
    {
        $middleware = $this->makeMiddleware(['key1', 'key2']);
        $request    = $this->makeRequest('Bearer key3');
        $handler    = $this->makeHandler(expectCall: false);

        $this->expectException(AuthException::class);

        try {
            $middleware->process($request, $handler);
        } catch (AuthException $e) {
            self::assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // 7. Mixed-case "bearer token" → must fail (case-sensitive)
    // -----------------------------------------------------------------------

    /**
     * The Bearer scheme keyword is case-sensitive per RFC 6750. A header that
     * uses lowercase "bearer" must be rejected with status 403.
     *
     * Requirements: 3.3
     */
    public function testLowercaseBearerSchemeFails(): void
    {
        $middleware = $this->makeMiddleware(['my-valid-key']);
        $request    = $this->makeRequest('bearer my-valid-key');
        $handler    = $this->makeHandler(expectCall: false);

        $this->expectException(AuthException::class);

        try {
            $middleware->process($request, $handler);
        } catch (AuthException $e) {
            self::assertSame(403, $e->getStatusCode(), 'Lowercase "bearer" scheme must be rejected with 403');
            throw $e;
        }
    }

    /**
     * Mixed-case "BEARER" (all caps) must also fail — only exactly "Bearer"
     * is accepted.
     *
     * Requirements: 3.3
     */
    public function testUppercaseBearerSchemeFails(): void
    {
        $middleware = $this->makeMiddleware(['my-valid-key']);
        $request    = $this->makeRequest('BEARER my-valid-key');
        $handler    = $this->makeHandler(expectCall: false);

        $this->expectException(AuthException::class);

        try {
            $middleware->process($request, $handler);
        } catch (AuthException $e) {
            self::assertSame(403, $e->getStatusCode(), 'Uppercase "BEARER" scheme must be rejected with 403');
            throw $e;
        }
    }
}
