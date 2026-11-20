<?php

declare(strict_types=1);

namespace Constile\Gate\Symfony;

use Constile\Gate\Cache\ConfigCache;
use Constile\Gate\ClientIp;
use Constile\Gate\Gate;
use Constile\Gate\GateRequest;
use Constile\Gate\GateResponse;
use Constile\Gate\Http\Clients;
use Constile\Gate\Http\HttpClient;
use Constile\Gate\SelfFetch;
use Constile\Gate\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the gate around a Symfony-style app (Laravel middleware, a Symfony
 * HttpKernel, Drupal). The page for an agent comes from the app itself, in
 * process ($next), so the request is handled once. A release fetches the
 * article over HTTP from the site's registered domain, with Constile-Gate-Bypass,
 * because it must not carry the agent's cookies or session; a request with
 * the right bypass value goes straight to the app.
 *
 * The visitor's address is the app's own Request::getClientIp(), which honors
 * the app's trusted-proxy settings.
 */
final class Adapter
{
    private HttpClient $client;

    private SelfFetch $selfFetch;

    /**
     * The gate handles only requests for the site's registered domain (or its
     * www twin), which the network's config names, and fetches released
     * articles from it. A Host header or the app's own URL setting never
     * chooses where a fetch goes.
     *
     * @param string $bypass The install's Constile-Gate-Bypass value.
     * @param string|null $connectUrl Where to connect for releases instead of the domain, such as http://127.0.0.1.
     * @param callable(\Closure): void|null $defer Runs work after the response is sent.
     * @param callable(string, \Throwable|null): void|null $log
     * @param int $maxSelfFetches Release fetches that may run at once across the server's PHP processes.
     */
    public function __construct(
        private string $siteId,
        private string $secret,
        private string $networkUrl,
        private string $bypass,
        private string $platform,
        ?string $connectUrl = null,
        private ?ConfigCache $cache = null,
        ?HttpClient $client = null,
        private float $timeout = 5.0,
        private mixed $defer = null,
        private mixed $log = null,
        int $maxSelfFetches = 2,
        private ?string $lockDir = null,
    ) {
        $this->client = $client ?? Clients::auto();
        $this->selfFetch = new SelfFetch($this->client, $bypass, null, $connectUrl, 3.0, $maxSelfFetches, $lockDir);
    }

    /** A gate with this adapter's settings, for checks outside a request. */
    public function gate(?callable $origin = null): Gate
    {
        return new Gate(
            siteId: $this->siteId,
            secret: $this->secret,
            networkUrl: $this->networkUrl,
            origin: $origin ?? $this->selfFetch,
            network: $this->client,
            cache: $this->cache,
            platform: $this->platform,
            timeout: $this->timeout,
            defer: $this->defer,
            log: $this->log,
            lockDir: $this->lockDir,
            requireDomain: true,
        );
    }

    public function selfFetch(): SelfFetch
    {
        return $this->selfFetch;
    }

    /** @param callable(Request): Response $next */
    public function handle(Request $request, callable $next): Response
    {
        // Off: the app as if the gate weren't there.
        if (Gate::disabledByEnv()) {
            return $next($request);
        }
        if (!Url::networkUrlAllowed($this->networkUrl)) {
            if (is_callable($this->log)) {
                ($this->log)('constile gate: the network URL must use https; passing everything through', null);
            }
            return $next($request);
        }
        if (SelfFetch::isBypass($request->headers->get(SelfFetch::HEADER), $this->bypass)) {
            // The gate's own request for a page. The install check asks it to say the bypass arrived.
            $response = $next($request);
            $nonce = SelfFetch::seenNonce($request->query->get(SelfFetch::CHECK_PARAM));
            if ($nonce !== null) {
                $response->headers->set(SelfFetch::SEEN_HEADER, $nonce);
            }
            return $response;
        }
        $incoming = Bridge::request($request);
        if ($this->selfFetch->isEcho($incoming)) {
            return $next($request); // The gate's own fetch, arriving without its bypass header.
        }
        $produced = null;
        $selfFetch = $this->selfFetch;
        $origin = static function (GateRequest $r, ?string $domain = null) use ($incoming, $request, $next, &$produced, $selfFetch): GateResponse {
            if ($r === $incoming) {
                $produced ??= $next($request);
                return Bridge::response($produced);
            }
            return $selfFetch($r, $domain);
        };
        try {
            return Bridge::toSymfony($this->gate($origin)->handle($incoming, ClientIp::signals($request->getClientIp())));
        } catch (\Throwable $e) {
            if (is_callable($this->log)) {
                ($this->log)('constile gate: failed, passing through', $e);
            }
            if ($produced instanceof Response) {
                return $produced;
            }
            $path = $request->getPathInfo();
            if ($path === '/.well-known/constile' || str_starts_with($path, '/sponsored/')) {
                return Bridge::toSymfony(Gate::json(['error' => 'Sponsored access is unavailable right now'], 503));
            }
            return $next($request);
        }
    }
}
