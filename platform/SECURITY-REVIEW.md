# Security review of lot 1 (permissions and isolation)

Review of 11 October 2026, at the exit of lot 1 (SRS 1.2, §9.1). It covers every route of the API and every screen of the platform, the database roles and their privileges, row-level security, the functions that run with their owner's rights, the session and sign-in, support access, the journal and the deployment's headers.

Method: the code and the grants were read layer by layer, and every property that must stay true was turned into a test, so that it also holds for the tables, functions and routes added in later lots. A finding that needs work beyond lot 1 is listed with its severity and the lot that takes it.

## The layers of a request

A school's request crosses these layers in order; each one refuses on its own, without counting on the next. The database believes the school and the person of a request only as the application signed them (F7).

| Layer | What it enforces | Where | Tests |
|---|---|---|---|
| Proxy | HTTPS only, HSTS, certificates | `docker/Caddyfile` | `deploy/smoke-test.sh` (HSTS) |
| Web server | `nosniff`, `Referrer-Policy: same-origin`, `X-Frame-Options: SAMEORIGIN` | `docker/apache.conf` | `deploy/smoke-test.sh` (`nosniff`) |
| Page | Content Security Policy: scripts from the platform, or inline with the response's nonce; no plugin; images, fonts, connections, frames and forms to the platform only | `ContentSecurityPolicy` | `ContentSecurityPolicyTest`, `tests/browser/acceptance.mjs` (nothing blocked, three browsers), `deploy/smoke-test.sh` |
| Session | Cookie `Secure`, `HttpOnly`, `SameSite=Lax`; a CSRF token on every change; an idle limit of 30 minutes (each school sets it between 15 and 120), an absolute limit of 12 hours | `config/session.php`, `compose.yaml`, `AccountState` | `AuthenticationTest`; the CSRF token in `tests/browser/acceptance.mjs` |
| Sign-in | Lockout after 5 failures per login and per address, from 1 minute doubling to 60; MFA by TOTP, a code accepted once; recovery codes hashed and single-use; the secret encrypted with the application key, which the database never holds | `LoginThrottle`, `MultiFactor` | `AuthenticationTest`, `SessionTest` (Argon2id) |
| School | An active membership in an active school, chosen by the server; MFA when the school requires it; a support session only under a valid consent. The school, the person, the consent and the request are then signed for the database, inside one transaction | `SchoolContext`, `SchoolPanelContext`, `DatabaseContext` | `SchoolIsolationTest`, `SchoolPanelTest`, `SupportTest` |
| Permission | One of the route's permissions, held through an active assignment, checked again at every request (a withdrawn permission is refused at once) | `permission:` middleware, `RequirePermission` | `PermissionCoverageTest`, `AuthorizationTest` |
| Module | A school's module turned off answers `module_disabled`, its menu disappears | `module:` middleware, `RequireModule` | `SchoolPanelTest`, `LotOneWalkthroughTest` (R09) |
| Scope | The controller narrows to the assignment's scope (site, level); a record of another school answers 404 | Controllers, `CurrentSchool`; `WithinSchool` for the jobs | `AuthorizationTest`, `SchoolReferencePanelTest` |
| Database | The application connects as `school_runtime`: no ownership, no `BYPASSRLS`, row-level security forced on every school table, column-level grants, no deletion of a business record (an import's undo goes through the provisioner's functions), no password hash, MFA secret or recovery code (those are `platform_auth`'s, the sign-in's role). Triggers refuse what a bug could still attempt: a permission granted by someone who does not hold it, an assignment to oneself, the last administrator removed, writes in a read-only school, a change to the journal. The request's context is believed only signed with a key no role of the application reads, on its own connection, in its own transaction. A person is read by the school they belong to, or by themselves | `database/schema/`, `deploy/roles.sql`, `school.context()` | `tests/sql/database.mjs` |

Accounts, memberships, account links and schools are written only by functions owned by `platform_provisioner`, which cannot sign in. A school's functions check the caller's permission in the school; the publisher's functions are granted to the console's role only, and that grant is their only guard. The publisher's console connects as `platform_admin`, which has no privilege on the `school` schema: a school's data is reached only through a support consent (FR-PLT-04).

The pupil/guardian APIs added in increment 31 use the resource policies of schema 021 for every read and mutation. Sensitive custom values have an additional action-specific permission/scope filter in `PupilRecords`, including archived definitions; hidden keys survive partial edits and cannot be overwritten without that permission. Its caches are request-specific (`PupilRecordsTest`). Unlinked creation and family-link mutations remain tenant-wide only. Schema 022 exposes an enrolment count solely to a user allowed to update that pupil, for block confirmation; it exposes no enrolment details. The 15 routes below are module- and permission-guarded, with optimistic versions on mutations of existing records.

Increment 32 adds 20 programme/enrolment/group routes under `enrollment_groups`, all represented below. Existing mutations check their own action scope; validation, suspension and cancellation have separate route permissions. Price history, state history and rosters remain under resource RLS and always paginate. Creation and group transfer remain tenant-wide. Amounts cross the API as decimal strings, and contractual price/version/terms cannot be supplied at enrolment creation. A scoped cancellation returns the authorised UPDATE result directly; subsequent reads may legitimately be denied once its group links end. `AcademicWorkflowTest` covers these boundaries with restricted SQL roles and foreign-school IDs.

Increment 34 adds the programmes' and groups' screens, which call those controllers inside the panel's school transaction. A record's action is shown by `SchoolScreen::writableFor` when the reader's grant reaches that record (`school.core_ids`, read once per list); it only hides or shows the button, and the database checks the action again on writing (`SchoolingPanelTest`, a site-scoped manager). The groups' list counts each group's current members among the memberships the reader may see, so it discloses nothing the reader could not list.

Increment 35 adds the pupils', the pupil's sheet and the guardians' screens on the increment 31 controllers. The sheet reads its pupil through the API, so a pupil of another school or outside the reader's scope is not found (404). The guardian links now carry the guardian's name and phone through a sub-select under the guardians' own policies. The forms show a sensitive custom field only to a school-wide `student.sensitive.read` grant, and send back only the fields they show, so hidden values are kept as they are.

Increment 36 adds the enrolments' and a group's members' screens, and one read route, `GET /api/enrollments/{enrollment}/groups`, under `enrollment.enrollment.read` and the memberships' own policies. Enrolment and membership rows carry their pupil through sub-selects under the pupils' policies: a reader who may see an enrolment but not its pupil gets no name. The enrolment form's idempotency key is made when the form opens and kept in the form's state; as with the API, the client may send any key, and `school.enroll` refuses one reused for another pupil or programme. Schema 024 only changes which reason the enrolment history keeps (the request's, when the row has none).

Increment 37 adds three kinds of import (pupils, guardians with their link, enrolments) to the existing routes; no route is added. An import now needs the permission that creates its records for the whole school, not merely somewhere: what it creates belongs to no site or group yet, as on screen, and a module turned off takes that permission away, so its kinds are neither offered nor accepted, templates included. The rows run as their author, in the background, under the same policies and triggers as the screens. A sensitive custom column is refused to whoever may not read it; a probable duplicate needs a reason, journaled. Schema 025 gives pupils and guardians the reference of the school's file, which `school_runtime` may set at creation and never change (no column privilege; a trigger refuses it to anyone else), and which the journal masks like a matricule. Undoing an import is the only deletion of a business record: four functions owned by `platform_provisioner` (`school.discard_student`, `discard_guardian`, `discard_guardian_link`, `discard_enrollment`) delete a row only if an import still in place reports having created it, if the caller holds the creating permission for the whole school, and if nothing depends on it (an enrolment, a guardian, an account, a photo; an enrolment's later state, group or transfer; the primary contact an enrolment relied on). The application also checks that the record still holds the imported values, so a correction typed since keeps the import. An enrolment's history stays unchangeable; only the provisioner deletes it, with its enrolment. The journal keeps the creation and the removal, with the reason `import_rollback`.

Increment 38 adds a pupil's photo: `GET /api/students/{student}/photo` under `student.record.read`, `POST` and `DELETE` under `student.record.update`, each on a pupil the reader may reach (a pupil out of scope answers 404). The sent file is decoded only after its declared size (2 MB) and pixel count (16 million) are checked, then drawn again as a JPEG: nothing of the sent file but its pixels is kept, EXIF and location included. Schema 026 accepts as a pupil's photo only two live files of the category `student_photo` made for that pupil (`school.check_student_photo`, owned by the provisioner), and retires the previous ones in the same transaction (`school.retire_student_photo`): archived and due, their bytes deleted once the change is committed, or by the daily purge. The file policies now show, add or change a photo only with the pupil it shows in the reader's scope, where the category's permission alone was the whole school's; `school.file_for_link` never names a photo, and the generic file routes neither take nor serve one. The image is served inline, `nosniff` and `no-store`. The journal masks both file references. A school may turn photos off (`student.photo.enabled`): they are hidden and no new one is taken, nothing is deleted. Bytes written for a change that is then refused are removed on rollback (`Files`, all categories).

