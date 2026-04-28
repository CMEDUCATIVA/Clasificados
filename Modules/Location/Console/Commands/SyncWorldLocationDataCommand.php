<?php

namespace Modules\Location\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncWorldLocationDataCommand extends Command
{
    protected $signature = 'location:sync-world {--force : Force sync even if the dataset is complete}';

    protected $description = 'Sync world location data (countries, cities, districts) using LocationSeeder';

    public function handle(): int
    {
        $minimumCountries = (int) config('location.seed.minimum_countries', 200);

        if (! Schema::hasTable('countries')) {
            $this->error('Table countries does not exist yet.');

            return self::FAILURE;
        }

        $countriesCount = (int) DB::table('countries')->count();
        $shouldSync = $this->option('force') || $countriesCount < $minimumCountries;

        if (! $shouldSync) {
            $this->info("Location dataset already complete ({$countriesCount} countries).");

            return self::SUCCESS;
        }

        $this->info("Syncing world location dataset (current countries: {$countriesCount})...");

        ini_set('memory_limit', (string) config('location.seed.memory_limit', '1024M'));

        $exitCode = Artisan::call('db:seed', [
            '--class' => 'Modules\\Location\\Database\\Seeders\\LocationSeeder',
            '--force' => true,
        ]);

        $this->output->write(Artisan::output());

        if ($exitCode !== 0) {
            $this->error('Location sync failed.');

            return self::FAILURE;
        }

        $updatedCountriesCount = (int) DB::table('countries')->count();
        $this->info("Location sync complete. Countries: {$updatedCountriesCount}");

        return self::SUCCESS;
    }
}

