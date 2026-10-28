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

Two processes run beside the web server: `php artisan queue:work` (background jobs, queued emails) and `php artisan schedule:work` (the notification outbox, the prospects' due reminders, `platform:lead-reminders`, and the messages to guardians, `platform:guardian-messages`, every minute; daily, the purge of expired files and each school's retention policy, `platform:retention`).

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

The screens' font, Inter, has no Arabic letters: Arabic is shown in the system's font, which differs between browsers (a serif in WebKit on Windows). Decided on 16 October 2026: no Arabic font is shipped; Arabic keeps the system's font.

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
| `retention.lead_months` | A lost prospect is anonymised (increment 55) | 24 months after its closure | 1–120 |
| `retention.staff_years` | An archived member of staff is anonymised (increment 59) | 10 years after archiving | 1–50 |

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

## Increment 57 (lot 2): a programme copied to a later year

`044_program_copy.sql`:

- **FR-PRG-02** (its edge case « copie d'un programme vers l'année suivante »), **FR-PRG-01**: a programme is **copied** into a later year still open (`program_copy_year` otherwise): the same level, code, names, description, duration, price and payment terms, as a **draft** to review, with each subject's line in force at the source year's end (its volumes, session length and coefficient) from the target year's first day; all of it modifiable afterwards, the source as it was. The copy keeps the programme it came from (`copied_from`); the same copy asked again answers the first one; a code already taken in the target year is refused (`program_code_taken`). With the caller's rights: `program.program.manage` for the programme, `program.subject.manage` for its subjects.

Route: `POST /api/programs/{program}/copy` (`year_id`); a programme's reply has `copied_from`. On screen, **Programmes** copies a programme to one of the later years.

| Requirement | Evidence |
|---|---|
| FR-PRG-02, FR-PRG-01 | `tests/sql/database.mjs`: a copy a draft at the same price, in the target year, with its two subjects and their volumes from the year's first day; asked again, the same copy; a copy's volume changed, the source's kept; an earlier year and a code taken refused. `ProgramCopyTest`: the same through the API and from the Programmes screen |

Validation on 16 October 2026: **262 PHP integration tests (10,478 assertions), 233 embedded SQL checks and 254 native PostgreSQL 16 checks including concurrency**; migration 044 rolled back alone with 82 programmes, 2 of them copies, in place (the copies kept as programmes of their year), applied again, then rolled back with all the others and applied again. Browser acceptance left to the CI: no new screen, an action on Programmes.

## Increment 58 (lot 2): programmes and groups from files

`045_program_group_imports.sql`, `app/Imports/ProgramsImporter.php`, `app/Imports/GroupsImporter.php`:

- **FR-IMP-01, FR-PRG-01** (section 3.3: the head of studies imports « staff, groupes »): **Programmes** is a new kind of import, by `program.program.manage` for the whole school. A row is a programme by its `year` and `code` (in any case): its `level` by the referential's code, never changed afterwards; its names (`label_fr`, `label_ar`), `description`, duration (`duration_value`, `duration_unit`), `base_price` (« 120000,50 » or « 120000.50 ») and `status`. Created, or updated column by column: a new price makes a new version (BR-11), the enrolments made keeping theirs; an empty state or price keeps the current one.
- **FR-IMP-01, FR-GRP-01**: **Groups and classes** is a new kind of import, by `group.group.manage` for the whole school. A row is a group by its `year` and `code`: its `group_type`, `program` and `site` by code (never changed afterwards), names, `capacity`, `valid_from` (the year's first day without one), `valid_to`, `status`, its `lead_teacher` by the staff's file reference or number and its `main_room` by its code on the group's site. The database applies the screen's rules: a class needs its programme, places never below the members, a lead teacher still with the school, a main room seating the group (`room_too_small`).
- The same file again changes nothing (BR-05). An import is undone while nothing has been built on what it created: a programme without enrolment, group, subject or copy (with its prices, `school.discard_program`), a group nobody was placed in nor a promotion aimed at (`school.discard_group`); an update while its values are still in place.

No new route: the engine's `GET /api/imports/templates/{kind}` and `POST /api/imports` (`kind` `programs` or `groups`).

| Requirement | Evidence |
|---|---|
| FR-IMP-01, FR-PRG-01, BR-05, BR-11 | `ProgramGroupImportTest`: programmes by year and code in any case, the level by its code, an unknown one refused with its line; the price as the school writes it; the same file unchanged; a new price a new version, undone to the former one |
| FR-IMP-01, FR-GRP-01, FR-GRP-01 AC2 | `ProgramGroupImportTest`: a class with its programme, lead teacher by number and main room by code; a support group without programme; a class without programme, a room smaller than the class, an unknown programme refused with their line; the same file unchanged; the programmes not undone while their groups stand, then the groups, then the programmes and their prices |

Validation on 16 October 2026: **263 PHP integration tests (10,548 assertions), 233 embedded SQL checks and 254 native PostgreSQL 16 checks including concurrency**; migration 045 rolled back alone with 82 programmes, 62 groups and 5 of their imports in place (the programmes and groups kept, their prices immutable again), applied again, then rolled back with all the others and applied again. Local browser acceptance in Chromium: 188 screens without a problem, the Programmes' copy and the import kinds included. The CI could not start its jobs (the GitHub account's billing), so the screens in Firefox and WebKit were not run for increments 57 and 58.

## Increment 59 (lot 2): the staff's retention

`046_staff_retention.sql`:

- **FR-STF-01, NFR-DATA-03, BR-17**: a member of staff who **left and was archived** is kept as long as the school says (**Anonymise an archived member of staff after (years)**, `retention.staff_years`, 10 years by default, as a pupil's record; each school changes it in its settings), then **anonymised** by the daily run (`platform:retention`): the names (the number stands for the first name), phone, e-mail, the school's fields, the file's reference and the account's link; their contracts' reference, notes and end's reason, their leaves' notes, the reasons of their days off and of the decisions on their availability. What stays: their number, type, site, dates, qualifications, the types and hours of their contracts and leaves, so that lot 3 and 4's sessions, hours and pay keep pointing at the record (BR-02). The run's counts go to the school's journal; the settings' retention summary counts the members due.

No new route.

| Requirement | Evidence |
|---|---|
| FR-STF-01, NFR-DATA-03, BR-17 | `tests/sql/database.mjs`: a member archived 11 years ago counted, then anonymised with their contract and leave, once, outside any school; their number, type and hours kept; the run's counts in the journal. `StaffRetentionTest`: the same through the API (departure, archive), `platform:retention` and the retention summary |

Validation on 16 October 2026: **264 PHP integration tests (10,575 assertions), 234 embedded SQL checks and 255 native PostgreSQL 16 checks including concurrency**; migration 046 rolled back alone with 45 members of staff, one of them anonymised, in place (kept as it is, the guards as 031, 034 and 035 wrote them), applied again, then rolled back with all the others and applied again. Browser acceptance not run: no new screen, a row in Settings and in its retention summary (the CI could not start, the GitHub account's billing).

## Increment 60 (lot 2): a pupil's history on their sheet

`047_student_history.sql`:

- **FR-ELV-01** (« consulter la fiche d'un élève avec son historique »): the pupil's sheet shows **what happened to the pupil**, newest first: their record's creation, its changes (the fields changed, never their values, BR-17), its states with their reasons, its archiving and anonymisation, by whom; their enrolments' states with their programme and reason; the groups they joined and left; the guardians linked and unlinked; their year-end decisions. Each part as the reader may see it: the record's own entries through `school.student_history`, which gives them to whoever reads the pupil (the journal itself stays its readers', FR-AUD-01); the rest through the enrolments', groups' and guardians' own policies.

Route: `GET /api/students/{student}/history` (`student.record.read`), at most 200 events.

| Requirement | Evidence |
|---|---|
| FR-ELV-01, BR-17, FR-IAM-04 | `tests/sql/database.mjs`: a pupil's creation, a change of phone (its field, not its value) and a block with its reason, newest first; refused to a reader of another site's pupils. `StudentHistoryTest`: the same through the API with the enrolment, its group and the guardian; a reader of the pupils without their enrolments reads the record's history only; the sheet showing it |

Validation on 16 October 2026: **265 PHP integration tests (10,653 assertions), 235 embedded SQL checks and 256 native PostgreSQL 16 checks including concurrency**; migration 047 rolled back alone with 611 pupils and their 659 journal entries in place, applied again, then rolled back with all the others and applied again. Local browser acceptance in Chromium: 188 screens without a problem, the pupil's sheet with its history included (the CI could not start, the GitHub account's billing).

## Increment 61 (lot 2): a block names its enrolments; two screens' choices fixed

- **FR-ELV-03 AC1** (« bloquer un élève avec inscriptions actives avertit des inscriptions concernées et ne les modifie pas »): the block's refusal (`student_active_enrollments`) and the sheet's warning now **name** the enrolments concerned that the reader may see (programme, year, state), and count the others; still none is changed.
- **BR-07, FR-GRP-02**: a full class refuses the next pupil on its members' screen with the reason (`group_full`), now tested through the screen.
- **Two fixes**: on a group's members screen, **Add a member** failed when sent, since its search list could not name the chosen enrolment again (Filament refuses such a choice); converting a prospect with **a guardian already recorded** failed the same way (increment 51). Both now name their choice.

No new route, no migration.

| Requirement | Evidence |
|---|---|
| FR-ELV-03 AC1 | `BlockAndCapacityTest`: the refusal lists both enrolments, the pupil left active; the sheet's warning names them |
| BR-07, FR-GRP-02 | `BlockAndCapacityTest`: a class of one place, full, refuses the next pupil from its screen, one member kept |
| FR-CRM-02 | `LeadTest`: a child converted from the prospect's sheet with a guardian already recorded |

Validation on 16 October 2026: **266 PHP integration tests (10,701 assertions), 235 embedded SQL checks and 256 native PostgreSQL 16 checks including concurrency**. Browser acceptance not run again: no new screen; the CI could not start (the GitHub account's billing).

## Increment 62 (lot 3): what an enrolment owes and when

Lot 3 starts with **FR-FIN-01** (SQL 048): an enrolment's adjustments and its schedule. Amounts are `NUMERIC(14,2)` in the base and two-decimal strings in the API, never floats (BR-04).

- **What is owed**: the agreed price copied with the enrolment (BR-11), less its approved discounts and debt waivers, plus its approved penalties (`platform.enrollment_due_of`, internal; `school.enrollment_due`, for whoever reads the enrolment's finance). An adjustment never takes it below nothing (`adjustment_exceeds_due`).
- **Adjustments** (`school.adjustment`): a discount, a debt waiver or a penalty, **always with its reason** (AC2). Within the school's threshold (new setting `finance.discount.approval_percent`, 10 % of the agreed price for the enrolment's discounts and waivers together by default: the OD-09 proposal), approved at once; a penalty always. Beyond it, pending until a decision by the new permission **`finance.discount.approve`** (the `direction` template, added to the schools that have student finance), never by its author (BR-08, sod rule `adjustment`); a refusal says why. Decided, it never changes (`adjustment_decided`); none is deleted. The same key again answers the first one (BR-05).
- **Schedule** (`school.payment_schedule`, `school.installment`): versioned, each version written whole and never changed; the one in force is the latest. Its lines **must add up to what is owed** (AC1, `schedule_sum_mismatch`); dates in order, amounts positive (`schedule_invalid`); a version after the first says why. The first one is proposed from the programme's payment terms copied with the enrolment (share and due date of each term, else equal monthly shares), each line rounded half up to the centime and the last taking the remainder. An adjustment since the version in force is flagged on screen: the schedule waits for its next version.
- **Access to courses** (FR-INS-04): `school.enrollment_paid` now reads what is owed after adjustments; validated payments come with increment 63.
- **Screens**: an enrolment's **Finance** (from the Enrolments screen: what is owed, adjustments with their decision, schedule in force; adjust, new version of the schedule, decide); **Adjustments to approve** in a new Finance section of the navigation.

Routes, all behind the `student_finance` module: `GET /api/enrollments/{enrollment}/finance`, `GET /api/enrollments/{enrollment}/schedule/proposal`, `POST /api/enrollments/{enrollment}/schedule`, `POST /api/enrollments/{enrollment}/adjustments`, `GET /api/adjustments`, `POST /api/adjustments/{adjustment}/approve` and `/reject`.

| Requirement | Evidence |
|---|---|
| FR-FIN-01 AC1 | `FinanceScheduleTest`: a schedule of 100,000 refused for 120,000 owed; the proposal 36,000 + 84,000 from the terms 30 % / 70 %; a second version after the adjustments, with its reason; tests/sql: sum, order, version and immutability |
| FR-FIN-01 AC2 | `FinanceScheduleTest`: an adjustment without its reason refused; 10 % approved at once, beyond it pending; tests/sql: a reason required by the base |
| BR-08 | `FinanceScheduleTest`, tests/sql: the accountant cannot approve, the direction approves or refuses with its reason once; the author never decides |
| BR-05 | `FinanceScheduleTest`: the same schedule key gives one version |
| Screens | `FinanceScheduleTest`: an adjustment recorded and the first schedule set from the enrolment's Finance screen, approved from Adjustments to approve |

Validation on 16 October 2026: **268 PHP integration tests (10,983 assertions), 237 embedded SQL checks and 258 native PostgreSQL 16 checks including concurrency**; migration 048 rolled back alone, then with all the others, and applied again; local browser acceptance in Chromium: 196 screens without a problem, an enrolment's Finance and Adjustments to approve included. The CI could not start (the GitHub account's billing).

## Increment 63 (lot 3): payments, their validation and reversals

**FR-FIN-02** and **FR-FIN-03** (SQL 049). A payment always belongs to an enrolment; amounts stay `NUMERIC(14,2)` and two-decimal strings (BR-04).

- **Recording** (`school.record_payment`): amount, date (never to come), means among the school's (new setting `finance.payment.methods`: cash, cheque, transfer, CCP, other by default; card can be added), external reference. Each payment gets a reference `PAY-{year}-{six digits}`, unique in the school and never given again (AC3). The same key answers the first payment, **even sent five times at the same moment** (AC1, TST-10, native check); a key given again for another payment is refused. Never beyond what is left to pay, waiting payments counted, unless entered as an **overpayment** (its credit's decision comes with refunds, FR-FIN-04). A cancelled enrolment takes no new payment; a suspended one does and stays suspended (FR-INS-02 AC2).
- **The cash desk**: `finance.payment.create.delegated` records a payment marked `delegated`; like any payment, it waits (`pending_validation`) for the finance.
- **Validation** (AC3, BR-08): by `finance.payment.validate`, never by the payment's author, unless the school sets `finance.allow_self_validation` (off by default), which marks the payment `self_validated`; the message names the rule. Only a validated payment counts in what is paid (AC2).
- **Immutability** (TST-11): a validated, reversed or withdrawn payment never changes, in the API and in the base (`payment_immutable`); nothing is deleted. While it waits, its author corrects it or withdraws it with a reason (`payment_not_author` for anyone else).
- **Reversal** (AC2, `school.reverse_payment`): `finance.payment.reverse` asks for the reversal of a validated payment, its whole amount, with its reason, one at a time; someone else than its author validates it (no exception), and the payment becomes `reversed`, both kept. What is paid is the validated payments less their validated reversals.
- **Financial state** (FR-INS-02, TST-09): `unpaid`, `partially_paid` or `paid`, computed by `school.payment_balance` (what is owed, paid, waiting, left), never entered; payments never touch the pedagogical state. **Access to courses** (FR-INS-04): `full` needs what is owed paid; `first_installment`, the first instalment of the schedule in force (without a schedule, a payment).
- **Screens**: the enrolment's **Finance** shows what is paid, what is left, the financial state and the payments with their references, authors and validators; it records a payment, validates one, withdraws one's own, or reverses a validated one. **Payments to validate** lists the waiting payments and reversals, the cash desk's included, with their means and reference. The Enrolments screen opens the finance for whoever records or validates payments.

