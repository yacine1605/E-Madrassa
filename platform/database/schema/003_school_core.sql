-- School level: membership, isolation (ARC-02, BR-01), read-only mode (FR-PLT-02 AC3),
-- append-only audit (BR-09, FR-AUD-01), sites (ARC-03) and school creation (FR-PLT-01).

-- FR-IAM-01 (base): only an active membership opens a school's data. Roles and scopes
-- come with the IAM work (FR-IAM-03/04); the application never writes memberships directly.
CREATE TABLE school.membership (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  user_id bigint NOT NULL REFERENCES public.users(id),
  status text NOT NULL DEFAULT 'invited' CHECK (status IN ('invited', 'active', 'suspended', 'revoked')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, user_id)
);

-- A revoked account is never reactivated: a new account is created instead (FR-IAM-01).
CREATE FUNCTION school.guard_membership() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF (NEW.tenant_id, NEW.user_id) IS DISTINCT FROM (OLD.tenant_id, OLD.user_id) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF OLD.status = 'revoked' AND NEW.status <> 'revoked' THEN
    RAISE EXCEPTION 'membership_revoked' USING ERRCODE = '23514';
  END IF;
  NEW.updated_at := now();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE UPDATE ON school.membership FOR EACH ROW EXECUTE FUNCTION school.guard_membership();
CREATE TRIGGER no_delete BEFORE DELETE ON school.membership FOR EACH ROW EXECUTE FUNCTION school.immutable();
ALTER TABLE school.membership ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.membership FORCE ROW LEVEL SECURITY;
CREATE POLICY own_membership ON school.membership FOR SELECT USING (user_id = school.actor());

-- The second barrier: a row is visible only in its own school's context, to an active
-- member, and never while the school is suspended.
CREATE FUNCTION school.allowed(t uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT t = school.tenant()
     AND platform.tenant_mode(t) IS DISTINCT FROM 'suspended'
     AND EXISTS (SELECT 1 FROM school.membership m WHERE m.tenant_id = t AND m.user_id = school.actor() AND m.status = 'active') $$;

-- The console sees every school; a school's application sees only its own.
ALTER TABLE platform.tenant ENABLE ROW LEVEL SECURITY;
ALTER TABLE platform.tenant FORCE ROW LEVEL SECURITY;
CREATE POLICY console ON platform.tenant FOR SELECT TO platform_admin USING (true);
CREATE POLICY own_school ON platform.tenant FOR SELECT TO school_runtime USING (school.allowed(id));

CREATE FUNCTION school.module_enabled(p_module text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT school.allowed(school.tenant()) AND platform.module_enabled(school.tenant(), p_module) $$;

-- FR-PLT-02 AC3: an archived or expired school reads and exports, never writes.
-- A trigger rather than a policy, so the writer gets an error instead of zero rows.
CREATE FUNCTION school.guard_writable() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.tenant_id ELSE NEW.tenant_id END;
BEGIN
  IF platform.tenant_mode(t) IS DISTINCT FROM 'active' THEN
    RAISE EXCEPTION 'tenant_read_only' USING ERRCODE = '25006';
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

-- Append-only audit (FR-AUD-01). Entries are written only by the triggers below,
-- never by the application, which has no INSERT privilege on this table.
CREATE TABLE school.audit_log (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  occurred_at timestamptz NOT NULL DEFAULT now(),
  actor_id bigint REFERENCES public.users(id),
  action text NOT NULL,
  object_type text NOT NULL,
  object_id uuid NOT NULL,
  changed_fields jsonb NOT NULL DEFAULT '[]',
  before jsonb,
  after jsonb,
  reason text,
  request_id text,
  ip text,
  linked_operation_id uuid
);
CREATE INDEX audit_log_time ON school.audit_log (tenant_id, occurred_at DESC, id);
CREATE INDEX audit_log_object ON school.audit_log (tenant_id, object_type, object_id);
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.audit_log FOR EACH ROW EXECUTE FUNCTION school.immutable();
ALTER TABLE school.audit_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.audit_log FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_read ON school.audit_log FOR SELECT USING (school.allowed(tenant_id));

-- An update records the old and new value of each changed field. Personal data and bulky
-- content are masked (BR-17): the field name is kept, the value is not. Bookkeeping columns
-- (version, updated_at, updated_by) are left out of the values.
CREATE FUNCTION school.audit() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE row_data jsonb; old_data jsonb; changed jsonb; before_values jsonb; after_values jsonb; masked text[];
BEGIN
  row_data := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
  old_data := CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE to_jsonb(OLD) END;
  SELECT COALESCE(jsonb_agg(key), '[]') INTO changed FROM jsonb_each(row_data)
  WHERE TG_OP <> 'UPDATE' OR value IS DISTINCT FROM old_data -> key;
  masked := ARRAY['first_name', 'last_name', 'birth_date', 'birth_place', 'phone', 'email', 'address', 'matricule',
                  'photo', 'observation', 'body', 'payload', 'rendered', 'rules', 'calculation', 'password'];
  IF TG_OP = 'UPDATE' THEN
    SELECT jsonb_object_agg(key, CASE WHEN key = ANY (masked) THEN to_jsonb('[masqué]'::text) ELSE old_data -> key END),
           jsonb_object_agg(key, CASE WHEN key = ANY (masked) THEN to_jsonb('[masqué]'::text) ELSE value END)
    INTO before_values, after_values
    FROM jsonb_each(row_data)
    WHERE value IS DISTINCT FROM old_data -> key AND key NOT IN ('version', 'updated_at', 'updated_by');
  END IF;
  INSERT INTO school.audit_log (tenant_id, actor_id, action, object_type, object_id, changed_fields, before, after, reason, request_id, ip)
  VALUES ((row_data ->> 'tenant_id')::uuid, school.actor(), TG_OP, TG_TABLE_NAME, (row_data ->> 'id')::uuid, changed,
          before_values, after_values, nullif(current_setting('app.reason', true), ''),
          nullif(current_setting('app.request_id', true), ''), nullif(current_setting('app.ip', true), ''));
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

-- FR-PLT-01: creation, suspension and archiving of a school are logged in its own journal,
-- which the school can read.
CREATE FUNCTION platform.audit_tenant() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  INSERT INTO school.audit_log (tenant_id, actor_id, action, object_type, object_id, changed_fields, before, after, reason, request_id, ip)
  VALUES (NEW.id, school.actor(), TG_OP, 'tenant', NEW.id,
          CASE WHEN TG_OP = 'INSERT' THEN '["name", "status", "default_locale"]'::jsonb
               ELSE (SELECT COALESCE(jsonb_agg(k), '[]') FROM unnest(ARRAY['name', 'status', 'default_locale']) k
                     WHERE to_jsonb(NEW) -> k IS DISTINCT FROM to_jsonb(OLD) -> k) END,
          CASE WHEN TG_OP = 'UPDATE' THEN jsonb_build_object('name', OLD.name, 'status', OLD.status, 'default_locale', OLD.default_locale) END,
          jsonb_build_object('name', NEW.name, 'status', NEW.status, 'default_locale', NEW.default_locale),
          nullif(current_setting('app.reason', true), ''), nullif(current_setting('app.request_id', true), ''),
          nullif(current_setting('app.ip', true), ''));
  RETURN NEW;
END $$;
CREATE TRIGGER audit AFTER INSERT OR UPDATE ON platform.tenant FOR EACH ROW EXECUTE FUNCTION platform.audit_tenant();

-- Every school table gets the same protection: RLS, read-only mode, §7.1 columns, audit.
CREATE PROCEDURE school.protect(tbl regclass) LANGUAGE plpgsql AS $$
BEGIN
  EXECUTE format('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', tbl);
  EXECUTE format('ALTER TABLE %s FORCE ROW LEVEL SECURITY', tbl);
  EXECUTE format('CREATE POLICY tenant ON %s USING (school.allowed(tenant_id)) WITH CHECK (school.allowed(tenant_id))', tbl);
  EXECUTE format('CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION school.guard_writable()', tbl);
  EXECUTE format('CREATE TRIGGER stamp BEFORE INSERT OR UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION school.stamp()', tbl);
  EXECUTE format('CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION school.audit()', tbl);
END $$;

-- ARC-03: a school has one or more sites; the first one is created with the school.
CREATE TABLE school.site (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  code text NOT NULL CHECK (code ~ '^[A-Za-z0-9_-]{1,40}$'),
  labels jsonb NOT NULL CHECK (jsonb_typeof(labels) = 'object' AND labels ? 'fr'),
  is_default boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, code),
  UNIQUE (tenant_id, id)
);
CREATE UNIQUE INDEX site_one_default ON school.site (tenant_id) WHERE is_default;
CALL school.protect('school.site');
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.site FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');

CREATE FUNCTION school.guard_last_site() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.archived_at IS NOT NULL AND OLD.archived_at IS NULL THEN
    PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text, 12));
    IF NOT EXISTS (SELECT 1 FROM school.site WHERE tenant_id = NEW.tenant_id AND id <> NEW.id AND archived_at IS NULL) THEN
      RAISE EXCEPTION 'last_site' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER last_site BEFORE UPDATE ON school.site FOR EACH ROW EXECUTE FUNCTION school.guard_last_site();

