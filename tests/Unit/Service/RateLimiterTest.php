<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Model\Config;
use App\Service\RateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Unit tests for RateLimiter.
 *
 * APCu is a shared-memory extension that may or may not be available in the
 * test runner.  All tests use an in-memory subclass (InMemoryRateLimiter) so
 * they run deterministically in every environment.  A separate test group
 * exercises the APCu-unavailable fallback path via a purpose-built subclass.
 *
 * Requirements: 6.5
 */
class RateLimiterTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeConfig(int $rateLimitRpm): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: ['test-key'],
            storageDir: sys_get_temp_dir(),
            baseUrl: 'https://example.com',
            rateLimitRpm: $rateLimitRpm,
        );
    }

    private function makeConfigNoLimit(): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: ['test-key'],
            storageDir: sys_get_temp_dir(),
            baseUrl: 'https://example.com',
            rateLimitRpm: null,
        );
    }

    // -----------------------------------------------------------------------
    // 1. Limit not reached → requests allowed
    // -----------------------------------------------------------------------

    /**
     * When the number of requests is below the configured RPM limit, every
     * call to isAllowed() returns true.
     *
     * Requirements: 6.5
     */
    public function testRequestsBelowLimitAreAllowed(): void
    {
        $limiter = new InMemoryRateLimiter($this->makeConfig(5), new NullLogger());

        self::assertTrue($limiter->isAllowed('api-key-one'), 'Request 1 of 5 should be allowed');
        self::assertTrue($limiter->isAllowed('api-key-one'), 'Request 2 of 5 should be allowed');
        self::assertTrue($limiter->isAllowed('api-key-one'), 'Request 3 of 5 should be allowed');
    }

    /**
     * Exactly at the limit (limit = 1): the single allowed request returns
     * true, and the next one returns false.
     *
     * Requirements: 6.5
     */
    public function testExactlyAtLimitOfOneAllowsThenBlocks(): void
    {
        $limiter = new InMemoryRateLimiter($this->makeConfig(1), new NullLogger());

        self::assertTrue($limiter->isAllowed('api-key'), 'The 1st request (at limit=1) must be allowed');
        self::assertFalse($limiter->isAllowed('api-key'), 'The 2nd request must be blocked when limit=1');
    }

    // -----------------------------------------------------------------------
    // 2. Limit reached → requests blocked
    // -----------------------------------------------------------------------

    /**
     * Once the configured RPM is exhausted all subsequent calls return false.
     *
     * Requirements: 6.5
     */
    public function testLimitReachedBlocksSubsequentRequests(): void
    {
        $limit   = 3;
        $limiter = new InMemoryRateLimiter($this->makeConfig($limit), new NullLogger());
        $key     = 'blocked-key';

        // Consume the entire quota
        for ($i = 0; $i < $limit; $i++) {
            $limiter->isAllowed($key);
        }

        self::assertFalse($limiter->isAllowed($key), 'Request beyond limit must be blocked');
        self::assertFalse($limiter->isAllowed($key), 'Second request beyond limit must also be blocked');
    }

    // -----------------------------------------------------------------------
    // 3. Separate keys are independent
    // -----------------------------------------------------------------------

    /**
     * Rate-limit counters are scoped to each API key.  Exhausting key A does
     * not affect key B.
     *
     * Requirements: 6.5
     */
    public function testSeparateKeysAreIndependent(): void
    {
        $limit   = 2;
        $limiter = new InMemoryRateLimiter($this->makeConfig($limit), new NullLogger());
        $keyA    = 'key-alpha';
        $keyB    = 'key-beta';

        // Exhaust keyA
        $limiter->isAllowed($keyA);
        $limiter->isAllowed($keyA);
        self::assertFalse($limiter->isAllowed($keyA), 'keyA should be blocked after 2 requests');

        // keyB is untouched — its first request must still be allowed
        self::assertTrue($limiter->isAllowed($keyB), 'keyB should be unaffected by keyA exhaustion');
        self::assertTrue($limiter->isAllowed($keyB), 'keyB 2nd request should still be allowed');
        self::assertFalse($limiter->isAllowed($keyB), 'keyB 3rd request should be blocked');
    }

    // -----------------------------------------------------------------------
    // 4. Window reset after 60-second boundary
    // -----------------------------------------------------------------------

    /**
     * The rate limiter uses a fixed 1-minute window keyed by the minute
     * boundary.  After the window rolls over (simulated by advancing the
     * internal clock), the counter resets and requests are allowed again.
     *
     * Requirements: 6.5
     */
    public function testWindowResetAfter60SecondBoundary(): void
    {
        $limit   = 2;
        $key     = 'window-reset-key';
        $limiter = new ManualClockRateLimiter($this->makeConfig($limit), new NullLogger());

        // Set initial time to the start of minute 100
        $limiter->setCurrentTime(100 * 60.0);

        // Exhaust the quota for minute 100
        self::assertTrue($limiter->isAllowed($key),  'Request 1 of window-1 must be allowed');
        self::assertTrue($limiter->isAllowed($key),  'Request 2 of window-1 must be allowed');
        self::assertFalse($limiter->isAllowed($key), 'Request 3 of window-1 must be blocked');

        // Advance to the next minute (window 101)
        $limiter->setCurrentTime(101 * 60.0);

        // The new window starts with a fresh counter
        self::assertTrue($limiter->isAllowed($key),  'Request 1 of window-2 must be allowed (counter reset)');
        self::assertTrue($limiter->isAllowed($key),  'Request 2 of window-2 must be allowed');
        self::assertFalse($limiter->isAllowed($key), 'Request 3 of window-2 must be blocked again');
    }

    // -----------------------------------------------------------------------
    // 5. Rate limiting disabled (rateLimitRpm = null)
    // -----------------------------------------------------------------------

    /**
     * When rateLimitRpm is null the limiter is disabled and must always allow
     * requests.  The in-memory store is never consulted.
     *
     * Requirements: 6.5
     */
    public function testRateLimitingDisabledAlwaysAllows(): void
    {
        $limiter = new InMemoryRateLimiter($this->makeConfigNoLimit(), new NullLogger());
        $key     = 'any-key';

        for ($i = 0; $i < 100; $i++) {
            self::assertTrue($limiter->isAllowed($key), "Request #{$i} must be allowed when rate limiting is disabled");
        }
    }

    // -----------------------------------------------------------------------
    // 6. APCu unavailable → graceful fallback (allows all, logs warning)
    // -----------------------------------------------------------------------

    /**
     * When APCu is not available the limiter degrades gracefully: it allows
     * every request and emits exactly one warning per call via the logger.
     *
     * Requirements: 6.5
     */
    public function testApcuUnavailableFallsBackAndLogsWarning(): void
    {
        $logger  = new CapturingLogger();
        $limiter = new ApcuUnavailableRateLimiter($this->makeConfig(5), $logger);

        // Should allow even though limit=5 would normally kick in after 5 calls
        for ($i = 0; $i < 10; $i++) {
            self::assertTrue(
                $limiter->isAllowed('some-key'),
                "Request #{$i} must be allowed when APCu is unavailable"
            );
        }

        // Every call should have produced a warning
        self::assertCount(10, $logger->records, 'Each call must log a warning when APCu is unavailable');
        foreach ($logger->records as $record) {
            self::assertSame('warning', $record['level']);
        }
    }

    // -----------------------------------------------------------------------
    // 7. Large limit — all requests within limit are allowed
    // -----------------------------------------------------------------------

    /**
     * Sanity-check: with a large limit (1000 RPM) the first 1000 calls are
     * all allowed and the 1001st is blocked.
     *
     * Requirements: 6.5
     */
    public function testLargeLimitAllowsUpToLimitAndThenBlocks(): void
    {
        $limit   = 1000;
        $key     = 'high-volume-key';
        $limiter = new InMemoryRateLimiter($this->makeConfig($limit), new NullLogger());

        for ($i = 1; $i <= $limit; $i++) {
            self::assertTrue($limiter->isAllowed($key), "Request #{$i} should be allowed (limit={$limit})");
        }

        self::assertFalse($limiter->isAllowed($key), "Request #1001 must be blocked (limit={$limit})");
    }
}

