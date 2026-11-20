<?php

declare(strict_types=1);

namespace Constile\Gate\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;

/** Auto-discovered through composer.json's extra.laravel.providers. */
final class GateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/constile.php', 'constile');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../../config/constile.php' => $this->app->configPath('constile.php')], 'constile-config');
        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
        }
        if ($this->app['config']->get('constile.register_middleware', true) && $this->app->bound(Kernel::class)) {
            $kernel = $this->app->make(Kernel::class);
            if (method_exists($kernel, 'hasMiddleware') && $kernel->hasMiddleware(GateMiddleware::class)) {
                return;
            }
            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(GateMiddleware::class);
            }
        }
    }
}
