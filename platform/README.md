# Madrassa platform — new base (SRS 1.2, lot 1)

New schema base decided in ND-09 (SRS 1.2, §10.1): written from the SRS data model (§7), on the same stack (PHP 8.3 / Laravel 12, PostgreSQL 16), reusing the proven invariants of the current application in `madrassa-backend/` and `ecole-filament/`. That application stays usable until this one covers its scope.

The project rule applies: in each lot, a rule's invariant and its test come before any screen.

## Layout

| Path | Content |
|---|---|
| `database/schema/0NN_*.sql` | The schema, applied in order: the same files the migrations and the tests load |
| `deploy/roles.sql` | Database roles and privileges; run as administrator after the migrations, on every deployment |
| `tests/sql/database.mjs` | Invariant suite (embedded PostgreSQL); `tests/sql/concurrency.mjs` reruns it on PostgreSQL 16 with races |
| `app/`, `routes/`, `tests/Feature/` | Laravel 12 application: sign-in, school context, module guard, API; integration tests on PostgreSQL 16 |

## Database roles

| Role | Used by | Can |
|---|---|---|
| `school_runtime` | The schools' application (web requests, jobs) | Its own school's data only, through row-level security; no ownership, no `BYPASSRLS`, no deletion, no membership or audit writes |
| `platform_admin` | The publisher console | Platform data and functions only; **no privilege on the `school` schema**, so no school data without consent (FR-PLT-04) |
| `platform_provisioner` | Nobody: owns the `SECURITY DEFINER` functions | Exactly the writes those functions need; cannot log in |

The migration owner is used for migrations only. It must be a superuser or a member of `platform_provisioner`: a later migration may replace a function the provisioner owns (006 replaces `platform.create_tenant`).

## Adding a school table

1. Columns from §7.1: `id`, `tenant_id`, `created_at`, `created_by`, `updated_at`, `updated_by`, `version`, `archived_at`, plus `UNIQUE (tenant_id, id)` so other tables reference it with a composite key `(tenant_id, id)`.
2. `CALL school.protect('school.<table>')`: row-level security, read-only mode of an expired or archived school, §7.1 columns maintained by trigger, audit with old and new values.
3. Grants in `deploy/roles.sql`: column-level `UPDATE`, never `DELETE` on a business record (BR-02), never a code column (BR-18).
4. Its invariants in `tests/sql/database.mjs`, written before the screens; its API behaviour in `tests/Feature/`.
   A level, track, specialty or subject is a key to `school.ref_node` or `school.subject`, never text (BR-13); a table recording results of a school year checks `school.year_open()`.
5. Its routes declare an annex A permission (`permission:` middleware, checked by `PermissionCoverageTest`); the controller narrows to the grant's scope. Never test a role code.

## Run the tests

```bash
npm ci
npm test
# Concurrency, on an empty disposable database whose name ends in _test:
DATABASE_URL=postgres://user:password@localhost:5432/platform_test npm run test:concurrency

# Application: PHP 8.3 with pdo_pgsql, and the same kind of database (settings in phpunit.xml).
composer install
cp .env.example .env && php artisan key:generate
php vendor/bin/phpunit
```

## Provisioning

```bash
php artisan migrate --database=owner        # schema, as the migration owner
php artisan platform:roles                  # roles, privileges and passwords (DB_PASSWORD, DB_CONSOLE_PASSWORD)
php artisan platform:user direction@ecole.dz
php artisan platform:create-school ecole-a "École A" --plan=essential --admin=direction@ecole.dz   # profile private_school_dz; sends the activation link
php artisan platform:create-school centre-b "Centre B" --profile=training_center
php artisan platform:invite-admin ecole-a   # a new link, if the first one expired (72 h)
php artisan platform:apply-profile ecole-a private_school_dz   # a newer profile version, only on request: adds, never overwrites
```

The administrator opens the link, chooses a password (or, already known, simply accepts), then enables MFA, compulsory for privileged roles. `platform:membership` forces a membership's status, for development only.

Two processes run beside the web server: `php artisan queue:work` (background jobs, queued emails) and `php artisan schedule:work` (the notification outbox, every minute).

Web requests and jobs connect as `school_runtime`; the console as `platform_admin`; only migrations and these commands use the owner (`DB_OWNER_*`), which must not be set for the web server.

## Increment 1: what is covered

47 invariants on embedded PostgreSQL, 49 on PostgreSQL 16 (10 October 2026).

| SRS | Covered at the database level |
|---|---|
| FR-PLT-01 | School creation: one call, exactly one default site, invited administrator, idempotent (AC3, including concurrent); permanent slug; suspension closes the school (AC2); archiving is final; every change in the school's own journal |
| FR-PLT-02 | Plans and modules from annex C; dependencies enabled automatically and protected from disabling (AC1); read-only after expiry plus grace period (AC3) |
| FR-PLT-04 AC1 | The console has no privilege on school data |
| ARC-02, BR-01, NFR-TEN-01, TST-01 | Isolation between schools at the database level: reading, writing, audit, membership, context, rollback |
| ARC-03 | At least one site in use, including under concurrent archiving |
| ARC-04, TST-04 | Module state per school, readable by the application |
| §7.1, BR-02, BR-09, BR-18 | Common columns with optimistic lock, archiving instead of deletion, append-only audit with old and new values, permanent codes |

