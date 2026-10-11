#!/bin/sh
# One-shot, as the database owner, on every deployment: the schema, then the roles' privileges
# and passwords, then the files' storage. The long-running services never hold the owner's password.
set -eu
php artisan migrate --database=owner --force --no-interaction
php artisan platform:roles
php artisan platform:storage
