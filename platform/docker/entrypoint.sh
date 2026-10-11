#!/bin/sh
# Before every process of the image (web, worker, scheduler, migrations): the storage volume's
# folders, the encryption key, then the process, as www-data (Apache drops to it by itself).
set -eu
cd /var/www/html

mkdir -p storage/app/private storage/app/mpdf storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown www-data:www-data storage storage/app storage/app/private storage/app/mpdf storage/framework \
    storage/framework/cache storage/framework/sessions storage/framework/views storage/logs

# The key that encrypts MFA secrets and queued links lives with the school data, on the storage
# volume, so that a backup restores it with them. The migrate service creates it on first start.
KEY_FILE=storage/app/.app-key
if [ -s "$KEY_FILE" ]; then
    SAVED=$(cat "$KEY_FILE")
    if [ -n "${APP_KEY:-}" ] && [ "$APP_KEY" != "$SAVED" ]; then
        echo "APP_KEY differs from the key kept with the data ($KEY_FILE): refusing to start." >&2
        exit 1
    fi
    export APP_KEY="$SAVED"
elif [ -z "${APP_KEY:-}" ] && [ "${MADRASSA_CREATES_KEY:-false}" = "true" ]; then
    APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
    export APP_KEY
    printf '%s\n' "$APP_KEY" > "$KEY_FILE"
elif [ -n "${APP_KEY:-}" ]; then
    printf '%s\n' "$APP_KEY" > "$KEY_FILE"
else
    echo "No application key yet: the migrate service creates it on its first run." >&2
    exit 1
fi
chown www-data:www-data "$KEY_FILE"
chmod 600 "$KEY_FILE"

if [ "$1" = "apache2-foreground" ]; then exec "$@"; fi
exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
