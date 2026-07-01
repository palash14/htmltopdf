<?php

declare(strict_types=1);

namespace App\Handler;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Interfaces\ErrorHandlerInterface;
use Throwable;

/**
 * Custom Slim error handler.
 *
 * - Checks whether the exception exposes a getStatusCode() method and uses it.
 * - Defaults to 500 for unknown Throwables.
 * - Always returns Content-Type: application/json with {"message": "..."}.
 * - Never exposes stack traces in production.
 */
class JsonErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        // Determine HTTP status code
        $statusCode = 500;
        if (method_exists($exception, 'getStatusCode')) {
            $statusCode = (int) $exception->getStatusCode();
        }

        // Build the error message — never expose stack traces in production
        $message = $exception->getMessage();
        if ($message === '') {
            $message = $this->defaultMessageForStatus($statusCode);
        }

        // Log the error when requested
        if ($logErrors) {
            $context = [
                'exception' => $exception::class,
                'status'    => $statusCode,
                'message'   => $message,
            ];
            if ($logErrorDetails) {
                $context['file']  = $exception->getFile();
                $context['line']  = $exception->getLine();
                $context['trace'] = $exception->getTraceAsString();
            }
            if ($statusCode >= 500) {
                $this->logger->error('Unhandled exception', $context);
            } else {
                $this->logger->info('Request error', $context);
            }
        }

        $payload = json_encode(['message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write((string) $payload);

        return $response
            ->withHeader('Content-Type', 'application/json');
    }

    private function defaultMessageForStatus(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            410 => 'Gone',
            422 => 'Unprocessable entity',
            429 => 'Too many requests',
            500 => 'Internal server error',
            502 => 'Bad gateway',
            503 => 'Service unavailable',
            504 => 'Gateway timeout',
            507 => 'Insufficient storage',
            default => 'An error occurred',
        };
    }
}
