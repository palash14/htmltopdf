<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ConcurrencyException;
use App\Model\Config;

/**
 * Controls concurrent access to the wkhtmltopdf renderer pool using a
 * System V semaphore (Linux) and an APCu queue-depth counter.
 *
 * Fallback behaviour:
 *  - If System V semaphores are unavailable (e.g. Windows), the semaphore
 *    check is skipped and only the APCu queue counter is enforced.
 *  - If APCu is also unavailable, all requests are allowed through.
 *
 * Requirements: 6.1, 6.2, 6.3, 6.4
 */
class ConcurrencyGuard
{
    /** APCu key tracking the number of requests currently waiting or rendering. */
    private const QUEUE_DEPTH_KEY = 'concurrency:queue_depth';

    /** Hard limit on how many requests may wait in the queue at once. */
    private const MAX_QUEUE_SIZE = 20;

    /** How long to sleep between non-blocking semaphore poll attempts (100 ms). */
    private const POLL_INTERVAL_US = 100_000;

    /**
     * System V semaphore resource, or null when semaphores are unavailable.
     *
     * @var \SysvSemaphore|null
     */
    private mixed $sem;

    public function __construct(private readonly Config $config)
    {
        if (function_exists('sem_get')) {
            // ftok converts the storage directory path to a numeric IPC key.
            // The second argument ('p') is a single-byte project identifier that
            // lets multiple independent semaphores share the same file.
            $key = ftok($config->storageDir, 'p');
            // The third argument sets the semaphore's initial count, which
            // equals the maximum number of simultaneous renderers.
            $this->sem = sem_get($key, $config->maxConcurrentRenderers);
        } else {
            $this->sem = null;
        }
    }

    /**
     * Acquire a renderer slot.
     *
     * Steps:
     *  1. Atomically increment the APCu queue-depth counter.
     *  2. If the new depth exceeds MAX_QUEUE_SIZE → decrement and throw 503.
     *  3. Spin-poll the semaphore (non-blocking) every 100 ms until the
     *     deadline; if acquired → return.
     *  4. If the deadline is reached → decrement and throw 503.
     *
     * @param int $timeoutSeconds Maximum seconds to wait for a slot (default 60).
     *
     * @throws ConcurrencyException When the queue is full (503) or the wait
     *                              times out (503).
     */
    public function acquire(int $timeoutSeconds = 60): void
    {
        // --- Step 1: increment queue counter ---
        $depth = $this->incrementQueueDepth();

        // --- Step 2: queue-full check ---
        if ($depth > self::MAX_QUEUE_SIZE) {
            $this->decrementQueueDepth();
            throw new ConcurrencyException('queue full');
        }

        // If no semaphore is available, the slot is considered acquired
        // immediately after the queue-depth check passes.
        if ($this->sem === null) {
            return;
        }

        // --- Step 3: spin-wait for semaphore ---
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            // non-blocking acquire: returns false instead of blocking
            if (@sem_acquire($this->sem, true)) {
                return; // slot acquired
            }
            usleep(self::POLL_INTERVAL_US);
        }

        // --- Step 4: timeout ---
        $this->decrementQueueDepth();
        throw new ConcurrencyException('queue timeout');
    }

    /**
     * Release a renderer slot back to the pool.
     *
     * Must be called in a `finally` block after every successful `acquire()`.
     */
    public function release(): void
    {
        if ($this->sem !== null) {
            @sem_release($this->sem);
        }

        $this->decrementQueueDepth();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Atomically increment the APCu queue-depth counter and return the new value.
     *
     * Falls back to a simple in-memory variable when APCu is not available so
     * that the class remains functional (without cross-process coordination) in
     * environments that lack APCu.
     */
    private function incrementQueueDepth(): int
    {
        if (function_exists('apcu_inc')) {
            $success = false;
            $value = apcu_inc(self::QUEUE_DEPTH_KEY, 1, $success);
            if (!$success) {
                // Counter did not exist yet; create it.
                apcu_store(self::QUEUE_DEPTH_KEY, 1);
                return 1;
            }
            return (int) $value;
        }

        // APCu unavailable — return a value that will never exceed the limit so
        // all requests are allowed through.
        return 1;
    }

    /**
     * Decrement the APCu queue-depth counter (floor at 0 to avoid negatives).
     */
    private function decrementQueueDepth(): void
    {
        if (!function_exists('apcu_dec')) {
            return;
        }

        $success = false;
        $value = apcu_dec(self::QUEUE_DEPTH_KEY, 1, $success);
        if ($success && (int) $value < 0) {
            // Guard against the counter going negative due to a race or
            // an unmatched decrement.
            apcu_store(self::QUEUE_DEPTH_KEY, 0);
        }
    }
}
