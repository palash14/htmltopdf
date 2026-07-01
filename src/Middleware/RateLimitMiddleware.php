<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\RateLimitException;
use App\Model\Config;
use App\Service\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware that enforces per-API-key rate limits.
 *
 * When {@see Config::$rateLimitRpm} is null the middleware is a no-op pass-
 * through.  Otherwise it extracts the Bearer token that has already been
 * validated by {@see AuthMiddleware} and delegates to {@see RateLimiter}.
 *
 * This middleware MUST sit behind AuthMiddleware in the stack so that the
 * Authorization header is guaranteed to be a well-formed `Bearer <token>`.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly RateLimiter $rateLimiter,
    ) {}

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        // Rate limiting disabled — pass through immediately.
        if ($this->config->rateLimitRpm === null) {
            return $handler->handle($request);
        }

        // Extract the Bearer token from the Authorization header.
        // AuthMiddleware has already validated the header format, so we can
        // safely parse it here without additional error handling.
        $authHeader = $request->getHeaderLine('Authorization');
        $token      = $this->extractBearerToken($authHeader);

        if (!$this->rateLimiter->isAllowed($token)) {
            throw new RateLimitException('Rate limit exceeded');
        }

        return $handler->handle($request);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extracts the token from a `Bearer <token>` header value.
     * Returns an empty string if the format is unexpected (should not happen
     * after AuthMiddleware has validated the request).
     */
    private function extractBearerToken(string $authHeader): string
    {
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return '';
    }
}
