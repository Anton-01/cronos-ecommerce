<?php

declare(strict_types=1);

namespace Cronos\Shared\Domain\Geo;

/**
 * One way to write an IP address, whatever the family. The same visitor can reach us as 187.190.10.20, as ::ffff:187.190.10.20 (an IPv6
 * socket carrying an IPv4 client) or as an IPv6 address; every lookup, trace and counter must treat the first two as one address.
 */
final class IpAddress
{
    public static function isValid(?string $ip): bool
    {
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /** Canonical text (lowercase compressed IPv6, mapped IPv4 unwrapped), or null when it is not an IP at all. */
    public static function normalize(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }
        $ip = trim($ip);
        $ip = trim($ip, '[]'); // "[2001:db8::1]" as seen in Host-style notation
        if (str_contains($ip, '%')) {
            $ip = substr($ip, 0, (int) strpos($ip, '%')); // fe80::1%eth0 → the zone is not part of the address
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return inet_ntop(substr($packed, 12)) ?: null; // ::ffff:a.b.c.d
        }

        return inet_ntop($packed) ?: null;
    }

    public static function family(?string $ip): ?int
    {
        $n = self::normalize($ip);

        return $n === null ? null : (str_contains($n, ':') ? 6 : 4);
    }

    /** Public, routable address: private ranges (10/8, 192.168/16, fc00::/7), loopback, link-local and reserved space are not. */
    public static function isPublic(?string $ip): bool
    {
        $n = self::normalize($ip);

        return $n !== null && filter_var($n, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * The key to count attempts by. A household or an attacker with IPv6 usually owns a whole /64, so counting single IPv6 addresses
     * would let them dodge every velocity rule by changing the last 64 bits; IPv4 is counted address by address.
     */
    public static function counterKey(?string $ip): ?string
    {
        $n = self::normalize($ip);
        if ($n === null || ! str_contains($n, ':')) {
            return $n;
        }
        $packed = (string) inet_pton($n);

        return (string) inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    /**
     * Expands "a.b.c.d/nn" or "2001:db8::/32" into its first and last address; null when it is not a valid CIDR.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function cidrRange(string $cidr): ?array
    {
        if (substr_count($cidr, '/') !== 1) {
            return null;
        }
        [$base, $bits] = explode('/', trim($cidr));
        $base = self::normalize($base);
        if ($base === null || ! ctype_digit($bits)) {
            return null;
        }
        $packed = (string) inet_pton($base);
        $total = strlen($packed) * 8;
        $bits = (int) $bits;
        if ($bits > $total) {
            return null;
        }

        $first = '';
        $last = '';
        for ($i = 0; $i < strlen($packed); $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $byte = ord($packed[$i]);
            $first .= chr($byte & $mask);
            $last .= chr(($byte & $mask) | (~$mask & 0xFF));
        }

        return [(string) inet_ntop($first), (string) inet_ntop($last)];
    }
}
