-- Authorization (FR-IAM-03, FR-IAM-04, FR-IAM-05): permission catalog (annex A), roles copied
-- per school, assignments with scope and validity dates, effective permissions, segregation of
-- duties. The application tests permissions only, never role codes (principle P4).

-- Annex A. A permission belongs to a module: it is effective only while that module is
-- enabled for the school, and only then assignable (FR-IAM-03, BR-20).
CREATE TABLE platform.permission (
  -- module.resource.action, kept as annex A writes it (`payroll.self_view` has two parts).
  code text PRIMARY KEY CHECK (code ~ '^[a-z_]+(\.[a-z_]+){1,3}$'),
  module_key text NOT NULL REFERENCES platform.module(key),
  sensitive boolean NOT NULL DEFAULT false,
  labels jsonb NOT NULL CHECK (jsonb_typeof(labels) = 'object' AND labels ? 'fr')
);

-- The roles of section 3.2 that live inside a school. `super_admin` is the publisher's
-- and is served by the platform_admin database role. `student` and `parent` roles only
-- ever see their own data (FR-IAM-04).
CREATE TABLE platform.role_template (
  code text PRIMARY KEY CHECK (code ~ '^[a-z][a-z0-9_]{1,39}$'),
  kind text NOT NULL CHECK (kind IN ('internal', 'student', 'parent')),
  labels jsonb NOT NULL CHECK (jsonb_typeof(labels) = 'object' AND labels ? 'fr'),
  -- The role given to the administrator invited when the school is created.
  initial_admin boolean NOT NULL DEFAULT false,
  sort_order smallint NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX role_template_one_initial_admin ON platform.role_template (initial_admin) WHERE initial_admin;

CREATE TABLE platform.role_template_permission (
  role_code text NOT NULL REFERENCES platform.role_template(code),
  permission_code text NOT NULL REFERENCES platform.permission(code),
  PRIMARY KEY (role_code, permission_code)
);

-- FR-IAM-05: who enters an operation never approves it. Checked by each approving table
-- through school.check_segregation(). Payment alone has an exception, set per school.
CREATE TABLE platform.sod_rule (
  entity text PRIMARY KEY,
  entry_action text NOT NULL REFERENCES platform.permission(code),
  approve_action text NOT NULL REFERENCES platform.permission(code),
  exception_setting text
);

CREATE TABLE school.role (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  code text NOT NULL CHECK (code ~ '^[a-z][a-z0-9_]{1,39}$'),
  kind text NOT NULL CHECK (kind IN ('internal', 'student', 'parent')),
  labels jsonb NOT NULL CHECK (jsonb_typeof(labels) = 'object' AND labels ? 'fr'),
  template_code text REFERENCES platform.role_template(code),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, code),
  UNIQUE (tenant_id, id)
);
CALL school.protect('school.role');
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.role FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');
CREATE TRIGGER kind_frozen BEFORE UPDATE ON school.role FOR EACH ROW EXECUTE FUNCTION school.freeze_code('kind');

CREATE TABLE school.role_permission (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  role_id uuid NOT NULL,
  permission_code text NOT NULL REFERENCES platform.permission(code),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, role_id, permission_code),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, role_id) REFERENCES school.role (tenant_id, id)
);
CALL school.protect('school.role_permission');
CREATE TRIGGER immutable BEFORE UPDATE ON school.role_permission FOR EACH ROW EXECUTE FUNCTION school.immutable();

-- FR-IAM-04: a role applies to the whole school, to some sites, levels, groups or subjects,
-- or only to the person's own data (`personal`). BR-10: dates of validity.
CREATE TABLE school.role_assignment (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  user_id bigint NOT NULL,
  role_id uuid NOT NULL,
  scope_type text NOT NULL DEFAULT 'tenant' CHECK (scope_type IN ('tenant', 'site', 'level', 'group', 'subject', 'personal')),
  scope_ids uuid[],
  valid_from date NOT NULL DEFAULT current_date,
  valid_until date,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK ((scope_type IN ('tenant', 'personal')) = (scope_ids IS NULL)),
  CHECK (scope_ids IS NULL OR cardinality(scope_ids) BETWEEN 1 AND 100),
  CHECK (valid_until IS NULL OR valid_until >= valid_from),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, role_id) REFERENCES school.role (tenant_id, id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES school.membership (tenant_id, user_id)
);
CREATE INDEX role_assignment_user ON school.role_assignment (tenant_id, user_id) WHERE archived_at IS NULL;
CALL school.protect('school.role_assignment');

