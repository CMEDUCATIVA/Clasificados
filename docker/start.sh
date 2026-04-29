#!/bin/sh
set -e

echo "START_SH_VERSION=2026-04-28-core-location-sync-v2"

if [ ! -f /var/www/html/.env ]; then
    echo ".env file not found. Configure environment variables in EasyPanel and provide an .env file."
    exit 1
fi

echo "Waiting for database connection..."
php -r '
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$attempts = 30;
while ($attempts-- > 0) {
    try {
        Illuminate\Support\Facades\DB::connection()->getPdo();
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDOUT, "Database not ready yet, retrying...\n");
        sleep(2);
    }
}

fwrite(STDERR, "Database connection timeout.\n");
exit(1);
'

set +e
CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database php artisan migrate --force
MIGRATE_EXIT=$?
set -e

if [ "$MIGRATE_EXIT" -ne 0 ] && [ "${AUTO_FIX_SPATIE_PERMISSION_TABLES:-1}" = "1" ]; then
    echo "Migration failed. Attempting automatic fix for Spatie permission tables..."
    php -r '
    require __DIR__ . "/vendor/autoload.php";
    $app = require __DIR__ . "/bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
    foreach ([
        "model_has_permissions",
        "model_has_roles",
        "role_has_permissions",
        "permissions",
        "roles",
    ] as $table) {
        Illuminate\Support\Facades\Schema::dropIfExists($table);
    }
    Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
    '

    CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database php artisan migrate --force
fi

if [ "${AUTO_SEED_AUTH_USERS:-1}" = "1" ]; then
    echo "Checking seeded auth users..."
    if php -r '
    require __DIR__ . "/vendor/autoload.php";
    $app = require __DIR__ . "/bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    try {
        $exists = Illuminate\Support\Facades\Schema::hasTable("users");
        if (! $exists) {
            exit(1);
        }

        $count = (int) Illuminate\Support\Facades\DB::table("users")->count();
        exit($count > 0 ? 0 : 2);
    } catch (Throwable) {
        exit(1);
    }
    '; then
        echo "Users already exist, skipping AuthUserSeeder."
    else
        CHECK_EXIT=$?
        if [ "$CHECK_EXIT" -eq 2 ]; then
            echo "No users found, running AuthUserSeeder..."
            CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database php artisan db:seed --class=Modules\\User\\Database\\Seeders\\AuthUserSeeder --force
        else
            echo "Could not verify users table, skipping AuthUserSeeder."
        fi
    fi
fi

AUTO_SEED_LOCATION_DATA=0

if [ "${AUTO_SYNC_LOCATION_ON_BOOT:-1}" = "1" ]; then
    echo "Checking location dataset integrity..."
    if php -r '
    require __DIR__ . "/vendor/autoload.php";
    $app = require __DIR__ . "/bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    try {
        if (! Illuminate\Support\Facades\Schema::hasTable("countries")
            || ! Illuminate\Support\Facades\Schema::hasTable("cities")
            || ! Illuminate\Support\Facades\Schema::hasTable("districts")) {
            exit(2);
        }

        $minimumCountries = (int) env("LOCATION_SEED_MINIMUM_COUNTRIES", 22);
        $countriesCount = (int) Illuminate\Support\Facades\DB::table("countries")->count();
        $peruCities = (int) Illuminate\Support\Facades\DB::table("cities")
            ->join("countries", "countries.id", "=", "cities.country_id")
            ->where("countries.code", "PE")
            ->count();

        if ($countriesCount >= $minimumCountries && $peruCities > 0) {
            exit(0);
        }

        exit(2);
    } catch (Throwable) {
        exit(1);
    }
    '; then
        echo "Location dataset is complete, skipping auto reseed."
    else
        CHECK_EXIT=$?
        if [ "$CHECK_EXIT" -eq 2 ]; then
            echo "Location dataset incomplete."
            if [ "${AUTO_SYNC_LOCATION_BLOCKING_ON_BOOT:-0}" = "1" ]; then
                echo "Running blocking automatic truncate + LocationSeeder..."
                php -r '
                require __DIR__ . "/vendor/autoload.php";
                $app = require __DIR__ . "/bootstrap/app.php";
                $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
                $kernel->bootstrap();

                Illuminate\Support\Facades\DB::statement("SET FOREIGN_KEY_CHECKS=0");
                Illuminate\Support\Facades\DB::table("districts")->truncate();
                Illuminate\Support\Facades\DB::table("cities")->truncate();
                Illuminate\Support\Facades\DB::table("countries")->truncate();
                Illuminate\Support\Facades\DB::statement("SET FOREIGN_KEY_CHECKS=1");
                '

                CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database php -d memory_limit=${LOCATION_SEED_MEMORY_LIMIT:-1024M} artisan db:seed --class=Modules\\Location\\Database\\Seeders\\LocationSeeder --force
            else
                echo "Skipping blocking reseed on boot (AUTO_SYNC_LOCATION_BLOCKING_ON_BOOT=0)."
                echo "You can run location sync manually after startup if needed."
            fi
        else
            echo "Could not verify location tables, skipping auto reseed."
        fi
    fi
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link

php-fpm -D
nginx -g 'daemon off;'