## Increment 2: application runtime (W1.1, W1.3)

19 integration tests on PostgreSQL 16 (10 October 2026), all under the restricted roles.

| SRS | Covered through the API |
|---|---|
| ARC-02, W1.1 | The school comes from the server session, chosen among the user's active memberships; never from the URL, a header or the body |
| ND-06 | A dedicated installation (`PLATFORM_DEDICATED_SCHOOL`) serves only its school |
| FR-PLT-01 AC2, FR-IAM-01 AC1 | A suspended school or membership ends the session at the next request; no sign-in without an open school |
| FR-PLT-02 AC2, AC3 | A disabled module answers 403 `module_disabled`; an expired school reads but answers 403 `tenant_read_only` to writes |
| TST-01 (API, jobs) | Sites isolated between schools; background jobs run in their school and recheck access when they run |
| NFR-TEN-03 | Cache and file keys prefixed per school |
| BR-09, NFR-UX-04 | Audit with author, reason, request identifier and address; database refusals become clear JSON errors, never SQL |
| NFR-SEC-02 | Passwords hashed with Argon2id |
| NFR-TEN-01 | Requests connect as `school_runtime` (no superuser, no `BYPASSRLS`), credentials read from the process environment |

## Increment 3: permissions and roles (W1.4, first part)

73 invariants on embedded PostgreSQL, 76 on PostgreSQL 16, 32 integration tests (10 October 2026).