-- Provisioning (the definer functions) and the database owner act as the system.
CREATE FUNCTION school.is_system() RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT current_user = 'platform_provisioner' OR EXISTS (SELECT 1 FROM pg_roles WHERE rolname = current_user AND rolsuper) $$;

-- Union of the user's roles today (FR-IAM-03 AC2), active membership only, permissions of
-- enabled modules only, with the scope of each assignment.
CREATE FUNCTION school.effective_permissions(p_user bigint, p_tenant uuid)
RETURNS TABLE (permission text, scope_type text, scope_ids uuid[])
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT rp.permission_code, a.scope_type, a.scope_ids
  FROM school.role_assignment a
  JOIN school.membership m ON m.tenant_id = a.tenant_id AND m.user_id = a.user_id AND m.status = 'active'
  JOIN school.role r ON r.tenant_id = a.tenant_id AND r.id = a.role_id AND r.archived_at IS NULL
  JOIN school.role_permission rp ON rp.tenant_id = a.tenant_id AND rp.role_id = a.role_id
  JOIN platform.permission p ON p.code = rp.permission_code
  JOIN platform.tenant_module tm ON tm.tenant_id = a.tenant_id AND tm.module_key = p.module_key
  WHERE a.tenant_id = p_tenant AND a.user_id = p_user AND a.archived_at IS NULL
    AND a.valid_from <= current_date AND (a.valid_until IS NULL OR a.valid_until >= current_date) $$;

-- What the signed-in user may do in the current school, for the application's checks.
CREATE FUNCTION school.my_permissions() RETURNS TABLE (permission text, sensitive boolean, scope_type text, scope_ids uuid[])
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT e.permission, p.sensitive, e.scope_type, e.scope_ids
  FROM school.effective_permissions(school.actor(), school.tenant()) e JOIN platform.permission p ON p.code = e.permission
  WHERE school.allowed(school.tenant())
  ORDER BY e.permission, e.scope_type $$;

