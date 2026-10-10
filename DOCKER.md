# Docker development environment

The root image combines `ecole-filament` with the files installed by `madrassa-backend/scripts/install.py`. Docker Compose starts that Laravel application with PostgreSQL 16 and Redis 7.

## Start the complete project

From the repository root:

```bash
docker-compose up --build -d
docker-compose logs -f app
```

If your Docker installation includes the Compose plugin, the equivalent command spelling is `docker compose`.

Open <http://localhost:8000/admin>. Create a local administrator interactively:

```bash
docker-compose exec app php artisan school:user direction@example.test
docker-compose exec app php artisan school:provision direction@example.test
```

Compose enables `SCHOOL_BACKEND=true` and disables the session demo. Each deployment belongs to one school, with its own PostgreSQL database, user accounts, Redis sessions and file storage. There is no establishment selector: login opens the deployment's school directly. Create a user and provision its membership using the commands above before logging in. The standalone frontend image retains the optional session demo.

Each school can choose its own feature package using `SCHOOL_PROFILE` or a custom `SCHOOL_MODULES` list in its environment file. See [school modules and deployment packages](MODULES.md) for profiles, dependencies, configuration commands and extension points.

To publish the web application with a Cloudflare managed tunnel, set `APP_URL` to the public HTTPS hostname and add `CLOUDFLARE_TUNNEL_TOKEN` to the root `.env` file. In Cloudflare Zero Trust, create a tunnel public hostname whose service is `http://app:80`. Start the tunnel with:

```powershell
docker-compose --profile cloudflare up -d cloudflared
```

Only `app` is exposed through the tunnel; PostgreSQL and Redis remain internal Docker services. Keep the tunnel token private. After changing `APP_URL`, recreate the app container:

```powershell
docker-compose up -d --force-recreate app
```

For a temporary free preview URL without a domain or token, start the quick tunnel and read the generated `trycloudflare.com` address from its logs:

```powershell
docker-compose --profile quick up -d cloudflared-quick
docker-compose logs -f cloudflared-quick
```

For a demonstration from a laptop at school, run `powershell -ExecutionPolicy Bypass -File .\Start-SchoolDemo.ps1` on the home PC. This creates a fresh temporary HTTPS link, updates `APP_URL`, enables secure session cookies, disables debug output and checks the public login page before printing the address. Sign in from the laptop with the existing school administrator account. Keep the home PC awake, connected to the internet, and Docker Desktop running throughout the visit. A tunnel restart can change the link; rerun the script to refresh it. Close internet access afterward with `docker-compose --profile quick stop cloudflared-quick`. The quick tunnel uses HTTP/2 to avoid the failed QUIC connection seen on this network.

Forms display French labels and related record names. Monetary inputs and tables use DA; the interface converts amounts to integer centimes for the API. Create campuses, periods, programs, levels, offerings and groups before enrolling students. Enrolling creates the invoice automatically; payments select that invoice. Attendance and grades can be corrected through their dedicated forms. Publication, enrollment status and work-log validation use the row action with a required reason. Payroll takes the first day of a month and uses the staff contract and validated work logs.

The administrative dashboard uses live, permission-scoped counts, cycle enrollment totals and upcoming sessions. Lists display readable relationships, status badges and paginated totals; on mobile, records appear as cards. `node tests/backend-design.cjs` runs CRUD and desktop/mobile presentation checks in a disposable Docker Compose project, and saves screenshots under `tests/artifacts`.

For the local 2026/2027 school already configured with `school:setup-2026` and `school:subjects-2026`, run `docker-compose exec app php artisan school:teachers-2026`. It creates 29 teachers with Arabic names and their class/subject assignments: one in each preschool class and Arabic, French, and English in each primary class. The command is safe to rerun and keeps existing assignments. View the assignments under **Enseignants** or **Classes et groupes**.

For primary levels, an Arabic qualification (`PRI-AR-LECT`) also qualifies the teacher for the other subjects in that level's curriculum, except French, English, Spanish and sport. These four subjects require an explicit qualification. The rule applies to teacher choices and validation when creating assignments or sessions; qualifications remain scoped to a level, and class assignments still control teacher portal access.

Run `docker-compose exec app php artisan school:staff-2026` to add the nine initial staff members with Arabic names and visible roles: four kitchen chefs, two workers, and three secretaries. It is safe to rerun and refuses to overwrite an existing staff code with different details.

Run `docker-compose exec app php artisan school:teacher-accounts` to create local login accounts for active teachers. Each account is linked to `school.staff.user_id` and has an active membership with no administrative permissions. Unique temporary passwords are written once to `storage/app/private/teacher-credentials-2026.csv` inside the application container; a private copy can be kept in the project's gitignored storage directory.

Teachers log in at <http://localhost:8000/enseignant/login>. The dedicated portal shows their assigned classes, enrolled student names and matricules, and their own seven-day schedule in Algeria time. Teachers can change their password under **Mon compte**; the initial credential export is not updated. Active membership and an active linked teacher record are checked on each request. Teacher accounts with no back-office permissions are redirected from `/admin` to this portal. Student contact information, finance and global school APIs remain unavailable to these accounts. Run `node tests/teacher-portal.cjs` after building the image to verify the portal in a disposable database on port 18183.

