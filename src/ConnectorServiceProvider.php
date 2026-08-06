<?php

declare(strict_types=1);

namespace Ruklab\Connector;

use Illuminate\Support\ServiceProvider;

final class ConnectorServiceProvider extends ServiceProvider
{
    public const VERSION = '0.1.0';

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
    }
}
