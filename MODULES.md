# School feature modules

Each school deployment can have its own feature package. Set its selection in its own environment file; the same application image supports every package. The default remains the complete platform.

## Choose a school package

| `SCHOOL_PROFILE` | Features |
| --- | --- |
| `full` / `school` | All implemented modules, including school report cards and annual progression |
| `language_center` | Classes, students, enrollment, teachers, planning, lessons, assessments, grades, attendance, finance, payroll, announcements, teacher portal and audit; no school report cards or admissions/progression workflow |
| `training_center` | Language-center features plus admissions and annual progression; no school report cards |
| `administration` | Student records, enrollment, admissions/history, catalog, finance and audit; no teaching, annual progression, staff, payroll or teacher portal |

These are starting packages, not restrictions on the kind of institution. Program types and curricula remain configured within each school's catalog.

For a custom package, `SCHOOL_MODULES` overrides the profile. For example, a school needing only student administration can use:

```dotenv
SCHOOL_PROFILE=school
SCHOOL_MODULES=students,lifecycle
```

This activates `core`, `catalog`, `students` and `lifecycle`. Finance, pedagogy, payroll and report cards are unavailable. Leave `SCHOOL_MODULES` unset or blank to use the profile; use `*` for everything. Unknown names fail at startup.

Copy `school.env.example` once for each school. Give each copy its own project name, URL, port, database name, password and module selection:

```powershell
Copy-Item school.env.example school-a.env
Copy-Item school.env.example school-b.env
# Edit each environment file, then start that school:
docker-compose --env-file school-a.env up --build -d
docker-compose --env-file school-a.env exec app php artisan school:user direction@example.test
docker-compose --env-file school-a.env exec app php artisan school:provision direction@example.test --name="School A"
```

Use that school's `--env-file` for subsequent commands. Preview selections without changing data:

```powershell
docker-compose --env-file school-a.env exec app php artisan school:modules
docker-compose --env-file school-a.env exec app php artisan school:modules --profile=language_center
docker-compose --env-file school-a.env exec app php artisan school:modules --modules=students,lifecycle
```

After changing a school's environment file, apply its selection:

```powershell
docker-compose --env-file school-a.env up -d --no-build --force-recreate app
docker-compose --env-file school-a.env exec app php artisan optimize:clear
```

When the application uses cached configuration, recreate its configuration cache after clearing it. Clear caches before serving requests when using a custom startup procedure. Normal Compose recreation starts a new container with the image's uncached configuration; school data remains in its persistent volumes.

## Module catalog

| Module | Features | Requires |
| --- | --- | --- |
| `core` | Dashboard | Always enabled |
| `catalog` | Programs, levels, periods, subjects, curriculum, offerings, classes, rooms and campuses | core |
| `students` | Students, guardians and enrollment | catalog |
| `hr` | Teachers, staff, assignments, qualifications, contracts and hours | catalog |
| `pedagogy` | Planning, attendance, lessons, assessments and grades | students, hr |
| `finance` | Invoices, payments, refunds, balances and expenses | students |
| `payroll` | Payroll and teacher payment workflows | hr, finance |
| `report_cards` | Term and annual school report cards, validation and archives | pedagogy |
| `lifecycle` | Admissions and student history | students |
| `progression` | Annual progression and re-enrollment | lifecycle, pedagogy |
| `communications` | Announcements | pedagogy |
| `teacher_portal` | Teacher login, assigned classes, schedule and grades | pedagogy |
| `audit` | Audit viewer | core |

Dependencies are added automatically. For example, selecting payroll also includes finance, student records, staff and catalog. The teacher portal's own pay screens require the optional payroll module. Enrollment still creates its internal invoice when the finance interface is disabled. Audit events continue to be recorded when the audit viewer is disabled.

## Access and school isolation

Feature selection is the school's available package. Existing membership permissions control which enabled features each employee may use. A disabled module cannot be restored by granting its permissions to an employee.

The menu and direct Filament page access check the module and read permissions. API endpoints for disabled features return 404; enabled features still check permissions and school membership. Document, lifecycle, payroll and teacher routes also check their owning module. Dashboard summaries omit data from disabled permission groups. Teacher payroll links disappear when payroll is disabled. Unimplemented demo-only pages stay out of the connected admin menu.

Each Compose project retains its separate database, storage, sessions and users. Changing modules does not move a school, create another institution, erase records or grant employee permissions. Re-enabling a feature restores access to its retained data.

## Maintain the modular application

This is a modular Laravel application with one image and a shared database schema per school. Feature modules are not separate microservices or independent installable repositories. All migrations still run; disabling a module controls its public entrypoints and interface, not table creation.

The module manifests in `madrassa-backend/config/modules/` own feature dependencies, permissions, API resources, dedicated API actions, page resource mappings and route entrypoints. `config/school_modules.php` owns school profiles. `app/School/Modules/ModuleRegistry.php` resolves dependencies and is the common boundary used by routes, API middleware and frontend authorization. The backend installer copies these files into the Laravel app during image creation.

For a new feature, add a manifest, define its resources and pages, implement its controllers/services/views, and guard its routes with `SchoolModule:<module>`. Declare new dedicated API operations under the manifest's `api_actions`. Generic resource routes use manifest ownership automatically. Add the module to a profile only when that package should receive it.

Verification uses `tests/modules.php` for manifest/dependency contracts and `tests/modular-schools.cjs` for two disposable schools, browser menus, direct access, API switches, runtime profile changes, retained data, route caching and isolation:

```powershell
docker-compose build app
docker-compose run --rm --no-deps -T --entrypoint php -v ./:/workspace:ro app /workspace/tests/modules.php
node tests/modular-schools.cjs
```
