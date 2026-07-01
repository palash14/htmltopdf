<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\SsrfException;
use App\Exception\ValidationException;

/**
 * Stateless SSRF guard.
 *
 * Resolves the hostname of the supplied URL via DNS and rejects any URL
 * whose resolved IP addresses fall within private, loopback, or link-local
 * ranges (RFC 1918, RFC 4193, RFC 3927, etc.).
 */
class SsrfGuard
{
    /**
     * Check a URL for potential SSRF risk.
     *
     * @throws ValidationException (422) if the hostname cannot be resolved
     * @throws SsrfException       (422) if any resolved IP is private/disallowed
     */
    public function check(string $url): void
    {
        // 1. Extract hostname from URL.
        $hostname = parse_url($url, PHP_URL_HOST);

        if ($hostname === false || $hostname === null || $hostname === '') {
            throw new ValidationException(422, 'URL hostname cannot be resolved');
        }

        // Strip IPv6 brackets, e.g. "[::1]" → "::1"
        if (str_starts_with($hostname, '[') && str_ends_with($hostname, ']')) {
            $hostname = substr($hostname, 1, -1);
        }

        // 2. Resolve hostname to IP addresses.
        $ips = $this->resolveHostname($hostname);

        // 3. No IPs resolved → unresolvable host.
        if (empty($ips)) {
            throw new ValidationException(422, 'URL hostname cannot be resolved');
        }

        // 4. Check each resolved IP against private CIDRs.
        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                throw new SsrfException('URL resolves to a private or disallowed IP address');
            }
        }

        // 5. All IPs are public — safe to proceed.
    }

    /**
     * Resolve a hostname to a list of IP address strings using DNS.
     *
     * @return string[] IPv4 and IPv6 address strings
     */
    protected function resolveHostname(string $hostname): array
    {
        $records = dns_get_record($hostname, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if ($record['type'] === 'A' && isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif ($record['type'] === 'AAAA' && isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * Check whether an IP address falls within any private/disallowed CIDR.
     *
     * Covered ranges:
     *   IPv4: 127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16,
     *         169.254.0.0/16, 0.0.0.0/8, 100.64.0.0/10
     *   IPv6: ::1/128, fc00::/7, fe80::/10
     */
    private function isPrivateIp(string $ip): bool
    {
        // --- IPv4 check ---
        $ipLong = ip2long($ip);
        if ($ipLong !== false) {
            // List of [network, mask] pairs for IPv4 private CIDRs.
            $ipv4Cidrs = [
                [ip2long('127.0.0.0'),   0xFF000000], // 127.0.0.0/8    loopback
                [ip2long('10.0.0.0'),    0xFF000000], // 10.0.0.0/8     private
                [ip2long('172.16.0.0'),  0xFFF00000], // 172.16.0.0/12  private
                [ip2long('192.168.0.0'), 0xFFFF0000], // 192.168.0.0/16 private
                [ip2long('169.254.0.0'), 0xFFFF0000], // 169.254.0.0/16 link-local
                [ip2long('0.0.0.0'),     0xFF000000], // 0.0.0.0/8      this-network
                [ip2long('100.64.0.0'),  0xFFC00000], // 100.64.0.0/10  shared address (RFC 6598)
            ];

            foreach ($ipv4Cidrs as [$network, $mask]) {
                if (($ipLong & $mask) === ($network & $mask)) {
                    return true;
                }
            }

            return false;
        }

        // --- IPv6 check ---
        $packed = inet_pton($ip);
        if ($packed === false) {
            // Unrecognised format — treat as safe (will fail at render time anyway)
            return false;
        }

        // Helper: build a packed 16-byte mask from a prefix length.
        $buildMask = static function (int $prefixLen): string {
            $mask = '';
            for ($i = 0; $i < 16; $i++) {
                $bits = max(0, min(8, $prefixLen - $i * 8));
                $mask .= chr($bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF);
            }
            return $mask;
        };

        // List of [packed-network, prefix-length] for IPv6 private CIDRs.
        $ipv6Cidrs = [
            [inet_pton('::1'),     128], // ::1/128    loopback
            [inet_pton('fc00::'),    7], // fc00::/7   unique-local
            [inet_pton('fe80::'),   10], // fe80::/10  link-local
        ];

        foreach ($ipv6Cidrs as [$network, $prefixLen]) {
            $mask = $buildMask($prefixLen);
            if (($packed & $mask) === ($network & $mask)) {
                return true;
            }
        }

        return false;
    }
}
