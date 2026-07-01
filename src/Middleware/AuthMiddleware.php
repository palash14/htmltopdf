<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\AuthException;
use App\Model\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware that enforces Bearer token authentication.
 *
 * Validation order (per Requirement 3.6 — auth before all other processing):
 *   1. Authorization header present          → 401 if missing
 *   2. Header matches "Bearer <token>"       → 403 if malformed
 *   3. Token is in Config::$apiKeys          → 403 if not found
 *   4. Pass request to next handler          → normal processing
 *
 * Security notes:
 *   - hash_equals() is used for constant-time comparison to prevent timing attacks.
 *   - The token value is NEVER written to any log or included in any exception message.
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Step 1: Require the Authorization header.
        $headerLine = $request->getHeaderLine('Authorization');
        if ($headerLine === '') {
            $serverParams = $request->getServerParams();
            $headerLine = (string) (
                $serverParams['HTTP_AUTHORIZATION']
                ?? $serverParams['REDIRECT_HTTP_AUTHORIZATION']
                ?? ''
            );
        }

        if ($headerLine === '') {
            throw new AuthException(401, 'Authorization header required');
        }

        // Step 2: Require "Bearer <token>" format.
        // The token must be non-empty and contain no whitespace.
        if (!preg_match('/^Bearer\s+(\S+)$/', $headerLine, $matches)) {
            throw new AuthException(403, 'Invalid authorization format');
        }

        $token = $matches[1];

        // Step 3: Validate token against the configured API key list using
        // hash_equals() for constant-time comparison (timing-attack prevention).
        // NEVER log $token.
        foreach ($this->config->apiKeys as $apiKey) {
            if (hash_equals($apiKey, $token)) {
                // Valid key — delegate to the next middleware / handler.
                return $handler->handle($request);
            }
        }

        throw new AuthException(403, 'Invalid API key');
    }
}
