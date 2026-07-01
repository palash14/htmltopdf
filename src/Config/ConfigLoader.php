<?php

declare(strict_types=1);

namespace App\Config;

use App\Model\Config;

/**
 * Loads application configuration from environment variables (highest priority)
 * or a PHP config file (fallback), validates all values, and returns an
 * immutable Config object.
 *
 * On any validation error the method writes a message to stderr and exits with
 * code 1 (intentionally before Monolog is available).
 */
class ConfigLoader
{
    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Load, validate, and return a Config object.
     *
     * Reads environment variables first; falls back to a PHP config file when
     * an env var is absent.  Exits with code 1 on any validation failure.
     *
     * @param string|null $configFile Path to the PHP config file.
     *                                Defaults to <project-root>/config/config.php.
     */
    public static function load(?string $configFile = null): Config
    {
        $configFile ??= dirname(__DIR__, 2) . '/config/config.php';

        $file = [];
        if (is_file($configFile)) {
            $file = require $configFile;
            if (!is_array($file)) {
                self::abort('Config file must return an array: ' . $configFile);
            }
        }

        // -------------------------------------------------------------------
        // Helper: resolve a value — env var wins over config file, else null.
        // -------------------------------------------------------------------
        $get = static function (string $envKey, string $fileKey) use ($file): mixed {
            $env = getenv($envKey);
            if ($env !== false && $env !== '') {
                return $env;
            }
            return $file[$fileKey] ?? null;
        };

        // -------------------------------------------------------------------
        // Required fields
        // -------------------------------------------------------------------

        // PORT
        $portRaw = $get('PORT', 'port');
        self::requirePresent($portRaw, 'PORT / port');
        $port = self::toInt($portRaw, 'PORT / port');
        self::requireRange($port, 1, 65535, 'PORT / port');

        // WKHTMLTOPDF_PATH
        $wkhtmltopdfPath = $get('WKHTMLTOPDF_PATH', 'wkhtmltopdfPath');
        self::requirePresent($wkhtmltopdfPath, 'WKHTMLTOPDF_PATH / wkhtmltopdfPath');

        // API_KEYS (env: comma-separated string; file: array of strings)
        $apiKeysRaw = $get('API_KEYS', 'apiKeys');
        self::requirePresent($apiKeysRaw, 'API_KEYS / apiKeys');
        $apiKeys = self::toApiKeys($apiKeysRaw);
        if (count($apiKeys) === 0) {
            self::abort('API_KEYS / apiKeys must contain at least one key.');
        }
        if (count($apiKeys) > 100) {
            self::abort('API_KEYS / apiKeys must not exceed 100 keys.');
        }

        // STORAGE_DIR
        $storageDir = $get('STORAGE_DIR', 'storageDir');
        self::requirePresent($storageDir, 'STORAGE_DIR / storageDir');

        // BASE_URL
        $baseUrl = $get('BASE_URL', 'baseUrl');
        self::requirePresent($baseUrl, 'BASE_URL / baseUrl');

        // -------------------------------------------------------------------
        // Optional fields with defaults and range checks
        // -------------------------------------------------------------------

        $ttlSeconds = self::optionalInt(
            $get('TTL_SECONDS', 'ttlSeconds'),
            3600,
            60,
            86400,
            'TTL_SECONDS / ttlSeconds'
        );

        $cleanupIntervalSeconds = self::optionalInt(
            $get('CLEANUP_INTERVAL_SECONDS', 'cleanupIntervalSeconds'),
            60,
            10,
            3600,
            'CLEANUP_INTERVAL_SECONDS / cleanupIntervalSeconds'
        );

        $maxConcurrentRenderers = self::optionalInt(
            $get('MAX_CONCURRENT_RENDERERS', 'maxConcurrentRenderers'),
            5,
            1,
            50,
            'MAX_CONCURRENT_RENDERERS / maxConcurrentRenderers'
        );

        $renderTimeoutSeconds = self::optionalInt(
            $get('RENDER_TIMEOUT_SECONDS', 'renderTimeoutSeconds'),
            30,
            5,
            300,
            'RENDER_TIMEOUT_SECONDS / renderTimeoutSeconds'
        );

        // Nullable — disabled by default
        $maxStorageMb = self::nullableInt(
            $get('MAX_STORAGE_MB', 'maxStorageMb'),
            1,
            PHP_INT_MAX,
            'MAX_STORAGE_MB / maxStorageMb'
        );

        $rateLimitRpm = self::nullableInt(
            $get('RATE_LIMIT_RPM', 'rateLimitRpm'),
            1,
            1000,
            'RATE_LIMIT_RPM / rateLimitRpm'
        );

        return new Config(
            port:                    $port,
            wkhtmltopdfPath:         (string) $wkhtmltopdfPath,
            apiKeys:                 $apiKeys,
            storageDir:              (string) $storageDir,
            baseUrl:                 (string) $baseUrl,
            ttlSeconds:              $ttlSeconds,
            cleanupIntervalSeconds:  $cleanupIntervalSeconds,
            maxConcurrentRenderers:  $maxConcurrentRenderers,
            renderTimeoutSeconds:    $renderTimeoutSeconds,
            maxStorageMb:            $maxStorageMb,
            rateLimitRpm:            $rateLimitRpm,
        );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Terminate the process with an error written to stderr.
     *
     * @param string $message Human-readable error description.
     * @return never
     */
    private static function abort(string $message): never
    {
        fwrite(STDERR, '[CONFIG ERROR] ' . $message . PHP_EOL);
        exit(1);
    }

    /**
     * Abort if $value is null, empty string, or false.
     *
     * @param mixed  $value The resolved value.
     * @param string $name  Human-readable setting name for the error message.
     */
    private static function requirePresent(mixed $value, string $name): void
    {
        if ($value === null || $value === '' || $value === false) {
            self::abort("Required configuration setting is missing: {$name}");
        }
    }

    /**
     * Cast a scalar to int, aborting on non-numeric input.
     *
     * @param mixed  $value Raw value.
     * @param string $name  Setting name for the error message.
     * @return int
     */
    private static function toInt(mixed $value, string $name): int
    {
        if (!is_numeric($value)) {
            self::abort("Configuration setting '{$name}' must be an integer, got: " . print_r($value, true));
        }
        return (int) $value;
    }

    /**
     * Assert that an integer lies within [min, max].
     *
     * @param int    $value Parsed integer value.
     * @param int    $min   Inclusive lower bound.
     * @param int    $max   Inclusive upper bound.
     * @param string $name  Setting name for the error message.
     */
    private static function requireRange(int $value, int $min, int $max, string $name): void
    {
        if ($value < $min || $value > $max) {
            self::abort(
                "Configuration setting '{$name}' is out of range [{$min}, {$max}]: {$value}"
            );
        }
    }

    /**
     * Parse API_KEYS: accepts a comma-separated string (from env) or an
     * array of strings (from config file).  Returns a list of trimmed,
     * non-empty key strings.
     *
     * @param mixed $raw Raw value from env or config file.
     * @return string[]
     */
    private static function toApiKeys(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw), static fn($k) => $k !== ''));
        }

        // Treat as comma-separated string
        return array_values(
            array_filter(
                array_map('trim', explode(',', (string) $raw)),
                static fn($k) => $k !== ''
            )
        );
    }

    /**
     * Resolve an optional integer setting.
     *
     * Returns $default when $raw is null / empty; validates the range when a
     * value is provided; aborts on out-of-range.
     *
     * @param mixed  $raw     Raw value.
     * @param int    $default Default value.
     * @param int    $min     Inclusive lower bound.
     * @param int    $max     Inclusive upper bound.
     * @param string $name    Setting name for the error message.
     * @return int
     */
    private static function optionalInt(mixed $raw, int $default, int $min, int $max, string $name): int
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return $default;
        }
        $value = self::toInt($raw, $name);
        self::requireRange($value, $min, $max, $name);
        return $value;
    }

    /**
     * Resolve a nullable optional integer setting.
     *
     * Returns null when $raw is null / empty; validates the range when a value
     * is provided; aborts on out-of-range.
     *
     * @param mixed  $raw  Raw value.
     * @param int    $min  Inclusive lower bound.
     * @param int    $max  Inclusive upper bound.
     * @param string $name Setting name for the error message.
     * @return int|null
     */
    private static function nullableInt(mixed $raw, int $min, int $max, string $name): ?int
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        $value = self::toInt($raw, $name);
        self::requireRange($value, $min, $max, $name);
        return $value;
    }
}