| SRS | Covered |
|---|---|
| Annex A, FR-IAM-03 | Permission catalog per module, sensitive ones flagged; the 10 school roles of section 3.2 copied into each new school with their default permissions (`super_admin` is the publisher's: the console role) |
| FR-IAM-03 AC1, AC2 | A school changes its roles without touching another's; a user's permissions are the union of their roles valid today, for the modules the school has |
| FR-IAM-03 | Custom roles, empty or copied; only permissions of enabled modules are assignable; a role is archived, never deleted, and not while assigned |
| FR-IAM-04 | Assignment to the whole school, to some sites or to the person's own data; student and parent roles only the latter; sites of the school only; `site.site.manage` limited to its sites |
| FR-IAM-05 | No assignment to oneself, no change to a role one holds; `school.check_segregation()` for every approving table to come (entry ≠ approval, payment exception flagged `self_validated`, message naming the rule) |
| FR-IAM-01 AC2 | The last member able to manage assignments can be neither suspended nor deprived of the role, including under concurrent changes |
| BR-10, BR-09 | Assignments with start and end dates; every change audited with author and reason |
| TST-02, FR-IAM-03 AC3 | No role code in application code (static scan); every school route declares a permission (route scan) |

The database checks what the code must never get wrong (who may change roles, never on oneself, last administrator, scopes); routes declare their permission with the `permission:` middleware, and controllers narrow to the grant's scope through `App\Support\Permissions`.

Left for later, with their dependencies: scopes by level, group and subject come with the referential (W1.5); the notification to the direction when a sensitive permission is assigned comes with notifications (W1.6).

## Increment 4: accounts and authentication (W1.4, second part)

92 invariants on embedded PostgreSQL, 96 on PostgreSQL 16, 47 integration tests (10 October 2026).

A person (`users`) signs in once and may belong to several schools; each school holds its account of the person (`school.membership`: status, username, import reference). Schools create, change, invite and suspend accounts only through database functions that check the permission; the application never writes accounts, memberships or links directly.

| SRS | Covered |
|---|---|
| FR-IAM-01 | Account by activation link (72 h, single use, a new one replaces the previous) or provisional password changed at first sign-in; email unique per school, optional for pupils and parents, required for privileged roles; username unique per school; a person already known by email joins as they are, and no single school changes their name, email or password |
| FR-IAM-01 AC1, AC2, AC3 | Suspension with a reason closes access at the next request; never one's own account; the last administrator cannot be suspended; revoked is final; 500 accounts imported twice by `external_ref` without duplicate, refused rows reported |
| FR-IAM-01 | Sending fails: the administrator receives the link to hand over, only for a person who has no password yet |
| FR-IAM-02 AC1 | MFA (TOTP) compulsory before any access for whoever holds a sensitive permission in the school; 8 single-use recovery codes; a code is never accepted twice |
| FR-IAM-02 AC2 | After 5 consecutive failures, per account and per address, the next attempt waits; each new failure doubles the wait (1 min to 60 min); the same answer whether the account exists or not |
| FR-IAM-02 AC3 | Recovery link valid 1 hour, single use even under concurrent use; the same answer for known and unknown addresses |
| FR-IAM-02 | Passwords of 10 characters or more, Argon2id, common passwords, sequences and the person's own name refused; session ends after the school's inactivity delay (15 to 120 min, default 30) and after 12 hours |
| FR-REF-06 (base) | School settings: a catalog with type, bounds and permission; the school stores what it changes |
| BR-09, NFR-SEC-06 | Account changes, invitations and password resets in the school's journal; tokens stored as hashes, sent in the URL fragment, never logged |

Left for later: the full import pipeline (file, preview, rejection report, rollback) and queued mail come with the shared services (W1.6); security alerts (NFR-SEC-07) with the enriched audit (W1.6); turning MFA off or regenerating recovery codes, and changing one's own email, with the account screens.

## Increment 5: referential (W1.5)

113 invariants on embedded PostgreSQL, 117 on PostgreSQL 16, 55 integration tests (10 October 2026).

| SRS | Covered |
|---|---|
| FR-PLT-03 | Versioned profiles: `private_school_dz` (4 cycles, 15 levels, 14 tracks, 28 subjects, French and Arabic) and `training_center` (domain, specialty, pathway, modules); applied at creation; a published version never changes; a new version reaches a school only on request, adding without overwriting |
| FR-REF-01 | Types from the profile: renamed, optional ones turned off when unused, never created by the school; "Niveau" or "Spécialité", "Matières" or "Modules" from the same code |
| FR-REF-02 | Nodes: permanent codes unique per type; labels in French or Arabic, unique among active siblings regardless of case, spaces and Arabic diacritics; parent of the expected type; archived, never deleted, a parent only once its children are |
| FR-REF-03 | Subject catalog: unique labels, permanent codes; deleted while unused, archived otherwise (a role scope already counts as a use) |
| FR-REF-04 | Custom fields of students, guardians, staff and leads: types, select options added never removed, 20 active per entity, required fields; the check entity tables will call |
| FR-REF-05 | School years with one current at most; periods inside their year, never overlapping (exclusion constraint); a closed year final |
| FR-REF-06 | Settings: open days (Sunday to Thursday by default), first day of the week, languages, receipt contacts; time zone Africa/Algiers |
| FR-IAM-04 | Role scopes by level (covering what lies below) and by subject |
| FR-AUD-01 | A deletion keeps the deleted values in the journal; a profile application appears in the school's journal |

The subjects of `madrassa-backend/config/algerian_curriculum.php` were one per cycle; the catalog has one entry per subject (Arabic, Mathematics…), the hours of each level belonging to programs (FR-PRG-02, lot 2). Labels, Arabic ones especially, await the school's validation (OD-13).

Left for later: time slots and approval thresholds come with planning (lot 4) and finance (lot 3); the logo with file storage (W1.6); group scopes with groups (lot 2).

## Increment 6: shared services, part 1 (W1.6)

121 invariants on embedded PostgreSQL, 126 on PostgreSQL 16, 61 integration tests (10 October 2026).

| SRS | Covered |
|---|---|
| FR-AUD-01 | The journal is read with `audit.log.read` and, domain by domain, a permission of that domain (`platform.audit_domain`), enforced by row-level security: an administrator reads accounts, rights and settings, an accountant the referential and, later, finance; filters by person, object, action, domain and period; pages that hold while entries are added; no route changes an entry (AC2) |
| FR-JOB-01 | `school.job`: one job per idempotency key, states queued, running, succeeded, failed, cancelled with guarded transitions; progress; plain error messages; cancellation between steps; manual retry; "Mes tâches" shows the user's own jobs only; each step re-checks the school and the membership (AC1); a job resumed after a crash or delivered twice duplicates nothing (AC2) |
| FR-IMP-01 (base) | Account import as a background job, by batches of 100 committed one by one, with its report |
| FR-NOT-01 | Notifications (the bell), each person's own only (AC2); email preferences per event; the email outbox written in the same transaction, claimed by workers without overlap (`SKIP LOCKED`, lease), retried with growing delays, removed once sent, kept as a failure after five attempts, visible and sent again by the settings' manager (AC1); emails carry the title and the school only (BR-19) |
| FR-IAM-05 | Assigning a sensitive permission (by role or by adding it to an assigned role) notifies the holders of `audit.log.read` for the whole school, the author aside |
| NFR-SEC-06 | The recovery link is queued, encrypted in the queue, and the answer comes at once whether the address is known or not |

Left for the second part of W1.6: files (FR-FIC-01), the import engine with files, preview and rollback (FR-IMP-01), exports (FR-IMP-02).

### PDF (FR-PDF-01, TST-17)

`App\Support\Pdf` renders a view of `resources/views/pdf` with mPDF: DejaVu Sans and XB Riyaz embedded, Arabic letters joined and laid out right to left, the school's name and receipt contacts in the header, the reference and generation time fixed by the caller in the footer with the page numbers. `App\Support\Money` writes amounts from whole centimes with no-break spaces between digit groups, so that an Arabic line never shows "500,50 12". Tests read the documents back with poppler (`pdftotext`, `pdffonts`); the PHP `gd` extension is required by mPDF. A receipt and a list template are ready for finance (lot 3) and exports. Security alerts (NFR-SEC-07) come with the console's supervision (FR-PLT-05).

## Next increments of lot 1

1. **Shared services, part 2** (W1.6), **console** (W1.2), with Arabic and right-to-left from the first screen (W1.7).
2. **Deployment**: Docker image and Compose file for this application, on the model of the current one (restricted role at start-up, worker, encrypted backups).
