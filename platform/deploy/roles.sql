-- Run as database administrator AFTER migrations, on every deployment. Safe to run again.
-- Passwords are set apart (ALTER ROLE ... PASSWORD), never in this file.
ALTER ROLE school_runtime LOGIN;
ALTER ROLE platform_admin LOGIN;

-- Schools' application: its own school's data only, through RLS. No table ownership,
-- no BYPASSRLS, no memberships or audit entries written directly, no deletion.
GRANT USAGE ON SCHEMA school, platform TO school_runtime;
GRANT SELECT ON public.users TO school_runtime;
-- Sign-in and the person's own credentials: password change, MFA enrolment, recovery codes.
-- Accounts, memberships and links are written by the definer functions only (007).
GRANT UPDATE (password, remember_token, updated_at, must_change_password, password_changed_at, mfa_secret, mfa_enabled_at, mfa_last_step)
  ON public.users TO school_runtime;
GRANT SELECT (user_id, code_hash, used_at), INSERT, DELETE ON public.mfa_recovery_code TO school_runtime;
GRANT UPDATE (used_at) ON public.mfa_recovery_code TO school_runtime;
GRANT SELECT ON school.membership, school.audit_log TO school_runtime;
GRANT SELECT, INSERT ON school.site TO school_runtime;
GRANT UPDATE (labels, version, archived_at) ON school.site TO school_runtime;
GRANT SELECT ON platform.tenant, platform.module, platform.module_dependency, platform.plan TO school_runtime;
GRANT EXECUTE ON FUNCTION school.tenant(), school.actor(), school.allowed(uuid), school.module_enabled(text),
  school.my_schools(), school.my_modules(), platform.tenant_mode(uuid) TO school_runtime;
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
-- Settings: a school stores the values it changes, within the catalog's bounds.
GRANT SELECT ON platform.setting_definition TO school_runtime;
GRANT SELECT, INSERT (tenant_id, key, value) ON school.setting TO school_runtime;
GRANT UPDATE (value, version, archived_at) ON school.setting TO school_runtime;

-- Publisher console: platform data and its functions only. No privilege at all on the
-- `school` schema, so no school data without the school's consent (FR-PLT-04 AC1).
GRANT USAGE ON SCHEMA platform TO platform_admin;
GRANT SELECT ON ALL TABLES IN SCHEMA platform TO platform_admin;
GRANT EXECUTE ON FUNCTION platform.create_tenant(uuid, text, text, text, text, date, date, integer, integer, text, text, bigint, text),
  platform.set_tenant_status(uuid, text, text), platform.enable_module(uuid, text), platform.disable_module(uuid, text),
  platform.set_entitlement(uuid, date, date, integer, integer, integer, integer), platform.tenant_mode(uuid),
  platform.module_enabled(uuid, text), platform.invite_admin(uuid, text), platform.apply_profile(uuid, text, integer) TO platform_admin;

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
GRANT EXECUTE ON FUNCTION school.label_key(text), school.valid_labels(jsonb) TO platform_provisioner;
GRANT SELECT, INSERT ON public.users TO platform_provisioner;
GRANT UPDATE (name, email, password, must_change_password, password_changed_at, remember_token, updated_at) ON public.users TO platform_provisioner;
GRANT USAGE ON SEQUENCE public.users_id_seq TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE ON public.account_token TO platform_provisioner;
GRANT UPDATE (status, username, external_ref, activated_at, updated_at) ON school.membership TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.tenant(), school.actor(), school.allowed(uuid), school.is_system() TO platform_provisioner;
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
