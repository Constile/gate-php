<?php

declare(strict_types=1);

namespace Constile\Gate;

use Constile\Gate\Http\HttpClient;

/**
 * The gate's origin for installs that can't call the rest of the app in
 * process: it fetches the page from the site over HTTP, with a
 * Constile-Gate-Bypass header carrying a per-install secret value. The install
 * passes a request with the right value straight to the app.
 *
 * Every fetch goes to the site's registered domain, from the network's
 * config: https://<domain> (http for development hosts) plus the request's
 * path and query. A request's Host header, the app's own idea of its URL
 * (WordPress's home_url() can follow the Host header), and a release URL's
 * host never choose where it connects. A connect URL changes only the
 * address it connects to; it still sends Host: <domain>.
 *
 * Two guards keep it from overloading a small server, across PHP processes:
 * - At most maxInFlight fetches run at once. When none is free, the fetch is
 *   refused (SelfFetchBusy) and the gate serves the page without an offer.
 * - While a fetch is in flight, isEcho() recognizes the same request arriving
 *   at the site, so a fetch whose bypass header a WAF or CDN strips passes
 *   through instead of being gated, and fetching, again.
 */
final class SelfFetch
{
    public const HEADER = 'Constile-Gate-Bypass';

    /** The install check's query parameter, and the response header that echoes it when the bypass arrived. */
    public const CHECK_PARAM = 'constile_gate_check';
    public const SEEN_HEADER = 'Constile-Gate-Bypass-Seen';

