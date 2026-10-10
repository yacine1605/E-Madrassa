-- Referential (FR-REF-01 to 05, FR-PLT-03): the school's tree of levels or specialties, its
-- subject catalog, school years and periods, custom fields. Every "level", "track",
-- "specialty" or "subject" elsewhere references these tables, never free text (BR-13).

CREATE EXTENSION IF NOT EXISTS btree_gist;

-- Uniqueness of labels ignores case, spaces and Arabic diacritics (FR-REF-02).
CREATE FUNCTION school.label_key(p text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT nullif(lower(regexp_replace(btrim(regexp_replace(p, '[ً-ٰٟـ]', '', 'g')), '\s+', ' ', 'g')), '') $$;

-- Labels in fr, ar, en, at least fr or ar, each a non-empty text.
CREATE FUNCTION school.valid_labels(p jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
  SELECT jsonb_typeof(p) = 'object' AND (p ? 'fr' OR p ? 'ar')
     AND NOT EXISTS (SELECT 1 FROM jsonb_each(p) e WHERE e.key NOT IN ('fr', 'ar', 'en')
                     OR jsonb_typeof(e.value) <> 'string' OR school.label_key(e.value #>> '{}') IS NULL) $$;

-- Profiles (FR-PLT-03): the publisher's models. A published version never changes; a school
-- receives a copy, which is then its own.
CREATE TABLE platform.profile (
  code text NOT NULL CHECK (code ~ '^[a-z][a-z0-9_]{1,39}$'),
  version integer NOT NULL CHECK (version >= 1),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  subject_kind text NOT NULL CHECK (subject_kind IN ('subject', 'module')),
  published_at timestamptz,
  PRIMARY KEY (code, version)
);

CREATE TABLE platform.profile_node_type (
  profile_code text NOT NULL,
  version integer NOT NULL,
  code text NOT NULL CHECK (code ~ '^[a-z][a-z0-9_]{1,39}$'),
  parent_code text,
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  level_order smallint NOT NULL,
  is_required boolean NOT NULL,
  PRIMARY KEY (profile_code, version, code),
  FOREIGN KEY (profile_code, version) REFERENCES platform.profile (code, version),
  FOREIGN KEY (profile_code, version, parent_code) REFERENCES platform.profile_node_type (profile_code, version, code)
);

CREATE TABLE platform.profile_node (
  profile_code text NOT NULL,
  version integer NOT NULL,
  type_code text NOT NULL,
  code text NOT NULL CHECK (code ~ '^[A-Z0-9_]{1,20}$'),
  parent_code text,
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  sort_order integer NOT NULL DEFAULT 0,
  platform_code text,
  PRIMARY KEY (profile_code, version, type_code, code),
  FOREIGN KEY (profile_code, version, type_code) REFERENCES platform.profile_node_type (profile_code, version, code)
);

CREATE TABLE platform.profile_subject (
  profile_code text NOT NULL,
  version integer NOT NULL,
  code text NOT NULL CHECK (code ~ '^[A-Z0-9_]{1,20}$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  sort_order integer NOT NULL DEFAULT 0,
  PRIMARY KEY (profile_code, version, code),
  FOREIGN KEY (profile_code, version) REFERENCES platform.profile (code, version)
);

CREATE FUNCTION platform.guard_profile() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; published timestamptz;
BEGIN
  IF TG_TABLE_NAME = 'profile' THEN
    IF TG_OP <> 'INSERT' AND OLD.published_at IS NOT NULL THEN RAISE EXCEPTION 'profile_published' USING ERRCODE = '23514'; END IF;
  ELSE
    SELECT p.published_at INTO published FROM platform.profile p WHERE p.code = r.profile_code AND p.version = r.version;
    IF published IS NOT NULL THEN RAISE EXCEPTION 'profile_published' USING ERRCODE = '23514'; END IF;
  END IF;
  RETURN r;
END $$;
CREATE TRIGGER guard BEFORE UPDATE OR DELETE ON platform.profile FOR EACH ROW EXECUTE FUNCTION platform.guard_profile();
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON platform.profile_node_type FOR EACH ROW EXECUTE FUNCTION platform.guard_profile();
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON platform.profile_node FOR EACH ROW EXECUTE FUNCTION platform.guard_profile();
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON platform.profile_subject FOR EACH ROW EXECUTE FUNCTION platform.guard_profile();

ALTER TABLE platform.tenant ADD COLUMN profile_code text, ADD COLUMN profile_version integer,
  ADD FOREIGN KEY (profile_code, profile_version) REFERENCES platform.profile (code, version);

-- FR-REF-01: the school's node types, copied from its profile. The school renames them and
-- turns optional ones off; it never creates one (BR-13).
CREATE TABLE school.node_type (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  code text NOT NULL CHECK (code ~ '^[a-z][a-z0-9_]{1,39}$'),
  parent_code text,
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  level_order smallint NOT NULL,
  is_required boolean NOT NULL,
  active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, code),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, parent_code) REFERENCES school.node_type (tenant_id, code)
);
CALL school.protect('school.node_type');

-- FR-REF-02: the nodes. Code permanent and unique per type; labels unique among the active
-- siblings once normalized; parent of the type's parent type; archived, never deleted.
CREATE TABLE school.ref_node (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  type_code text NOT NULL,
  parent_id uuid,
  code text NOT NULL CHECK (code ~ '^[A-Z0-9_]{1,20}$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  sort_order integer NOT NULL DEFAULT 0,
  platform_code text,
  label_fr_key text GENERATED ALWAYS AS (school.label_key(labels ->> 'fr')) STORED,
  label_ar_key text GENERATED ALWAYS AS (school.label_key(labels ->> 'ar')) STORED,
  label_en_key text GENERATED ALWAYS AS (school.label_key(labels ->> 'en')) STORED,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, type_code, code),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, type_code) REFERENCES school.node_type (tenant_id, code),
  FOREIGN KEY (tenant_id, parent_id) REFERENCES school.ref_node (tenant_id, id)
);
CREATE INDEX ref_node_parent ON school.ref_node (tenant_id, parent_id);
CREATE UNIQUE INDEX ref_node_label_fr ON school.ref_node (tenant_id, type_code, coalesce(parent_id, '00000000-0000-0000-0000-000000000000'), label_fr_key) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX ref_node_label_ar ON school.ref_node (tenant_id, type_code, coalesce(parent_id, '00000000-0000-0000-0000-000000000000'), label_ar_key) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX ref_node_label_en ON school.ref_node (tenant_id, type_code, coalesce(parent_id, '00000000-0000-0000-0000-000000000000'), label_en_key) WHERE archived_at IS NULL;
CALL school.protect('school.ref_node');
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.ref_node FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');

CREATE FUNCTION school.guard_node_type() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF (NEW.code, NEW.parent_code, NEW.level_order, NEW.is_required) IS DISTINCT FROM (OLD.code, OLD.parent_code, OLD.level_order, OLD.is_required) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF OLD.active AND NOT NEW.active THEN
    IF NEW.is_required THEN RAISE EXCEPTION 'type_required' USING ERRCODE = '23514'; END IF;
    IF EXISTS (SELECT 1 FROM school.ref_node WHERE tenant_id = NEW.tenant_id AND type_code = NEW.code AND archived_at IS NULL) THEN
      RAISE EXCEPTION 'type_in_use' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard_node_type BEFORE UPDATE ON school.node_type FOR EACH ROW EXECUTE FUNCTION school.guard_node_type();

CREATE FUNCTION school.guard_ref_node() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE kind school.node_type; parent school.ref_node;
BEGIN
  IF TG_OP = 'UPDATE' THEN
    IF (NEW.type_code, NEW.parent_id) IS DISTINCT FROM (OLD.type_code, OLD.parent_id) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    -- A parent stays in use while it has active children.
    IF NEW.archived_at IS NOT NULL AND OLD.archived_at IS NULL AND EXISTS (
         SELECT 1 FROM school.ref_node WHERE tenant_id = NEW.tenant_id AND parent_id = NEW.id AND archived_at IS NULL) THEN
      RAISE EXCEPTION 'node_has_children' USING ERRCODE = '23514';
    END IF;
    IF NEW.archived_at IS NULL AND OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
    RETURN NEW;
  END IF;
  SELECT * INTO kind FROM school.node_type WHERE tenant_id = NEW.tenant_id AND code = NEW.type_code;
  IF NOT kind.active THEN RAISE EXCEPTION 'type_inactive' USING ERRCODE = '23514'; END IF;
  IF kind.parent_code IS NULL THEN
    IF NEW.parent_id IS NOT NULL THEN RAISE EXCEPTION 'parent_invalid' USING ERRCODE = '23514'; END IF;
  ELSE
    SELECT * INTO parent FROM school.ref_node WHERE tenant_id = NEW.tenant_id AND id = NEW.parent_id;
    IF NOT FOUND OR parent.type_code <> kind.parent_code OR parent.archived_at IS NOT NULL THEN
      RAISE EXCEPTION 'parent_invalid' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard_ref_node BEFORE INSERT OR UPDATE ON school.ref_node FOR EACH ROW EXECUTE FUNCTION school.guard_ref_node();

-- The node and every node below it: a role scoped to a cycle covers its grades (FR-IAM-04).
CREATE FUNCTION school.node_subtree(p_nodes uuid[]) RETURNS TABLE (id uuid) LANGUAGE sql STABLE AS $$
  WITH RECURSIVE tree AS (
    SELECT n.id FROM school.ref_node n WHERE n.tenant_id = school.tenant() AND n.id = ANY (p_nodes)
    UNION SELECT c.id FROM school.ref_node c JOIN tree ON c.parent_id = tree.id WHERE c.tenant_id = school.tenant())
  SELECT id FROM tree $$;

-- FR-REF-03: the subject (school) or module (training centre) catalog. Deleted only while
-- nothing references it (the references of later tables refuse it), archived otherwise.
CREATE TABLE school.subject (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  code text NOT NULL CHECK (code ~ '^[A-Z0-9_]{1,20}$'),
  kind text NOT NULL CHECK (kind IN ('subject', 'module')),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  description text CHECK (length(description) <= 2000),
  sort_order integer NOT NULL DEFAULT 0,
  label_fr_key text GENERATED ALWAYS AS (school.label_key(labels ->> 'fr')) STORED,
  label_ar_key text GENERATED ALWAYS AS (school.label_key(labels ->> 'ar')) STORED,
  label_en_key text GENERATED ALWAYS AS (school.label_key(labels ->> 'en')) STORED,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, code),
  UNIQUE (tenant_id, id)
);
CREATE UNIQUE INDEX subject_label_fr ON school.subject (tenant_id, label_fr_key) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX subject_label_ar ON school.subject (tenant_id, label_ar_key) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX subject_label_en ON school.subject (tenant_id, label_en_key) WHERE archived_at IS NULL;
CALL school.protect('school.subject');
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.subject FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');
CREATE TRIGGER kind_frozen BEFORE UPDATE ON school.subject FOR EACH ROW EXECUTE FUNCTION school.freeze_code('kind');

-- A subject named by a role's scope is in use, like one referenced by a later table's key.
CREATE FUNCTION school.guard_subject_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM school.role_assignment WHERE tenant_id = OLD.tenant_id AND scope_type = 'subject' AND OLD.id = ANY (scope_ids)) THEN
    RAISE EXCEPTION 'subject_in_use' USING ERRCODE = '23514';
  END IF;
  RETURN OLD;
