#!/bin/sh
set -eu

if [ ! -f .env ]; then
    cp .env.example .env
fi

mkdir -p database storage/app/private/demo-uploads storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
touch database/database.sqlite
chown -R www-data:www-data database storage bootstrap/cache

if ! grep -q '^APP_KEY=.\+' .env; then
    php artisan key:generate --force --no-interaction
fi

php artisan migrate --force --no-interaction
php artisan filament:assets

exec "$@"
