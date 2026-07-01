#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/cleanup.php — CLI entry point for the periodic cleanup cron job.
 *
 * Bootstrap the DI container (same definitions as public/index.php, minus Slim
 * app setup), instantiate CleanupJob, run one cleanup cycle, and exit with:
 *   0 — success (no I/O errors)
 *   1 — one or more I/O errors occurred during the run
 *
 * Usage (cPanel cron, every minute):
 *   * * * * * /usr/bin/php /path/to/bin/cleanup.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\ConfigLoader;
use App\Job\CleanupJob;
use App\Model\Config;
use DI\ContainerBuilder;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

// ---------------------------------------------------------------------------
// 1. Build the PHP-DI container
//    Uses the same definitions as public/index.php for Config and Logger.
//    CleanupJob and its dependencies are autowired.
// ---------------------------------------------------------------------------

$builder = new ContainerBuilder();

$builder->addDefinitions([

    // --- Configuration -------------------------------------------------------
    Config::class => static function (): Config {
        return ConfigLoader::load();
    },

    // --- Logger (Monolog, rotating, same file as web process) ----------------
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

    // --- CleanupJob (autowired — constructor needs Config + LoggerInterface) --
    CleanupJob::class => \DI\autowire(CleanupJob::class),
]);

$container = $builder->build();

// ---------------------------------------------------------------------------
// 2. Run the cleanup job
// ---------------------------------------------------------------------------

/** @var CleanupJob $cleanupJob */
$cleanupJob = $container->get(CleanupJob::class);
$result     = $cleanupJob->run();

// ---------------------------------------------------------------------------
// 3. Exit
//    Code 0 → clean run; code 1 → at least one I/O error (cron can alert)
// ---------------------------------------------------------------------------

exit($result->errorCount > 0 ? 1 : 0);