Increment 39 adds one read route, `POST /api/guardians/duplicates`, under `guardian.record.read`: like the pupils' suggestions, it answers from the guardians the reader may see (row-level security), at most ten, and never the reference of the school's file. Creating a guardian (`POST /api/guardians`) and importing one now refuse a probable duplicate (same name, same phone or e-mail) unless a reason is given, which the journal keeps; the import's message names the guardians found, the importer holding the permission to create guardians for the whole school. No schema change: names are compared by the application (`NameMatch`), on rows the database has already filtered.

Increment 40 adds `POST /api/enrollments/{enrollment}/transfer` under `enrollment.enrollment.cancel`, the controller also asking for `enrollment.enrollment.create` for the whole school, as a new enrolment does. `school.transfer_enrollment` runs with the writer's rights: the source's cancellation goes through its guard (cancel permission and scope), the target through the enrolment's guard and policies. The link between them (`transferred_from`) has no column privilege: `school.link_transfer` (provisioner) sets it on the new row only for a source of the same pupil cancelled in the same transaction, once per source (unique index); a forged `app.transfer_from` setting points to nothing it accepts. `school.keep_primary_contact` (provisioner) reads every link and guardian of the pupil, whatever the writer may see, with the pupil's row locked, and refuses the change that would leave an enrolled minor without a primary contact with a phone (schema 027).

Increment 41 adds a programme's subjects (schema 028): `GET /api/programs/{program}/subjects` and `GET /api/program-subjects/{line}/volumes` under `program.program.read`; `POST`, `PUT`, `POST …/end` and `DELETE` under `program.subject.manage`, on a programme the reader may reach (otherwise 404), the controller checking that permission's scope on that programme. The lines and their versions are read with the programme (`school.core_ids` on `program.program.read`), and written or deleted within the scope of `program.subject.manage`, which the guard checks again; a scoped reader finds in the journal only the entries of the lines they can see. `school.check_program_subject` (provisioner) reads the programme's year, the subject and the programme's other lines whatever the writer may see, to refuse a closed year, an archived subject or a second line of the same subject at the same dates; two requests adding it at once are taken one after the other (an advisory lock on the programme and subject), and an exclusion constraint backs the rule. The volume's version number and its versions (`school.program_subject_volume`) are the database's alone: no column privilege, `school.version_program_subject` (provisioner) writes them, nothing changes them. `school_runtime` may delete a line, like a subject of the catalogue (BR-02): its versions go with it by the foreign key, the journal keeps its values, and a subject a line names cannot be deleted. No personal data is involved.

Increment 42 attaches every pupil and guardian to a site (schema 029, decision of 13 October 2026) and lets grants limited to sites create there; no route is added. Creation routes ask for their permission with any scope instead of the whole school, and the database checks the site: `school.attach_site` (pupils, guardians: the site given, the grant's only site, or the default site for a school-wide grant; never an archived site; a move needs the update's grant on the new site) and `school.authorize_creation` (an enrolment for a pupil of the grant's sites, a group on them, a place in one of their groups for an enrolment the grant reaches, a guardian's link between a pupil and a guardian it reaches), both with the writer's rights, before the rules that read other rows. The creators' policies repeat these checks. Since a new row is not among the IDs a policy computes for the statement that writes it, the readers' policies of pupils, guardians, enrolments, groups and places also accept the row's own site (or its pupil's, its group's) through `school.site_scope_ids` (provisioner): what `core_ids` would open once the row exists, no more. A site's grant now also reads the pupils and guardians recorded on its sites and their enrolments and places; grants limited to groups, levels or one's own record are unchanged and still create nothing. `school.next_matricule` gives a number to whoever may record a pupil somewhere. Duplicate suggestions stay within what the writer may read, as before: a site's secretary is not told of a pupil recorded on another site.

Increment 43 adds each school's retention policy (schema 030) and one read route, `GET /api/retention` under `tenant.settings.manage`, which gives counts only (`school.retention_due`, provisioner, refused to anyone else). The daily run, `platform.apply_retention` (provisioner, granted to `school_runtime` for the scheduler like the file purge), takes no input but its limit and applies each school's own durations. It alone gets past four rules: a former pupil's frozen record, a read-only school, the school's required fields and the journal's immutability; `school.retention_run()` lets them through only when the current user is the provisioner and the run's transaction-local flag is set, so neither the application (`school_runtime`) nor the migrations' owner can, flag or not (tests/sql). The provisioner gains `UPDATE` on the personal columns it clears and `DELETE` on the journal, which only that rule then allows. Anonymisation leaves no personal value in the record, and the journal already masked them. A business refusal keeps a record for the next run; any other error aborts the run, so a missing privilege cannot silently keep records forever.

Increment 44 adds the staff's records (schema 031) and eight routes under the `staff` module: `GET /api/staff`, `GET /api/staff/{staff}` (`staff.profile.read`), `POST /api/staff` (`staff.profile.create`), `PUT /api/staff/{staff}`, `POST /api/staff/{staff}/status`, `/account`, `/archive` and `GET /api/staff/accounts` (`staff.profile.update`), and the screen `/school/staff`. Row access follows the pupils' pattern: restrictive policies for reading, creating and updating, by the whole school, by the grant's sites (the row's own site, through `school.site_scope_ids`, so that a record just created is read back), or by one's own record for a personal grant (`school.staff_ids`, provisioner); a grant limited to levels, groups or subjects reaches no record. `school.attach_site` now also attaches a member of staff, with the staff's permissions. A record is never deleted (no `DELETE` grant); the number, made by `school.next_staff_number` (provisioner, one creation at a time per school) or given, never changes; an archived record no longer changes. Linking an account is an ordinary update of the record under `staff.profile.update`, but nobody links their own account (`self_account`, as nobody assigns themselves a role), a revoked membership is refused, and an account goes to one record only, checked over every record whatever the writer may read (`school.check_staff_account`, provisioner). The link opens nothing by itself: a personal grant does, and only role managers give grants. The account list for linking (`GET /api/staff/accounts`) shows the school's members, as the account screens and the guardians' links already let a manager see them. The journal's `staff` domain is read with `staff.profile.read`, not by the technical administrator alone, and a scoped reader reads the entries of the records they reach (`core_resource_reader`); names, contact details and the school's fields are masked as for pupils. The school's sensitive fields of a staff record are read and written with `staff.contract.read` (the staff's human resources) on that record, never with `staff.profile.read` alone: the accountant reads a record's identity, not them.

