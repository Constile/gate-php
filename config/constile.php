<?php

/*
 * Constile for Laravel. Copy the site id, the site secret, and the
 * network URL from your site page in the Constile dashboard into .env.
 * To edit this file, publish it:
 *   php artisan vendor:publish --tag=constile-config
 */
return [
    'site_id' => env('CONSTILE_SITE_ID'),
    'secret' => env('CONSTILE_SITE_SECRET'),
    'network_url' => env('CONSTILE_NETWORK_URL'),

    // Turns the gate off: every request goes to your app unchanged, and nothing goes to the network. Set
    // CONSTILE_DISABLED=1 in .env (then run php artisan config:cache if you cache your config), or in the server's
    // environment, which the gate also reads on every request.
    'disabled' => env('CONSTILE_DISABLED', false),

    // The gate handles only requests for your site's registered domain (or its www twin), which the network's
    // config names, and fetches the articles for released offers from it.
    // Optional: where to connect for those fetches instead, such as http://127.0.0.1, when the server can't reach its
    // own public address. The domain still goes in the Host header.
    'connect_url' => env('CONSTILE_CONNECT_URL'),

    // Release fetches that may run at once across the server's PHP processes.
    'max_self_fetches' => (int) env('CONSTILE_MAX_SELF_FETCHES', 2),

    // The Constile-Gate-Bypass value that marks the gate's own requests. Defaults to a value derived from the secret.
    'bypass' => env('CONSTILE_GATE_BYPASS'),

    // Seconds to wait for the network before serving the page as if the gate weren't there. The config call waits
    // at most 3.
    'timeout' => (float) env('CONSTILE_NETWORK_TIMEOUT', 5),

    // The cache store for the site's config. Null uses APCu when it's on, else your default store.
    'cache_store' => env('CONSTILE_CACHE_STORE'),

    // Add the middleware to the global middleware stack automatically. Set to false to add
    // Constile\Gate\Laravel\GateMiddleware yourself.
    'register_middleware' => (bool) env('CONSTILE_REGISTER_MIDDLEWARE', true),
];
