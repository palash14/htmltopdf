<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Exception\SsrfException;
use App\Exception\ValidationException;
use App\Service\SsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * A testable subclass that bypasses real DNS resolution.
 *
 * Inject the IPs you want returned by overriding resolveHostname().
 * SsrfGuard::resolveHostname() must be protected (not private) for this to work.
 */
class TestableSsrfGuard extends SsrfGuard
{
    /** @var string[] */
    private array $injectedIps;

    /** @param string[] $ips */
    public function __construct(array $ips)
    {
        $this->injectedIps = $ips;
    }

    /** @return string[] */
    protected function resolveHostname(string $hostname): array
    {
        return $this->injectedIps;
    }
}

/**
 * Unit tests for SsrfGuard.
 *
 * All DNS resolution is stubbed via TestableSsrfGuard so no real network
 * calls are made.
 *
 * Requirements: 5.2, 5.5
 */
class SsrfGuardTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helper: create a guard that resolves to the given IPs
    // -----------------------------------------------------------------------

    /** @param string[] $ips */
    private function guardWithIps(array $ips): TestableSsrfGuard
    {
        return new TestableSsrfGuard($ips);
    }

    private function checkUrl(TestableSsrfGuard $guard, string $url = 'https://example.com'): void
    {
        $guard->check($url);
    }

    // -----------------------------------------------------------------------
    // IPv4 private / disallowed ranges — all must throw SsrfException
    // -----------------------------------------------------------------------

    public function testLoopback127Throws(): void
    {
        $this->expectException(SsrfException::class);
        $this->checkUrl($this->guardWithIps(['127.0.0.1']));
    }

    public function testPrivate10Throws(): void
    {
        $this->expectException(SsrfException::class);
        $this->checkUrl($this->guardWithIps(['10.0.0.1']));
    }

    public function testPrivate172_16Throws(): void
    {
        $this->expectException(SsrfException::class);
        $this->checkUrl($this->guardWithIps(['172.16.0.1']));
    }

    public function testPrivate172_31Throws(): void
    {
        // Highest address in 172.16.0.0/12 range
        $this->expectException(SsrfException::class);
        $this->checkUrl($this->guardWithIps(['172.31.255.255']));
    }

    public function testPrivate192_168Throws(): void
    {
        $this->expectException(SsrfException::class);
        $this->checkUrl($this->guardWithIps(['192.168.1.1']));
    }

    public function testLinkLocal169_254Throws(): void
    {
        $this->expectException(SsrfException::class);
        $this->checkUrl($this->guardWithIps(['169.254.0.1']));
    }

    public function testThisNetwork0_0_0_1Throws(): void
    {
        $this->expectException(SsrfException::class);
        $this->checkUrl($this->guardWithIps(['0.0.0.1']));
    }

    public function testSharedAddress100_64Throws(): void
    {
        // RFC 6598 shared address space
        $this->expectException(SsrfException::class);
        $this->checkUrl($this->guardWithIps(['100.64.0.1']));
    }

    // -----------------------------------------------------------------------
    // IPv6 private / disallowed ranges — all must throw SsrfException
    // -----------------------------------------------------------------------

    public function testIpv6LoopbackThrows(): void
    {
        $this->expectException(SsrfException::class);
        $this->checkUrl($this->guardWithIps(['::1']));
    }

    public function testIpv6UniqueLocalFc00Throws(): void
    {
        $this->expectException(SsrfException::class);
        $this->checkUrl($this->guardWithIps(['fc00::1']));
    }

    public function testIpv6LinkLocalFe80Throws(): void
    {
        $this->expectException(SsrfException::class);
        $this->checkUrl($this->guardWithIps(['fe80::1']));
    }

    // -----------------------------------------------------------------------
    // Public IP addresses — must NOT throw any exception
    // -----------------------------------------------------------------------

    public function testPublicIp8_8_8_8Passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->checkUrl($this->guardWithIps(['8.8.8.8']));
    }

    public function testPublicIp1_1_1_1Passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->checkUrl($this->guardWithIps(['1.1.1.1']));
    }

    public function testPublicIp172_32JustOutside172_16_12Passes(): void
    {
        // 172.32.0.0 is the first address OUTSIDE the 172.16.0.0/12 range
        $this->expectNotToPerformAssertions();
        $this->checkUrl($this->guardWithIps(['172.32.0.1']));
    }

    // -----------------------------------------------------------------------
    // Empty IPs array — unresolvable hostname → ValidationException 422
    // -----------------------------------------------------------------------

    public function testEmptyIpsArrayThrowsValidationException422(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->checkUrl($this->guardWithIps([]));
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }
}