Increment 45 adds a teacher's qualifications (schema 032) and four routes under the `staff` module: `GET /api/staff/{staff}/qualifications` (`staff.profile.read`), `POST /api/staff/{staff}/qualifications`, `POST /api/qualifications/{line}/end` and `DELETE /api/qualifications/{line}` (`staff.qualification.manage`), and the screen `/school/staff-qualifications`. A qualification is read with its teacher's record and written within the scope of `staff.qualification.manage` on that teacher (`school.staff_ids`): restrictive policies for reading, creating, updating and deleting. `school_runtime` may delete a line, as a programme's subject (028): a configuration line nothing uses yet; lot 4's sessions will refuse the deletion of one they use. A line's teacher, subject, node and first day never change, its end is final, and an archived record's lines no longer change. The rules that read the teacher's state, the catalogue and the other lines run with the provisioner's rights (`school.check_qualification`), so that a scoped writer cannot qualify twice what they cannot see. `school.qualified` (the coverage of a node's descendants, AC2) runs with the caller's rights: it answers about the qualifications the caller may read, and the staff list filter built on it shows only the records the reader reaches. The journal's `qualification` entries belong to the staff's domain, read like the records.

Increment 46 gives a group its lead teacher (schema 033) and the teachers their implicit scope (FR-IAM-04 AC1, TST-03); no route is added. `lead_teacher_id` is written with the group (`group.group.manage`, its existing scope); naming one also needs the teacher's record within the writer's scope of `staff.profile.read` (`school.guard_lead_teacher`, writer's rights), so that a grant limited to sites is told nothing of another site's staff, and the API answers an unreadable or unknown record as a validation error. The teacher's state is checked whatever the writer may read (`school.check_lead_teacher`, provisioner): a teacher still with the school. `school.core_ids` now opens to a personal grant the groups led by its holder's own staff record, while that record is neither left nor archived, exactly as a grant on these groups: their members, current pupils and their guardians, enrolments and programmes, and the journal entries of these, for the permissions the grant holds; it never creates (`school.authorize_creation` still asks for a site). A link between an account and a staff record is made by `staff.profile.update`, never by its holder (044), and the lead teacher by a group manager: a teacher cannot widen their own scope. The group's reply names its lead teacher only if the reader reads that record. The `teacher` role template, and the schools' copies made from it, gain `student.record.read`, `guardian.record.read` and `staff.profile.read` (section 3.3, « P »): assigned with the personal scope, the role reads its pupils and its own record; assigned for the whole school, it would read every pupil, as any internal role so assigned, which the assignment screen shows (P4: no code names a role).

Increment 47 adds a teacher's availability (schema 034) and eleven routes under the `staff` module: `GET /api/staff/{staff}/availabilities` and `/unavailabilities` (`staff.profile.read`), declaring, withdrawing and cancelling (`staff.availability.manage`), approving and rejecting (`staff.availability.approve`), and the screens `/school/staff-availability` and `/school/staff-unavailability`. Rows are read with the teacher's record and written within the scope of each permission on that teacher (`school.staff_ids`): restrictive policies for reading, creating, updating and deleting. `school_runtime` updates only a row's state, its decision's reason and its version; the trigger sets who decided and when. A declaration or a day off is in force at once unless the school's setting `staff.availability.require_approval` is on and the writer may not approve that record, or it is their own (`school.availability_in_force`, writer's rights); nobody decides on their own record (`self_approval`, through `school.is_own_staff`, provisioner, which answers only about the caller's own record). Only a pending row is withdrawn (deleted, journaled); a decided one never changes. The teacher's state and the rows already there are read with the provisioner's rights (`school.check_availability`), so a scoped writer cannot declare twice what they cannot see. `school.available` runs with the caller's rights, like `school.qualified`. The journal's `availability` and `unavailability` entries belong to the staff's domain.

Increment 48 adds the staff's contracts and leave (schema 035) and ten routes under the `staff` module, two of them screens: `GET /api/staff/{staff}/contracts` (`staff.contract.read`), recording, amending, ending and deleting a contract (`staff.contract.manage`); `GET /api/staff/{staff}/leaves` (`staff.leave.read`), recording and cancelling a leave (`staff.leave.manage`); `/school/staff-contracts` and `/school/staff-leaves`. Each is read and written within the scope of its own permission on that member (`school.staff_ids`), with restrictive policies, never with `staff.profile.read` alone. A contract has no column for an amount (FR-STF-04 AC1, checked by the SQL suite): pay stays with the remuneration rules and `payroll.*`. `school_runtime` changes only a contract's end (earlier only), its reason and its version, and a leave's state; it deletes a contract not begun yet and never a leave. A teacher's leave writes, in the writer's rights, an unavailability that points to it (`leave_id`) and carries no reason: whoever reads the record (`staff.profile.read`) reads that the teacher is away, never the leave's type or notes, which are read with `staff.leave.read` and masked in the journal (`observation`, BR-17). That unavailability is written and cancelled with its leave only: `school.check_availability` (provisioner) refuses one that does not match a planned leave of the same member and days, or that changes while its leave is planned, and keeps none for a member who does not teach; the availability's routes refuse it before the database. The policies on `school.unavailability` let `staff.leave.manage` write a row only when it has a `leave_id`. The member's state and the terms already there are read with the provisioner's rights (`school.check_staff_terms`), so a scoped writer cannot record twice what they cannot see. The `teacher` role template, and the schools' copies, gain `staff.contract.read` and `staff.leave.read` (section 3.3, « P »): with the personal scope, a teacher reads their own contracts and leave, and, as `staff.contract.read` also opens a staff record's sensitive custom fields (031), their own record's.

Increment 49 imports the staff and their qualifications (schema 036) through the existing import routes; no route is added. As every import, a kind is offered, previewed, confirmed and undone only by whoever holds its permission for the whole school (`staff.profile.create`, `staff.qualification.manage`) and `import.job.create`; a grant limited to sites imports nothing. Each row is written in the importer's own rights, under the same triggers and policies as the screens: an update of an existing member needs `staff.profile.update`, a site is named by code among the school's, a teacher, a subject and a node by reference or code, never created. A member's file reference (`external_ref`) is given once, never changed (`code_immutable`), and masked in the journal. A sensitive custom field of a member is written by whoever reads the staff's contracts (`staff.contract.read`), as on screen; the pupils' stay with `student.sensitive.read`. Undoing a member goes through `school.discard_staff` (provisioner): only a member that an import still in place created, active, without an account, a qualification, an availability, a day off, a contract, a leave or a group they lead, by whoever may create staff for the whole school; the provisioner gains `DELETE` and `UPDATE (archived_at)` on `school.staff` for it (the column only lets it lock the row). A qualification created by an import is undone by the importer, in its rights, while its end is as imported; the import never changes a line already there.

Increment 50 adds rooms (schema 037) and sixteen routes under the `sites_rooms` module, three of them screens: reading rooms, their closures and the school's lists of room types and equipment (`referential.node.read`, as the sites), and writing them (`room.room.manage`). A room and its closures are written within that permission's scope, the room's site or the whole school, by restrictive policies (`school.site_scope_ids`) and by the controllers before any lock (a row another site's grant may not update is not locked, the answer is `permission_denied`); the lists are the whole school's and changed only with the permission for the whole school. A room's site and code never change; a list's kind and code neither; both are archived, never deleted, and closures cancelled. A room's type and equipment must be entries of the lists still in use when given. The provisioner's `platform.enable_module` (which the console and `create_tenant` call) now also gives a school in use the publisher's lists when it receives the module, through `INSERT` on `school.room_option` (it already bypasses RLS); a school not in use writes nothing and receives the module without them, then takes them back itself with `POST /api/room-options/defaults` (`room.room.manage` for the whole school, reading `platform.room_option_default`); `school.guard_room_option` lets the provisioner through and nobody else without the permission for the whole school. A group's main room (`main_room_id`) is written with the group (`group.group.manage`): `school.check_main_room`, in the writer's rights (rooms are read by every member), keeps it on the group's site, in use, available when named, and never smaller than the group, whichever changes (FR-SLL-02 AC1). A site with rooms in use is not archived (FR-SLL-01). `school.room_available` runs with the caller's rights, for lot 4's sessions (AC2). The journal's `room`, `room_closure` and `room_option` entries belong to the referential's domain.

Increment 51 adds prospects (schema 038) and twenty-six routes under the `students_crm` module, three of them screens: reading prospects, their children, interactions and reminders, the members they may be assigned to and the school's lists of sources and steps (`crm.lead.read`), recording one (`crm.lead.create`), following it (`crm.lead.update`) and converting a child (`crm.lead.convert`). A prospect belongs to a site, as a pupil: restrictive policies read and write it within each permission's scope (`school.site_scope_ids`), and its children, interactions and reminders follow it through the prospect's own visibility; the guards check again, per permission and site (`school.lead_in_scope`), that the update of a contact needs `crm.lead.update` and the registration of a conversion `crm.lead.convert` on the prospect's site, so that a grant of one permission on a site and of another elsewhere does not combine. An interaction has no `UPDATE` privilege; a reminder only changes its state. Whom a prospect is assigned to is checked by `school.lead_assignee_valid` (the provisioner's, over the member's roles of the day: an active member reading the prospect's site), and the members offered by `school.lead_assignees`, for whoever reads prospects, as identifiers the school's people view names. The conversion runs in the request's single transaction: it locks the child first, creates the pupil and the guardian through the same code and rules as their own routes (permissions checked again by their policies), links them, enrols through `school.enroll`, then sets the child's link; `school.guard_lead_child` sets that link once, to the pupil's own enrolment (`lead_conversion_mismatch`), so a second conversion fails and rolls back whatever it created, even at once. A closed prospect's planned reminders are cancelled by `school.close_lead_reminders`, the provisioner's (`UPDATE (status)` on the reminders), because the conversion's writer may not change reminders. The scheduler's `platform.notify_due_reminders` (the provisioner's, called by `school_runtime` outside any school) reads the due reminders of the schools in use only, records each one's notice once in `school.lead_reminder_notice` (no privilege for the application but `SELECT` within its school), and notifies the assignee only while they read the prospect's site; the notification holds the prospect's and reminder's identifiers, never a name or phone (BR-19). `platform.enable_module` gives a school in use the lists of sources and steps as it gives the rooms' (`INSERT` on `school.lead_option`); the pipeline's own steps are never archived. The direction's and head of studies' role templates gain `crm.lead.read` (section 3.3); their roles in the schools in use receive it. The journal's `lead*` entries belong to the pedagogy domain, read with `crm.lead.read`, a scoped reader reading those of the prospects they see; their names, phone, e-mail, birth dates, what was said (`body`) and the school's fields are masked.

Increment 52 adds no route. An enrolment's reply carries `access_granted`, computed by `school.access_open(status, id)` and `school.access_granted(id)`, both with the caller's rights: the enrolment is read through its own policies, so an enrolment out of the reader's reach opens nothing, and nothing is stored. `school.enrollment_paid` reads the enrolment's agreed price and discounts only; lot 3 replaces it with the validated payments. The school's policy is a setting of a new kind (`code`, one of its definition's `allowed_values`), written by `tenant.settings.manage` for the whole school and checked by `school.guard_setting`.

