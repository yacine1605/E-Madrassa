#!/bin/sh
set -eu

if [ ! -f .env ]; then
    cp .env.example .env
fi

mkdir -p storage/app/private/demo-uploads storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php /usr/local/bin/madrassa-persist-key.php

# Only the app container migrates; the queue worker starts from the same image.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    # Schema changes and grants need the database owner.
    php artisan migrate --force --no-interaction
    php /usr/local/bin/madrassa-runtime-role.php
    php artisan filament:assets
fi

# Requests and jobs run as the restricted role: no table ownership, no BYPASSRLS,
# so row-level security still separates schools if application code has a bug.
# `docker compose exec app php artisan ...` keeps the owner, for provisioning commands.
if [ -z "${DB_RUNTIME_PASSWORD:-}" ]; then
    echo "DB_RUNTIME_PASSWORD is required." >&2
    exit 1
fi
export DB_USERNAME=school_runtime
export DB_PASSWORD="$DB_RUNTIME_PASSWORD"
unset DB_RUNTIME_PASSWORD

exec "$@"
