-- Publisher level (ARC-03, FR-PLT-01, FR-PLT-02): module catalog (annex C), plans,
-- schools and their entitlements. Writes go through the SECURITY DEFINER functions below.

CREATE TABLE platform.module (
  key text PRIMARY KEY CHECK (key ~ '^[a-z][a-z_]{1,39}$'),
  labels jsonb NOT NULL CHECK (jsonb_typeof(labels) = 'object' AND labels ? 'fr'),
  sort_order smallint NOT NULL DEFAULT 0
);

-- Mandatory dependencies only; optional ones (annex C) never force an activation.
CREATE TABLE platform.module_dependency (
  module_key text NOT NULL REFERENCES platform.module(key),
  requires_key text NOT NULL REFERENCES platform.module(key),
  PRIMARY KEY (module_key, requires_key),
  CHECK (module_key <> requires_key)
);

CREATE TABLE platform.plan (
  code text PRIMARY KEY CHECK (code ~ '^[a-z][a-z_]{1,39}$'),
  labels jsonb NOT NULL CHECK (jsonb_typeof(labels) = 'object' AND labels ? 'fr')
);

CREATE TABLE platform.plan_module (
  plan_code text NOT NULL REFERENCES platform.plan(code),
  module_key text NOT NULL REFERENCES platform.module(key),
  PRIMARY KEY (plan_code, module_key)
);

CREATE TABLE platform.tenant (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  slug text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9-]{3,40}$'),
  name text NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 150),
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'archived')),
  default_locale text NOT NULL DEFAULT 'fr' CHECK (default_locale IN ('fr', 'ar', 'en')),
  -- FR-PLT-01 AC3: a repeated creation request returns the same school.
  creation_key uuid NOT NULL UNIQUE,
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE platform.tenant_entitlement (
  tenant_id uuid PRIMARY KEY REFERENCES platform.tenant(id),
  plan_code text NOT NULL REFERENCES platform.plan(code),
  quota_active_students integer NOT NULL CHECK (quota_active_students >= 0),
  quota_storage_mb integer NOT NULL CHECK (quota_storage_mb >= 0),
  quota_ai_requests_month integer NOT NULL DEFAULT 0 CHECK (quota_ai_requests_month >= 0),
  valid_from date NOT NULL,
  valid_until date,
  grace_days integer NOT NULL DEFAULT 15 CHECK (grace_days BETWEEN 0 AND 365),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (valid_until IS NULL OR valid_until >= valid_from)
);

CREATE TABLE platform.tenant_module (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  module_key text NOT NULL REFERENCES platform.module(key),
  enabled_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, module_key)
);

-- The slug and creation key are permanent; an archived school stays archived;
-- a school is never deleted.
CREATE FUNCTION platform.guard_tenant() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF (NEW.id, NEW.slug, NEW.creation_key) IS DISTINCT FROM (OLD.id, OLD.slug, OLD.creation_key) THEN
    RAISE EXCEPTION 'code_immutable' USING ERRCODE = '23514';
  END IF;
  IF OLD.status = 'archived' AND NEW.status <> 'archived' THEN
    RAISE EXCEPTION 'tenant_archived' USING ERRCODE = '23514';
  END IF;
  NEW.updated_at := now();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE UPDATE ON platform.tenant FOR EACH ROW EXECUTE FUNCTION platform.guard_tenant();
CREATE TRIGGER no_delete BEFORE DELETE ON platform.tenant FOR EACH ROW EXECUTE FUNCTION school.immutable();

-- ARC-04 / FR-PLT-02 AC1: a module is enabled only with its dependencies and cannot be
-- disabled while an enabled module depends on it. `core` is always enabled.
CREATE FUNCTION platform.guard_tenant_module() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t uuid := CASE WHEN TG_OP = 'INSERT' THEN NEW.tenant_id ELSE OLD.tenant_id END;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended(t::text, 11));
  IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'INSERT' THEN
    IF EXISTS (SELECT 1 FROM platform.module_dependency d WHERE d.module_key = NEW.module_key
               AND NOT EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = t AND m.module_key = d.requires_key)) THEN
      RAISE EXCEPTION 'module_dependency_missing' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF OLD.module_key = 'core' OR EXISTS (
       SELECT 1 FROM platform.module_dependency d JOIN platform.tenant_module m ON m.module_key = d.module_key
       WHERE m.tenant_id = t AND d.requires_key = OLD.module_key) THEN
    RAISE EXCEPTION 'module_required' USING ERRCODE = '23514';
  END IF;
  RETURN OLD;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON platform.tenant_module FOR EACH ROW EXECUTE FUNCTION platform.guard_tenant_module();

-- A school's mode now: `suspended` (nothing accessible, FR-PLT-01), `read_only` (archived,
-- or expired beyond its grace period, FR-PLT-02 AC3: it can still read and export) or `active`.
CREATE FUNCTION platform.tenant_mode(t uuid) RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT CASE
    WHEN tn.status = 'suspended' THEN 'suspended'
    WHEN tn.status = 'archived' OR e.tenant_id IS NULL THEN 'read_only'
    WHEN e.valid_until IS NOT NULL AND current_date > e.valid_until + e.grace_days THEN 'read_only'
    ELSE 'active' END
  FROM platform.tenant tn LEFT JOIN platform.tenant_entitlement e ON e.tenant_id = tn.id
  WHERE tn.id = t $$;