Routes, behind the `student_finance` module: `GET /api/payments`, `POST /api/enrollments/{enrollment}/payments`, `PATCH /api/payments/{payment}`, `POST /api/payments/{payment}/cancel`, `/validate` and `/reverse`; `GET /api/enrollments/{enrollment}/finance` now answers the cash desk and the validators too.

| Requirement | Evidence |
|---|---|
| FR-FIN-02 AC1, TST-10 | tests/sql (native): five sendings of one key at once make one payment; two payments at once never pass what is left; `PaymentTest`: the same key again, the same payment |
| FR-FIN-02 AC2 | `PaymentTest`, tests/sql: what is paid and left after each validation and reversal |
| FR-FIN-02 AC3 | tests/sql: a cancelled payment's reference is not given again; references unique |
| FR-FIN-03 AC1, TST-11 | `PaymentTest` (API), tests/sql (base): a validated payment's amount refused |
| FR-FIN-03 AC2 | `PaymentTest`, tests/sql: the reversal validated by someone else, the payment `reversed` and kept |
| FR-FIN-03 AC3, BR-08 | `PaymentTest`, tests/sql: the author refused with `segregation_of_duties`; the school's self-validation marked |
| TST-09, FR-INS-02 AC2 | tests/sql, `PaymentTest`: the enrolment stays pending, then suspended, when paid |
| Screens | `PaymentTest`: the cash desk records from the Finance screen, the finance validates from Payments to validate, reverses and validates the reversal from the Finance screen |

Validation on 16 October 2026: **270 PHP integration tests (11,291 assertions), 239 embedded SQL checks and 261 native PostgreSQL 16 checks including concurrency**; migration 049 rolled back alone, then with all the others, and applied again; local browser acceptance in Chromium: 200 screens without a problem, Payments to validate and the payments on an enrolment's Finance included. The CI could not start (the GitHub account's billing).

## Increment 64 (lot 3): refunds and overpayments

**FR-FIN-04** (SQL 050): a circuit distinct from payments.

- **Asked** by the finance (`finance.refund.request`) with its reason, once per key (BR-05), never beyond what is paid, the refunds already asked for counted (`refund_exceeds_paid`).
- **Decided** by the direction (`finance.refund.approve`): approved, or rejected with its reason; never by the one who asked (BR-08, sod rule `refund`).
- **Executed** by the finance once approved only (AC2, `refund_not_approved`): a refund with its means, date and outside reference, and its own reference `RMB-{year}-{six digits}`. What was refunded comes out of what is paid. Decided or executed, it never changes; none is deleted.
- **A credit** (paid beyond what is owed) is decided explicitly: refunded; **carried over** to another open enrolment of the same pupil (`refund_target_invalid` otherwise), whose execution records a linked payment there (means `carry_over`), validated by the finance as any other and never corrected, withdrawn or reversed (`payment_carried`); or **kept** by the school with its reason, as an adjustment `credit_retained` within the credit (`credit_exceeded`). The same circuit carries an enrolment's payments to the one it was transferred to (FR-INS-03).
- **AC1**: cancelling an enrolment moves no money; its payments stay, and the finance decides.
- **Screens**: the enrolment's **Finance** shows what was refunded and the credit, the refunds and carry-overs with their decision; it asks for a refund or a carry-over, decides one, executes one, or keeps a credit (Adjustment, « crédit conservé »). **Refunds to approve** lists the requests for the direction.

Routes, behind the `student_finance` module: `GET /api/refunds`, `POST /api/enrollments/{enrollment}/refunds`, `POST /api/refunds/{refund}/approve`, `/reject` and `/execute`.

| Requirement | Evidence |
|---|---|
| FR-FIN-04 AC1 | tests/sql: a paid enrolment cancelled, no payment nor refund added, what it paid unchanged |
| FR-FIN-04 AC2 | `RefundTest`, tests/sql: executing before the approval refused; a rejected refund never executed |
| BR-08 | `RefundTest`, tests/sql: the accountant cannot approve; an account with both rights cannot approve its own request |
| Credit | `RefundTest`, tests/sql: a refund, a carry-over validated on the other enrolment, the rest kept, the credit at nothing |
| Screens | `RefundTest`: the credit proposed as the amount, asked from the Finance screen, approved from Refunds to approve, executed from the Finance screen |

Validation on 16 October 2026: **272 PHP integration tests (11,517 assertions), 240 embedded SQL checks and 262 native PostgreSQL 16 checks including concurrency**; migration 050 rolled back alone, then with all the others, and applied again; local browser acceptance in Chromium: 204 screens without a problem, Refunds to approve and the refunds on an enrolment's Finance included. The CI could not start (the GitHub account's billing).

## Increment 65 (lot 3): receipts

**FR-FIN-05** (SQL 051) on the common PDF service (FR-PDF-01).

- **One receipt** per validated payment and per executed refund (`school.issue_receipt`), issued by its first download: numbered `REC-{year}-{six digits}` from a locked counter, so that receipts issued at the same moment never share a number (AC1, native check), and one payment printed twice at once has one receipt.
- **Frozen content**, written by the base when it is issued: the school's identity, the pupil and their matricule, the programme, the payment's or refund's reference, amount, means, outside reference and date, a refund's reason, and **what is left to pay just after it** (or the credit), computed at the moment the payment was validated or the refund executed (`platform.balance_at`). A later payment changes nothing in it; nothing changes it, nothing deletes it.
- **Printed again**: the same receipt, its content and time of then, marked « DUPLICATA » and watermarked. `App\Support\Pdf::render` takes, for a document frozen when issued, the identity and the time to print and a watermark.
- **Language**: the school's setting `finance.receipt.locale` (French by default, or Arabic), fixed on the receipt when it is issued. In Arabic, letters joined and amounts left to right (AC2, TST-17).
- Whoever reads the payment or the refund reads its receipt; the cash desk prints the receipt of a payment it recorded once validated. The enrolment's **Finance** screen links each validated payment's and executed refund's receipt.

Routes: `GET /api/payments/{payment}/receipt`, `GET /api/refunds/{refund}/receipt`.

| Requirement | Evidence |
|---|---|
| FR-FIN-05 AC1 | tests/sql (native): four printings of two payments at once, two receipts with two numbers |
| FR-FIN-05 AC2, TST-17 | `ReceiptTest`: an Arabic receipt read back by pdftotext, letters in their joined forms, amounts in order |
| Frozen, duplicate | `ReceiptTest`, tests/sql: the balance after the payment kept after a later payment; the second printing marked DUPLICATA with the same number |
| Validated only | `ReceiptTest`, tests/sql: a waiting payment's or an unexecuted refund's receipt refused (`receipt_not_validated`) |

