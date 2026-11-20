# Constile gate for PHP

`constile/gate` is the Constile gate for PHP sites. When an AI
agent fetches one of your articles for a person, the gate serves the
Constile offer instead. People, search engines, and crawlers get your
pages as usual.

The package has no dependencies. It includes:

- the gate itself, for any PHP 8.1+ app;
- Laravel middleware with an auto-discovered service provider;
- a Symfony HttpKernel middleware, which the Drupal module also uses.

For WordPress, use the Constile plugin, and for Drupal, the Constile module.
Both bundle this package. The [setup guides](https://constile.app/setup) cover
every platform.

## Requirements

- PHP 8.1 or later.
- Optional: the curl extension. Without it, the gate uses PHP streams.
- Optional: APCu. With it, the gate keeps the site's config in shared memory.
- Optional: mbstring (or iconv), to read page meta in charsets other than
  UTF-8 and windows-1252, and to lowercase non-ASCII keywords the way the
  Cloudflare gate does.

## Install

```sh
composer require constile/gate:^0.7
```

Or download `constile-gate-php-0.7.0.zip` from your site page in the Constile
dashboard and install it from a local folder:

```sh
mkdir -p packages
cp ~/Downloads/constile-gate-php-0.7.0.zip packages/
composer config repositories.constile artifact ./packages
composer require constile/gate:^0.7
```

## Set up Laravel

1. Add your site's settings to `.env`. Copy the values from your site page in
   the dashboard:

   ```sh
   CONSTILE_SITE_ID=site_...
   CONSTILE_SITE_SECRET=...
   CONSTILE_NETWORK_URL=https://constile.app
   ```

   The network URL must start with `https://`.

2. Clear the config cache if you use one: `php artisan config:clear`.
3. Once the app is deployed, have the network check the install:

   ```sh
   php artisan constile:check
   ```

   The command checks that the gate's own requests reach your app with their
   bypass header. Then the network sends a one-time code to your site's
   `/.well-known/constile`, and the gate confirms it with the site's
   credentials.

The service provider adds the middleware to the end of the global middleware
stack, so it runs after `TrustProxies`. To add it yourself instead, set
`CONSTILE_REGISTER_MIDDLEWARE=false`. To edit the settings file, publish it:

```sh
php artisan vendor:publish --tag=constile-config
```

The gate handles only requests for your site's registered domain, which the
network's config names, and its `www.` twin. It passes every other request to
your app untouched. `APP_URL` doesn't count. If the server can't reach its own
public address for releases, set `CONSTILE_CONNECT_URL`, such as
`http://127.0.0.1`; the gate still sends your domain as the host name.

## Set up Symfony

Wrap your kernel with the middleware:

```php
use Constile\Gate\Symfony\Adapter;
use Constile\Gate\Symfony\GateMiddleware;
use Constile\Gate\SelfFetch;

$app = new GateMiddleware($kernel, new Adapter(
    siteId: $_ENV['CONSTILE_SITE_ID'],
    secret: $_ENV['CONSTILE_SITE_SECRET'],
    networkUrl: $_ENV['CONSTILE_NETWORK_URL'],
    bypass: SelfFetch::derive($_ENV['CONSTILE_SITE_SECRET']),
    platform: 'symfony',
));
$response = $app->handle($request);
```

## Use the gate in any PHP app

To have the network check the install, call `$gate->requestInstallCheck()`.
It returns `ok`, the network's HTTP `status` (429 when you check too often),
and the `reason` a check failed.

Give `Gate` your site's settings and a way to get a page from your site. For
an app that can't call itself in process, `SelfFetch` requests the page over
HTTP from your site's registered domain, with a `Constile-Gate-Bypass` header.
Pass any request with your bypass value, or one `isEcho()` recognizes, straight
to your app.

```php
use Constile\Gate\{ClientIp, Gate, GateRequest, GateResponse, Headers, SelfFetch};
use Constile\Gate\Http\Clients;

$bypass = SelfFetch::derive($secret);
$fetch = new SelfFetch(Clients::auto(), $bypass);
$url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$request = new GateRequest($_SERVER['REQUEST_METHOD'], $url, new Headers(getallheaders()));
if (!SelfFetch::isBypass($_SERVER['HTTP_CONSTILE_GATE_BYPASS'] ?? null, $bypass) && !$fetch->isEcho($request)) {
    $gate = new Gate(
        siteId: $siteId,
        secret: $secret,
        networkUrl: $networkUrl,
        origin: $fetch,
        forward: fn () => GateResponse::forward(), // Let the app answer.
        platform: 'php',
        requireDomain: true, // Only requests for the registered domain; fetches go there.
    );
    $ip = ClientIp::resolve($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null, $trustedProxies);
    try {
        $response = $gate->handle($request, ClientIp::signals($ip));
    } catch (\Throwable $e) {
        $response = GateResponse::forward(); // The self-fetch failed: fail open.
    }
    if (!$response->isForward()) {
        http_response_code($response->status);
        foreach ($response->headers as [$name, $value]) {
            header("{$name}: {$value}", false);
        }
        echo $response->body();
        exit;
    }
}
// Your app handles the request as usual.
```

`Gate` and `SelfFetch` keep their cross-process locks in files in the system's
temporary directory. To keep them somewhere else, such as your database, pass
`locks:` your own `LockStore`. The WordPress plugin keeps them in the options
table.

## Paywalled content

Gate 0.6.0 and later can fetch a paywalled article with the release
instructions the network sends, so an agent gets the full text and the offer
fits it. The network gives the gate a page address on your site's own host
and, optionally, headers to send: `Cookie`, `Authorization`,
`Constile-Paywall-Key`, or a header starting with `X-`. The gate ignores an
address on another host and drops other headers. It strips `Set-Cookie` from
what it releases and never logs the headers. To set up a method, open your
site's page in the dashboard and choose a content API, a paywall rule, or a
Constile login. A paywalled page gets offers only after the method works and
the gate is 0.6.0 or later.

## Scripts that pose as a browser

Some scripts send a browser's user agent so they get your pages as a person
would. Gate 0.7.0 and later can send them an ad instead: on your site's page
in the dashboard, under **Settings**, set **Scripts that pose as a browser**
to **Send them an ad**. The gate then takes a request with a browser's user
agent for a script when it sends neither `Accept-Language` nor
`Sec-Fetch-Mode`, headers browsers always send, and has no `constile_browser`
cookie. These ads count toward your site's daily limit on earnings from agents
nothing could verify. If a person's browser is ever taken for a script, the ad
page says the page loads in a moment, sets the `constile_browser` cookie, and
reloads, so the person gets your page. That takes JavaScript and cookies, and,
if your site sends a `Content-Security-Policy`, one that allows inline
scripts. The setting starts at **Serve them the normal page**, and older gates
ignore it.

## Turn it off

To send every request to your app unchanged, with nothing going to the
network, set `CONSTILE_DISABLED=1`. The gate reads it from the server's variables
on every request, so a change in the server's environment takes effect at
once. In Laravel, you can also put it in `.env`; then run
`php artisan config:cache` if you cache your config.

## How it works

- **Same behavior everywhere.** The gate behaves like Constile's gates for
  Cloudflare, JavaScript, Python, Ruby, and the proxy: every one passes the
  same conformance cases.
- **People never wait on the network** once the gate has a config. It keeps
  the config for the time the network sets (10 to 3,600 seconds). After that,
  it uses the last config, for up to a day, while it fetches a new one after
  the response. One process fetches at a time; the others use the last config
  or wait for that fetch.
- **After a failed config fetch**, the gate uses the last config (or passes
  everything through) and doesn't ask again for 30 seconds from the failure
  (set `configRetryS`).
- **It fails open.** The config call times out after 3 seconds and other
  network calls after 5 (set `timeout`). When the network can't be reached, the gate serves the page as
  if it weren't there. Callbacks (`/sponsored/...`) answer 503 instead,
  because they release content only against a recorded commitment.
- **A page passed through keeps its exact bytes.** The gate reads the page
  meta from a copy, decoded in the charset its `Content-Type` names.
- **The page for an agent** comes from your app in process in Laravel and
  Symfony. Releases fetch the article over HTTP from your site's registered
  domain, without the agent's cookies, and carry `Constile-Gate-Bypass`.
- **Self-fetches go only to the registered domain** that the network's config
  names: `https://<domain>` plus the request's path and query. A request's
  `Host` header, your app's URL setting, and a release URL's host never choose
  where a fetch goes. A request for any other host passes through ungated.
- **Self-fetches can't swamp a small server.** At most two run at once across
  the server's PHP processes (set `maxInFlight`), each for at most 3 seconds.
  When none is free, the agent gets the page without an offer.
- **Self-fetches can't loop.** While one is in flight, the same request
  arriving without its bypass header (a WAF or CDN removed it) passes through
  ungated.
- **The visitor's address** comes from your host's trusted source: Laravel's
  and Symfony's `getClientIp()`, which honor your trusted-proxy settings.
  `ClientIp::resolve()` reads `X-Forwarded-For` only from proxies you list.

## Differences from the Cloudflare gate

- In Laravel and Symfony, `Cache-Control: no-store` becomes
  `no-store, private`, because Symfony adds `private` to every `Cache-Control`
  header without `public`.
- Charsets other than UTF-8 and windows-1252 (with its aliases, such as
  `iso-8859-1`) need mbstring or iconv. Without them, the gate reads that
  page's meta as UTF-8.

## Support

Write to <hello@constile.app>, or see the
[setup guide for PHP](https://constile.app/setup/php).

## License

MIT. See [LICENSE](LICENSE).
