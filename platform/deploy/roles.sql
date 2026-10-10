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
  platform.tenant_mode(uuid) TO school_runtime;

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
GRANT SELECT ON public.users TO platform_provisioner;
GRANT EXECUTE ON FUNCTION school.tenant(), school.actor(), school.allowed(uuid) TO platform_provisioner;
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
