<?php

declare(strict_types=1);

namespace Constile\Gate\Laravel;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Constile\Gate\Cache\ApcuCache;
use Constile\Gate\Cache\CallbackCache;
use Constile\Gate\Cache\ConfigCache;
use Constile\Gate\Gate;
use Constile\Gate\Http\HttpClient;
use Constile\Gate\SelfFetch;
use Constile\Gate\Symfony\Adapter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Constile gate as Laravel middleware. The service provider adds
 * it to the global middleware stack, after TrustProxies, so it answers
 * /.well-known/constile and /sponsored/* before routing, and reads the
 * visitor's address with the app's trusted-proxy settings.
 */
final class GateMiddleware
{
    public function __construct(private Application $app)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $adapter = self::adapter($this->app);
        return $adapter === null ? $next($request) : $adapter->handle($request, $next);
    }

    /** The adapter for the app's settings, or null when they're incomplete. */
    public static function adapter(Application $app): ?Adapter
    {
        $c = (array) $app['config']->get('constile', []);
        if (Gate::isOn($c['disabled'] ?? false)) {
            return null;
        }
        $secret = (string) ($c['secret'] ?? '');
        if (($c['site_id'] ?? '') === '' || $secret === '' || ($c['network_url'] ?? '') === '') {
            return null;
        }
        return new Adapter(
            siteId: (string) $c['site_id'],
            secret: $secret,
            networkUrl: (string) $c['network_url'],
            bypass: (string) ($c['bypass'] ?? '') ?: SelfFetch::derive($secret),
            platform: 'laravel',
            connectUrl: ($c['connect_url'] ?? null) ?: null,
            cache: self::cache($app, $c['cache_store'] ?? null),
            client: $app->bound(HttpClient::class) ? $app->make(HttpClient::class) : null,
            timeout: (float) ($c['timeout'] ?? 5.0),
            defer: static fn (Closure $work) => $app->terminating($work),
            log: static fn (string $message, ?\Throwable $e = null) => Log::warning($message, $e === null ? [] : ['exception' => $e->getMessage()]),
            maxSelfFetches: (int) ($c['max_self_fetches'] ?? 2),
        );
    }

    private static function cache(Application $app, ?string $store): ConfigCache
    {
        if (($store === null || $store === '') && ApcuCache::available()) {
            return new ApcuCache();
        }
        $repo = $app['cache']->store($store ?: null);
        return new CallbackCache(
            static fn (string $key): mixed => $repo->get($key),
            static function (string $key, array $entry, int $ttl) use ($repo): void {
                $repo->put($key, $entry, max(1, $ttl));
            },
            static function (string $key) use ($repo): void {
                $repo->forget($key);
            },
        );
    }
}
