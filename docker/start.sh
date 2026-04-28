#!/bin/sh
set -e

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

if [ "${AUTO_SYNC_LOCATION_ON_BOOT:-1}" = "1" ]; then
    echo "Starting non-blocking location sync command in background..."
    CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database php artisan location:sync-world >> /var/www/html/storage/logs/location-sync.log 2>&1 &
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link

php-fpm -D
nginx -g 'daemon off;'
