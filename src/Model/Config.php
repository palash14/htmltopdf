<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Immutable value object holding all application configuration.
 *
 * Required fields must be supplied explicitly; optional fields carry defaults
 * or are nullable (disabled when null).
 */
final class Config
{
    /**
     * @param int         $port                     Listening port (1–65535)
     * @param string      $wkhtmltopdfPath          Absolute path to the wkhtmltopdf binary
     * @param string[]    $apiKeys                  List of valid API keys (1–100 entries)
     * @param string      $storageDir               Absolute path to the PDF storage directory
     * @param string      $baseUrl                  Base URL used to build download URLs
     * @param int         $ttlSeconds               PDF time-to-live in seconds (60–86400, default 3600)
     * @param int         $cleanupIntervalSeconds   Cleanup job interval in seconds (10–3600, default 60)
     * @param int         $maxConcurrentRenderers   Max simultaneous wkhtmltopdf processes (1–50, default 5)
     * @param int         $renderTimeoutSeconds     Per-render timeout in seconds (5–300, default 30)
     * @param int|null    $maxStorageMb             Max storage size in MB; null = disabled
     * @param int|null    $rateLimitRpm             Requests-per-minute per API key; null = disabled (1–1000 when set)
     */
    public function __construct(
        public readonly int     $port,
        public readonly string  $wkhtmltopdfPath,
        public readonly array   $apiKeys,
        public readonly string  $storageDir,
        public readonly string  $baseUrl,
        public readonly int     $ttlSeconds              = 3600,
        public readonly int     $cleanupIntervalSeconds  = 60,
        public readonly int     $maxConcurrentRenderers  = 5,
        public readonly int     $renderTimeoutSeconds    = 30,
        public readonly ?int    $maxStorageMb            = null,
        public readonly ?int    $rateLimitRpm            = null,
        public readonly string  $rendererEngine          = 'wkhtmltopdf',
        public readonly ?string $chromePath              = null,
    ) {}
}
