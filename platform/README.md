# Madrassa platform — new base (SRS 1.2, lots 1–2)

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
| `platform_auth` | The sign-in, the person's own password and MFA (`App\Support\Credentials`) | Password hashes, MFA secrets and recovery codes, which no other role reads; nothing else |
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
php artisan platform:operator support@editeur.dz   # access to the publisher's console, /console (--revoke to withdraw)
php artisan filament:assets                         # the console's styles and scripts, on every deployment
```

The administrator opens the link, chooses a password (or, already known, simply accepts), then enables MFA, compulsory for privileged roles. `platform:membership` forces a membership's status, for development only.

Two processes run beside the web server: `php artisan queue:work` (background jobs, queued emails) and `php artisan schedule:work` (the notification outbox and the prospects' due reminders, `platform:lead-reminders`, every minute; daily, the purge of expired files and each school's retention policy, `platform:retention`).

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


### Exports (FR-IMP-02)

`POST /api/exports` with a kind (`config/platform.php`, `exports`: an `App\Exports\Dataset`), a format (CSV, XLSX, PDF), filters and a language. An export needs `export.job.create` and the permission that reads the data on screen; a column may require a permission of its own, left out otherwise (AC1: no salary column for whoever cannot see salaries). Cells that a spreadsheet would read as formulas become text (AC2). CSV in UTF-8 with its mark and semicolons; Excel right-to-left in Arabic; PDF through the list template. Up to 2 000 rows the file is ready at once, beyond a background job makes it and notifies its author. Each export is logged (`school.export_log`: author, kind, filters, columns, rows, file), read by its author and by the journal's readers; its file is the author's and leaves storage after seven days.

### Files (FR-FIC-01)

`school.file_object` registers every file: category of rights (`platform.file_category`: finance attachments, student documents and photos, staff documents, teaching resources, exports and imports), real type read from the content, size, SHA-256, owner record, expiry. The database shows and accepts a file only with its category's permission, an export or an import only to its author (AC2); it applies the school's types and size (`files.types`, `files.max_mb`; by default PDF, JPEG, PNG, DOCX, XLSX, 10 MB) and its storage quota, also under concurrent uploads; an expired school still exports and adds nothing else. The bytes are on the `files` disk under `tenant/{id}/{category}/`: S3-compatible storage in production (`FILES_DRIVER=s3`, MinIO or a provider), a local directory in development. An executable renamed `.pdf` is refused (AC1); downloads are attachments the browser does not interpret; a signed link opens one file for 15 minutes without a session (AC3). `php artisan platform:purge-files`, daily, removes expired files from storage.

### PDF (FR-PDF-01, TST-17)

`App\Support\Pdf` renders a view of `resources/views/pdf` with mPDF: DejaVu Sans and XB Riyaz embedded, Arabic letters joined and laid out right to left, the school's name and receipt contacts in the header, the reference and generation time fixed by the caller in the footer with the page numbers. `App\Support\Money` writes amounts from whole centimes with no-break spaces between digit groups, so that an Arabic line never shows "500,50 12". Tests read the documents back with poppler (`pdftotext`, `pdffonts`); the PHP `gd` extension is required by mPDF. A receipt and a list template are ready for finance (lot 3) and exports. Security alerts (NFR-SEC-07) come with the console's supervision (FR-PLT-05).

### Imports (FR-IMP-01, TST-14)

`GET /api/imports/templates/{kind}` gives the template (CSV or Excel, the columns' codes on the first line); `POST /api/imports` keeps the file (category `import`) and previews it in the background: every row is validated, then applied for real in a transaction that is rolled back, so the preview finds every refusal, the database's included, and writes no business data (AC3, the "reprise à blanc"). Each refused row has its line and cause (AC2), also as a downloadable report; references are found by code, never created. `POST /api/imports/{id}/confirm` applies the valid rows by batches of 100, each committed on its own; rows are found by their stable reference, so the same file imported again duplicates nothing (AC1). `POST /api/imports/{id}/rollback` undoes a complete import, last row first, all or nothing, and refuses while something done since depends on it; an imported account is removed only if nobody has used it (`school.discard_account`). Kinds: `accounts` and `referential` (`config/platform.php`, `imports`: an `App\Imports\Importer`); pupils, guardians and enrolments since increment 37; staff, payments and the rest join with their modules.

A refusal that must undo what the request already wrote is thrown as `App\Exceptions\Refusal`, never as `HttpResponseException`, which the router answers on the spot without rolling the transaction back.

## Increment 11: the publisher's console (W1.2, first part)

139 invariants on PostgreSQL 16, 90 integration tests (11 October 2026). The user interface is Filament 5; the console is its panel at `/console`.

| SRS | Covered |
|---|---|
| FR-PLT-01 | A school created from one form (name, permanent slug, profile, plan, language, first administrator's email, subscription): its site, profile, modules and invited administrator (AC1); sent twice, one school (AC3); suspended, reactivated, archived with a reason, recorded in the school's journal with the operator as author; a suspended school closes (AC2); invitation sent again, or its link shown when sending fails |
| FR-PLT-02, FR-PLT-03 | Modules (dependencies kept), subscription and quotas, a newer profile version applied on request |
| FR-PLT-05 | Supervision: per school, modules, active accounts, storage against quota, jobs, failed emails, last change; counts only, no school data |
| FR-IAM-02 | Operators (`platform.operator`, the `super_admin`) sign in with compulsory MFA (Filament's, on the platform's own secret and recovery codes), progressive lockout, session limits |
| FR-PLT-04 AC1 | The console reads through `platform_admin`, which has no privilege on school data |

## Increment 12: support access (FR-PLT-04)

145 invariants on PostgreSQL 16, 97 integration tests (11 October 2026).

| SRS | Covered |
|---|---|
| FR-PLT-04 | The school consents (`POST /api/support-grants`, permission `support.access.grant`, the administrator and the direction by default): a reason, 72 hours at most, sensitive data included or not; revoked at any time; every consent listed with its author and its consultations (AC3) |
| AC1 | Without a valid consent the operator reaches nothing: the database itself checks the consent named by the session (`app.support_grant`), its school, its dates, that it is not revoked, and that the actor is an active operator |
| AC2 | Expired or revoked, the consent ends the support session at the next request (`support_ended`); the database's clock decides |
| Read-only | A support session reads the school's modules (`*.read` permissions), never manages roles, and writes nothing: the transaction is read-only and every school table refuses writes (`support_read_only`), exports included |
| Sensitive data | Salaries, financial attachments and the other sensitive data of annex A need the distinct consent |
| Journal | Each request of a support session, refused ones included, is a `SUPPORT_READ` entry in the school's journal, with the operator as author |

The operator opens a support session from the console (action "Accès support" on a school, which lists the school's consents) or through `POST /api/support/session`; `DELETE /api/support/session` closes it. The console still reads no school data itself; only `platform.support_grants()` tells it the consents.

`support.access.grant` is not in annex A; FR-PLT-04 needs it, since the code asks for a permission and never for a role (P4).

At deployment, `php artisan filament:assets` publishes Filament's assets.

## Increment 13: languages (W1.7, first part)

152 invariants on PostgreSQL 16, 110 integration tests (11 October 2026).

| SRS | Covered |
|---|---|
| FR-I18-01 | Each person's language (`PUT /api/account/locale`; none follows the school) if the school offers it (`locale.languages`), else the school's, else French; the database resolves it (`platform.locale_for`) for requests, background jobs and emails. Before sign-in, the browser's preference. `GET /api/me` gives the language in use and its direction; every answer carries `Content-Language` |
| NFR-I18N-01 | Every message comes from the catalogs `lang/fr` and `lang/ar`: the database's refusals, the session's, validation, emails, the console, the framework's own errors (404, 419, 429, 500). Filament turns right to left in Arabic; its French catalog is completed in `lang/vendor` |
| AC2 | `school.search_key`: alif and hamza forms, alif maqsura, ta marbuta folded, diacritics and tatweel dropped, Latin case and accents ignored, Arabic-Indic digits read as digits. `GET /api/accounts?q=احمد` finds « أحمد » |
| AC3, TST-17 | French shown as a pseudo-language (every catalog string marked): the API's messages, the emails and the console's pages read back carry no text outside the catalogs. The code and the views are read for texts too, French and Arabic compared key by key with the same placeholders, Filament's catalogs checked complete, the screens' styles checked for logical directions (start, end) only |
| Formats | Gregorian dates, day first, in Algiers time; « 12 500,00 DA » and « 12 500,00 د.ج »; Arabic-Indic digits in Arabic on the school's request (`locale.arabic_digits`) |
| BR-18 | A new school's default site is named in French and Arabic; a site, like every label, needs one of the two |

English is accepted by the database but not offered yet: it arrives with its catalog (`Locales::AVAILABLE`). A background job writes its messages in the language of the person who started it. The console's changes are recorded in the school's journal with a reason in the school's language. Label uniqueness (008) dropped Arabic-Indic digits along with the diacritics: « فوج ١ » and « فوج ٢ » are now two labels.

Left for the second part of W1.7: the school's screens in Filament. Names in Arabic and in Latin letters come with the people they belong to (students, guardians, staff: lot 2).

## Increment 14: the school's accounts and rights (W1.7, second part)

The school panel is at `/school/login`. It uses the existing email/username login, MFA/recovery-code challenge and progressive lockout. People belonging to several schools choose one from their memberships; a dedicated installation keeps its configured school. `/school/credentials` provides provisional-password replacement, MFA enrolment and the person's language before school access.

| SRS | Covered |
|---|---|
| FR-IAM-01 | Paginated account list, normalized Arabic/Latin search, status filter, invitation, provisional password, identity changes, suspension/reactivation/revocation with a reason; self-suspension and the last-administrator rule remain enforced |
| FR-IAM-03, FR-IAM-04 | School roles, copies, labels, active-module permissions, archival; assignments with school/site/referential/subject/personal scope and validity dates; self-assignment and self-elevation refused |
| FR-PLT-02, FR-PLT-04 | Expired schools and support sessions show a read-only banner and offer no writes; consent revocation ends access on the next update and returns the operator to the console |
| NFR-TEN-02, NFR-SEC-04 | Permissions and membership are checked again on Livewire updates; a tab opened in another school is refused. The entire update holds the restricted SQL transaction through hydration, actions and rendering; handled write refusals roll back to a savepoint |
| FR-I18-01, NFR-I18N-01 | French/Arabic catalogs, Arabic RTL and pseudo-localization checks for the new screens |

Forms call the same controllers and SQL functions as the API. Account pagination is opt-in (`paginate=1`, `per_page` up to 100); existing API consumers keep their response shape. Permission checks use permission codes, never role names. Empty roles and roles without permissions in enabled modules are excluded from assignment/copy choices.

Validation on 11 October 2026: 152 PostgreSQL 16 checks including concurrency, and the full PHP regression suite (124 tests, 2,745 assertions). See the [SRS 1.2 gap report](../RAPPORT_ECART_SRS_1_2.md) for the remaining requirements. Invitation activation and forgotten-password completion remain API flows; their public browser forms are still to build. MFA enrolment currently shows the secret for manual entry in an authenticator. Browser/mobile acceptance and visual RTL checks remain pending.

## Increment 15: academic reference data and calendar (W1.7, continued)

Five school pages extend the same permission-checked panel:

| Path | Behavior |
|---|---|
| `/school/reference-types` | Profile-defined types, their labels and optional activation; codes and hierarchy stay fixed (FR-REF-01) |
| `/school/reference-nodes` | Nodes by type and archival status; compatible active parents, French or Arabic labels, ordering, immutable codes and reasoned archival from children upwards (FR-REF-02) |
| `/school/subjects` | Subjects for a school, training modules for a centre; descriptions, ordering, unused-entry deletion, archival and history (FR-REF-03) |
| `/school/years` | Planned/current/closed years, dates, one current year, final closure with a reason and a link to the year's periods (FR-REF-05) |
| `/school/periods` | Periods filtered by year and archival status; dates within the year, inclusive non-overlap, ordering and archival; closed-year periods remain readable |

Every page requires `referential.node.read`. Changes require `referential.node.manage`, or `subject.subject.manage` for the subject catalog, for the whole school. Support and expired-school sessions remain read-only. Forms reuse the existing controllers, including optimistic versions and database refusals isolated by savepoints. Lists use the database's Arabic/Latin search normalization; catalogs are paginated for display but still fetched in full through the existing APIs. Large-catalog server pagination remains pending.

`GET /api/years?include_archived=1` additionally includes archived periods for history; the default response still includes active periods only. No schema change or migration is needed. French and Arabic catalogs, permission coverage and pseudo-localization checks include all five pages. `SchoolReferencePanelTest` exercises stale edits, foreign parent/record IDs, school switching, revoked management rights, duplicate labels, subject usage, period boundaries and closure, including real HTTP Livewire submissions.

Validation on 11 October 2026: the full PHP/PostgreSQL 16 suite passes with 134 tests and 3,144 assertions, without a local `.env` file. All 146 embedded SQL checks pass. The 152 native SQL/concurrency checks were last run in increment 14; this increment changes no SQL schema.

The SRS source document is unchanged. Custom fields, school settings and the remaining shared-service screens are still to implement; browser/mobile acceptance and visual RTL checks remain pending.

## Increment 16: the school's administration (W1.7, continued)

The panel's menu now groups its pages: people and rights, referential, administration.

| Path | SRS | Behavior |
|---|---|---|
| `/school/settings` | FR-REF-06 | Each setting with its value and default, edited with the field its type calls for (number within bounds, switch, text, days, languages, file types); the database still checks every value. Requires `tenant.settings.manage` |
| `/school/custom-fields` | FR-REF-04 | Fields of students, guardians, staff and leads: permanent key and type, French or Arabic labels, list values added but never removed, obligation, sensitivity, order, archival with a reason, stale edits refused |
| `/school/support-access` | FR-PLT-04 | The school's consents: granted for 72 hours at most, sensitive data apart, withdrawn at any time, each with its consultations and a link to them in the journal. Readable with `support.access.grant` or `audit.log.read`; an operator under consent sees it read-only |
| `/school/journal` | FR-AUD-01 | Newest first, by numbered pages; filters by domain, object, action, period and object; each entry's detail with the fields changed, before and after, reason, request and address. Row-level security still decides which domains each reader sees |

`GET /api/audit` also takes `page` (numbered pages) beside its cursor; `GET /api/settings` gives each setting's `allowed_values`. Custom field list values are stored as a list whatever keys a form gives them.

BR-18: modules, plans, permissions, role templates and settings were labelled in French only. Migration 017 adds their Arabic labels, and the same label to each school's roles copied from a template and not renamed since; an SQL check keeps every platform catalog in both languages.

Validation on 11 October 2026: 153 native PostgreSQL 16 checks including concurrency, 147 embedded checks, the full PHP suite (140 tests, 3,747 assertions); migration 017 rolls back and applies again.

## Increment 17: tasks, notifications, imports and exports on screen (W1.7, continued)

| Path | SRS | Behavior |
|---|---|---|
| `/school/tasks` | FR-JOB-01 | The person's own background jobs, refreshed while open: progress, message, cancellation, retry after failure, the file of a finished export |
| `/school/notifications` | FR-NOT-01 | The person's notifications, the unread ones counted on the menu entry; marked read one by one or all at once; which events also come by email |
| `/school/deliveries` | FR-NOT-01 AC1 | Emails that failed five times, with their reason, sent again on request. Requires `tenant.settings.manage` |
| `/school/imports` | FR-IMP-01 | Templates, a file previewed without writing anything, its rejected rows with their cause and the report, confirmation, undo. Each kind of data needs the permission that creates it on screen |
| `/school/exports` | FR-IMP-02 | CSV, Excel or PDF of what the person may read on screen, in the language chosen, with its filters; ready at once, or in the background beyond 2,000 rows; each file downloadable by its author for 7 days |

Tasks and notifications need no permission: row-level security shows each person their own. A read-only school or a support session still changes nothing there; an operator under consent follows the imports without starting one. `GET /api/imports` now counts each import's rows (in all, valid, rejected); a file chosen on screen reaches the import controller as an upload, exactly as through the API.

Validation on 11 October 2026: the full PHP/PostgreSQL 16 suite (145 tests, 4,142 assertions) and the 147 embedded SQL checks; no schema change.

## Increment 18: account links in the browser, and sites (W1.7, continued)

| Path | SRS | Behavior |
|---|---|---|
| `/school/activation` | FR-IAM-01 | An invitation link opens the account: the first password, or acceptance by a person who already has one; then sign-in |
| `/school/recovery` | FR-IAM-02 | Linked from the sign-in page: the email asked for (the same answer whether or not it has an account), then the link opens the choice of a new password |
| `/school/sites` | FR-SLL-01 | Sites named in French or Arabic, archived with a reason; the last one in use stays. Only with the `sites_rooms` module: without it, neither menu entry nor page (R09) |

Links now point to the panel: `PLATFORM_FRONT_URL` defaults to `APP_URL/school`. The token still travels in the fragment, which never reaches a server: the page reads it, takes it out of the address bar and history, and checks it with the API's controller. These pages before sign-in have the same limits as the API's public routes (60 a minute per address; recovery: 10 an hour per address, 3 per email). Module routes are checked again on every Livewire update, like permissions.

Validation on 11 October 2026: the full PHP/PostgreSQL 16 suite (149 tests, 4,282 assertions) and the 147 embedded SQL checks; no schema change.

## Increment 19: deployment (Docker Compose)

`Dockerfile`, `compose.yaml` and [DEPLOY.md](DEPLOY.md): one Compose project per installation.

| SRS | Covered |
|---|---|
| NFR-SEC-01 | Caddy alone faces the network: HTTPS for every connection, HSTS, certificates from Let's Encrypt or its own authority for `localhost`; secure session cookies; the application trusts the proxy's headers for the client's address and scheme |
| P4, NFR-TEN-01 | A one-shot `migrate` service runs the migrations as the database owner, then `deploy/roles.sql` and the roles' passwords; requests and jobs connect as `school_runtime`, the console as `platform_admin`. The owner's password never reaches the running services |
| FR-FIC-01, ARC-07 | The schools' files in Garage, S3-compatible, on its own volume, with an access key allowed on the files' bucket only (`platform:storage`); any other S3 provider by configuration. MinIO no longer publishes public images |
| FR-JOB-01, FR-NOT-01 | A queue worker, and a scheduler for the email outbox and the purge of expired files |
| NFR-PORT-01 | PHP 8.3 and Apache, PostgreSQL 16, Redis 7; the encryption key created on the first start and kept with the data on the storage volume |

`deploy/new-env.sh` writes a deployment's settings with fresh secrets; `deploy/smoke-test.sh` deploys the image in a disposable project and checks HTTPS and HSTS, the restricted roles, the owner's password kept out of the running services, the files' storage, a school created and signed in to, the scheduler and the worker. CI runs it on every change.

## Increment 20: backups and restoration

| SRS | Covered |
|---|---|
| NFR-REC-01 | With `--profile backup`, every day at 3:00 Algeria time: the database, the files' bucket and the encryption key, 14 kept; the application in maintenance for the few seconds of the copy; a health check that fails after 26 hours without success |
| NFR-SEC-09, NFR-DATA-05 | Each archive encrypted (GnuPG, AES-256) with a passphrase kept apart; checksums before and after encryption, the row count of every table and the size of the files in a manifest; `verify` decrypts and checks a backup |
| NFR-REC-02 | Restoration into a fresh installation only, refused over existing data: the bucket prepared, the roles recreated, the database restored in one transaction and every table counted, the files counted, the key put back; the new installation keeps its own passwords |

`deploy/restore-test.sh` backs up one installation and restores it into another; the school, its administrator's sign-in, a file and the key come back. CI runs it with the smoke test.

Fixed on the way: `platform:invite-admin` passed the school's name where `AccountLinks::send` expects the language since increment 13, so the command's invitations failed; it now invites exactly as the console does, and the smoke test checks the email leaves.

## Increment 21: every screen in a browser

`deploy/acceptance-test.sh` deploys a disposable installation, gives it a school whose administrator and a console operator have MFA (`platform:user --mfa`), signs both in through their real pages, then opens every screen (the school's 20 pages, the console's 3, the 4 pages before sign-in) in French and Arabic, on a desktop and a phone: 108 screens. Each one must load, read right to left in Arabic, overflow nothing sideways, show no raw catalog key and raise no script error. A screenshot of each is kept in `.acceptance/` (in CI, the `platform-screens` artifact) for a person to review.

| SRS | Covered |
|---|---|
| FR-I18-01 AC1, NFR-UX-02 | Every lot 1 screen usable in Arabic, right to left, on a desktop and a phone |
| NFR-COMP-01 | Chromium: Chrome in CI, Edge locally (`BROWSER_CHANNEL=msedge`); Firefox and Safari still to run |
| FR-IAM-02 | The school's sign-in with MFA, as a person goes through it |

Found and fixed on the way: after the password, the school's sign-in page kept its first fields and never showed the code's until reloaded (the form is built once per request); it now comes again with the code's field. Lists use the language's comma (« ، » in Arabic), the first day of the week is named and chosen as a day, long settings wrap instead of pushing their action off the screen, and the forgotten password link stands on its own line.

## Increment 22: lists by pages (NFR-PERF-04)

The lists that grow with use come by pages of 25 by default and 100 at most, with their total, when the caller asks for it (`paginate=1`, `page`, `per_page`); without it, the whole list as before (`App\Support\Paging`). They are the accounts, the referential's nodes, the role assignments, the support consents, the imports, the exports, the tasks, the notifications and the emails not sent. The nodes and the assignments are also searched by the database, however a word is written (`q`), and each node names its parent. The school's screens ask for one page at a time: none loads a whole list any more.

The small catalogs (types, subjects, years, roles, custom fields, sites, settings, permissions) stay whole: a school has a few dozen of each.

## Increment 23: exit of lot 1

The lot's walkthroughs run as tests, each step through the API or the screens a person uses, under the restricted roles (`LotOneWalkthroughTest`): two schools created from the console, activated and run end to end without ever meeting (TST-01); R01, an account invited, given a role, suspended and recovered, its exports bound by the same rights as its screens; R09, a module turned off leaving neither menu nor API, and its data back when turned on; R10, a school and a centre on the same code, each with its own referential.

The permissions' security review is in [SECURITY-REVIEW.md](SECURITY-REVIEW.md): the layers a request crosses, the access matrix of every route and screen (`php artisan platform:access-matrix`, which `PermissionCoverageTest` keeps equal to the review's copy), what the tests keep true for later lots, and the findings. One was fixed here: every function of the schemas was executable by everyone, so a school's application could call the publisher's functions; `deploy/roles.sql` now takes that back on every deployment, and the SQL suite checks it. CI also audits the PHP dependencies (`composer audit`).

## Increment 24: Redis behind its password, and every browser

Redis asks for a password (`REDIS_PASSWORD`, written by `deploy/new-env.sh`), and the database, Redis and the storage are on an internal network that only the application's services join: the proxy cannot reach them, and they cannot reach the internet (finding F5 of [SECURITY-REVIEW.md](SECURITY-REVIEW.md)). `deploy/smoke-test.sh` checks both. An existing `deploy.env` needs the new password (DEPLOY.md, Updates).

Every screen is now checked in Chromium, Firefox and WebKit, Safari's engine (finding F6): `BROWSERS=chromium,firefox,webkit sh deploy/acceptance-test.sh`; CI runs the three side by side, each with its screenshots. All 324 screens pass, 108 in each browser.

| Requirement | Evidence |
|---|---|
| NFR-COMP-01 | Chromium (Chrome in CI, Edge locally), Firefox and WebKit: every screen in French and Arabic, desktop and phone |
| NFR-TEN-01 | The data services on an internal network, Redis behind its password, checked by the smoke test |

The smoke and acceptance tests also run from Git Bash on Windows, in a folder whose name holds `&`: their files stay in the project's folder, and Playwright is called without `npx`.

The screens' font, Inter, has no Arabic letters: Arabic is shown in the system's font, which differs between browsers (a serif in WebKit on Windows). Whether to ship an Arabic font is for the review of the Arabic labels.

## Increment 25 (lot 2): credentials apart

The first increment of lot 2 closes findings F2 and F3 of [SECURITY-REVIEW.md](SECURITY-REVIEW.md). A fourth database role, `platform_auth`, is the only one that reads or writes the credentials: password hashes, MFA secrets, the last TOTP step, recovery codes (`018_credentials.sql`). The schools' application, `school_runtime`, sees a person's name, email, language, and whether they have a password and MFA: an SQL injection in a school's screen reads no credential and writes none directly (F7 says what it could still do). The application uses the new role through `App\Support\Credentials` only: the sign-in, the MFA challenge, the person's own password and MFA, and the console's sign-in (`CredentialsUserProvider`). The `User` model reads only the columns `school_runtime` may read. "Remember me" is gone from the console: a session ends at its time limits.

Deployments need `DB_AUTH_PASSWORD` in their `deploy.env` (DEPLOY.md, Updates); `deploy/new-env.sh` writes it.

The review also gained F7: row-level security learns the school and the person from settings that any SQL of `school_runtime` can set, so it stops a query that forgets its school, not an SQL injection. Its fix, a context the database checks, comes later in lot 2.

## Increment 26 (lot 2): Content Security Policy

Every response carries a Content Security Policy (finding F4 of [SECURITY-REVIEW.md](SECURITY-REVIEW.md); `App\Http\Middleware\ContentSecurityPolicy`). A script runs only from the platform's files, or inline with the response's nonce, which Livewire and Filament put on theirs; images, fonts, connections, frames and forms stay on the platform; no other site may frame it. `'unsafe-eval'` remains, for Alpine's expressions and Livewire's `@script` blocks, and `'unsafe-inline'` for styles. `PLATFORM_CSP_REPORT_ONLY=true` reports without blocking (DEPLOY.md).

The browser acceptance now fails a screen on anything the policy blocks, in Chromium, Firefox and WebKit. Its first run found that Filament's avatars came from ui-avatars.com, which learned the initials of whoever was signed in on every page (finding F8): the initials are now drawn by the platform (`App\Support\InitialsAvatar`).

| Requirement | Evidence |
|---|---|
| NFR-SEC-03 | `ContentSecurityPolicyTest`: a fresh nonce on every page, borne by every inline script; nothing loaded from another site. The acceptance: nothing blocked on 324 screens. The smoke test: the header on the deployment |

## Increment 27 (lot 2): a signed request context

Finding F7 of [SECURITY-REVIEW.md](SECURITY-REVIEW.md) is closed. The database learnt a request's school and person from two settings that any SQL of `school_runtime` could set; it now believes only a context the application signs (`019_signed_context.sql`, `App\Support\DatabaseContext`). The context names the school, the person, the support consent, the request and its address, with an HMAC-SHA256 under a key derived from the application's key; `php artisan platform:roles` gives the database that key at every deployment, in a `secret` schema that only the definer functions' owner reaches. `school.context()` refuses a context forged, altered, signed for another connection or before its transaction began; `school.tenant()`, `school.actor()`, the consent in use and the journal's author, request and address read it. An SQL injection can no longer name another school or person, nor replay another request's context.

A check of the signature costs about 13 µs, where reading a setting cost nothing, so the cost was measured before keeping it (`tests/sql/context-cost.mjs`). Every policy, and the functions that read the context for each row, now read it once per query; a school's own membership is read with the provisioner's rights, without the permission checks of the membership's policies. The measure also found what the old policies cost on large tables: each row called `school.allowed()`, whose reading of the membership ran its permission checks again, so a page of 25 subjects among 25 000, with its count, took 80 seconds.

| Measure, PostgreSQL 16, two schools of 25 000 subjects | Before | Now |
|---|---|---|
| A request that only enters its school | 1.6 ms | 1.6 ms |
| Its start, as `SchoolContext` checks the school and the person | 6.5 ms | 5.3 ms |
| `school.actor()` called for each of 10 000 rows | 3.2 ms | 125 ms |
| A page of 25 subjects among 25 000, and their count | 80 s | 5.0 ms |
| The journal's first page, and its count | 7.4 ms | 8.0 ms |
| 500 subjects written, stamped and journaled | 849 ms | 58 ms |

| Requirement | Evidence |
|---|---|
| NFR-SEC-04 | SQL suite, Security review: forged, altered and replayed contexts refused; the settings of lot 1 open nothing; no role of the application reads the key; every policy reads the context once per query. `DatabaseContextTest`: the application's signature believed, another connection's refused |
| NFR-PERF-03 | `tests/sql/context-cost.mjs`: a page among 25 000 rows in milliseconds; the SQL suite bounds the checks a request makes |

Deployments need nothing new: the key comes from the application's (DEPLOY.md). A connection pooler in transaction mode cannot sit between the application and the database, since a context is signed for one connection.

## Increment 28 (lot 2): people apart

The rest of finding F2 is closed. A school's application read every person's name and email, in every school; row-level security now confines `public.users` (`020_people.sql`). It reads the person of the request and, in the school the request reaches, its members, whatever their state, and the publisher's operators, whom the school's journal names (`school.people`). Outside a school it reads the person alone; without a context, nobody. A person changes their own language only; an administrator changes the others' accounts through the school's functions, as before.

The person signed in is read before any school, in a context of their own that the application rolls back at once, so that it never replaces the context around it (`DatabaseContext::reading`). The user provider reads that way every person it loads (`CredentialsUserProvider`), the console's sign-in finds the person by email through `platform.find_login`, and the recovery's email takes the person's language from `platform.issue_recovery`. The sign-in's role, `platform_auth`, still reads the credentials of whoever signs in.

Each person is checked by an index as their row is read. On one school of 25 000 people, a page of 25 with its count takes 84 ms instead of 32 (`tests/sql/context-cost.mjs`); NFR-PERF-01 allows 800 ms.

| Requirement | Evidence |
|---|---|
| NFR-SEC-04, NFR-TEN-01 | SQL suite, Security review: the people of the school and the operators, the person alone outside a school, nobody without a context, one's own language only. `SchoolIsolationTest`: another school's person and a stranger unseen, the accounts' screen unchanged. `ConsoleTest`: the console's sign-in finds the operator by email |

## Increment 29 (lot 2): `'unsafe-eval'` tried away, and kept

The rest of finding F4 of [SECURITY-REVIEW.md](SECURITY-REVIEW.md) was tried: Livewire's CSP build of Alpine, which parses expressions instead of evaluating them, under a policy without `'unsafe-eval'`. `PLATFORM_CSP_STRICT=true` serves both (`config/livewire.php`, `config/platform.php`); it is a trial, never for production. In Chromium, 104 of the 108 screens fail as they load, all on Filament 5.10's own markup: statements, `new`, globals and arrow functions in its Alpine attributes, and the `@script` block it adds to every signed-in page, which the parser cannot read. `'unsafe-eval'` therefore stays; after each upgrade of Filament, `PLATFORM_CSP_STRICT=true sh deploy/acceptance-test.sh` tells whether it still must.

The platform's own markup is ready for that build: the account link's logic moved from an attribute into an `Alpine.data` component. What keeps `'unsafe-eval'` harmless is now tested for the code to come: no view or class writes raw HTML, and the sanitizer behind Filament's notifications drops every attribute Alpine or Livewire reads (`ContentSecurityPolicyTest`).

| Requirement | Evidence |
|---|---|
| NFR-SEC-03 | `ContentSecurityPolicyTest`: the trial serves the CSP build without `'unsafe-eval'`; no raw HTML, no Alpine attribute through the sanitizer. The acceptance: 108 screens without a problem; 104 failing under the trial, every one on Filament's markup |

## Increment 30 (lot 2): pupils, guardians, programmes, groups and enrolments

`021_pupils_and_enrolments.sql` supplies the first school-domain schema; the migration and the SQL suite load the same file. It adds no API or screen yet.

| Requirement | Database behaviour and evidence in `tests/sql/database.mjs` |
|---|---|
| FR-ELV-01/02/03 | A pupil's Arabic or Latin name, immutable matricule, dates, custom values and statuses; shared guardians and links. Generated matricules skip imported ones, serialize concurrent creation and refuse an exhausted five-digit sequence. A minor needs a primary contact with a phone to enrol (`student.majority_age`, initially 19) |
| FR-PRG-01, BR-11, TST-18 | A programme references a school year and the school's referential. Each price change creates an immutable version. Enrolments copy the agreed price and terms, which cannot be edited after a catalogue change |
| FR-GRP-01/02, BR-07, TST-07 | A group's capacity is locked before a place is counted and taken; 30 simultaneous requests fill exactly 25 places. Capacity cannot fall below current membership. One main group per enrolment; `school.move_to_group` keeps dated history and rolls back the source change if the target refuses |
| FR-INS-01/02/03, TST-08 | `school.enroll(student, program, idempotency_key)` returns the same enrolment after simultaneous duplicate submissions, and refuses a key reused for different input. Teaching-state transitions need their own permission and scope. Cancellation freezes the record and frees its places; a concurrent assignment cannot leave a cancelled enrolment occupying a place |
| FR-PLT-02 | The quota counts distinct pupils with a non-cancelled enrolment in a year that is not closed. Concurrent requests cannot both take its last place; another programme for an already-counted pupil takes no new place |
| FR-IAM-04, TST-03 | Restrictive resource policies intersect tenant isolation with each action's site, level (including descendants), group or personal scope, including history and core-domain audit entries. Personal scopes use provisioned pupil/guardian account links; students cannot read classmates, and unlinking a guardian removes access immediately. Role managers can assign valid group scopes without reading the roster |

The application cannot edit matricule counters, price versions, agreed terms or state history directly. School-domain audit entries mask names, contact details and custom values. Definer functions read the complete membership when applying capacity and quota rules, independently of the caller's narrower visibility; the resource policies compute scoped IDs once per query.

Validation on 12 October 2026: **183 embedded SQL checks, 194 native PostgreSQL 16 checks with concurrency, 168 PHP integration tests (4,747 assertions)**, plus migration, restricted grants, rollback and reapplication on a disposable database. Browser/deployment acceptance remains the evidence of increment 29; no new screens were introduced here.

Remaining limits: unlinked record creation currently requires a tenant-scoped permission. Subject scopes remain closed for these records until programme-subject links exist; staff will provide implicit teacher/replacement scopes. Personal account links are reserved to provisioning, with no portal exposed. Photos, field-level sensitive-data filtering, duplicate detection, API/UI workflows, CRM, rooms, staff, programme subjects, programme transfers, annual progression and payment-based access are not delivered by this schema. Financial state, discounts and invoices come with lot 3.

## Increment 31 (lot 2): pupil and guardian APIs

`StudentController`, `GuardianController` and `PupilRecords` expose 15 routes under the school session, CSRF protection, `students_crm` module and each action's permission. The database still enforces tenant and resource scopes. New unlinked dossiers and changes to family links require a tenant-wide grant; a scoped user can read and update permitted dossiers.

| Routes | Contract |
|---|---|
| `GET /api/students`, `GET /api/guardians` | Always paginated: 25 by default, at most 100; `page`, `per_page`, normalized `q`, `include_archived`. Pupils also accept `birth_date` and `status` filters |
| `GET /api/students/{id}`, `GET /api/guardians/{id}` | An explicit set of dossier fields; inaccessible or foreign IDs return 404; provisioning account links and photo references are not exposed |
| `POST /api/students`, `POST /api/guardians` | At least one complete Arabic or Latin name; pupils require a non-future birth date, with generated or imported immutable matricule |
| `PUT /api/students/{id}`, `PUT /api/guardians/{id}` | Partial changes with required `version`; stale edits return 409. `custom` patches supplied keys, preserving omitted values |
| `POST /api/students/duplicates` | Up to ten scoped suggestions by normalized name plus birth date, or normalized phone. Same-date records in different alphabets are broad warnings, without transliteration or a name similarity score |
| `POST /api/students/{id}/status` | `status`, `reason`, `version`; blocking with non-cancelled enrolments first returns 409 `student_active_enrollments` and their count, then requires `confirm_enrollments: true`. Enrolments and group places are unchanged |
| `POST /api/students/{id}/archive` | Only a former pupil, with `reason` and `version`; retention timing remains dependent on OD-07 |
| `GET/POST /api/students/{id}/guardians` | Read links or attach an existing guardian with relationship and contact/payer/emergency flags; one guardian can serve siblings |
| `PUT /api/students/{id}/guardians/{link}`, `POST .../{link}/archive` | Versioned link metadata; archiving needs a reason and retains history, available with `include_archived=1` |

A creation that finds probable duplicates returns 409 `student_duplicate` with those suggestions. Retrying with `duplicate_reason` records the reason in the journal. The check and creation share a school-specific transaction lock. This is a warning mechanism, never an automatic merge; cross-alphabet suggestions need refinement before FR-ELV-04 is complete.

Sensitive custom values, including those with archived definitions, are omitted without `student.sensitive.read` on that particular resource, and attempts to write them are refused. An ordinary partial edit preserves hidden values. Permission and definition caches belong to the request, including when a controller survives multiple requests. Database triggers validate custom value types; impossible calendar dates are rejected before SQL. Errors have French and Arabic messages.

`022_pupil_admission.sql` supplies the narrowly scoped count needed to confirm a block: the updater can learn the number of non-cancelled enrolments even without enrolment read access, but no programme or price details. It checks the pupil update permission and its scope itself. The pupil row lock serializes the status change with enrolment creation.

`PupilRecordsTest` exercises the real routes with restricted database roles: duplicate override audit, shared guardians and link history, versions, localized status refusals, pagination, school/module boundaries, action-specific group scopes, sensitive fields and permission changes between requests, and blocking without changing enrolments. The test database reset also drops its private `secret` schema, allowing consecutive suite runs against the same disposable database.

Validation on 12 October 2026: **177 PHP integration tests (4,959 assertions), 183 embedded SQL checks and 194 native PostgreSQL 16 checks including concurrency**. Migration 022, restricted grants, rollback and reapplication were checked on a disposable database. Browser/deployment checks were not rerun for this API-only increment.

Screens, photos, aggregated dossier history, pupil imports and programme/enrolment/group APIs remain to be delivered. The primary contact is checked when enrolling a minor; maintaining it after later contact/link edits remains open. The access matrix in `SECURITY-REVIEW.md` includes the new routes.

## Increment 32 (lot 2): programme, enrolment and group APIs

Twenty additional routes expose the school-domain rules of schema 021 under the school session, CSRF protection, the `enrollment_groups` module and each action's permission. `AcademicRecords` shares tenant filtering, pagination, resource lookup and action-scope checks. Database triggers and functions enforce capacity, eligibility, immutable terms and history; no new migration is needed.

| Routes | Contract |
|---|---|
| `GET/POST /api/programs`, `GET/PUT /api/programs/{id}` | Year, referential node, immutable code, FR/AR labels, description, paired duration/unit, base price, payment terms and status. Updates are partial and require `version`; year, node, code and price-version changes by the caller are refused |
| `GET /api/programs/{id}/prices` | Paginated, immutable price/terms history, latest version first |
| `GET/POST /api/groups`, `GET/PUT /api/groups/{id}` | Year, site, programme (required for class/session), labels, capacity, dates and status. Updates require `version`; identity fields stay fixed. Closing keeps the composition and makes the group immutable |
| `GET/POST /api/enrollments`, `GET /api/enrollments/{id}` | Creation takes only `student_id`, `program_id`, `idempotency_key`; SQL fixes pending status, year, price, version and terms. A successful retry returns 201 with the same resource; key reuse with different input returns 409 |
| `POST /api/enrollments/{id}/validate`, `/suspend`, `/cancel` | Each route requires its own permission plus `version` and `reason`. Validation also resumes a suspended enrolment. Cancellation is final, releases places and performs no financial operation |
| `GET /api/enrollments/{id}/history` | Paginated state history with author, dates and suspension/cancellation reasons |
| `GET/POST /api/groups/{id}/memberships` | Paginated current composition, `include_history=1` for ended memberships, optional `enrollment_id` filter. Creation takes an enrolment and optional `valid_from`; SQL derives whether this is the main group |
| `POST /api/group-memberships/{id}/end`, `/transfer` | Required `version` and audited `reason`; transfer also takes the destination `group_id`. A refused destination rolls back the source change; success returns the new `data` and ended `previous` membership |

Every list paginates (25 by default, 100 at most), including price/state histories and composition. Programmes and groups accept normalized `q` on code and translated labels. Programmes filter by year, node and status; groups by year, programme, site, type and status; enrolments by pupil, programme, year and teaching status. Inactive programmes are excluded from an admission selector by requesting `status=active`.

Send a programme's `base_price` as a decimal **string**, for example `"120000.50"`. The API refuses numeric JSON values, scientific notation, negative amounts, more than two decimal places and values beyond NUMERIC(14,2), rather than rounding them. Prices in resources and the enrolment snapshot's `base_price` are also strings, including `"999999999999.99"`; the database keeps the original numeric snapshot unchanged. `payment_terms` is an opaque list of at most 100 entries for now, with a JSON encoding depth limit of 12 to keep the enclosing snapshot readable; excessive nesting returns 422. Generating and validating financial instalments belongs to lot 3.

Create the minor's dossier and primary guardian contact first, create an active programme and group, then `POST /api/enrollments` with a stable UUID key. Assign its result through `POST /api/groups/{group}/memberships`, then validate it with `version: 1` and a reason. `AcademicWorkflowTest` runs this complete API walkthrough. It also tests price precision and snapshot stability, repeated submissions, stale versions, capacity, transfer rollback/history, suspension/resumption/cancellation, scoped actions/rosters/history, foreign references, module guards and localized domain refusals.

Creation and group transfers still require tenant-wide grants under schema 021. Existing records can be changed within each action's resource scope. A group-scoped cancellation returns its authorised result directly because ending the memberships may immediately remove the caller's subsequent read access. Inaccessible reads and rows excluded by a mutation's policies answer 404; a missing action permission answers 403. The access matrix includes all new routes.

Validation on 12 October 2026: **185 PHP integration tests (5,303 assertions), 183 embedded SQL checks and 194 native PostgreSQL 16 checks including concurrency**, with 26 focused API/permission/localization tests (3,073 assertions) also passing after the final payment-terms nesting validation. No new migration or browser/deployment acceptance was needed for this API increment.

The next step is the screen workflow. Programme subjects/volumes, teacher/room links, planning conflicts, composition notifications, programme-to-programme transfers, financial instalments, payment-based access and annual progression are still open. No new screen or browser acceptance is claimed by this increment.

## Increment 33 (lot 2): what a restricted scope costs

Before the screens, the cost of the resource policies of increments 30 to 32 was measured for readers limited to a site, a level, some groups or their own record (`tests/sql/scope-cost.mjs`: one school, each pupil with a guardian, an enrolment and a class, on two sites; medians, round trips included). It grew with the school rather than with the scope: each policy computed the reader's permissions twice, then joined every pupil, enrolment, membership and group of the school before keeping those in scope, and compared each row with every visible ID in turn.

`023_scope_cost.sql` keeps the rules and changes how they are computed. `school.core_ids` starts from the scope: the groups and sites, the levels with their descendants, the reader's own pupils, and only what they reach, by index. The policies compare each row with those IDs through a hash built once per query. `school.effective_permissions` is written in PL/pgSQL, whose plans last from one call to the next: 0.8 ms instead of 1.8, for every permission check of every request.

| Pupils' list, count and first page | Whole school | One site | Two classes | One level | Own record |
|---|---|---|---|---|---|
| 2 000 pupils, before | 6.1 ms | 22.8 ms | 20.5 ms | 21.9 ms | 20.6 ms |
| 2 000 pupils, now | 3.2 ms | 6.5 ms | 5.2 ms | 5.6 ms | 5.1 ms |
| 10 000 pupils, before | 6.5 ms | 101 ms | 36.6 ms | 68.6 ms | 37.4 ms |
| 10 000 pupils, now | 4.0 ms | 21.1 ms | 9.3 ms | 19.2 ms | 6.8 ms |

A single pupil now takes 1.4 to 3.2 ms for every reader of a school of 2 000 (2.8 to 11.4 before), and the enrolments' list of a site reader among 10 000 pupils 22 ms instead of 158. Each reader sees exactly the same rows as before. The SQL suite (183 embedded, 194 native checks), the 185 PHP tests and the migration's rollback pass unchanged.

| Requirement | Evidence |
|---|---|
| NFR-PERF-01, NFR-PERF-03 | `tests/sql/scope-cost.mjs [platform folder] [pupils]`: the reference school of 2 000 pupils under 9 ms for every list of every reader; 10 000 pupils measured too |

## Increment 34 (lot 2): the programmes' and groups' screens

The school panel has a new section, *Scolarité*, with the first two of lot 2's screens; each calls the API of increment 32 inside the panel's school transaction, so the same permissions, triggers and refusals apply.

- **Programmes** (`program.program.read`, module `enrollment_groups`): the year's offer by level of the referential, searched in either alphabet, filtered by year, level and state; price and duration as they are read (« 120 000,00 DA », « 9 mois »). A manager creates a programme for an open year and changes its labels, description, duration, price and state; a new price is a new version, whose history the row shows, and a closed year hides the changes. The payment terms' model waits for lot 3's finance.
- **Groups and classes** (`group.group.read`): type, programme, site, year, dates, state, and the places taken against the capacity (« 22 / 25 »). A class or a session names its programme; a group goes from draft to open to closed, never back; fewer places than members is refused with its message, and a closed group keeps its members and is no longer changed.

A record's action now shows to whoever may perform it on that record, not only to whole-school grants: `SchoolScreen::writableFor` reads the reader's scope once per list (`school.core_ids`), so a manager of one site edits that site's groups and no other, and the database decides again on writing. The groups' API gives each group its programme's code and its current members, counted among the memberships the reader may see. Measured with `tests/sql/scope-cost.mjs` (new line), the groups' list stays at 6 to 10 ms for every reader of a school of 2 000 pupils.

`platform:membership --role=…` gives an account whole-school roles, once; the browser acceptance gives its school's administrator `academic_hr`, so the two new screens are checked in French and Arabic, desktop and phone, in each browser.

| Requirement | Evidence |
|---|---|
| FR-PRG-01, BR-11 | `SchoolingPanelTest`: creation with Arabic labels and search, refused duration without unit and price with three decimals, a new price as version 2 with its history, stale version refused, nothing changed once the year is closed |
| FR-GRP-01 | `SchoolingPanelTest`: a class without programme refused, a support group without one, places shown, capacity under the members refused, closing keeps the members |
| FR-IAM-04, BR-01 | `SchoolingPanelTest`: pages by permission and module; a site-scoped manager sees and edits their site's groups only |
| NFR-I18N-01 | `PseudoLocalizationTest` with both pages; acceptance in three browsers |

## Increment 35 (lot 2): pupils and their guardians on screen

- **Pupils** (`student.record.read`, module `students_crm`): searched by name in either alphabet, matricule or phone, filtered by state and birth date, archived records on request; each row opens the pupil's sheet. A new pupil gets a matricule, or keeps one taken from another register. Before writing, the screen asks the API for probable duplicates (the same name and birth date, the same birth date written in the other alphabet, the same phone): the existing record opens, or the new one is made with a reason, which the journal keeps.
- **The pupil's sheet**: identity and the school's own fields (a sensitive one only for whoever may read it), the state and the reason of a block; editing, a change of state with its reason, the archive of a former pupil. Blocking first says how many enrolments are not cancelled, and leaves them as they are. Its guardians: link an existing one (brothers and sisters share one record) or create one with the link, together or not at all; change the link (relationship, primary contact, payer, emergency contact), the guardian, or remove the link with a reason. A former pupil's sheet is read only.
- **Guardians** (`guardian.record.read`): list, creation, edits.

A record's actions follow the reader's scope: a secretary of one site edits the pupils of that site, and reads no other. Creating records and linking guardians stay with whole-school grants, as in the API (increment 31). The guardians' API gives each link the guardian's name and phone, as the reader may see them.

What people typed (a phone, an address, an e-mail) keeps its own direction within an Arabic line (`Formats::isolated`, Unicode isolates): « 0550 12 34 56 » no longer comes out as « 56 34 12 0550 ».

A fix for every school screen: a list made of the API's rows kept, for the render following an action, the rows it had read to find the action's record, so an archived node or a removed link stayed on screen until the next request. `SchoolPage::rendering` reads them again.

The browser acceptance now creates, through the API, a year, a programme, a class and a pupil with their guardian: the lists have rows, and the pupil's sheet is checked as well (128 screens in each browser).

| Requirement | Evidence |
|---|---|
| FR-ELV-01, FR-ELV-03 | `SchoolingPanelTest`: matricule given, imported one kept and unique, incomplete name and future birth date refused; the sheet's edits; a block that warns of the enrolment and leaves it pending; a former pupil read only, then archived |
| FR-ELV-04 | `SchoolingPanelTest`: the same birth date in the other alphabet warns, nothing is written without a reason, the reason is in the journal; search in Arabic and by matricule |
| FR-ELV-02 | `SchoolingPanelTest`: one guardian for two children, the same link twice refused, the link and the guardian changed, the link removed with its reason |
| FR-IAM-04, BR-01 | `SchoolingPanelTest`: a site-scoped secretary sees and edits their site's pupils only; a pupil of another school, or out of scope, is not found; an administrator without pupil rights is refused |

## Increment 36 (lot 2): enrolments and groups' members, the walkthrough through the screens

- **Enrolments** (`enrollment.enrollment.read`): the pupil, the programme and the price of the enrolment's day (BR-11), the year, the main class, the state; searched by the pupil's name or matricule, filtered by state, year and pupil. **Enrol** offers active programmes only (FR-PRG-01 AC2) and, if the reader may place pupils, the programme's open classes with their places: the enrolment and its class are made together or not at all. The form's key is made when it opens, so the same form sent twice makes one enrolment, and the second answer is the first one, not a refusal (TST-08, FR-INS-01 AC1). Each row: place in a group, validate, suspend, cancel (a reason each time, by whoever may on that enrolment), and its history: the states with their reasons, the groups with their dates.
- **A group's members** (from Groups and classes): current members, or every one since the start; add an enrolment of the year (of the programme, for a class or a session), transfer to another group (today's membership ends and the next begins, both kept, FR-GRP-02 AC3), remove with a reason. The group's places are shown above.
- **The pupil's sheet** shows the enrolments with their main class, and enrols the pupil while they are active.

The history of an enrolment's states now keeps every reason, a validation's included: a validated enrolment holds no reason of its own, so `024_enrollment_history_reason.sql` takes the one the request gave. The enrolments' and memberships' APIs give each row its pupil as the reader may see them, and the main class; `GET /api/enrollments/{id}/groups` lists an enrolment's groups with their dates. With them, the enrolments' list of a school of 2 000 pupils takes 6.2 ms for the whole school, 12.4 ms for one site and 9.0 ms for a pupil's own record (`tests/sql/scope-cost.mjs`, which now lists them as the API does), 2 to 5 ms more than without.

`LotTwoWalkthroughTest` goes through lot 2 with the screens only: a year, a programme and two classes; a minor refused without a primary contact, then enrolled in class A once their father is linked; validated, transferred to class B, its history showing both classes and the validation's reason; cancelled, frozen, its place given back. The browser acceptance adds the enrolments and a class's members (136 screens in each browser).

| Requirement | Evidence |
|---|---|
| FR-INS-01, TST-08 | `LotTwoWalkthroughTest`: enrolment and class together, price of the day; the same form sent twice gives one enrolment and two successes; inactive and draft programmes not offered |
| FR-INS-02, FR-INS-03, BR-12 | validation, cancellation from the list; history with each reason (tests/sql: the validation's reason kept); a cancelled enrolment offers no action and frees its place |
| FR-GRP-02, BR-07 | members listed, transfer keeping both memberships, history with dates |
| FR-ELV-01, FR-ELV-02 AC2 | the sheet shows the enrolment and its class; a minor without a primary contact is refused |

## Increment 37 (lot 2): the 500 reference pupils, imported

Three kinds join the imports (`config/platform.php`), to bring in a school's pupils with their data (lot 2 exit, R02), in this order:

| Kind | A row | Found again by |
|---|---|---|
| `students` | A pupil: names in Arabic and/or Latin letters, birth date (`2012-03-14` or `14/03/2012`), place, gender (`m`/`f`), phone, email, address, school of origin, the school's own fields as `custom_<key>` columns; a former `matricule`, or none for one of the year | `external_ref`, the reference of the school's file (SQL 025) |
| `guardians` | A guardian and their link to one pupil: names, phone, email, profession, own fields, `relationship` (`father`, `mother`, `guardian`, `other`), `is_primary_contact`, `is_payer`, `emergency_contact` (`oui`/`non`, `1`/`0`, `yes`/`no`, `نعم`/`لا`). Brothers and sisters: one row each, the same guardian reference | the guardian's `external_ref` and the pupil's `student_ref` (the pupils' file reference, or the matricule) |
| `enrollments` | A pupil in a programme of a year, by codes (`year`, `program`), with their class (`group`), `pending` or `validated` | the pupil, the year and the programme |

Each row goes through what the screens apply: names, dates and duplicates checked by the application; permissions, scopes, a minor's primary contact, quota, a class's places, an active programme, the price of the day, by the database. A probable duplicate (same name and birth date, in either alphabet, FR-ELV-04) is refused with the existing matricule, unless the row's `duplicate_reason` says why to create it anyway, which the journal keeps. A column the file leaves out keeps the record's value; an empty cell clears it. An existing enrolment is left as it is: its changes are made on screen. Each kind needs the permission that creates its records for the whole school, and its module.

Undone, an import puts back what it changed and deletes what it created, last row first, all or nothing: an enrolment with its class and history, then a guardian's link and the guardian once no pupil is linked to them, then a pupil without guardian or enrolment. It refuses while something depends on it, including a correction typed on a record since; so a school undoes its enrolments, then its guardians, then its pupils. The deletion goes through functions owned by `platform_provisioner`, for what an import still in place reports having created only (`025_pupil_imports.sql`, SECURITY-REVIEW.md).

| Requirement | Evidence |
|---|---|
| FR-IMP-01 AC1–AC3, TST-14, lot 2 exit | `PupilImportTest`: 500 pupils previewed without writing, imported, imported again without a duplicate; 250 guardians for 500 pupils; 500 enrolments validated in two classes, a pupil found by their former matricule; each refused line with its cause; an update undone, imports undone in order, the school's own and sensitive columns |
| FR-ELV-01, FR-ELV-04 AC1 | former matricules kept, the others generated once each; a duplicate in the other alphabet refused, a reason journaled |
| FR-ELV-02 AC1, AC2, FR-INS-01, BR-07 | one guardian for brothers and sisters; a minor without a primary contact and a full class refused, row by row |
| BR-02, BR-05 | `tests/sql/database.mjs`: a reference given once; an undo deletes only what an import still in place created, while nothing depends on it |

The 500 pupils, their guardians and enrolments, previewed, imported and partly imported again, take about a minute in the test environment (about 4 000 rows applied, 16 ms each, round trips to the database included); an import runs in the background. The history of an enrolment made and validated at once now lists its creation first.

## Increment 38 (lot 2): the pupil's photo

A pupil may have a photo; nothing depends on it (FR-ELV-01 AC0): a pupil is created, enrolled and imported without one, and the sheet then shows their initials, drawn by the platform.

| Route | Contract |
|---|---|
| `POST /api/students/{id}/photo` (`student.record.update`) | Multipart `photo`, `version`, and `square` to keep the image's centred square, as the screen proposes. JPEG, PNG or WebP by its content (the name's extension must agree), 2 MB at most, 16 million pixels at most, read from the file's header before anything is decoded. The image is drawn again: turned upright as the camera's EXIF orientation says, reduced to 1 024 pixels at most, with a square thumbnail of 128, written as JPEG with nothing of the sent file but its pixels (no EXIF, no location; transparency becomes white). Answers the pupil, `has_photo: true` |
| `DELETE /api/students/{id}/photo` (`student.record.update`) | `version`; the photo is removed |
| `GET /api/students/{id}/photo` (`student.record.read`), `?size=thumb` | The image, inline, `nosniff`, `no-store`, to whoever may read the pupil; 404 without a photo, out of scope, or while the school has photos turned off |

`026_student_photos.sql` holds the rules. A pupil's photo is two live files of the category `student_photo`, made for that pupil, or nothing; any other file is refused. Replaced or removed, the previous images are retired in the same transaction, archived and due, and their bytes deleted once the change is committed; the daily purge (`platform:purge-files`) deletes what that could not (BR-17: the old image is not kept). A photo file is seen, added or changed only with the pupil it shows in the reader's scope; it is never given a signed link, and the generic file routes neither take nor serve it. The journal records that the photo changed and masks both file references. A former pupil's photo stays as it is. The setting `student.photo.enabled` (on by default) turns photos off: they are hidden and no new one is taken, nothing is deleted. Bytes stored for a change that is then refused are removed when it rolls back, for every kind of file.

On the sheet, the photo's thumbnail (or the initials) heads the identity; a *Photo* menu adds, changes or removes it, for whoever may update the pupil. The upload has no preview: FilePond draws it in a worker made from a `blob:` URL, which the Content Security Policy refuses.

The platform image's GD now reads WebP (`--with-webp`, `deploy/smoke-test.sh` checks JPEG, PNG and WebP). The browser acceptance gives its pupil a photo, drawn in the page and sent as a form would, and now fails any screen where an image does not load.

| Requirement | Evidence |
|---|---|
| FR-ELV-01 AC0 | `StudentPhotoTest`: a pupil without a photo; a PDF, a text or a GIF refused whatever their name; 2 MB and 16 million pixels at most; the served image has neither the EXIF block nor the place the camera wrote, is upright and reduced, its thumbnail 128 × 128; WebP and PNG accepted |
| FR-ELV-01 (photo rules), BR-17 | `StudentPhotoTest`: the previous images deleted from storage and marked purged on replacement and removal; a refused change leaves no bytes; the journal masks the files; photos turned off are hidden, kept, and back when turned on. `tests/sql/database.mjs`: two images of this pupil or nothing, retired at once and due, journal without file references |
| FR-IAM-04, TST-03 | `StudentPhotoTest`, `tests/sql/database.mjs`: a reader limited to a group or a site sees and changes the photo of its pupils only; a pupil sees their own; no signed link; another school sees none |
| FR-ELV-01 (screen) | `SchoolingPanelTest`: initials, then the photo added from the sheet, a PDF refused, the photo removed and its files deleted, no photo action while photos are off |

Validation on 12 October 2026: **209 PHP integration tests (6,728 assertions), 190 embedded SQL checks and 201 native PostgreSQL 16 checks including concurrency**; migration 026 rolled back with the others and alone with photos in place, then applied again; the browser acceptance in Chromium, 136 screens without a problem, the pupil's sheet showing their photo. The whole PHPUnit suite, run in one process, now passes 128 MB: `phpunit.xml` gives it 512 MB.

## Increment 39 (lot 2): duplicates across alphabets, and guardians typed again

`NameMatch` decides whether two names are probably one (FR-ELV-04, NFR-I18N-03), on rows the database has already filtered by the reader's scope:

- **In the same alphabet**, the name as the search reads it (`school.search_key`, ported letter for letter and checked against the database): case, accents, Arabic diacritics and letter forms, spaces and the order of the two fields aside. « HÉLÈNE Benali » is « Benali Helene »; « Amine » is not « Amina », so twins are not taken for one child.
- **Across alphabets**, when the two records share none: both names come down to their consonants by sound, in the same symbols (ح and ه and h are H, ج and dj are J, ش and ch are C, ق, ك, k, q and g are K; vowels, ʿayn, hamza and tāʾ marbūṭa unwritten; the article ال and « el » dropped; a doubled letter once). « Mohamed Amine » and « محمد أمين » are M H M D, M N. Each part must share 75 % of its consonants (« Abdelkader », « عبد القادر »), or a compound name must hold the other (« Amine », « محمد أمين »).

| Who | Probable duplicate | Where |
|---|---|---|
| A pupil | The same phone; or the same birth date and the same name, in either alphabet. Each suggestion says why (`match`: `phone`, `name`, `other_alphabet`). Another child born the same day in the other alphabet is no longer one | `POST /api/students/duplicates`, creation (409 `student_duplicate`), the Pupils screen, the import |
| A guardian | The same name, and the same phone or e-mail. The mother on the household's phone is another person | `POST /api/guardians/duplicates` (`guardian.record.read`), creation (409 `guardian_duplicate` with the suggestions, unless `duplicate_reason`, journaled), the Guardians screen, the pupil's sheet, the import |

On the pupil's sheet, a new guardian who is already recorded (a brother's father, in either alphabet) is proposed: link them, or create another record with a reason (FR-ELV-02 AC1). The guardians' import refuses a guardian recorded under another reference, naming them, unless its `duplicate_reason` column says why; brothers and sisters share the first row's reference as before. No schema change.

| Requirement | Evidence |
|---|---|
| FR-ELV-04 AC1, NFR-I18N-03 | `NameMatchTest`: 15 Algerian names and their Arabic writing, compound names, articles, doubled letters; twins and other names apart; the key equal to the database's. `DuplicatesTest`: « Mohamed Amine » and « محمد أمين » born the same day, and not another child born that day |
| FR-ELV-02 AC1 | `DuplicatesTest`: the same father by phone, in Arabic letters by e-mail, refused without a reason; the mother on the same phone and a namesake on another created. `SchoolingPanelTest`: found from the sheet and linked, one record for two children; a reason from the Guardians screen |
| FR-IMP-01 | `PupilImportTest`: a guardian recorded on screen refused under two references, in either alphabet; created with a reason, journaled; brothers and sisters linked |

Validation on 12 October 2026: **217 PHP integration tests (6,917 assertions)**, among them the key checked against the database's for French, Arabic and digits; no migration, so the SQL suites, the rollback and the browser screens are unchanged (the new warnings appear in forms only).

## Increment 40 (lot 2): an enrolled minor's primary contact, and a programme transfer

`027_enrolment_continuity.sql`:

- **FR-ELV-02**: a minor enrolled in a year still open keeps at least one primary contact with a phone, as the enrolment required. A link no longer primary, archived or deleted, a guardian whose phone is cleared or who is archived, a birth date that makes the pupil a minor: each is refused (`primary_contact_kept`) if it would leave none. Another contact is named first, then the previous one steps back. The check locks the pupil's row: two secretaries each unsetting one of two contacts at the same moment leave one (native concurrency check). A cancelled enrolment, an adult, a closed year: nothing to keep. A later change of the school's majority age is not rechecked.
- **FR-INS-03**: `POST /api/enrollments/{id}/transfer` with `program_id`, `idempotency_key`, `version` and `reason` (`enrollment.enrollment.cancel` on the enrolment, `enrollment.enrollment.create` for the whole school). In one transaction, the enrolment is cancelled with the reason and its places given back; the new one is made by the enrolment's own rules (an active programme, the price of the day, the quota, a minor's contact), pending, and linked to it (`transferred_from`, set by the database alone, once per source). If the new enrolment is refused, nothing changed. The same key again answers the same transfer, including two requests at the same moment. Each enrolment shows `transfer_source` and `transfer_target`. Carrying payments over belongs to lot 3.

On screen, the Enrollments list offers **Transfer to another programme** (active programmes, the new class at once), and the history shows where an enrolment came from or went to. The pupil's sheet says why a primary contact cannot be removed.

| Requirement | Evidence |
|---|---|
| FR-ELV-02 | `tests/sql/database.mjs`: unset, archived, phone cleared, guardian archived, birth date corrected, all refused while enrolled; another contact first; two contacts unset at once (native). `AcademicWorkflowTest`, `LotTwoWalkthroughTest`: through the API and the sheet |
| FR-INS-03 AC2, TST-08 | `tests/sql/database.mjs`: source cancelled with its reason and history, place given back, target at its price and linked; same programme, stale version, second transfer refused; the link not writable nor claimable; two requests at once make one (native). `AcademicWorkflowTest`: an inactive target changes nothing; retry. `LotTwoWalkthroughTest`: from the Enrollments screen with the new class, both histories |

Validation on 12 October 2026: **220 PHP integration tests (7,040 assertions), 192 embedded SQL checks and 205 native PostgreSQL 16 checks including concurrency**; migration 027 rolled back alone with transfers in place, then with all the others, and applied again. The Enrollments screen gains an action and a history section only: the browser acceptance was left to CI.

## Increment 41 (lot 2): a programme's subjects and their volumes

`028_program_subjects.sql`:

- **FR-PRG-02**: a programme teaches subjects or modules of the school's catalogue (BR-13), each with at least one of its total hours, weekly hours or number of sessions (`program_subject_volume_required`, AC2), the length of its sessions (by default the school's, the new setting `planning.session_minutes`, 60) and an optional coefficient. Hours have two decimal places (1.50 is an hour and a half), sent and read as decimals, refused rather than rounded beyond. A subject appears once in a programme at any date (`program_subject_overlap`): of two requests adding it at the same moment, the second waits for the first and is refused, an exclusion constraint backing the rule. A programme may have none (AC4).
- A changed volume is a new version (`volume_version`), kept in `school.program_subject_volume` with the day it was set, written by the database alone and never changed (AC1: 40 h, then 36 h, both kept).
- A line is taught from `valid_from` (today, or the first day of a year not begun yet) until `valid_to`, within its programme's year. Its end is final (`program_subject_ended`): the subject is added again for a later period. A line nothing uses is deleted with its versions, the journal keeping its values; a subject a line names stays in the catalogue (`subject_in_use`). A closed year freezes its programmes' subjects. With the sessions of lot 4, a line they use will refuse deletion and be ended instead (AC3).
- The average length its hours and sessions imply (`implicit_minutes`) is given; more than 10 % away from the session's length (`duration_gap`), it is pointed out, never refused.
- Read with the programme, by scoped readers too and in the journal; changed within the scope of `program.subject.manage` (`academic_hr`, `tenant_admin`).

Routes: `GET` and `POST /api/programs/{program}/subjects` (`include_ended` for the ended lines), `PUT /api/program-subjects/{line}`, `POST /api/program-subjects/{line}/end`, `DELETE /api/program-subjects/{line}` (with `version`), `GET /api/program-subjects/{line}/volumes`.

On screen, a programme's row opens **its subjects**: add one, change its volume, read its versions, end it or remove it. The implied average of a session is shown, coloured with its explanation when it is far from the session's length. The school's administrator manages a programme's subjects without managing the programme (section 3.2).

| Requirement | Evidence |
|---|---|
| FR-PRG-02 AC1, AC2, AC4 | `tests/sql/database.mjs`: no volume refused, defaults, ranges, dates within the year, archived or foreign subject refused, overlap refused, two versions kept, versions and their numbers not writable, a programme without subjects enrols; two lines added at once make one (native). `AcademicWorkflowTest`: decimals refused rather than rounded, 40 h then 36 h, the implied 120 min pointed out, stale version. `SchoolingPanelTest`: through the programme's subjects screen |
| FR-PRG-02 AC3, BR-02 | `tests/sql/database.mjs`, `AcademicWorkflowTest`: an end is final, the subject comes back for a later period; a line nothing uses is deleted with its versions, journal kept; a subject in a programme is not deleted |
| FR-IAM-04, FR-REF-05 | `tests/sql/database.mjs`: a site-scoped reader reads the subjects and journal of the programmes taught on their site only, a site-scoped manager changes only those; a closed year refuses every change. `AcademicWorkflowTest`: the direction reads and changes nothing |

Copying a programme to the next year with its subjects comes with the annual progression (FR-INS-05).

Validation on 13 October 2026: **222 PHP integration tests (7,326 assertions), 197 embedded SQL checks and 211 native PostgreSQL 16 checks including concurrency**; migration 028 rolled back alone with subjects, their versions and a school's setting in place, then with all the others, and applied again. Local browser acceptance in Chromium: 140 screens without a problem, the programme's subjects included; Firefox and WebKit by CI.

## Increment 42 (lot 2): a pupil's site from their creation

Decision of 13 October 2026: a pupil is attached to a site administratively from the day they are recorded. `029_pupil_sites.sql`:

- **Pupils and guardians belong to a site** (`site_id`). A new record goes to the site the form or the file names; without one, to the writer's only site when their grant names a single site, else to the school's default site (`site_required` when it is archived). Never to an archived site (`site_archived`). Records already there were attached to the site of their current class, else to the default site; a guardian to the site of their first pupil.
- **A grant limited to sites (FR-IAM-04) now creates there**: pupils and guardians of its sites, a guardian's link between a pupil and a guardian it reaches, an enrolment for a pupil of its sites, a place in a group of its sites, a group on its sites. Elsewhere, `permission_denied`. Moving a record to another site needs the update's grant on that site. A grant limited to groups, levels or one's own record still creates nothing.
- **What a site's grant reads**: the pupils and guardians recorded on its sites, before any enrolment or class, their enrolments and these enrolments' places in groups, besides what it already read (the groups on the site and their members, 021). A record just created is read back at once: the policies check the row's own site (`school.site_scope_ids`), since a new row is not yet among the IDs computed for the statement that writes it.
- The checks are `school.attach_site` (pupils, guardians) and `school.authorize_creation` (enrolments, groups, places, guardian links), before the rules that read other rows; the policies repeat them. A matricule is given to whoever may record a pupil somewhere.

The API takes `site_id` on pupils and guardians (`GET /api/students?site_id=`, `GET /api/guardians?site_id=` filter them); creation routes now ask for their permission with any scope, the database checking the site. Imports: a `site` column (the site's code) for pupils, refused when unknown; a guardian is recorded on their pupil's site. On screen: the site is chosen among the writer's when a pupil, guardian or group is created (the default site first), the pupils' list has a Site column and filter, the pupil's sheet shows and moves the site; a site's secretary sees *New pupil*, *Enrol* and the guardian actions for the pupils of their site.

| Requirement | Evidence |
|---|---|
| FR-IAM-04 (decision of 13 October) | `tests/sql/database.mjs`: default site, the writer's only site, another site refused, a move refused out of the grant and journaled within it, an archived site refused; a site's office enrols its pupils, places them in its groups and opens a group there, nowhere else. `SchoolingPanelTest`: a site's secretary records a pupil on their site, enrols and places them, and is refused another site; a site's manager opens a group on their site only |
| FR-IMP-01 | `PupilImportTest`: pupils on the site their row names or the default one, an unknown site refused with its line, a guardian on their pupil's site, a later file moving a pupil undone |
| NFR-PERF-03 | `tests/sql/scope-cost.mjs` on 2,000 pupils, before and after: the same rows for every reader; a site's lists 7.9 to 14.6 ms instead of 6.5 to 13.0 |

Validation on 13 October 2026: **223 PHP integration tests (7,410 assertions), 199 embedded SQL checks and 213 native PostgreSQL 16 checks including concurrency**; migration 029 rolled back alone with 594 pupils on 43 sites in place, then with all the others, and applied again (every pupil attached again). Local browser acceptance in Chromium: 140 screens without a problem.

## Increment 43 (lot 2): a retention policy per category

Decision of 13 October 2026: each school defines how long it keeps each category of data (NFR-DATA-03). `030_retention.sql`:

| Setting | Category | Default | Bounds |
|---|---|---|---|
| `retention.former_archive_months` | A former pupil is archived (FR-ELV-03) | 12 months after leaving | 1–120 |
| `retention.pupil_years` | An archived pupil's record is anonymised | 10 years after archiving | 1–50 |
| `retention.minor_years` | The same, for a pupil who left as a minor (the school's majority age) | 10 years | 1–50 |
| `retention.journal_years` | The journal's entries are deleted | 10 years | 5–50 |
| `retention.finance_years` | Financial records, applied with lot 3 | 10 years | 10–50 |

The defaults were accepted on 16 October 2026 (OD-07), the prospects' 24 months too (increment 55); each school changes them in its settings when its client asks.

- A pupil's departure day (`left_on`) is set by the database when they become a former pupil; pupils who left before were given the day the journal recorded it.
- `php artisan platform:retention` runs daily (scheduler) for every school, whatever its state: it archives the former pupils due, then anonymises the archived records due. An anonymised pupil keeps their matricule, birth year, gender, site, enrolments and groups; their names become `ANONYME` and their matricule, everything else personal goes (phone, e-mail, address, place of birth, school of origin, the school's own fields, import reference, account link, photo, whose files are then purged). A guardian is anonymised and archived once every pupil linked to them is: only their links remain. Journal entries older than the school's duration are deleted. At most `--limit` records per category and school (1,000 by default); the rest waits for the next night.
- A record a rule still keeps (an enrolled minor's last primary contact) waits for a later run, counted as kept; any other error stops the run instead of keeping records forever unnoticed. Each run that changed something leaves its counts in the school's journal (`RETENTION`, reason `retention`).
- Only this run gets past the rules that freeze a former pupil's record, a read-only school, the school's required fields and the journal (`school.retention_run()`: the provisioner, within the run's own flag); neither the application nor a migration does.
- `GET /api/retention` (`tenant.settings.manage`): the school's durations and what the next run will do, as counts. On screen, **Settings** shows the durations among the other settings and a **Data retention** view of the next run.

Not yet: the purge of an archived school (FR-PLT-01) belongs to the console; finance's duration waits for lot 3's records.

| Requirement | Evidence |
|---|---|
| NFR-DATA-03, FR-ELV-03, BR-17 | `tests/sql/database.mjs`: departure day, nothing written by hand, the application and the owner refused the run's bypass, bounds; archived after leaving, a minor anonymised after the minors' years while an adult is not, the record as anonymised, enrolments kept, a guardian kept while an enrolled minor needs them then anonymised, the old journal purged and the run's counts journaled, a run with nothing to do leaving nothing. `RetentionTest`: the preview and its permission, the daily command archiving then anonymising a pupil and their guardian, the Settings view |

Validation on 13 October 2026: **224 PHP integration tests (7,466 assertions), 201 embedded SQL checks and 215 native PostgreSQL 16 checks including concurrency**; migration 030 rolled back alone with anonymised records and retention settings in place, then with all the others, and applied again. Local browser acceptance in Chromium: 140 screens without a problem.

## Increment 44 (lot 2): the staff's records

`031_staff.sql`, module `staff` (plans Pro and Complete):

- **FR-STF-01**: a record for each member of staff, internal or external teacher, administrative or other (`staff_type`), with or without an account (principle P7): a name in Arabic or Latin letters, or both, phone, e-mail, the day they were hired and the school's own fields (FR-REF-04, entity `staff`). Its number (`staff_number`) is the school's, given once and never changed, or made by the database: `P0001`, `P0002`… after the highest one made, one creation at a time. Pay is never here (module REM, lot 3).
- **States**: active, on leave (`on_leave`) or left; a departure has its day (`left_on`), today unless told, never in the future (`left_on_future`) nor before the hiring (`left_before_hire`); coming back (`left` → `active`) clears it; a member on leave may leave without coming back first. The reason of each change goes to the journal. Nothing is ever deleted (BR-02): the sessions, hours and pay of lots 3 and 4 will keep pointing at the record; only a member who left is archived (`staff_archive_left`), and an archived record no longer changes (`staff_archived`).
- **A site**, as a pupil's (029): the record belongs to the site the form names, the writer's only site, or the school's default site. A grant limited to sites records, reads and changes the staff of its sites; a personal grant reads the holder's own record (a teacher's), once their account is linked to it.
- **The account**: linked or unlinked by `staff.profile.update`, among the school's members not revoked (`membership_revoked`); one record per account (`staff_account_linked`), and nobody links their own (`self_account`). The link opens nothing by itself: a personal grant does.
- **The journal**: domain `staff`, read with `staff.profile.read` (direction), by a scoped reader for the records they reach; names, contact details and the school's fields masked. The school's sensitive fields of a staff record are read and written with `staff.contract.read` (the staff's human resources: academic_hr, direction); the accountant reads the record's identity without them (section 3.3).

Routes: `GET /api/staff` (`q` on names in either alphabet, number, phone, e-mail; `status`, `staff_type`, `site_id`, `include_archived`; by pages), `GET /api/staff/{staff}`, `POST /api/staff`, `PUT /api/staff/{staff}` (with `version`), `POST /api/staff/{staff}/status` (`status`, `reason`, `left_on`, `version`), `POST /api/staff/{staff}/account` (`user_id` or null, `version`), `POST /api/staff/{staff}/archive` (`reason`, `version`), `GET /api/staff/accounts` (the members an account link may name). On screen, a **Staff** section with its **Staff** page: the list with its number, function, phone, site, account and state, filters by state, function and site; create, edit (the site among the writer's), change the state with the day of a departure, link or unlink the account, archive a member who left.

| Requirement | Evidence |
|---|---|
| FR-STF-01, BR-02, BR-18 | `tests/sql/database.mjs`: a record without an account, numbers made after the highest and given ones kept, unique and never changed, a name in either alphabet, the type from its list, the school's required fields; on leave, left with its day, never in the future nor before the hiring, back again, archived only once left and then frozen, never deleted, every change journaled; two records made at once get two numbers (native). `StaffTest`: the same through the API, the reason in the journal, a departure's day kept, lists searched and filtered, the page creating, editing, linking, unlinking, recording a departure and archiving |
| FR-IAM-04, FR-IAM-05, FR-AUD-01 | `tests/sql/database.mjs`: a site's grant records on its site and reads and changes its staff only, never moves one away; a personal grant reads one's own record and its journal entries, nobody else's; the technical administrator reads none of the staff's entries; an account linked to one record, never by its holder, never a revoked one; without the module, nothing. `StaffTest`: the same through the API and the page, a teacher reading their own record until unlinked, the accountant without the sensitive fields, the administrator and a school without the module refused |

Validation on 15 October 2026: **231 PHP integration tests (7,790 assertions), 206 embedded SQL checks and 221 native PostgreSQL 16 checks including concurrency**; migration 031 rolled back alone with 11 staff records and their journal in place, then with all the others, and applied again. Local browser acceptance in Chromium: 144 screens without a problem, the staff's page included.

Not yet: qualifications (FR-STF-02), availability (FR-STF-03), contracts and leave (FR-STF-04), the import of staff, a group's lead teacher and the implicit scopes of teachers and replacements that come with them (TST-03), the retention of a staff record that left.

## Increment 45 (lot 2): a teacher's qualifications

`032_qualifications.sql`:

- **FR-STF-02**: a teacher, internal or external, is qualified for a subject or module of the catalogue (BR-13) at a node of the referential: a level (1AS), a cycle (Primaire), a track or a specialty (Data Science), from `valid_from` (today by default, or a day in the past) until `valid_to` (included). A qualification at a node covers its descendants, computed when asked, never copied: `school.qualified(staff, subject, node, day)` (AC2: a qualification at Primaire covers 3AP). The same subject at the same node is given once at any date (`qualification_overlap`), even by two requests at once.
- A line keeps its teacher, subject, node and first day; its end is final (`qualification_ended`), the qualification is given again for a later period. A line nothing uses is deleted, the journal keeping its values; with the sessions of lot 4, a line they use will be ended instead, and a session will take only a teacher qualified for its subject and its group's level on its day (AC1). A subject a qualification names stays in the catalogue.
- Only a teacher (`qualification_not_teacher`) still with the school (`staff_left`) receives one; a member who left keeps theirs as history, and an archived record's no longer change. An archived subject or node is refused.
- Read with the teacher's record (`staff.profile.read`, a teacher's own with a personal grant); given, ended and removed within the scope of `staff.qualification.manage` on that teacher (academic_hr, a site's grant for the teachers of its sites). The journal's entries belong to the staff's domain.

Routes: `GET /api/staff/{staff}/qualifications` (`include_ended`), `POST /api/staff/{staff}/qualifications` (`subject_id`, `node_id`, `valid_from`, `valid_to`), `POST /api/qualifications/{line}/end` (`valid_to`, `version`), `DELETE /api/qualifications/{line}` (`version`); `GET /api/staff?subject_id=&node_id=&on=` keeps the teachers qualified for that subject at that node on that day. On screen, a teacher's row opens **their qualifications**: add one, end it, remove it; the **Staff** list filters the teachers qualified for a subject at a level.

| Requirement | Evidence |
|---|---|
| FR-STF-02 AC2, BR-13 | `tests/sql/database.mjs`: Primaire covers 3AP, not Moyen nor another subject nor the day before; a past qualification covers its days only; overlaps refused, a later period accepted; the same qualification given twice at once recorded once (native). `StaffTest`: the same through the list's filter, a cycle's qualification finding the teacher at a level, the page giving, ending and removing |
| FR-STF-02, FR-STF-01, BR-02 | `tests/sql/database.mjs`: teacher, subject, node and first day never change, an end is final and not before the first day, a line nothing uses deleted and journaled; an administrative member, a member who left, an archived subject refused; an archived record's lines frozen; a subject in a qualification not deleted. `StaffTest`: the same refusals through the API, history listed with `include_ended` |
| FR-IAM-04 | `tests/sql/database.mjs`: a site's grant qualifies the teachers of its site only and is told nothing of the others; a teacher reads their own qualifications and gives none. `StaffTest`: the accountant reads them and changes none |

Validation on 15 October 2026: **233 PHP integration tests (7,962 assertions), 210 embedded SQL checks and 226 native PostgreSQL 16 checks including concurrency**; migration 032 rolled back alone with a qualification and its 7 journal entries in place, then with all the others, and applied again. Local browser acceptance in Chromium: 148 screens without a problem, a teacher's qualifications included.

AC1 (a session's teacher qualified for its subject and level) comes with the sessions of lot 4, which will call `school.qualified` from their rules.

## Increment 46 (lot 2): a group's lead teacher, and the teacher's own groups

`033_lead_teachers.sql`:

- **FR-GRP-01**: a group (class, support or option group, training session) has an optional lead teacher (`lead_teacher_id`), named with the group by `group.group.manage` while it is open: a teacher, internal or external, still with the school (`lead_teacher_not_teacher`, `staff_left`, `staff_archived`), among the records the writer reads with `staff.profile.read` (a grant limited to sites names the teachers of its sites). Who leaves later stays the lead teacher in the group's history.
- **FR-IAM-04 AC1, TST-03**: a personal grant reads, beside one's own records, the groups one leads through one's own staff record (linked to the account, FR-STF-01), as a grant on these groups would: their members, their current pupils and these pupils' guardians, their programmes, the journal's entries of these, for the permissions the grant holds. Another lead teacher, or a departure from the school, and the groups are no longer theirs. Such a grant never creates a group or a pupil. Replacements' groups come with the sessions of lot 4.
- **Section 3.3**: the `teacher` role, as the template and the schools' copies made from it, also reads pupils, guardians and staff records (`student.record.read`, `guardian.record.read`, `staff.profile.read`); assigned with the personal scope (« Données personnelles uniquement, et ses groupes pour un enseignant »), it reads its pupils and its own record, never enrolments.

Routes: `POST /api/groups` and `PUT /api/groups/{group}` take `lead_teacher_id`; `GET /api/groups` filters by `lead_teacher_id`; a group's reply has `lead_teacher` (number and names) when the reader reads that record. On screen, the **Groups and classes** list shows the lead teacher, and the creation and edit forms choose one among the teachers still with the school; a teacher signed in sees their groups and their pupils only.

| Requirement | Evidence |
|---|---|
| FR-GRP-01, FR-STF-01 | `tests/sql/database.mjs`: a teacher named, changed in the journal, removed; an administrative member, a member who left, an archived record, an unknown one refused; who leaves later kept in the group; a site's grant names a teacher of its site, not of another. `LeadTeacherTest`: the same through the API (a validation error for a record the writer cannot read), the list filtered by lead teacher, the edit form naming one and the column showing them |
| FR-IAM-04 AC1, TST-03, section 3.3 | `tests/sql/database.mjs`: a teacher reads no group before leading one, then that group, its pupil, guardian, enrolment, place and programme, their own record and the group's journal, nothing of the other group; changes what their grant allows on that group only, creates none; loses it all to another lead teacher or on leaving, gets it back on returning; the `teacher` role assigned with the personal scope reads its pupils and own record, no enrolment. `LeadTeacherTest`: the same through the API and the screens (Groups and classes, Pupils) for a teacher with the school's own `teacher` role |

Validation on 16 October 2026: **235 PHP integration tests (8,065 assertions), 213 embedded SQL checks and 229 native PostgreSQL 16 checks including concurrency**; migration 033 rolled back alone with 3 lead teachers and the groups' 55 journal entries in place (the groups and their journal kept, the teacher roles' new permissions removed), then with all the others, and applied again (the schools in use extended again, as by 015). Local browser acceptance in Chromium: 148 screens without a problem, the groups' list with its lead teacher included.

## Increment 47 (lot 2): a teacher's availability

`034_availability.sql`:

- **FR-STF-03**: a teacher, internal or external, declares the hours of the week they may teach: slots of a day of the week (0 Sunday to 6 Saturday) from a time until another (`HH:MM`, `24:00` ending a day), in force from `valid_from` (today by default, never a past day) until a later declaration's first day; with bounds, at least so many hours a week, at most so many a day and a week. Slots that overlap on a day or are malformed are refused (`availability_slots_overlap`, `availability_slots_invalid`), adjacent ones join; a minimum above the slots' hours is refused (`availability_below_minimum`). A declaration with no slot says the teacher is available at no time; no declaration at all leaves their availability unknown. A declaration never changes; a change is a new one, the earlier ones kept (BR-10), one a day (`availability_same_day`), even for two requests at once.
- Days off (`school.unavailability`): from one day until another (included), with a reason read by whoever reads the record; they prevail over the week, never overlap (`unavailability_overlap`, even at once); one in force is cancelled until its last day (`unavailability_ended` after), never deleted.
- Entered by the teacher on their own record (`staff.availability.manage` with a personal grant, as the `teacher` role's template has it) or by their manager. In force at once, unless the school's setting `staff.availability.require_approval` (off by default) asks for an approval the writer may not give on that record, or the record is their own: it then waits, and `staff.availability.approve` approves it (from its first day, or from today when that day has passed) or refuses it with a reason; nobody decides on their own record (`self_approval`, FR-IAM-05). A pending one is withdrawn (deleted, journaled); a decided one stays as it is (`availability_decided`). A teacher whose changes wait asks their manager to cancel a day off (`approval_required`).
- `school.available(staff, day, from, until)` says whether a teacher may teach on a day from a time until another: never on a day off approved, else within that day's slots in force, or at any time while they declared none. Lot 4's sessions will call it from their rules (AC1: a session outside them is a conflict; AC2: more hours a day than allowed, a warning), as `school.qualified`.
- Read with the teacher's record (`staff.profile.read`); only a teacher (`availability_not_teacher`) still with the school (`staff_left`) declares, and an archived record's no longer change. The journal's entries belong to the staff's domain.

Routes: `GET /api/staff/{staff}/availabilities` (`status`), `POST /api/staff/{staff}/availabilities` (`valid_from`, `slots`, `min_weekly_hours`, `max_daily_hours`, `max_weekly_hours`), `POST /api/availabilities/{declaration}/approve` and `/reject` (`version`, `decision_reason`), `DELETE /api/availabilities/{declaration}` (`version`); `GET /api/staff/{staff}/unavailabilities` (`status`, `include_past`), `POST /api/staff/{staff}/unavailabilities` (`date_from`, `date_to`, `reason`), `POST /api/unavailabilities/{unavailability}/approve`, `/reject` and `/cancel`, `DELETE /api/unavailabilities/{unavailability}`; `GET /api/staff?availability_pending=1` keeps the members whose availability waits, and each row has `availability_pending`. A declaration's reply has its `weekly_hours`, its `state` (`in_force`, `upcoming`, `replaced`, `pending`, `rejected`) and who declared and decided it. On screen, a teacher's row opens **their availability** (declare from the week in force, approve, refuse, withdraw) and from there **their days off** (declare, approve, refuse, cancel, withdraw); the **Staff** list shows and filters the members whose availability waits.

| Requirement | Evidence |
|---|---|
| FR-STF-03, BR-10 | `tests/sql/database.mjs`: unknown before any declaration; slots in the week's order, adjacent ones joined, a later declaration in force from its first day; a day off prevailing, then cancelled; malformed or overlapping slots, a past day, a second declaration of a day, a minimum above the slots refused; a declaration in force neither changed, refused nor withdrawn; an administrative member and a member who left declare nothing; two declarations of a day and two overlapping days off made at once recorded once (native). `AvailabilityTest`: the same through the API, with the validation errors before the database, and the pages declaring, cancelling and showing the week in force |
| FR-STF-03, FR-IAM-05, FR-REF-06 | `tests/sql/database.mjs`: with approval asked, the manager's change in force at once, the teacher's pending and not in force, withdrawn, refused with a reason (the day free again) or approved, by another than the teacher; an approver's own record waiting for another approver; approved after its first day, in force from today. `AvailabilityTest`: the same through the API and the pages, the Staff list filtered on availability waiting |
| FR-IAM-04, FR-AUD-01 | `tests/sql/database.mjs`: a site's grant declares for the teachers of its site only and is told nothing of the others; a teacher reads their own and its journal, nothing else. `AvailabilityTest`: the accountant reads them and declares and decides nothing; the teacher declares on another's record and reads it not |

Validation on 16 October 2026: **238 PHP integration tests (8,534 assertions), 216 embedded SQL checks and 233 native PostgreSQL 16 checks including concurrency**; migration 034 rolled back alone with 8 declarations, 3 days off and their 21 journal entries in place (the tables, the setting and its definition removed, the journal kept and its policy as 032 wrote it), then with all the others, and applied again. Local browser acceptance in Chromium: 156 screens without a problem, a teacher's availability and days off included.

## Increment 48 (lot 2): the staff's contracts and leave

`035_contracts_and_leave.sql`:

- **FR-STF-04, contracts**: any member of staff has a contract in dated versions (`school.staff_contract`): a type (`permanent`, `fixed_term`, `hourly`, `service`, `internship`, `other`), a reference, from `valid_from` until `valid_to` (included; none for an open-ended contract, required for a fixed-term one or an internship, `contract_end_required`), the hours a week it provides for, and notes. **No amount** (AC1): there is no such column, pay being the remuneration rules' (FR-REM-01, lot 3) under `payroll.*`; academic_hr reads contracts and no amount. A member's versions never overlap (`contract_overlap`), even for two requests at once. An amendment is a new version from a day after the first one's, the version in force ending the day before; what it does not change is kept. A version keeps what it says; its end only comes earlier (`contract_end_later`), with a reason if told, never before its first day; one not begun yet is deleted (`contract_started` otherwise), the journal keeping it.
- **FR-STF-04, leave**: a member's planned absences (`school.staff_leave`): annual leave, sickness, training, maternity, unpaid leave, an exceptional absence, other; from one day until another, possibly gone by (a sickness recorded afterwards), with notes. Recorded by the member's manager and in force at once; never two at a time (`leave_overlap`, even at once); cancelled until its last day (`leave_ended` after), never deleted.
- **A teacher's leave makes them unavailable** (FR-STF-03): it writes an unavailability of its own days that points to it (`leave_id`), in force whatever the school asks of declared ones, without a reason: whoever reads the record reads « Absence planifiée », never the leave's type or notes. It may cover days the teacher declared; it is cancelled with the leave only (`unavailability_from_leave` through the availability's routes). A member who does not teach has none. `school.available` therefore answers « no » on a teacher's leave; lot 4's sessions will list those it touches (AC2).
- Read with their own permission, `staff.contract.read` and `staff.leave.read`, written with `staff.contract.manage` and `staff.leave.manage`, each within its scope (the member's site, the whole school, one's own). The notes are masked in the journal (`observation`, BR-17); its entries belong to the staff's domain, read with the contracts or the leave. **Section 3.3**: the `teacher` role, as the template and the schools' copies, reads its own contracts and leave (« P »).
- Only a member still with the school receives a contract or a leave (`staff_left`); an archived record's no longer change.

Routes: `GET /api/staff/{staff}/contracts`, `POST /api/staff/{staff}/contracts` (`contract_type`, `reference`, `valid_from`, `valid_to`, `weekly_hours`, `observation`), `POST /api/contracts/{contract}/amend` (`valid_from` and what changes, `version`), `POST /api/contracts/{contract}/end` (`valid_to`, `end_reason`, `version`), `DELETE /api/contracts/{contract}` (`version`); `GET /api/staff/{staff}/leaves` (`leave_type`, `include_past`, `include_cancelled`), `POST /api/staff/{staff}/leaves` (`leave_type`, `date_from`, `date_to`, `observation`), `POST /api/leaves/{leave}/cancel` (`version`). A contract's reply has its `state` (`in_force`, `upcoming`, `ended`); a leave's, its `days`, its `state` (`upcoming`, `under_way`, `past`, `cancelled`) and who recorded it; an unavailability's, its `leave_id`. On screen, a member's row opens **their contracts** (record, amend, end, delete one to come) and **their leave** (record, cancel), each for whoever reads them; a teacher's days off show a leave's as « Absence planifiée », without its actions.

| Requirement | Evidence |
|---|---|
| FR-STF-04 AC1 | `tests/sql/database.mjs`: no column of a contract names an amount; versions dated, amended the day before, never overlapping, an end only earlier and not before the first day, a version begun kept and one to come deleted with its notes masked in the journal; any member, not one who left, an archived record's frozen; two overlapping contracts made at once recorded once (native). `ContractLeaveTest`: no key of a contract's reply is an amount; the same rules through the API, academic_hr and the direction reading, the accountant (who reads the records) not; the Contracts page recording, amending, ending and deleting |
| FR-STF-04, FR-STF-03 | `tests/sql/database.mjs`: a teacher's sickness leave makes them unavailable on its days, past ones included, without a reason; overlapping leaves refused, even at once (native); its unavailability neither cancelled, deleted nor forged through the availability; cancelled with its leave, the teacher available again; a past leave kept; none for an administrative member. `ContractLeaveTest`: the same through the API and the pages, the days off showing « Absence planifiée » and not the leave's notes |
| FR-IAM-04, section 3.3 | `tests/sql/database.mjs`: a site's grant records contracts and leave for its site's members only, and its leave makes its teacher unavailable without the availability's permission; whoever reads the records only reads no contract nor leave, and the unavailability without a reason; a teacher with their own scope reads their contract, their leave and their journal, changes none; the school's `teacher` role holds both permissions. `ContractLeaveTest`: the teacher signed in with the school's `teacher` role reads their own leave and contract, nobody else's, records none |

Validation on 16 October 2026: **241 PHP integration tests (8,952 assertions), 219 embedded SQL checks and 237 native PostgreSQL 16 checks including concurrency**; migration 035 rolled back alone with 3 contracts, 4 leaves, their 3 days off and 17 journal entries in place (the tables and the leaves' days off removed, the declared days off kept with their reason required again, 034's rules and journal policy back, the teacher roles' 484 new permissions removed), then with all the others, and applied again. Local browser acceptance in Chromium: 164 screens without a problem, a member's contracts and leave included.

AC2 (a leave lists the sessions it touches) comes with the sessions of lot 4, which will read the teacher's unavailability. A leave does not change the record's state (`on_leave`), which the manager sets for a long absence (FR-STF-01).

## Increment 49 (lot 2): the staff's import

`036_staff_imports.sql`, `app/Imports/StaffImporter.php`, `app/Imports/QualificationsImporter.php`:

- **FR-IMP-01, FR-STF-01**: the **Imports** screen offers **Personnel** (`staff`) to whoever may create staff for the whole school. A row per member: `external_ref` (the school's stable reference, required), `staff_number` (kept when given, attributed otherwise, never changed: `code_immutable`; one already another member's refused), the names in Arabic or Latin letters, `staff_type` (`teacher_internal`, `teacher_external`, `administrative`, `other`), `phone`, `email`, `hire_date` (`2020-09-01` or `01/09/2020`), `site` (a site's code; the default site when empty) and the school's own fields as `custom_<key>` columns. The same file imported again updates what it changes, column by column, or leaves the member as they are, never duplicates them (BR-05); a column it leaves out keeps its value. A member is created active; a departure is recorded on screen. A sensitive field is written by whoever reads the staff's contracts (`staff.contract.read`), as on screen.
- **FR-IMP-01, FR-STF-02**: **Habilitations des enseignants** (`qualifications`), for whoever manages qualifications for the whole school: `staff_ref` (the staff file's reference, or the member's number), `subject` and `node` (codes of the catalogue and of the referential, in any case), `valid_from` (today when empty) and `valid_to`. An unknown member, subject or node is refused, never created (AC2); the database's rules apply as on screen (`qualification_not_teacher`, `staff_left`, `qualification_overlap`…). A line already there is left as it is, whatever day the file comes again: without `valid_from`, the line in force or to come; a file giving it another end is refused (`qualification_differs`), an end being final and changed on screen.
- **Undo** (FR-IMP-01): last import first, while nothing depends on it. A member an import created goes through `school.discard_staff` while they are active, without an account, a qualification, an availability, a day off, a contract, a leave or a group they lead; a member it updated gets their values back while they still hold the imported ones. A qualification it created is removed while its end is as imported. The reference never changes once given, and the journal masks it.

| Requirement | Evidence |
|---|---|
| FR-IMP-01 AC1, AC3, BR-05 | `StaffImportTest`: three members previewed without anything written, created with a number kept and one attributed, on the site named or the default one; the same file again unchanged, a changed cell updating one member; qualifications by reference or number and by codes in any case, unchanged when imported again. `tests/sql/database.mjs`: a reference unique and never changed, even by the database's owner |
| FR-IMP-01 AC2 | `StaffImportTest`: each refused line with its cause: a wrong function, an impossible date, no full name, an unknown site, a number taken or changed, a line twice; a member who left, an unknown member, subject or node, an administrative member, a line whose end the file changes |
| FR-IMP-01 (undo), BR-02 | `tests/sql/database.mjs`: `school.discard_staff` refuses a member no import created, one with a qualification, a contract or not active, and anyone without the permission for the whole school; the journal keeps the removal with its reason. `StaffImportTest`: the staff's import kept while their qualifications, a contract or a correction on screen depend on it, undone once these are; imported again, the members come back once |
| FR-REF-04, FR-IAM-04 | `StaffImportTest`: the school's own fields as columns, a sensitive one refused to a role that does not read the contracts; the qualifications' kind refused to that role, both kinds to a grant limited to a site |

Validation on 16 October 2026: **245 PHP integration tests (9,113 assertions), 220 embedded SQL checks and 238 native PostgreSQL 16 checks including concurrency**; migration 036 rolled back alone with 42 members, 10 of them from files, and 15 staff and qualification imports in place (the members kept without their reference), then with all the others, and applied again. Browser acceptance left to the CI: no new screen, the Imports screen offers two more kinds.

## Increment 50 (lot 2): rooms

`037_rooms.sql`:

- **FR-SLL-02, lists**: the school's own lists of **room types** and **equipment** (« paramétrables »), each entry a code that never changes and names in French and Arabic. The publisher's defaults (classroom, laboratory, computer room, lecture hall, other; projector, whiteboard, interactive board, computers, air conditioning, lab benches) are given to a school in use when it receives the `sites_rooms` module, at its creation or later (`platform.enable_module`), and to the schools in use that had it. A school that is not in use (suspended, expired, archived) writes nothing: it receives the module without them, and takes them back itself once in use again (**Reprendre les listes par défaut**, `POST /api/room-options/defaults`), the entries it archived staying archived. The school adds entries and archives them, never deletes them: an archived entry is offered no more, and the rooms that have it keep it. Changed by `room.room.manage` for the whole school.
- **FR-SLL-02, rooms**: a site's rooms: a code unique on the site and never changed, a name, a capacity (1 or more), a type and equipment among the lists in use (`room_option_unknown`), active or **unavailable** (no new session, no new group), archived when no longer used, never deleted (an archived room no longer changes: `room_archived`). Read by whoever reads the referential, as the sites; written within the scope of `room.room.manage`, the room's site or the whole school.
- **FR-SLL-02 AC2, closures**: dated closures (works) from one day until another, with the reason; never two at a time (`room_closure_overlap`, even at once); from today on; cancelled until their last day, never deleted. `school.room_available(room, day)` says whether a room takes a session on a day (in use, available, not closed): lot 4's sessions will call it.
- **FR-GRP-01, FR-SLL-02 AC1**: a group's optional **main room** (`main_room_id`): on the group's site (`room_other_site`), in use and available when named (`room_unavailable`), and never seating fewer than the group's capacity, whichever changes (`room_too_small`, the capacity conflict of AC1); a room is not archived while a group in use has it (`room_in_use`).
- **FR-SLL-01**: a site whose rooms are in use is not archived (`site_has_rooms`).

Routes: `GET /api/rooms` (`site_id`, `room_type`, `status`, `min_capacity`, `equipment`, `q`, `include_archived`), `GET /api/rooms/{room}`, `POST /api/rooms`, `PUT /api/rooms/{room}`, `POST /api/rooms/{room}/archive`; `GET /api/rooms/{room}/closures` (`include_past`, `include_cancelled`), `POST /api/rooms/{room}/closures`, `POST /api/room-closures/{closure}/cancel`; `GET /api/room-options` (`kind`), `POST /api/room-options`, `POST /api/room-options/defaults`, `PUT /api/room-options/{option}`, `POST /api/room-options/{option}/archive`; `POST /api/groups` and `PUT /api/groups/{group}` take `main_room_id`, `GET /api/groups` filters by it, and a group's reply has `main_room` (code, names, capacity, state). On screen, **Rooms** (by site, type, equipment and state, with the next closure) opens a room's **closures**; **Room types and equipment** manages the lists; **Groups and classes** shows and chooses a group's main room among its site's.

| Requirement | Evidence |
|---|---|
| FR-SLL-02, BR-02 | `tests/sql/database.mjs`: the publisher's lists given with the module; equipment kept once, in order; a code unique on its site, the same on another; an unknown type or equipment, a capacity of 0 refused; an entry archived refused to new rooms, kept by the room that has it, and frozen; an archived room frozen; a site's grant creates on its site only, changes no room of another, no list. `RoomTest`: the same through the API (lists, filters by site, places and equipment, a stale version), and the pages Rooms, Room types and equipment |
| FR-SLL-02 AC2 | `tests/sql/database.mjs`: a room closed on its days and available after; overlapping, past or reversed closures refused, two overlapping made at once recorded once (native); cancelled, the room available again; one passed kept; an unavailable room available on no day. `RoomTest`: the same through the API and the closures page |
| FR-GRP-01, FR-SLL-02 AC1, FR-SLL-01 | `tests/sql/database.mjs`: a room of 20 places refused to a group of 25, a room of another site refused; the group growing past its room and the room shrinking below its group refused, the room in use not archived; an unavailable room named no more, kept by its group; a site with a room in use not archived, archived once its rooms are, then taking no room. `RoomTest`: the same through the API (an unknown room a validation error) and the Groups page |
| FR-SLL-02, FR-PLT-01 | `RoomTest`: a school missing entries takes back the publisher's, the archived ones staying archived, once only; a suspended school receives the module from the console without them. Migration 037 applied with suspended and expired schools in place |
| FR-IAM-04 | `RoomTest`: the direction reads rooms and writes none; a grant limited to the annex creates rooms there, not on the main site, and changes neither the main site's rooms, their closures nor the lists |

Validation on 16 October 2026: **250 PHP integration tests (9,519 assertions), 223 embedded SQL checks and 242 native PostgreSQL 16 checks including concurrency**; migration 037 rolled back alone with 10 rooms, 2 closures, 2,752 list entries, a group's main room and their journal in place, among 251 schools of which 4 read-only and 2 suspended (the groups kept without their main room, `enable_module` as 002 wrote it), applied again, then rolled back with all the others and applied again. Local browser acceptance in Chromium: 176 screens without a problem, Rooms, a room's closures and Room types and equipment included.

## Increment 51 (lot 2): prospects and their conversion

`038_leads.sql`:

- **FR-CRM-01, lists**: the school's own lists of prospects' **sources** (word of mouth, social media, posters, other) and **steps** (new, contacted, visit scheduled, visited, registered, lost), given as the rooms' are (037): to a school in use when it receives the `students_crm` module, at its creation or later, and to the schools in use that had it; a school not in use takes them back itself (**Reprendre les listes par défaut**, `POST /api/lead-options/defaults`). The school adds sources and steps, archives them, never deletes them; `new`, `registered` and `lost` are the pipeline's own and stay (`lead_status_reserved`). Changed by `crm.lead.update` for the whole school.
- **FR-CRM-01, prospects**: a contact (a name in any alphabet, a phone or an e-mail, how they are related to the children, `self` for an adult who enrols), their **children** with the level they aim at (a node of the referential), a source and a step of the lists (`lead_option_unknown`), whom it is **assigned to**: a member who reads the prospects of its site (`lead_assignee_invalid`). Like a pupil (029), a prospect belongs to a site whose grants of `crm.lead.*` read and follow it. Recording one looks for the **same phone** among the prospects, pupils and guardians the reader sees (`lead_duplicate`, with them), and records it anyway only with a reason, which the journal keeps. A prospect is **no pupil**: no matricule, no enrolment, in no headcount (AC3).
- **FR-CRM-01, closing**: `lost` only with its reason (`lost_reason_required`, AC2); `registered` only by a conversion (`lead_not_converted`); a closed prospect changes no more (`lead_closed`) unless reopened with a reason (`reopen_reason_required`), kept in the journal; its planned reminders are cancelled with it.
- **FR-CRM-01, follow-up**: **interactions** (call, visit, meeting, message, note), at the time they happened (`interaction_future`), never changed nor deleted; **reminders** for later (`reminder_past`), done or cancelled. A due reminder **notifies the prospect's assignee** (its author when nobody is assigned), once, if they still read its site (AC1): `platform:lead-reminders`, every minute, for the schools in use, even two runs at once (`school.lead_reminder_notice`). The notification (`crm.reminder_due`, no e-mail by default) points to the prospect and names nobody (BR-19).
- **FR-CRM-02**: a child is **converted** into a pupil on the prospect's site, with their guardian (the contact, new, or one recorded already, or none for an adult) linked first, then their enrolment, pending, in an active programme, in the request's one transaction: everything or nothing (a minor without a primary contact leaves no pupil, no guardian). The child keeps the link to the pupil and the enrolment, set once (`lead_already_converted`, `lead_conversion_mismatch`); the prospect is registered. The same request key again answers the same conversion (a double click: AC1), even at once; another key is refused. The pupil's and guardian's own rules apply as on their screens (duplicates with a reason, the school's fields). The interactions stay readable on the pupil's sheet (AC2).
- Section 3.3 of the SRS: the direction and the head of studies read prospects (`crm.lead.read`, L); the front office keeps the rest (annex A). Their roles in the schools in use receive it. In the journal, the prospects' names, phone, e-mail, birth dates, what was said and the school's fields are masked (BR-17).

Routes: `GET /api/leads` (`q` on the contact's or a child's name, phone or e-mail, `status`, `source`, `site_id`, `assigned_to`, `mine`, `open`), `GET /api/leads/{lead}`, `POST /api/leads` (with `children`, `duplicate_reason`), `PUT /api/leads/{lead}`, `POST /api/leads/{lead}/status` (`lost_reason`, `reason`), `POST /api/leads/duplicates` (`phone`), `GET /api/lead-assignees` (`site_id`); `POST /api/leads/{lead}/children`, `PUT /api/lead-children/{child}`, `POST /api/lead-children/{child}/archive`, `POST /api/lead-children/{child}/convert` (`crm.lead.convert`); `GET|POST /api/leads/{lead}/interactions`, `GET|POST /api/leads/{lead}/reminders` (`planned`), `POST /api/lead-reminders/{reminder}/done`, `POST /api/lead-reminders/{reminder}/cancel`; `GET /api/lead-options` (`kind`), `POST /api/lead-options`, `POST /api/lead-options/defaults`, `PUT /api/lead-options/{option}`, `POST /api/lead-options/{option}/archive`; `GET /api/students/{student}/lead`. `POST /api/students` and `POST /api/guardians` share their rules with the conversion. On screen, **Prospects** (open ones first, by step, source, site, those one follows) records a family with its children; a prospect's **sheet** shows its contact, planned reminders, children and interactions, notes an interaction, plans and closes reminders, changes its step, and **converts** a child with what the prospect already says; **Sources and steps of prospects** manages the lists; the pupil's sheet shows the **prospect of origin** and its interactions; a due reminder's notification opens its prospect.

| Requirement | Evidence |
|---|---|
| FR-CRM-01 | `tests/sql/database.mjs`: the publisher's lists given with the module; an unknown source, a closed step at creation, a prospect without phone nor e-mail refused; the school's own step used, then archived and refused; a pipeline's step not archived. `LeadTest`: the same through the API, the same phone found among prospects (and pupils, guardians) and recorded only with a reason, the search by a child's name |
| FR-CRM-01 AC2 | `tests/sql/database.mjs`: lost without a reason, or a blank one, refused; closed, the prospect changes no more; reopened only with a reason, its reason of loss cleared; registered refused without a conversion. `LeadTest`: the same through the API, the reason of the reopening in the journal, the reminders cancelled with the closure, a child refused to a closed prospect |
| FR-CRM-01 AC1 | `tests/sql/database.mjs`: a due reminder notifies its prospect's assignee once, even run twice, outside any school; one whose assignee no longer reads the prospect notifies nobody. `LeadTest`: `platform:lead-reminders` run twice, one notification in the assignee's bell, pointing to the prospect without its name |
| FR-CRM-01, FR-IAM-04 | `tests/sql/database.mjs`: an assignee who does not read the site, or a suspended member, refused; a site's grant reads, records and follows its site's prospects only; an interaction never changed, one in the future refused; a reminder in the past refused, done once, cancelled with its prospect. `LeadTest`: the assignees offered (the head of studies, not the accountant) |
| FR-CRM-02 AC1 | `tests/sql/database.mjs`: a child converted once, with its own pupil's enrolment only; a lost prospect's child not converted; two conversions of a child at once make one pupil, the other's undone (native). `LeadTest`: the same request twice gives the same pupil and enrolment, another refused, one pupil; a refused enrolment leaves no pupil nor guardian; a sibling converted later with the guardian already recorded |
| FR-CRM-02 AC2, FR-CRM-01 AC3 | `LeadTest`: the prospect and its interactions read from the pupil's record; a prospect counted among no pupils. The pages Prospects, a prospect's sheet (interaction, closure) and Sources and steps of prospects |

Validation on 16 October 2026: **254 PHP integration tests (10,120 assertions), 227 embedded SQL checks and 247 native PostgreSQL 16 checks including concurrency** (two conversions of a child at once); migration 038 rolled back alone with 6 prospects, 2 of their children converted, their interactions, reminders, a reminder's notification, 2,531 list entries and their journal in place, among 255 schools of which 6 not in use (the 601 pupils kept, `enable_module` as 037 wrote it, the direction's and head of studies' `crm.lead.read` taken back), applied again, then rolled back with all the others and applied again. Local browser acceptance in Chromium: 188 screens without a problem, Prospects, a prospect's sheet and Sources and steps of prospects included.

## Increment 52 (lot 2): access to courses and payment

`039_access_policy.sql`:

- **FR-INS-04**: whether an enrolment opens its pupil's courses (`access_granted`, for lot 5's courses and the parents' portal), **computed when asked, never stored**: validated, and paid as far as the school asks. The school's setting **Payment required for access to courses** (`enrollment.access.require_payment`: `none`, `first_installment`, `full`; `none` by default, each school's to decide, OD-05) is a new kind of setting, one code of a list (`code`), checked by the database (`setting_invalid`). A pending, suspended (FR-INS-03) or cancelled enrolment opens nothing; one out of the reader's reach neither.
- What the payments cover is `school.enrollment_paid(enrollment, level)`: lot 3's finance records payments and schedules and replaces it. Until then no payment is validated, so with a payment required only an enrolment that owes nothing (a free programme, a full discount) opens its courses (AC1).

The enrolments' replies (`GET /api/enrollments`, `GET /api/enrollments/{enrollment}`, and those of their changes) carry `access_granted`; `school.access_granted(enrollment)` gives it to the database's own callers. On screen, **Enrolments** and the pupil's sheet show it; **Settings** offers the policy's three choices.

| Requirement | Evidence |
|---|---|
| FR-INS-04 AC1, OD-05 | `tests/sql/database.mjs`: a pending enrolment closed, a validated one open by default; with the first instalment or all of it required, a validated enrolment without a validated payment closed, a free programme's open; a code outside the list, or a list, refused; a suspended enrolment closed; an enrolment out of the reader's reach closed. `AccessPolicyTest`: the same through the API (the setting read and changed, each reply's `access_granted`, the suspension) and the Settings page's choices |

Validation on 16 October 2026: **256 PHP integration tests (10,185 assertions)**, **228 embedded SQL checks and 248 native PostgreSQL 16 checks including concurrency**; migration 039 rolled back alone with 2 schools' values of the setting and 538 enrolments in place (the settings' guard as 011 wrote it), applied again, then rolled back with all the others and applied again. Browser acceptance left to the CI: no new screen, a column on Enrolments and on the pupil's sheet, a row in Settings.

## Increment 53 (lot 2): the annual progression

`040_progression.sql`:

- **FR-INS-05**: at the end of a year, for a validated enrolment (`progression_not_validated`), one **decision**: promoted, repeated, left or completed, with its reason, by `enrollment.progression.decide` within a scope that reaches the enrolment. A promotion or a repetition names a **class or session of a later year** (`progression_same_year`, `progression_target_invalid`) and the day the new enrolment's first instalment falls due (lot 3's invoice): the pupil is enrolled there, **pending**, at the programme's price of the day, and assigned to the class from its first day (FR-INS-01); a repetition keeps the level, a promotion changes it (`progression_level_changed`, `progression_level_same`, AC4); a pupil already enrolled in the target programme is not enrolled again (`progression_target_enrolled`). Leaving or completing a cycle enrols nobody.
- A decision is written once by `school.decide_progression` and never changes: the same decision again answers the first one, even sent twice at once, and makes nothing (AC2); another one is refused (`progression_decided`). The source enrolment, its groups and history stay as they were (AC3, BR-03).
- A **batch** of decisions is the request's: if one target fails (a full class, a pupil already enrolled), none is made (AC1).
- A promotion or a repetition enrols and assigns with the decider's own rights (`enrollment.enrollment.create`, `group.membership.manage`), as a transfer does (027): the head of studies has them; the direction, which also decides (annex A), records departures and completions, and a promotion only with a role that enrols.

Routes: `POST /api/progressions` (`decisions`: each `enrollment_id`, `decision`, `reason`, and for a promotion or a repetition `target_group_id`, `due_date`; 200 at most), `GET /api/progressions` (`year_id`, `decision`, `target_group_id`); an enrolment's reply carries its `progression`. On screen, a class's **members** show each one's decision, and **Decide the progression** takes the class's validated pupils without one, at once.

| Requirement | Evidence |
|---|---|
| FR-INS-05 AC1 | `tests/sql/database.mjs`: two decisions into a class of one place, in one transaction: refused, no decision, no assignment. `ProgressionTest`: the same batch through the API, no enrolment created |
| FR-INS-05 AC2 | `tests/sql/database.mjs`: the same decision again answers the first, the pupil enrolled once; another refused; the same decision sent twice at once makes one enrolment (native). `ProgressionTest`: the same batch again, the same decisions, no new enrolment |
| FR-INS-05 AC3, BR-03 | `tests/sql/database.mjs`, `ProgressionTest`: the source enrolment's state, version and class unchanged; a decision never changed |
| FR-INS-05 AC4 | `tests/sql/database.mjs`: a repetition at another level, a promotion at the same level, a target in the same year, a promotion without its class or due date, a departure with a class, a blank reason, a pending enrolment, a pupil already enrolled in the target programme: refused. `ProgressionTest`: the same through the API, and the class's screen deciding for two pupils at once |

Validation on 16 October 2026: **258 PHP integration tests (10,340 assertions), 229 embedded SQL checks and 250 native PostgreSQL 16 checks including concurrency**; migration 040 rolled back alone with 4 decisions, their 4 enrolments and their journal in place (the enrolments kept, the journal's policy as 038 wrote it), applied again, then rolled back with all the others and applied again. Browser acceptance left to the CI: no new screen, a column and an action on a class's members.

## Increment 54 (lot 2): a group's composition notified

`041_composition_notices.sql`:

- **FR-GRP-02, step 4** (annex B, `group.membership.changed`): a pupil **joining** a group (an assignment, the target of a transfer, a promotion's class) or **leaving** it (an end, the source of a transfer) notifies the group's **lead teacher**: through their account, if they have one, are still with the school, still read the groups, and did not make the change themselves. The notification (`group.membership_changed`, no e-mail by default) names the group and the change only, no pupil (BR-19); it opens the group's members, which the teacher reads with their own rights (033).
- The waiting list of a full group (FR-GRP-02's SHOULD) waits for its decision (OD-14).

No new route. On screen, a composition's notification opens **the group**.

| Requirement | Evidence |
|---|---|
| FR-GRP-02, BR-19 | `tests/sql/database.mjs`: an assignment and a transfer notify the lead teacher of the group joined and left, by the group and the change only; a group without a lead teacher notifies nobody; the teacher's own change tells them nothing. `CompositionNoticeTest`: through the API, an assignment and its end in the teacher's bell, none in the author's, the notification opening the group |

Validation on 16 October 2026: **259 PHP integration tests (10,360 assertions), 230 embedded SQL checks and 251 native PostgreSQL 16 checks including concurrency**; migration 041 rolled back alone with 2 of its notifications and 541 memberships in place (the memberships kept), applied again, then rolled back with all the others and applied again. Browser acceptance left to the CI: no new screen, an action on a notification.

## Increment 55 (lot 2): the prospects' retention

`042_lead_retention.sql`:

- **NFR-DATA-03, BR-17**: a **lost** prospect is kept as long as the school says (**Anonymise a lost prospect after (months)**, `retention.lead_months`, 24 by default, accepted on 16 October 2026), counted from its closure, then **anonymised** by the daily run (`platform:retention`, after the pupils'): the contact's names, phone, e-mail and the school's fields, the reason of its loss, its children's names and birth dates, what was said in its interactions and reminders. What stays: its site, source, step, dates, its children's levels, and the number of its interactions. The run's counts go to the school's journal; an anonymised prospect changes no more (`lead_anonymised`). An open prospect is not touched; a registered one stays with its pupil, whose own policy applies (030).
- The settings' retention summary counts the lost prospects the next run will anonymise.

No new route.

| Requirement | Evidence |
|---|---|
| NFR-DATA-03, BR-17 | `tests/sql/database.mjs`: a prospect lost 25 months ago counted, then anonymised with its child, interaction and reminder, once, outside any school; one lost lately and an open one kept; the run's counts in the journal; the anonymised prospect not reopened. `LeadTest`: the same through the API, `platform:retention` and the retention summary |

Validation on 16 October 2026: **260 PHP integration tests (10,387 assertions), 231 embedded SQL checks and 252 native PostgreSQL 16 checks including concurrency**; migration 042 rolled back alone with 7 prospects, one of them anonymised, in place (the anonymised one kept as it is), applied again, rolled back and applied again, then rolled back with all the others and applied again. Browser acceptance left to the CI: no new screen, a row in Settings and in its retention summary.

## Increment 56 (lot 2): the prospects' import, and imported records anonymised

`043_lead_imports.sql`, `app/Imports/LeadsImporter.php`:

- **FR-IMP-01, FR-CRM-01** (section 3.3: the front office imports « élèves, CRM »): **Prospects** is a new kind of import, by `crm.lead.create` for the whole school. A row is a prospect by the reference of the school's file (`external_ref`, never changed): its contact (`last_name`, `first_name`, `phone`, `email`, `relationship`), its `source` (the school's code, in any case), its `site` by code (else the default site), and its first child (`child_first_name`, `child_last_name`, `child_birth_date`, `child_level` by the referential's code) and the school's own fields (`custom_<key>`). Created at the first step, or updated column by column; the same file again changes nothing (BR-05); a closed prospect is changed on screen only. A phone already known among prospects, pupils and guardians is refused unless the row gives a reason (`duplicate_reason`), kept in the journal. An import is undone, last first, while nothing has been built on its prospects (no interaction, reminder, conversion, closure: `school.discard_lead`).
- **A fix of the retention run (030, NFR-DATA-03)**: it clears an imported pupil's or guardian's file reference when it anonymises them, which the references' frozen code refused; the refusal counted as a rule keeping the record, and **imported pupils and guardians were never anonymised**. The run alone (its flag) now passes the frozen codes; nobody else does. The prospects' run clears their reference too.

No new route: `GET /api/imports/templates/leads` and `POST /api/imports` (`kind` `leads`) are the engine's.

| Requirement | Evidence |
|---|---|
| FR-IMP-01, FR-CRM-01, BR-05 | `LeadImportTest`: the template's columns; a file previewed, then imported: the annex and the source by their codes in any case, the child with its birth date and level, the e-mail as the screen stores it; the same phone as a pupil's without a reason, an unknown source, a row without phone nor e-mail refused with their line; the same file again unchanged, a changed cell updated; undone last first, an interaction since keeping its prospect |
| NFR-DATA-03, BR-17 | `tests/sql/database.mjs`: an imported pupil archived long enough anonymised, their file's reference cleared; outside the run, a reference still never changes. Prospects: their reference cleared with the rest |

Validation on 16 October 2026: **261 PHP integration tests (10,428 assertions), 232 embedded SQL checks and 253 native PostgreSQL 16 checks including concurrency**; migration 043 rolled back alone with 9 prospects, 2 of them imported, and 3 imports in place (the prospects kept without their reference, the frozen codes as 001 wrote them), applied again, then rolled back with all the others and applied again. Browser acceptance left to the CI: no new screen, a kind more on Imports.

## Next increments of lot 2

1. A full group's waiting list once OD-14 is decided. Payments themselves, which `access_granted` will read, and the progression's invoices come with lot 3. Replacements' groups (the rest of TST-03) come with lot 4's sessions.
2. The dashboard's conversion rate comes with lot 5's dashboards.

Lot 1's last exit item: the Arabic labels were confirmed by a native speaker on 16 October 2026; the choice of an Arabic font stays open.
