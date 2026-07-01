<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * PSR-15 middleware that logs a structured entry for every HTTP request.
 *
 * Logged fields: timestamp, method, path, status, elapsed_ms.
 *
 * Security note: the Authorization header value is NEVER written to any log
 * entry, in compliance with Requirements 3.5 and 9.4.
 */
class RequestLogMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Record the start time, delegate to the inner handler, then log the
     * request summary.
     */
    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        // 1. Capture start time before any downstream processing.
        $startTime = microtime(true);

        // 2. Delegate to the next middleware / route handler.
        $response = $handler->handle($request);

        // 3. Calculate elapsed time in milliseconds.
        $elapsed = (microtime(true) - $startTime) * 1000;

        // 4. Log the structured request record.
        //    The Authorization header value is intentionally omitted — only
        //    the listed fields are written.
        $this->logger->info('Request', [
            'timestamp'  => date('c'),
            'method'     => $request->getMethod(),
            'path'       => $request->getUri()->getPath(),
            'status'     => $response->getStatusCode(),
            'elapsed_ms' => round($elapsed, 2),
        ]);

        // 5. Return the response unchanged.
        return $response;
    }
}
