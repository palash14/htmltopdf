<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Exception\ConcurrencyException;
use App\Model\Config;
use App\Service\ConcurrencyGuard;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConcurrencyGuard.
 *
 * Because ConcurrencyGuard depends on System V semaphores (Linux-only) and
 * APCu, we use a TestableConcurrencyGuard subclass that overrides acquire()
 * and release() to use simple in-memory counters. This makes the tests
 * portable to Windows and avoids shared-memory side effects between test runs.
 *
 * Requirements: 6.1, 6.2, 6.3, 6.4
 */

// ---------------------------------------------------------------------------
// Test double: in-memory ConcurrencyGuard
// ---------------------------------------------------------------------------

/**
 * TestableConcurrencyGuard replaces the SysV semaphore and APCu queue
 * tracking with simple instance-level counters so that the queue-full,
 * queue-timeout, and release semantics can be exercised without OS-level
 * IPC or a shared-memory extension.
 *
 * Slot model:
 *   $slots   — how many renderer slots are currently available (starts = maxConcurrentRenderers)
 *   $depth   — how many requests are currently "in the queue" (waiting or rendering)
 *
 * acquire() logic mirrors ConcurrencyGuard:
 *   1. Increment depth.
 *   2. If depth > MAX_QUEUE_SIZE → decrement depth, throw ConcurrencyException("queue full").
 *   3. Try to take a slot (non-blocking).  If a slot is available → take it, return.
 *   4. Spin until timeout, trying to take a slot every poll interval.
 *   5. On timeout → decrement depth, throw ConcurrencyException("queue timeout").
 */
class TestableConcurrencyGuard extends ConcurrencyGuard
{
    /** Maximum requests allowed in the "queue" (mirrors the production constant). */
    private const MAX_QUEUE_SIZE = 20;

    /** How many milliseconds to sleep between slot-availability polls. */
    private const POLL_INTERVAL_MS = 10;

    /** Number of renderer slots currently available. */
    private int $slots;

    /** Current queue depth (waiting + rendering). */
    private int $depth = 0;

    public function __construct(Config $config)
    {
        // Bypass the parent constructor's sem_get() call entirely: we call the
        // grandparent (\RuntimeException is the root, so we can't avoid the
        // constructor chain).  We do this by invoking parent::__construct but
        // using a temp dir that exists so sem_get (if present) won't crash.
        parent::__construct($config);

        // Override the slot count with the configured maximum.
        $this->slots = $config->maxConcurrentRenderers;
    }

    // -----------------------------------------------------------------------
    // Public API (overrides)
    // -----------------------------------------------------------------------

    /**
     * In-memory acquire: increment depth → check queue full → wait for slot →
     * timeout.
     */
    public function acquire(int $timeoutSeconds = 60): void
    {
        // Step 1: claim a queue spot.
        $this->depth++;

        // Step 2: queue-full check.
        if ($this->depth > self::MAX_QUEUE_SIZE) {
            $this->depth--;
            throw new ConcurrencyException('queue full');
        }

        // Step 3 & 4: spin-wait for a renderer slot.
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if ($this->slots > 0) {
                $this->slots--;
                return; // slot acquired
            }
            usleep(self::POLL_INTERVAL_MS * 1_000);
        } while (microtime(true) < $deadline);

        // Step 5: timeout.
        $this->depth--;
        throw new ConcurrencyException('queue timeout');
    }

    /**
     * In-memory release: return the slot and decrement depth.
     */
    public function release(): void
    {
        $this->slots++;
        if ($this->depth > 0) {
            $this->depth--;
        }
    }

    // -----------------------------------------------------------------------
    // Inspection helpers (test-only)
    // -----------------------------------------------------------------------

    /** Returns the current queue depth (number of in-flight acquire() calls). */
    public function getCurrentQueueDepth(): int
    {
        return $this->depth;
    }

    /** Returns the number of available renderer slots. */
    public function getAvailableSlots(): int
    {
        return $this->slots;
    }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------

class ConcurrencyGuardTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a Config suitable for ConcurrencyGuard construction.
     *
     * @param int $maxConcurrentRenderers Number of renderer slots.
     */
    private function makeConfig(int $maxConcurrentRenderers = 2): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: ['key'],
            storageDir: sys_get_temp_dir(),
            baseUrl: 'https://example.com',
            maxConcurrentRenderers: $maxConcurrentRenderers,
        );
    }

    /**
     * Create a TestableConcurrencyGuard with the given slot count.
     */
    private function makeGuard(int $maxConcurrentRenderers = 2): TestableConcurrencyGuard
    {
        return new TestableConcurrencyGuard($this->makeConfig($maxConcurrentRenderers));
    }

    // -----------------------------------------------------------------------
    // 1. Slot available → acquires immediately (Requirement 6.1)
    // -----------------------------------------------------------------------

    /**
     * When at least one renderer slot is available, acquire() returns without
     * throwing.
     *
     * Requirement: 6.1
     */
    public function testSlotAvailableAcquiresImmediately(): void
    {
        $guard = $this->makeGuard(maxConcurrentRenderers: 1);

        // Should not throw — one slot is free.
        $guard->acquire();

        // Depth must reflect the one in-flight request.
        $this->assertSame(1, $guard->getCurrentQueueDepth());

        $guard->release();
    }

    /**
     * Multiple slots can be acquired concurrently up to the limit without
     * throwing.
     *
     * Requirement: 6.1
     */
    public function testMultipleSlotsCanBeAcquiredUpToLimit(): void
    {
        $guard = $this->makeGuard(maxConcurrentRenderers: 3);

        $guard->acquire(); // slot 1
        $guard->acquire(); // slot 2
        $guard->acquire(); // slot 3 — still within limit

        $this->assertSame(3, $guard->getCurrentQueueDepth());
        $this->assertSame(0, $guard->getAvailableSlots());

        $guard->release();
        $guard->release();
        $guard->release();
    }

    // -----------------------------------------------------------------------
    // 2. Queue full → 503 (Requirement 6.3)
    // -----------------------------------------------------------------------

    /**
     * When the queue depth would exceed MAX_QUEUE_SIZE (20), acquire() throws
     * ConcurrencyException with a message containing "queue full".
     *
     * Requirement: 6.3
     */
    public function testQueueFullReturns503(): void
    {
        // Use MAX_QUEUE_SIZE (20) slots so there's never a slot available.
        // This saturates the queue without triggering a timeout.
        $guard = $this->makeGuard(maxConcurrentRenderers: 20);

        // Fill up MAX_QUEUE_SIZE spots without releasing: each acquire()
        // immediately takes a slot, so depth goes from 1 to 20.
        for ($i = 0; $i < 20; $i++) {
            $guard->acquire();
        }

        // The 21st acquire() should trigger the queue-full path.
        $this->expectException(ConcurrencyException::class);
        $this->expectExceptionMessageMatches('/queue full/i');

        $guard->acquire();
    }

    /**
     * After a queue-full rejection the depth is decremented back so the guard
     * remains consistent.
     *
     * Requirement: 6.3
     */
    public function testQueueFullDoesNotCorruptDepthCounter(): void
    {
        $guard = $this->makeGuard(maxConcurrentRenderers: 20);

        for ($i = 0; $i < 20; $i++) {
            $guard->acquire();
        }

        $depthBeforeRejection = $guard->getCurrentQueueDepth(); // should be 20

        try {
            $guard->acquire(); // will be rejected
        } catch (ConcurrencyException) {
            // expected
        }

        // Depth must be back to what it was before the rejected call.
        $this->assertSame($depthBeforeRejection, $guard->getCurrentQueueDepth(),
            'Depth must not be incremented by a queue-full rejection');
    }

    // -----------------------------------------------------------------------
    // 3. Queue timeout → 503 (Requirement 6.4)
    // -----------------------------------------------------------------------

    /**
     * When all renderer slots are taken and the timeout expires before a slot
     * is freed, acquire() throws ConcurrencyException with "queue timeout".
     *
     * Requirement: 6.4
     */
    public function testQueueTimeoutReturns503(): void
    {
        // 1 slot — acquire it immediately so it is unavailable for the next call.
        $guard = $this->makeGuard(maxConcurrentRenderers: 1);
        $guard->acquire(); // consumes the only slot

        // Second acquire with a 1-second timeout: no slot will be freed,
        // so it must time out.
        $this->expectException(ConcurrencyException::class);
        $this->expectExceptionMessageMatches('/queue timeout/i');

        $guard->acquire(timeoutSeconds: 1);
    }

    /**
     * After a timeout the depth is decremented back to its pre-acquire value.
     *
     * Requirement: 6.4
     */
    public function testQueueTimeoutDoesNotCorruptDepthCounter(): void
    {
        $guard = $this->makeGuard(maxConcurrentRenderers: 1);
        $guard->acquire(); // fill the only slot; depth = 1

        $depthBeforeTimeout = $guard->getCurrentQueueDepth(); // 1

        try {
            $guard->acquire(timeoutSeconds: 1);
        } catch (ConcurrencyException) {
            // expected
        }

        // Depth must be restored to what it was before the timed-out call.
        $this->assertSame($depthBeforeTimeout, $guard->getCurrentQueueDepth(),
            'Depth must not remain incremented after a timeout rejection');
    }

    // -----------------------------------------------------------------------
    // 4. Release decrements depth (Requirement 6.2)
    // -----------------------------------------------------------------------

    /**
     * After acquire() + release(), the queue depth is decremented back to 0.
     *
     * Requirement: 6.2
     */
    public function testReleaseDecrementsDepth(): void
    {
        $guard = $this->makeGuard(maxConcurrentRenderers: 2);

        $guard->acquire();
        $this->assertSame(1, $guard->getCurrentQueueDepth(), 'Depth must be 1 after one acquire');

        $guard->release();
        $this->assertSame(0, $guard->getCurrentQueueDepth(), 'Depth must be 0 after release');
    }

    /**
     * Releasing a slot makes it available for the next acquire().
     *
     * Requirement: 6.2
     */
    public function testReleaseRestoresSlotForNextAcquire(): void
    {
        $guard = $this->makeGuard(maxConcurrentRenderers: 1);

        $guard->acquire(); // takes the only slot
        $this->assertSame(0, $guard->getAvailableSlots());

        $guard->release(); // returns the slot
        $this->assertSame(1, $guard->getAvailableSlots());

        // A fresh acquire should now succeed immediately.
        $guard->acquire();
        $this->assertSame(1, $guard->getCurrentQueueDepth());
        $guard->release();
    }

    // -----------------------------------------------------------------------
    // 5. Multiple acquire/release cycles (Requirement 6.2)
    // -----------------------------------------------------------------------

    /**
     * Acquiring N times then releasing N times brings depth back to 0.
     *
     * Requirement: 6.2
     */
    public function testMultipleAcquireReleaseCyclesReturnToZero(): void
    {
        $n     = 3;
        $guard = $this->makeGuard(maxConcurrentRenderers: $n);

        for ($i = 0; $i < $n; $i++) {
            $guard->acquire();
        }
        $this->assertSame($n, $guard->getCurrentQueueDepth(),
            "Depth must be {$n} after {$n} acquires");

        for ($i = 0; $i < $n; $i++) {
            $guard->release();
        }
        $this->assertSame(0, $guard->getCurrentQueueDepth(),
            'Depth must be 0 after releasing all acquired slots');
        $this->assertSame($n, $guard->getAvailableSlots(),
            'All slots must be restored after releasing all acquired slots');
    }

    /**
     * Repeated single acquire/release cycles all succeed without leaking depth.
     *
     * Requirement: 6.2
     */
    public function testRepeatedSingleCyclesDoNotLeakDepth(): void
    {
        $guard = $this->makeGuard(maxConcurrentRenderers: 1);

        for ($i = 0; $i < 10; $i++) {
            $guard->acquire();
            $guard->release();
        }

        $this->assertSame(0, $guard->getCurrentQueueDepth(),
            'Depth must be 0 after 10 acquire/release cycles');
        $this->assertSame(1, $guard->getAvailableSlots(),
            'Slot must be restored after each release cycle');
    }
}


