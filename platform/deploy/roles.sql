-- Run as database administrator AFTER migrations, on every deployment. Safe to run again.
-- Passwords are set apart (ALTER ROLE ... PASSWORD), never in this file.
ALTER ROLE school_runtime LOGIN;
ALTER ROLE platform_admin LOGIN;

-- Schools' application: its own school's data only, through RLS. No table ownership,
-- no BYPASSRLS, no memberships or audit entries written directly, no deletion.
GRANT USAGE ON SCHEMA school, platform TO school_runtime;
GRANT SELECT ON public.users TO school_runtime;
GRANT UPDATE (password, remember_token, updated_at) ON public.users TO school_runtime;
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

-- Publisher console: platform data and its functions only. No privilege at all on the
-- `school` schema, so no school data without the school's consent (FR-PLT-04 AC1).
GRANT USAGE ON SCHEMA platform TO platform_admin;
GRANT SELECT ON ALL TABLES IN SCHEMA platform TO platform_admin;
GRANT EXECUTE ON FUNCTION platform.create_tenant(uuid, text, text, text, text, date, date, integer, integer, text, text, bigint),
  platform.set_tenant_status(uuid, text, text), platform.enable_module(uuid, text), platform.disable_module(uuid, text),
  platform.set_entitlement(uuid, date, date, integer, integer, integer, integer), platform.tenant_mode(uuid),
  platform.module_enabled(uuid, text) TO platform_admin;

-- The provisioner owns the SECURITY DEFINER functions and holds exactly the privileges
-- they need. It bypasses RLS but cannot log in.
GRANT USAGE ON SCHEMA platform, school TO platform_provisioner;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA platform TO platform_provisioner;
GRANT SELECT, INSERT ON school.site, school.membership, school.audit_log TO platform_provisioner;
GRANT SELECT, INSERT ON school.role, school.role_permission, school.role_assignment TO platform_provisioner;
GRANT SELECT ON public.users TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.tenant(), school.actor(), school.allowed(uuid), school.is_system() TO platform_provisioner;
ALTER FUNCTION platform.create_tenant(uuid, text, text, text, text, date, date, integer, integer, text, text, bigint) OWNER TO platform_provisioner;
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