CREATE FUNCTION school.can(p_permission text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT school.allowed(school.tenant()) AND EXISTS (
    SELECT 1 FROM school.effective_permissions(school.actor(), school.tenant()) e WHERE e.permission = p_permission) $$;

CREATE FUNCTION school.can_everywhere(p_permission text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT school.allowed(school.tenant()) AND EXISTS (
    SELECT 1 FROM school.effective_permissions(school.actor(), school.tenant()) e
    WHERE e.permission = p_permission AND e.scope_type = 'tenant') $$;

-- Active members able to manage role assignments for the whole school.
CREATE FUNCTION school.manager_count(p_tenant uuid) RETURNS integer
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT count(DISTINCT m.user_id)::integer FROM school.membership m
  WHERE m.tenant_id = p_tenant AND m.status = 'active' AND EXISTS (
    SELECT 1 FROM school.effective_permissions(m.user_id, p_tenant) e
    WHERE e.permission = 'role.assignment.manage' AND e.scope_type = 'tenant') $$;

CREATE FUNCTION school.role_manages(p_tenant uuid, p_role uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM school.role_permission WHERE tenant_id = p_tenant AND role_id = p_role
                 AND permission_code = 'role.assignment.manage') $$;

-- Writes to roles and assignments: the right permission, never on oneself (FR-IAM-05).
CREATE FUNCTION school.guard_role() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT school.is_system() AND NOT school.can_everywhere('role.definition.manage') THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF TG_OP = 'UPDATE' AND NEW.archived_at IS NOT NULL AND OLD.archived_at IS NULL AND EXISTS (
       SELECT 1 FROM school.role_assignment a WHERE a.tenant_id = NEW.tenant_id AND a.role_id = NEW.id AND a.archived_at IS NULL
       AND (a.valid_until IS NULL OR a.valid_until >= current_date)) THEN
    RAISE EXCEPTION 'role_in_use' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard_role BEFORE INSERT OR UPDATE ON school.role FOR EACH ROW EXECUTE FUNCTION school.guard_role();

CREATE FUNCTION school.guard_role_permission() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE row_role uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.role_id ELSE NEW.role_id END;
        row_tenant uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.tenant_id ELSE NEW.tenant_id END;
BEGIN
  IF school.is_system() THEN RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; END IF;
  IF NOT school.can_everywhere('role.definition.manage') THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  -- Changing a role one holds would be raising oneself.
  IF EXISTS (SELECT 1 FROM school.role_assignment a WHERE a.tenant_id = row_tenant AND a.role_id = row_role
             AND a.user_id = school.actor() AND a.archived_at IS NULL) THEN
    RAISE EXCEPTION 'self_elevation' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'INSERT' AND NOT school.module_enabled((SELECT module_key FROM platform.permission WHERE code = NEW.permission_code)) THEN
    RAISE EXCEPTION 'permission_module_disabled' USING ERRCODE = '23514';
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER guard_role_permission BEFORE INSERT OR DELETE ON school.role_permission FOR EACH ROW EXECUTE FUNCTION school.guard_role_permission();

CREATE FUNCTION school.guard_role_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE role_kind text; role_archived timestamptz;
BEGIN
  IF NOT school.is_system() THEN
    IF NOT school.can_everywhere('role.assignment.manage') THEN
      RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
    END IF;
    IF NEW.user_id = school.actor() THEN RAISE EXCEPTION 'self_assignment' USING ERRCODE = '23514'; END IF;
  END IF;
  IF TG_OP = 'UPDATE' THEN
    IF (NEW.user_id, NEW.role_id, NEW.valid_from) IS DISTINCT FROM (OLD.user_id, OLD.role_id, OLD.valid_from) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  END IF;
  SELECT kind, archived_at INTO role_kind, role_archived FROM school.role WHERE tenant_id = NEW.tenant_id AND id = NEW.role_id;
  IF TG_OP = 'INSERT' AND role_archived IS NOT NULL THEN RAISE EXCEPTION 'role_archived' USING ERRCODE = '23514'; END IF;
  IF role_kind IN ('student', 'parent') AND NEW.scope_type <> 'personal' THEN
    RAISE EXCEPTION 'scope_personal_required' USING ERRCODE = '23514';
  END IF;
  IF NEW.scope_type = 'site' AND EXISTS (SELECT 1 FROM unnest(NEW.scope_ids) s
       WHERE NOT EXISTS (SELECT 1 FROM school.site WHERE tenant_id = NEW.tenant_id AND id = s)) THEN
    RAISE EXCEPTION 'scope_unknown' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard_role_assignment BEFORE INSERT OR UPDATE ON school.role_assignment FOR EACH ROW EXECUTE FUNCTION school.guard_role_assignment();

-- FR-IAM-01: a school that has someone able to manage roles never loses its last one through
-- an ordinary change (assignment ended, permission removed, role archived, membership suspended).
-- Runs as the provisioner: it counts across the whole school, whoever makes the change.
CREATE FUNCTION school.guard_last_manager() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.tenant_id ELSE NEW.tenant_id END;
        removes boolean := false;
BEGIN
  IF TG_TABLE_NAME = 'role_assignment' THEN
    removes := OLD.archived_at IS NULL AND OLD.scope_type = 'tenant'
      AND (NEW.archived_at IS NOT NULL OR NEW.valid_until IS DISTINCT FROM OLD.valid_until OR NEW.scope_type <> 'tenant')
      AND school.role_manages(t, OLD.role_id);
  ELSIF TG_TABLE_NAME = 'role_permission' THEN
    removes := OLD.permission_code = 'role.assignment.manage';
  ELSIF TG_TABLE_NAME = 'role' THEN
    removes := NEW.archived_at IS NOT NULL AND OLD.archived_at IS NULL AND school.role_manages(t, NEW.id);
  ELSIF TG_TABLE_NAME = 'membership' THEN
    removes := OLD.status = 'active' AND NEW.status <> 'active';
  END IF;
  IF removes THEN
    PERFORM pg_advisory_xact_lock(hashtextextended(t::text, 13));
    IF school.manager_count(t) = 0 AND school.manager_count_before(t, TG_TABLE_NAME, to_jsonb(OLD)) > 0 THEN
      RAISE EXCEPTION 'last_admin' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NULL;
END $$;

-- Whether the school had a manager before this change: the change's own row, as it was, counts.
CREATE FUNCTION school.manager_count_before(p_tenant uuid, p_table text, p_old jsonb) RETURNS integer
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT CASE
    WHEN p_table = 'membership' THEN
      (SELECT count(*)::integer FROM school.role_assignment a
       JOIN school.role r ON r.tenant_id = a.tenant_id AND r.id = a.role_id AND r.archived_at IS NULL
       WHERE a.tenant_id = p_tenant AND a.user_id = (p_old ->> 'user_id')::bigint AND a.archived_at IS NULL AND a.scope_type = 'tenant'
         AND a.valid_from <= current_date AND (a.valid_until IS NULL OR a.valid_until >= current_date)
         AND school.role_manages(p_tenant, a.role_id))
    WHEN p_table = 'role_assignment' THEN
      (SELECT count(*)::integer FROM school.membership m
       WHERE m.tenant_id = p_tenant AND m.user_id = (p_old ->> 'user_id')::bigint AND m.status = 'active'
         AND (p_old ->> 'valid_from')::date <= current_date
         AND ((p_old ->> 'valid_until') IS NULL OR (p_old ->> 'valid_until')::date >= current_date))
    WHEN p_table = 'role_permission' THEN
      (SELECT count(*)::integer FROM school.role_assignment a
       JOIN school.membership m ON m.tenant_id = a.tenant_id AND m.user_id = a.user_id AND m.status = 'active'
       WHERE a.tenant_id = p_tenant AND a.role_id = (p_old ->> 'role_id')::uuid AND a.archived_at IS NULL AND a.scope_type = 'tenant'
         AND a.valid_from <= current_date AND (a.valid_until IS NULL OR a.valid_until >= current_date))
    WHEN p_table = 'role' THEN
      (SELECT count(*)::integer FROM school.role_assignment a
       JOIN school.membership m ON m.tenant_id = a.tenant_id AND m.user_id = a.user_id AND m.status = 'active'
       WHERE a.tenant_id = p_tenant AND a.role_id = (p_old ->> 'id')::uuid AND a.archived_at IS NULL AND a.scope_type = 'tenant'
         AND a.valid_from <= current_date AND (a.valid_until IS NULL OR a.valid_until >= current_date))
    ELSE 0 END $$;

-- Whoever manages accounts or assignments sees the school's members, not only their own
-- membership. Through definer functions, so the policy never reads the table it guards.
CREATE POLICY school_members ON school.membership FOR SELECT TO school_runtime USING (
  tenant_id = school.tenant() AND ((SELECT school.can('user.account.read')) OR (SELECT school.can('role.assignment.manage'))));

CREATE TRIGGER last_manager AFTER UPDATE ON school.role_assignment FOR EACH ROW EXECUTE FUNCTION school.guard_last_manager();
CREATE TRIGGER last_manager AFTER DELETE ON school.role_permission FOR EACH ROW EXECUTE FUNCTION school.guard_last_manager();
CREATE TRIGGER last_manager AFTER UPDATE ON school.role FOR EACH ROW EXECUTE FUNCTION school.guard_last_manager();
CREATE TRIGGER last_manager AFTER UPDATE ON school.membership FOR EACH ROW EXECUTE FUNCTION school.guard_last_manager();

-- FR-IAM-05: called by each approving table. Raises when the approver entered the operation,
-- except where the rule has a school setting allowing it (payment); then returns true, which
-- the table stores as `self_validated`.
CREATE FUNCTION school.check_segregation(p_entity text, p_author bigint, p_allow_self boolean DEFAULT false) RETURNS boolean
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE rule platform.sod_rule;
BEGIN
  SELECT * INTO rule FROM platform.sod_rule WHERE entity = p_entity;
  IF NOT FOUND THEN RAISE EXCEPTION 'unknown_sod_entity' USING ERRCODE = '23514'; END IF;
  IF p_author IS DISTINCT FROM school.actor() THEN RETURN false; END IF;
  IF rule.exception_setting IS NOT NULL AND p_allow_self THEN RETURN true; END IF;
  RAISE EXCEPTION 'segregation_of_duties' USING ERRCODE = '23514',
    HINT = format('%s: the person who entered it (%s) cannot approve it (%s).', p_entity, rule.entry_action, rule.approve_action);
END $$;
