<?php

declare(strict_types=1);

use App\Config\ConfigLoader;
use App\Controller\ConvertController;
use App\Controller\FileController;
use App\Handler\JsonErrorHandler;
use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RequestLogMiddleware;
use App\Model\Config;
use App\Service\ConcurrencyGuard;
use App\Service\InputValidator;
use App\Service\RateLimiter;
use App\Service\RendererService;
use App\Service\SsrfGuard;
use App\Service\StorageService;
use DI\ContainerBuilder;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// 1. Build the PHP-DI container
// ---------------------------------------------------------------------------

$builder = new ContainerBuilder();

$builder->addDefinitions([

    // --- Configuration -------------------------------------------------------
    Config::class => static function (): Config {
        return ConfigLoader::load();
    },

    // --- Logger (Monolog) ----------------------------------------------------
    LoggerInterface::class => static function (): LoggerInterface {
        $logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logger = new Logger('app');
        $logger->pushHandler(
            new RotatingFileHandler($logDir . '/app.log', 30, Logger::DEBUG)
        );
        return $logger;
    },

    // --- Services (deferred / autowired) -------------------------------------
    InputValidator::class  => \DI\autowire(InputValidator::class),
    SsrfGuard::class       => \DI\autowire(SsrfGuard::class),
    StorageService::class  => \DI\autowire(StorageService::class),
    RendererService::class => \DI\autowire(RendererService::class),
    ConcurrencyGuard::class => \DI\autowire(ConcurrencyGuard::class),
    RateLimiter::class     => \DI\autowire(RateLimiter::class),

    // --- Middleware (deferred / autowired) ------------------------------------
    AuthMiddleware::class        => \DI\autowire(AuthMiddleware::class),
    RateLimitMiddleware::class   => \DI\autowire(RateLimitMiddleware::class),
    RequestLogMiddleware::class  => \DI\autowire(RequestLogMiddleware::class),

    // --- Controllers (deferred / autowired) -----------------------------------
    ConvertController::class => \DI\autowire(ConvertController::class),
    FileController::class    => \DI\autowire(FileController::class),

    // --- PSR-7 ResponseFactory (required by JsonErrorHandler) ----------------
    ResponseFactoryInterface::class => static function (): ResponseFactoryInterface {
        return new ResponseFactory();
    },

    // --- Error handler -------------------------------------------------------
    JsonErrorHandler::class => \DI\autowire(JsonErrorHandler::class),
]);

$container = $builder->build();

// ---------------------------------------------------------------------------
// 2. Create the Slim app from the container
// ---------------------------------------------------------------------------

AppFactory::setContainer($container);
$app = AppFactory::createFromContainer($container);

// ---------------------------------------------------------------------------
// 3. Register middleware stack (outermost → innermost)
//    Order: RequestLog → Auth → RateLimit → (Slim routing/error)
// ---------------------------------------------------------------------------

// Slim's built-in routing and error middleware are added first (innermost)
$app->addRoutingMiddleware();

$callableResolver = $app->getCallableResolver();
$responseFactory  = $app->getResponseFactory();

$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: false,  // never expose traces in production
    logErrors: true,
    logErrorDetails: false,
);

// Register our custom JSON error handler for all Throwables
$jsonErrorHandler = $container->get(JsonErrorHandler::class);
$errorMiddleware->setDefaultErrorHandler($jsonErrorHandler);

// Outer middleware (added last so they run first)
$app->add($container->get(RateLimitMiddleware::class));
$app->add($container->get(AuthMiddleware::class));
$app->add($container->get(RequestLogMiddleware::class));

// ---------------------------------------------------------------------------
// 4. Register routes
// ---------------------------------------------------------------------------

$app->post('/api/convert', [ConvertController::class, 'handle']);
$app->get('/api/files/{filename}', [FileController::class, 'download']);

// ---------------------------------------------------------------------------
// 5. Run
// ---------------------------------------------------------------------------

$app->run();
