#!/bin/sh
# Deploys the platform in a disposable Compose project and checks it end to end: HTTPS and HSTS
# through the proxy, the restricted roles at work, the owner's password absent from the running
# services, the data services out of the proxy's reach and Redis behind its password, files in
# the bundled storage, the scheduler, a school created and signed in to.
# Usage: sh deploy/smoke-test.sh   (removes its project and volumes afterwards)
set -eu
cd "$(dirname "$0")/.."
# Git Bash on Windows would rewrite container paths such as /backups; no effect elsewhere.
export MSYS_NO_PATHCONV=1
project=madrassa-smoke
# Its files in the project's folder: Docker and curl on Windows would not find a POSIX path.
work=.smoke
env_file=$work/env
jar=$work/cookies
rm -rf "$work"
mkdir -p "$work"
port=18443
DEPLOY_ENV="$env_file" sh deploy/new-env.sh "https://localhost:$port" >/dev/null
sed -i -e "s#^COMPOSE_PROJECT_NAME=.*#COMPOSE_PROJECT_NAME=$project#" -e 's#^HTTP_PORT=.*#HTTP_PORT=18080#' -e "s#^HTTPS_PORT=.*#HTTPS_PORT=$port#" "$env_file"
compose() { docker compose -p "$project" --env-file "$env_file" "$@"; }
cleanup() { compose down -v --remove-orphans >/dev/null 2>&1 || true; rm -rf "$work"; }
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; compose logs --tail=40 >&2 || true; exit 1; }
pass() { echo "PASS $*"; }

compose up -d --build --wait --wait-timeout 300 || fail "the deployment did not become healthy"
pass "every service started; migrations, roles and storage applied by the one-shot service"

base="https://localhost:$port"
headers=$(curl -ksS -D - -o "$work/login.html" "$base/school/login")
echo "$headers" | grep -q "^HTTP/[0-9.]* 200" || fail "the sign-in page is not served"
echo "$headers" | grep -qi "^strict-transport-security: max-age=31536000" || fail "no HSTS header"
echo "$headers" | grep -qi "^x-content-type-options: nosniff" || fail "no nosniff header"
pass "the sign-in page is served over HTTPS with HSTS (NFR-SEC-01)"
echo "$headers" | grep -qi "^content-security-policy: default-src 'self'; script-src 'self' 'nonce-" || fail "no Content Security Policy"
pass "the sign-in page carries the Content Security Policy (NFR-SEC-03)"
grep -q "Se connecter" "$work/login.html" || fail "the sign-in page is not the platform's"

[ "$(compose exec -T app php artisan tinker --execute="echo DB::selectOne('select current_user u')->u;")" = school_runtime ] \
    || fail "requests do not run as school_runtime"
compose exec -T app sh -c 'test -z "${DB_OWNER_PASSWORD:-}"' || fail "the owner's password reaches the application"
pass "requests run as school_runtime; the owner's password stays with the migrate service"

compose exec -T proxy nc -z -w 3 app 80 || fail "the proxy cannot reach the application"
for service in postgres:5432 redis:6379 garage:3900; do
    if compose exec -T proxy nc -z -w 3 "${service%:*}" "${service#*:}" >/dev/null 2>&1; then fail "the proxy reaches $service"; fi
done
compose exec -T redis env -u REDISCLI_AUTH redis-cli ping 2>&1 | grep -q NOAUTH || fail "Redis answers without its password"
pass "the database, Redis and the storage are out of the proxy's reach; Redis asks for its password"

[ "$(compose exec -T app php artisan tinker --execute="Storage::disk('files')->put('smoke/check.txt', 'ok'); echo Storage::disk('files')->get('smoke/check.txt');")" = ok ] \
    || fail "the files' storage does not answer"
pass "files are written to and read from the bundled S3-compatible storage (FR-FIC-01)"
compose exec -T app php -r 'exit((imagetypes() & IMG_JPEG) && (imagetypes() & IMG_PNG) && (imagetypes() & IMG_WEBP) ? 0 : 1);' \
    || fail "the image cannot read JPEG, PNG and WebP photos"
pass "pupils' photos are read in JPEG, PNG and WebP (FR-ELV-01)"

compose run --rm -T migrate php artisan platform:user direction@smoke.test --name=Direction --password='Le figuier du jardin 2026' >/dev/null
compose run --rm -T migrate php artisan platform:create-school smoke-a "École A" --admin=direction@smoke.test | grep -q "Invitation sent"     || fail "the administrator's invitation was not sent"
compose run --rm -T migrate php artisan platform:membership smoke-a direction@smoke.test >/dev/null
token=$(curl -ksS -c "$jar" -b "$jar" "$base/api/csrf" | sed -E 's/.*"csrf_token":"([^"]+)".*/\1/')
curl -ksS -c "$jar" -b "$jar" -H "X-CSRF-TOKEN: $token" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d '{"login":"direction@smoke.test","password":"Le figuier du jardin 2026"}' "$base/api/session" | grep -q '"slug":"smoke-a"' \
    || fail "the school's administrator cannot sign in"
pass "a school created by the publisher's commands, its administrator invited by email and signed in"

i=0
until compose logs scheduler 2>/dev/null | grep -q "platform:outbox"; do
    i=$((i + 1)); [ $i -le 15 ] || fail "the scheduler did not run the outbox"; sleep 5
done
pass "the scheduler works the email outbox (FR-NOT-01)"
[ "$(compose ps --status running --services | grep -c '^worker$')" = 1 ] || fail "the queue worker is not running"
pass "the queue worker is running (FR-JOB-01)"
echo "Deployment smoke test passed."
