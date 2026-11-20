<?php

declare(strict_types=1);

namespace Constile\Gate;

use Constile\Gate\Cache\Caches;
use Constile\Gate\Cache\ConfigCache;
use Constile\Gate\Http\Clients;
use Constile\Gate\Http\HttpClient;

/**
 * The publisher's gate, ported from handleGate in packages/gate/src/core.ts.
 * It does three things:
 *
 * 1. For an agent fetching an eligible page for a person, it serves the
 *    Constile offer page with HTTP 200 instead of the article.
 * 2. It forwards the offer's callbacks (/sponsored/...) to the network and,
 *    when the network grants access, returns the article from the origin.
 * 3. Everything else (people, search engines, crawlers, subscribers, other
 *    paths) goes straight to the origin, untouched.
 *
 * It fails open: if the network can't be reached, the page is served as if
 * the gate weren't there. Callbacks can't fail open, because they release
 * content only against a recorded commitment.
 */
final class Gate
{
    public const VERSION = 'constile-gate 0.6.0';

    /** How long a config may serve past its TTL while the network can't give a fresh one. */
    public const CONFIG_STALE_S = 86400;

    /** The longest the config call may take: people may be waiting on it. */
    public const CONFIG_TIMEOUT_S = 3.0;

    public const SPONSORED_HEADERS = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Vary' => 'User-Agent'];

    private const CALLBACK = '/^\/sponsored\/(deferred|prepared|delivered)\/([A-Za-z0-9_-]+)$/';
    private const CONTENT = '/^\/sponsored\/content\/([A-Za-z0-9_-]+)$/';
    private const MACHINE_OFFER = '/^\/sponsored\/offer\/([A-Za-z0-9_-]+)$/';
    private const UNAVAILABLE = 'Sponsored access is unavailable right now';

    /** Configs this process has already read, by network and site. @var array<string, array{config: object, until: int}> */
    private static array $memo = [];

    /** @var \Closure(GateRequest): GateResponse */
    private \Closure $origin;

    /** @var \Closure(GateRequest): GateResponse */
    private \Closure $forward;

    /** @var \Closure(): int */
    private \Closure $now;

    private ?\Closure $defer;

    private ?\Closure $onConfig;

    private ?\Closure $log;

    private HttpClient $network;

    private ConfigCache $cache;

    private Locks $locks;

    /** Config refreshes already scheduled in this process, by cache key. @var array<string, true> */
    private static array $refreshing = [];

    /**
     * @param string $siteId The site id from the publisher dashboard.
     * @param string $secret The site secret.
     * @param string $networkUrl The network's origin, for example https://network.example.com
     * @param callable(GateRequest): GateResponse $origin Fetches from the publisher's site. The gate passes the
     *     incoming request object itself when it wants the page as the visitor would get it, and a new request
     *     (with only Accept and User-Agent headers) to release an article.
     * @param HttpClient|null $network Sends the network calls. Defaults to curl, or PHP streams without curl.
     * @param ConfigCache|null $cache Keeps the config between requests. Defaults to APCu, or files.
     * @param string $platform Where the gate runs, such as "wordpress" or "laravel".
     * @param callable(): int|null $now Seconds since the epoch.
     * @param float $timeout Seconds to wait for each network call before failing open.
     * @param callable(\Closure): void|null $defer Runs work after the response is sent, like a Worker's waitUntil.
     *     Without it, that work runs before the gate returns.
     * @param callable(GateRequest): GateResponse|null $forward Hands the incoming request to the app untouched, when
     *     the gate doesn't need to see the page. Defaults to $origin.
     * @param callable(object): void|null $onConfig Called with each config the gate fetches from the network.
     * @param int $configRetryS After a failed config fetch, how long to pass everything through before asking again,
     *     so an outage costs one timeout per period instead of one per request. 0 asks every time.
     * @param callable(string, \Throwable|null): void|null $log Receives warnings.
     * @param string|null $lockDir Where the cross-process config fetch lock lives. Defaults to the system's
     *     temporary directory.
     * @param bool $requireDomain For installs that fetch their own pages: handle only requests for the site's
     *     registered domain from the config (or its www twin), pass every other request to the app untouched, and
     *     give the origin that domain to fetch from. Without a domain in the config, pages pass through.
     */
    public function __construct(
        private string $siteId,
        private string $secret,
        private string $networkUrl,
        callable $origin,
        ?HttpClient $network = null,
        ?ConfigCache $cache = null,
        private string $platform = 'unknown',
        ?callable $now = null,
        private float $timeout = 5.0,
        ?callable $defer = null,
        ?callable $forward = null,
        ?callable $onConfig = null,
        private int $configRetryS = 30,
        ?callable $log = null,
        ?string $lockDir = null,
        private bool $requireDomain = false,
    ) {
        $this->origin = \Closure::fromCallable($origin);
        $this->forward = $forward === null ? $this->origin : \Closure::fromCallable($forward);
        $this->now = $now === null ? static fn (): int => time() : \Closure::fromCallable($now);
        $this->defer = $defer === null ? null : \Closure::fromCallable($defer);
        $this->onConfig = $onConfig === null ? null : \Closure::fromCallable($onConfig);
        $this->log = $log === null ? null : \Closure::fromCallable($log);
        $this->network = $network ?? Clients::auto();
        $this->cache = $cache ?? Caches::auto();
        $this->locks = new Locks(rtrim($lockDir ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'constile-gate', '/\\'));
    }

