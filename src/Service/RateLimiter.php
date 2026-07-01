<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Sliding-window rate limiter backed by APCu shared memory.
 *
 * Each API key gets a per-minute bucket keyed by the sha256 hash of the key
 * and the current 60-second window boundary.  The TTL on each APCu entry is
 * 120 seconds so that the previous window is automatically evicted.
 *
 * SECURITY: raw API key values are NEVER stored or logged — only their
 * sha256 hashes.
 */
class RateLimiter
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns true when the API key is within its allowed request budget for
     * the current 60-second window, false when the limit has been exceeded.
     *
     * Always returns true (and logs a warning) when APCu is unavailable so
     * that the application degrades gracefully rather than blocking all traffic.
     */
    public function isAllowed(string $apiKey): bool
    {
        // If rate limiting is disabled, always allow.
        if ($this->config->rateLimitRpm === null) {
            return true;
        }

        // Graceful degradation when APCu is not available.
        if (!$this->isApcuAvailable()) {
            $this->logger->warning('APCu is unavailable; rate limiting is disabled for this request.');
            return true;
        }

        $keyHash = hash('sha256', $apiKey);
        $window  = (int) floor($this->getCurrentTime() / 60);
        $apcuKey = "rl:{$keyHash}:{$window}";

        // Atomically increment. apcu_inc returns the new value on success or
        // false when the key does not yet exist.
        $count = $this->apcuIncrement($apcuKey, 120);

        return $count <= $this->config->rateLimitRpm;
    }

    // -------------------------------------------------------------------------
    // Helpers — protected so subclasses can override for testing
    // -------------------------------------------------------------------------

    /**
     * Returns the current Unix timestamp as a float (seconds + microseconds).
     * Protected so test subclasses can override it to control time.
     */
    protected function getCurrentTime(): float
    {
        return microtime(true);
    }

    /**
     * Returns true when APCu is loaded and enabled.
     * Protected so test subclasses can override this check.
     */
    protected function isApcuAvailable(): bool
    {
        return function_exists('apcu_inc') && (bool) ini_get('apc.enabled');
    }

    /**
     * Increment the APCu counter for the given key.
     * Creates the key with value 1 when it does not yet exist.
     * Returns the resulting counter value.
     *
     * Protected so test subclasses can substitute an in-memory store.
     *
     * @param string $key  The APCu cache key
     * @param int    $ttl  Time-to-live in seconds for the APCu entry
     */
    protected function apcuIncrement(string $key, int $ttl): int
    {
        $count = apcu_inc($key, 1, $success, $ttl);

        if (!$success) {
            // Key did not exist — initialise to 1.
            apcu_store($key, 1, $ttl);
            $count = 1;
        }

        return (int) $count;
    }

    /**
     * Store a value in APCu.
     * Protected so test subclasses can substitute an in-memory store.
     *
     * @param string $key   The APCu cache key
     * @param int    $value Value to store
     * @param int    $ttl   Time-to-live in seconds
     */
    protected function apcuStore(string $key, int $value, int $ttl): void
    {
        apcu_store($key, $value, $ttl);
    }
}