Under **Notes**, teachers create a **Devoir** or **Examen** for an assigned class/subject, choose a date and mark scale (20 by default), then enter scores for enrolled students. Blank scores mean ungraded; zero, absence and exemption are distinct. Grades are saved per page of 25 students. Corrections require a reason; stale forms cannot overwrite newer grades. Published evaluations are read-only. Every read and write checks the teacher's current class/subject assignment and the student's enrollment. New evaluations and marks also appear in the administrative assessment and grade screens. Attendance entry remains outside the teacher portal.

Admissions and annual progression are available under **Scolarité → Admissions** and **Passage annuel**. Applications are reviewed before conversion into a student/enrollment/invoice. Annual decisions create new enrollments for promotion or repetition, preserving prior years. See `madrassa-backend/docs/admissions-progression.md`.

Interventions, settings, user administration, optimization and the student portal do not yet have backend endpoints. Their pages explicitly report this and do not display demo data. General record editing/deletion is not offered where the backend has no corresponding operation.

The local `school:provision` command creates this deployment's school on the first run. Later runs add administrators to that same school, without creating another school. Existing memberships and permissions are retained (including disabled memberships). On the first run, you may customize the school name and type:

```bash
docker-compose exec app php artisan school:provision direction@example.test --name="Mon établissement" --kind=mixed
```

Web requests and background jobs never use the PostgreSQL owner. On every start the app container runs migrations as the owner, then creates or updates the restricted `school_runtime` role (no table ownership, no `BYPASSRLS`, no deletion of business records) from `madrassa-backend/deploy/runtime-grants.sql`, and starts Apache and the queue worker under that role. Row-level security therefore still separates schools if application code has a bug. Set its password with `DB_RUNTIME_PASSWORD`; the local default is for development only. Commands run with `docker-compose exec app php artisan ...` keep the owner account, which provisioning commands such as `school:provision` need.

The `worker` service runs `php artisan queue:work` on Redis for background jobs, so long operations never run inside a web request. Failed jobs are kept in `storage/framework/cache/failed-jobs.json`.

The Compose defaults (`APP_ENV=local`, no TLS between containers, development passwords) are still for local use. For production, set every password, `APP_DEBUG=false` and HTTPS.

Stop the services while retaining data with `docker-compose down`. To also delete the local PostgreSQL, Redis, and application-storage volumes, run `docker-compose down -v`.

## Automatic backups and recovery

Start with `docker-compose --profile backup up --build -d` to include daily backups at 03:00 Algeria time. Set `BACKUP_PASSPHRASE` (12 characters or more) first: each backup is encrypted with it (GnuPG, AES-256), because it contains personal data and the school's encryption key. Store the passphrase apart from the backups; without it no restore is possible. Backups made before encryption remain restorable. The service retains 14 verified database/file backups in `backups/madrassa`, including the persistent application encryption key. It briefly puts the application in maintenance during each backup and catches up missed runs when Docker restarts. Restore into a new deployment, never over the existing school. See [backup and restoration instructions](madrassa-backend/docs/backups.md). Run `node tests/backups.cjs` for an isolated recovery test.

## Separate deployments for separate schools

Copy `school.env.example` to `school-a.env` and `school-b.env`. Give each file a unique `COMPOSE_PROJECT_NAME`, `APP_PORT`, database name and password, and set `APP_URL` to match its address. For example use `school-a` / port `8001` and `school-b` / port `8002`. Keep each project name stable: it identifies that school's persistent volumes.

```bash
docker-compose --env-file school-a.env up --build -d
docker-compose --env-file school-a.env exec app php artisan school:user direction@school-a.test
docker-compose --env-file school-a.env exec app php artisan school:provision direction@school-a.test --name="School A"

docker-compose --env-file school-b.env up --build -d
docker-compose --env-file school-b.env exec app php artisan school:user direction@school-b.test
docker-compose --env-file school-b.env exec app php artisan school:provision direction@school-b.test --name="School B"
```

Open each school's own URL and sign in using that school's account. Database and Redis ports stay private to each Compose network. No central user account or browser selection switches between databases. API routes still carry a school UUID for compatibility, but the server rejects any UUID other than the database's fixed school, even if an extra membership exists. The `/school-api/school` endpoint returns only that school.

The migration adopts an existing database when it contains exactly one school, preserving its accounts and records. If an existing database contains multiple schools it stops explicitly; those records must be split into separate databases before migration. Changing `DB_DATABASE` does not rename or move an existing PostgreSQL volume: select it when creating a new deployment.

## Browser integration verification

With Docker and Microsoft Edge installed, first build the default application image, then run the isolated tests:

```bash
npm install --prefix .test-tools
docker-compose build app
node tests/dedicated-schools.cjs
```

The browser suites start their own temporary projects from the images `madrassa-app:latest` and `madrassa-backup:latest`. To test other images without retagging the ones a school runs on, set `TEST_APP_IMAGE` and `TEST_BACKUP_IMAGE`.

This test creates two temporary Compose projects on ports 18181 and 18182. It verifies the browser workflows, removal of the selector, automatic school selection, separate records and credentials, session isolation and rejection of cross-school URLs. It removes only those temporary projects and volumes afterward. The fixture refuses to run in the normal application database.
