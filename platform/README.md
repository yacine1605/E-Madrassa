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
php artisan platform:operator support@editeur.dz   # access to the publisher's console, /console (--revoke to withdraw)
php artisan filament:assets                         # the console's styles and scripts, on every deployment
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


### Exports (FR-IMP-02)

`POST /api/exports` with a kind (`config/platform.php`, `exports`: an `App\Exports\Dataset`), a format (CSV, XLSX, PDF), filters and a language. An export needs `export.job.create` and the permission that reads the data on screen; a column may require a permission of its own, left out otherwise (AC1: no salary column for whoever cannot see salaries). Cells that a spreadsheet would read as formulas become text (AC2). CSV in UTF-8 with its mark and semicolons; Excel right-to-left in Arabic; PDF through the list template. Up to 2 000 rows the file is ready at once, beyond a background job makes it and notifies its author. Each export is logged (`school.export_log`: author, kind, filters, columns, rows, file), read by its author and by the journal's readers; its file is the author's and leaves storage after seven days.

### Files (FR-FIC-01)

`school.file_object` registers every file: category of rights (`platform.file_category`: finance attachments, student documents and photos, staff documents, teaching resources, exports and imports), real type read from the content, size, SHA-256, owner record, expiry. The database shows and accepts a file only with its category's permission, an export or an import only to its author (AC2); it applies the school's types and size (`files.types`, `files.max_mb`; by default PDF, JPEG, PNG, DOCX, XLSX, 10 MB) and its storage quota, also under concurrent uploads; an expired school still exports and adds nothing else. The bytes are on the `files` disk under `tenant/{id}/{category}/`: S3-compatible storage in production (`FILES_DRIVER=s3`, MinIO or a provider), a local directory in development. An executable renamed `.pdf` is refused (AC1); downloads are attachments the browser does not interpret; a signed link opens one file for 15 minutes without a session (AC3). `php artisan platform:purge-files`, daily, removes expired files from storage.

### PDF (FR-PDF-01, TST-17)

`App\Support\Pdf` renders a view of `resources/views/pdf` with mPDF: DejaVu Sans and XB Riyaz embedded, Arabic letters joined and laid out right to left, the school's name and receipt contacts in the header, the reference and generation time fixed by the caller in the footer with the page numbers. `App\Support\Money` writes amounts from whole centimes with no-break spaces between digit groups, so that an Arabic line never shows "500,50 12". Tests read the documents back with poppler (`pdftotext`, `pdffonts`); the PHP `gd` extension is required by mPDF. A receipt and a list template are ready for finance (lot 3) and exports. Security alerts (NFR-SEC-07) come with the console's supervision (FR-PLT-05).

### Imports (FR-IMP-01, TST-14)

`GET /api/imports/templates/{kind}` gives the template (CSV or Excel, the columns' codes on the first line); `POST /api/imports` keeps the file (category `import`) and previews it in the background: every row is validated, then applied for real in a transaction that is rolled back, so the preview finds every refusal, the database's included, and writes no business data (AC3, the "reprise à blanc"). Each refused row has its line and cause (AC2), also as a downloadable report; references are found by code, never created. `POST /api/imports/{id}/confirm` applies the valid rows by batches of 100, each committed on its own; rows are found by their stable reference, so the same file imported again duplicates nothing (AC1). `POST /api/imports/{id}/rollback` undoes a complete import, last row first, all or nothing, and refuses while something done since depends on it; an imported account is removed only if nobody has used it (`school.discard_account`). Kinds: `accounts` and `referential` (`config/platform.php`, `imports`: an `App\Imports\Importer`); students, staff, groups, payments and the rest join with their modules.

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

## Next increments of lot 1

1. **Last exit item**: a native speaker's review of the Arabic labels, on the acceptance screenshots (CI artifacts `platform-screens-*`), with the choice of an Arabic font.
2. **Lot 2** opens with findings F2 to F4: the credentials of `public.users`, out of the school application's reach, and a Content Security Policy.