    /** CONSTILE_DISABLED's on values: true, or 1, true, yes, or on in any case. */
    public static function isOn(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        return is_string($value) && preg_match('/\A(1|true|yes|on)\z/i', trim($value)) === 1;
    }

    /**
     * Whether CONSTILE_DISABLED turns the gate off, from the server's variables, the environment, or getenv. Read on every
     * call, so a change takes effect on the next request wherever the server's environment can change without a restart.
     */
    public static function disabledByEnv(): bool
    {
        foreach ([$_SERVER['CONSTILE_DISABLED'] ?? null, $_ENV['CONSTILE_DISABLED'] ?? null, getenv('CONSTILE_DISABLED')] as $v) {
            if ($v !== null && $v !== false && $v !== '') {
                return self::isOn($v);
            }
        }
        return false;
    }

    /** For tests: forget the configs this process has read. */
    public static function clearMemo(): void
    {
        self::$memo = [];
        self::$refreshing = [];
    }

    /**
     * @param array{asn?: int|null, as_org?: string|null, country?: string|null, ip_prefix?: string|null, ip?: string|null}|null $signals
     *     What the host knows about the visitor's network. ip is the visitor's address; it's sent only for requests that
     *     claim a registered agent.
     */
    public function handle(GateRequest $request, ?array $signals = null): GateResponse
    {
        // Off: the site as if the gate weren't there. Checked here too, so a Gate used on its own (plain PHP) obeys it.
        if (self::disabledByEnv()) {
            return $this->pass($request);
        }
        $url = Url::parse($request->url);
        if ($url === null) {
            return $this->pass($request);
        }
        $method = strtoupper($request->method);
        $domain = null;
        if ($this->requireDomain) {
            $domain = $this->domain();
            $gateRoute = $url->path === '/.well-known/constile' || str_starts_with($url->path, '/sponsored/');
            if ($domain === null ? !$gateRoute : !Url::hostMatchesDomain($url->host(), $domain)) {
                // Another host (a forged Host header), or no domain to fetch from: not the gate's business.
                return $this->pass($request);
            }
        }

        if ($url->path === '/.well-known/constile') {
            return $this->sellerFile($url);
        }

        if (preg_match(self::CALLBACK, $url->path, $m) === 1) {
            if ($method !== 'GET' && $method !== 'POST') {
                return self::json(['error' => 'Method Not Allowed'], 405);
            }
            try {
                $r = $this->call('/events', 'POST', ['token' => $m[2], 'callback' => $m[1], 'method' => $method]);
                if (!is_object($r)) {
                    throw new \UnexpectedValueException('The network answered without an event');
                }
            } catch (\Throwable $e) {
                return self::errorResponse($e);
            }
            if (!empty($r->release)) {
                return $this->releaseArticle((string) ($r->url ?? ''), $domain, $r);
            }
            $rest = clone $r;
            unset($rest->url, $rest->release, $rest->release_url, $rest->release_headers);
            $rest->content_released = false;
            return self::json($rest);
        }

        if (preg_match(self::CONTENT, $url->path, $m) === 1) {
            if ($method !== 'GET' && $method !== 'HEAD') {
                return self::json(['error' => 'Method Not Allowed'], 405);
            }
            try {
                $g = $this->call("/grants/{$m[1]}");
                if (!is_object($g)) {
                    throw new \UnexpectedValueException('The network answered without a grant');
                }
            } catch (\Throwable $e) {
                return self::errorResponse($e);
            }
            if (empty($g->release)) {
                $reason = $g->reason ?? null;
                return self::json(['error' => $reason ?? 'No access'], $reason === 'Commitment required' ? 403 : 410);
            }
            return $this->releaseArticle((string) ($g->url ?? ''), $domain, $g);
        }

        if (preg_match(self::MACHINE_OFFER, $url->path, $m) === 1) {
            try {
                return self::json($this->call("/offers/{$m[1]}"));
            } catch (\Throwable $e) {
                return self::errorResponse($e);
            }
        }

        // Everything below is the normal site. Only GETs can become offers.
        if ($method !== 'GET') {
            return $this->pass($request);
        }

        try {
            $config = $this->config();
        } catch (\Throwable $e) {
            $this->warn('no config, passing through', $e);
            return $this->pass($request);
        }
        $status = $config->status ?? null;
        $demandOnly = $status === 'pending' && ($config->count_demand ?? null) === true;
        if (($status !== 'active' && !$demandOnly) || !self::pathEligible($config, $url->path)) {
            return $this->pass($request);
        }
        $cookies = self::cookieNames($request->headers);
        foreach (self::listOf($config->bypass_cookies ?? null) as $c) {
            if (is_string($c) && isset($cookies[$c])) {
                return $this->pass($request);
            }
        }

        $cls = Classifier::classify($request->headers, self::listOf($config->agents ?? null));
        if ($cls['kind'] === 'agent') {
            if (($cls['agent']->tier ?? null) === 'blocked') {
                return $this->pass($request);
            }
            $agentId = (string) ($cls['agent']->id ?? '');
        } elseif ($cls['kind'] === 'unknown_client' && ($config->unknown_clients ?? null) === 'offer') {
            $agentId = null;
        } else {
            return $this->pass($request);
        }

        if ($demandOnly) {
            // The site is in review: serve the page as usual, and tell the network an agent asked for it.
            $body = $this->demandRequest($request, $url, $agentId, $signals);
            $report = function () use ($body): void {
                try {
                    $this->call('/demand', 'POST', $body);
                } catch (\Throwable $e) {
                    $this->warn('demand report failed', $e);
                }
            };
            if ($this->defer !== null) {
                ($this->defer)($report);
                return $this->pass($request);
            }
            $res = $this->pass($request);
            $report();
            return $res;
        }

        // Fetch the page first: no offer for missing pages or non-HTML, and the
        // network needs the title and keywords to pick an ad.
        try {
            $originRes = ($this->origin)($request, $domain);
        } catch (SelfFetchBusy) {
            // Too many self-fetches in flight: the agent gets the page, without an offer.
            return $this->pass($request);
        }
        $type = $originRes->headers->get('content-type') ?? '';
        if ($originRes->status !== 200 || preg_match('/text\/html|application\/xhtml/i', $type) !== 1) {
            return $originRes;
        }
        // The page's own bytes stay as they are for passing it through; a copy, decoded in its charset, gives the meta.
        $meta = PageMeta::extract(Charset::decode($originRes->body(), $type), $url->path);

        $offerReq = [
            'url' => $url->toString(),
            'title' => $meta['title'],
            'description' => $meta['description'],
            'keywords' => $meta['keywords'],
            'section' => $meta['section'],
            'agent_id' => $agentId,
            'user_agent' => $request->headers->get('user-agent') ?? '',
            'signals' => $this->signals($request, $agentId, $signals),
        ];
        // Web Bot Auth: forward the signature unchanged so the network verifies it, not the gate.
        $signature = self::signature($request, $url);
        if ($signature !== null) {
            $offerReq['signature'] = $signature;
        }
        try {
            $r = $this->call('/offers', 'POST', $offerReq);
        } catch (\Throwable $e) {
            $this->warn('offer failed, passing through', $e);
            return $originRes;
        }
        $offer = is_object($r) ? ($r->offer ?? null) : null;
        if (!is_object($offer)) {
            return $originRes;
        }
        $offerUrl = (string) ($offer->offer_url ?? '');
        $headers = new Headers([
            'Content-Type' => (string) ($offer->content_type ?? ''),
            'Constile-Offer' => $offerUrl,
            'Link' => "<{$offerUrl}>; rel=\"sponsored-access\"",
        ] + self::SPONSORED_HEADERS);
        return new GateResponse(200, $headers, (string) ($offer->body ?? ''));
    }