Increment 53 adds two routes: reading the annual decisions (`enrollment.enrollment.read`) and taking a batch of them (`enrollment.progression.decide`). `school.progression` is written only through `school.decide_progression`, with the caller's rights: the decider's scope must reach the source enrolment (`school.require_core`, and a restrictive policy on insert), and a promotion's or repetition's enrolment and assignment go through `school.enroll` and the memberships' own policies and triggers, so they need the decider's own permissions to enrol and to assign (no definer function bypasses them). A decision has no `UPDATE` or `DELETE` privilege and its guard refuses both; an advisory lock per source enrolment makes the same decision sent twice at once one. Decisions are read with the enrolments they decide about. The journal's `progression` entries belong to the pedagogy domain, a scoped reader reading those of the enrolments they see.

Increment 54 adds no route. `school.notify_composition`, the provisioner's, runs after a membership is written or ended, because the writer may read neither the staff's records nor the teacher's roles: it reads the group's lead teacher's account, notifies them through `school.notify` (active members only) if they still hold `group.group.read` and are not the author, with the group's identifier and the change only.

Increment 55 adds no route. `platform.apply_lead_retention` and `platform.lead_retention_candidates`, the provisioner's, called by the daily run outside any school, anonymise the lost prospects whose duration is over, school by school, with the retention flag (`school.retention_run`) that alone lets 038's guards through; the provisioner gains `UPDATE` on the personal columns of the prospects, their children, interactions and reminders, nothing else. An anonymised prospect is refused any later change.

Increment 56 adds a kind of import and no route. `school.discard_lead`, the provisioner's, deletes a prospect an import still in place created, with its children, only for `crm.lead.create` for the whole school and while nothing has been built on it (`DELETE` on the prospects and their children for the provisioner, nothing for the application). `school.freeze_code` now lets the retention run alone (the provisioner with its flag) clear a frozen code, the file references it anonymises: before, imported pupils and guardians were never anonymised (their refusal counted as kept); a migration, the owner or the application still cannot change one.

Increment 57 adds one route, copying a programme (`program.program.manage`). `school.copy_program` runs with the caller's rights: the source is read through its own policies, the copy and its subjects are written through the programmes' and their subjects' policies and triggers (an open target year, the catalogue's subjects), and an advisory lock per source makes the same copy asked twice at once one.

Increment 58 adds two kinds of import and no route. `school.discard_program` and `school.discard_group`, the provisioner's, delete a programme or a group an import still in place created, only with the permission for the whole school and while nothing uses it; the provisioner gains `DELETE` on programmes, their prices and groups, and reads the decisions of the annual progression. A programme's prices stay immutable for everyone else: `school.guard_program_price` lets their deletion through only to the provisioner, for the programme its function is discarding (its flag).

Increment 59 adds no route. `platform.apply_staff_retention` and `platform.staff_retention_candidates`, the provisioner's, called by the daily run outside any school, anonymise the archived members of staff whose duration is over, with the retention flag that alone lets 031's, 034's and 035's guards and rules through; the provisioner gains `UPDATE` on the personal columns of the staff, their contracts, leaves, days off and availability, nothing else.

## Access matrix

Every route of the API and every screen, with who may reach it. Generated by `php artisan platform:access-matrix`; `PermissionCoverageTest` fails when this table and the code differ, so a route cannot be added without appearing here.

- **Access**: `public`; `signed link` (a temporary URL signed by the server); `signed in` (any person with an account, before choosing a school); `member of the school` (the layers above, in the school chosen by the server); `operator, MFA` (a publisher operator who has passed MFA).
- **Permission (one of)**: annex A permissions, any one of which opens the route. `(own records)`: no permission, because the route describes the session itself (`/api/me`, `/api/school`) or row-level security restricts it to the person's own jobs and notifications.
- **Module**: the module that must be on.

Livewire's update endpoint is not listed: it runs again the middleware of the page it updates (`SchoolPanelContext`, `RequirePermission`, `RequireModule`), which `PermissionCoverageTest` checks.

