-- Run as database administrator AFTER migrations, on every deployment. Safe to run again.
-- Passwords are set apart (ALTER ROLE ... PASSWORD), never in this file.
ALTER ROLE school_runtime LOGIN;
ALTER ROLE platform_admin LOGIN;
ALTER ROLE platform_auth LOGIN;

-- A function runs only where this file grants it, never for everyone. PostgreSQL grants EXECUTE
-- to PUBLIC on every new function, and a per-schema default privilege cannot take that back
-- (001's did nothing): without this, the restricted roles could call every function of the
-- schemas they use, the publisher's definer functions included. Applied again on every
-- deployment, so that a function added later is closed too (SECURITY-REVIEW.md).
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA platform, school, secret FROM PUBLIC;
REVOKE ALL ON ALL PROCEDURES IN SCHEMA school, secret FROM PUBLIC;

-- Schools' application: its own school's data only, through RLS. No table ownership,
-- no BYPASSRLS, no memberships or audit entries written directly, no deletion.
GRANT USAGE ON SCHEMA school, platform TO school_runtime;
-- A person as the schools see them: no password hash, MFA secret or recovery code (018,
-- findings F2 and F3). Revoked first, so that a deployment also takes back what an older one
-- granted; the person changes only their language. Accounts, memberships and links are
-- written by the definer functions only (007).
REVOKE ALL ON public.users, public.mfa_recovery_code FROM school_runtime;
GRANT SELECT (id, name, email, email_verified_at, locale, must_change_password, password_changed_at, mfa_enabled_at, has_password,
  created_at, updated_at) ON public.users TO school_runtime;
GRANT UPDATE (locale, updated_at) ON public.users TO school_runtime;
-- Whose rows: the person, and the people of the school the request reaches (020, rest of F2).
GRANT SELECT ON school.people TO school_runtime;
GRANT SELECT ON school.membership, school.audit_log TO school_runtime;
GRANT SELECT, INSERT ON school.site TO school_runtime;
GRANT UPDATE (labels, version, archived_at) ON school.site TO school_runtime;
GRANT SELECT ON platform.tenant, platform.module, platform.module_dependency, platform.plan TO school_runtime;
-- The request's context, signed by the application and checked by school.context() (019, F7).
GRANT EXECUTE ON FUNCTION school.context(), school.tenant(), school.actor(), school.allowed_tenant(), school.allowed(uuid),
  school.module_enabled(text), school.my_schools(), school.my_modules(), platform.tenant_mode(uuid) TO school_runtime;
-- Roles and assignments: written by the application, checked by triggers (permission held,
-- never on oneself, scope, last administrator). A role is archived, never deleted (FR-IAM-03);
-- a permission is taken off a role by deleting the link, which the audit keeps.
GRANT SELECT ON platform.permission, platform.role_template, platform.role_template_permission, platform.sod_rule TO school_runtime;
GRANT SELECT ON school.role, school.role_permission, school.role_assignment TO school_runtime;
GRANT INSERT (tenant_id, code, kind, labels) ON school.role TO school_runtime;
GRANT UPDATE (labels, version, archived_at) ON school.role TO school_runtime;
GRANT INSERT (tenant_id, role_id, permission_code), DELETE ON school.role_permission TO school_runtime;
GRANT INSERT (tenant_id, user_id, role_id, scope_type, scope_ids, valid_from, valid_until) ON school.role_assignment TO school_runtime;
GRANT UPDATE (scope_type, scope_ids, valid_until, version, archived_at) ON school.role_assignment TO school_runtime;
GRANT EXECUTE ON FUNCTION school.is_system(), school.my_permissions(), school.can(text), school.can_everywhere(text),
  school.check_segregation(text, bigint, boolean) TO school_runtime;
GRANT EXECUTE ON FUNCTION school.create_account(text, text, text, text, text), school.update_account(bigint, text, text, text, text),
  school.reset_password(bigint, text), school.set_account_status(bigint, text, text), school.issue_activation(bigint, text),
  platform.issue_recovery(text, text), platform.redeem_token(text, text, text), platform.peek_token(text, text),
  platform.find_login(text, text),
  school.mfa_required(), school.setting_value(text) TO school_runtime;
-- Referential (008): the school's tree, subjects, years, periods and custom fields. Types come
-- from the profile and are only renamed or turned off; nodes, years and periods are archived,
-- never deleted; a subject is deleted only while nothing references it (FR-REF-03).
GRANT SELECT ON platform.profile, platform.profile_node_type, platform.profile_node, platform.profile_subject TO school_runtime;
GRANT SELECT ON school.node_type, school.ref_node, school.subject, school.academic_year, school.period, school.custom_field TO school_runtime;
GRANT UPDATE (labels, active, version) ON school.node_type TO school_runtime;
GRANT INSERT (tenant_id, type_code, parent_id, code, labels, sort_order, platform_code) ON school.ref_node TO school_runtime;
GRANT UPDATE (labels, sort_order, version, archived_at) ON school.ref_node TO school_runtime;
GRANT INSERT (tenant_id, code, kind, labels, description, sort_order), DELETE ON school.subject TO school_runtime;
GRANT UPDATE (labels, description, sort_order, version, archived_at) ON school.subject TO school_runtime;
GRANT INSERT (tenant_id, code, start_date, end_date, status) ON school.academic_year TO school_runtime;
GRANT UPDATE (start_date, end_date, status, version) ON school.academic_year TO school_runtime;
GRANT INSERT (tenant_id, year_id, code, labels, start_date, end_date, sort_order) ON school.period TO school_runtime;
GRANT UPDATE (labels, start_date, end_date, sort_order, version, archived_at) ON school.period TO school_runtime;
GRANT INSERT (tenant_id, entity, key, field_type, options, required, is_sensitive, labels, sort_order) ON school.custom_field TO school_runtime;
GRANT UPDATE (options, required, is_sensitive, labels, sort_order, version, archived_at) ON school.custom_field TO school_runtime;
GRANT EXECUTE ON FUNCTION school.label_key(text), school.valid_labels(jsonb), school.node_subtree(uuid[]), school.year_open(uuid),
  school.check_custom_values(text, jsonb), school.open_day(date) TO school_runtime;
-- Shared services (010): the journal read by domain (its policy decides), the user's own jobs,
-- notifications and preferences; emails leave through the outbox, worked by the definer functions.
GRANT SELECT ON platform.audit_domain, platform.notification_event TO school_runtime;
GRANT SELECT, INSERT (tenant_id, user_id, kind, idempotency_key, input) ON school.job TO school_runtime;
GRANT UPDATE (status, progress, result, error_code, error_message, cancel_requested_at) ON school.job TO school_runtime;
GRANT SELECT, UPDATE (read_at) ON school.notification TO school_runtime;
GRANT SELECT, INSERT, UPDATE (email) ON school.notification_preference TO school_runtime;
GRANT SELECT ON school.outbox TO school_runtime;
GRANT EXECUTE ON FUNCTION school.audit_readable_types(), school.notify(text, bigint[], jsonb), platform.claim_outbox(integer, integer),
  platform.finish_outbox(uuid, text), school.retry_delivery(uuid) TO school_runtime;
-- Files (011): the register, read and written within the categories the user's permissions
-- open (row-level security); the bytes are the application's, in private storage.
GRANT SELECT ON platform.file_category TO school_runtime;
GRANT SELECT, INSERT (tenant_id, category, storage_key, original_name, file_type, media_type, size_bytes, sha256, owner_type, owner_id, expires_at)
  ON school.file_object TO school_runtime;
GRANT UPDATE (owner_type, owner_id, archived_at, version) ON school.file_object TO school_runtime;
GRANT EXECUTE ON FUNCTION school.file_categories(text), school.storage_use(uuid), school.file_for_link(uuid, uuid),
  platform.expired_files(integer), platform.mark_purged(uuid) TO school_runtime;
-- Export log (012): written by the author of each export, never changed.
GRANT SELECT, INSERT (tenant_id, user_id, kind, format, filters, columns, row_count, file_id) ON school.export_log TO school_runtime;
-- Imports (013): batches and their rows, worked by their author; imported accounts nobody
-- used yet are discarded only through the definer function.
GRANT SELECT, INSERT (tenant_id, user_id, kind, file_id, options) ON school.import_batch TO school_runtime;
GRANT UPDATE (status, error_message) ON school.import_batch TO school_runtime;
GRANT SELECT, INSERT (tenant_id, batch_id, line, external_ref, data, outcome, action, error_code, error_message, target_type, target_id, previous)
  ON school.import_row TO school_runtime;
GRANT UPDATE (outcome, action, error_code, error_message, target_type, target_id, previous) ON school.import_row TO school_runtime;
GRANT EXECUTE ON FUNCTION school.discard_account(bigint) TO school_runtime;
-- Support access (015): consents written by the school; a support session reads under one.
GRANT SELECT, INSERT (tenant_id, include_sensitive, reason, starts_at, expires_at) ON school.support_grant TO school_runtime;
GRANT UPDATE (revoked_at, version) ON school.support_grant TO school_runtime;
GRANT EXECUTE ON FUNCTION school.support_grant_in_use(), school.log_support_access(text) TO school_runtime;
-- Languages (016): the person chooses their own (granted above); the rule resolving it, the search key.
GRANT EXECUTE ON FUNCTION platform.locale_for(bigint, uuid), school.search_key(text) TO school_runtime;
-- Settings: a school stores the values it changes, within the catalog's bounds.
GRANT SELECT ON platform.setting_definition TO school_runtime;
GRANT SELECT, INSERT (tenant_id, key, value) ON school.setting TO school_runtime;
GRANT UPDATE (value, version, archived_at) ON school.setting TO school_runtime;
-- Pupils, guardians, programmes, groups, enrolments (021). Archived, never deleted (BR-02); the
-- matricule, a price version, an enrolment's price and state history are written by the database.
GRANT SELECT ON school.student, school.guardian, school.student_guardian, school.program, school.program_price, school.study_group,
  school.enrollment, school.enrollment_status_history, school.group_membership TO school_runtime;
GRANT INSERT (tenant_id, matricule, last_name_ar, first_name_ar, last_name_lat, first_name_lat, birth_date, birth_place, gender, phone,
  email, address, status, status_reason, origin_school, custom, external_ref) ON school.student TO school_runtime;
GRANT UPDATE (last_name_ar, first_name_ar, last_name_lat, first_name_lat, birth_date, birth_place, gender, phone, email, address, status,
  status_reason, origin_school, custom, version, archived_at) ON school.student TO school_runtime;
-- A pupil's photo (026): two files the database accepts as theirs; it retires the previous ones.
GRANT UPDATE (photo_file_id, photo_thumb_file_id) ON school.student TO school_runtime;
GRANT INSERT (tenant_id, last_name_ar, first_name_ar, last_name_lat, first_name_lat, phone, email, profession, custom, external_ref)
  ON school.guardian TO school_runtime;
GRANT UPDATE (last_name_ar, first_name_ar, last_name_lat, first_name_lat, phone, email, profession, custom, version, archived_at)
  ON school.guardian TO school_runtime;
GRANT INSERT (tenant_id, student_id, guardian_id, relationship, is_primary_contact, is_payer, emergency_contact) ON school.student_guardian TO school_runtime;
GRANT UPDATE (relationship, is_primary_contact, is_payer, emergency_contact, version, archived_at) ON school.student_guardian TO school_runtime;
GRANT INSERT (tenant_id, year_id, node_id, code, labels, description, duration_value, duration_unit, base_price, payment_terms, status)
  ON school.program TO school_runtime;
GRANT UPDATE (labels, description, duration_value, duration_unit, base_price, payment_terms, status, version, archived_at) ON school.program TO school_runtime;
GRANT INSERT (tenant_id, year_id, code, labels, group_type, program_id, site_id, capacity, valid_from, valid_to, status) ON school.study_group TO school_runtime;
GRANT UPDATE (labels, capacity, valid_to, status, version, archived_at) ON school.study_group TO school_runtime;
GRANT INSERT (tenant_id, student_id, program_id, idempotency_key) ON school.enrollment TO school_runtime;
GRANT UPDATE (status, status_reason, version) ON school.enrollment TO school_runtime;
GRANT INSERT (tenant_id, enrollment_id, group_id, valid_from) ON school.group_membership TO school_runtime;
GRANT UPDATE (valid_to, version) ON school.group_membership TO school_runtime;
GRANT EXECUTE ON FUNCTION school.require(text), school.person_name_valid(text, text, text, text), school.enroll(uuid, uuid, uuid),
  school.move_to_group(uuid, uuid), school.next_matricule(uuid), school.core_ids(text, text), school.require_core(text, text, uuid),
  school.group_scope_valid(uuid, uuid[]) TO school_runtime;
GRANT EXECUTE ON FUNCTION school.student_enrollment_count(uuid) TO school_runtime;
-- Undoing an import of pupils, guardians or enrolments (025): what it created, nothing built on since.
GRANT EXECUTE ON FUNCTION school.discard_student(uuid), school.discard_guardian(uuid), school.discard_guardian_link(uuid),
  school.discard_enrollment(uuid) TO school_runtime;
-- A transfer from one programme to another (027): with the writer's rights, each step its permission.
GRANT EXECUTE ON FUNCTION school.transfer_enrollment(uuid, uuid, uuid, text, integer) TO school_runtime;
-- A programme's subjects (028): a line's programme, subject and first day stay; its volumes are
-- versioned by the database. A line never used is deleted (BR-02), as a subject of the catalogue.
GRANT SELECT ON school.program_subject, school.program_subject_volume TO school_runtime;
GRANT INSERT (tenant_id, program_id, subject_id, total_hours, weekly_hours, session_count, session_duration_minutes, coefficient,
  valid_from, valid_to) ON school.program_subject TO school_runtime;
GRANT UPDATE (total_hours, weekly_hours, session_count, session_duration_minutes, coefficient, valid_to, version) ON school.program_subject TO school_runtime;
GRANT DELETE ON school.program_subject TO school_runtime;
-- A pupil's site (029): set when they are recorded, within the writer's sites; moved with the update's grant on the new one.
GRANT INSERT (site_id) ON school.student, school.guardian TO school_runtime;
GRANT UPDATE (site_id) ON school.student, school.guardian TO school_runtime;
GRANT EXECUTE ON FUNCTION school.site_scope_ids(text, text) TO school_runtime;
-- Retention (030): what the next run will do, for whoever manages the settings; the daily run, by the scheduler.
GRANT EXECUTE ON FUNCTION school.retention_run(), school.retention_due(), platform.apply_retention(integer) TO school_runtime;
-- Staff (031): a record with or without an account (P7), on a site; its number given or made once; archived after
-- leaving, never deleted (BR-02). The dates of a departure and the number made are the database's.
GRANT SELECT ON school.staff TO school_runtime;
GRANT INSERT (tenant_id, staff_number, last_name_ar, first_name_ar, last_name_lat, first_name_lat, staff_type, phone, email, hire_date,
  site_id, custom, user_id) ON school.staff TO school_runtime;
GRANT UPDATE (last_name_ar, first_name_ar, last_name_lat, first_name_lat, staff_type, phone, email, hire_date, status, left_on, site_id,
  custom, user_id, version, archived_at) ON school.staff TO school_runtime;
GRANT EXECUTE ON FUNCTION school.staff_ids(text), school.require_staff(text, uuid), school.next_staff_number(uuid) TO school_runtime;
-- A teacher's qualifications (032): a line keeps its teacher, subject, node and first day; it ends, or is deleted while
-- nothing uses it (a configuration line, as a programme's subject). Its coverage of a node's descendants is computed.
GRANT SELECT, DELETE ON school.qualification TO school_runtime;
GRANT INSERT (tenant_id, staff_id, subject_id, node_id, valid_from, valid_to) ON school.qualification TO school_runtime;
GRANT UPDATE (valid_to, version) ON school.qualification TO school_runtime;
GRANT EXECUTE ON FUNCTION school.qualified(uuid, uuid, uuid, date) TO school_runtime;
-- A group's lead teacher (033): named with the group, among the teachers the writer reads.
GRANT INSERT (lead_teacher_id), UPDATE (lead_teacher_id) ON school.study_group TO school_runtime;

-- Sign-in and the person's own password and MFA (018): the credentials, and nothing else. No
-- schema of the platform's, no function, no other column of a person.
REVOKE ALL ON public.users, public.mfa_recovery_code FROM platform_auth;
GRANT SELECT (id, password, mfa_secret, mfa_enabled_at, mfa_last_step) ON public.users TO platform_auth;
GRANT UPDATE (password, must_change_password, password_changed_at, mfa_secret, mfa_enabled_at, mfa_last_step, updated_at)
  ON public.users TO platform_auth;
GRANT SELECT, INSERT, DELETE ON public.mfa_recovery_code TO platform_auth;
GRANT UPDATE (used_at) ON public.mfa_recovery_code TO platform_auth;

-- Publisher console: platform data and its functions only. No privilege at all on the
-- `school` schema, so no school data without the school's consent (FR-PLT-04 AC1).
GRANT USAGE ON SCHEMA platform TO platform_admin;
GRANT SELECT ON ALL TABLES IN SCHEMA platform TO platform_admin;
GRANT EXECUTE ON FUNCTION platform.create_tenant(uuid, text, text, text, text, date, date, integer, integer, text, text, bigint, text),
  platform.set_tenant_status(uuid, text, text), platform.enable_module(uuid, text), platform.disable_module(uuid, text),
  platform.set_entitlement(uuid, date, date, integer, integer, integer, integer), platform.tenant_mode(uuid),
  platform.module_enabled(uuid, text), platform.invite_admin(uuid, text), platform.apply_profile(uuid, text, integer),
  platform.ensure_user(text, text), platform.supervision(), platform.support_grants(uuid), platform.locale_for(bigint, uuid) TO platform_admin;

-- The provisioner owns the SECURITY DEFINER functions and holds exactly the privileges
-- they need. It bypasses RLS but cannot log in.
GRANT USAGE ON SCHEMA platform, school TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA platform TO platform_provisioner;
GRANT SELECT, INSERT ON school.site, school.membership, school.audit_log TO platform_provisioner;
GRANT SELECT, INSERT ON school.role, school.role_permission, school.role_assignment TO platform_provisioner;
GRANT SELECT, INSERT ON school.node_type, school.ref_node, school.subject TO platform_provisioner;
GRANT SELECT, INSERT ON school.notification TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE, DELETE ON school.outbox TO platform_provisioner;
GRANT SELECT ON school.notification_preference TO platform_provisioner;
GRANT SELECT, UPDATE ON school.file_object TO platform_provisioner;
GRANT DELETE ON school.membership, public.users, public.account_token TO platform_provisioner;
GRANT SELECT ON school.job, school.support_grant, school.setting TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.can_everywhere(text) TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.label_key(text), school.valid_labels(jsonb) TO platform_provisioner;
GRANT SELECT, INSERT ON public.users TO platform_provisioner;
GRANT UPDATE (name, email, password, must_change_password, password_changed_at, remember_token, updated_at) ON public.users TO platform_provisioner;
GRANT USAGE ON SEQUENCE public.users_id_seq TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE ON public.account_token TO platform_provisioner;
GRANT UPDATE (status, username, external_ref, activated_at, updated_at) ON school.membership TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.tenant(), school.actor(), school.allowed_tenant(), school.allowed(uuid), school.is_system() TO platform_provisioner;
-- The key that signs the request's context (019, F7): read by school.context() alone, which the
-- provisioner owns; no other role reaches the `secret` schema.
GRANT USAGE ON SCHEMA secret TO platform_provisioner;
GRANT SELECT ON secret.context_key TO platform_provisioner;
ALTER FUNCTION school.context() OWNER TO platform_provisioner;
ALTER FUNCTION school.allowed_tenant() OWNER TO platform_provisioner;
-- Read with its owner's rights: the membership's policies do not apply to it (020).
ALTER VIEW school.people OWNER TO platform_provisioner;
-- The rules of pupils, groups and enrolments that read other rows (021): capacity, quota, history.
GRANT SELECT ON school.student, school.guardian, school.student_guardian, school.program, school.enrollment, school.group_membership
  TO platform_provisioner;
-- PostgreSQL row locks also require an UPDATE privilege. These columns are never written by
-- the rule functions; the grant permits locking eligibility, price and enrolment state.
GRANT UPDATE (archived_at) ON school.student, school.program, school.enrollment TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE (last) ON school.matricule_counter TO platform_provisioner;
GRANT SELECT, UPDATE (archived_at) ON school.study_group TO platform_provisioner;
GRANT SELECT, INSERT ON school.program_price, school.enrollment_status_history TO platform_provisioner;
GRANT UPDATE (valid_to, updated_at, updated_by, version) ON school.group_membership TO platform_provisioner;
GRANT SELECT ON school.academic_year TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.require(text), school.require_core(text, text, uuid) TO platform_provisioner;
ALTER FUNCTION school.version_program_price() OWNER TO platform_provisioner;
ALTER FUNCTION school.check_group_places() OWNER TO platform_provisioner;
ALTER FUNCTION school.check_enrollment() OWNER TO platform_provisioner;
ALTER FUNCTION school.follow_enrollment() OWNER TO platform_provisioner;
ALTER FUNCTION school.check_group_membership() OWNER TO platform_provisioner;
ALTER FUNCTION school.core_ids(text, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.next_matricule(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.group_scope_valid(uuid, uuid[]) OWNER TO platform_provisioner;
ALTER FUNCTION school.student_enrollment_count(uuid) OWNER TO platform_provisioner;
-- Undoing an import (025): the rows an import still in place created, deleted by these functions
-- alone; the rows locked first, the import's own report read to know what it created.
GRANT SELECT ON school.import_batch, school.import_row TO platform_provisioner;
GRANT UPDATE (archived_at) ON school.guardian, school.student_guardian TO platform_provisioner;
GRANT DELETE ON school.student, school.guardian, school.student_guardian, school.enrollment, school.enrollment_status_history,
  school.group_membership TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.import_created(text, uuid) TO platform_provisioner;
ALTER FUNCTION school.discard_student(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.discard_guardian(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.discard_guardian_link(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.discard_enrollment(uuid) OWNER TO platform_provisioner;
-- A pupil's photo (026): its files checked whatever the writer may read, the previous ones retired.
GRANT EXECUTE ON FUNCTION school.setting_value(text) TO platform_provisioner;
ALTER FUNCTION school.check_student_photo() OWNER TO platform_provisioner;
ALTER FUNCTION school.retire_student_photo() OWNER TO platform_provisioner;
-- An enrolled minor's primary contact, a transfer's link (027): rows the writer may not see, the pupil's row locked.
ALTER FUNCTION school.keep_primary_contact(uuid, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.check_primary_contact() OWNER TO platform_provisioner;
ALTER FUNCTION school.link_transfer() OWNER TO platform_provisioner;
-- A programme's subjects (028): its year and the lines already there, whatever the writer may read; its volumes.
GRANT SELECT ON school.program_subject TO platform_provisioner;
GRANT SELECT, INSERT ON school.program_subject_volume TO platform_provisioner;
ALTER FUNCTION school.check_program_subject() OWNER TO platform_provisioner;
ALTER FUNCTION school.version_program_subject() OWNER TO platform_provisioner;
-- A pupil's site (029): the sites a grant names, and the pupils, guardians and groups on them, whatever the reader may see.
ALTER FUNCTION school.site_scope_ids(text, text) OWNER TO platform_provisioner;
-- Retention (030): the run anonymises pupils and guardians and deletes the journal's old entries, alone (its flag).
GRANT EXECUTE ON FUNCTION school.retention_run(), school.check_custom_values(text, jsonb), school.search_key(text),
  school.person_name_valid(text, text, text, text) TO platform_provisioner;
GRANT UPDATE (last_name_ar, first_name_ar, last_name_lat, first_name_lat, birth_date, birth_place, phone, email, address, origin_school, custom,
  external_ref, user_id, photo_file_id, photo_thumb_file_id, status_reason, anonymised_at) ON school.student TO platform_provisioner;
GRANT UPDATE (last_name_ar, first_name_ar, last_name_lat, first_name_lat, phone, email, profession, custom, external_ref, user_id, anonymised_at)
  ON school.guardian TO platform_provisioner;
GRANT DELETE ON school.audit_log TO platform_provisioner;
ALTER FUNCTION platform.retention_value(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.retention_candidates(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.apply_retention(integer) OWNER TO platform_provisioner;
ALTER FUNCTION school.retention_due() OWNER TO platform_provisioner;
-- Staff (031): the records a scope reaches, the next number and an account's one record, whatever the writer may read.
GRANT SELECT ON school.staff TO platform_provisioner;
ALTER FUNCTION school.staff_ids(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.next_staff_number(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.check_staff_account() OWNER TO platform_provisioner;
-- A teacher's qualifications (032): the teacher's state, the catalogue's and the lines already there, whatever the writer may read.
GRANT SELECT ON school.qualification TO platform_provisioner;
ALTER FUNCTION school.check_qualification() OWNER TO platform_provisioner;
-- A group's lead teacher (033): the teacher's state, whatever the writer may read.
ALTER FUNCTION school.check_lead_teacher() OWNER TO platform_provisioner;
ALTER FUNCTION platform.create_tenant(uuid, text, text, text, text, date, date, integer, integer, text, text, bigint, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.set_tenant_status(uuid, text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.enable_module(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.disable_module(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.set_entitlement(uuid, date, date, integer, integer, integer, integer) OWNER TO platform_provisioner;
ALTER FUNCTION platform.tenant_mode(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.module_enabled(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.module_enabled(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.audit() OWNER TO platform_provisioner;
ALTER FUNCTION platform.audit_tenant() OWNER TO platform_provisioner;
ALTER FUNCTION school.my_schools() OWNER TO platform_provisioner;
ALTER FUNCTION school.my_modules() OWNER TO platform_provisioner;
ALTER FUNCTION school.effective_permissions(bigint, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.my_permissions() OWNER TO platform_provisioner;
ALTER FUNCTION school.can(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.can_everywhere(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.manager_count(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.role_manages(uuid, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.manager_count_before(uuid, text, jsonb) OWNER TO platform_provisioner;
ALTER FUNCTION school.guard_last_manager() OWNER TO platform_provisioner;
ALTER FUNCTION school.check_segregation(text, bigint, boolean) OWNER TO platform_provisioner;
ALTER FUNCTION school.require_everywhere(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.audit_account(uuid, bigint, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.holds_sensitive_role(uuid, bigint) OWNER TO platform_provisioner;
ALTER FUNCTION school.shared_account(uuid, bigint) OWNER TO platform_provisioner;
ALTER FUNCTION school.create_account(text, text, text, text, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.update_account(bigint, text, text, text, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.reset_password(bigint, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.set_account_status(bigint, text, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.issue_activation(bigint, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.invite_admin(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.issue_recovery(text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.redeem_token(text, text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.find_login(text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.peek_token(text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.apply_profile(uuid, text, integer) OWNER TO platform_provisioner;
ALTER FUNCTION school.audit_readable_types() OWNER TO platform_provisioner;
ALTER FUNCTION school.notify(text, bigint[], jsonb) OWNER TO platform_provisioner;
ALTER FUNCTION school.journal_watchers(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.notify_sensitive() OWNER TO platform_provisioner;
ALTER FUNCTION platform.claim_outbox(integer, integer) OWNER TO platform_provisioner;
ALTER FUNCTION platform.finish_outbox(uuid, text) OWNER TO platform_provisioner;
ALTER FUNCTION school.retry_delivery(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.file_categories(text) OWNER TO platform_provisioner;
ALTER FUNCTION school.storage_use(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.file_for_link(uuid, uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.expired_files(integer) OWNER TO platform_provisioner;
ALTER FUNCTION platform.mark_purged(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION school.discard_account(bigint) OWNER TO platform_provisioner;
ALTER FUNCTION platform.ensure_user(text, text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.supervision() OWNER TO platform_provisioner;
ALTER FUNCTION school.support_grant_in_use() OWNER TO platform_provisioner;
ALTER FUNCTION school.log_support_access(text) OWNER TO platform_provisioner;
ALTER FUNCTION platform.support_grants(uuid) OWNER TO platform_provisioner;
ALTER FUNCTION platform.locale_for(bigint, uuid) OWNER TO platform_provisioner;