    /**
     * The site's config, ported from getConfig in packages/gate/src/core.ts:
     * - fresh from this process or the shared cache;
     * - past its TTL, the last config at once (for up to a day) while a new
     *   one is fetched after the response, so people don't wait on the network;
     * - after a failed fetch, the last config, or none, for configRetryS from
     *   when the fetch failed;
     * - one fetch at a time across processes: a process with no config waits
     *   for the one fetching (up to the config timeout) and uses its result.
     *
     * @throws \Throwable when there's no config to use.
     */
    public function config(): object
    {
        $key = $this->networkUrl . '|' . $this->siteId;
        $cacheKey = 'constile_gate_config_' . hash('sha256', $key);
        $now = ($this->now)();
        $entry = $this->entry($key, $cacheKey);
        if ($entry['config'] !== null && $entry['until'] > $now) {
            return $entry['config'];
        }
        $stale = $entry['config'] !== null && $entry['until'] + self::CONFIG_STALE_S > $now ? $entry['config'] : null;
        if ($entry['failed_until'] > $now) {
            return $stale ?? throw new \RuntimeException('The network failed recently; passing through until it\'s asked again');
        }
        if ($stale !== null) {
            $this->scheduleRefresh($key, $cacheKey);
            return $stale;
        }
        $lock = $this->locks->lock('config-' . substr(hash('sha256', $key), 0, 24), self::CONFIG_TIMEOUT_S);
        if ($lock === null) {
            throw new \RuntimeException('Another process is fetching the config and hasn\'t finished');
        }
        try {
            // Another process may have fetched it while this one waited.
            $entry = $this->entry($key, $cacheKey, true);
            if ($entry['config'] !== null && $entry['until'] > ($this->now)()) {
                return $entry['config'];
            }
            if ($entry['failed_until'] > ($this->now)()) {
                throw new \RuntimeException('The network failed recently; passing through until it\'s asked again');
            }
            return $this->fetchConfig($key, $cacheKey, $entry);
        } finally {
            $lock->release();
        }
    }

