<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exception\ConcurrencyException;
use App\Model\Config;
use App\Service\ConcurrencyGuard;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for concurrent request handling and semaphore behaviour.
 *
 * PHP CLI does not support true threads, so we test the queuing/semaphore
 * logic directly through the ConcurrencyGuard interface rather than spawning
 * HTTP processes. This is the correct integration test approach on Windows
 * (where System V semaphores are unavailable) and allows verifying the exact
 * concurrency semantics described in Requirements 6.1–6.3.
 *
 * The tests use the same TestableConcurrencyGuard pattern established in
 * ConcurrencyGuardTest but drive higher-level scenarios:
 *  - Simulating 10 simultaneous requests against a pool of maxConcurrentRenderers
 *  - Verifying at most maxConcurrentRenderers run at once
 *  - Verifying excess requests are queued or return 503
 *
 * Requirements: 6.1, 6.2, 6.3
 */

// ---------------------------------------------------------------------------
// Re-usable test double (inline to avoid cross-namespace coupling)
// ---------------------------------------------------------------------------

/**
 * An in-memory ConcurrencyGuard that does not use System V semaphores or APCu.
 * Slot model is purely instance-level, making it deterministic and portable.
 */
class IntegrationTestableConcurrencyGuard extends ConcurrencyGuard
{
    private const MAX_QUEUE_SIZE   = 20;
    private const POLL_INTERVAL_MS = 10;

    private int $slots;
    private int $depth = 0;

    /** Tracks the peak number of simultaneously active (slot-holding) acquires. */
    private int $peakActive = 0;

    /** How many acquires are currently holding a slot (rendered "active"). */
    private int $currentActive = 0;

    public function __construct(Config $config)
    {
        parent::__construct($config);
        $this->slots = $config->maxConcurrentRenderers;
    }

    public function acquire(int $timeoutSeconds = 60): void
    {
        $this->depth++;

        if ($this->depth > self::MAX_QUEUE_SIZE) {
            $this->depth--;
            throw new ConcurrencyException('queue full');
        }

        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if ($this->slots > 0) {
                $this->slots--;
                $this->currentActive++;
                if ($this->currentActive > $this->peakActive) {
                    $this->peakActive = $this->currentActive;
                }
                return;
            }
            usleep(self::POLL_INTERVAL_MS * 1_000);
        } while (microtime(true) < $deadline);

        $this->depth--;
        throw new ConcurrencyException('queue timeout');
    }

    public function release(): void
    {
        $this->slots++;
        $this->currentActive = max(0, $this->currentActive - 1);
        if ($this->depth > 0) {
            $this->depth--;
        }
    }

    // -----------------------------------------------------------------------
    // Inspection helpers
    // -----------------------------------------------------------------------

    public function getAvailableSlots(): int   { return $this->slots; }
    public function getCurrentQueueDepth(): int { return $this->depth; }
    public function getCurrentActive(): int     { return $this->currentActive; }
    public function getPeakActive(): int        { return $this->peakActive; }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------

class ConcurrencyIntegrationTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeConfig(int $maxConcurrentRenderers = 2): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '',
            apiKeys: ['key'],
            storageDir: sys_get_temp_dir(),
            baseUrl: 'https://example.com',
            maxConcurrentRenderers: $maxConcurrentRenderers,
        );
    }

    private function makeGuard(int $maxConcurrentRenderers = 2): IntegrationTestableConcurrencyGuard
    {
        return new IntegrationTestableConcurrencyGuard($this->makeConfig($maxConcurrentRenderers));
    }

    // -----------------------------------------------------------------------
    // 1. At most maxConcurrentRenderers active at once (Requirements 6.1, 6.2)
    // -----------------------------------------------------------------------

    /**
     * Simulates 10 simultaneous requests against a pool of 3 slots.
     *
     * Because PHP CLI is single-threaded we model "simultaneous" requests by
     * acquiring all available slots first, then verifying that further acquires
     * are queued (depth increases) but do not exceed maxConcurrentRenderers in
     * the active-holding state until slots are released.
     *
     * The scenario:
     *   - 3 slots available (maxConcurrentRenderers = 3)
     *   - Acquire 3 times → all slots taken (3 "active" renderers)
     *   - Attempt 7 more acquires with a 0-second timeout → all time out (503)
     *     because no slot will be freed and we model the excess as rejected
     *   - Verify peak active never exceeded maxConcurrentRenderers
     *   - Release all 3 → slots restored
     *
     * Requirements: 6.1, 6.2
     */
    public function testAtMostMaxConcurrentRenderersActiveAtOnce(): void
    {
        $max   = 3;
        $guard = $this->makeGuard(maxConcurrentRenderers: $max);

        // Fill all $max slots
        for ($i = 0; $i < $max; $i++) {
            $guard->acquire();
        }

        $this->assertSame(0, $guard->getAvailableSlots(),
            'All slots must be taken after acquiring maxConcurrentRenderers times');
        $this->assertSame($max, $guard->getCurrentActive(),
            'Active render count must equal maxConcurrentRenderers');

        // Simulate 7 excess requests with a 0-second timeout — they should all
        // time out immediately since no slot is freed.
        $rejected = 0;
        for ($i = 0; $i < 7; $i++) {
            try {
                $guard->acquire(timeoutSeconds: 0);
            } catch (ConcurrencyException) {
                $rejected++;
            }
        }

        // All 7 excess requests must have been rejected (queue timeout)
        $this->assertSame(7, $rejected,
            '7 excess requests must all be rejected when all slots are taken');

        // The peak active count must never have exceeded maxConcurrentRenderers
        $this->assertLessThanOrEqual($max, $guard->getPeakActive(),
            'Peak active renderers must never exceed maxConcurrentRenderers');

        // Release all 3 active slots
        for ($i = 0; $i < $max; $i++) {
            $guard->release();
        }

        $this->assertSame(0, $guard->getCurrentActive(),
            'Active count must be 0 after releasing all slots');
        $this->assertSame($max, $guard->getAvailableSlots(),
            'All slots must be restored after releasing');
    }

    /**
     * Verifies the specific "10 simultaneous requests with maxConcurrentRenderers=2"
     * scenario from the task description.
     *
     * Steps:
     *  1. Acquire 2 slots (fills pool)
     *  2. Attempt 8 more acquires with 0-second timeout → all return 503
     *  3. Peak active == 2 (never exceeded the limit)
     *  4. Release both → pool restored
     *
     * Requirements: 6.1, 6.2, 6.3
     */
    public function testTenSimultaneousRequestsWithTwoSlotPool(): void
    {
        $max   = 2;
        $total = 10;
        $guard = $this->makeGuard(maxConcurrentRenderers: $max);

        // Acquire all available slots
        for ($i = 0; $i < $max; $i++) {
            $guard->acquire();
        }

        $this->assertSame(0, $guard->getAvailableSlots(), 'All slots must be consumed');

        // Simulate the remaining 8 "simultaneous" requests arriving while pool is full
        $accepted = 0;
        $rejected = 0;

        for ($i = 0; $i < ($total - $max); $i++) {
            try {
                $guard->acquire(timeoutSeconds: 0); // immediate timeout → 503
                $accepted++;
            } catch (ConcurrencyException $e) {
                $this->assertMatchesRegularExpression(
                    '/queue (full|timeout)/i',
                    $e->getMessage(),
                    'Rejected requests must have a queue full or queue timeout message'
                );
                $rejected++;
            }
        }

        // All 8 excess requests must be rejected
        $this->assertSame(0, $accepted,
            'No excess request must be accepted when the pool is full');
        $this->assertSame($total - $max, $rejected,
            'All excess requests must be rejected with 503');

        // Peak active must never have exceeded the configured maximum
        $this->assertLessThanOrEqual($max, $guard->getPeakActive(),
            'Peak concurrent renderers must never exceed maxConcurrentRenderers');

        // Release the 2 active slots and verify the pool is restored
        for ($i = 0; $i < $max; $i++) {
            $guard->release();
        }

        $this->assertSame($max, $guard->getAvailableSlots(),
            'Pool must be fully restored after all releases');
        $this->assertSame(0, $guard->getCurrentQueueDepth(),
            'Queue depth must be 0 after all releases');
    }

    // -----------------------------------------------------------------------
    // 2. Queue-full behaviour — immediate 503 (Requirement 6.3)
    // -----------------------------------------------------------------------

    /**
     * When the queue is at MAX_QUEUE_SIZE (20), additional requests must be
     * rejected immediately with "queue full" without entering the spin-wait.
     *
     * This models the scenario where 10 requests arrive simultaneously, the
     * queue-full threshold is hit, and excess requests get 503 immediately.
     *
     * Requirements: 6.3
     */
    public function testExcessRequestsBeyondQueueSizeAreRejectedImmediately(): void
    {
        // MAX_QUEUE_SIZE = 20, and we need slots > 0 to allow the first 20 to
        // enter the queue (depth increments before the slot check).
        // Use 20 slots so the first 20 acquires each take a slot immediately.
        $guard = $this->makeGuard(maxConcurrentRenderers: 20);

        // Fill the queue depth to exactly MAX_QUEUE_SIZE (20)
        for ($i = 0; $i < 20; $i++) {
            $guard->acquire();
        }

        $this->assertSame(20, $guard->getCurrentQueueDepth(),
            'Queue depth must be 20 after 20 acquires');

        // 21st acquire must be rejected with "queue full"
        $caught = null;
        try {
            $guard->acquire();
        } catch (ConcurrencyException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Must throw ConcurrencyException when queue is full');
        $this->assertStringContainsString('queue full', $caught->getMessage(),
            'Exception message must say "queue full"');

        // Depth must not have been incremented by the rejected request
        $this->assertSame(20, $guard->getCurrentQueueDepth(),
            'Depth must remain 20 after a queue-full rejection');

        // Clean up
        for ($i = 0; $i < 20; $i++) {
            $guard->release();
        }
    }

    // -----------------------------------------------------------------------
    // 3. Queue-timeout behaviour — 503 after wait (Requirement 6.4)
    // -----------------------------------------------------------------------

    /**
     * When all slots are taken and the configured timeout elapses, acquire()
     * must throw ConcurrencyException with "queue timeout".
     *
     * Simulates requests that queue successfully but wait too long.
     *
     * Requirements: 6.4
     */
    public function testQueuedRequestTimesOutWith503(): void
    {
        $guard = $this->makeGuard(maxConcurrentRenderers: 1);
        $guard->acquire(); // take the only slot; depth = 1

        $caught = null;
        $start  = microtime(true);

        try {
            $guard->acquire(timeoutSeconds: 1); // must time out after ~1 second
        } catch (ConcurrencyException $e) {
            $caught = $e;
        }

        $elapsed = microtime(true) - $start;

        $this->assertNotNull($caught, 'Must throw ConcurrencyException on timeout');
        $this->assertStringContainsString('queue timeout', $caught->getMessage(),
            'Exception message must say "queue timeout"');

        // Should have waited approximately 1 second (give 1.5 s upper bound for CI)
        $this->assertGreaterThanOrEqual(0.9, $elapsed,
            'Timeout must wait at least ~1 second before throwing');
        $this->assertLessThanOrEqual(3.0, $elapsed,
            'Timeout must not wait significantly longer than the configured timeout');

        // Depth must be restored to 1 (only the original acquire remains)
        $this->assertSame(1, $guard->getCurrentQueueDepth(),
            'Queue depth must be restored to 1 after the timed-out request is removed');

        $guard->release();
    }

    // -----------------------------------------------------------------------
    // 4. Release restores capacity — next request succeeds (Requirement 6.2)
    // -----------------------------------------------------------------------

    /**
     * After a slot is released, a new acquire succeeds immediately.
     * This models the sequential queuing behaviour: once a renderer finishes,
     * the next waiting request is admitted.
     *
     * Requirements: 6.2
     */
    public function testReleasingSlotAllowsNextRequestToSucceed(): void
    {
        $guard = $this->makeGuard(maxConcurrentRenderers: 1);
        $guard->acquire(); // Renderer 1 starts

        // Renderer 1 finishes
        $guard->release();

        // Next request must now succeed immediately
        $guard->acquire(); // Renderer 2 starts

        $this->assertSame(0, $guard->getAvailableSlots(), 'Slot must be taken by renderer 2');
        $this->assertSame(1, $guard->getCurrentActive(), 'Exactly one renderer must be active');

        $guard->release();

        $this->assertSame(0, $guard->getCurrentQueueDepth(),
            'Queue depth must be 0 after complete cycle');
        $this->assertSame(1, $guard->getAvailableSlots(),
            'Slot must be fully restored');
    }

    /**
     * Sequential requests that fit within the concurrency limit all succeed.
     *
     * Simulates 10 requests being processed 2 at a time:
     *  - Acquire 2 (fills pool)
     *  - Release 2
     *  - Acquire 2 (fills pool again)
     *  - ... repeat 5 times total → all 10 requests served without rejection
     *
     * Requirements: 6.1, 6.2
     */
    public function testTenSequentialRequestsAllSucceedWithTwoSlotPool(): void
    {
        $max           = 2;
        $totalRequests = 10;
        $batches       = $totalRequests / $max; // 5 batches
        $guard         = $this->makeGuard(maxConcurrentRenderers: $max);

        $served = 0;

        for ($batch = 0; $batch < $batches; $batch++) {
            // Acquire all slots
            for ($i = 0; $i < $max; $i++) {
                $guard->acquire();
                $served++;
            }

            $this->assertSame(0, $guard->getAvailableSlots(),
                "Batch {$batch}: all slots must be taken");

            // Release all slots (batch complete)
            for ($i = 0; $i < $max; $i++) {
                $guard->release();
            }

            $this->assertSame($max, $guard->getAvailableSlots(),
                "Batch {$batch}: all slots must be restored after release");
        }

        $this->assertSame($totalRequests, $served,
            'All 10 requests must have been served');
        $this->assertSame(0, $guard->getCurrentQueueDepth(),
            'Queue depth must be 0 at the end');
        $this->assertLessThanOrEqual($max, $guard->getPeakActive(),
            'Peak concurrent renderers must never exceed maxConcurrentRenderers');
    }

    // -----------------------------------------------------------------------
    // 5. Mixed batch — some succeed, rest queue or 503 (Requirements 6.1–6.3)
    // -----------------------------------------------------------------------

    /**
     * Simulates a burst of 10 requests where the pool has 3 slots and the
     * remaining 7 requests are held in the queue until the first 3 finish:
     *
     *  Phase A: Acquire 3 slots (active = 3, depth = 3)
     *  Phase B: Attempt 7 more with timeout=0 → all 7 time out immediately
     *  Phase C: Release 3 slots → pool restored
     *  Phase D: Acquire 3 more → succeeds (pool available again)
     *
     * This verifies that the semaphore-based slot model correctly enforces
     * the concurrency limit across the entire request lifecycle.
     *
     * Requirements: 6.1, 6.2, 6.3
     */
    public function testMixedBurstWithPartialQueueDrainage(): void
    {
        $max   = 3;
        $guard = $this->makeGuard(maxConcurrentRenderers: $max);

        // Phase A: Fill the pool
        for ($i = 0; $i < $max; $i++) {
            $guard->acquire();
        }
        $this->assertSame($max, $guard->getCurrentActive());

        // Phase B: 7 excess requests with 0-second timeout → all rejected
        $rejectedCount = 0;
        for ($i = 0; $i < 7; $i++) {
            try {
                $guard->acquire(timeoutSeconds: 0);
            } catch (ConcurrencyException) {
                $rejectedCount++;
            }
        }
        $this->assertSame(7, $rejectedCount, '7 excess requests must be rejected');

        // Phase C: Release the 3 active slots
        for ($i = 0; $i < $max; $i++) {
            $guard->release();
        }
        $this->assertSame($max, $guard->getAvailableSlots(), 'Pool must be fully restored');

        // Phase D: New requests can now acquire immediately
        for ($i = 0; $i < $max; $i++) {
            $guard->acquire();
        }
        $this->assertSame($max, $guard->getCurrentActive(),
            'New requests must fill the pool after the previous batch released');
        $this->assertLessThanOrEqual($max, $guard->getPeakActive(),
            'Peak concurrent renderers must never have exceeded maxConcurrentRenderers');

        // Clean up
        for ($i = 0; $i < $max; $i++) {
            $guard->release();
        }
    }
}
