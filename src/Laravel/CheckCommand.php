<?php

declare(strict_types=1);

namespace Constile\Gate\Laravel;

use Illuminate\Console\Command;
use Constile\Gate\Url;

/**
 * php artisan constile:check
 *
 * Has the network run its install check: it requests /.well-known/constile on
 * your site's domain with a fresh nonce, and the gate confirms the nonce with
 * the site's credentials. Run it once the app is deployed.
 */
final class CheckCommand extends Command
{
    protected $signature = 'constile:check {--path=/ : A page on your site that answers with HTML, for the gate to fetch}';

    protected $description = 'Have the Constile network check that the gate is installed on your site';

    public function handle(): int
    {
        $c = (array) $this->laravel['config']->get('constile', []);
        $missing = array_keys(array_filter(['CONSTILE_SITE_ID' => $c['site_id'] ?? '', 'CONSTILE_SITE_SECRET' => $c['secret'] ?? '', 'CONSTILE_NETWORK_URL' => $c['network_url'] ?? ''], static fn ($v) => (string) $v === ''));
        if ($missing !== []) {
            $this->error('Set ' . implode(', ', $missing) . ' in .env first, then clear the config cache.');
            return self::FAILURE;
        }
        $adapter = GateMiddleware::adapter($this->laravel);
        if (!Url::networkUrlAllowed((string) $c['network_url'])) {
            $this->error('CONSTILE_NETWORK_URL must start with https://.');
            return self::FAILURE;
        }
        $gate = $adapter->gate();

        // The gate's own requests must reach the app with their bypass header, or releases would be gated again.
        $domain = $gate->domain();
        if ($domain === null) {
            $this->error('The network didn\'t give this site\'s domain. Check CONSTILE_SITE_ID, CONSTILE_SITE_SECRET, and CONSTILE_NETWORK_URL.');
            return self::FAILURE;
        }
        $path = '/' . ltrim((string) $this->option('path'), '/');
        $bypass = $adapter->selfFetch()->checkBypass($domain, $path);
        if (!$bypass['ok']) {
            $this->error(match (true) {
                $bypass['error'] !== null => "Couldn't fetch {$domain}{$path}: {$bypass['error']}. Set CONSTILE_CONNECT_URL if the server can't reach its own address.",
                $bypass['status'] !== 200 => "{$domain}{$path} answered HTTP {$bypass['status']}; the gate needs a 200 page. Name one with --path.",
                !$bypass['bypassArrived'] => 'The gate\'s own requests reach the app without their Constile-Gate-Bypass header. Something in between (a CDN or firewall) removes it; set CONSTILE_CONNECT_URL to reach the app directly.',
                default => "{$domain}{$path} didn't answer with an HTML page. Name one with --path.",
            });
            return self::FAILURE;
        }
        $this->info("The gate's own requests reach the app at {$domain} with their bypass header.");

        $check = $gate->requestInstallCheck();
        if ($check['ok']) {
            $this->info('The network confirmed the install: it sent a one-time code to your site, and the gate confirmed it with the site\'s credentials.');
            return self::SUCCESS;
        }
        $this->error($check['status'] === 401
            ? 'The network didn\'t accept CONSTILE_SITE_ID and CONSTILE_SITE_SECRET.'
            : 'The install check failed: ' . $check['reason']);
        return self::FAILURE;
    }
}
