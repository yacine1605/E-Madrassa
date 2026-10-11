# Deploying the platform

One Compose project per installation: the shared SaaS, or a school's dedicated installation (ND-06). Docker and its Compose plugin are the only requirements on the server.

## Services

| Service | Role |
|---|---|
| `proxy` | Caddy: the only service facing the network; HTTPS for every connection, HSTS (NFR-SEC-01); certificates from Let's Encrypt for a public name, from its own authority for `localhost` |
| `app` | The application (Apache, PHP 8.3): API, school panel `/school`, publisher console `/console` |
| `worker` | Background jobs: imports, exports, previews (FR-JOB-01) |
| `scheduler` | The email outbox every minute, the purge of expired files every day |
| `migrate` | One-shot, on every start: migrations as the database owner, then `deploy/roles.sql` and the roles' passwords, then the files' bucket |
| `postgres` | PostgreSQL 16 |
| `redis` | Sessions, cache, job queue; behind its password (`REDIS_PASSWORD`) |
| `garage` | S3-compatible storage for the schools' files (FR-FIC-01), on its own volume |
| `backup` | With `--profile backup`: the daily encrypted backups, and restorations (below) |

The database, Redis and the storage are on the internal `data` network: no way out to the internet, and no way in but from the application's services (`app`, `worker`, `scheduler`, `migrate`, `backup`). The proxy cannot reach them.

The database owner's password reaches `migrate` only. Requests and jobs connect as `school_runtime` (row-level security), the console as `platform_admin` (no school data), the sign-in as `platform_auth` (passwords and MFA secrets, which the other two cannot read); none owns a table or bypasses row-level security. The application's encryption key is created on the first start and kept on the storage volume, with the data it protects.

## First start

```sh
cd platform
sh deploy/new-env.sh https://madrassa.example.dz   # writes deploy.env with fresh secrets
docker compose --env-file deploy.env up -d --build --wait
```

Without an address, `deploy/new-env.sh` prepares a local installation at <https://localhost:8443> (Caddy's own certificate: the browser warns once). `deploy.env` holds every password of the installation: keep it private and out of Git (it is ignored). For a public name, its DNS points to the server and ports 80 and 443 are open: Caddy obtains the certificate itself.

Then, as the publisher:

```sh
# An operator of the console (/console), with a temporary password shown once; MFA at first sign-in.
docker compose --env-file deploy.env run --rm migrate php artisan platform:user operator@example.dz --name="Opérateur"
docker compose --env-file deploy.env run --rm migrate php artisan platform:operator operator@example.dz
```

Schools are created from the console (`/console`, « Nouvel établissement »), which invites their first administrator by email; or by command:

```sh
docker compose --env-file deploy.env run --rm migrate php artisan platform:create-school ecole-a "École A" --admin=direction@ecole-a.dz
```

Commands that need the database owner run in `migrate`; the running services cannot.

## Email

Until the provider is chosen (OD-12), `MAIL_MAILER=log` writes emails to the logs: invitation links can be read with `docker compose --env-file deploy.env logs app worker`. For a provider: `MAIL_MAILER=smtp` and its `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, then `docker compose --env-file deploy.env up -d`.

## Files in another S3 storage

To use a provider instead of the bundled Garage: in `deploy.env`, `GARAGE_ADMIN_URL=` (empty), `FILES_ENDPOINT`, `FILES_REGION`, `FILES_BUCKET` and the provider's key in `FILES_ACCESS_KEY_ID` and `FILES_SECRET_ACCESS_KEY`; the bucket exists and is private. The `garage` service may then be stopped. NFR-DATA-01: the files stay where the provider keeps them; choose its region accordingly.

## Updates

```sh
git pull
docker compose --env-file deploy.env up -d --build --wait
```

`migrate` applies the new migrations and privileges before the application restarts. When Compose answers `set REDIS_PASSWORD` or `set DB_AUTH_PASSWORD`, the `deploy.env` predates that secret: add it with `echo "REDIS_PASSWORD=$(openssl rand -hex 24)" >> deploy.env` (or `DB_AUTH_PASSWORD=`), then update again. Every migration has its reverse (`php artisan migrate:rollback --database=owner`, in `migrate`).

## Checking an installation

`sh deploy/smoke-test.sh` builds the image and deploys it in a disposable project on ports 18080 and 18443, then checks HTTPS and HSTS, the restricted roles, the owner's password kept out of the running services, the data services out of the proxy's reach and Redis behind its password, the files' storage, a school created, its administrator invited and signed in, the scheduler and the worker; it removes its project afterwards. CI runs it on every change.

## Backups and restoration

```sh
docker compose --env-file deploy.env --profile backup up -d --build --wait
```

The `backup` service copies, every day at `BACKUP_HOUR` (Algeria time, 3 by default), the database, the files' bucket and the encryption key, then keeps the last `BACKUP_KEEP` (14) in `BACKUP_DIR` (`./backups`). The first start and missed days catch up at once. During a copy the application answers 503 for a few seconds and the jobs pause, so that the database and the files are copied at rest. Each backup is a folder with `database.dump.gpg`, `key.tar.gz.gpg`, `objects.tar.gz.gpg` (GnuPG, AES-256, with `BACKUP_PASSPHRASE`) and `manifest.json`: the row count of every table, the number and size of the files, the checksums before and after encryption.

`BACKUP_PASSPHRASE` is kept apart from the backups: without it no restoration is possible. The folder holds personal data and the encryption key, encrypted: keep it private, and copy it regularly to another disk or site (NFR-REC-01: a copy on the same disk does not survive the disk).

```sh
docker compose --env-file deploy.env run --rm backup backup        # a backup now
docker compose --env-file deploy.env run --rm backup verify NAME   # decrypt and check one
docker compose --env-file deploy.env run --rm backup health        # the latest one is recent and intact
```

The service reports itself unhealthy when the last success is more than 26 hours old.

### Restoring

Always into a **new** installation: the restoration refuses a database, a storage volume or a bucket that is not empty, and never touches the installation the backup comes from.

```sh
sh deploy/new-env.sh https://madrassa.example.dz   # in another folder, or with DEPLOY_ENV=recovery.env
# In the new settings: another COMPOSE_PROJECT_NAME, the same BACKUP_PASSPHRASE and BACKUP_DIR.
docker compose --env-file recovery.env up -d --wait postgres garage redis
docker compose --env-file recovery.env run --rm backup restore backup-YYYYMMDDTHHMMSSffffffZ
docker compose --env-file recovery.env up -d --build --wait
```

The restoration prepares the bucket, recreates the database roles, restores the database in one transaction and checks every table's row count, then the files (their number and size checked) and the encryption key. The new installation keeps its own passwords: `migrate` sets them again with the roles' privileges when it starts. Sessions are not kept: people sign in again.

`sh deploy/acceptance-test.sh` opens every screen of a disposable installation in a browser, in French and Arabic, on a desktop and a phone, and keeps a screenshot of each in `.acceptance/` (`BROWSER_CHANNEL=msedge` uses an installed Edge; otherwise Playwright's Chromium). CI runs it too.

`sh deploy/restore-test.sh` does all of this between two disposable installations and checks that the school, its administrator's sign-in, a file and the key come back; CI runs it on every change.