END $$;
CREATE TRIGGER guard_delete BEFORE DELETE ON school.subject FOR EACH ROW EXECUTE FUNCTION school.guard_subject_delete();

-- FR-REF-05: school years, one current at most; a closed year is final.
CREATE TABLE school.academic_year (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  code text NOT NULL CHECK (code ~ '^[A-Za-z0-9_-]{1,20}$'),
  start_date date NOT NULL,
  end_date date NOT NULL,
  status text NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'current', 'closed')),
  closed_at timestamptz,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (end_date > start_date),
  CHECK ((status = 'closed') = (closed_at IS NOT NULL)),
  UNIQUE (tenant_id, code),
  UNIQUE (tenant_id, id)
);
CREATE UNIQUE INDEX academic_year_one_current ON school.academic_year (tenant_id) WHERE status = 'current';
CALL school.protect('school.academic_year');
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.academic_year FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');

-- Periods (terms, semesters, modules) inside their year, never overlapping one another.
CREATE TABLE school.period (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  year_id uuid NOT NULL,
  code text NOT NULL CHECK (code ~ '^[A-Z0-9_]{1,20}$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  start_date date NOT NULL,
  end_date date NOT NULL,
  sort_order integer NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (end_date >= start_date),
  UNIQUE (tenant_id, year_id, code),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, year_id) REFERENCES school.academic_year (tenant_id, id),
  CONSTRAINT period_no_overlap EXCLUDE USING gist (tenant_id WITH =, year_id WITH =, daterange(start_date, end_date, '[]') WITH &&)
    WHERE (archived_at IS NULL)
);
CALL school.protect('school.period');
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.period FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');

