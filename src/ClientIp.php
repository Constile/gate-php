<?php

declare(strict_types=1);

namespace Constile\Gate;

/**
 * The visitor's address, from the connection's own address (REMOTE_ADDR).
 * X-Forwarded-For counts only when the connection comes from a proxy the
 * publisher trusts, and then only the entries those proxies added: the gate
 * walks the list from the right and takes the first address that isn't a
 * trusted proxy. A visitor can forge entries on the left, never on the right.
 */
final class ClientIp
{
    /**
     * @param list<string> $trustedProxies Addresses or CIDR ranges, such as 10.0.0.0/8 or 2001:db8::/32.
     */
    public static function resolve(string $remoteAddr, ?string $forwardedFor, array $trustedProxies): ?string
    {
        $remote = self::valid(trim($remoteAddr));
        if ($remote === null) {
            return null;
        }
        if ($trustedProxies === [] || !self::trusted($remote, $trustedProxies) || $forwardedFor === null || trim($forwardedFor) === '') {
            return $remote;
        }
        $hops = array_map('trim', explode(',', $forwardedFor));
        $client = $remote;
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $ip = self::valid(self::stripPort($hops[$i]));
            if ($ip === null) {
                // A malformed entry wasn't written by a trusted proxy; stop at the last good one.
                return $client;
            }
            $client = $ip;
            if (!self::trusted($ip, $trustedProxies)) {
                return $ip;
            }
        }
        return $client;
    }

    /** @param list<string> $ranges */
    public static function trusted(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    public static function inRange(string $ip, string $range): bool
    {
        [$net, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton((string) $net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $max = strlen($a) * 8;
        $bits = $bits === null ? $max : (ctype_digit($bits) ? (int) $bits : -1);
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $whole = intdiv($bits, 8);
        if (strncmp($a, $b, $whole) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$whole]) & $mask) === (ord($b[$whole]) & $mask);
    }

    /** The first three parts of an address, as the Cloudflare gate sends: 203.0.113 or 2001:db8:1. */
    public static function prefix(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (str_contains($ip, ':')) {
            return implode(':', array_slice(explode(':', $ip), 0, 3));
        }
        $parts = explode('.', $ip);
        return count($parts) === 4 ? implode('.', array_slice($parts, 0, 3)) : null;
    }

    /**
     * The gate's signals for a visitor address. Off Cloudflare the gate doesn't
     * know the visitor's network number, organization, or country.
     *
     * @return array{asn: null, as_org: null, country: null, ip_prefix: string|null, ip: string|null}
     */
    public static function signals(?string $ip): array
    {
        return ['asn' => null, 'as_org' => null, 'country' => null, 'ip_prefix' => self::prefix($ip), 'ip' => $ip];
    }

    /**
     * Parses a publisher's list of trusted proxies (separated by commas, spaces, or lines). Entries that aren't an
     * address or a CIDR range are dropped.
     *
     * @return list<string>
     */
    public static function parseList(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $bin = @inet_pton($net);
            if ($bin === false) {
                continue;
            }
            if ($bits !== null && (!ctype_digit($bits) || (int) $bits > strlen($bin) * 8)) {
                continue;
            }
            $out[] = $entry;
        }
        return array_values(array_unique($out));
    }

    private static function valid(string $ip): ?string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? null : $ip;
    }

    /** X-Forwarded-For entries sometimes carry a port: 203.0.113.7:4711 or [2001:db8::1]:4711. */
    private static function stripPort(string $hop): string
    {
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $hop, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $hop, $m) === 1) {
            return $m[1];
        }
        return $hop;
    }
}
