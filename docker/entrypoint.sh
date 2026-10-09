#!/bin/sh
set -eu

if [ ! -f .env ]; then
    cp .env.example .env
fi

mkdir -p storage/app/private/demo-uploads storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php /usr/local/bin/madrassa-persist-key.php

php artisan migrate --force --no-interaction
php artisan filament:assets

exec "$@"