CREATE FUNCTION school.guard_academic_year() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.status = 'closed' THEN RAISE EXCEPTION 'status_invalid' USING ERRCODE = '23514'; END IF;
    RETURN NEW;
  END IF;
  IF OLD.status = 'closed' THEN RAISE EXCEPTION 'year_closed' USING ERRCODE = '23514'; END IF;
  IF NEW.status <> OLD.status AND (OLD.status, NEW.status) NOT IN (('planned', 'current'), ('current', 'closed'), ('planned', 'closed')) THEN
    RAISE EXCEPTION 'status_invalid' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = 'closed' THEN NEW.closed_at := now(); END IF;
  IF (NEW.start_date, NEW.end_date) IS DISTINCT FROM (OLD.start_date, OLD.end_date) AND EXISTS (
       SELECT 1 FROM school.period WHERE tenant_id = NEW.tenant_id AND year_id = NEW.id AND archived_at IS NULL
       AND (start_date < NEW.start_date OR end_date > NEW.end_date)) THEN
    RAISE EXCEPTION 'period_outside_year' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard_academic_year BEFORE INSERT OR UPDATE ON school.academic_year FOR EACH ROW EXECUTE FUNCTION school.guard_academic_year();

CREATE FUNCTION school.guard_period() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE y school.academic_year;
BEGIN
  SELECT * INTO y FROM school.academic_year WHERE tenant_id = NEW.tenant_id AND id = NEW.year_id;
  IF y.status = 'closed' THEN RAISE EXCEPTION 'year_closed' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'UPDATE' AND NEW.year_id <> OLD.year_id THEN RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514'; END IF;
  IF NEW.archived_at IS NULL AND (NEW.start_date < y.start_date OR NEW.end_date > y.end_date) THEN
    RAISE EXCEPTION 'period_outside_year' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard_period BEFORE INSERT OR UPDATE ON school.period FOR EACH ROW EXECUTE FUNCTION school.guard_period();