    /** Hop-by-hop headers, and ones the HTTP client sets itself. */
    private const DROP = ['connection', 'keep-alive', 'proxy-connection', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade', 'host', 'content-length', 'accept-encoding', 'expect'];

    /** Echo markers are spread over this many lock files. */
    private const ECHO_BUCKETS = 256;

    private ?Url $connect;

    private LockStore $locks;

    /**
     * @param string $bypass The install's bypass value.
     * @param string|null $domain The site's registered domain, when it's known up front. The gate passes the
     *     config's domain with each fetch instead.
     * @param string|null $connectUrl Where to connect instead, such as http://127.0.0.1, when the server can't reach
     *     its own public address. Only its scheme, host, and port count.
     * @param float $timeout Seconds for each fetch.
     * @param int $maxInFlight Fetches that may run at once across the server's PHP processes.
     * @param string|null $lockDir Where the cross-process locks live. Defaults to the system's temporary directory.
     * @param LockStore|null $locks The cross-process locks instead of files in $lockDir, such as the host's database.
     *     They must be this install's own: sites that share them share slots.
     */
    public function __construct(
        private HttpClient $client,
        private string $bypass,
        private ?string $domain = null,
        ?string $connectUrl = null,
        private float $timeout = 3.0,
        private int $maxInFlight = 2,
        ?string $lockDir = null,
        ?LockStore $locks = null,
    ) {
        if ($connectUrl !== null && trim($connectUrl) !== '') {
            $this->connect = Url::parse($connectUrl) ?? throw new \InvalidArgumentException('The connect URL must be an http or https URL');
        } else {
            $this->connect = null;
        }
        if ($locks !== null) {
            $this->locks = $locks;
        } else {
            $base = rtrim($lockDir ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'constile-gate', '/\\');
            $this->locks = new Locks($base . DIRECTORY_SEPARATOR . $this->lockNamespace());
        }
    }

    /** The lock folder's name for this install, so sites that share a server don't share slots. */
    public function lockNamespace(): string
    {
        return 'sf-' . substr(hash('sha256', 'locks|' . $this->bypass), 0, 16);
    }

    /** Fetches the page with the bypass header. The gate passes the config's domain as the second argument. */
    public function __invoke(GateRequest $request, ?string $domain = null): GateResponse
    {
        return $this->fetch($request, $domain, true);
    }

    /** @throws SelfFetchBusy when every slot is taken; \RuntimeException without a domain. */
    public function fetch(GateRequest $request, ?string $domain = null, bool $bypass = true): GateResponse
    {
        $domain = Url::domain($domain ?? $this->domain);
        if ($domain === null) {
            throw new \RuntimeException('The site\'s domain isn\'t known yet, so the gate fetches nothing');
        }
        $asked = Url::parse($request->url);
        $pathAndQuery = $asked === null ? '/' : $asked->path . ($asked->query === null ? '' : '?' . $asked->query);
        $scheme = Url::isDevHost($domain) ? 'http' : 'https';
        $headers = new Headers();
        foreach ($request->headers as [$name, $value]) {
            $lower = strtolower($name);
            if (in_array($lower, self::DROP, true) || $lower === strtolower(self::HEADER)) {
                continue;
            }
            $headers->add($name, $value);
        }
        if ($this->connect !== null) {
            $url = $this->connect->origin() . $pathAndQuery;
            $headers->set('Host', $domain);
            $headers->set('X-Forwarded-Proto', $scheme);
        } else {
            $url = "{$scheme}://{$domain}{$pathAndQuery}";
        }
        if ($bypass) {
            $headers->set(self::HEADER, $this->bypass);
        }

        $slot = $this->slot();
        if ($slot === null) {
            throw new SelfFetchBusy('Every self-fetch slot is taken');
        }
        // Only a fetch that carries the bypass can come back as an echo; a check sent without it must be gated normally.
        $marker = $bypass ? $this->locks->tryLock($this->echoName($request->method, $pathAndQuery, $request->headers->get('user-agent'))) : null;
        try {
            return $this->client->send(new GateRequest($request->method, $url, $headers, $request->body), $this->timeout);
        } finally {
            $marker?->release();
            $slot->release();
        }
    }

    /**
     * Whether a request arriving at the site matches a self-fetch now in
     * flight (the same method, path and query, and user agent): the gate's own
     * fetch, whose bypass header didn't arrive. Pass it to the app ungated.
     */
    public function isEcho(GateRequest $incoming): bool
    {
        $u = Url::parse($incoming->url);
        $pathAndQuery = $u === null ? '/' : $u->path . ($u->query === null ? '' : '?' . $u->query);
        return $this->locks->isHeld($this->echoName($incoming->method, $pathAndQuery, $incoming->headers->get('user-agent')));
    }

    /**
     * Checks that a self-fetch reaches the app with its bypass header: it
     * requests $path with an agent user agent and a one-time value in
     * ?constile_gate_check=, and the install echoes the value in the
     * Constile-Gate-Bypass-Seen header when the bypass arrived.
     *
     * @return array{ok: bool, status: int, html: bool, bypassArrived: bool, error: string|null}
     */
    public function checkBypass(string $domain, string $path = '/', string $agentToken = 'ChatGPT-User'): array
    {
        $nonce = bin2hex(random_bytes(16));
        $path = '/' . ltrim($path, '/');
        $request = new GateRequest('GET', 'https://' . $domain . $path . (str_contains($path, '?') ? '&' : '?') . self::CHECK_PARAM . '=' . $nonce, new Headers([
            'User-Agent' => "Mozilla/5.0 (compatible; {$agentToken}/1.0; +ConstileInstallCheck)",
            'Accept' => 'text/html,application/xhtml+xml',
        ]));
        try {
            $res = $this->fetch($request, $domain, true);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'html' => false, 'bypassArrived' => false, 'error' => $e->getMessage()];
        }
        $html = preg_match('/text\/html|application\/xhtml/i', (string) $res->headers->get('content-type')) === 1;
        $arrived = $res->headers->get(self::SEEN_HEADER) === $nonce;
        return ['ok' => $res->status === 200 && $html && $arrived, 'status' => $res->status, 'html' => $html, 'bypassArrived' => $arrived, 'error' => null];
    }

    /** The install check's value from ?constile_gate_check=, when it's well formed, to echo in Constile-Gate-Bypass-Seen. */
    public static function seenNonce(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{32}$/D', $value) === 1 ? $value : null;
    }

    /** Whether a request's Constile-Gate-Bypass value is this install's. */
    public static function isBypass(?string $value, string $bypass): bool
    {
        return $bypass !== '' && $value !== null && $value !== '' && hash_equals($bypass, $value);
    }

    /** A bypass value derived from the site secret, for installs that have nowhere to keep a random one. */
    public static function derive(string $secret): string
    {
        return hash_hmac('sha256', 'Constile-Gate-Bypass', $secret);
    }

    private function slot(): ?Lock
    {
        for ($i = 0; $i < max(1, $this->maxInFlight); $i++) {
            $lock = $this->locks->tryLock("slot-{$i}");
            if ($lock !== null) {
                return $lock;
            }
        }
        return null;
    }

    private function echoName(string $method, string $pathAndQuery, ?string $userAgent): string
    {
        $fingerprint = strtoupper($method) . ' ' . $pathAndQuery . ' ' . strtolower((string) $userAgent);
        return 'echo-' . (hexdec(substr(hash('sha256', $fingerprint), 0, 6)) % self::ECHO_BUCKETS);
    }
}
