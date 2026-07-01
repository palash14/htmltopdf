<?php

declare(strict_types=1);

namespace Tests\Property;

use App\Service\SsrfGuard;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Property-based tests for SsrfGuard.
 *
 * Feature: url-to-pdf-api
 *
 * Because SsrfGuard::isPrivateIp() is private, we access it via ReflectionMethod
 * to test the CIDR-matching logic directly without triggering real DNS queries.
 *
 * Validates: Requirement 5.2
 */
class SsrfGuardPropertyTest extends TestCase
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

    /** @var ReflectionMethod */
    private ReflectionMethod $isPrivateIp;

    /** @var SsrfGuard */
    private SsrfGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard       = new SsrfGuard();
        $this->isPrivateIp = new ReflectionMethod(SsrfGuard::class, 'isPrivateIp');
        $this->isPrivateIp->setAccessible(true);
    }

    /**
     * Call the private isPrivateIp() method on our SsrfGuard instance.
     */
    private function isPrivateIp(string $ip): bool
    {
        return (bool) $this->isPrivateIp->invoke($this->guard, $ip);
    }

    /**
     * Format four octets as a dotted-decimal IPv4 string.
     */
    private static function ipv4(int $a, int $b, int $c, int $d): string
    {
        return "{$a}.{$b}.{$c}.{$d}";
    }

    // -----------------------------------------------------------------------
    // Property 2: SSRF protection blocks private IP ranges
    //
    // For any URL whose hostname resolves to an IP address within the private,
    // loopback, or link-local ranges (10.0.0.0/8, 172.16.0.0/12,
    // 192.168.0.0/16, 127.0.0.0/8, 169.254.0.0/16, ::1/128, fc00::/7,
    // fe80::/10) — the API SHALL return HTTP 422.
    //
    // // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
    //
    // Validates: Requirement 5.2
    // -----------------------------------------------------------------------

    /**
     * @test
     * Property 2a: Any address in 10.0.0.0/8 is classified as private.
     *
     * // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
     */
    public function testRfc1918ClassAAddressesArePrivate(): void
    {
        // 10.0.0.0/8 — second octet 0-255, third 0-255, fourth 0-255
        $this->forAll(
            Generators::choose(0, 255),  // second octet
            Generators::choose(0, 255),  // third octet
            Generators::choose(0, 255)   // fourth octet
        )
            ->withMaxSize(100)
            ->then(function (int $b, int $c, int $d): void {
                $ip = self::ipv4(10, $b, $c, $d);
                $this->assertTrue(
                    $this->isPrivateIp($ip),
                    "10.x.x.x address {$ip} must be classified as private (10.0.0.0/8)"
                );
            });
    }

    /**
     * @test
     * Property 2b: Any address in 172.16.0.0/12 is classified as private.
     *
     * // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
     */
    public function testRfc1918ClassBAddressesArePrivate(): void
    {
        // 172.16.0.0/12 — second octet 16-31, third 0-255, fourth 0-255
        $this->forAll(
            Generators::choose(16, 31),  // second octet (172.16–172.31)
            Generators::choose(0, 255),  // third octet
            Generators::choose(0, 255)   // fourth octet
        )
            ->withMaxSize(100)
            ->then(function (int $b, int $c, int $d): void {
                $ip = self::ipv4(172, $b, $c, $d);
                $this->assertTrue(
                    $this->isPrivateIp($ip),
                    "172.{$b}.{$c}.{$d} must be classified as private (172.16.0.0/12)"
                );
            });
    }

    /**
     * @test
     * Property 2c: Any address in 192.168.0.0/16 is classified as private.
     *
     * // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
     */
    public function testRfc1918ClassCAddressesArePrivate(): void
    {
        // 192.168.0.0/16 — third octet 0-255, fourth 0-255
        $this->forAll(
            Generators::choose(0, 255),  // third octet
            Generators::choose(0, 255)   // fourth octet
        )
            ->withMaxSize(100)
            ->then(function (int $c, int $d): void {
                $ip = self::ipv4(192, 168, $c, $d);
                $this->assertTrue(
                    $this->isPrivateIp($ip),
                    "192.168.{$c}.{$d} must be classified as private (192.168.0.0/16)"
                );
            });
    }

    /**
     * @test
     * Property 2d: Any address in 127.0.0.0/8 is classified as private (loopback).
     *
     * // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
     */
    public function testLoopbackAddressesArePrivate(): void
    {
        // 127.0.0.0/8 — second, third, fourth octets 0-255
        $this->forAll(
            Generators::choose(0, 255),
            Generators::choose(0, 255),
            Generators::choose(0, 255)
        )
            ->withMaxSize(100)
            ->then(function (int $b, int $c, int $d): void {
                $ip = self::ipv4(127, $b, $c, $d);
                $this->assertTrue(
                    $this->isPrivateIp($ip),
                    "127.{$b}.{$c}.{$d} must be classified as private (127.0.0.0/8 loopback)"
                );
            });
    }

    /**
     * @test
     * Property 2e: Any address in 169.254.0.0/16 is classified as private (link-local).
     *
     * // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
     */
    public function testLinkLocalAddressesArePrivate(): void
    {
        // 169.254.0.0/16 — third octet 0-255, fourth 0-255
        $this->forAll(
            Generators::choose(0, 255),
            Generators::choose(0, 255)
        )
            ->withMaxSize(100)
            ->then(function (int $c, int $d): void {
                $ip = self::ipv4(169, 254, $c, $d);
                $this->assertTrue(
                    $this->isPrivateIp($ip),
                    "169.254.{$c}.{$d} must be classified as private (169.254.0.0/16 link-local)"
                );
            });
    }

    /**
     * @test
     * Property 2f: IPv6 loopback address ::1 is classified as private.
     *
     * // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
     */
    public function testIpv6LoopbackIsPrivate(): void
    {
        // ::1/128 is a single address
        $this->assertTrue(
            $this->isPrivateIp('::1'),
            '::1 must be classified as private (IPv6 loopback)'
        );
    }

    /**
     * @test
     * Property 2g: Any address in fc00::/7 (unique-local) is classified as private.
     *
     * fc00::/7 covers fc00:: through fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff.
     * The first octet pair (first byte) must be in 0xFC–0xFD.
     *
     * // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
     */
    public function testIpv6UniqueLocalAddressesArePrivate(): void
    {
        // fc00::/7 — first byte is 0xFC or 0xFD; generate representative addresses.
        // We pick from the two valid first-byte values (0xfc = 252, 0xfd = 253)
        // and generate random values for the remaining groups.
        $this->forAll(
            Generators::elements(['fc', 'fd']),    // first byte group prefix
            Generators::choose(0, 0xffff),         // second group
            Generators::choose(0, 0xffff),         // third group
            Generators::choose(0, 0xffff)          // fourth group (keep it short)
        )
            ->withMaxSize(100)
            ->then(function (string $prefix, int $g2, int $g3, int $g4): void {
                $ip = sprintf('%s00:%04x:%04x:%04x::', $prefix, $g2, $g3, $g4);
                $this->assertTrue(
                    $this->isPrivateIp($ip),
                    "{$ip} must be classified as private (fc00::/7 unique-local)"
                );
            });
    }

    /**
     * @test
     * Property 2h: Any address in fe80::/10 (link-local) is classified as private.
     *
     * fe80::/10 covers fe80:: through febf::.
     * The first 10 bits must be 1111111010 = 0xFE8x–0xFEBx.
     *
     * // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
     */
    public function testIpv6LinkLocalAddressesArePrivate(): void
    {
        // fe80::/10 — first 10 bits are 1111111010.
        // The first group (16 bits) must be fe80–febf.
        // The high byte is always 0xfe (254); the low byte is 0x80–0xbf.
        $this->forAll(
            Generators::choose(0x80, 0xbf),        // low byte of first group (fe80–febf)
            Generators::choose(0, 0xffff),         // second group
            Generators::choose(0, 0xffff),         // third group
            Generators::choose(0, 0xffff)          // fourth group
        )
            ->withMaxSize(100)
            ->then(function (int $lowByte, int $g2, int $g3, int $g4): void {
                $ip = sprintf('fe%02x:%04x:%04x:%04x::', $lowByte, $g2, $g3, $g4);
                $this->assertTrue(
                    $this->isPrivateIp($ip),
                    "{$ip} must be classified as private (fe80::/10 link-local)"
                );
            });
    }

    /**
     * @test
     * Property 2i: Public IPv4 addresses (e.g. 8.8.x.x, 1.1.x.x) are NOT flagged as private.
     *
     * We generate addresses outside all private CIDRs to verify no false positives.
     *
     * // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
     */
    public function testPublicIpv4AddressesAreNotPrivate(): void
    {
        // Representative public first octets that are outside all private CIDRs:
        //   1, 2, 4, 5, 6, 7, 8, 9, 11, 12, 13, 14, 15 ... 
        // (avoiding 0, 10, 100 (RFC 6598), 127, 169, 172, 192)
        $publicFirstOctets = [1, 2, 4, 5, 6, 7, 8, 9, 11, 12, 13, 14, 15,
                               17, 18, 19, 20, 23, 24, 25, 26, 40, 50, 51,
                               52, 54, 66, 74, 85, 93, 104, 108, 142, 151,
                               185, 199, 203, 208, 216, 217, 220, 221];

        $this->forAll(
            Generators::elements($publicFirstOctets), // safe first octet
            Generators::choose(0, 255),
            Generators::choose(0, 255),
            Generators::choose(0, 255)
        )
            ->withMaxSize(100)
            ->then(function (int $a, int $b, int $c, int $d): void {
                $ip = self::ipv4($a, $b, $c, $d);
                $this->assertFalse(
                    $this->isPrivateIp($ip),
                    "Public IPv4 address {$ip} must NOT be classified as private"
                );
            });
    }

    /**
     * @test
     * Property 2j: Well-known public IPv6 addresses are NOT flagged as private.
     *
     * // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
     */
    public function testPublicIpv6AddressesAreNotPrivate(): void
    {
        // Public IPv6 addresses from the 2001::/32 (documentation/Teredo),
        // 2400::/12, 2600::/12, 2a00::/12, 2606::/32 ranges.
        $publicIpv6Addresses = [
            '2001:4860:4860::8888',   // Google DNS
            '2001:4860:4860::8844',   // Google DNS
            '2606:4700:4700::1111',   // Cloudflare DNS
            '2606:4700:4700::1001',   // Cloudflare DNS
            '2001:db8::1',            // Documentation range (RFC 3849)
            '2001:db8:85a3::8a2e:370:7334',
            '2400:cb00::1',           // Cloudflare range
            '2600:1400::1',           // Akamai range
        ];

        $this->forAll(
            Generators::elements($publicIpv6Addresses)
        )
            ->withMaxSize(100)
            ->then(function (string $ip): void {
                $this->assertFalse(
                    $this->isPrivateIp($ip),
                    "Public IPv6 address {$ip} must NOT be classified as private"
                );
            });
    }

    /**
     * @test
     * Property 2k: Addresses just outside the 172.16.0.0/12 range
     * (172.0–172.15 and 172.32–172.255) are NOT classified as private.
     *
     * This boundary test confirms the /12 mask is applied correctly.
     *
     * // Feature: url-to-pdf-api, Property 2: SSRF protection blocks private IP ranges
     */
    public function testAddressesOutsideRfc1918ClassBRangeAreNotPrivate(): void
    {
        // 172.0–172.15 and 172.32–172.255 are NOT in 172.16.0.0/12
        $outsideSecondOctets = array_merge(
            range(0, 15),    // 172.0–172.15
            range(32, 255)   // 172.32–172.255
        );

        // Exclude the 172.32–172.63 range that might overlap 100.64.0.0/10 — no,
        // that's a different first octet.  All 172.x with x outside [16,31] are safe.

        $this->forAll(
            Generators::elements($outsideSecondOctets),
            Generators::choose(0, 255),
            Generators::choose(0, 255)
        )
            ->withMaxSize(100)
            ->then(function (int $b, int $c, int $d): void {
                $ip = self::ipv4(172, $b, $c, $d);
                $this->assertFalse(
                    $this->isPrivateIp($ip),
                    "172.{$b}.{$c}.{$d} must NOT be classified as private (outside 172.16.0.0/12)"
                );
            });
    }
}
