#!/bin/sh
# The finance's review pack of lot 3 (tests/browser/review-lot3.mjs): an installation of its own,
# kept running, with the example school « École Les Oliviers », its four accounts with MFA, and a
# month of example data. The receipts, screenshots and the review sheet go to .review/; the
# accounts and their codes to .review/comptes.txt. REVUE_LOT3.md says what to check.
# Usage: sh deploy/review-lot3.sh          set it up (again, from nothing)
#        sh deploy/review-lot3.sh stop     remove it, its data included
set -eu
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
project=madrassa-review
port=18943
env_file=.review.env
compose() { docker compose -p "$project" --env-file "$env_file" "$@"; }
if [ "${1:-}" = stop ]; then
    compose down -v --remove-orphans
    rm -f "$env_file"
    exit 0
fi
[ -f "$env_file" ] && compose down -v --remove-orphans >/dev/null 2>&1 || true
rm -rf .review "$env_file"
DEPLOY_ENV="$env_file" sh deploy/new-env.sh "https://localhost:$port" >/dev/null
sed -i -e "s#^COMPOSE_PROJECT_NAME=.*#COMPOSE_PROJECT_NAME=$project#" -e 's#^HTTP_PORT=.*#HTTP_PORT=18980#' -e "s#^HTTPS_PORT=.*#HTTPS_PORT=$port#" "$env_file"
compose up -d --build --wait --wait-timeout 300

password='Les oliviers de Hydra 2026'
mkdir -p .review
secret() { # the MFA secret platform:user --mfa shows once
    compose run --rm -T migrate php artisan platform:user "$1" --name="$2" --password="$password" --mfa | sed -n 's/.*authenticator application: \([A-Z2-7]*\).*/\1/p'
}
accounts=''
{
    echo "Revue du lot 3 : $(printf 'https://localhost:%s/school' "$port")"
    echo "Mot de passe de chaque compte : $password"
    echo "Code à six chiffres : ajouter le secret à une application d'authentification (Google Authenticator, Aegis…)."
    echo
} > .review/comptes.txt
for row in 'direction|Direction (Samira Haddad)|direction' 'comptable|Comptable (Rachid Bouzid)|accountant' \
        'tresorier|Trésorier (Leïla Amrani)|accountant' 'enseignant|Enseignant (Karim Meziane)|teacher'; do
    kind=${row%%|*}; rest=${row#*|}; name=${rest%%|*}; role=${rest#*|}
    email="$kind@oliviers.example"
    code=$(secret "$email" "$name")
    [ -n "$code" ] || { echo "FAIL: no MFA secret for $email" >&2; exit 1; }
    printf '%-12s %-36s secret MFA %s\n' "$kind" "$email" "$code" >> .review/comptes.txt
    accounts="$accounts\"$kind\":{\"email\":\"$email\",\"password\":\"$password\",\"secret\":\"$code\"},"
    if [ "$kind" = direction ]; then
        compose run --rm -T migrate php artisan platform:create-school oliviers "École Les Oliviers" --plan=complete --admin="$email" >/dev/null
        compose run --rm -T migrate php artisan platform:membership oliviers "$email" --role=direction --role=academic_hr --role=front_office >/dev/null
    else
        compose run --rm -T migrate php artisan platform:membership oliviers "$email" --role="$role" >/dev/null
    fi
done

[ -d tests/browser/node_modules/playwright ] || npm ci --prefix tests/browser --no-audit --no-fund >/dev/null
[ -n "${BROWSER_CHANNEL:-}" ] || node tests/browser/node_modules/playwright/cli.js install chromium >/dev/null
BASE_URL="https://localhost:$port" OUT=.review REVIEW_ACCOUNTS="{${accounts%,}}" node tests/browser/review-lot3.mjs
echo
cat .review/comptes.txt
echo
echo "Fiche : .review/fiche-revue.md ; reçus : .review/recus ; écrans : .review/ecrans. À retirer : sh deploy/review-lot3.sh stop"
