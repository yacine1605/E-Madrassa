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
| `redis` | Sessions, cache, job queue |
| `garage` | S3-compatible storage for the schools' files (FR-FIC-01), on its own volume |

The database owner's password reaches `migrate` only. Requests and jobs connect as `school_runtime` (row-level security), the console as `platform_admin` (no school data); neither owns a table nor bypasses row-level security. The application's encryption key is created on the first start and kept on the storage volume, with the data it protects.

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

`migrate` applies the new migrations and privileges before the application restarts. Every migration has its reverse (`php artisan migrate:rollback --database=owner`, in `migrate`).

## Checking an installation

`sh deploy/smoke-test.sh` builds the image and deploys it in a disposable project on ports 18080 and 18443, then checks HTTPS and HSTS, the restricted roles, the owner's password kept out of the running services, the files' storage, a school created and signed in to, the scheduler and the worker; it removes its project afterwards. CI runs it on every change.

## Not yet

Encrypted backups and their tested restoration (NFR-REC-01, NFR-REC-02) come with the next increment.
