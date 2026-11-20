<?php

declare(strict_types=1);

namespace Constile\Gate;

/**
 * An http or https URL, normalized the way the WHATWG URL parser (JavaScript's
 * URL) does for the parts the gate uses: lowercase scheme and host, no
 * default port, dot segments removed, and unsafe characters percent-encoded.
 */
final class Url
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /** The network's development hosts, which it reaches over http: localhost, 127.0.0.1, *.localhost, and *.test. */
    private const DEV_HOST = '/^(localhost|127\.0\.0\.1|[a-z0-9-]+\.localhost|[a-z0-9.-]+\.test)(:\d{1,5})?$/i';

    public static function isDevHost(string $hostWithPort): bool
    {
        return preg_match(self::DEV_HOST, $hostWithPort) === 1;
    }

    /** Whether a request's host is the site's registered domain or its www twin, as the network matches them. */
    public static function hostMatchesDomain(string $host, string $domain): bool
    {
        $h = strtolower($host);
        $d = strtolower($domain);
        return $h === $d || $h === "www.{$d}" || "www.{$h}" === $d;
    }

    /** A registered domain as the network sends it (a host, with a port only for development), or null. */
    public static function domain(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $d = strtolower(trim($value));
        return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/', $d) === 1 ? $d : null;
    }

    /** A network URL must use https, so the site secret never crosses the internet in the clear; development hosts may use http. */
    public static function networkUrlAllowed(string $url): bool
    {
        $u = self::parse($url);
        return $u !== null && ($u->scheme === 'https' || self::isDevHost($u->host()));
    }

    private function __construct(
        public readonly string $scheme,
        public readonly string $hostname,
        public readonly ?int $port,
        public readonly string $path,
        public readonly ?string $query,
    ) {
    }

    public static function parse(string $url): ?self
    {
        $url = trim($url, "\x00..\x20");
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);
        if (!isset(self::DEFAULT_PORTS[$scheme])) {
            return null;
        }
        $host = strtolower($p['host']);
        $isIpv6 = str_starts_with($host, '[') && str_ends_with($host, ']');
        if ($host === '' || (!$isIpv6 && preg_match('/[\x00-\x20#%\/:<>?@\[\\\\\]^|]/', $host) === 1)) {
            return null;
        }
        $port = isset($p['port']) ? (int) $p['port'] : null;
        if ($port === self::DEFAULT_PORTS[$scheme]) {
            $port = null;
        }
        $path = self::removeDotSegments(self::encode($p['path'] ?? '/', '"<>`{}'));
        $query = isset($p['query']) ? self::encode($p['query'], '"<>\'') : null;
        return new self($scheme, $host, $port, $path, $query);
    }

    /** The host with its port when it isn't the default, like URL.host. */
    public function host(): string
    {
        return $this->hostname . ($this->port === null ? '' : ':' . $this->port);
    }

    /** Like URL.origin. */
    public function origin(): string
    {
        return $this->scheme . '://' . $this->host();
    }

    /** Like URL.toString(), without a fragment. */
    public function toString(): string
    {
        return $this->origin() . $this->path . ($this->query === null ? '' : '?' . $this->query);
    }

    /**
     * Whether this URL is for the same site as another: the same host, or its
     * www twin, and the same port. The scheme doesn't count, since a proxy in
     * front of the app may end TLS.
     */
    public function sameSite(self $other): bool
    {
        $bare = static fn (string $h): string => str_starts_with($h, 'www.') ? substr($h, 4) : $h;
        return $bare($this->hostname) === $bare($other->hostname) && $this->port === $other->port;
    }

    /** The first value of a query parameter, decoded like URLSearchParams.get; null when it's absent. */
    public function queryParam(string $name): ?string
    {
        if ($this->query === null || $this->query === '') {
            return null;
        }
        foreach (explode('&', $this->query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $k = urldecode($eq === false ? $pair : substr($pair, 0, $eq));
            if ($k === $name) {
                return Text::utf8(urldecode($eq === false ? '' : substr($pair, $eq + 1)));
            }
        }
        return null;
    }

    private static function encode(string $s, string $extra): string
    {
        return preg_replace_callback(
            '/[\x00-\x20\x7F-\xFF' . preg_quote($extra, '/') . ']/',
            static fn (array $m): string => sprintf('%%%02X', ord($m[0])),
            $s,
        ) ?? $s;
    }

    private static function removeDotSegments(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $segments = explode('/', substr($path, 1));
        $out = [];
        $last = count($segments) - 1;
        foreach ($segments as $i => $seg) {
            $lower = strtolower($seg);
            if (in_array($lower, ['..', '.%2e', '%2e.', '%2e%2e'], true)) {
                array_pop($out);
                if ($i === $last) {
                    $out[] = '';
                }
            } elseif ($lower === '.' || $lower === '%2e') {
                if ($i === $last) {
                    $out[] = '';
                }
            } else {
                $out[] = $seg;
            }
        }
        return '/' . implode('/', $out);
    }
}
