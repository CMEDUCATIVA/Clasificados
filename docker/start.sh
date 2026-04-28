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

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link

php-fpm -D
nginx -g 'daemon off;'