<!-- access-matrix -->
| Method | Path | Access | Permission (one of) | Module |
|---|---|---|---|---|
| DELETE | `/api/availabilities/{declaration}` | member of the school | `staff.availability.manage` | staff |
| DELETE | `/api/contracts/{contract}` | member of the school | `staff.contract.manage` | staff |
| DELETE | `/api/program-subjects/{line}` | member of the school | `program.subject.manage` | enrollment_groups |
| DELETE | `/api/qualifications/{line}` | member of the school | `staff.qualification.manage` | staff |
| DELETE | `/api/session` | public |  |  |
| DELETE | `/api/students/{student}/photo` | member of the school | `student.record.update` | students_crm |
| DELETE | `/api/subjects/{subject}` | member of the school | `subject.subject.manage` |  |
| DELETE | `/api/support/session` | signed in |  |  |
| DELETE | `/api/unavailabilities/{unavailability}` | member of the school | `staff.availability.manage` | staff |
| GET | `/api/accounts` | member of the school | `user.account.read` |  |
| GET | `/api/audit` | member of the school | `audit.log.read` |  |
| GET | `/api/csrf` | public |  |  |
| GET | `/api/custom-fields` | member of the school | `referential.node.read` |  |
| GET | `/api/deliveries/failed` | member of the school | `tenant.settings.manage` |  |
| GET | `/api/enrollments/{enrollment}/groups` | member of the school | `enrollment.enrollment.read` | enrollment_groups |
| GET | `/api/enrollments/{enrollment}/history` | member of the school | `enrollment.enrollment.read` | enrollment_groups |
| GET | `/api/enrollments/{enrollment}` | member of the school | `enrollment.enrollment.read` | enrollment_groups |
| GET | `/api/enrollments` | member of the school | `enrollment.enrollment.read` | enrollment_groups |
| GET | `/api/exports` | member of the school | `export.job.create` |  |
| GET | `/api/files/link/{tenant}/{file}` | signed link |  |  |
| GET | `/api/files/{file}/content` | member of the school | `finance.payment.read, expense.attachment.read, student.record.read, staff.profile.read, program.program.read, export.job.create, import.job.create` |  |
| GET | `/api/files/{file}` | member of the school | `finance.payment.read, expense.attachment.read, student.record.read, staff.profile.read, program.program.read, export.job.create, import.job.create` |  |
| GET | `/api/groups/{group}/memberships` | member of the school | `group.group.read` | enrollment_groups |
| GET | `/api/groups/{group}` | member of the school | `group.group.read` | enrollment_groups |
| GET | `/api/groups` | member of the school | `group.group.read` | enrollment_groups |
| GET | `/api/guardians/{guardian}` | member of the school | `guardian.record.read` | students_crm |
| GET | `/api/guardians` | member of the school | `guardian.record.read` | students_crm |
| GET | `/api/imports/templates/{kind}` | member of the school | `import.job.create` |  |
| GET | `/api/imports/{batch}/report` | member of the school | `import.job.create, import.job.read` |  |
| GET | `/api/imports/{batch}/rows` | member of the school | `import.job.create, import.job.read` |  |
| GET | `/api/imports/{batch}` | member of the school | `import.job.create, import.job.read` |  |
| GET | `/api/imports` | member of the school | `import.job.create, import.job.read` |  |
| GET | `/api/jobs/{job}` | member of the school | (own records) |  |
| GET | `/api/jobs` | member of the school | (own records) |  |
| GET | `/api/lead-assignees` | member of the school | `crm.lead.read` | students_crm |
| GET | `/api/lead-options` | member of the school | `crm.lead.read` | students_crm |
| GET | `/api/leads/{lead}/interactions` | member of the school | `crm.lead.read` | students_crm |
| GET | `/api/leads/{lead}/reminders` | member of the school | `crm.lead.read` | students_crm |
| GET | `/api/leads/{lead}` | member of the school | `crm.lead.read` | students_crm |
| GET | `/api/leads` | member of the school | `crm.lead.read` | students_crm |
| GET | `/api/me` | member of the school | (own records) |  |
| GET | `/api/notifications/preferences` | member of the school | (own records) |  |
| GET | `/api/notifications` | member of the school | (own records) |  |
| GET | `/api/permissions` | member of the school | `role.definition.manage, role.assignment.manage` |  |
| GET | `/api/program-subjects/{line}/volumes` | member of the school | `program.program.read` | enrollment_groups |
| GET | `/api/programs/{program}/prices` | member of the school | `program.program.read` | enrollment_groups |
| GET | `/api/programs/{program}/subjects` | member of the school | `program.program.read` | enrollment_groups |
| GET | `/api/programs/{program}` | member of the school | `program.program.read` | enrollment_groups |
| GET | `/api/programs` | member of the school | `program.program.read` | enrollment_groups |
| GET | `/api/progressions` | member of the school | `enrollment.enrollment.read` | enrollment_groups |
| GET | `/api/referential/nodes` | member of the school | `referential.node.read` |  |
| GET | `/api/referential/types` | member of the school | `referential.node.read` |  |
| GET | `/api/retention` | member of the school | `tenant.settings.manage` |  |
| GET | `/api/role-assignments` | member of the school | `role.assignment.manage` |  |
| GET | `/api/roles` | member of the school | `role.definition.manage, role.assignment.manage` |  |
| GET | `/api/room-options` | member of the school | `referential.node.read` | sites_rooms |
| GET | `/api/rooms/{room}/closures` | member of the school | `referential.node.read` | sites_rooms |
| GET | `/api/rooms/{room}` | member of the school | `referential.node.read` | sites_rooms |
| GET | `/api/rooms` | member of the school | `referential.node.read` | sites_rooms |
| GET | `/api/school` | member of the school | (own records) |  |
| GET | `/api/session/schools` | signed in |  |  |
| GET | `/api/settings` | member of the school | `tenant.settings.manage` |  |
| GET | `/api/sites` | member of the school | `referential.node.read` | sites_rooms |
| GET | `/api/staff/accounts` | member of the school | `staff.profile.update` | staff |
| GET | `/api/staff/{staff}/availabilities` | member of the school | `staff.profile.read` | staff |
| GET | `/api/staff/{staff}/contracts` | member of the school | `staff.contract.read` | staff |
| GET | `/api/staff/{staff}/leaves` | member of the school | `staff.leave.read` | staff |
| GET | `/api/staff/{staff}/qualifications` | member of the school | `staff.profile.read` | staff |
| GET | `/api/staff/{staff}/unavailabilities` | member of the school | `staff.profile.read` | staff |
| GET | `/api/staff/{staff}` | member of the school | `staff.profile.read` | staff |
| GET | `/api/staff` | member of the school | `staff.profile.read` | staff |
| GET | `/api/students/{student}/guardians` | member of the school | `guardian.record.read` | students_crm |
| GET | `/api/students/{student}/lead` | member of the school | `crm.lead.read` | students_crm |
| GET | `/api/students/{student}/photo` | member of the school | `student.record.read` | students_crm |
| GET | `/api/students/{student}` | member of the school | `student.record.read` | students_crm |
| GET | `/api/students` | member of the school | `student.record.read` | students_crm |
| GET | `/api/subjects` | member of the school | `referential.node.read` |  |
| GET | `/api/support-grants` | member of the school | `support.access.grant, audit.log.read` |  |
| GET | `/api/years` | member of the school | `referential.node.read` |  |
| GET | `/console/login` | public |  |  |
| GET | `/console/multi-factor-authentication/set-up` | operator, MFA |  |  |
| GET | `/console/supervision` | operator, MFA |  |  |
| GET | `/console/tenants/create` | operator, MFA |  |  |
| GET | `/console/tenants` | operator, MFA |  |  |
| GET | `/console` | operator, MFA |  |  |
| GET | `/school/accounts` | member of the school | `user.account.read` |  |
| GET | `/school/activation` | public |  |  |
| GET | `/school/assignments` | member of the school | `role.assignment.manage` |  |
| GET | `/school/choose-school` | signed in |  |  |
| GET | `/school/credentials` | signed in |  |  |
| GET | `/school/custom-fields` | member of the school | `referential.node.read` |  |
| GET | `/school/deliveries` | member of the school | `tenant.settings.manage` |  |
| GET | `/school/enrollments` | member of the school | `enrollment.enrollment.read` | enrollment_groups |
| GET | `/school/exports` | member of the school | `export.job.create` |  |
| GET | `/school/group-members` | member of the school | `group.group.read` | enrollment_groups |
| GET | `/school/groups` | member of the school | `group.group.read` | enrollment_groups |
| GET | `/school/guardians` | member of the school | `guardian.record.read` | students_crm |
| GET | `/school/home` | member of the school | (own records) |  |
| GET | `/school/imports` | member of the school | `import.job.create, import.job.read` |  |
| GET | `/school/journal` | member of the school | `audit.log.read` |  |
| GET | `/school/lead-options` | member of the school | `crm.lead.read` | students_crm |
| GET | `/school/lead-sheet` | member of the school | `crm.lead.read` | students_crm |
| GET | `/school/leads` | member of the school | `crm.lead.read` | students_crm |
| GET | `/school/login` | public |  |  |
| GET | `/school/notifications` | member of the school | (own records) |  |
| GET | `/school/periods` | member of the school | `referential.node.read` |  |
| GET | `/school/program-subjects` | member of the school | `program.program.read` | enrollment_groups |
| GET | `/school/programs` | member of the school | `program.program.read` | enrollment_groups |
| GET | `/school/recovery` | public |  |  |
| GET | `/school/reference-nodes` | member of the school | `referential.node.read` |  |
| GET | `/school/reference-types` | member of the school | `referential.node.read` |  |
| GET | `/school/roles` | member of the school | `role.definition.manage` |  |
| GET | `/school/room-closures` | member of the school | `referential.node.read` | sites_rooms |
| GET | `/school/room-options` | member of the school | `referential.node.read` | sites_rooms |
| GET | `/school/rooms` | member of the school | `referential.node.read` | sites_rooms |
| GET | `/school/settings` | member of the school | `tenant.settings.manage` |  |
| GET | `/school/sites` | member of the school | `referential.node.read` | sites_rooms |
| GET | `/school/staff-availability` | member of the school | `staff.profile.read` | staff |
| GET | `/school/staff-contracts` | member of the school | `staff.contract.read` | staff |
| GET | `/school/staff-leaves` | member of the school | `staff.leave.read` | staff |
| GET | `/school/staff-qualifications` | member of the school | `staff.profile.read` | staff |
| GET | `/school/staff-unavailability` | member of the school | `staff.profile.read` | staff |
| GET | `/school/staff` | member of the school | `staff.profile.read` | staff |
| GET | `/school/student-sheet` | member of the school | `student.record.read` | students_crm |
| GET | `/school/students` | member of the school | `student.record.read` | students_crm |
| GET | `/school/subjects` | member of the school | `referential.node.read` |  |
| GET | `/school/support-access` | member of the school | `support.access.grant, audit.log.read` |  |
| GET | `/school/tasks` | member of the school | (own records) |  |
| GET | `/school/years` | member of the school | `referential.node.read` |  |
| GET | `/school` | member of the school | (own records) |  |
| POST | `/api/account/mfa/confirm` | signed in |  |  |
| POST | `/api/account/mfa` | signed in |  |  |
| POST | `/api/accounts/import` | member of the school | `user.account.create` |  |
| POST | `/api/accounts/{user}/invitation` | member of the school | `user.account.create` |  |
| POST | `/api/accounts/{user}/password` | member of the school | `user.account.update` |  |
| POST | `/api/accounts/{user}/status` | member of the school | `user.account.suspend` |  |
| POST | `/api/accounts` | member of the school | `user.account.create` |  |
| POST | `/api/activation/check` | public |  |  |
| POST | `/api/activation` | public |  |  |
| POST | `/api/availabilities/{declaration}/approve` | member of the school | `staff.availability.approve` | staff |
| POST | `/api/availabilities/{declaration}/reject` | member of the school | `staff.availability.approve` | staff |
| POST | `/api/contracts/{contract}/amend` | member of the school | `staff.contract.manage` | staff |
| POST | `/api/contracts/{contract}/end` | member of the school | `staff.contract.manage` | staff |
| POST | `/api/custom-fields/{field}/archive` | member of the school | `custom_field.definition.manage` |  |
| POST | `/api/custom-fields` | member of the school | `custom_field.definition.manage` |  |
| POST | `/api/deliveries/{delivery}/retry` | member of the school | `tenant.settings.manage` |  |
| POST | `/api/enrollments/{enrollment}/cancel` | member of the school | `enrollment.enrollment.cancel` | enrollment_groups |
| POST | `/api/enrollments/{enrollment}/suspend` | member of the school | `enrollment.enrollment.suspend` | enrollment_groups |
| POST | `/api/enrollments/{enrollment}/transfer` | member of the school | `enrollment.enrollment.cancel` | enrollment_groups |
| POST | `/api/enrollments/{enrollment}/validate` | member of the school | `enrollment.enrollment.validate` | enrollment_groups |
| POST | `/api/enrollments` | member of the school | `enrollment.enrollment.create` | enrollment_groups |
| POST | `/api/exports` | member of the school | `export.job.create` |  |
| POST | `/api/files/{file}/archive` | member of the school | `finance.payment.create, expense.expense.create, student.record.update, staff.profile.update, program.program.manage` |  |
| POST | `/api/files/{file}/link` | member of the school | `finance.payment.read, expense.attachment.read, student.record.read, staff.profile.read, program.program.read, export.job.create, import.job.create` |  |
| POST | `/api/files` | member of the school | `finance.payment.create, expense.expense.create, student.record.update, staff.profile.update, program.program.manage` |  |
| POST | `/api/group-memberships/{membership}/end` | member of the school | `group.membership.manage` | enrollment_groups |
| POST | `/api/group-memberships/{membership}/transfer` | member of the school | `group.membership.manage` | enrollment_groups |
| POST | `/api/groups/{group}/memberships` | member of the school | `group.membership.manage` | enrollment_groups |
| POST | `/api/groups` | member of the school | `group.group.manage` | enrollment_groups |
| POST | `/api/guardians/duplicates` | member of the school | `guardian.record.read` | students_crm |
| POST | `/api/guardians` | member of the school | `guardian.record.create` | students_crm |
| POST | `/api/imports/{batch}/confirm` | member of the school | `import.job.create` |  |
| POST | `/api/imports/{batch}/rollback` | member of the school | `import.job.create` |  |
| POST | `/api/imports` | member of the school | `import.job.create` |  |
| POST | `/api/jobs/{job}/cancel` | member of the school | (own records) |  |
| POST | `/api/jobs/{job}/retry` | member of the school | (own records) |  |
| POST | `/api/lead-children/{child}/archive` | member of the school | `crm.lead.update` | students_crm |
| POST | `/api/lead-children/{child}/convert` | member of the school | `crm.lead.convert` | students_crm |
| POST | `/api/lead-options/defaults` | member of the school | `crm.lead.update` | students_crm |
| POST | `/api/lead-options/{option}/archive` | member of the school | `crm.lead.update` | students_crm |
| POST | `/api/lead-options` | member of the school | `crm.lead.update` | students_crm |
| POST | `/api/lead-reminders/{reminder}/cancel` | member of the school | `crm.lead.update` | students_crm |
| POST | `/api/lead-reminders/{reminder}/done` | member of the school | `crm.lead.update` | students_crm |
| POST | `/api/leads/duplicates` | member of the school | `crm.lead.read` | students_crm |
| POST | `/api/leads/{lead}/children` | member of the school | `crm.lead.update` | students_crm |
| POST | `/api/leads/{lead}/interactions` | member of the school | `crm.lead.update` | students_crm |
| POST | `/api/leads/{lead}/reminders` | member of the school | `crm.lead.update` | students_crm |
| POST | `/api/leads/{lead}/status` | member of the school | `crm.lead.update` | students_crm |
| POST | `/api/leads` | member of the school | `crm.lead.create` | students_crm |
| POST | `/api/leaves/{leave}/cancel` | member of the school | `staff.leave.manage` | staff |
| POST | `/api/notifications/read` | member of the school | (own records) |  |
| POST | `/api/notifications/{notification}/read` | member of the school | (own records) |  |
| POST | `/api/periods/{period}/archive` | member of the school | `referential.node.manage` |  |
| POST | `/api/program-subjects/{line}/end` | member of the school | `program.subject.manage` | enrollment_groups |
| POST | `/api/programs/{program}/copy` | member of the school | `program.program.manage` | enrollment_groups |
| POST | `/api/programs/{program}/subjects` | member of the school | `program.subject.manage` | enrollment_groups |
| POST | `/api/programs` | member of the school | `program.program.manage` | enrollment_groups |
| POST | `/api/progressions` | member of the school | `enrollment.progression.decide` | enrollment_groups |
| POST | `/api/qualifications/{line}/end` | member of the school | `staff.qualification.manage` | staff |
| POST | `/api/recovery/check` | public |  |  |
| POST | `/api/recovery/request` | public |  |  |
| POST | `/api/recovery` | public |  |  |
| POST | `/api/referential/nodes/{node}/archive` | member of the school | `referential.node.manage` |  |
| POST | `/api/referential/nodes` | member of the school | `referential.node.manage` |  |
| POST | `/api/role-assignments/{assignment}/archive` | member of the school | `role.assignment.manage` |  |
| POST | `/api/role-assignments` | member of the school | `role.assignment.manage` |  |
| POST | `/api/roles/{role}/archive` | member of the school | `role.definition.manage` |  |
| POST | `/api/roles` | member of the school | `role.definition.manage` |  |
| POST | `/api/room-closures/{closure}/cancel` | member of the school | `room.room.manage` | sites_rooms |
| POST | `/api/room-options/defaults` | member of the school | `room.room.manage` | sites_rooms |
| POST | `/api/room-options/{option}/archive` | member of the school | `room.room.manage` | sites_rooms |
| POST | `/api/room-options` | member of the school | `room.room.manage` | sites_rooms |
| POST | `/api/rooms/{room}/archive` | member of the school | `room.room.manage` | sites_rooms |
| POST | `/api/rooms/{room}/closures` | member of the school | `room.room.manage` | sites_rooms |
| POST | `/api/rooms` | member of the school | `room.room.manage` | sites_rooms |
| POST | `/api/session/mfa` | public |  |  |
| POST | `/api/session` | public |  |  |
| POST | `/api/sites/{site}/archive` | member of the school | `site.site.manage` | sites_rooms |
| POST | `/api/sites` | member of the school | `site.site.manage` | sites_rooms |
| POST | `/api/staff/{staff}/account` | member of the school | `staff.profile.update` | staff |
| POST | `/api/staff/{staff}/archive` | member of the school | `staff.profile.update` | staff |
| POST | `/api/staff/{staff}/availabilities` | member of the school | `staff.availability.manage` | staff |
| POST | `/api/staff/{staff}/contracts` | member of the school | `staff.contract.manage` | staff |
| POST | `/api/staff/{staff}/leaves` | member of the school | `staff.leave.manage` | staff |
| POST | `/api/staff/{staff}/qualifications` | member of the school | `staff.qualification.manage` | staff |
| POST | `/api/staff/{staff}/status` | member of the school | `staff.profile.update` | staff |
| POST | `/api/staff/{staff}/unavailabilities` | member of the school | `staff.availability.manage` | staff |
| POST | `/api/staff` | member of the school | `staff.profile.create` | staff |
| POST | `/api/students/duplicates` | member of the school | `student.record.read` | students_crm |
| POST | `/api/students/{student}/archive` | member of the school | `student.record.archive` | students_crm |
| POST | `/api/students/{student}/guardians/{link}/archive` | member of the school | `guardian.record.update` | students_crm |
| POST | `/api/students/{student}/guardians` | member of the school | `guardian.record.create` | students_crm |
| POST | `/api/students/{student}/photo` | member of the school | `student.record.update` | students_crm |
| POST | `/api/students/{student}/status` | member of the school | `student.record.update` | students_crm |
| POST | `/api/students` | member of the school | `student.record.create` | students_crm |
| POST | `/api/subjects/{subject}/archive` | member of the school | `subject.subject.manage` |  |
| POST | `/api/subjects` | member of the school | `subject.subject.manage` |  |
| POST | `/api/support-grants/{grant}/revoke` | member of the school | `support.access.grant` |  |
| POST | `/api/support-grants` | member of the school | `support.access.grant` |  |
| POST | `/api/support/session` | signed in |  |  |
| POST | `/api/unavailabilities/{unavailability}/approve` | member of the school | `staff.availability.approve` | staff |
| POST | `/api/unavailabilities/{unavailability}/cancel` | member of the school | `staff.availability.manage` | staff |
| POST | `/api/unavailabilities/{unavailability}/reject` | member of the school | `staff.availability.approve` | staff |
| POST | `/api/years/{year}/periods` | member of the school | `referential.node.manage` |  |
| POST | `/api/years/{year}/status` | member of the school | `referential.node.manage` |  |
| POST | `/api/years` | member of the school | `referential.node.manage` |  |
| POST | `/console/locale/{locale}` | operator, MFA |  |  |
| POST | `/console/logout` | operator, MFA |  |  |
| POST | `/school/logout` | signed in |  |  |
| PUT | `/api/account/locale` | signed in |  |  |
| PUT | `/api/account/password` | signed in |  |  |
| PUT | `/api/accounts/{user}` | member of the school | `user.account.update` |  |
| PUT | `/api/custom-fields/{field}` | member of the school | `custom_field.definition.manage` |  |
| PUT | `/api/groups/{group}` | member of the school | `group.group.manage` | enrollment_groups |
| PUT | `/api/guardians/{guardian}` | member of the school | `guardian.record.update` | students_crm |
| PUT | `/api/lead-children/{child}` | member of the school | `crm.lead.update` | students_crm |
| PUT | `/api/lead-options/{option}` | member of the school | `crm.lead.update` | students_crm |
| PUT | `/api/leads/{lead}` | member of the school | `crm.lead.update` | students_crm |
| PUT | `/api/notifications/preferences` | member of the school | (own records) |  |
| PUT | `/api/periods/{period}` | member of the school | `referential.node.manage` |  |
| PUT | `/api/program-subjects/{line}` | member of the school | `program.subject.manage` | enrollment_groups |
| PUT | `/api/programs/{program}` | member of the school | `program.program.manage` | enrollment_groups |
| PUT | `/api/referential/nodes/{node}` | member of the school | `referential.node.manage` |  |
| PUT | `/api/referential/types/{type}` | member of the school | `referential.node.manage` |  |
| PUT | `/api/roles/{role}/permissions` | member of the school | `role.definition.manage` |  |
| PUT | `/api/roles/{role}` | member of the school | `role.definition.manage` |  |
| PUT | `/api/room-options/{option}` | member of the school | `room.room.manage` | sites_rooms |
| PUT | `/api/rooms/{room}` | member of the school | `room.room.manage` | sites_rooms |
| PUT | `/api/session/school` | signed in |  |  |
| PUT | `/api/settings/{key}` | member of the school | `tenant.settings.manage` |  |
| PUT | `/api/sites/{site}` | member of the school | `site.site.manage` | sites_rooms |
| PUT | `/api/staff/{staff}` | member of the school | `staff.profile.update` | staff |
| PUT | `/api/students/{student}/guardians/{link}` | member of the school | `guardian.record.update` | students_crm |
| PUT | `/api/students/{student}` | member of the school | `student.record.update` | students_crm |
| PUT | `/api/subjects/{subject}` | member of the school | `subject.subject.manage` |  |
| PUT | `/api/years/{year}` | member of the school | `referential.node.manage` |  |
<!-- /access-matrix -->

