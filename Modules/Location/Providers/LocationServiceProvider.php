<?php

namespace Modules\Location\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Location\Console\Commands\SyncWorldLocationDataCommand;

class LocationServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Location';

    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/migrations'));
        $this->loadRoutesFrom(module_path($this->moduleName, 'routes/web.php'));
    }

    public function register(): void
    {
        $this->commands([
            SyncWorldLocationDataCommand::class,
        ]);
    }
}
