<?php

declare(strict_types=1);

namespace Tests\Property;

use App\Model\Config;
use App\Service\RateLimiter;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Property-based tests for RateLimiter.
 *
 * Feature: url-to-pdf-api
 *
 * Because APCu is a shared-memory extension that may not be available in all
 * test environments, the tests use a TestableRateLimiter subclass that stores
 * counters in an in-memory array instead of APCu.  This makes the property
 * deterministic and fully portable without any infrastructure requirements.
 *
 * Validates: Requirement 6.5
 */
class RateLimiterPropertyTest extends TestCase
{
    use TestTrait;

    // -----------------------------------------------------------------------
    // Eris / PHPUnit 10 compatibility shim
    // -----------------------------------------------------------------------

    /**
     * PHPUnit 10 removed getAnnotations().  Return an empty structure so that
     * Eris falls back to its defaults (100 iterations, rand method, 50% ratio).
     *
     * @return array<string, array<string, list<string>>>
     */
    public function getTestCaseAnnotations(): array
    {
        return ['method' => [], 'class' => []];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a Config with rate limiting enabled at the given RPM.
     */
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

    /**
     * Build a Config with rate limiting disabled (null).
     */
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
    // Property 8: Rate limiting enforced per key
    //
    // For any API key with rate limiting enabled at limit R
    // requests-per-minute — the first R requests within any 60-second window
    // SHALL be allowed, and all subsequent requests within that same window
    // SHALL return HTTP 429 (i.e. isAllowed() returns false).
    //
    // // Feature: url-to-pdf-api, Property 8: Rate limiting enforced per key
    //
    // Validates: Requirement 6.5
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 8: The first R calls to isAllowed() for a given key are true;
     * the (R+1)-th call is false.
     */
    public function testFirstRRequestsAllowedSubsequentBlocked(): void
    {
        $this->forAll(
            // Rate limit: 1–20 RPM (small so the test stays fast)
            Generators::choose(1, 20),
            // API key: hex string to simulate real key format
            Generators::map(
                static fn(int $n): string => 'key-' . dechex($n),
                Generators::choose(0, PHP_INT_MAX)
            )
        )
            ->withMaxSize(100)
            ->then(function (int $limit, string $apiKey): void {
                $rateLimiter = new PropertyTestRateLimiter(
                    $this->makeConfig($limit),
                    new NullLogger()
                );

                // First $limit calls must ALL be allowed
                for ($i = 1; $i <= $limit; $i++) {
                    $this->assertTrue(
                        $rateLimiter->isAllowed($apiKey),
                        "Request #{$i} of {$limit} should be allowed for key '{$apiKey}' with limit {$limit}"
                    );
                }

                // The very next call (limit + 1) must be blocked
                $this->assertFalse(
                    $rateLimiter->isAllowed($apiKey),
                    "Request #" . ($limit + 1) . " should be blocked for key '{$apiKey}' with limit {$limit}"
                );
            });
    }

    /**
     * @test
     * Property 8b: All requests beyond the limit (multiple over-limit calls)
     * are also blocked within the same window.
     */
    public function testAllRequestsBeyondLimitAreBlocked(): void
    {
        $this->forAll(
            Generators::choose(1, 10),   // limit R
            Generators::choose(1, 10),   // extra requests beyond limit
            Generators::map(
                static fn(int $n): string => 'key-' . dechex($n),
                Generators::choose(0, PHP_INT_MAX)
            )
        )
            ->withMaxSize(100)
            ->then(function (int $limit, int $extra, string $apiKey): void {
                $rateLimiter = new PropertyTestRateLimiter(
                    $this->makeConfig($limit),
                    new NullLogger()
                );

                // Consume the entire quota
                for ($i = 0; $i < $limit; $i++) {
                    $rateLimiter->isAllowed($apiKey);
                }

                // Every request over the limit must be blocked
                for ($j = 1; $j <= $extra; $j++) {
                    $this->assertFalse(
                        $rateLimiter->isAllowed($apiKey),
                        "Over-limit request #{$j} should be blocked for key '{$apiKey}' with limit {$limit}"
                    );
                }
            });
    }

    /**
     * @test
     * Property 8c: When rate limiting is disabled (rateLimitRpm = null),
     * every request is allowed regardless of call count.
     */
    public function testWhenRateLimitingDisabledAllRequestsAllowed(): void
    {
        $this->forAll(
            Generators::choose(1, 50),   // number of calls
            Generators::map(
                static fn(int $n): string => 'key-' . dechex($n),
                Generators::choose(0, PHP_INT_MAX)
            )
        )
            ->withMaxSize(100)
            ->then(function (int $callCount, string $apiKey): void {
                $rateLimiter = new PropertyTestRateLimiter(
                    $this->makeConfigNoLimit(),
                    new NullLogger()
                );

                for ($i = 1; $i <= $callCount; $i++) {
                    $this->assertTrue(
                        $rateLimiter->isAllowed($apiKey),
                        "Call #{$i} should be allowed when rate limiting is disabled"
                    );
                }
            });
    }

    /**
     * @test
     * Property 8d: Different API keys have independent rate limit counters.
     * Key A reaching its limit does NOT affect key B.
     */
    public function testDifferentKeysAreIndependent(): void
    {
        $this->forAll(
            Generators::choose(1, 10),   // limit R
            // Two distinct key suffixes
            Generators::map(
                static fn(int $n): string => 'key-A-' . dechex($n),
                Generators::choose(0, PHP_INT_MAX)
            ),
            Generators::map(
                static fn(int $n): string => 'key-B-' . dechex($n),
                Generators::choose(0, PHP_INT_MAX)
            )
        )
            ->withMaxSize(100)
            ->then(function (int $limit, string $keyA, string $keyB): void {
                // Ensure keys are actually different
                if ($keyA === $keyB) {
                    $keyB = $keyB . '-distinct';
                }

                $rateLimiter = new PropertyTestRateLimiter(
                    $this->makeConfig($limit),
                    new NullLogger()
                );

                // Exhaust keyA's quota
                for ($i = 0; $i < $limit; $i++) {
                    $rateLimiter->isAllowed($keyA);
                }
                // keyA is now over its limit
                $this->assertFalse(
                    $rateLimiter->isAllowed($keyA),
                    "keyA should be blocked after {$limit} requests"
                );

                // keyB must still have its full quota available
                $this->assertTrue(
                    $rateLimiter->isAllowed($keyB),
                    "keyB should still be allowed after keyA is exhausted"
                );
            });
    }
}

// =============================================================================
// PropertyTestRateLimiter — in-memory APCu substitute for property tests
// =============================================================================

/**
 * @internal Test-only subclass of RateLimiter that replaces APCu with an
 *           in-memory array.  Each instance has its own isolated store, so
 *           independent `new PropertyTestRateLimiter(...)` calls within a
 *           single property iteration start with clean counters.
 */
class PropertyTestRateLimiter extends RateLimiter
{
    /** @var array<string, int> In-memory counter store keyed by window key */
    private array $store = [];

    protected function isApcuAvailable(): bool
    {
        return true; // Always report APCu as available so the rate-limit path runs
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
