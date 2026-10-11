#!/bin/sh
# NFR-REC-02: backs up an installation and restores it into a fresh one with its own passwords,
# then checks that nothing was lost: the school and its administrator's sign-in, a file of the
# bucket, the encryption key; and that a restore over existing data is refused.
# Usage: sh deploy/restore-test.sh   (removes both projects and the backups afterwards)
set -eu
cd "$(dirname "$0")/.."
# Git Bash on Windows would rewrite container paths such as /backups; no effect elsewhere.
export MSYS_NO_PATHCONV=1
work=.restore-test
rm -rf "$work"
mkdir -p "$work/backups"
env_a=$work/a.env
env_b=$work/b.env
DEPLOY_ENV="$env_a" sh deploy/new-env.sh https://localhost:18643 >/dev/null
DEPLOY_ENV="$env_b" sh deploy/new-env.sh https://localhost:18743 >/dev/null
passphrase=$(sed -n 's/^BACKUP_PASSPHRASE=//p' "$env_a")
settle() { # project, env file, https port, http port
    sed -i -e "s#^COMPOSE_PROJECT_NAME=.*#COMPOSE_PROJECT_NAME=$1#" -e "s#^HTTPS_PORT=.*#HTTPS_PORT=$3#" -e "s#^HTTP_PORT=.*#HTTP_PORT=$4#" \
        -e "s#^BACKUP_DIR=.*#BACKUP_DIR=./$work/backups#" -e "s#^BACKUP_PASSPHRASE=.*#BACKUP_PASSPHRASE=$passphrase#" "$2"
    printf 'BACKUP_DRAIN_SECONDS=1\n' >> "$2"
}
settle madrassa-restore-a "$env_a" 18643 18180
settle madrassa-restore-b "$env_b" 18743 18280
a() { docker compose -p madrassa-restore-a --env-file "$env_a" "$@"; }
b() { docker compose -p madrassa-restore-b --env-file "$env_b" "$@"; }
cleanup() {
    # The backups belong to the container's root, and are readable by it only: removed from inside.
    a run --rm -T --no-deps --entrypoint sh backup -c 'rm -rf /backups/* /backups/.[!.]*' >/dev/null 2>&1 || true
    a --profile backup down -v --remove-orphans >/dev/null 2>&1 || true
    b --profile backup down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS $*"; }
password='Le figuier du jardin 2026'
signs_in() { # port: the administrator signs in and finds the school
    jar=$work/cookies
    rm -f "$jar"
    token=$(curl -ksS -c "$jar" -b "$jar" "https://localhost:$1/api/csrf" | sed -E 's/.*"csrf_token":"([^"]+)".*/\1/')
    curl -ksS -c "$jar" -b "$jar" -H "X-CSRF-TOKEN: $token" -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -d "{\"login\":\"direction@restore.test\",\"password\":\"$password\"}" "https://localhost:$1/api/session" | grep -q '"slug":"restore-a"'
}

a up -d --build --wait --wait-timeout 300 || fail "the first installation did not start"
a run --rm -T migrate php artisan platform:user direction@restore.test --name=Direction --password="$password" >/dev/null
a run --rm -T migrate php artisan platform:create-school restore-a "École A" --admin=direction@restore.test >/dev/null
a run --rm -T migrate php artisan platform:membership restore-a direction@restore.test >/dev/null
a exec -T app php artisan tinker --execute="Storage::disk('files')->put('tenant/restore/check.txt', 'restored');" >/dev/null
key_a=$(a exec -T app cat storage/app/.app-key)
signs_in 18643 || fail "the first installation's administrator cannot sign in"
pass "an installation with a school, its administrator and a file"

a build backup >/dev/null 2>&1
name=$(a run --rm -T backup backup | sed -n 's/.*"backup": "\([^"]*\)".*/\1/p')
[ -n "$name" ] || fail "no backup made"
listing=$(a run --rm -T --no-deps --entrypoint ls backup "/backups/$name") || fail "the backup cannot be listed"
[ "$(printf '%s\n' $listing | sort | tr '\n' ' ')" = "database.dump.gpg key.tar.gz.gpg manifest.json objects.tar.gz.gpg " ] \
    || fail "the backup holds something other than its encrypted archives and manifest: $listing"
a run --rm -T backup verify "$name" >/dev/null || fail "the backup does not verify"
signs_in 18643 || fail "the installation stayed in maintenance after the backup"
pass "an encrypted backup, verified, and the installation back in service (NFR-REC-01)"

b up -d --wait --wait-timeout 120 postgres garage redis || fail "the second installation's database and storage did not start"
b run --rm -T backup restore "$name" || fail "the restore failed"
b up -d --wait --wait-timeout 300 || fail "the restored installation did not start"
signs_in 18743 || fail "the restored school's administrator cannot sign in"
[ "$(b exec -T app php artisan tinker --execute="echo Storage::disk('files')->get('tenant/restore/check.txt');")" = restored ] \
    || fail "the bucket's file was not restored"
[ "$(b exec -T app cat storage/app/.app-key)" = "$key_a" ] || fail "the encryption key was not restored"
[ "$(b exec -T app php artisan tinker --execute="echo DB::selectOne('select current_user u')->u;")" = school_runtime ] \
    || fail "the restored installation does not run as school_runtime"
pass "restored into a fresh installation with its own passwords: school, sign-in, files and key (NFR-REC-02)"

if b run --rm -T backup restore "$name" >/dev/null 2>&1; then fail "a restore over existing data was accepted"; fi
pass "a restore over existing data is refused"
echo "Backup and restore test passed."