Validation on 16 October 2026: **273 PHP integration tests (11,619 assertions), 241 embedded SQL checks and 264 native PostgreSQL 16 checks including concurrency**; migration 051 rolled back alone, then with all the others, and applied again; local browser acceptance in Chromium: 204 screens without a problem. The CI could not start (the GitHub account's billing).

## Increment 66 (lot 3): arrears

**FR-FIN-06** (SQL 052).

- **Open balances and overdue instalments**, as at a date (today by default), for the enrolments whose payments the reader reads (`finance.payment.read` for the whole school or the pupil's site), the cancelled ones left out (`school.arrears`): what is owed, paid and left; the instalments of the schedule in force whose date has passed and that what is paid does not cover (`overdue`, `overdue_since`); the next one to come. Computed, never stored; an enrolment without a schedule has nothing overdue. On an enrolment's Finance screen, the overdue amount and its date (FR-INS-02's `overdue` indicator).
- **AC1**: the list's totals are what the listed enrolments owe less what they paid, whatever the page. Filters: date, programme, group, overdue only, pupil.
- **AC2**: a teacher, or whoever reads no payment, reads none of it (`permission_denied`).
- **Controlled export**: the Arrears screen exports what it lists (CSV, Excel, PDF), for whoever reads the payments and exports, logged as any export (FR-IMP-02); also on the Exports screen.
- **Reminders**: each morning (`platform:finance-reminders`, 06:00 in Algiers), the instalments not yet covered that fall on the school's offsets (new setting `finance.reminder.offsets`: J-3, J and J+7 by default, among J-7 to J+30) are counted, and each member who reads the whole school's payments receives one notification, which opens the arrears; once a day per school. **No sanction is automatic**: nobody is suspended or blocked by it. Reminders to the payers themselves wait for their accounts (the parents' portal).

Route: `GET /api/arrears`. Screen: **Arrears** in the Finance section.

| Requirement | Evidence |
|---|---|
| FR-FIN-06 AC1 | `ArrearsTest`, tests/sql: totals of the open balances equal to what is owed less what is paid |
| FR-FIN-06 AC2 | `ArrearsTest`, tests/sql: a teacher refused by the API, the screen and the base; a site's reader limited to its site |
| Overdue | `ArrearsTest`, tests/sql: two instalments fallen due, one covered, the rest late since the second; due today not late yet |
| Reminders | `ArrearsTest`, tests/sql: the late instalment counted seven days after, notified once |
| Export | `ArrearsTest`: the screen's export, logged with its rows |

Validation on 16 October 2026: **274 PHP integration tests (11,756 assertions), 242 embedded SQL checks and 265 native PostgreSQL 16 checks including concurrency**; migration 052 rolled back alone, then with all the others, and applied again; local browser acceptance in Chromium: 208 screens without a problem, Arrears included. The CI could not start (the GitHub account's billing).

## Increment 67 (lot 3): the financial period's close

**FR-FIN-07**, **BR-15** (SQL 053).

- **Closure**: a past month, for the whole school, by `finance.period.close` held for the whole school (`school.close_period`), once no payment or reversal dated in it waits for its validation (`period_has_pending`); never the month in progress (`period_not_ended`), once at a time (`period_closed`). Its **reconciliation** is written with it and never changes: the validated payments dated in it by means (the carried-over ones apart), the reversals and the executed refunds dated in it, the carry-overs between enrolments, and what came in net (payments − reversals − refunds). Read before closing (`school.reconciliation`), with what still waits in the month.
- **AC1**: afterwards nothing is recorded, moved, validated or executed with a date in it (`period_closed`): a payment dated in it, a waiting payment moved into it, a validation, a refund executed on a day of it. A correction goes through a linked operation in the open period: a reversal is dated the day it is asked for, and the payment it reverses keeps its date. The check sits in the payments' and refunds' triggers, behind a lock the closure takes: a month closed while a payment dated in it is recorded never takes both.
- **AC2**: reopened by `finance.period.reopen` (the direction) for the whole school, with its reason (`reason_required`), never by whoever closed it (`segregation_of_duties`, sod rule `finance_period`); who and when are recorded, and the journal keeps the change. Reopened, the month takes its dates again until it is closed again; the former closure stays beside the new one. A closure is never deleted.

Routes: `GET /api/finance-periods`, `GET /api/finance-periods/reconciliation?month=`, `POST /api/finance-periods`, `POST /api/finance-periods/{period}/reopen`. Screen: **Closures** in the Finance section: the closed months, their net and reconciliation; *Close a month* shows the month's reconciliation before closing; *Reopen* for the direction.

| Requirement | Evidence |
|---|---|
| FR-FIN-07 AC1 | `FinancePeriodTest`, tests/sql: a payment dated in a closed month refused; a waiting payment moved into it, a refund executed on a day of it refused; a reversal of today accepted, its payment reversed |
| FR-FIN-07 AC1, concurrency | tests/sql native: a closure and a payment dated in the month at the same moment, never both |
| FR-FIN-07 AC2 | `FinancePeriodTest`, tests/sql: reopened by the direction with its reason, journalled; refused without reason, to the accountant, to whoever closed it |
| Reconciliation | `FinancePeriodTest`, tests/sql: payments by means, refunds, net, written with the closure and read before it |
| Conditions | `FinancePeriodTest`, tests/sql: refused while a payment waits, for the month in progress, twice, for a reader of one site |

Validation on 16 October 2026: **275 PHP integration tests (11,959 assertions), 243 embedded SQL checks and 267 native PostgreSQL 16 checks including concurrency**; migration 053 rolled back alone, then with all the others, and applied again; local browser acceptance in Chromium: 212 screens without a problem, Closures included. The CI could not start (the GitHub account's billing).

## Increment 68 (lot 3): a payment's supporting document

**FR-FIN-02** (« justificatif optionnel »), **FR-FIC-01 AC2** (SQL 054).

- **Optional document**: a cheque, a transfer's notice or a deposit slip (PDF or image, the school's file types and size, its real type checked), sent with the payment or added afterwards by its author while the payment waits for its validation; a cashier on delegation adds the one of the payment they collected. The same key sent again keeps the first document. Replaced while it waits, the former one is archived, not deleted: it stays a financial record.
- **Frozen once validated** (TST-11): neither changed nor removed by anyone (`payment_immutable`); someone else than its author adds none (`payment_not_author`); only a file made for this payment is accepted (`payment_attachment_invalid`).
- **AC2**: the document follows its payment: whoever reads the payment reads it (its author, or the finance of the pupil's site), nobody else, whatever the category's permission says: a teacher or a reader of another site's payments sees nothing, through the payment's route or the files' one.
- Screens: the enrolment's Finance takes a document with *Record a payment* and offers *Attach a document* for one's own waiting payments, with a link on each payment; *Payments to validate* shows which carry one and opens it before deciding.

Routes: `POST /api/payments/{payment}/attachment`, `GET /api/payments/{payment}/attachment`; `POST /api/enrollments/{enrollment}/payments` takes an optional `attachment`.

| Requirement | Evidence |
|---|---|
| FR-FIN-02 document | `PaymentAttachmentTest`, tests/sql: sent with the payment, kept on a repeated key, replaced while it waits with the former archived |
| TST-11 | `PaymentAttachmentTest`, tests/sql: frozen once validated; refused to another than its author |
| FR-FIC-01 AC1 | `PaymentAttachmentTest`: an executable named .pdf refused |
| FR-FIC-01 AC2 | `PaymentAttachmentTest`, tests/sql: a teacher and another site's reader see nothing; a cashier on delegation reads their own |
| Screens | `PaymentAttachmentTest`: recorded with its document, added afterwards, opened from the validations |

Validation on 16 October 2026: **276 PHP integration tests (12,043 assertions), 244 embedded SQL checks and 268 native PostgreSQL 16 checks including concurrency**; migration 054 rolled back alone, then with all the others, and applied again; local browser acceptance in Chromium: 212 screens without a problem. The CI could not start (the GitHub account's billing).

## Increment 69 (lot 3): expenses, their categories and validation

**FR-DEP-01**, **FR-DEP-02** (SQL 055), module `expenses`.

- **Categories** (FR-DEP-01): the publisher's six (`salaries`, `advertising`, `missions`, `rent`, `equipment`, `other`), given with the module to a school in use (a school not in use takes them back itself), named, ordered and archived by whoever validates expenses for the whole school; a code never changes; an archived category stays on its expenses. **AC1**: `salaries` is the payroll's (FR-REM-04): never entered by hand, never archived (`expense_category_system`). A category may ask what the expense is (`other`: its label).
- **An expense** (FR-DEP-02): its day (never to come, never in a closed month), amount, category, label, beneficiary, reason, the programme it is charged to if any; entered once per key (BR-05), a draft its author corrects and documents, then submits. It waits as it is, then is validated or rejected with its reason by `expense.expense.validate`, never by its author (**AC1**, sod rule `expense`); beyond the school's amount (new setting `expense.approval_amount`, 50,000 DA by default, OD-09 proposal), by the new permission `expense.expense.approve` (the direction). **AC2**: decided, it never changes, and is never deleted.
- **Documents**: files of the expense (PDF or image, real type checked), added by its author while it is a draft; **AC3**: read only with `expense.attachment.read`, even with their address; a teacher, or a reader of payments without that permission, sees none.
- Screens: **Expenses** in the Finance section (filters by state, category, period, beneficiary; entry, correction, documents, submission, decision) and **Expense categories**, opened from it.

Routes: `GET|POST /api/expense-categories`, `POST /api/expense-categories/defaults`, `PUT /api/expense-categories/{category}`, `POST /api/expense-categories/{category}/archive`, `GET|POST /api/expenses`, `GET|PUT /api/expenses/{expense}`, `POST /api/expenses/{expense}/submit`, `/validate`, `/reject`, `/attachments`, `GET /api/expenses/{expense}/attachments/{file}`.

| Requirement | Evidence |
|---|---|
| FR-DEP-01 AC1 | `ExpenseTest`, tests/sql: `salaries` refused by hand and never archived; an archived category refused |
| FR-DEP-02 AC1 | `ExpenseTest`, tests/sql: the author refused (`segregation_of_duties`); validated by another accountant, by the direction beyond the threshold |
| FR-DEP-02 AC2 | `ExpenseTest`, tests/sql: a submitted, validated or rejected expense never changes; no deletion |
| FR-DEP-02 AC3 | `ExpenseTest`, tests/sql: a teacher and a payments' reader see no document, by the expense's route or the files' one |
| BR-05, BR-15 | `ExpenseTest`, tests/sql: one expense per key; none dated to come or in a closed month |

Validation on 16 October 2026: **277 PHP integration tests (12,320 assertions), 245 embedded SQL checks and 269 native PostgreSQL 16 checks including concurrency**; migration 055 rolled back alone on the database the suite had filled (its first rollback failed: the checks of an expense depended on its table, now dropped first), then applied again; local browser acceptance in Chromium: 220 screens without a problem, Expenses and Expense categories included. The CI could not start (the GitHub account's billing).

## Increment 70 (lot 3): an expense's payments

**FR-DEP-02** (SQL 056), with **FR-FIN-07** and **BR-15** for the expenses; module `expenses`.

- **Committed and paid told apart**: a validated expense is committed; what is paid of it comes from its payments, computed, never entered: `unpaid`, `partially_paid`, `paid` (`payment_state`, with what is paid and what is left).
- **A payment** (`expense.expense.pay`, for the whole school): of a validated expense, by a means the school accepts (`finance.payment.methods`), with its reference (a cheque, a transfer), on a day not to come and not in a closed month, never beyond what is left; at once or in parts; recorded once per key (BR-05), even sent five times at once, and two payments at once never pass what is left (TST-10). It takes effect when recorded and never changes nor goes (TST-11).
- **Its reversal** (`expense.expense.reverse`): the whole amount of a payment, dated the day it is asked for, with its reason, one at a time; it waits for its validation by `expense.expense.validate`, never by whoever asked for it (BR-08, new sod rule `expense_payment_reversal`), or is rejected with a reason. Validated, it takes the payment back; the payment is kept, `reversed`.
- **Readers**: whoever reads, validates, pays or takes back the expenses reads their payments; a payments' reader or a teacher sees none.
- **Filters and exports**: the expenses also filtered by payment state, and exported as listed (`expenses`, CSV, Excel or PDF) by whoever reads them and exports, logged.
- **Closed months** (FR-FIN-07): a month closes once nothing dated in it waits for a decision, the submitted expenses and the reversals asked for included; an expense entered or submitted holds the closing lock like a payment. Its reconciliation adds the expenses validated with a day in it by category, what was paid of them by means, the reversals, and the month's cash balance (what came in net, less the expenses paid net).
- Screens: **Expenses** (payment state column and filter, *Pay*, *Payments*, *Export*), **Expense payments** (reversal, validation, rejection), **Closures** (the expense lines of a reconciliation).

Routes: `GET|POST /api/expenses/{expense}/payments`, `GET /api/expense-payments`, `POST /api/expense-payments/{payment}/reverse`, `/validate`, `/reject`; export kind `expenses`.

| Requirement | Evidence |
|---|---|
| FR-DEP-02 (pay) | `ExpensePaymentTest`, tests/sql: not before its validation; in parts; never beyond what is left; payment state computed |
| BR-05, TST-10 | `ExpensePaymentTest`, tests/sql natifs: the same key answers the first payment, five sendings at once record one, two payments at once never pass what is left |
| TST-11, BR-08 | `ExpensePaymentTest`, tests/sql: a payment never changed nor deleted; its reversal never validated by whoever asked for it, the original kept `reversed` |
| FR-DEP-02 (exports) | `ExpensePaymentTest`: the expenses filtered by payment state exported and logged; refused to a teacher |
| FR-FIN-07, BR-15 | tests/sql (and natifs): a month with a submitted expense not closed; its report with the expenses, their payments and the cash balance; nothing paid with a day in it afterwards; a closure and a payment dated in its month at once: the payment refused, or the closure waits for it and reports it |

Validation on 16 October 2026: **278 PHP integration tests (12,551 assertions), 247 embedded SQL checks and 272 native PostgreSQL 16 checks including concurrency**; migration 056 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 224 screens without a problem, Expense payments included. The CI could not start (the GitHub account's billing).

## Increment 71 (lot 3): an expense's corrections

SQL 057 (`database/schema/057_expense_corrections.sql`, migration `2026_10_16_000057`) corrects a validated expense without changing nor erasing it (FR-DEP-03):

- **A correction** (`expense.expense.reverse`, for the whole school): a negative entry linked to a validated expense, with its reason, dated the day it is asked for: part of what is committed (`correction`, never all of it) or all of it (`cancellation`, its amount set by the base). Asked for once per key (BR-05), even five times at once; one waits at a time of an expense, even two asked for at once.
- **Its decision**: validated by `expense.expense.validate`, never by whoever asked for it (BR-08, new sod rule `expense_correction`), or rejected with a reason. Decided, it never changes nor goes (TST-11); the expense keeps its amount, and what is committed of it (its amount and its validated corrections) is computed, `cancelled` once nothing is (AC1).
- **Never below what is paid**: checked when it is asked for and again when it is validated, under the expense's payments' lock, so a cancellation validated while the expense is paid never leaves more paid than committed; a paid expense is cancelled once its payments are taken back. A payment is now limited by what is committed after the corrections.
- **Closed months** (BR-15, FR-FIN-07): a correction is dated the day it is asked for, never in a closed month; a month's closure waits for the corrections dated in it, and its reconciliation adds them and what is committed once they are counted.
- **Readers**: whoever reads, validates, pays or corrects the expenses reads their corrections; a payments' reader or a teacher sees none. The expenses' export adds what is committed after corrections.
- Screens: **Expenses** (what is committed now under the amount, *Correct or cancel*, *Corrections*, the `cancelled` payment state), **Expense corrections** (validation, rejection), **Closures** (the corrections of a reconciliation).

Routes: `GET|POST /api/expenses/{expense}/corrections`, `GET /api/expense-corrections`, `POST /api/expense-corrections/{correction}/validate`, `/reject`.

| Requirement | Evidence |
|---|---|
| FR-DEP-03 AC1 | `ExpenseCorrectionTest`, tests/sql: cancelling a validated expense creates a linked negative entry and keeps the original; what is committed computed, `cancelled` |
| BR-08, TST-11 | `ExpenseCorrectionTest`, tests/sql: never validated by whoever asked for it, on the API and the screen; decided, never changed nor deleted |
| BR-05, BR-06 | tests/sql natifs: five sendings of a cancellation ask once; two corrections at once leave one waiting; a cancellation validated while the expense is paid: never both |
| FR-DEP-02 (paid) | `ExpenseCorrectionTest`, tests/sql: never corrected below what is paid; a payment limited by what is committed after the corrections |
| FR-FIN-07, BR-15 | tests/sql: a correction waiting counts in its month; validated, the reconciliation reports it and what is committed |

Validation on 16 October 2026: **279 PHP integration tests (12,719 assertions), 249 embedded SQL checks and 275 native PostgreSQL 16 checks including concurrency**; migration 057 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 228 screens without a problem, Expense corrections included. The CI could not start (the GitHub account's billing).

## Increment 72 (lot 3): the remuneration rules

SQL 058 (`database/schema/058_pay_rules.sql`, migration `2026_10_16_000058`) says how each member of staff is paid (FR-REM-01), with the `payroll` module:

- **A version** from the first day of a month until the last day of one (none for an open one): `fixed_monthly` (its monthly amount), `hourly` or `per_session` (a default rate at least), `mixed` (a fixed part, a variable basis in hours or sessions, a default rate, what it includes, 0 by default) (AC6, AC7). Written with its rates and recurring bonuses all or nothing, once per key (BR-05), even sent five times at once. A member's versions never overlap, even two written at once.
- **The member's type** (AC3): the administrative staff and `other` on a fixed salary only; a member who left or an archived record receives none.
- **Rates** by default and by node of the school's referential (a level, a stream, a speciality, BR-13), one per node (AC8); `school.pay_rate` gives a node's own rate, else its nearest ancestor's, else the default, none when nothing resolves (AC5, BR-16).
- **Versions** (BR-10, AC1, AC4): begun, a version keeps its amounts, rates and bonuses; a change is a new version from the first day of this month or one to come, which ends the one in force the day before; never before (no retroactive change). A version ends earlier at the end of a month not gone by, with its reason; one not begun is deleted, the one it ended in force again.
- **Readers** (AC2): written by `payroll.rule.manage` for the whole school (the accountant), read with `payroll.amount.read` too (the direction); academic_hr, who manages the contracts, reads none.
- Screen: **Remuneration** of a member of staff, from the staff list and their contracts (versions, rates, bonuses; a new version, an end, a deletion).

Routes: `GET|POST /api/staff/{staff}/pay-rules`, `GET /api/pay-rules/{rule}/rate?node=`, `POST /api/pay-rules/{rule}/end`, `DELETE /api/pay-rules/{rule}`. The browser acceptance's school is now on the `complete` plan, so that the payroll's screens are checked.

| Requirement | Evidence |
|---|---|
| FR-REM-01 AC1, AC4 | `PayRuleTest`, tests/sql: a change is a new version from this month or one to come, the months before kept; a version begun never changes |
| FR-REM-01 AC2 | `PayRuleTest`, tests/sql: academic_hr refused on the API, the screen and the table; the direction reads, never writes |
| FR-REM-01 AC3, AC6, AC7, AC8 | `PayRuleTest`, tests/sql: an hourly administrative refused; an incomplete mix, a fixed salary with a rate, two rates for a node, a node outside the referential refused |
| FR-REM-01 AC5 | `PayRuleTest`, tests/sql: with a Secondary rate and a default, 2AS takes the Secondary rate, 4AM the default |
| BR-05, BR-10 | tests/sql natifs: two versions from the same month at once leave one; five sendings of one write it once |

Validation on 16 October 2026: **280 PHP integration tests (12,989 assertions), 250 embedded SQL checks and 277 native PostgreSQL 16 checks including concurrency**; migration 058 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium on the `complete` plan: 232 screens without a problem, Remuneration included. The CI could not start (the GitHub account's billing).

## Increment 73 (lot 3): the monthly pays

SQL 059 (`database/schema/059_payroll_runs.sql`, migration `2026_10_16_000059`) computes each member's pay for a month (FR-REM-02, FR-REM-03), with the `payroll` module:

- **One pay per member and month** (AC1, TST-12), generated by `payroll.run.generate` (the accountant) for a month begun, for the whole school or one member, as often as needed: a draft is computed again, a validated pay left as it is; five generations at once give one. A member with no day with the school that month receives none.
- **The computation** (TST-19), every line rounded to the cent and the total their sum (BR-04): the fixed part of a fixed salary or a mix over the days of the month with the school (AC9; `payroll.prorate_fixed`, on by default, in the school's settings); each unit done, entered by hand in lot 3 (a level, a day of the month gone by, hours or whole sessions), at the rate of its level, its nearest ancestor or the default (AC3, AC5, AC6, AC10); a mix paying only the units beyond those it includes, the month's earliest (AC7, AC11), never below its fixed part (AC12); the recurring bonuses, then the one-off bonuses and the adjustments, each with its reason (FR-REM-03). A unit no rate resolves is a line marked « missing rate », never counted at zero (AC8, BR-16).
- **The detail** keeps the copy of the version, the level, the quantity and the rate applied (BR-03): a rule changed afterwards rewrites no validated pay (FR-REM-03 AC2), and a draft computed before it is refused at validation until computed again.
- **Validation** by `payroll.run.validate` (the direction), never by whoever computed it (BR-08), with no line lacking its rate and a net not below zero; then frozen. A correction is an adjustment of a later month linked to the validated pay it corrects (FR-REM-03 AC1), once per key (BR-05).
- **Readers**: `payroll.amount.read`, `payroll.run.generate`, `payroll.run.validate`; never academic_hr.
- Screens: **Pays** (a month's pays, teaching and administrative staff told apart; generation; validation) and the **detail of a pay** (its lines, its units entered, its bonuses and adjustments, a new computation, its validation).

Routes: `GET /api/payroll-runs?period=`, `POST /api/payroll-runs/generate`, `GET /api/payroll-runs/{run}`, `POST /api/payroll-runs/{run}/units`, `POST /api/payroll-runs/{run}/adjustments`, `GET /api/payroll-runs/{run}/corrected`, `POST /api/payroll-runs/{run}/validate`.

| Requirement | Evidence |
|---|---|
| FR-REM-02 AC1, TST-12 | `PayrollRunTest`, tests/sql: generated again, one pay a member; five generations at once, one |
| FR-REM-02 AC3 to AC7, AC9 to AC12, TST-19 | tests/sql: 63 750,00; 32 000,00; 36 000,00; 38 500,00; 49 000,00; 52 000,00 (the 10 earliest included); the fixed part only for 18 h of 20; a month begun on the 11th prorated, or not by the school's setting; `PayrollRunTest`: AC4 and AC5 through the API |
| FR-REM-02 AC2, AC8 | tests/sql: a validated pay never computed again; a missing rate marked and blocking the validation |
| FR-REM-03 AC1, AC2 | `PayrollRunTest`, tests/sql: a validated pay refused any change, its correction an adjustment of a later month linked to it; a rate changed after the validation changes neither the detail nor the total |
| BR-05, BR-08 | `PayrollRunTest`, tests/sql natifs: an adjustment sent five times at once, once; whoever computed a pay never validates it, even holding both rights |

Validation on 17 October 2026: **281 PHP integration tests (13,369 assertions), 251 embedded SQL checks and 279 native PostgreSQL 16 checks including concurrency**; migration 059 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 240 screens without a problem, Pays and a pay's detail included. The CI could not start (the GitHub account's billing).

## Increment 74 (lot 3): the payouts of the pays and the personal view

SQL 060 (`database/schema/060_payroll_payouts.sql`, migration `2026_10_17_000060`) pays the validated pays (FR-REM-04) and lets a member read their own (FR-REM-05):

- **Payouts** by `payroll.payout.record` (the accountant), of a validated pay only, at once or in parts, never beyond what is left of its net, once per key (BR-05), dated neither to come, before the pay's month nor in a closed month (BR-15). The pay's state follows: `validated`, `partially_paid` (AC1), `paid`.
- **The expense of each payout** (BR-06): in the payout's transaction, the database writes the validated expense of the `salaries` category, of the same amount and day, with its payment by the same means. No one enters, pays, corrects or takes back an expense of the payroll by hand (`expense_payroll`); each month, the `salaries` expenses committed equal what is paid of the pays, never counted twice (AC2, TST-13).
- **A payout never changes**: it is taken back by its reversal, its whole amount, with its reason, validated or rejected by `payroll.run.validate` (the direction), never by whoever asked for it (BR-08). Validated, it takes back the expense's payment and cancels the expense, on the day it was asked for; the originals are kept.
- **The month's closure** waits for the reversals waiting with a day in it, and its reconciliation reports the payouts, their reversals, what is paid of the pays and the `salaries` expenses committed, which equal it.
- **What a member is paid** is read with the pays' rights only, even as an expense.
- **Personal view** (FR-REM-05): once the school turns on `payroll.self_view` (off by default, in its settings), a member holding `payroll.self_view` (the teachers) reads their own validated pays: the hours or sessions each counts, its detail and its payouts; never a draft nor anyone else's pay.
- Screens: the **detail of a pay** pays it, takes a payout back and decides a reversal; **Pays** shows each pay's state and what is left; **My pay** (« Ma rémunération ») for the member; the **closure** shows the payouts.

Routes: `POST /api/payroll-runs/{run}/payouts`, `POST /api/payroll-payouts/{payout}/reverse`, `POST /api/payroll-payouts/{payout}/validate`, `POST /api/payroll-payouts/{payout}/reject`, `GET /api/my-pay`, `GET /api/my-pay/{run}`.

| Requirement | Evidence |
|---|---|
| FR-REM-04 AC1 | `PayrollPayoutTest`, tests/sql: half of the net paid, `partially_paid`, and a `salaries` expense of that amount, validated and paid; the rest paid, `paid` |
| FR-REM-04 AC2, TST-13 | `PayrollPayoutTest`, tests/sql: each month, the `salaries` expenses committed equal what is paid of the pays, a reversal included; one expense a payout |
| BR-05, BR-06 | tests/sql natifs: a payout sent five times at once, once, with one expense; two payouts at once never beyond the net |
| BR-08 | `PayrollPayoutTest`, tests/sql: a reversal decided by someone else than whoever asked for it; its expense cancelled with it |
| FR-DEP-01 AC1 | `PayrollPayoutTest`, tests/sql: an expense of the payroll never paid, corrected nor taken back by hand, nor written but by its payout |
| FR-REM-05 | `PayrollPayoutTest`, tests/sql: nothing before the school says so; then their own validated pay, its lines and payouts; never a draft nor a colleague's |

Validation on 17 October 2026: **282 PHP integration tests (13,606 assertions), 252 embedded SQL checks and 281 native PostgreSQL 16 checks including concurrency**; migration 060 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 244 screens without a problem, My pay included. The CI could not start (the GitHub account's billing).

## Increment 75 (lot 3): the finance's Arabic screens

In the « by » columns of an enrolment's finance and of a pay, each person is written on their own line: Latin names no longer run into one another around the Arabic comma. The references (`PAY-`, `RMB-`) keep their own direction, and a refund's state is written one piece of information a line. The Arabic receipts and screens of the review pack were accepted by the project's owner, a native reader, on 17 October 2026 (`REVUE_LOT3.md`).

## Increment 76 (lot 4): the calendar

SQL 061 (`database/schema/061_calendar.sql`, migration `2026_10_17_000061`) gives the school its calendar (FR-PLN-01), on which lot 4's sessions will be planned:

- **The week's open days** stay a setting (`calendar.open_days`, Sunday to Thursday by default).
- **Dated exceptions** prevail over the week, for the whole school or one site: closures (public holiday, vacation, other) and exceptional openings (a make-up day), from one day until another, with their reason. Never two of the same scope on a day, even for two requests at once; a site's prevails over the whole school's (`school.day_open(day, site)`). An exception starts today or later, keeps its days and reason, and is cancelled until its last day, never deleted.
- **The grid of time slots**: a code, a start and an end to the minute (until 24:00), never two in use with the same times; a slot keeps its code and times and is archived, never deleted.
- **Who keeps it**: the new permission `schedule.calendar.manage` (the administrator and the pedagogy and HR manager), for the whole school or on the sites of a scoped grant; the grid needs a school-wide grant. Every member who reads the planning reads the calendar.
- Screens, in the new **Planning** section: **Calendar** (the week's days, the exceptions to come, adding and cancelling one) and **Time slots**.

Routes: `GET /api/calendar/days?from=&to=&site_id=` (92 days at most), `GET|POST /api/calendar-exceptions`, `POST /api/calendar-exceptions/{exception}/cancel`, `GET|POST /api/time-slots`, `POST /api/time-slots/{slot}/archive`.

| Requirement | Evidence |
|---|---|
| FR-PLN-01 | `CalendarTest`, tests/sql: Sunday to Thursday by default; a public holiday closes every site; a make-up Friday on one site only; a site's opening prevails over the school's vacation; cancelled, the week is back |
| FR-PLN-01 | tests/sql natifs: two overlapping closures of the school made at once, one recorded |
| FR-IAM-04 | `CalendarTest`, tests/sql: a site's grant keeps its site's calendar, never another's nor the whole school's, nor the grid; a teacher reads and changes nothing |

Validation on 17 October 2026: **286 PHP integration tests (13,914 assertions)**, the two the first run failed (the access matrix to regenerate, a placeholder written in the code) passing once fixed; **254 embedded SQL checks and 284 native PostgreSQL 16 checks including concurrency**; migration 061 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 252 screens without a problem, Calendar and Time slots included. The CI could not start (the GitHub account's billing).

## Increment 77 (lot 4): sessions and their weekly series

SQL 062 (`database/schema/062_sessions.sql`, migration `2026_10_17_000062`) plans the sessions (FR-PLN-02) and refuses their conflicts before writing (FR-PLN-03); the API comes first, the screens with the planning's views:

- **A weekly series**: a group, a subject, a teacher, a room or none, a day of the week and its hours (typed, or a slot of the grid), from one day until another, planned once per key. Its sessions are generated on the days its group's site is open (AC1: closures and closed days skipped), in Algiers' time; a blocking conflict on any day refuses the whole series. Read beforehand day by day (`session-series/check`).
- **A session** is planned on its own or by its series. Changed on its own (teacher, room, day, hours), it leaves its series (AC2); the series changed from a day on (today or later) changes its sessions still planned and not changed on their own; ended sooner, the sessions after its new last day go. A session is cancelled with its reason, never deleted; only a planned one changes, never one whose attendance is entered (`locked`, ready for the attendance).
- **Blocking conflicts**, refused by the database: a teacher, a room or a group already taken — PostgreSQL exclusion constraints keep it for requests at the same moment (TST-15) — and a pupil of the group in another group of any programme at the same time (AC2, the group's pupils locked one by one so that the second request sees the first); a teacher not qualified (FR-STF-02), unavailable (FR-STF-03), gone or not a teacher; a room of another site, closed or unavailable (FR-SLL-02), or with fewer places than the group; a day closed (FR-PLN-01 AC1); a subject the programme does not teach; a group not active or outside its dates.
- **Warnings**, told and never refused: the teacher's hours a day or a week beyond their bounds, the programme's volume (sessions, hours, hours a week), a change of site without the travel time (`planning.travel_minutes`, 30 by default).
- **The calendar the other way round**: a day with sessions is closed no more, and an opening is cancelled only while none of its days has a session.
- **The volume follow-up** of a group: by subject, the sessions and hours planned and delivered (attendance, next increments) against the programme's volume in force.
- Read with `schedule.session.read` (the whole school, the group's site, the groups of a scoped grant, or one's own sessions as their teacher); planned with `schedule.session.create`, changed with `schedule.session.update`, cancelled with `schedule.session.cancel`, on the group's site.

Routes: `GET /api/class-sessions?from=&to=&group_id=&teacher_id=&room_id=` (62 days at most), `GET /api/class-sessions/{session}`, `POST /api/class-sessions/check`, `POST /api/class-sessions`, `PUT /api/class-sessions/{session}`, `POST /api/class-sessions/{session}/cancel`, `GET /api/session-series?group_id=`, `POST /api/session-series/check`, `POST /api/session-series`, `POST /api/session-series/{series}/change`, `POST /api/session-series/{series}/end`, `GET /api/groups/{group}/volume`.

| Requirement | Evidence |
|---|---|
| FR-PLN-02 AC1 | tests/sql: thirteen Sundays, a public holiday and two of vacation, ten sessions; `ClassSessionTest`: eight Sundays, a holiday, seven |
| FR-PLN-02 AC2 | tests/sql, `ClassSessionTest`: a session moved on its own keeps its hours when the series changes; the others follow |
| FR-PLN-03 AC1, TST-15 | tests/sql natifs: five sessions of one teacher at the same hour sent at once, one; the exclusion constraints refuse what the check would not see |
| FR-PLN-03 AC2 | tests/sql (and natifs, at once), `ClassSessionTest`: a pupil in two groups of different programmes, refused |
| FR-PLN-03 | tests/sql, `ClassSessionTest`: not qualified, unavailable, room closed, elsewhere or too small, closed day, subject outside the programme refused; daily, weekly and volume bounds told only |
| FR-PLN-01 AC1 | tests/sql, `ClassSessionTest`: no session on a closed day; a day with sessions closed no more |

Validation on 17 October 2026: **289 PHP integration tests (14,134 assertions), 259 embedded SQL checks and 290 native PostgreSQL 16 checks including concurrency** (TST-15); migration 062 rolled back alone on the database the suite had filled, then applied again. No screen changed: the browser acceptance comes with the planning's screens. The CI could not start (the GitHub account's billing).

## Increment 78 (lot 4): the planning's screens, timetables and agendas

The planning as people read it (FR-PLN-04), with the screens of increment 77's sessions:

- **Planning** (« Planning », in the Planning section): the sessions of a week, by group, teacher or room, the cancelled ones on demand. It plans a weekly course (a slot of the grid gives its hours), adds a session, changes one on its own (it then leaves its course) or cancels it with its reason. What conflicts is refused with its reason; what is only a warning is told once saved.
- **The week's timetable in PDF** of a group, a teacher or a room: the school's open days in columns (and a day exceptionally open), the hours in rows, each session with its subject, room and teacher or group; cancelled ones struck through; right to left in Arabic.
- **An agenda file (ICS)** of a group, a teacher, a room or one's own sessions, ninety days at most: each start and end written in Algiers' time with its zone, described as UTC+1 all year, so that an agenda shows the school's hours wherever it is opened (AC1); a cancelled session is sent as cancelled so that the agenda drops it.
- **My timetable** (« Mon emploi du temps », personal section) for a teacher whose account is their staff record: their sessions over four weeks and their agenda.
- SQL 063 (`database/schema/063_teacher_groups.sql`, migration `2026_10_17_000063`): a teacher's personal grant reads the groups they teach, with their members and pupils, as it reads those they lead: the groups of their sessions not cancelled, from a month ago on.

Routes: `GET /api/timetable/week.pdf?week=&group_id=|teacher_id=|room_id=`, `GET /api/timetable/calendar.ics?from=&to=&group_id=|teacher_id=|room_id=|mine=1`, `GET /api/timetable/mine?from=&to=`.

| Requirement | Evidence |
|---|---|
| FR-PLN-04 AC1 | `TimetableTest`: `DTSTART;TZID=Africa/Algiers:…T080000` for a session at eight, the zone described UTC+1, a cancelled session `STATUS:CANCELLED`, lines folded at 75 octets |
| FR-PLN-04 | `TimetableTest`: the week's timetable of a group and of a teacher read back from the PDF; the Planning screen plans, changes and cancels; a teacher reads their own timetable, not a colleague's sessions |
| FR-IAM-04 | tests/sql: a teacher reads the group they teach and its members, not the others; a session cancelled opens nothing more |

Validation on 17 October 2026: **292 PHP integration tests (14,494 assertions)**, the one the first run failed (a dash written in the code as a placeholder) passing once fixed; **260 embedded SQL checks and 291 native PostgreSQL 16 checks including concurrency**; migration 063 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 256 screens without a problem, Planning included. The CI could not start (the GitHub account's billing).

## Increment 79 (lot 4): replacements

A session's teacher replaced, and the history of the sessions' changes (FR-PLN-06):

- **Replace** (« Remplacer », on a session of the Planning screen, before it ends): the substitutes offered are the teachers qualified, available and free at its hours, with no other blocking conflict; the database checks them again on writing (AC1). The reason is required. The session becomes `replaced` and keeps the teacher planned; choosing that teacher gives it back to them. Only its teacher changes while it is replaced; it leaves its weekly course, whose changes no longer touch it. A replaced session can still be cancelled.
- **Session history** (« Historique des séances », in the Planning section): the sessions cancelled, replaced or given back, of a group or all, eight weeks from the day chosen, each change with its reason, the teacher before and after, who made it and when (AC2). Read as the sessions are; never changed.
- **Notices**: each cancellation, replacement and return of a session not yet over is told to its teachers (the one planned, the one before, the one after) and to the pupils of the group that day, through their accounts, if they read the planning; the author aside. The notice names the session, its group and its day only (BR-19).
- The teacher replaced still sees the session, in their timetable and on the Planning screen filtered on them; their agenda (ICS) and their week's PDF show it as not theirs to hold. The group's agenda and timetable show the substitute.
- SQL 064 (`database/schema/064_replacements.sql`, migration `2026_10_17_000064`): the replacement's columns, its rules in the session's guard, the history table, the notices, `school.substitutes(session)`.

Routes: `GET /api/class-sessions/{session}/substitutes`, `POST /api/class-sessions/{session}/replace` (`teacher_id`, `reason`, `version`), `GET /api/session-changes?from=&to=&group_id=`.

| Requirement | Evidence |
|---|---|
| FR-PLN-06 AC1 | tests/sql and `ReplacementTest`: a teacher not qualified refused (`session_teacher_not_qualified`, its message), one busy refused, a reason required, the substitutes listed qualified and free only |
| FR-PLN-06 AC2 | tests/sql and `ReplacementTest`: the group's history lists the replacement, the return and the cancellation with their reasons and teachers; the substitute reads the changes they were part of, a pupil without the planning none |
| FR-PLN-06 | tests/sql: the teacher planned kept by the database, the substitute busy at the session's hours and the teacher planned free; the teachers and the pupil who read the planning told of each change, the author and a pupil without the planning not; `ReplacementTest`: the teacher replaced sees the session, their agenda cancels it; the Planning screen replaces, the history screen lists it |

Validation on 17 October 2026: **295 PHP integration tests (14,680 assertions)**; **261 embedded SQL checks and 292 native PostgreSQL 16 checks including concurrency**; migration 064 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 260 screens without a problem (continuous integration still blocked by the GitHub account's billing).

## Increment 80 (lot 4): the timetable's versions and publication

The timetable is prepared, validated, then published (FR-PLN-05). A version covers the whole school over a period (the scope proposed on 17 October 2026):

- **Publications** (« Publications du planning », in the Planning section, for those who plan or publish): the versions, newest first, with their period, state, reason, number of sessions, who prepared and published them. **Prepare** a version over a period with its reason: a copy of the sessions not cancelled, one version open at a time over the same days. **Conflicts** lists its sessions in a blocking conflict, checked again as things are now (a room made smaller, a pupil who joined another group, a qualification ended). **Validate** and **Publish** (`schedule.timetable.publish`) are refused while a session is in a blocking conflict (AC1) or has changed since the copy; **Refresh the copy** takes the sessions again and makes it a draft to validate anew. **Discard** sets a version not yet published aside. **Compare** lists the sessions added, removed or changed between the version and another one, or the sessions as they are now.
- Publishing supersedes the version published over the same days. It tells, through their accounts, the teachers (before and after) and the pupils of the groups whose sessions it adds, removes or changes against the version it supersedes, if they read the planning; nobody else, and nothing when it changes nothing for them.
- AC2: a cancellation, a replacement or a return (increment 79) is kept in the history at once, but told only once its day has been published.
- Versions are never deleted; a version and its copy change only through the database's functions.
- SQL 065 (`database/schema/065_timetable_versions.sql`, migration `2026_10_17_000065`).

Routes: `GET /api/timetable-versions`, `GET /api/timetable-versions/{version}`, `GET …/{version}/conflicts`, `GET …/{version}/compare?with=`, `POST /api/timetable-versions` (`date_from`, `date_to`, `reason`), `POST …/{version}/refresh|discard` (`schedule.session.update`), `POST …/{version}/validate|publish` (`schedule.timetable.publish`), each with `version`.

| Requirement | Evidence |
|---|---|
| FR-PLN-05 AC1 | tests/sql and `TimetableVersionTest`: a room made too small since, the session listed in conflict with its reason, validation refused (`timetable_conflicts`); refused too with a copy out of date (`timetable_version_stale`) or not validated |
| FR-PLN-05 AC2 | tests/sql and `TimetableVersionTest`: a replacement and a cancellation before publication told to nobody, the teacher told on publication and of the next cancellation; a publication changing nothing of theirs tells them nothing |
| FR-PLN-05 | tests/sql: versions numbered, one open at a time over the same days, changed only through their functions, never deleted; validated and published by `schedule.timetable.publish` only; the published one superseded by the next; two versions compared; a teacher reads the copies of their sessions, not the versions. `TimetableVersionTest`: the Publications screen prepares, lists the conflicts, validates, publishes and compares |

Validation on 17 October 2026: **297 PHP integration tests (14,909 assertions)**; **262 embedded SQL checks and 293 native PostgreSQL 16 checks including concurrency**; migration 065 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 264 screens without a problem (continuous integration still blocked by the GitHub account's billing).

## Increment 81 (lot 4): the teachers' attendance

The teachers' attendance entered session by session, and validated by someone else (FR-PTG-01, FR-PTG-02, TST-05):

- **Teachers' attendance** (« Pointage des enseignants », in the Planning section, for the supervisor and whoever validates): the sessions begun on a day, a week or a month, with their entry or « not entered ». A session not entered is never an absence (AC1).
- **Enter** (`attendance.teacher.entry.create`): present, late (minutes), absent, or replaced by a teacher qualified and not elsewhere at those hours; the hours actually held when they differ; a note. Manual only. A session to come is refused (AC2), as is a cancelled one or one older than the school's delay (`attendance.entry_days`, 7 days, in the settings). One entry a session; the entry locks the session (no more changes, cancellation or replacement).
- **Correct** (`attendance.teacher.entry.update`): by its author only, until it is decided; submitted again.
- **Validate** or **Reject** with a reason (`attendance.teacher.validation.approve`): never by its author (BR-08), whatever roles they hold, the refusal saying the rule (TST-05). A rejected entry goes back to its author, who is told. **Validate the period's submitted entries** validates at once the others' entries of the day, week or month shown, and says how many of one's own were left. Validated, the session is held (`delivered`) unless the teacher was absent, and the entry no longer changes here (traced corrections come with the hours, FR-PTG-03).
- SQL 066 (`database/schema/066_teacher_attendance.sql`, migration `2026_10_17_000066`).

Routes: `GET /api/teacher-attendance?from=&to=&state=&group_id=&teacher_id=`, `POST /api/teacher-attendance`, `PUT /api/teacher-attendance/{attendance}`, `POST /api/teacher-attendance/{attendance}/validate|reject`, `POST /api/teacher-attendance/validate-period`.

| Requirement | Evidence |
|---|---|
| FR-PTG-01 AC1 | `TeacherAttendanceTest`: the sessions not entered listed with no entry, filtered « not entered »; tests/sql: no entry, the session still planned and open |
| FR-PTG-01 AC2 | tests/sql and `TeacherAttendanceTest`: a session to come refused (`attendance_future`) |
| FR-PTG-02 AC1, TST-05, FR-IAM-05 AC1, AC2 | tests/sql and `TeacherAttendanceTest`: one holding the supervisor's and the pedagogy manager's roles validates another's entry, never their own (`segregation_of_duties`, its message); the supervisor validates nothing |
| FR-PTG-01, FR-PTG-02 | tests/sql: late without minutes, hours apart, a substitute not qualified or the teacher themselves refused; one entry a session, which locks it; corrected by its author only; rejected with its reason and its author told; validated, the session held, an absence not; validated, frozen and never deleted. `TeacherAttendanceTest`: the period validated at once, one's own left; the screen enters and validates |

Validation on 17 October 2026: **299 PHP integration tests (15,162 assertions)**; **263 embedded SQL checks and 294 native PostgreSQL 16 checks including concurrency**; migration 066 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 268 screens without a problem (continuous integration still blocked by the GitHub account's billing).

## Increment 82 (lot 4): the hours delivered, in the pays

The hours delivered come from the validated attendance and feed the pays (FR-PTG-03; FR-REM-02, lot 4):

- **A session's hours**: those actually held when entered, else those planned less the delay; credited to the teacher who held it, the substitute when replaced; an absence, a session not entered or not validated gives none. Hours planned alone never pay.
- **Hours delivered** (« Heures assurées », Planning section, for `hours.validated.read` and whoever computes the pays): by teacher, a month's sessions held, their hours, those held as a substitute and those corrected. A teacher reads their own only (`GET /api/delivered-hours?from=&to=&staff_id=`, a month at most).
- **The pays**: a month's draft now counts these sessions as its units, at the rate of their group's level, by the hour or one a session, beside the units entered by hand; the month's hours are the sum of its validated sessions (AC1). A substitute is paid their sessions (AC13).
- **Correct (trace)** (on a validated entry, `attendance.teacher.validation.approve`): the entry itself is unchanged; the correction keeps the former and the new values, its reason and author (AC2), and the hours and pays follow it. Refused once the month is in a validated pay of the teacher credited before or after: that month is corrected by a pay's adjustment (FR-REM-03). `GET|POST /api/teacher-attendance/{attendance}/corrections`.
- SQL 067 (`database/schema/067_delivered_hours.sql`, migration `2026_10_17_000067`).

| Requirement | Evidence |
|---|---|
| FR-PTG-03 AC1, FR-REM-02 lot 4 | tests/sql: present 0.83 h, 10 minutes late 0.67 h, the replaced session credited to the substitute, the absence and the session not entered none; the hourly pay's unit lines 1,245.00 and 1,005.00, the substitute's one session 3,000.00. `DeliveredHoursTest`: the month's pay net 1,005.00, 1,245.00 once corrected |
| FR-PTG-03 AC2 | tests/sql and `DeliveredHoursTest`: a correction keeps the former values, the entry unchanged, the same values or no reason refused, never changed; refused once the month's pay is validated |
| FR-PTG-03 | tests/sql: a teacher reads their own hours and no one else's; whoever reads neither hours nor pays reads none. `DeliveredHoursTest`: the hours screen and the correction on the attendance screen |

Validation on 17 October 2026: **300 PHP integration tests (15,269 assertions)**; **264 embedded SQL checks and 295 native PostgreSQL 16 checks including concurrency**; migration 067 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 272 screens without a problem (continuous integration still blocked by the GitHub account's billing).

## Increment 83 (lot 4): the pupils' roll call

The roll call, session by session (FR-PRE-01):

- **Roll call** (« Appel des élèves », Planning section, `attendance.student.entry.create`): the sessions begun on a day that the reader calls, a teacher their own only, the supervisor those of the school or their sites; for each, how many pupils are marked and absent. **Call the roll** lists the pupils the group has on the session's day (AC1: one who joined it later is not called) and marks them present, absent, late (minutes optional) or excused, all together. A roll not called is no absence.
- A mark changed (`attendance.student.entry.update`) keeps its former value, who changed it and when (AC2), read in the session's **History**. Marks are never deleted; a session to come or cancelled is not called.
- Absence thresholds and notices to the families wait for the portal (lot 10).
- SQL 068 (`database/schema/068_student_attendance.sql`, migration `2026_10_17_000068`).

Routes: `GET /api/roll-calls?day=`, `GET|POST /api/class-sessions/{session}/roll-call` (`entries`: `student_id`, `status`, `late_minutes`, `note`), `GET /api/class-sessions/{session}/roll-call/history`.

| Requirement | Evidence |
|---|---|
| FR-PRE-01 AC1 | tests/sql: two pupils in the group since before the session called, the third, joined after it, refused (`roll_not_member`) |
| FR-PRE-01 AC2 | tests/sql and `RollCallTest`: absent changed to excused or late, the former value, who and when kept, the history never changed |
| FR-PRE-01 | tests/sql: the session's own teacher calls it, a colleague not; a session to come refused; a pupil not called has no mark and no absence. `RollCallTest`: the teacher's sessions of the day, the roll saved together, the screen calls it; whoever calls no roll has no such screen |

Validation on 17 October 2026: **301 PHP integration tests (15,378 assertions)**; **265 embedded SQL checks and 296 native PostgreSQL 16 checks including concurrency**; migration 068 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 276 screens without a problem (continuous integration still blocked by the GitHub account's billing).

## Increment 84 (lot 4): the finance dashboard

The finance dashboard (FR-TDB-01; FR-TDB-03 for its figures' sources):

- **Finance dashboard** (« Tableau de bord financier », Dashboards section, `dashboard.finance.read` for the whole school): month by month over up to 13 months, each figure the sum of its source operations computed when read, its basis shown on its heading, the time of computation said:
  - **billed**: the instalments of each enrolment's schedule in force, enrolments not cancelled, by month due;
  - **net receipts**: payments validated − reversals validated − refunds executed, by the day paid (carry-overs between enrolments left out) — the month's reconciliation (TST-16, AC1);
  - **expenses committed** and **paid**, other than the pays' payouts; **pays committed** (validated nets) and **paid out** (payouts less their reversals): a salary payout, which writes a `salaries` expense, is never counted twice (AC2);
  - **management result** = net receipts − expenses paid − pays paid out, neither a cash position nor a certified result;
  - **arrears** at the period's end.
- **Programmes' profitability**: billed and received of each programme's enrolments, its direct costs (the expenses committed to it), the margin; the method shown (shared costs and pays not shared out), a programme with no cost said so.
- **Operations**: a month's operations behind a figure, read with the reader's own rights: none of those they may not read.
- The figures of a module the school does not use do not appear (BR-20).
- SQL 069 (`database/schema/069_finance_dashboard.sql`, migration `2026_10_17_000069`).

Routes: `GET /api/dashboards/finance?from=&to=`, `GET /api/dashboards/finance/programs?from=&to=`, `GET /api/dashboards/finance/sources?indicator=&month=`.

| Requirement | Evidence |
|---|---|
| FR-TDB-01 AC1, TST-16 | tests/sql: every month of the suite's year, payments − reversals − refunds equal to the month's reconciliation computed apart (SQL 053); `FinanceDashboardTest`: a payment validated counted, one waiting not |
| FR-TDB-01 AC2 | tests/sql: every month, the expenses paid and the pays paid out together equal every expense payment of the month, the salaries' included once |
| FR-TDB-01, FR-TDB-03 | tests/sql: refused to a grant limited to a site and for a period over 13 months; the pays committed equal the validated nets. `FinanceDashboardTest`: the figure's operations listed by reference, the programme's receipts and its missing costs, the screen and its operations |

Validation on 17 October 2026: **302 PHP integration tests (15,524 assertions)**; **266 embedded SQL checks and 297 native PostgreSQL 16 checks including concurrency**; migration 069 rolled back alone on the database the suite had filled, then applied again; local browser acceptance in Chromium: 280 screens without a problem (continuous integration still blocked by the GitHub account's billing).

## Increment 85 (lot 4): the operational dashboard

The operational dashboard (FR-TDB-02; FR-TDB-03 for a group's sessions):

- **Operational dashboard** (« Tableau de bord opérationnel », Dashboards section, `dashboard.operations.read`), group by group, each figure counted from the rows when read, its basis on its heading, the time of computation said:
  - **fill**: the group's members today over its capacity, counted as its composition list and the capacity rule count them (AC1);
  - over a period of days (400 at most): the **sessions held** (begun, not cancelled), the **teacher's attendance validated** over them, the sessions **entered by no one**, the **roll calls not taken** (a roll not called is no absence), the pupils' **absences** over the marks and their **lates**;
  - for the whole school: the **enrolments waiting**, and the period's **prospects**, registered and lost, with the conversion rate.
- **Scope by role** (« un enseignant voit ses groupes, le surveillant la présence »): the groups of the reader's grant, for the whole school, its sites, levels or groups, or a teacher's own (a personal grant: the groups they lead or teach, as SQL 063 opens them); the fill also within `group.group.read`; the school's figures only for whoever holds the dashboard and the enrolments' or prospects' read for the whole school. The grant is added to the direction, the pedagogy head and the teachers.
- **Sessions**: a group's sessions held in the period, with their teacher's attendance and roll call, those the reader may read.
- The figures of a module the school does not use do not appear (BR-20).
- SQL 070 (`database/schema/070_operations_dashboard.sql`, migration `2026_10_17_000070`).

Routes: `GET /api/dashboards/operations?from=&to=`, `GET /api/dashboards/operations/sessions?group=&from=&to=`.

| Requirement | Evidence |
|---|---|
| FR-TDB-02 AC1 | tests/sql: every open group's members equal its composition list (memberships in force), its capacity its own; `OperationsDashboardTest`: 2 members of 25, 8.0 %, the composition list read through the API |
| FR-TDB-02 | tests/sql: every group's sessions held, attendance entered, validated and missing, roll calls and marks counted again from the rows; enrolments waiting and prospects; refused without the grant and for a period over 400 days. `OperationsDashboardTest`: one absent of two marks, a session entered by no one |
| FR-TDB-02, FR-TDB-03 | tests/sql: a grant limited to a site sees its groups only and none of the school's figures, and is refused another site's sessions. `OperationsDashboardTest`: a teacher sees their own group only and is refused another's sessions; an accountant has no dashboard; the screen and a group's sessions |

Validation on 17 October 2026: **303 PHP integration tests (15,687 assertions)**; **267 embedded SQL checks and 298 native PostgreSQL 16 checks including concurrency**; migration 070 rolled back alone on the database the suite had filled, then applied again, and every migration reset and applied again on that database (the rollbacks of 061 and 070 now leave the read-only schools' triggers aside); dependency audit without an advisory; deployment end to end and backup and restore checked; browser acceptance of 284 screens in Chromium, Firefox and WebKit without a problem. The GitHub workflow, blocked by the account's billing, was run locally, job by job (its platform jobs).

## Increment 98 (lot 5): the course's screens and the teachers' own resources

FR-LMS-01 on screen, and its last rule. SQL 079 (`database/schema/079_lesson_resources.sql`, migration `2026_10_28_000079`):

- **The teachers' own resources**: a teacher adds a link (a title and an HTTPS address of the school's allow-list) to a lesson pupils can read now, of a course whose subject they teach today in one of its programme's groups; published at once, without review. Its lesson, title and address never change; it is withdrawn by its author, or by the pedagogy manager whoever added it, never deleted. The new permission `lms.resource.manage` (teachers and the pedagogy manager). A colleague not assigned is refused (`403 lms_group_forbidden`), a lesson not yet published too (`lms_lesson_not_visible`). They are read with the course's published lessons, each with its author.
- **Courses** (« Cours », « Mes cours » for whoever only reads them, in the Pedagogy section): the courses the reader reaches; the manager creates a programme's subject's course.
- **A course's outline**, for the manager: its chapters and lessons in their order, each lesson's state (draft, in review, published, scheduled) and version. Chapters and lessons are added, renamed, moved up and down and archived; a lesson is written in the editor (title, objectives, prerequisites, length, its document: formatted text, headings, lists, quotations, code and links, no file), previewed as a pupil will read it, submitted when the school asks for a review, and published, with its chapter or the whole course, now or from a moment in Algiers' time, after a list of what the publication does lesson by lesson and of what stops it. The editor's own attributes (a link's `rel` and `class`, empty ones) are dropped before sending (`App\Support\LessonDocument`); anything else the database refuses, with its reason.
- **A course as its pupils read it**: its chapters, each lesson at its last version published, its objectives and prerequisites, its document and the teachers' resources. A document is drawn from its tree by Blade views with escaped text, never as HTML (`lesson-node`, `lesson-text`): a `<script>` typed in a lesson shows as text. Links open in a new tab with `rel="noopener noreferrer"`, marked ↗, with a note that they lead out of the platform. An image of another site is shown as a link to open there: the page asks nothing of a third party (the Content Security Policy allows the platform's images only). The teacher adds and withdraws their resources there.

Routes: `POST /api/lessons/{lesson}/resources`, `POST /api/lesson-resources/{resource}/withdraw`; `GET /api/courses/{course}/lessons` now gives each lesson's resources. Screens: `/school/courses`, `/school/course-outline?course=`, `/school/course-reader?course=`.

| Requirement | Evidence |
|---|---|
| FR-LMS-01 (ressources des enseignants) | tests/sql, `CourseScreensTest`: the assigned teacher adds a link of the allow-list to a visible lesson, read at once by the pupil; a colleague not assigned, a pupil, a draft lesson, another site refused; withdrawn by its author from the screen, by the manager whoever added it, never deleted |
| FR-LMS-01 (aperçu, publication) | `CourseScreensTest`: the manager creates the course, adds and moves chapters, writes and publishes a lesson from the outline; the preview draws the working copy |
| Lot 5 §2 (contenu, liens) | `CourseScreensTest`: the pupil reads the lesson drawn from its document, the link with `rel="noopener noreferrer"`, a typed `<script>` as text; the editor's extra attributes dropped, unknown nodes left for the database to refuse |
| Lot 5 §7 | `CourseScreensTest`: the pupil opens no outline and adds no resource |

Validation on 28 October 2026: **316 PHP integration tests (16,967 assertions)**, the one the first run failed (`PseudoLocalizationTest`: four texts written in the code or hidden from its view check, moved to the catalogs and helpers) passing once fixed; **273 embedded SQL checks and 304 native PostgreSQL 16 checks including concurrency**; migration 079 rolled back alone on the database the suite had filled, then applied again. The browser acceptance, which lists the Courses screen, was not run; the outline and the reader, opened with a course, are covered by `CourseScreensTest`. The CI could not start (the GitHub account's billing).

## Increment 97 (lot 5): the course, its chapters and lessons, published in versions

FR-LMS-01's rules, as `SPECIFICATION_LOT5_PEDAGOGIE.md` §2 sets them. SQL 078 (`database/schema/078_courses.sql`, migration `2026_10_28_000078`); the screens come next:

- **A course** is a programme's subject (one the programme teaches), kept by the new permission `lms.course.manage` (the pedagogy and HR manager, for the whole school). Its **chapters** and each chapter's **lessons** are in an explicit order, positions from 1 never two the same, checked at the transaction's end so that a new order is written at once; one archived leaves the order and the pupils' view, never deleted.
- **A lesson's working copy**: title, objectives, prerequisites, length and its **document**, a ProseMirror/Tiptap tree the database checks node by node: paragraphs, headings (1 to 4), lists, quotations, code, horizontal rules, images; bold, italic, underline, strike, code, sub- and superscript, links; their alignment, a heading's level, a list's start, a code block's language, an image's text. Anything else is refused (`lms_content_invalid`): HTML, scripts, styles, event attributes, another node or mark. 512 KB and 20 levels at most.
- **Links and images** are HTTPS addresses on the school's allow-list (the new setting `lms.link_domains`, a new kind of setting, a list of domain names: Google Drive and Docs, GitHub, OneDrive by default), a host below one of its domains (`raw.github.com`, never `docs.google.com.evil.dz` nor `evilgithub.com`), without credentials (`lms_link_invalid`, `lms_link_domain_blocked`, both `422`). Nothing is uploaded nor fetched.
- **Publication** of a lesson, a chapter or the whole course, after a preview that says, lesson by lesson, what it would publish and what stops it: all or nothing, each lesson changed since its last version a new version, frozen, visible now or from a later moment (`visible_from`, entered in Algiers' time; never in the past). Written by the database alone, one publication at a time per course; a link the allow-list no longer holds stops it. A change to a published lesson makes a draft again; pupils keep reading its last version.
- **A review**, when the school asks for one (`lms.lesson.review_required`, off by default): a draft is submitted, and published by another manager, who is recorded as having approved it.
- **Who reads**: `lms.course.read` (the manager, the direction, teachers and pupils). The manager and the direction read every course; a teacher, the courses of the subjects of the groups they are assigned to today; a pupil, those of the programmes they learn in by an enrolment granting access (FR-INS-04), closed at once when it no longer does. They read the published lessons only, each at its last version visible now (`school.published_lessons`); drafts, reviews, versions to come and the working copies are the manager's. A course out of reach is refused alike whether it exists or not (`403 lms_access_denied`).

Routes: `GET|POST /api/courses`, `PUT /api/courses/{course}`, `GET /api/courses/{course}/outline`, `GET /api/courses/{course}/lessons`, `POST /api/courses/{course}/chapters`, `POST /api/courses/{course}/chapters/order`, `PUT /api/chapters/{chapter}`, `POST /api/chapters/{chapter}/archive`, `POST /api/chapters/{chapter}/lessons`, `POST /api/chapters/{chapter}/lessons/order`, `GET|PUT /api/lessons/{lesson}`, `GET /api/lessons/{lesson}/versions/{version}`, `POST /api/lessons/{lesson}/archive`, `POST /api/lessons/{lesson}/submit`, `POST /api/publications/preview`, `POST /api/publications`.

| Requirement | Evidence |
|---|---|
| FR-LMS-01 AC | tests/sql, `CourseTest`: a lesson changed after its publication leaves the version published as it was; a new version on the next publication |
| FR-LMS-01, lot 5 §2 | tests/sql: HTML, scripts, styles, event attributes, unknown marks, a paragraph in a paragraph, 25 levels deep refused; links with credentials, spaces, `http`, `javascript:` and look-alike domains refused; the allow-list changed, a link it lost refused, and a chapter holding it published not at all |
| Lot 5 §2 (publication) | tests/sql, `CourseTest`: by lesson, chapter or course; scheduled tomorrow, the pupil reads the previous version; never in the past; the states and versions written by the publication alone |
| Lot 5 §2 (relecture) | tests/sql: with the review asked, a draft is not published, a lesson submitted is published by another manager, recorded as approver |
| Lot 5 §1, §7 | tests/sql, `CourseTest`: the pupil of the programme and the assigned teacher read the published lessons; a colleague not assigned, a pupil not enrolled or suspended, nothing (`lms_access_denied`); no pupil reads a working copy or a version directly |

The settings screen edits the new kind of setting, a list of domain names, one per entry. Validation on 28 October 2026: **314 PHP integration tests (16,651 assertions)**; **272 embedded SQL checks and 303 native PostgreSQL 16 checks including concurrency**; migration 078 rolled back alone on the database the suite had filled, then applied again. No new screen: the browser acceptance comes with the course's screens. The CI could not start (the GitHub account's billing).

## Increment 96 (lot 5): teaching assignments and the pupils' own accounts

Lot 5's base, as `SPECIFICATION_LOT5_PEDAGOGIE.md` §1 describes it (its defaults stand until the school answers `questions.md` §4). SQL 077 (`database/schema/077_teaching_assignments.sql`, migration `2026_10_28_000077`), module `lms`:

- **A teaching assignment** names the teacher of a group's subject from a day until another (included), one teacher at a time, even for two requests at once (an exclusion constraint): a teacher is replaced by ending their assignment, then assigning the next from the day after. On its first day, the group's programme teaches the subject, and the teacher, still with the school, is qualified for it at the programme's level (FR-STF-02). It starts today or later and keeps its group, subject, teacher and first day; its end, yesterday at the earliest, is final; one not begun is removed, one begun is ended, never deleted.
- **Who assigns**: the new permission `lms.assignment.manage` (the pedagogy and HR manager), for the whole school or on the group's site. Assignments are read with the groups (`group.group.read`); a teacher's personal grant now also reads the groups they are assigned to, now or later, with their pupils (as those they lead or teach, 033 and 063).
- **Whether the caller teaches** a group's subject on a day (`school.teaches`): their own staff record, still with the school, assigned that day. Lot 5's activities ask it before a teacher publishes or corrects; assigning is not teaching.
- **The pupil's own account**: a pupil's record is linked to the account they sign in with (role `student`, their own data), by whoever changes the record; never one's own, a revoked membership's, a member of staff's or another pupil's. **The groups a pupil learns in** (`school.learner_groups`): those they are placed in today, by an enrolment that grants access to courses (FR-INS-04): validated, paid as far as the school asks; a suspended enrolment, an ended membership or a blocked record opens nothing.
- Screens: **Teaching assignments** (« Affectations d'enseignement », in the new **Pedagogy** section): the assignments of the groups the reader sees, theirs marked, assigned, ended or removed by the manager; the pupil's sheet names their account, linked and unlinked from it.

Routes: `GET|POST /api/teaching-assignments` (`group_id`, `subject_id`, `staff_id`, `mine`, `include_ended`), `POST /api/teaching-assignments/{assignment}/end`, `DELETE /api/teaching-assignments/{assignment}`, `GET /api/students/accounts`, `POST /api/students/{student}/account`.

| Requirement | Evidence |
|---|---|
| Lot 5 §1 (affectation en vigueur) | tests/sql, `TeachingAssignmentTest`: a subject outside the programme, a teacher not qualified, not a teacher or a day gone refused; a second teacher over the same days refused; the first ended, the next from the day after; only the teacher assigned that day teaches, the manager does not; a teacher reads their groups' assignments, another nothing; one begun never deleted |
| FR-IAM-04 | tests/sql: a site's grant elsewhere assigns nothing; a teacher's personal grant reads the groups of their assignments |
| FR-INS-04, lot 5 §1 | tests/sql: a pupil learns in their group once their enrolment is validated; with the full payment required and missing, or suspended, nothing; another pupil's account learns nothing |
| Lot 5 §1 (comptes élèves) | tests/sql, `TeachingAssignmentTest`: a pupil's account linked once, never one's own nor a member of staff's; the sheet names it and unlinks it |

Validation on 28 October 2026: **313 PHP integration tests (16,510 assertions)**, the one the first run failed (`PupilRecordsTest`: the pupil's API never shows the account's identifier, so it says `has_account` and the account's name) passing once fixed; **270 embedded SQL checks and 301 native PostgreSQL 16 checks including concurrency**; migration 077 rolled back alone on the database the suite had filled, then applied again. The browser acceptance, which lists the new screen, was not run. The CI could not start (the GitHub account's billing).

## Increment 95 (lot 4): the dashboards' cache

FR-TDB-03 is complete. The finance and operational dashboards' figures, and a programme's profitability, are kept for their reader (`App\Support\DashboardCache`, in the application's cache, Redis when deployed) and computed again as soon as anything changes in the school, on another day, after five minutes at most, or when the reader asks (« Recalculer » on both screens, `fresh=1` on the API). Every write to a school table leaves a trace in the audit log, so the school's stamp (`school.dashboard_stamp()`, SQL 076) is its last trace and the set of its traces of the last quarter of an hour (a transaction that began before the last trace and commits after it changes that set), with its modules, its mode and its settings. The figures are never shared between readers: their rights choose what the figures count. The screens keep showing when the figures were computed. The exports read the same figures as the screen.

Validation on 17 October 2026: **311 PHP integration tests (16,289 assertions)**; **268 embedded SQL checks and 299 native PostgreSQL 16 checks including concurrency**; every migration reset and applied again on the database the suite had filled; dependency audit without an advisory; deployment end to end and backup and restore checked; browser acceptance of 864 screens in Chromium, Firefox and WebKit without a problem. The GitHub workflow, blocked by the account's billing, was run locally, job by job.

## Increment 94 (lot 4): a pupil's timetable

FR-PLN-04's views by pupil, for the staff. A pupil's sessions are those of the groups they belonged to on each session's day, as its roll call lists them: a pupil who joined a group on a Tuesday has none of its sessions before. « Planning » filters by pupil (found by name or number, among those the reader may read) and prints the pupil's week and month from there; the API takes `student_id` beside `group_id`, `teacher_id` and `room_id` (`/api/class-sessions`, `/api/timetable/week.pdf`, `month.pdf`, `calendar.ics`). Each session says its group and its teacher, a pupil having several groups. A pupil the reader may not read is not found (404): a teacher with a personal scope reads those of the groups they teach. The pupils and their parents will read their own with the portal (lot 10).

Validation on 17 October 2026: **310 PHP integration tests (16,246 assertions)**; **268 embedded SQL checks and 299 native PostgreSQL 16 checks including concurrency**; every migration reset and applied again on the database the suite had filled; dependency audit without an advisory; deployment end to end and backup and restore checked; browser acceptance of 864 screens in Chromium, Firefox and WebKit without a problem. The GitHub workflow, blocked by the account's billing, was run locally, job by job.

## Increment 93 (lot 4): a session's default length

FR-PLN-01 is complete. A session, or a weekly course, given its start without its end lasts a session of its subject in its group's programme that day (`session_duration_minutes`, FR-PRG-02), which takes the school's length when the subject is added (`planning.session_minutes`, 60 minutes, « Paramètres de l'école »). One that would end past midnight is refused (`session_spans_days`). The API (`/api/class-sessions`, `/api/session-series` and its change from a day on) and « Planning » leave the end optional; the sessions already planned keep their hours. No migration: the setting and the subject's length were there since SQL 028.

Validation on 17 October 2026: **309 PHP integration tests (16,210 assertions)**; **268 embedded SQL checks and 299 native PostgreSQL 16 checks including concurrency**; every migration reset and applied again on the database the suite had filled; dependency audit without an advisory; deployment end to end and backup and restore checked; browser acceptance of 864 screens in Chromium, Firefox and WebKit without a problem. The GitHub workflow, blocked by the account's billing, was run locally, job by job.

## Increment 92 (lot 4): the guardians told by WhatsApp

FR-PRE-01 is complete. Beside the email, as decided on 17 October 2026:

- **When**: the school turns it on (`attendance.notify_guardians_whatsapp`, « Paramètres de l'école », off by default) and its WhatsApp Business account is set up (`WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, DEPLOY.md). A minor's absence alert then queues a message to each primary contact with a phone, on this channel, beside the email if that is on too (SQL 075).
- **What**: the school's template approved by Meta (`absence_alert` by default, Utility category, in French and Arabic), sent in the school's language with six parameters: the pupil's first name, the absences, the window's first and last days, the school and its phone; the same words as the email (DEPLOY.md gives them).
- **How**: `platform:guardian-messages` sends both channels, through Meta's Cloud API (`App\Support\WhatsApp`); a phone written nationally (0550 12 34 56) gets Algeria's code. A message that can never be sent fails at once with why (`guardian_phone_unusable` for a landline, `whatsapp_not_configured` without an account); one that the API refuses is tried again, five times at most. « Alertes d'absence » shows each message's state.

Validation on 17 October 2026: **308 PHP integration tests (16,181 assertions)**; **268 embedded SQL checks and 299 native PostgreSQL 16 checks including concurrency**; every migration reset and applied again on the database the suite had filled; dependency audit without an advisory; deployment end to end and backup and restore checked; browser acceptance of 864 screens in Chromium, Firefox and WebKit without a problem. The GitHub workflow, blocked by the account's billing, was run locally, job by job.

## Increment 91 (lot 4): the guardians told by email

FR-PRE-01's notification to the guardians, as decided on 17 October 2026:

- **When**: the school turns it on (`attendance.notify_guardians_email`, « Paramètres de l'école », off by default). An absence alert raised for a **minor** (under `student.majority_age`) then queues one message to each of the pupil's primary contacts with an email address; an adult learner's alert stays within the school.
- **What**: the pupil's first name, the absences without excuse and their window, and the school to contact with its phone (`identity.phone`), in the school's language then the other (Arabic and French); nothing more (BR-19), and no reply expected.
- **How**: the messages are kept in `school.guardian_message` (SQL 074) and sent by `php artisan platform:guardian-messages`, every minute through the scheduler, as the notification emails are: claimed once across schools, tried five times, the failure kept without the address. « Alertes d'absence » shows, for each alert, what the guardians were sent and when.

Validation on 17 October 2026: **307 PHP integration tests (16,141 assertions)**; **268 embedded SQL checks and 299 native PostgreSQL 16 checks including concurrency**; every migration reset and applied again on the database the suite had filled; dependency audit without an advisory; deployment end to end and backup and restore checked; browser acceptance of 864 screens in Chromium, Firefox and WebKit without a problem. The GitHub workflow, blocked by the account's billing, was run locally, job by job.

## Increment 90 (lot 4): absence thresholds

FR-PRE-01's thresholds, decided with the school on 17 October 2026:

- **The rule**, two school settings (« Paramètres de l'école »): `attendance.absence_threshold` absences without excuse over the last `attendance.absence_window_days` days, 3 in 30 by default; 0 sets no threshold. Late arrivals and excused absences do not count, nor a session cancelled since.
- **The alert**: the database raises it when the mark that reaches the threshold is saved, a mark corrected to an absence too (`school.check_absence_threshold`, SQL 073), with the absences counted, the window and the threshold then; one per pupil and window, never changed.
- **Who is told**: a notification (`attendance.absence_threshold`, in the application, by email if they want it) to whoever corrects the group's rolls for the whole school or on its site, and to its lead teacher; the notification opens « Alertes d'absence », the alerts they may read, the latest first, a pupil whose record they do not read unnamed. `GET /api/absence-alerts`, `GET /api/absence-alerts/rule`.

The guardians are told next, by email (increment 91) and WhatsApp (increment 92), each when the school turns it on.

Validation on 17 October 2026: **306 PHP integration tests (16,055 assertions)**; **268 embedded SQL checks and 299 native PostgreSQL 16 checks including concurrency**; every migration reset and applied again on the database the suite had filled; dependency audit without an advisory; deployment end to end and backup and restore checked; browser acceptance of 864 screens in Chromium, Firefox and WebKit without a problem. The GitHub workflow, blocked by the account's billing, was run locally, job by job.

## Increment 89 (lot 4): the planning by month

FR-PLN-04's monthly view:

- **The Planning screen** reads a week or a month (« Période »): the sessions of the month the day chosen falls in, by group, teacher or room, with the same actions as the week's.
- **A month's timetable in PDF** (« Imprimer le mois », on the Planning screen once a group, a teacher or a room is chosen, and on « Mon emploi du temps » for a teacher's own): the month's weeks in rows, from the day the school starts its weeks on, the open days in columns (and any other day a session falls on), each day its sessions with their hours, subject, teacher or group, and room; cancelled sessions struck through, and those a teacher was replaced on struck through in their own timetable. Right to left in Arabic. `GET /api/timetable/month.pdf?{group_id|teacher_id|room_id|mine}=&month=YYYY-MM`.

The pupils' and parents' own timetables come with the portal (lot 10).

Validation on 17 October 2026: **305 PHP integration tests (15,958 assertions)**; **267 embedded SQL checks and 298 native PostgreSQL 16 checks including concurrency**; every migration reset and applied again on the database the suite had filled; dependency audit without an advisory; deployment end to end and backup and restore checked; browser acceptance of 852 screens in Chromium, Firefox and WebKit without a problem. The GitHub workflow, blocked by the account's billing, was run locally, job by job.

## Increment 88 (lot 4): absences per pupil and their trend

The rest of FR-TDB-02, on the operational dashboard, within the groups the reader's `dashboard.operations.read` opens and while the planning and attendance modules are active:

- **Absences per pupil** (« Absences par élève », on each group's line): the pupils marked at the roll calls of the group's sessions held in the period, the most absent first, with their marks, absences, late arrivals, excused absences, absence rate and last absence. A pupil is named only where the reader reads their record (the pupil's own rules, SQL 029 and 063): a teacher no longer reads a pupil who has left their group, who stays counted but unnamed. `GET /api/dashboards/operations/pupils?group=&from=&to=`, `school.operations_pupils` (SQL 072).
- **The trend** (« Tendance des absences », in the header, and in each group's window): week by week, Sunday to Saturday, every week of the period even without a session, the sessions held, the absences over the marks, the late arrivals, the absence rate and its change in points from the last week with a roll call. `GET /api/dashboards/operations/weeks?[group=]&from=&to=`, `school.operations_weeks` (SQL 072); without a group, the reader's whole scope.

Validation on 17 October 2026: **305 PHP integration tests (15,904 assertions)**; **267 embedded SQL checks and 298 native PostgreSQL 16 checks including concurrency**; every migration reset and applied again on the database the suite had filled; dependency audit without an advisory; deployment end to end and backup and restore checked; browser acceptance of 852 screens in Chromium, Firefox and WebKit without a problem. The GitHub workflow, blocked by the account's billing, was run locally, job by job.

## Increment 87 (lot 4): the finance dashboard's conventions

FR-TDB-01's two conventions, each a school setting (« Paramètres de l'école »), the default reading as before:

- **The management result's basis** (`dashboard.result_basis`): `cash`, net receipts − expenses paid − pays paid out (the default), or `accrual`, revenue billed − expenses committed − pays committed. The dashboard's help and the result's tooltip give the formula in use; it stays neither a cash position nor a certified result.
- **The common costs** (`dashboard.common_costs`): `kept` out of the programmes' profitability (the default), or shared among the programmes in proportion to their revenue billed: the expenses committed to no programme, or paid, with the pays. The shares are to the cent, the cents left over going to the largest remainders, so that they add up to the common costs; with no revenue billed over the period, nothing is shared and the screen says so.
- A programme's direct costs and margin follow the result's basis: the revenue billed less the expenses committed to it, or its net receipts less the expenses paid for it (`school.program_costs_paid`, SQL 071). The « Rentabilité par programme » window states the method, and the common costs shared.

Validation on 17 October 2026: **304 PHP integration tests (15,816 assertions)**; **267 embedded SQL checks and 298 native PostgreSQL 16 checks including concurrency**; every migration reset and applied again on the database the suite had filled; dependency audit without an advisory; deployment end to end and backup and restore checked; browser acceptance of 852 screens in Chromium, Firefox and WebKit without a problem. The GitHub workflow, blocked by the account's billing, was run locally, job by job.

## Increment 86 (lot 4): the dashboards' exports

The dashboards exported (FR-TDB-03):

- **Export** on the finance and the operational dashboards (« Nouvel export »): CSV, Excel or PDF, the dashboard as its screen shows it for the screen's period: the finance month by month, the operations group by group (the group named with its programme, the rates in percent).
- Under `export.job.create` and the dashboard's own permission, as every export (FR-IMP-02): the figures within the reader's scope (a teacher's groups only), the columns of a module the school does not use left out (BR-20), each export logged; the export screen offers both to whoever reads them.
- The export kinds' screen also names the expenses' export, which it showed by its key.

Validation on 17 October 2026: **303 PHP integration tests (15,750 assertions)**; **267 embedded SQL checks and 298 native PostgreSQL 16 checks including concurrency**; every migration reset and applied again on the database the suite had filled; dependency audit without an advisory; deployment end to end and backup and restore checked; browser acceptance of 284 screens in Chromium, Firefox and WebKit without a problem. The GitHub workflow, blocked by the account's billing, was run locally, job by job (its platform jobs).

## Next increments of lot 3

1. The finance's review of lot 3 before its exit: the reconciliation to be validated by the finance (lot 3's exit criterion; the Arabic receipts were accepted on 17 October 2026). `sh deploy/review-lot3.sh` prepares it on an installation of its own: the example school « École Les Oliviers », four accounts with MFA, and a month entered through the API by the people who would enter it (payments, a refund, expenses, pays and their payouts, then two things taken back the next month). It writes the receipts, the screens in French and Arabic, and a sheet comparing the reconciliation it expects with the one it gets to `.review/`; it fails on any figure that differs. `REVUE_LOT3.md` (at the repository's root) is the reviewers' guide and record. The hours and sessions of the pays come from lot 4's validated attendance (FR-PTG-03), a replacement paid to whoever taught (FR-REM-02 AC13).

## Next increments of lot 4

Lot 4's exit review was held on 17 October 2026: `TST-05`, `TST-15` and `TST-16` green, the validated hours delivered in the pays; lot 4 can exit. `REVUE_LOT4.md` (at the repository's root) records each criterion with its checks, what remains of each requirement and the points the school is to confirm.

Lot 4's requirements still partial were reviewed against the SRS, rule by rule, on 17 October 2026: FR-PLN-02, 03, 05 and 06 and FR-PTG-01 to 03 are complete. FR-PLN-04 waits for the portal (lot 10) for the pupils' and parents' own timetables.

Lot 4 is complete but for FR-PLN-04's pupils and parents reading their own timetables, which the portal brings (lot 10).

## Next increments of lot 5

Lot 5 follows `SPECIFICATION_LOT5_PEDAGOGIE.md` (at the repository's root): pupils signed in, links on the school's allow-list and no upload, a scale per programme or level, the lessons' review off by default. Its defaults stand until the school answers `questions.md` §4; each is a setting or an activity's choice, so that an answer changes a value, not the design. Increment 96 laid its base (assignments, the pupils' accounts and their access), increments 97 and 98 the course (FR-LMS-01). In order:

1. Activities (FR-LMS-02, 03): a quiz or a homework of an assigned teacher's group and subject, draft, scheduled, open, closed, archived; a version frozen at publication, the audience of the day, deadline, scale, coefficient; never published to a group the teacher does not teach (`LMS_GROUP_FORBIDDEN`).
2. Quiz attempts (FR-LMS-03): the server's timer (`min(start + duration, deadline)`), answers saved as they go, the same attempt resumed with its time left, closed at expiry by a job, submissions idempotent, attempts counted, MCQs graded all or nothing.
3. Homework submissions (FR-LMS-02): HTTPS links of the allow-list, each version kept, idempotent; late refused by default, accepted until a date, or extended for one pupil with a reason; an inaccessible link replaced within 48 hours.
4. Correction and publication (FR-LMS-03, 04): open questions corrected; a zero for a missing work only explicitly; excused absences; results and answer keys shown immediately, after the deadline or when the teacher publishes; every change of a published mark a new version; a re-correction with its reason.
5. Marks and averages (FR-LMS-04): the scale, step, rounding and pass mark per programme or level, versioned without overlap; averages from published marks only, weighted, rounded once; a group's gradebook and its export.
6. The activities' screens for the teacher and the pupil, and their notifications (no mark in a message).
7. Lot 5's walkthrough of the specification's seven paths (§8), and its exit review.

## Next increments of lot 2

1. A full group's waiting list once OD-14 is decided. Replacements' groups (the rest of TST-03) come with lot 4's sessions.

The conversion rate is on the operational dashboard since increment 85.

Lot 1's last exit item is closed: the Arabic labels were confirmed by a native speaker on 16 October 2026, and Arabic keeps the system's font (decision of the same day).
