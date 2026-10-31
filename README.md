# E-Madrassa

This repository holds two versions of the Madrassa school management application. They run side by side on the same PC, each with its own Docker Compose project and database.

| Port | Version | Folder | State |
|---|---|---|---|
| [http://localhost:8000](http://localhost:8000/admin) | First prototype | `ecole-filament/` + `madrassa-backend/` (root `compose.yaml`) | Most of the planned features, usable for demonstrations |
| [https://localhost:8443](https://localhost:8443/school) | Production version | `platform/` | Backend mostly done, with a focus on security; interfaces not all finished |

## The prototype (port 8000)

The first prototype covers most of the functionalities discussed: school setup, classes and groups, teachers and staff, enrolments and invoices, payments, attendance, grades and report cards, admissions and annual progression, payroll, the teacher portal, backups. It is a Laravel / Filament application with PostgreSQL 16 and Redis 7.

### Run it locally

Requirements: Docker Desktop (or Docker Engine with Compose).

From the repository root:

```bash
docker-compose up --build -d
docker-compose logs -f app
```

Create an administrator, then attach it to the deployment's school (the first run creates the school):

```bash
docker-compose exec app php artisan school:user direction@example.test
docker-compose exec app php artisan school:provision direction@example.test --name="Mon établissement"
```

Open <http://localhost:8000/admin> and sign in with that account.

Optional data for a fuller demonstration:

```bash
docker-compose exec app php artisan school:setup-2026
docker-compose exec app php artisan school:subjects-2026
docker-compose exec app php artisan school:teachers-2026
docker-compose exec app php artisan school:staff-2026
docker-compose exec app php artisan school:teacher-accounts
```

`school:teacher-accounts` writes the teachers' temporary passwords once to `storage/app/private/teacher-credentials-2026.csv` inside the container. Teachers sign in at <http://localhost:8000/enseignant/login>.

Stop with `docker-compose down` (data kept); `docker-compose down -v` also deletes the local database and files. See [DOCKER.md](DOCKER.md) for the details: school modules, Cloudflare tunnels for a demonstration from another PC, backups, separate deployments per school, browser tests.

### AI quiz generator (branch `quiz`)

The AI agent that creates quizzes is on the [`quiz`](https://github.com/yacine1605/E-Madrassa/tree/quiz) branch, not on `main`. It adds:

- `ai-service/`: a Python microservice (LangChain + Groq, `llama-3.3-70b-versatile`) that generates structured multiple-choice quizzes from a subject, a level and a topic;
- a Filament page in the prototype, **Pédagogie → Générateur de quiz IA** (`/admin/ai-quiz-generator`);
- `ecole-filament/seed-demo-data.php`: a mock data script for demonstrations.

To try it:

```bash
git checkout quiz
cd ai-service
pip install -r requirements.txt
cp .env.example .env      # then set GROQ_API_KEY (https://console.groq.com/keys)
python quiz_service.py    # listens on http://127.0.0.1:8001
```

In the prototype's environment, set `AI_QUIZ_URL=http://host.docker.internal:8001` (the app runs in Docker), rebuild with `docker-compose up --build -d`, and open the page. See `ai-service/README.md` on that branch.

## The production version (port 8443)

`platform/` is the version meant for production, rewritten from SRS 1.2. Its backend is mostly done and built around security: one database role per purpose, row-level security separating schools, passwords and MFA secrets readable by the sign-in role only, Argon2id hashing, compulsory MFA for the publisher's operators, HTTPS through Caddy, encrypted files storage, audit journal. The interfaces are not all done yet: some features exist in the API before they have a screen.

Start it (see [platform/DEPLOY.md](platform/DEPLOY.md)):

```bash
cd platform
sh deploy/new-env.sh                                  # writes deploy.env with fresh secrets, for https://localhost:8443
docker compose --env-file deploy.env up -d --build --wait
```

Create accounts and a school with the commands run in the `migrate` service:

```bash
docker compose --env-file deploy.env run --rm migrate php artisan platform:user operator@example.test --password="..." --mfa
docker compose --env-file deploy.env run --rm migrate php artisan platform:operator operator@example.test
docker compose --env-file deploy.env run --rm migrate php artisan platform:create-school ecole-test "École Test"
docker compose --env-file deploy.env run --rm migrate php artisan platform:user direction@example.test --password="..."
docker compose --env-file deploy.env run --rm migrate php artisan platform:membership ecole-test direction@example.test --role=tenant_admin
```

- School application: <https://localhost:8443/school> (the browser warns once about Caddy's local certificate).
- Publisher console: <https://localhost:8443/console>, for operators only.
- Roles: `tenant_admin`, `direction`, `academic_hr`, `supervisor`, `front_office`, `accountant`, `stock_manager`, `teacher`. The `student` and `parent` roles must be linked to a pupil or guardian record, so they are given from the school application once those exist.
- Known issue: in the console, the MFA setup dialog always answers « Le mot de passe est incorrect », because the user model never loads the password hash. Create operators with `--mfa` (as above) until it is fixed.

`deploy.env` holds every password of the installation: it is ignored by Git, keep it private. See [platform/README.md](platform/README.md) for the architecture and the tests.