    /** The site's registered domain from the config, or null when there's no config or it has none. */
    public function domain(): ?string
    {
        try {
            return Url::domain($this->config()->domain ?? null);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{config: object|null, until: int, failed_until: int} $entry The entry as it is now.
     * @throws \Throwable when the network gives no config.
     */
    private function fetchConfig(string $key, string $cacheKey, array $entry): object
    {
        try {
            $config = $this->call('/config', 'GET', null, min($this->timeout, self::CONFIG_TIMEOUT_S));
            if (!is_object($config)) {
                throw new \UnexpectedValueException('The network answered without a config');
            }
        } catch (\Throwable $e) {
            // The wait starts when the fetch fails, not when it began.
            $failedUntil = ($this->now)() + $this->configRetryS;
            if ($this->configRetryS > 0) {
                $this->store($key, $cacheKey, ['config' => $entry['config'], 'until' => $entry['until'], 'failed_until' => $failedUntil]);
            }
            throw $e;
        }
        $now = ($this->now)();
        $ttl = isset($config->ttl_s) && is_numeric($config->ttl_s) ? (float) $config->ttl_s : 10.0;
        $until = (int) ceil($now + max(10, min($ttl, 3600)));
        $this->store($key, $cacheKey, ['config' => $config, 'until' => $until, 'failed_until' => 0]);
        if ($this->onConfig !== null) {
            ($this->onConfig)($config);
        }
        return $config;
    }

    /** Fetches a new config after the response, once per process; leaves it to another process that's fetching. */
    private function scheduleRefresh(string $key, string $cacheKey): void
    {
        if (isset(self::$refreshing[$cacheKey])) {
            return;
        }
        self::$refreshing[$cacheKey] = true;
        $refresh = function () use ($key, $cacheKey): void {
            try {
                $lock = $this->locks->tryLock('config-' . substr(hash('sha256', $key), 0, 24));
                if ($lock === null) {
                    return; // Another process is fetching it.
                }
                try {
                    $entry = $this->entry($key, $cacheKey, true);
                    if (($entry['config'] === null || $entry['until'] <= ($this->now)()) && $entry['failed_until'] <= ($this->now)()) {
                        $this->fetchConfig($key, $cacheKey, $entry);
                    }
                } finally {
                    $lock->release();
                }
            } catch (\Throwable $e) {
                $this->warn('config refresh failed', $e);
            } finally {
                unset(self::$refreshing[$cacheKey]);
            }
        };
        if ($this->defer !== null) {
            ($this->defer)($refresh);
        } else {
            register_shutdown_function($refresh);
        }
    }

    /**
     * The config entry from this process or, when this process has none (or $fromCache), the shared cache.
     *
     * @return array{config: object|null, until: int, failed_until: int}
     */
    private function entry(string $key, string $cacheKey, bool $fromCache = false): array
    {
        $memo = self::$memo[$key] ?? null;
        if ($memo !== null && !$fromCache) {
            return $memo;
        }
        $stored = $this->cache->get($cacheKey);
        $config = is_array($stored) && is_string($stored['json'] ?? null) ? json_decode($stored['json'], false) : null;
        $entry = [
            'config' => is_object($config) ? $config : ($memo['config'] ?? null),
            'until' => is_object($config) && is_int($stored['until'] ?? null) ? $stored['until'] : (int) ($memo['until'] ?? 0),
            'failed_until' => is_array($stored) && is_int($stored['failed_until'] ?? null) ? $stored['failed_until'] : 0,
        ];
        self::$memo[$key] = $entry;
        return $entry;
    }

    /** @param array{config: object|null, until: int, failed_until: int} $entry */
    private function store(string $key, string $cacheKey, array $entry): void
    {
        self::$memo[$key] = $entry;
        $now = ($this->now)();
        $keep = max($entry['failed_until'] - $now, $entry['config'] !== null ? $entry['until'] + self::CONFIG_STALE_S - $now : 0, 1);
        $this->cache->set($cacheKey, ['json' => $entry['config'] !== null ? Json::encode($entry['config']) : null, 'until' => $entry['until'], 'failed_until' => $entry['failed_until']], $keep);
    }

    /**
     * Asks the network to run its install check now: it requests the site's
     * /.well-known/constile with a fresh nonce, and this site's gate confirms
     * the nonce with the site's credentials (POST /v1/gate/check).
     *
     * @return array{ok: bool, status: int, reason: string|null} status is the network's HTTP status (429 when
     *     checked too often), or 0 when it couldn't be reached.
     */
    public function requestInstallCheck(float $timeout = 30.0): array
    {
        try {
            $r = $this->call('/check', 'POST', new \stdClass(), $timeout);
        } catch (NetworkError $e) {
            return ['ok' => false, 'status' => $e->status, 'reason' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'reason' => "Couldn't reach the network: " . $e->getMessage()];
        }
        $ok = is_object($r) && ($r->ok ?? null) === true;
        $reason = is_object($r) && is_string($r->reason ?? null) ? $r->reason : null;
        return ['ok' => $ok, 'status' => 200, 'reason' => $ok ? null : ($reason ?? 'The network answered without a result')];
    }

    /** Sends the network one call and returns the decoded JSON. */
    public function call(string $path, string $method = 'GET', mixed $body = null, ?float $timeout = null): mixed
    {
        $headers = new Headers([
            'Constile-Site' => $this->siteId,
            'Authorization' => "Bearer {$this->secret}",
            'Constile-Gate-Version' => self::VERSION,
            'Constile-Gate-Platform' => $this->platform,
        ]);
        if ($body !== null) {
            $headers->set('Content-Type', 'application/json');
        }
        $req = new GateRequest($method, self::networkBase($this->networkUrl) . '/v1/gate' . $path, $headers, $body === null ? null : Json::encode($body));
        $res = $this->network->send($req, $timeout ?? $this->timeout);
        if ($res->status < 200 || $res->status > 299) {
            $detail = json_decode($res->body(), false);
            $error = is_object($detail) ? ($detail->error ?? null) : null;
            throw new NetworkError($res->status, is_scalar($error) ? (string) $error : "network returned {$res->status}");
        }
        return Json::decode($res->body());
    }

    private function sellerFile(Url $url): GateResponse
    {
        $nonce = $url->queryParam('nonce');
        $confirmed = null;
        if ($nonce !== null && $nonce !== '') {
            try {
                $this->call('/verify', 'POST', ['nonce' => $nonce]);
                $confirmed = true;
            } catch (\Throwable) {
                $confirmed = false;
            }
        }
        // The site's seller file, as ads.txt is for AdSense: which network sells this site, and as which seller in its /sellers.json.
        try {
            $sellerId = $this->config()->seller_id ?? null;
        } catch (\Throwable) {
            $sellerId = null;
        }
        $network = self::networkBase($this->networkUrl);
        $file = [
            'site_id' => $this->siteId,
            'gate_version' => self::VERSION,
            'platform' => $this->platform,
            'confirmed' => $confirmed,
            'network' => $network,
            'seller_id' => $sellerId,
        ];
        // The sellers list only when there's a seller in it: the network publishes it only when it's switched on.
        if ($sellerId !== null) {
            $file['sellers_json'] = "{$network}/sellers.json";
        }
        return self::json($file, $confirmed === false ? 502 : 200);
    }

    /** X- names the network can't add: they steer routing, the method, middleware, or who the origin thinks the caller is. */
    private const DENIED_X_PREFIXES = ['x-forwarded', 'x-original', 'x-rewrite', 'x-middleware', 'x-real', 'x-client', 'x-cluster-client', 'x-originating', 'x-remote', 'x-host', 'x-http-method', 'x-method'];
    private const DENIED_X_SUFFIXES = ['-override', '-ip', '-user', '-host', '-url', '-uri', '-path', '-proto', '-scheme', '-port', '-for'];

    /** Header names the network may add to a release: its paywall key, credentials for the page, and custom X- headers. Nothing else. */
    private static function allowedReleaseHeader(string $name): bool
    {
        // An HTTP token. Servers fold `_` and `-` into the same variable, so names with `_` are refused outright.
        if (preg_match("/^[!#$%&'*+\\-.^`|~0-9A-Za-z]+\$/D", $name) !== 1) {
            return false;
        }
        $n = strtolower($name);
        if (in_array($n, ['cookie', 'authorization', 'constile-paywall-key'], true)) {
            return true;
        }
        if (!str_starts_with($n, 'x-')) {
            return false;
        }
        foreach (self::DENIED_X_PREFIXES as $p) {
            if (str_starts_with($n, $p)) {
                return false;
            }
        }
        foreach (self::DENIED_X_SUFFIXES as $x) {
            if (str_ends_with($n, $x)) {
                return false;
            }
        }
        return true;
    }

    /** The URL to fetch: release_url only on the page's own scheme, host, and port, with no username or password; else the page. */
    private static function releaseTarget(string $url, mixed $releaseUrl): string
    {
        $page = Url::parse($url);
        $fallback = $page === null ? $url : $page->toString();
        if (!is_string($releaseUrl) || $page === null) {
            return $fallback;
        }
        // parse_url and the WHATWG parser disagree on backslashes, whitespace, and control characters, so refuse them first.
        if (preg_match('/[\\\\\s\x00-\x1f\x7f]/', $releaseUrl) === 1) {
            return $fallback;
        }
        $parts = parse_url($releaseUrl);
        if ($parts === false || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return $fallback;
        }
        $wanted = Url::parse($releaseUrl);
        // `host()` includes a non-default port, so another port is another host.
        if ($wanted === null || $wanted->scheme !== $page->scheme || $wanted->host() !== $page->host()) {
            return $fallback;
        }
        // Rebuilt from the validated components, never the raw string.
        return $wanted->toString();
    }

    /**
     * Fetches the article for an agent that has access. Never forwards the agent's headers to the origin.
     * For a paywalled page the network's answer adds release instructions: a `release_url` (used only on the page's own
     * scheme, host, and port) and `release_headers` (only the allowed names; the rest are dropped without a trace, and
     * no name or value is logged).
     */
    private function releaseArticle(string $url, ?string $domain, ?object $instructions = null): GateResponse
    {
        if ($this->requireDomain && $domain === null) {
            return self::json(['error' => self::UNAVAILABLE], 503);
        }
        $sent = [
            'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.5',
            'User-Agent' => self::VERSION . ' (release)',
        ];
        $extra = $instructions->release_headers ?? null;
        if (is_object($extra)) {
            foreach (get_object_vars($extra) as $name => $value) {
                // ASCII only, and no NUL, CR, or LF: a value the origin could read as another header is dropped.
                if (is_string($value) && preg_match('/[\x00\r\n\x80-\xff]/', $value) !== 1 && self::allowedReleaseHeader((string) $name)) {
                    $sent[strtolower((string) $name)] = $value;
                }
            }
        }
        $target = self::releaseTarget($url, $instructions->release_url ?? null);
        try {
            $res = ($this->origin)(new GateRequest('GET', $target, new Headers($sent)), $domain);
        } catch (SelfFetchBusy) {
            $busy = self::json(['error' => 'Sponsored access is busy; try again in a moment'], 503);
            $busy->headers->set('Retry-After', '2');
            return $busy;
        }
        $headers = new Headers($res->headers->all());
        foreach (self::SPONSORED_HEADERS as $k => $v) {
            $headers->set($k, $v);
        }
        $headers->remove('Set-Cookie');
        return new GateResponse($res->status, $headers, static fn (): string => $res->body());
    }

    private function pass(GateRequest $request): GateResponse
    {
        return ($this->forward)($request);
    }

    /** @param array<string, mixed>|null $signals */
    private function signals(GateRequest $request, ?string $agentId, ?array $signals): array
    {
        return [
            'asn' => $signals['asn'] ?? null,
            'as_org' => $signals['as_org'] ?? null,
            'country' => $signals['country'] ?? null,
            'ip_prefix' => $signals['ip_prefix'] ?? null,
            'signature_agent' => $request->headers->get('signature-agent'),
            // Only a registered agent's address goes to the network, to check against the operator's list.
            'ip' => $agentId !== null && $agentId !== '' ? ($signals['ip'] ?? null) : null,
        ];
    }

    /** @param array<string, mixed>|null $signals */
    private function demandRequest(GateRequest $request, Url $url, ?string $agentId, ?array $signals): array
    {
        $body = [
            'url' => $url->toString(),
            'agent_id' => $agentId,
            'user_agent' => $request->headers->get('user-agent') ?? '',
            'signals' => $this->signals($request, $agentId, $signals),
        ];
        $signature = self::signature($request, $url);
        if ($signature !== null) {
            $body['signature'] = $signature;
        }
        return $body;
    }

    private static function signature(GateRequest $request, Url $url): ?array
    {
        $sig = $request->headers->get('signature');
        $input = $request->headers->get('signature-input');
        if ($sig === null || $sig === '' || $input === null || $input === '') {
            return null;
        }
        return ['signature' => $sig, 'signature_input' => $input, 'signature_agent' => $request->headers->get('signature-agent'), 'authority' => $url->host()];
    }

    private static function pathEligible(object $config, string $path): bool
    {
        foreach (self::listOf($config->eligible_paths ?? null) as $p) {
            if (!is_string($p)) {
                continue;
            }
            $bare = str_ends_with($p, '/') ? substr($p, 0, -1) : $p;
            if ($p === '/' || $path === $bare || str_starts_with($path, str_ends_with($p, '/') ? $p : "{$p}/")) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, true> */
    private static function cookieNames(Headers $headers): array
    {
        $names = [];
        foreach (explode(';', $headers->get('cookie') ?? '') as $part) {
            $name = Text::trim(explode('=', $part, 2)[0]);
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        return $names;
    }

    /** @return list<mixed> */
    private static function listOf(mixed $v): array
    {
        return is_array($v) ? array_values($v) : [];
    }

    private static function networkBase(string $networkUrl): string
    {
        return str_ends_with($networkUrl, '/') ? substr($networkUrl, 0, -1) : $networkUrl;
    }

    private static function errorResponse(\Throwable $e): GateResponse
    {
        return $e instanceof NetworkError ? self::json(['error' => $e->getMessage()], $e->status) : self::json(['error' => self::UNAVAILABLE], 503);
    }

    public static function json(mixed $body, int $status = 200): GateResponse
    {
        return new GateResponse($status, new Headers(['Content-Type' => 'application/json'] + self::SPONSORED_HEADERS), Json::encode($body));
    }

    private function warn(string $message, ?\Throwable $e = null): void
    {
        if ($this->log !== null) {
            ($this->log)('constile gate: ' . $message, $e);
        }
    }
}