-- Whether the year still accepts ordinary changes; later tables (groups, enrollments, results)
-- refuse theirs once it is closed (FR-REF-05 AC2).
CREATE FUNCTION school.year_open(p_year uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT EXISTS (SELECT 1 FROM school.academic_year WHERE tenant_id = school.tenant() AND id = p_year AND status <> 'closed') $$;

-- FR-REF-04: extra fields on students, guardians, staff and leads; at most 20 active per
-- entity. Key and type are permanent: another type is another field.
CREATE TABLE school.custom_field (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  entity text NOT NULL CHECK (entity IN ('student', 'guardian', 'staff', 'lead')),
  key text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]{1,39}$'),
  field_type text NOT NULL CHECK (field_type IN ('text', 'number', 'date', 'boolean', 'select')),
  options jsonb,
  required boolean NOT NULL DEFAULT false,
  is_sensitive boolean NOT NULL DEFAULT false,
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  sort_order integer NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  -- A select field lists its values, each with a permanent code and its labels.
  CHECK ((field_type = 'select') = (options IS NOT NULL)),
  CHECK (options IS NULL OR (jsonb_typeof(options) = 'array' AND jsonb_array_length(options) BETWEEN 1 AND 100)),
  UNIQUE (tenant_id, entity, key),
  UNIQUE (tenant_id, id)
);
CALL school.protect('school.custom_field');
CREATE TRIGGER key_frozen BEFORE UPDATE ON school.custom_field FOR EACH ROW EXECUTE FUNCTION school.freeze_code('key');
CREATE TRIGGER type_frozen BEFORE UPDATE ON school.custom_field FOR EACH ROW EXECUTE FUNCTION school.freeze_code('field_type');
CREATE TRIGGER entity_frozen BEFORE UPDATE ON school.custom_field FOR EACH ROW EXECUTE FUNCTION school.freeze_code('entity');

