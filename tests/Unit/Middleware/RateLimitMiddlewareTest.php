<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\RateLimitMiddleware;
use App\Model\Config;
use App\Service\RateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

class RateLimitMiddlewareTest extends TestCase
{
    private function makeConfig(?int $rateLimitRpm = 60): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: ['test-key'],
            storageDir: '/tmp',
            baseUrl: 'https://example.com',
            rateLimitRpm: $rateLimitRpm,
        );
    }

    public function testFileDownloadBypassesRateLimitWithoutAuthorizationHeader(): void
    {
        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->expects($this->never())->method('isAllowed');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler->expects($this->once())
                ->method('handle')
                ->willReturn($response);

        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest(
            'GET',
            'https://example.com/api/files/abcdef1234567890abcdef1234567890.pdf'
        );

        $middleware = new RateLimitMiddleware($this->makeConfig(), $rateLimiter);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    public function testConvertRequestUsesBearerTokenForRateLimit(): void
    {
        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->expects($this->once())
                    ->method('isAllowed')
                    ->with('test-key')
                    ->willReturn(true);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler->expects($this->once())
                ->method('handle')
                ->willReturn($response);

        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', 'https://example.com/api/convert')
                           ->withHeader('Authorization', 'Bearer test-key');

        $middleware = new RateLimitMiddleware($this->makeConfig(), $rateLimiter);

        self::assertSame($response, $middleware->process($request, $handler));
    }
}