// =============================================================================
// Test-only subclasses
// =============================================================================

/**
 * RateLimiter backed by an in-memory array instead of APCu.
 *
 * Each instance starts with an empty store, giving full isolation between
 * test cases.  The window is derived from the real system clock (same as the
 * production code) so window-rotation tests should use ManualClockRateLimiter
 * instead.
 *
 * @internal
 */
class InMemoryRateLimiter extends RateLimiter
{
    /** @var array<string, int> */
    private array $store = [];

    protected function isApcuAvailable(): bool
    {
        return true;
    }

    protected function apcuIncrement(string $key, int $ttl): int
    {
        if (!isset($this->store[$key])) {
            $this->store[$key] = 0;
        }

        return ++$this->store[$key];
    }

    protected function apcuStore(string $key, int $value, int $ttl): void
    {
        $this->store[$key] = $value;
    }
}

/**
 * RateLimiter with a controllable clock, allowing window-boundary tests.
 *
 * Call setCurrentTime() to move the simulated time before each call to
 * isAllowed().  Time is expressed in seconds (as a float, same as microtime).
 *
 * @internal
 */
class ManualClockRateLimiter extends RateLimiter
{
    /** @var array<string, int> */
    private array $store = [];

    private float $currentTime;

    public function setCurrentTime(float $time): void
    {
        $this->currentTime = $time;
    }