CREATE FUNCTION school.guard_custom_field() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.options IS NOT NULL AND EXISTS (
       SELECT 1 FROM jsonb_array_elements(NEW.options) o
       WHERE jsonb_typeof(o) <> 'object' OR coalesce(o ->> 'code', '') !~ '^[A-Z0-9_]{1,20}$' OR NOT school.valid_labels(o -> 'labels'))
     OR NEW.options IS NOT NULL AND (SELECT count(DISTINCT o ->> 'code') FROM jsonb_array_elements(NEW.options) o) <> jsonb_array_length(NEW.options) THEN
    RAISE EXCEPTION 'options_invalid' USING ERRCODE = '23514';
  END IF;
  -- A value already chosen keeps its meaning: options are added or relabelled, never removed.
  IF TG_OP = 'UPDATE' AND OLD.options IS NOT NULL AND EXISTS (
       SELECT 1 FROM jsonb_array_elements(OLD.options) o
       WHERE NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.options) n WHERE n ->> 'code' = o ->> 'code')) THEN
    RAISE EXCEPTION 'option_removed' USING ERRCODE = '23514';
  END IF;
  IF NEW.archived_at IS NULL AND (TG_OP = 'INSERT' OR OLD.archived_at IS NOT NULL) THEN
    PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text || NEW.entity, 15));
    IF (SELECT count(*) FROM school.custom_field WHERE tenant_id = NEW.tenant_id AND entity = NEW.entity AND archived_at IS NULL AND id <> NEW.id) >= 20 THEN
      RAISE EXCEPTION 'custom_field_limit' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard_custom_field BEFORE INSERT OR UPDATE ON school.custom_field FOR EACH ROW EXECUTE FUNCTION school.guard_custom_field();

