<?php

declare(strict_types=1);

namespace Ruklab\Connector;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Ruklab\Connector\Http\HandleRedirects;

final class ConnectorServiceProvider extends ServiceProvider
{
    public const VERSION = '0.9.0';

    public function register(): void
    {
        // Merged rather than required: a site that publishes nothing still
        // gets working defaults, and the ones that differ override only what
        // differs.
        $this->mergeConfigFrom(__DIR__.'/../config/ruklab.php', 'ruklab');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/ruklab.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/ruklab.php' => config_path('ruklab.php'),
        ], 'ruklab-config');

        $this->serveRedirects();
    }

    /**
     * Put the redirect middleware in the global stack.
     *
     * Global rather than in the `web` group, because a URL that matches no
     * route never reaches a route's middleware — and those are exactly the
     * URLs a redirect exists for. Pushed after boot so the kernel is there to
     * push onto, and skipped on the console, where there is no kernel to
     * resolve and nothing to redirect.
     */
    private function serveRedirects(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        if (! config('ruklab.redirects.enabled', true)) {
            return;
        }

        $this->app->booted(function (): void {
            $this->app->make(HttpKernel::class)->pushMiddleware(HandleRedirects::class);
        });
    }
}
