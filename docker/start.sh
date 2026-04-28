#!/bin/sh
set -e

if [ ! -f /var/www/html/.env ]; then
    echo ".env file not found. Configure environment variables in EasyPanel and provide an .env file."
    exit 1
fi

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link

php-fpm -D
nginx -g 'daemon off;'
