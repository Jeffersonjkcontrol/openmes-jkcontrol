<?php

namespace Modules\JkControl\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Entry point of the JkControl module. Only loaded while the module is enabled
 * in Admin → Modules.
 */
class JkControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jkcontrol.php', 'jkcontrol');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