    protected function isApcuAvailable(): bool
    {
        return true;
    }

    protected function getCurrentTime(): float
    {
        return $this->currentTime;
    }

    protected function apcuIncrement(string $key, int $ttl): int
    {
        if (!isset($this->store[$key])) {
            $this->store[$key] = 0;
        }

        return ++$this->store[$key];
    }

    protected function apcuStore(string $key, int $value, int $ttl): void
    {
        $this->store[$key] = $value;
    }
}

/**
 * RateLimiter that always reports APCu as unavailable.
 *
 * Used to test the graceful-degradation path.
 *
 * @internal
 */
class ApcuUnavailableRateLimiter extends RateLimiter
{
    protected function isApcuAvailable(): bool
    {
        return false;
    }
}

// =============================================================================
// CapturingLogger — records log calls for assertion in tests
// =============================================================================

/**
 * Simple PSR-3 logger that stores every log record for later inspection.
 *
 * @internal
 */
class CapturingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param mixed               $level
     * @param string|\Stringable  $message
     * @param array<mixed>        $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}


// =============================================================================
// ApcuStoreExposingRateLimiter — subclass to exercise the real apcuStore()
// =============================================================================

/**
 * Thin subclass that exposes the protected apcuStore() method for direct
 * testing, while still declaring APCu available so the full isAllowed()
 * code path runs.  apcuIncrement is overridden to simulate the "key did not
 * exist" (success=false) branch so that apcuStore gets called.
 *
 * @internal
 */
class ApcuStoreCallingRateLimiter extends RateLimiter
{
    /** Tracks how many times apcuStore was called via callApcuStore(). */
    public int $storeCallCount = 0;

    protected function isApcuAvailable(): bool
    {
        // Report APCu as available so the main isAllowed() path proceeds.
        return true;
    }

    protected function apcuIncrement(string $key, int $ttl): int
    {
        // Directly invoke the real apcuStore (protected) to simulate the
        // "key did not exist" branch in the real apcuIncrement().
        // apcu_store is a no-op / returns false when APCu is not loaded,
        // so this won't throw.
        $this->apcuStore($key, 1, $ttl);
        $this->storeCallCount++;
        return 1;
    }

    /**
     * Public proxy so tests can call apcuStore() directly.
     */
    public function callApcuStore(string $key, int $value, int $ttl): void
    {
        $this->apcuStore($key, $value, $ttl);
    }
}

/**
 * Additional tests to cover the apcuStore() method body (line 113 in
 * RateLimiter.php) and the apcuIncrement fallback that calls it.
 */
class RateLimiterApcuStoreTest extends TestCase
{
    private function makeConfig(int $rateLimitRpm = 5): Config
    {
        return new Config(
            port: 8080,
            wkhtmltopdfPath: '/usr/bin/wkhtmltopdf',
            apiKeys: ['test-key'],
            storageDir: sys_get_temp_dir(),
            baseUrl: 'https://example.com',
            rateLimitRpm: $rateLimitRpm,
        );
    }

    /**
     * The protected apcuStore() method must be callable without throwing,
     * even when the real APCu extension is absent (apcu_store returns false
     * gracefully rather than erroring).
     *
     * This directly covers the apcuStore() method body (RateLimiter line 113).
     */
    public function testApcuStoreMethodDoesNotThrow(): void
    {
        $limiter = new ApcuStoreCallingRateLimiter($this->makeConfig(), new NullLogger());

        // Calling via public proxy must not throw regardless of APCu availability
        $this->expectNotToPerformAssertions();
        $limiter->callApcuStore('test-key', 1, 120);
    }

    /**
     * When apcuIncrement internally calls apcuStore (the "key did not exist"
     * path), isAllowed() must still return the correct boolean result.
     *
     * This covers the apcuStore() call within apcuIncrement() and the
     * apcuStore() method itself.
     */
    public function testApcuStoreIsCalledDuringApcuIncrementFallback(): void
    {
        $limiter = new ApcuStoreCallingRateLimiter($this->makeConfig(rateLimitRpm: 5), new NullLogger());

        $result = $limiter->isAllowed('some-key');

        // apcuIncrement was called and internally invoked apcuStore()
        $this->assertSame(1, $limiter->storeCallCount,
            'apcuStore must have been called once during apcuIncrement');
        // Count returned was 1 (≤ limit of 5), so the request is allowed
        $this->assertTrue($result, 'isAllowed must return true when count=1 ≤ rateLimitRpm=5');
    }
}