## What the tests keep true

Each property is a test, so a later lot that breaks one fails CI. The first nine look at every table, policy, function, route, page and view there is, so they also hold for those added later.

| Property | Test |
|---|---|
| Every table of the `school` schema forces row-level security, even on its owner | `tests/sql/database.mjs`, Security review |
| Every policy reads the request's context once per query, never once per row | `tests/sql/database.mjs`, Security review |
| No function of the `school`, `platform` and `secret` schemas is executable by everyone; each function running with its owner's rights belongs to `platform_provisioner` | `tests/sql/database.mjs`, Security review |
| `school_runtime`, `platform_admin` and `platform_auth` are not superusers, do not bypass row-level security, create neither roles nor databases, and own no table or function | `tests/sql/database.mjs`, Security review |
| Every school route declares a permission, except those that describe the session or the person's own records | `PermissionCoverageTest` |
| Every page of the school's panel declares a permission, except the person's own pages (home, tasks, notifications, choice of school, credentials); Livewire's updates go through the same checks | `PermissionCoverageTest` |
| No application code names a role: it asks for permissions (P4) | `PermissionCoverageTest` |
| This review's matrix is the code's | `PermissionCoverageTest` |
| No view or class writes raw HTML, and the sanitizer behind Filament's notifications drops every attribute Alpine or Livewire reads: no markup from data carries an expression for `'unsafe-eval'` to run | `ContentSecurityPolicyTest` |
| A school's application cannot create a school, make an account, archive a school or read the publisher's supervision | `tests/sql/database.mjs`, Security review |
| A school's application reads no password hash, MFA secret or recovery code, and changes none; the sign-in's role reaches those and nothing else | `tests/sql/database.mjs`, Security review; `AuthenticationTest` |
| The database believes a request's context only signed with the key, on the connection it was made for, since its transaction began; a forged, altered or replayed context is refused, the unsigned settings of lot 1 open nothing, and no role of the application reads or sets the key | `tests/sql/database.mjs`, Security review |
| A school's application reads the person of the request and, in its school, its people and the publisher's operators; nobody else, and nobody without a context; a person changes their own language only | `tests/sql/database.mjs`, Security review; `SchoolIsolationTest` |
| The start of a request and a page check the signature a few times, never once per row or per permission | `tests/sql/database.mjs`, Security review; `tests/sql/context-cost.mjs` measures the cost |
| Two schools created from the console never see each other's people, through the API or the screens | `LotOneWalkthroughTest`, `SchoolIsolationTest`, `tests/sql/database.mjs` (isolation) |
| What a person may not see on screen, they may not export | `LotOneWalkthroughTest` (R01), `ExportTest` |
| No permission granted by someone who does not hold it; no assignment to oneself; the last administrator kept; segregation of duties | `tests/sql/database.mjs` (roles), `AuthorizationTest` |
| The journal is never changed or deleted, and records the author and the reason | `tests/sql/database.mjs` (audit) |
| A pupil, a guardian, a link or an enrolment is deleted only by undoing the import still in place that created it, by whoever may create it for the whole school, while nothing depends on it | `tests/sql/database.mjs` (the reference import), `PupilImportTest` |
| Account links: 256 random bits, stored as a SHA-256 hash, used once, expiring | `AccountTest`, `SchoolPublicPagesTest` |
| A file is served to a person who may read it in its school, or through a signed link valid 15 minutes | `FileTest` |
| A pupil's photo is read and changed only with the pupil it shows, within the reader's scope; never through a link or the generic file routes; drawn again without metadata; the previous image deleted | `tests/sql/database.mjs` (the pupil's photo), `StudentPhotoTest` |
| Support access only under the school's consent, limited in time, revocable, each consultation journaled, read-only | `SupportTest`, `tests/sql/database.mjs` (support access) |
| No PHP dependency with a known vulnerability is shipped (NFR-SEC-08) | CI, `composer audit` |
| Every page sends the Content Security Policy with a fresh nonce, which every inline script bears; no screen has anything blocked, in Chromium, Firefox and WebKit; no page loads anything from another site | `ContentSecurityPolicyTest`, `tests/browser/acceptance.mjs` |
| The strict policy's trial serves Livewire's CSP build of Alpine without `'unsafe-eval'`, so that the browser acceptance can tell when Filament runs on it | `ContentSecurityPolicyTest`; `PLATFORM_CSP_STRICT=true sh deploy/acceptance-test.sh` |
| Requests run as `school_runtime`; the owner's password never reaches the application | `deploy/smoke-test.sh`, `SessionTest` |
| The database, Redis and the storage are out of the proxy's reach; Redis asks for its password | `deploy/smoke-test.sh` |

## Findings

| # | Finding | Severity | State |
|---|---|---|---|
| F1 | Every function was executable by everyone | High | Fixed in this review |
| F2 | `school_runtime` read every person's credentials | Medium | Fixed in increment 25; names and emails confined in increment 28 |
| F3 | `school_runtime` could change the credential columns of any person | Medium | Fixed in increment 25 |
| F4 | No Content Security Policy | Medium | Fixed in increment 26; `'unsafe-eval'` kept, Filament needs it (increment 29) |
| F5 | Redis had no password | Low | Fixed in increment 24 |
| F6 | Firefox and Safari were not tested | Low | Fixed in increment 24 |
| F7 | Row-level security trusted the context the application sets | Medium | Fixed in increment 27 |
| F8 | Every signed-in page sent the person's initials to ui-avatars.com | Low | Fixed in increment 26 |

### F1. Every function was executable by everyone (fixed)

PostgreSQL grants `EXECUTE` to `PUBLIC` on every new function. `001_foundation.sql` meant to take it back with a per-schema default privilege, which cannot undo the global grant: it did nothing. So `school_runtime` could call every function of the `school` and `platform` schemas, among them the publisher's functions that run with the provisioner's rights: create a school (`platform.create_tenant`), make an account (`platform.ensure_user`), suspend or archive a school (`platform.set_tenant_status`), read the supervision's counts of every school.

These functions check no caller: their grant was meant to be their only guard. It could not be reached from a browser, since the application calls them only in the console and every query is parameterised; but an SQL injection in a school's screen would have reached them, and the separation of the database roles, the line that holds when the application has a bug, was undone.

Fix: `deploy/roles.sql` takes `EXECUTE` back from `PUBLIC` on every function of both schemas, then grants each function to the roles that need it. It runs on every deployment, so a function added later is closed too. The SQL suite now checks that no function is executable by everyone, and that a school's application cannot call the publisher's functions.

### F2. `school_runtime` read every person's credentials (fixed)

`public.users` has no row-level security, and `school_runtime` could read all its columns: every person's password hash (Argon2id) and MFA secret (encrypted with the application key, which the database does not hold), in every school. The application read only what it needed, but an SQL injection would have exposed every hash.

### F3. `school_runtime` could change the credential columns of any person (fixed)

`school_runtime` could update `password`, `mfa_secret` and the columns around them on every row of `public.users`. The application changed them only for the person signed in and at sign-in; the database did not confine them.

Fix of F2 and F3 (increment 25): a fourth role, `platform_auth`, is the only one that reads or writes the credentials: password hashes, MFA secrets, the last TOTP step and the recovery codes (`018_credentials.sql`, `deploy/roles.sql`). It has no other column of a person, no table of the platform or of a school, and no function. The application uses it through one class, `App\Support\Credentials`, called by the sign-in, the MFA challenge and the person's own password and MFA, and by `CredentialsUserProvider` for the console's sign-in. `school_runtime` keeps a person's name, email, language, and whether they have a password and MFA; an administrator's reset still goes through `school.reset_password`. No "remember me" is offered any more: a session ends at its time limits, and no token outlives it.

What remained: `school_runtime` still read every person's name and email, not only its school's people, since the person signed in is read before a school is chosen. Fixed in increment 28, once the database knew the person for sure (F7): row-level security confines `public.users` (`020_people.sql`). A school's application reads the person of the request and, in the school the request reaches, its members, whatever their state, and the publisher's operators, whom its journal names (`school.people`, read with its owner's rights so that the membership's own permission checks do not hide the journal's authors). Outside a school it reads the person alone; without a context, nobody. A person changes their own language only. The application reads the person signed in, before any school, in a context of their own that it rolls back at once (`DatabaseContext::reading`, `CredentialsUserProvider`); the console's sign-in finds the person by `platform.find_login`, and the recovery's email takes the person's language from `platform.issue_recovery`. Each person is checked by an index as their row is read: a page of 25 among the 25 000 people of one school, with its count, takes 84 ms instead of 32 (`tests/sql/context-cost.mjs`), a single person 0.3 ms.

### F4. No Content Security Policy (fixed, `'unsafe-eval'` kept)

The headers stopped sniffing, framing by another site and the leak of addresses (`docker/apache.conf`), but no `Content-Security-Policy` restricted scripts (NFR-SEC-03).

Fix (increment 26): every response carries a policy (`App\Http\Middleware\ContentSecurityPolicy`). A script runs only from the platform's own files, or inline with the response's nonce, a new one each time, which Livewire and Filament put on theirs: a script injected into a page, inline or from elsewhere, does not run. No plugin; images, fonts, connections, frames and form targets on the platform only; no framing by another site. The browser acceptance fails a screen on anything the policy blocks, in three browsers, the sign-in's typing and clicks included; `deploy/smoke-test.sh` checks the header. `PLATFORM_CSP_REPORT_ONLY=true` reports without blocking, should a screen break in production (DEPLOY.md).

What is left: `'unsafe-eval'` for scripts, and `'unsafe-inline'` for styles. Alpine, under Filament, evaluates the expressions written in its attributes, and Livewire its `@script` blocks; Filament writes style attributes. Markup injected into a page could still carry Alpine attributes that run: Blade and Filament escape what they show and no screen writes raw HTML, so the injection must come first. Tried in increment 29, with `PLATFORM_CSP_STRICT=true`, which serves Livewire's CSP build of Alpine and takes `'unsafe-eval'` out of the policy: 104 of the 108 screens fail in Chromium as they load, before any click. That build parses expressions instead of evaluating them, and its parser takes no statement, `new`, arrow function, template literal or global of the browser. Filament 5.10 writes all of these in its own markup (its tables' selection and sorting, its modals, dropdowns, tabs, sidebar and form fields), and Livewire wraps each `@script` block in an arrow function, the one Filament adds to every signed-in page included. Every failure is Filament's: 92 screens on that block (`CSP Parser Error: Unexpected token )`), 12 on the forms of the sign-in and recovery pages. The four that pass are the platform's own account link, whose logic moved from its markup into an `Alpine.data` component. `'unsafe-eval'` stays until Filament runs on that build; the same trial, after each upgrade of Filament, tells when (`PLATFORM_CSP_STRICT=true sh deploy/acceptance-test.sh`; never in production).

What keeps `'unsafe-eval'` from being used against the platform: an expression runs only from an attribute of the page's markup, and no markup comes from data. No view or class of the platform writes raw HTML (`{!!`, `x-html`, `HtmlString`, `->html()`, `->markdown()`, `->allowHtml()`), and the sanitizer behind Filament's notifications keeps none of the attributes Alpine or Livewire read (`x-`, `@`, `:`, `wire:`). `ContentSecurityPolicyTest` keeps both true for the code to come.

### F5. Redis had no password (fixed)

Sessions, the cache and the queue are in Redis, which listened without a password on the deployment's network. No port was published, but any other container of that network, the proxy included, could read the sessions.

Fix (increment 24): Redis asks for `REDIS_PASSWORD`, written by `deploy/new-env.sh` like the other secrets. The database, Redis and the storage moved to an internal network that only the application's services join: the proxy no longer reaches them, and they have no way out to the internet. `deploy/smoke-test.sh` checks both, on every change.

### F6. Firefox and Safari were not tested (fixed)

The browser acceptance ran on Chromium only. The server's checks do not depend on the browser, but the screens' behaviour may.

Fix (increment 24): `deploy/acceptance-test.sh` runs every screen in Chromium, Firefox and WebKit, Safari's engine (`BROWSERS`); CI runs the three side by side. All 324 screens pass: 108 in each browser.

### F7. Row-level security trusted the context the application sets (fixed)

The database learnt the school and the person of a request from two settings, `app.tenant_id` and `app.user_id`, which the application set at the start of each transaction. Any SQL running as `school_runtime` could set them too. An SQL injection could therefore name another school and read its rows, or name an administrator and pass the permission checks of the functions running with their owner's rights, which read the same settings. Row-level security stopped a query that forgot its school; it did not stop an injection.

Fix (increment 27): the application signs the context, and the database believes it only signed (`019_signed_context.sql`, `App\Support\DatabaseContext`). The context names the school, the person, the support consent, the request and its address, followed by its HMAC-SHA256 under a key derived from the application's key; `platform:roles` gives the database that key at every deployment. The key is in the `secret` schema, which only `platform_provisioner` reaches, and `school.context()` alone reads it: `school_runtime`, `platform_admin` and `platform_auth` neither read nor change it. `school.context()` refuses, with `context_invalid`, a context whose signature is wrong, signed for another server process than the connection's (`pg_backend_pid()`, which the signature covers), or signed before its transaction began (five seconds allowed between the clocks of the application's host and the database's). `school.tenant()`, `school.actor()`, the consent in use and the journal's author, request and address all come from it. An injection can thus name neither another school nor another person. It cannot replay a context taken from another request either: that one was signed for another connection, or before the current transaction. It can only clear the context, which closes every school row.

The cost was measured before the fix was kept (`tests/sql/context-cost.mjs`: PostgreSQL 16, two schools of 25 000 subjects each, journaled; medians, round trips included):

| Measure | Settings of lot 1 | Signed context |
|---|---|---|
| A request that only enters its school | 1.6 ms | 1.6 ms |
| Its start, as `SchoolContext` checks the school and the person | 6.5 ms | 5.3 ms |
| `school.actor()` called for each of 10 000 rows | 3.2 ms | 125 ms |
| A page of 25 subjects among 25 000, and their count | 80 s | 5.0 ms |
| The journal's first page, and its count | 7.4 ms | 8.0 ms |
| 500 subjects written, stamped and journaled | 849 ms | 58 ms |

A check of the signature costs about 13 µs, where reading a setting cost almost nothing: a query must not make it once per row (the third line). Every policy, and every function that read it for each row of a query, now reads the context once per query, as `(SELECT school.actor())`; a test keeps that true for every policy, those to come included, and another bounds the checks made by the start of a request and a page. The triggers still read it once per row written, which the last line includes. A school's own membership is read with the provisioner's rights (`school.allowed_tenant()`), since the person is now certain, without the permission checks the membership's policies make. The measure also showed what the policies of lot 1 cost: each row called `school.allowed()`, whose reading of the membership ran those permission checks again, so a page of 25 subjects among 25 000, with its count, took 80 seconds; it takes 5 ms now, and writing 500 rows 58 ms instead of 849.

What remains: an injection still acts as the request's own person in the request's school, within that person's permissions and the roles' privileges. The reason given for a change (`app.reason`) stays the application's statement, unsigned, like the text of a form. A connection pooler in transaction mode cannot sit between the application and the database, since a context is signed for one server process (DEPLOY.md).

### F8. Every signed-in page sent the person's initials to ui-avatars.com (fixed)

Filament draws a person's avatar by asking an outside service, ui-avatars.com, for an image of their initials. Every page of the panels thus told that service the initials, the address and the browser of whoever was signed in. The Content Security Policy found it, by blocking the image (F4).

Fix (increment 26): the initials are drawn by the platform (`App\Support\InitialsAvatar`), as an image inside the page. `ContentSecurityPolicyTest` checks that a signed-in page loads nothing from another site.

## Outside this review

- A penetration test by a third party.
- Alerts on abnormal activity (NFR-SEC-07): locks and changes of rights are journaled, not yet alerted.
- The rotation of keys and passwords in operation (NFR-SEC-02).
- Encrypted traffic between containers, unnecessary on one host's private network; to reconsider when the deployment spans several hosts (NFR-SEC-01).