-- The check the entity tables run on their custom values (FR-REF-04 AC1, AC2): types, select
-- values, required fields. Values of an archived field are kept as they are; an unknown key is
-- refused. The hint names the field.
CREATE FUNCTION school.check_custom_values(p_entity text, p_values jsonb) RETURNS void LANGUAGE plpgsql STABLE AS $$
DECLARE f school.custom_field; v jsonb; k text;
BEGIN
  IF p_values IS NOT NULL AND jsonb_typeof(p_values) <> 'object' THEN RAISE EXCEPTION 'custom_value_invalid' USING ERRCODE = '23514'; END IF;
  FOR k IN SELECT jsonb_object_keys(coalesce(p_values, '{}')) LOOP
    IF NOT EXISTS (SELECT 1 FROM school.custom_field WHERE tenant_id = school.tenant() AND entity = p_entity AND key = k) THEN
      RAISE EXCEPTION 'custom_field_unknown' USING ERRCODE = '23514', HINT = k;
    END IF;
  END LOOP;
  FOR f IN SELECT * FROM school.custom_field WHERE tenant_id = school.tenant() AND entity = p_entity AND archived_at IS NULL LOOP
    v := p_values -> f.key;
    IF v IS NULL OR jsonb_typeof(v) = 'null' OR v = '""' THEN
      IF f.required THEN RAISE EXCEPTION 'custom_value_required' USING ERRCODE = '23514', HINT = f.key; END IF;
      CONTINUE;
    END IF;
    IF NOT (CASE f.field_type
         WHEN 'text' THEN jsonb_typeof(v) = 'string' AND length(v #>> '{}') <= 1000
         WHEN 'number' THEN jsonb_typeof(v) = 'number'
         WHEN 'boolean' THEN jsonb_typeof(v) = 'boolean'
         WHEN 'date' THEN jsonb_typeof(v) = 'string' AND (v #>> '{}') ~ '^\d{4}-\d{2}-\d{2}$' AND (v #>> '{}') IS NOT NULL
         WHEN 'select' THEN jsonb_typeof(v) = 'string' AND EXISTS (SELECT 1 FROM jsonb_array_elements(f.options) o WHERE o ->> 'code' = v #>> '{}')
       END) THEN
      RAISE EXCEPTION 'custom_value_invalid' USING ERRCODE = '23514', HINT = f.key;
    END IF;
    IF f.field_type = 'date' THEN PERFORM (v #>> '{}')::date; END IF;
  END LOOP;
END $$;

-- School settings (FR-REF-06): more types and keys. Time zone Africa/Algiers, fixed.
ALTER TABLE platform.setting_definition DROP CONSTRAINT setting_definition_value_type_check;
ALTER TABLE platform.setting_definition ADD CONSTRAINT setting_definition_value_type_check
  CHECK (value_type IN ('integer', 'boolean', 'text', 'day_set', 'language_set'));

CREATE OR REPLACE FUNCTION school.guard_setting() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE def platform.setting_definition; n numeric;
BEGIN
  SELECT * INTO def FROM platform.setting_definition WHERE key = NEW.key;
  IF NOT school.is_system() AND NOT school.can_everywhere(def.permission) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF NOT (CASE def.value_type
       WHEN 'boolean' THEN jsonb_typeof(NEW.value) = 'boolean'
       WHEN 'integer' THEN jsonb_typeof(NEW.value) = 'number'
       WHEN 'text' THEN jsonb_typeof(NEW.value) = 'string' AND length(NEW.value #>> '{}') <= coalesce(def.max_value, 1000)
       -- Days 0 (Sunday) to 6 (Saturday), each once, at least one.
       WHEN 'day_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) BETWEEN 1 AND 7
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) d WHERE jsonb_typeof(d) <> 'number' OR (d #>> '{}') NOT IN ('0', '1', '2', '3', '4', '5', '6'))
         AND (SELECT count(DISTINCT d #>> '{}') FROM jsonb_array_elements(NEW.value) d) = jsonb_array_length(NEW.value)
       WHEN 'language_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) BETWEEN 1 AND 3
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) l WHERE jsonb_typeof(l) <> 'string' OR (l #>> '{}') NOT IN ('fr', 'ar', 'en'))
         AND (SELECT count(DISTINCT l #>> '{}') FROM jsonb_array_elements(NEW.value) l) = jsonb_array_length(NEW.value)
     END) THEN
    RAISE EXCEPTION 'setting_invalid' USING ERRCODE = '23514';
  END IF;
  IF def.value_type = 'integer' THEN
    n := (NEW.value #>> '{}')::numeric;
    IF n <> trunc(n) OR n < coalesce(def.min_value, n) OR n > coalesce(def.max_value, n) THEN
      RAISE EXCEPTION 'setting_invalid' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('calendar.open_days', 'day_set', '[0, 1, 2, 3, 4]', NULL, NULL, 'tenant.settings.manage', '{"fr": "Jours ouverts", "ar": "أيام العمل"}'),
  ('calendar.first_day_of_week', 'integer', '0', 0, 6, 'tenant.settings.manage', '{"fr": "Premier jour de la semaine", "ar": "أول أيام الأسبوع"}'),
  ('locale.languages', 'language_set', '["fr", "ar"]', NULL, NULL, 'tenant.settings.manage', '{"fr": "Langues activées", "ar": "اللغات المفعلة"}'),
  ('identity.address', 'text', '""', NULL, 300, 'tenant.settings.manage', '{"fr": "Adresse (reçus)", "ar": "العنوان (الوصولات)"}'),
  ('identity.phone', 'text', '""', NULL, 40, 'tenant.settings.manage', '{"fr": "Téléphone (reçus)", "ar": "الهاتف (الوصولات)"}'),
  ('identity.email', 'text', '""', NULL, 200, 'tenant.settings.manage', '{"fr": "E-mail (reçus)", "ar": "البريد الإلكتروني (الوصولات)"}');

-- Whether the school is open that day (FR-REF-06; used by the planning, FR-PLN-01).
CREATE FUNCTION school.open_day(p_day date) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT school.setting_value('calendar.open_days') @> to_jsonb(extract(dow FROM p_day)::int) $$;

-- FR-PLT-03: applies a profile version to a school, adding what the school lacks: types,
-- nodes, subjects. Nothing the school already has is changed, renamed or brought back from
-- archive; a new version is applied only when someone asks (never automatically).
CREATE FUNCTION platform.apply_profile(p_tenant uuid, p_profile text, p_version integer DEFAULT NULL) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE v integer; current_profile text; kind text; level smallint;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended(p_tenant::text, 14));
  SELECT coalesce(p_version, max(version)) INTO v FROM platform.profile WHERE code = p_profile AND published_at IS NOT NULL;
  SELECT subject_kind INTO kind FROM platform.profile WHERE code = p_profile AND version = v AND published_at IS NOT NULL;
  IF kind IS NULL THEN RAISE EXCEPTION 'profile_unknown' USING ERRCODE = '23514'; END IF;
  SELECT profile_code INTO current_profile FROM platform.tenant WHERE id = p_tenant FOR UPDATE;
  IF current_profile IS DISTINCT FROM p_profile AND current_profile IS NOT NULL THEN
    RAISE EXCEPTION 'profile_mismatch' USING ERRCODE = '23514';
  END IF;
  INSERT INTO school.node_type (tenant_id, code, parent_code, labels, level_order, is_required)
  SELECT p_tenant, t.code, t.parent_code, t.labels, t.level_order, t.is_required FROM platform.profile_node_type t
  WHERE t.profile_code = p_profile AND t.version = v
    AND NOT EXISTS (SELECT 1 FROM school.node_type x WHERE x.tenant_id = p_tenant AND x.code = t.code)
  ORDER BY t.level_order;
  -- Level by level, so that each node finds its parent.
  FOR level IN SELECT DISTINCT t.level_order FROM platform.profile_node_type t WHERE t.profile_code = p_profile AND t.version = v ORDER BY 1 LOOP
    INSERT INTO school.ref_node (tenant_id, type_code, parent_id, code, labels, sort_order, platform_code)
    SELECT p_tenant, n.type_code, parent.id, n.code, n.labels, n.sort_order, coalesce(n.platform_code, n.code)
    FROM platform.profile_node n
    JOIN platform.profile_node_type t ON t.profile_code = n.profile_code AND t.version = n.version AND t.code = n.type_code
    LEFT JOIN school.ref_node parent ON parent.tenant_id = p_tenant AND parent.type_code = t.parent_code AND parent.code = n.parent_code
    WHERE n.profile_code = p_profile AND n.version = v AND t.level_order = level
      AND NOT EXISTS (SELECT 1 FROM school.ref_node x WHERE x.tenant_id = p_tenant AND x.type_code = n.type_code AND x.code = n.code)
      -- A node whose parent the school archived or never had stays out.
      AND (t.parent_code IS NULL OR (parent.id IS NOT NULL AND parent.archived_at IS NULL))
    ORDER BY n.sort_order;
  END LOOP;
  INSERT INTO school.subject (tenant_id, code, kind, labels, sort_order)
  SELECT p_tenant, s.code, kind, s.labels, s.sort_order FROM platform.profile_subject s
  WHERE s.profile_code = p_profile AND s.version = v
    AND NOT EXISTS (SELECT 1 FROM school.subject x WHERE x.tenant_id = p_tenant AND x.code = s.code)
  ORDER BY s.sort_order;
  UPDATE platform.tenant SET profile_code = p_profile, profile_version = greatest(coalesce(profile_version, 0), v) WHERE id = p_tenant;
  RETURN v;
END $$;

-- A role scoped to levels or subjects names nodes and subjects of the school: replaces the
-- guard of 007. Group scopes arrive with groups (lot 2).
CREATE OR REPLACE FUNCTION school.guard_role_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
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
  IF NEW.scope_type = 'group' THEN RAISE EXCEPTION 'scope_unknown' USING ERRCODE = '23514'; END IF;
  IF NEW.scope_type IN ('site', 'level', 'subject') AND EXISTS (SELECT 1 FROM unnest(NEW.scope_ids) s WHERE NOT (CASE NEW.scope_type
       WHEN 'site' THEN EXISTS (SELECT 1 FROM school.site WHERE tenant_id = NEW.tenant_id AND id = s)
       WHEN 'level' THEN EXISTS (SELECT 1 FROM school.ref_node WHERE tenant_id = NEW.tenant_id AND id = s)
       WHEN 'subject' THEN EXISTS (SELECT 1 FROM school.subject WHERE tenant_id = NEW.tenant_id AND id = s) END)) THEN
    RAISE EXCEPTION 'scope_unknown' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'INSERT' AND EXISTS (SELECT 1 FROM school.role_permission rp JOIN platform.permission p ON p.code = rp.permission_code AND p.sensitive
       WHERE rp.tenant_id = NEW.tenant_id AND rp.role_id = NEW.role_id)
     AND (SELECT email FROM public.users WHERE id = NEW.user_id) IS NULL THEN
    RAISE EXCEPTION 'email_required' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;

-- FR-AUD-01: a deletion keeps the deleted values (masked as for updates): replaces 003's audit.
CREATE OR REPLACE FUNCTION school.audit() RETURNS trigger
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
    WHERE value IS DISTINCT FROM old_data -> key AND key NOT IN ('version', 'updated_at', 'updated_by') AND key NOT LIKE 'label\_%\_key';
  ELSIF TG_OP = 'DELETE' THEN
    SELECT jsonb_object_agg(key, CASE WHEN key = ANY (masked) THEN to_jsonb('[masqué]'::text) ELSE value END) INTO before_values
    FROM jsonb_each(old_data) WHERE key NOT LIKE 'label\_%\_key';
  END IF;
  INSERT INTO school.audit_log (tenant_id, actor_id, action, object_type, object_id, changed_fields, before, after, reason, request_id, ip)
  VALUES ((row_data ->> 'tenant_id')::uuid, school.actor(), TG_OP, TG_TABLE_NAME, (row_data ->> 'id')::uuid, changed,
          before_values, after_values, nullif(current_setting('app.reason', true), ''),
          nullif(current_setting('app.request_id', true), ''), nullif(current_setting('app.ip', true), ''));
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

-- The school's journal also records which profile version was applied, and only real changes:
-- replaces 003's tenant audit.
CREATE OR REPLACE FUNCTION platform.audit_tenant() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE tracked text[] := ARRAY['name', 'status', 'default_locale', 'profile_code', 'profile_version']; changed jsonb;
BEGIN
  SELECT COALESCE(jsonb_agg(k), '[]') INTO changed FROM unnest(tracked) k
  WHERE TG_OP = 'INSERT' OR to_jsonb(NEW) -> k IS DISTINCT FROM to_jsonb(OLD) -> k;
  IF changed = '[]' THEN RETURN NEW; END IF;
  INSERT INTO school.audit_log (tenant_id, actor_id, action, object_type, object_id, changed_fields, before, after, reason, request_id, ip)
  VALUES (NEW.id, school.actor(), TG_OP, 'tenant', NEW.id, changed,
          CASE WHEN TG_OP = 'UPDATE' THEN (SELECT jsonb_object_agg(k, to_jsonb(OLD) -> k) FROM jsonb_array_elements_text(changed) k) END,
          (SELECT jsonb_object_agg(k, to_jsonb(NEW) -> k) FROM jsonb_array_elements_text(changed) k),
          nullif(current_setting('app.reason', true), ''), nullif(current_setting('app.request_id', true), ''),
          nullif(current_setting('app.ip', true), ''));
  RETURN NEW;
END $$;