CREATE FUNCTION platform.module_enabled(p_tenant uuid, p_module text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT platform.tenant_mode(p_tenant) IS DISTINCT FROM 'suspended'
     AND EXISTS (SELECT 1 FROM platform.tenant_module WHERE tenant_id = p_tenant AND module_key = p_module) $$;

-- Enables a module and, first, every module it depends on (deepest dependency first).
CREATE FUNCTION platform.enable_module(p_tenant uuid, p_module text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM platform.module m WHERE m.key = p_module) THEN
    RAISE EXCEPTION 'unknown_module' USING ERRCODE = '23514';
  END IF;
  INSERT INTO platform.tenant_module (tenant_id, module_key)
  SELECT p_tenant, needed.key FROM (
    WITH RECURSIVE chain (key, depth) AS (
      SELECT p_module, 0
      UNION ALL
      SELECT d.requires_key, c.depth + 1 FROM platform.module_dependency d JOIN chain c ON d.module_key = c.key WHERE c.depth < 20
    ) SELECT key, max(depth) AS depth FROM chain GROUP BY key
  ) needed
  WHERE NOT EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = p_tenant AND m.module_key = needed.key)
  ORDER BY needed.depth DESC;
END $$;

-- Disabling keeps the module's data; it becomes visible again on re-activation (BR-20).
CREATE FUNCTION platform.disable_module(p_tenant uuid, p_module text) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  DELETE FROM platform.tenant_module WHERE tenant_id = p_tenant AND module_key = p_module $$;

CREATE FUNCTION platform.set_tenant_status(t uuid, new_status text, reason text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF reason IS NULL OR length(btrim(reason)) < 3 THEN
    RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514';
  END IF;
  PERFORM set_config('app.reason', reason, true);
  UPDATE platform.tenant SET status = new_status WHERE id = t;
  IF NOT FOUND THEN RAISE EXCEPTION 'unknown_tenant' USING ERRCODE = '23514'; END IF;
END $$;

CREATE FUNCTION platform.set_entitlement(t uuid, p_valid_from date, p_valid_until date, p_grace_days integer,
  p_quota_active_students integer, p_quota_storage_mb integer, p_quota_ai_requests_month integer) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  UPDATE platform.tenant_entitlement SET valid_from = p_valid_from, valid_until = p_valid_until, grace_days = p_grace_days,
    quota_active_students = p_quota_active_students, quota_storage_mb = p_quota_storage_mb,
    quota_ai_requests_month = p_quota_ai_requests_month, updated_at = now()
  WHERE tenant_id = t;
  IF NOT FOUND THEN RAISE EXCEPTION 'unknown_tenant' USING ERRCODE = '23514'; END IF;
END $$;

-- Annex C: modules, mandatory dependencies and the three commercial packs (OD-02).
-- `custom` starts with `core` only; the console then enables modules one by one.
INSERT INTO platform.module (key, labels, sort_order) VALUES
  ('core', '{"fr": "Noyau"}', 1),
  ('sites_rooms', '{"fr": "Sites et salles"}', 2),
  ('students_crm', '{"fr": "Élèves et CRM"}', 3),
  ('enrollment_groups', '{"fr": "Programmes, groupes, inscriptions"}', 4),
  ('staff', '{"fr": "Personnel, habilitations, contrats"}', 5),
  ('scheduling_manual', '{"fr": "Séances et planning manuel"}', 6),
  ('scheduling_optimization', '{"fr": "Optimisation d''emploi du temps"}', 7),
  ('attendance', '{"fr": "Pointage enseignants et présences élèves"}', 8),
  ('student_finance', '{"fr": "Finance des élèves"}', 9),
  ('expenses', '{"fr": "Dépenses"}', 10),
  ('payroll', '{"fr": "Rémunérations"}', 11),
  ('lms', '{"fr": "Pédagogie"}', 12),
  ('ai_assistant', '{"fr": "Assistant IA"}', 13),
  ('stock', '{"fr": "Stock"}', 14),
  ('parent_portal', '{"fr": "Portail parents"}', 15),
  ('dashboards', '{"fr": "Tableaux de bord"}', 16);

INSERT INTO platform.module_dependency (module_key, requires_key) VALUES
  ('sites_rooms', 'core'), ('students_crm', 'core'), ('enrollment_groups', 'students_crm'),
  ('staff', 'core'), ('scheduling_manual', 'enrollment_groups'), ('scheduling_manual', 'staff'),
  ('scheduling_optimization', 'scheduling_manual'), ('attendance', 'scheduling_manual'),
  ('student_finance', 'enrollment_groups'), ('expenses', 'core'), ('payroll', 'staff'), ('payroll', 'expenses'),
  ('lms', 'enrollment_groups'), ('ai_assistant', 'lms'), ('stock', 'core'),
  ('parent_portal', 'students_crm'), ('dashboards', 'core');

INSERT INTO platform.plan (code, labels) VALUES
  ('essential', '{"fr": "Essentiel"}'), ('pro', '{"fr": "Pro"}'),
  ('complete', '{"fr": "Complet"}'), ('custom', '{"fr": "Personnalisé"}');

INSERT INTO platform.plan_module (plan_code, module_key)
SELECT 'essential', m FROM unnest(ARRAY['core', 'students_crm', 'enrollment_groups', 'sites_rooms', 'student_finance']) m
UNION ALL SELECT 'pro', m FROM unnest(ARRAY['core', 'students_crm', 'enrollment_groups', 'sites_rooms', 'student_finance',
  'staff', 'expenses', 'scheduling_manual', 'attendance', 'dashboards']) m
UNION ALL SELECT 'complete', m FROM unnest(ARRAY['core', 'students_crm', 'enrollment_groups', 'sites_rooms', 'student_finance',
  'staff', 'expenses', 'scheduling_manual', 'attendance', 'dashboards',
  'payroll', 'lms', 'scheduling_optimization', 'stock', 'parent_portal']) m
UNION ALL SELECT 'custom', 'core';