-- FR-PLT-01: one call creates the school, its entitlement and plan modules, its default
-- site and, when given, its first administrator's invitation. A repeated call with the
-- same key returns the same school; the same key with other content is refused.
CREATE FUNCTION platform.create_tenant(p_creation_key uuid, p_slug text, p_name text, p_default_locale text, p_plan text,
  p_valid_from date, p_valid_until date, p_quota_active_students integer, p_quota_storage_mb integer,
  p_site_code text, p_site_name text, p_admin_user_id bigint) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid; m text;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended(p_creation_key::text, 10));
  SELECT id INTO t FROM platform.tenant WHERE creation_key = p_creation_key;
  IF t IS NOT NULL THEN
    IF NOT EXISTS (SELECT 1 FROM platform.tenant WHERE id = t AND slug = p_slug AND name = p_name) THEN
      RAISE EXCEPTION 'creation_key_reused' USING ERRCODE = '23514';
    END IF;
    RETURN t;
  END IF;
  INSERT INTO platform.tenant (slug, name, default_locale, creation_key, created_by)
  VALUES (p_slug, p_name, p_default_locale, p_creation_key, school.actor()) RETURNING id INTO t;
  INSERT INTO platform.tenant_entitlement (tenant_id, plan_code, quota_active_students, quota_storage_mb, valid_from, valid_until)
  VALUES (t, p_plan, p_quota_active_students, p_quota_storage_mb, p_valid_from, p_valid_until);
  PERFORM platform.enable_module(t, 'core');
  FOR m IN SELECT module_key FROM platform.plan_module WHERE plan_code = p_plan LOOP
    PERFORM platform.enable_module(t, m);
  END LOOP;
  INSERT INTO school.site (tenant_id, code, labels, is_default) VALUES (t, p_site_code, jsonb_build_object('fr', p_site_name), true);
  IF p_admin_user_id IS NOT NULL THEN
    INSERT INTO school.membership (tenant_id, user_id, status) VALUES (t, p_admin_user_id, 'invited');
  END IF;
  RETURN t;
END $$;

REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA platform, school FROM PUBLIC;
REVOKE ALL ON ALL PROCEDURES IN SCHEMA school FROM PUBLIC;
