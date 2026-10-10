-- Madrassa platform, PostgreSQL 16+. Run as the migration owner, never as an application role.
-- `platform` holds the publisher's data: schools (tenants), plans, modules.
-- `school` holds each school's data: every row carries tenant_id and row-level security filters it.

-- Roles exist before the policies that name them. deploy/roles.sql grants their privileges
-- and enables login. None owns a table or bypasses RLS, except the provisioner, which owns
-- only the SECURITY DEFINER functions and cannot log in.
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'school_runtime') THEN
    CREATE ROLE school_runtime NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_admin') THEN
    CREATE ROLE platform_admin NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'platform_provisioner') THEN
    CREATE ROLE platform_provisioner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;
  END IF;
END $$;

CREATE SCHEMA platform;
CREATE SCHEMA school;
REVOKE ALL ON SCHEMA platform, school FROM PUBLIC;
-- Functions are executable only where deploy/roles.sql grants it.
ALTER DEFAULT PRIVILEGES IN SCHEMA platform, school REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

-- Request context, set with set_config(..., true) at the start of each transaction.
-- These settings are not credentials: RLS also requires an active membership.
CREATE FUNCTION school.tenant() RETURNS uuid LANGUAGE sql STABLE AS $$
  SELECT nullif(current_setting('app.tenant_id', true), '')::uuid $$;
CREATE FUNCTION school.actor() RETURNS bigint LANGUAGE sql STABLE AS $$
  SELECT nullif(current_setting('app.user_id', true), '')::bigint $$;

-- §7.1 common columns, maintained here so no writer can forget or forge them.
-- `version` is an optimistic lock: a writer that read an older version is refused.
CREATE FUNCTION school.stamp() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    NEW.created_at := now(); NEW.created_by := school.actor();
    NEW.updated_at := NEW.created_at; NEW.updated_by := NEW.created_by; NEW.version := 1;
    RETURN NEW;
  END IF;
  IF (NEW.id, NEW.tenant_id, NEW.created_at, NEW.created_by) IS DISTINCT FROM (OLD.id, OLD.tenant_id, OLD.created_at, OLD.created_by) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.version IS DISTINCT FROM OLD.version THEN
    RAISE EXCEPTION 'stale_version' USING ERRCODE = '40001';
  END IF;
  NEW.version := OLD.version + 1; NEW.updated_at := now(); NEW.updated_by := school.actor();
  RETURN NEW;
END $$;

CREATE FUNCTION school.immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END $$;

-- BR-18: a code never changes once created. TG_ARGV[0] names the column.
CREATE FUNCTION school.freeze_code() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF to_jsonb(NEW) -> TG_ARGV[0] IS DISTINCT FROM to_jsonb(OLD) -> TG_ARGV[0] THEN
    RAISE EXCEPTION 'code_immutable' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
