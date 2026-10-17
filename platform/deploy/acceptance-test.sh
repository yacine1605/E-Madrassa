#!/bin/sh
# Browser acceptance (tests/browser/acceptance.mjs) on a disposable installation: a school with
# its administrator, a console operator, both with MFA; every screen in French and Arabic, on a
# desktop and a phone, in each browser of BROWSERS. Screenshots and report in .acceptance/.
# Removes its project afterwards.
# Usage: sh deploy/acceptance-test.sh
#   BROWSERS=chromium,firefox,webkit   the browsers, chromium by default
#   BROWSER_CHANNEL=msedge             an installed Edge or Chrome instead of Playwright's Chromium
#   PLATFORM_CSP_STRICT=true           the trial of a policy without 'unsafe-eval' (SECURITY-REVIEW.md,
#                                      F4), after each upgrade of Filament: with 5.10 every screen fails
set -eu
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
project=madrassa-acceptance
port=18843
env_file=.acceptance.env
rm -rf .acceptance "$env_file"
DEPLOY_ENV="$env_file" sh deploy/new-env.sh "https://localhost:$port" >/dev/null
sed -i -e "s#^COMPOSE_PROJECT_NAME=.*#COMPOSE_PROJECT_NAME=$project#" -e 's#^HTTP_PORT=.*#HTTP_PORT=18880#' -e "s#^HTTPS_PORT=.*#HTTPS_PORT=$port#" "$env_file"
compose() { docker compose -p "$project" --env-file "$env_file" "$@"; }
cleanup() { [ -n "${KEEP:-}" ] && return; compose down -v --remove-orphans >/dev/null 2>&1 || true; rm -f "$env_file"; }
trap cleanup EXIT

compose up -d --build --wait --wait-timeout 300
password='Le figuier du jardin 2026'
secret() { # the MFA secret platform:user --mfa shows once
    compose run --rm -T migrate php artisan platform:user "$1" --name="$2" --password="$password" --mfa | sed -n 's/.*authenticator application: \([A-Z2-7]*\).*/\1/p'
}
admin_secret=$(secret direction@acceptance.test Direction)
operator_secret=$(secret operator@acceptance.test Opérateur)
[ -n "$admin_secret" ] && [ -n "$operator_secret" ] || { echo "FAIL: no MFA secret" >&2; exit 1; }
compose run --rm -T migrate php artisan platform:operator operator@acceptance.test >/dev/null
compose run --rm -T migrate php artisan platform:create-school acceptance-a "École Acceptation" --plan=complete --admin=direction@acceptance.test >/dev/null
compose run --rm -T migrate php artisan platform:membership acceptance-a direction@acceptance.test --role=academic_hr --role=front_office --role=accountant --role=teacher >/dev/null

[ -d tests/browser/node_modules/playwright ] || npm ci --prefix tests/browser --no-audit --no-fund >/dev/null
export BROWSERS="${BROWSERS:-chromium}"
for browser in $(printf '%s' "$BROWSERS" | tr ',' ' '); do
    [ "$browser" = chromium ] && [ -n "${BROWSER_CHANNEL:-}" ] && continue
    # Playwright's own command, not npx: Windows's command shim breaks on a path with `&`.
    node tests/browser/node_modules/playwright/cli.js install "$browser" >/dev/null
done
BASE_URL="https://localhost:$port" SHOTS=.acceptance \
ACCEPTANCE_ACCOUNTS="{\"admin\":{\"email\":\"direction@acceptance.test\",\"password\":\"$password\",\"secret\":\"$admin_secret\"},\"operator\":{\"email\":\"operator@acceptance.test\",\"password\":\"$password\",\"secret\":\"$operator_secret\"}}" \
    node tests/browser/acceptance.mjs
