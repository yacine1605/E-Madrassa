#!/bin/sh
# Writes deploy.env with fresh secrets, from deploy.env.example. Refuses to overwrite one.
# Usage: sh deploy/new-env.sh [https://public.address] (default https://localhost:8443)
# DEPLOY_ENV names another file to write (the smoke test's).
set -eu
cd "$(dirname "$0")/.."
out=${DEPLOY_ENV:-deploy.env}
[ -e "$out" ] && { echo "$out exists: keep it, or move it away first." >&2; exit 1; }
url=${1:-https://localhost:8443}
host=$(printf '%s' "$url" | sed -E 's#^https://##; s#[:/].*$##')
hex() { openssl rand -hex "$1"; }
sed -e "s#^APP_URL=.*#APP_URL=$url#" \
    -e "s#^SITE_ADDRESS=.*#SITE_ADDRESS=$host#" \
    -e "s#^DB_OWNER_PASSWORD=.*#DB_OWNER_PASSWORD=$(hex 24)#" \
    -e "s#^DB_RUNTIME_PASSWORD=.*#DB_RUNTIME_PASSWORD=$(hex 24)#" \
    -e "s#^DB_CONSOLE_PASSWORD=.*#DB_CONSOLE_PASSWORD=$(hex 24)#"     -e "s#^DB_AUTH_PASSWORD=.*#DB_AUTH_PASSWORD=$(hex 24)#" \
    -e "s#^REDIS_PASSWORD=.*#REDIS_PASSWORD=$(hex 24)#" \
    -e "s#^FILES_ACCESS_KEY_ID=.*#FILES_ACCESS_KEY_ID=GK$(hex 12)#" \
    -e "s#^FILES_SECRET_ACCESS_KEY=.*#FILES_SECRET_ACCESS_KEY=$(hex 32)#" \
    -e "s#^GARAGE_RPC_SECRET=.*#GARAGE_RPC_SECRET=$(hex 32)#" \
    -e "s#^GARAGE_ADMIN_TOKEN=.*#GARAGE_ADMIN_TOKEN=$(hex 32)#" \
    -e "s#^BACKUP_PASSPHRASE=.*#BACKUP_PASSPHRASE=$(hex 24)#" \
    deploy.env.example > "$out"
chmod 600 "$out"
if [ "$host" != localhost ]; then
    sed -i -e 's#^HTTP_PORT=.*#HTTP_PORT=80#' -e 's#^HTTPS_PORT=.*#HTTPS_PORT=443#' "$out"
fi
echo "$out written for $url. Keep it private: it holds every password of this deployment."