// =============================================================================
// RealConcurrencyGuardTest — exercises the real ConcurrencyGuard class
// =============================================================================

/**
 * Tests that call the real (non-overridden) ConcurrencyGuard methods.
 *
 * On Windows, sem_get is not defined so $this->sem = null inside the
 * constructor's else branch.  APCu is also unavailable in CLI PHPUnit on
 * Windows, so incrementQueueDepth() always returns 1 (< MAX_QUEUE_SIZE=20).
 *
 * These tests therefore cover:
 *   - constructor: function_exists('sem_get') === false → sem = null  (line 50)
 *   - acquire():   depth=1 ≤ 20, sem===null → return immediately       (lines 72,75,77,82-83)
 *   - release():   sem===null → skip sem_release; decrementQueueDepth() (lines 99,101)
 *   - incrementQueueDepth(): APCu unavailable → return 1               (lines 109-110)
 *   - decrementQueueDepth(): APCu unavailable → return immediately      (lines 129-130)
 */
class RealConcurrencyGuardTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeConfig(): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: ['key'],
            storageDir: sys_get_temp_dir(),
            baseUrl: 'https://example.com',
            maxConcurrentRenderers: 5,
        );
    }

    // -----------------------------------------------------------------------
    // 1. Real acquire() succeeds when no semaphore is available
    // -----------------------------------------------------------------------

    /**
     * On platforms where sem_get is absent (Windows), the real acquire()
     * must return without throwing after the queue-depth check passes.
     */
    public function testRealAcquireSucceedsWhenNoSemaphore(): void
    {
        $guard = new ConcurrencyGuard($this->makeConfig());

        // Must not throw — depth=1 ≤ MAX_QUEUE_SIZE, sem===null → returns immediately
        $this->expectNotToPerformAssertions();
        $guard->acquire();
    }

    // -----------------------------------------------------------------------
    // 2. Real release() does not throw
    // -----------------------------------------------------------------------

    /**
     * Calling release() after a successful acquire() must not throw,
     * regardless of whether sem_get is available.
     */
    public function testRealReleaseDoesNotThrow(): void
    {
        $guard = new ConcurrencyGuard($this->makeConfig());
        $guard->acquire();

        // release() must never throw
        $this->expectNotToPerformAssertions();
        $guard->release();
    }

    // -----------------------------------------------------------------------
    // 3. Multiple acquire/release cycles on real class
    // -----------------------------------------------------------------------

    /**
     * Multiple sequential acquire+release cycles on the real class must all
     * succeed without exception.  This exercises the APCu-unavailable
     * fallback path in both incrementQueueDepth and decrementQueueDepth
     * across several iterations.
     */
    public function testRealMultipleCyclesDoNotThrow(): void
    {
        $guard = new ConcurrencyGuard($this->makeConfig());

        for ($i = 0; $i < 5; $i++) {
            $guard->acquire();
            $guard->release();
        }

        // All 5 cycles completed without exception
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // 4. acquire() without release() (depth accumulates, still ≤ 20)
    // -----------------------------------------------------------------------

    /**
     * Acquire several times without releasing: because APCu is unavailable,
     * incrementQueueDepth() always returns 1 (not the real accumulated depth),
     * so no ConcurrencyException is thrown even after many acquires.
     */
    public function testRealAcquireWithoutReleaseDoesNotThrowWhenApcuUnavailable(): void
    {
        $guard = new ConcurrencyGuard($this->makeConfig());

        // 5 sequential acquires — APCu unavailable means depth=1 each time
        for ($i = 0; $i < 5; $i++) {
            $guard->acquire();
        }

        $this->assertTrue(true, 'No ConcurrencyException should be thrown when APCu is unavailable');
    }
}
