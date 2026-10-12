-- Lot 2, the school's core (SRS 1.2, modules ELV and GRP; W2.1, W2.2, W2.5, W2.7, W2.8): pupils
-- and their guardians, the programmes one enrols in, groups, enrolments and the assignments to
-- groups. The database keeps the rules a bug or a race could break: a matricule given once
-- (FR-ELV-01 AC1), a group never over its capacity (BR-07, TST-07), one enrolment however often
-- it is asked for (BR-05, TST-08), the price agreed at enrolment (BR-11), the two dimensions of its
-- state (BR-12), levels from the referential only (BR-13, TST-18). Resource policies below
-- apply permissions and scopes even when a caller queries a table directly (TST-03).

-- Triggers check the action; resource policies also constrain its scope.
CREATE FUNCTION school.require(p_permission text) RETURNS void LANGUAGE plpgsql STABLE AS $$
BEGIN
  IF NOT school.is_system() AND NOT school.can(p_permission) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
END $$;

-- FR-ELV-02: a minor's enrolment needs a primary contact with a phone. The school can
-- configure the age used by this validation; its initial value is 19.
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('student.majority_age', 'integer', '19', 16, 21, 'tenant.settings.manage', '{"fr": "Âge de la majorité (années)", "ar": "سن الرشد (بالسنوات)"}');

-- A name in Arabic or in Latin letters, the search key of all of them (FR-I18-01 AC2).
CREATE FUNCTION school.person_name_valid(p_last_ar text, p_first_ar text, p_last_lat text, p_first_lat text) RETURNS boolean
LANGUAGE sql IMMUTABLE AS $$
  SELECT (p_last_ar IS NOT NULL AND p_first_ar IS NOT NULL) OR (p_last_lat IS NOT NULL AND p_first_lat IS NOT NULL) $$;

-- FR-ELV-01. The matricule is the school's, given once and never changed: `{year}{5 digits}` when
-- the school does not bring its own (OD-11), from a counter that no two pupils can share.
CREATE TABLE school.student (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  matricule text NOT NULL CHECK (matricule ~ '^[A-Za-z0-9_-]{1,30}$'),
  last_name_ar text CHECK (length(last_name_ar) BETWEEN 1 AND 80),
  first_name_ar text CHECK (length(first_name_ar) BETWEEN 1 AND 80),
  last_name_lat text CHECK (length(last_name_lat) BETWEEN 1 AND 80),
  first_name_lat text CHECK (length(first_name_lat) BETWEEN 1 AND 80),
  birth_date date NOT NULL,
  birth_place text CHECK (length(birth_place) BETWEEN 1 AND 120),
  gender text CHECK (gender IN ('m', 'f')),
  phone text CHECK (length(phone) BETWEEN 1 AND 40),
  email text CHECK (length(email) BETWEEN 3 AND 200),
  address text CHECK (length(address) BETWEEN 1 AND 300),
  photo_file_id uuid,
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'former', 'blocked')),
  status_reason text CHECK (length(btrim(status_reason)) BETWEEN 3 AND 500),
  origin_school text CHECK (length(origin_school) BETWEEN 1 AND 200),
  custom jsonb NOT NULL DEFAULT '{}',
  -- The pupil's own account, for their own data (role `student`, scope `personal`).
  user_id bigint,
  name_key text GENERATED ALWAYS AS (school.search_key(coalesce(last_name_ar, '') || ' ' || coalesce(first_name_ar, '') || ' '
    || coalesce(last_name_lat, '') || ' ' || coalesce(first_name_lat, ''))) STORED,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (school.person_name_valid(last_name_ar, first_name_ar, last_name_lat, first_name_lat)),
  CHECK (status <> 'blocked' OR status_reason IS NOT NULL),
  UNIQUE (tenant_id, matricule),
  UNIQUE (tenant_id, user_id),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, photo_file_id) REFERENCES school.file_object (tenant_id, id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES school.membership (tenant_id, user_id)
);
CREATE INDEX student_name ON school.student (tenant_id, name_key);
CREATE TRIGGER matricule_frozen BEFORE UPDATE ON school.student FOR EACH ROW EXECUTE FUNCTION school.freeze_code('matricule');
CALL school.protect('school.student');

CREATE TABLE school.matricule_counter (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  year integer NOT NULL,
  last integer NOT NULL CHECK (last >= 1),
  PRIMARY KEY (tenant_id, year)
);
ALTER TABLE school.matricule_counter ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.matricule_counter FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.matricule_counter USING (tenant_id = (SELECT school.allowed_tenant()))
  WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON school.matricule_counter FOR EACH ROW EXECUTE FUNCTION school.guard_writable();

-- The application never edits the counter directly. Imported numbers are skipped, and an
-- exhausted five-digit sequence fails explicitly instead of truncating its sixth digit.
CREATE FUNCTION school.next_matricule(p_tenant uuid) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE y integer := extract(year FROM current_date)::integer; n integer; candidate text;
BEGIN
  IF p_tenant IS DISTINCT FROM school.allowed_tenant() OR NOT school.can_everywhere('student.record.create') THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  LOOP
    INSERT INTO school.matricule_counter AS c (tenant_id, year, last) VALUES (p_tenant, y, 1)
    ON CONFLICT (tenant_id, year) DO UPDATE SET last = c.last + 1 RETURNING c.last INTO n;
    IF n > 99999 THEN RAISE EXCEPTION 'matricule_exhausted' USING ERRCODE = '23514'; END IF;
    candidate := y::text || lpad(n::text, 5, '0');
    EXIT WHEN NOT EXISTS (SELECT 1 FROM school.student WHERE tenant_id = p_tenant AND matricule = candidate);
  END LOOP;
  RETURN candidate;
END $$;

-- FR-ELV-01, FR-ELV-03: who may write, what a pupil's record holds, the transitions of its state.
-- A former pupil's record stays as it is, readable; a blocked one says why.
CREATE FUNCTION school.guard_student() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    PERFORM school.require('student.record.create');
  ELSIF NEW.archived_at IS DISTINCT FROM OLD.archived_at THEN
    PERFORM school.require_core('student.record.archive', 'student', OLD.id);
    IF (to_jsonb(NEW) - 'archived_at' - 'version') IS DISTINCT FROM (to_jsonb(OLD) - 'archived_at' - 'version') THEN
      PERFORM school.require_core('student.record.update', 'student', OLD.id);
    END IF;
  ELSE
    PERFORM school.require_core('student.record.update', 'student', OLD.id);
  END IF;
  IF TG_OP = 'UPDATE' AND OLD.status = 'former' AND (to_jsonb(NEW) - 'archived_at' - 'version' - 'updated_at' - 'updated_by' - 'name_key')
     IS DISTINCT FROM (to_jsonb(OLD) - 'archived_at' - 'version' - 'updated_at' - 'updated_by' - 'name_key') THEN
    RAISE EXCEPTION 'student_former' USING ERRCODE = '23514';
  END IF;
  NEW.last_name_ar := nullif(btrim(NEW.last_name_ar), ''); NEW.first_name_ar := nullif(btrim(NEW.first_name_ar), '');
  NEW.last_name_lat := nullif(btrim(NEW.last_name_lat), ''); NEW.first_name_lat := nullif(btrim(NEW.first_name_lat), '');
  NEW.email := nullif(lower(btrim(NEW.email)), '');
  NEW.phone := nullif(btrim(NEW.phone), '');
  IF NEW.birth_date > current_date THEN RAISE EXCEPTION 'birth_date_future' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'UPDATE' AND NEW.status IS DISTINCT FROM OLD.status
     AND (OLD.status, NEW.status) NOT IN (('active', 'former'), ('active', 'blocked'), ('blocked', 'active')) THEN
    RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514';
  END IF;
  IF NEW.status <> 'blocked' THEN NEW.status_reason := NULL; END IF;
  -- The school's own fields, when they change: a field added later does not hold up other changes.
  IF TG_OP = 'INSERT' OR NEW.custom IS DISTINCT FROM OLD.custom THEN PERFORM school.check_custom_values('student', NEW.custom); END IF;
  IF TG_OP = 'INSERT' AND NEW.matricule IS NULL THEN
    NEW.matricule := school.next_matricule(NEW.tenant_id);
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.student FOR EACH ROW EXECUTE FUNCTION school.guard_student();

-- FR-ELV-02: one guardian for brothers and sisters, linked to each of them.
CREATE TABLE school.guardian (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  last_name_ar text CHECK (length(last_name_ar) BETWEEN 1 AND 80),
  first_name_ar text CHECK (length(first_name_ar) BETWEEN 1 AND 80),
  last_name_lat text CHECK (length(last_name_lat) BETWEEN 1 AND 80),
  first_name_lat text CHECK (length(first_name_lat) BETWEEN 1 AND 80),
  phone text CHECK (length(phone) BETWEEN 1 AND 40),
  email text CHECK (length(email) BETWEEN 3 AND 200),
  profession text CHECK (length(profession) BETWEEN 1 AND 120),
  custom jsonb NOT NULL DEFAULT '{}',
  -- The parent's own account, for their children's data (role `parent`, scope `personal`).
  user_id bigint,
  name_key text GENERATED ALWAYS AS (school.search_key(coalesce(last_name_ar, '') || ' ' || coalesce(first_name_ar, '') || ' '
    || coalesce(last_name_lat, '') || ' ' || coalesce(first_name_lat, ''))) STORED,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (school.person_name_valid(last_name_ar, first_name_ar, last_name_lat, first_name_lat)),
  UNIQUE (tenant_id, user_id),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES school.membership (tenant_id, user_id)
);
CREATE INDEX guardian_name ON school.guardian (tenant_id, name_key);
CREATE INDEX guardian_phone ON school.guardian (tenant_id, phone) WHERE archived_at IS NULL;
CALL school.protect('school.guardian');

CREATE TABLE school.student_guardian (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  student_id uuid NOT NULL,
  guardian_id uuid NOT NULL,
  relationship text NOT NULL CHECK (relationship IN ('father', 'mother', 'guardian', 'other')),
  is_primary_contact boolean NOT NULL DEFAULT false,
  is_payer boolean NOT NULL DEFAULT false,
  emergency_contact boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, student_id) REFERENCES school.student (tenant_id, id),
  FOREIGN KEY (tenant_id, guardian_id) REFERENCES school.guardian (tenant_id, id)
);
CREATE UNIQUE INDEX student_guardian_once ON school.student_guardian (tenant_id, student_id, guardian_id) WHERE archived_at IS NULL;
CREATE INDEX student_guardian_guardian ON school.student_guardian (tenant_id, guardian_id) WHERE archived_at IS NULL;
CALL school.protect('school.student_guardian');

CREATE FUNCTION school.guard_guardian() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require(CASE WHEN TG_OP = 'INSERT' THEN 'guardian.record.create' ELSE 'guardian.record.update' END);
  IF TG_OP = 'UPDATE' AND TG_TABLE_NAME = 'guardian' THEN PERFORM school.require_core('guardian.record.update', 'guardian', OLD.id); END IF;
  IF TG_TABLE_NAME = 'guardian' THEN
    NEW.last_name_ar := nullif(btrim(NEW.last_name_ar), ''); NEW.first_name_ar := nullif(btrim(NEW.first_name_ar), '');
    NEW.last_name_lat := nullif(btrim(NEW.last_name_lat), ''); NEW.first_name_lat := nullif(btrim(NEW.first_name_lat), '');
    NEW.email := nullif(lower(btrim(NEW.email)), '');
    NEW.phone := nullif(btrim(NEW.phone), '');
    IF TG_OP = 'INSERT' OR NEW.custom IS DISTINCT FROM OLD.custom THEN PERFORM school.check_custom_values('guardian', NEW.custom); END IF;
  ELSE
    IF TG_OP = 'UPDATE' AND (NEW.student_id, NEW.guardian_id) IS DISTINCT FROM (OLD.student_id, OLD.guardian_id) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    -- A former pupil's record, its guardians included, stays as it is.
    IF EXISTS (SELECT 1 FROM school.student WHERE tenant_id = NEW.tenant_id AND id = NEW.student_id AND status = 'former') THEN
      RAISE EXCEPTION 'student_former' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.guardian FOR EACH ROW EXECUTE FUNCTION school.guard_guardian();
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.student_guardian FOR EACH ROW EXECUTE FUNCTION school.guard_guardian();

-- FR-PRG-01: what one enrols in, for a level of the referential (BR-13, TST-18) and a year. A
-- new price is a new version (BR-11): enrolments keep the one they were made with.
CREATE TABLE school.program (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  year_id uuid NOT NULL,
  node_id uuid NOT NULL,
  code text NOT NULL CHECK (code ~ '^[A-Z0-9_-]{1,30}$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  description text CHECK (length(description) <= 2000),
  duration_value integer CHECK (duration_value > 0),
  duration_unit text CHECK (duration_unit IN ('hours', 'days', 'weeks', 'months', 'years')),
  base_price numeric(14, 2) NOT NULL DEFAULT 0 CHECK (base_price >= 0),
  payment_terms jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(payment_terms) = 'array'),
  price_version integer NOT NULL DEFAULT 1,
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'inactive')),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK ((duration_value IS NULL) = (duration_unit IS NULL)),
  UNIQUE (tenant_id, year_id, code),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, year_id) REFERENCES school.academic_year (tenant_id, id),
  FOREIGN KEY (tenant_id, node_id) REFERENCES school.ref_node (tenant_id, id)
);
CALL school.protect('school.program');

-- Each price a programme has had, never changed.
CREATE TABLE school.program_price (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  program_id uuid NOT NULL,
  version integer NOT NULL CHECK (version >= 1),
  base_price numeric(14, 2) NOT NULL CHECK (base_price >= 0),
  payment_terms jsonb NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by bigint REFERENCES public.users(id),
  PRIMARY KEY (tenant_id, program_id, version),
  FOREIGN KEY (tenant_id, program_id) REFERENCES school.program (tenant_id, id)
);
ALTER TABLE school.program_price ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.program_price FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.program_price USING (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.program_price FOR EACH ROW EXECUTE FUNCTION school.immutable();

CREATE FUNCTION school.guard_program() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require('program.program.manage');
  IF TG_OP = 'UPDATE' THEN PERFORM school.require_core('program.program.manage', 'program', OLD.id); END IF;
  IF TG_OP = 'UPDATE' AND (NEW.year_id, NEW.node_id) IS DISTINCT FROM (OLD.year_id, OLD.node_id) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  -- A closed year freezes its programmes (FR-PRG-01, FR-REF-05).
  IF NOT school.is_system() AND NOT school.year_open(NEW.year_id) THEN RAISE EXCEPTION 'year_closed' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'INSERT' AND EXISTS (SELECT 1 FROM school.ref_node WHERE tenant_id = NEW.tenant_id AND id = NEW.node_id AND archived_at IS NOT NULL) THEN
    RAISE EXCEPTION 'node_archived' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'INSERT' THEN
    NEW.price_version := 1;
  ELSIF (NEW.base_price, NEW.payment_terms) IS DISTINCT FROM (OLD.base_price, OLD.payment_terms) THEN
    NEW.price_version := OLD.price_version + 1;
  ELSE
    NEW.price_version := OLD.price_version;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.program FOR EACH ROW EXECUTE FUNCTION school.guard_program();
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.program FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');

CREATE FUNCTION school.version_program_price() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF TG_OP = 'INSERT' OR NEW.price_version <> OLD.price_version THEN
    INSERT INTO school.program_price (tenant_id, program_id, version, base_price, payment_terms, created_by)
    VALUES (NEW.tenant_id, NEW.id, NEW.price_version, NEW.base_price, NEW.payment_terms, school.actor());
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER price_version AFTER INSERT OR UPDATE ON school.program FOR EACH ROW EXECUTE FUNCTION school.version_program_price();

-- FR-GRP-01: a class, a support or option group, a training session; on one site, for a year.
CREATE TABLE school.study_group (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  year_id uuid NOT NULL,
  code text NOT NULL CHECK (code ~ '^[A-Z0-9_-]{1,30}$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  group_type text NOT NULL CHECK (group_type IN ('class', 'support', 'option', 'session')),
  program_id uuid,
  site_id uuid NOT NULL,
  capacity integer NOT NULL CHECK (capacity >= 1),
  valid_from date NOT NULL,
  valid_to date,
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'closed')),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  -- A class or a session is the main group of a programme's pupils.
  CHECK (group_type NOT IN ('class', 'session') OR program_id IS NOT NULL),
  CHECK (valid_to IS NULL OR valid_to >= valid_from),
  UNIQUE (tenant_id, year_id, code),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, year_id) REFERENCES school.academic_year (tenant_id, id),
  FOREIGN KEY (tenant_id, program_id) REFERENCES school.program (tenant_id, id),
  FOREIGN KEY (tenant_id, site_id) REFERENCES school.site (tenant_id, id)
);
CREATE INDEX study_group_program ON school.study_group (tenant_id, program_id);
CALL school.protect('school.study_group');

-- FR-INS-01: a pupil enrolled in a programme, at the price of the day (BR-11). Its state is the
-- teaching one (BR-12); what was paid comes with lot 3, computed, never typed.
CREATE TABLE school.enrollment (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  student_id uuid NOT NULL,
  program_id uuid NOT NULL,
  year_id uuid NOT NULL,
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'validated', 'suspended', 'cancelled')),
  status_reason text CHECK (length(btrim(status_reason)) BETWEEN 3 AND 500),
  agreed_price numeric(14, 2) NOT NULL CHECK (agreed_price >= 0),
  discount_total numeric(14, 2) NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
  price_version integer NOT NULL,
  terms_snapshot jsonb NOT NULL,
  validated_by bigint REFERENCES public.users(id),
  validated_at timestamptz,
  transferred_from uuid,
  idempotency_key uuid NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (discount_total <= agreed_price),
  CHECK (status NOT IN ('suspended', 'cancelled') OR status_reason IS NOT NULL),
  UNIQUE (tenant_id, idempotency_key),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, student_id) REFERENCES school.student (tenant_id, id),
  FOREIGN KEY (tenant_id, program_id) REFERENCES school.program (tenant_id, id),
  FOREIGN KEY (tenant_id, year_id) REFERENCES school.academic_year (tenant_id, id),
  FOREIGN KEY (tenant_id, transferred_from) REFERENCES school.enrollment (tenant_id, id)
);
-- FR-INS-01: one enrolment that is not cancelled per pupil and programme.
CREATE UNIQUE INDEX enrollment_once ON school.enrollment (tenant_id, student_id, program_id) WHERE status <> 'cancelled';
CREATE INDEX enrollment_program ON school.enrollment (tenant_id, program_id);
CALL school.protect('school.enrollment');

-- Every change of an enrolment's state, its reason and its author, never changed (SRS §7.2).
CREATE TABLE school.enrollment_status_history (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  enrollment_id uuid NOT NULL,
  from_status text,
  to_status text NOT NULL,
  reason text,
  changed_at timestamptz NOT NULL DEFAULT now(),
  changed_by bigint REFERENCES public.users(id),
  FOREIGN KEY (tenant_id, enrollment_id) REFERENCES school.enrollment (tenant_id, id)
);
CREATE INDEX enrollment_status_history_enrollment ON school.enrollment_status_history (tenant_id, enrollment_id, changed_at);
ALTER TABLE school.enrollment_status_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.enrollment_status_history FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.enrollment_status_history USING (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.enrollment_status_history FOR EACH ROW EXECUTE FUNCTION school.immutable();

-- FR-GRP-02: an enrolment's place in a group, from `valid_from` until `valid_to` (excluded: a
-- membership ended today is no longer current). A class or a session is the main group: one at
-- most at a time per enrolment.
CREATE TABLE school.group_membership (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  enrollment_id uuid NOT NULL,
  group_id uuid NOT NULL,
  is_main boolean NOT NULL DEFAULT false,
  valid_from date NOT NULL DEFAULT current_date,
  valid_to date,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (valid_to IS NULL OR valid_to >= valid_from),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, enrollment_id) REFERENCES school.enrollment (tenant_id, id),
  FOREIGN KEY (tenant_id, group_id) REFERENCES school.study_group (tenant_id, id)
);
CREATE UNIQUE INDEX group_membership_main ON school.group_membership (tenant_id, enrollment_id) WHERE is_main AND valid_to IS NULL;
CREATE UNIQUE INDEX group_membership_once ON school.group_membership (tenant_id, enrollment_id, group_id) WHERE valid_to IS NULL;
CREATE INDEX group_membership_group ON school.group_membership (tenant_id, group_id) WHERE valid_to IS NULL;
CALL school.protect('school.group_membership');

-- Writes are checked twice. A `guard` trigger, with the writer's rights, asks for the permission
-- and keeps what never changes. A second trigger, with its owner's rights, applies the rules that
-- read other rows (capacity, quota, a primary contact, the main group): it sees all of them,
-- whatever the writer may read, and the application cannot call it.

CREATE FUNCTION school.guard_study_group() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p school.program;
BEGIN
  PERFORM school.require('group.group.manage');
  IF TG_OP = 'UPDATE' THEN PERFORM school.require_core('group.group.manage', 'study_group', OLD.id); END IF;
  IF TG_OP = 'UPDATE' AND (NEW.year_id, NEW.group_type, NEW.program_id, NEW.site_id, NEW.valid_from)
     IS DISTINCT FROM (OLD.year_id, OLD.group_type, OLD.program_id, OLD.site_id, OLD.valid_from) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF NOT school.is_system() AND NOT school.year_open(NEW.year_id) THEN RAISE EXCEPTION 'year_closed' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'UPDATE' AND OLD.status = 'closed' THEN RAISE EXCEPTION 'group_closed' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'UPDATE' AND NEW.status IS DISTINCT FROM OLD.status
     AND (OLD.status, NEW.status) NOT IN (('draft', 'active'), ('draft', 'closed'), ('active', 'closed')) THEN
    RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'INSERT' AND NEW.program_id IS NOT NULL THEN
    SELECT * INTO p FROM school.program WHERE tenant_id = NEW.tenant_id AND id = NEW.program_id;
    IF p.year_id IS DISTINCT FROM NEW.year_id THEN RAISE EXCEPTION 'program_other_year' USING ERRCODE = '23514'; END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.study_group FOR EACH ROW EXECUTE FUNCTION school.guard_study_group();
CREATE TRIGGER code_frozen BEFORE UPDATE ON school.study_group FOR EACH ROW EXECUTE FUNCTION school.freeze_code('code');

-- FR-GRP-01 AC2: never fewer places than members. This update locks the group's row, so that no
-- place is taken meanwhile.
CREATE FUNCTION school.check_group_places() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NEW.capacity < OLD.capacity AND (SELECT count(*) FROM school.group_membership
       WHERE tenant_id = NEW.tenant_id AND group_id = NEW.id AND valid_to IS NULL) > NEW.capacity THEN
    RAISE EXCEPTION 'capacity_below_members' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER places BEFORE UPDATE ON school.study_group FOR EACH ROW EXECUTE FUNCTION school.check_group_places();

CREATE FUNCTION school.guard_enrollment() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    PERFORM school.require('enrollment.enrollment.create');
    RETURN NEW;
  END IF;
  -- FR-INS-03 AC1: a cancelled enrolment is frozen (BR-03).
  IF OLD.status = 'cancelled' THEN RAISE EXCEPTION 'enrollment_cancelled' USING ERRCODE = '23514'; END IF;
  IF (NEW.student_id, NEW.program_id, NEW.year_id, NEW.agreed_price, NEW.price_version, NEW.terms_snapshot, NEW.idempotency_key,
      NEW.transferred_from, NEW.validated_by, NEW.validated_at) IS DISTINCT FROM (OLD.student_id, OLD.program_id, OLD.year_id,
      OLD.agreed_price, OLD.price_version, OLD.terms_snapshot, OLD.idempotency_key, OLD.transferred_from, OLD.validated_by, OLD.validated_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.status IS NOT DISTINCT FROM OLD.status THEN
    PERFORM school.require_core('enrollment.enrollment.update', 'enrollment', OLD.id);
    NEW.status_reason := OLD.status_reason;
  -- BR-12: the teaching state changes by hand, by whoever may, with a reason when it stops.
  ELSIF (OLD.status, NEW.status) IN (('pending', 'validated'), ('suspended', 'validated')) THEN
    PERFORM school.require_core('enrollment.enrollment.validate', 'enrollment', OLD.id);
    NEW.status_reason := NULL;
  ELSIF (OLD.status, NEW.status) = ('validated', 'suspended') THEN
    PERFORM school.require_core('enrollment.enrollment.suspend', 'enrollment', OLD.id);
  ELSIF NEW.status = 'cancelled' THEN
    PERFORM school.require_core('enrollment.enrollment.cancel', 'enrollment', OLD.id);
  ELSE
    RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514';
  END IF;
  IF NEW.status IN ('suspended', 'cancelled') AND NEW.status_reason IS NULL THEN
    RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.enrollment FOR EACH ROW EXECUTE FUNCTION school.guard_enrollment();

CREATE FUNCTION school.check_enrollment() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE p school.program; s school.student; quota integer; majority integer;
BEGIN
  IF EXISTS (SELECT 1 FROM school.academic_year WHERE tenant_id = NEW.tenant_id
             AND id = CASE WHEN TG_OP = 'INSERT' THEN (SELECT year_id FROM school.program WHERE tenant_id = NEW.tenant_id AND id = NEW.program_id)
                           ELSE OLD.year_id END AND status = 'closed') THEN
    RAISE EXCEPTION 'year_closed' USING ERRCODE = '23514';
  END IF;
  -- Freeze the pupil's eligibility until commit, including against a concurrent block.
  SELECT * INTO s FROM school.student WHERE tenant_id = NEW.tenant_id AND id = NEW.student_id FOR SHARE;
  IF TG_OP = 'UPDATE' THEN
    IF NEW.status = 'validated' AND OLD.status <> 'validated' THEN
      IF s.status = 'blocked' THEN RAISE EXCEPTION 'student_blocked' USING ERRCODE = '23514'; END IF;
      -- FR-INS-01: a programme taught in groups is validated with the pupil's main group.
      IF EXISTS (SELECT 1 FROM school.study_group WHERE tenant_id = NEW.tenant_id AND program_id = NEW.program_id
                 AND group_type IN ('class', 'session') AND status <> 'closed')
         AND NOT EXISTS (SELECT 1 FROM school.group_membership WHERE tenant_id = NEW.tenant_id AND enrollment_id = NEW.id
                         AND is_main AND valid_to IS NULL) THEN
        RAISE EXCEPTION 'main_group_required' USING ERRCODE = '23514';
      END IF;
      IF OLD.status = 'pending' THEN NEW.validated_by := school.actor(); NEW.validated_at := now(); END IF;
    END IF;
    RETURN NEW;
  END IF;
  SELECT * INTO p FROM school.program WHERE tenant_id = NEW.tenant_id AND id = NEW.program_id FOR SHARE;
  -- FR-PRG-01 AC2: an inactive programme takes no enrolment.
  IF p.status IS DISTINCT FROM 'active' OR p.archived_at IS NOT NULL THEN RAISE EXCEPTION 'program_not_active' USING ERRCODE = '23514'; END IF;
  -- FR-INS-01 AC2, FR-ELV-01: neither a blocked pupil nor a former one.
  IF s.status = 'blocked' THEN RAISE EXCEPTION 'student_blocked' USING ERRCODE = '23514'; END IF;
  IF s.status = 'former' OR s.archived_at IS NOT NULL THEN RAISE EXCEPTION 'student_former' USING ERRCODE = '23514'; END IF;
  -- FR-ELV-02 AC2: a minor has a primary contact with a phone.
  majority := coalesce((SELECT (v.value #>> '{}')::integer FROM school.setting v
                        WHERE v.tenant_id = NEW.tenant_id AND v.key = 'student.majority_age' AND v.archived_at IS NULL),
                       (SELECT (d.default_value #>> '{}')::integer FROM platform.setting_definition d WHERE d.key = 'student.majority_age'));
  IF s.birth_date > current_date - make_interval(years => majority) AND NOT EXISTS (
       SELECT 1 FROM school.student_guardian l JOIN school.guardian g ON g.tenant_id = l.tenant_id AND g.id = l.guardian_id
       WHERE l.tenant_id = NEW.tenant_id AND l.student_id = NEW.student_id AND l.is_primary_contact AND l.archived_at IS NULL
         AND g.archived_at IS NULL AND g.phone IS NOT NULL) THEN
    RAISE EXCEPTION 'primary_contact_required' USING ERRCODE = '23514';
  END IF;
  -- FR-PLT-02: the plan's quota of active pupils, those with an enrolment that is not cancelled in
  -- a year that is not closed; one enrolment at a time per school past this point, so that two
  -- cannot both take the last place of the quota.
  PERFORM pg_advisory_xact_lock(hashtextextended('enrollment:' || NEW.tenant_id::text, 0));
  SELECT quota_active_students INTO quota FROM platform.tenant_entitlement WHERE tenant_id = NEW.tenant_id;
  IF NOT EXISTS (SELECT 1 FROM school.enrollment e JOIN school.academic_year y ON y.tenant_id = e.tenant_id AND y.id = e.year_id
                 WHERE e.tenant_id = NEW.tenant_id AND e.student_id = NEW.student_id AND e.status <> 'cancelled' AND y.status <> 'closed')
     AND (SELECT count(DISTINCT e.student_id) FROM school.enrollment e
          JOIN school.academic_year y ON y.tenant_id = e.tenant_id AND y.id = e.year_id
          WHERE e.tenant_id = NEW.tenant_id AND e.status <> 'cancelled' AND y.status <> 'closed') >= quota THEN
    RAISE EXCEPTION 'student_quota_exceeded' USING ERRCODE = '23514';
  END IF;
  -- BR-11: the price and the terms of the day, copied; the catalogue may change afterwards.
  NEW.year_id := p.year_id;
  NEW.status := 'pending'; NEW.status_reason := NULL; NEW.validated_by := NULL; NEW.validated_at := NULL; NEW.transferred_from := NULL;
  NEW.agreed_price := p.base_price; NEW.price_version := p.price_version; NEW.discount_total := 0;
  NEW.terms_snapshot := jsonb_build_object('code', p.code, 'labels', p.labels, 'base_price', p.base_price,
    'payment_terms', p.payment_terms, 'price_version', p.price_version);
  RETURN NEW;
END $$;
CREATE TRIGGER rules BEFORE INSERT OR UPDATE ON school.enrollment FOR EACH ROW EXECUTE FUNCTION school.check_enrollment();

-- After each change of state: its history, and a cancelled enrolment's places given back
-- (FR-INS-03), whoever cancels it.
CREATE FUNCTION school.follow_enrollment() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF TG_OP = 'INSERT' OR NEW.status IS DISTINCT FROM OLD.status THEN
    INSERT INTO school.enrollment_status_history (tenant_id, enrollment_id, from_status, to_status, reason, changed_by)
    VALUES (NEW.tenant_id, NEW.id, CASE WHEN TG_OP = 'UPDATE' THEN OLD.status END, NEW.status, NEW.status_reason, school.actor());
  END IF;
  IF TG_OP = 'UPDATE' AND NEW.status = 'cancelled' AND OLD.status <> 'cancelled' THEN
    UPDATE school.group_membership SET valid_to = greatest(current_date, valid_from)
    WHERE tenant_id = NEW.tenant_id AND enrollment_id = NEW.id AND valid_to IS NULL;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER follow AFTER INSERT OR UPDATE ON school.enrollment FOR EACH ROW EXECUTE FUNCTION school.follow_enrollment();

-- FR-GRP-02: an assignment, once ended, stays as it was: it is the history of the pupil's groups.
CREATE FUNCTION school.guard_group_membership() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require('group.membership.manage');
  IF TG_OP = 'UPDATE' THEN PERFORM school.require_core('group.membership.manage', 'group_membership', OLD.id); END IF;
  IF TG_OP = 'UPDATE' AND ((NEW.enrollment_id, NEW.group_id, NEW.is_main, NEW.valid_from)
       IS DISTINCT FROM (OLD.enrollment_id, OLD.group_id, OLD.is_main, OLD.valid_from) OR OLD.valid_to IS NOT NULL) THEN
    RAISE EXCEPTION 'membership_ended' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.group_membership FOR EACH ROW EXECUTE FUNCTION school.guard_group_membership();

-- An assignment needs an enrolment pending or validated, an open group of its programme and year,
-- and a place (BR-07, TST-07): taken with the group's row locked, so that two requests for the
-- last place never both get it, and counted over every member of the group. The main group
-- follows from the group's type.
CREATE FUNCTION school.check_group_membership() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE e school.enrollment; g school.study_group;
BEGIN
  -- An assignment and a cancellation serialize on the same enrolment, before the group.
  SELECT * INTO e FROM school.enrollment WHERE tenant_id = NEW.tenant_id AND id = NEW.enrollment_id FOR NO KEY UPDATE;
  SELECT * INTO g FROM school.study_group WHERE tenant_id = NEW.tenant_id AND id = NEW.group_id FOR NO KEY UPDATE;
  IF e.status NOT IN ('pending', 'validated') THEN RAISE EXCEPTION 'enrollment_not_assignable' USING ERRCODE = '23514'; END IF;
  IF g.status <> 'active' OR g.archived_at IS NOT NULL THEN RAISE EXCEPTION 'group_not_active' USING ERRCODE = '23514'; END IF;
  IF g.year_id <> e.year_id THEN RAISE EXCEPTION 'group_other_year' USING ERRCODE = '23514'; END IF;
  IF g.group_type IN ('class', 'session') AND g.program_id <> e.program_id THEN RAISE EXCEPTION 'group_other_program' USING ERRCODE = '23514'; END IF;
  IF EXISTS (SELECT 1 FROM school.academic_year WHERE tenant_id = g.tenant_id AND id = g.year_id AND status = 'closed') THEN
    RAISE EXCEPTION 'year_closed' USING ERRCODE = '23514';
  END IF;
  IF NEW.valid_from < g.valid_from OR (g.valid_to IS NOT NULL AND NEW.valid_from > g.valid_to) THEN
    RAISE EXCEPTION 'outside_group_dates' USING ERRCODE = '23514';
  END IF;
  IF (SELECT count(*) FROM school.group_membership WHERE tenant_id = NEW.tenant_id AND group_id = NEW.group_id AND valid_to IS NULL) >= g.capacity THEN
    RAISE EXCEPTION 'group_full' USING ERRCODE = '23514';
  END IF;
  NEW.is_main := g.group_type IN ('class', 'session');
  NEW.valid_to := NULL;
  RETURN NEW;
END $$;
CREATE TRIGGER place BEFORE INSERT ON school.group_membership FOR EACH ROW EXECUTE FUNCTION school.check_group_membership();

-- TST-08, BR-05: an enrolment asked for twice with the same key is made once; the second answer
-- is the first enrolment. A key reused for another pupil or programme is refused.
CREATE FUNCTION school.enroll(p_student uuid, p_program uuid, p_key uuid) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE e school.enrollment;
BEGIN
  SELECT * INTO e FROM school.enrollment WHERE tenant_id = school.tenant() AND idempotency_key = p_key;
  IF e.id IS NULL THEN
    INSERT INTO school.enrollment (tenant_id, student_id, program_id, idempotency_key)
    VALUES (school.tenant(), p_student, p_program, p_key)
    ON CONFLICT (tenant_id, idempotency_key) DO NOTHING RETURNING * INTO e;
    -- Asked for at the same time: the other request made it.
    IF e.id IS NULL THEN SELECT * INTO e FROM school.enrollment WHERE tenant_id = school.tenant() AND idempotency_key = p_key; END IF;
  END IF;
  IF (e.student_id, e.program_id) IS DISTINCT FROM (p_student, p_program) THEN
    RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514';
  END IF;
  RETURN e.id;
END $$;

-- FR-GRP-02 AC3: a transfer ends the assignment today and opens the next one, in one go; both stay.
CREATE FUNCTION school.move_to_group(p_membership uuid, p_group uuid) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE m school.group_membership; target uuid;
BEGIN
  UPDATE school.group_membership SET valid_to = greatest(current_date, valid_from)
  WHERE tenant_id = school.tenant() AND id = p_membership AND valid_to IS NULL RETURNING * INTO m;
  IF m.id IS NULL THEN RAISE EXCEPTION 'membership_ended' USING ERRCODE = '23514'; END IF;
  INSERT INTO school.group_membership (tenant_id, enrollment_id, group_id, valid_from)
  VALUES (m.tenant_id, m.enrollment_id, p_group, current_date) RETURNING id INTO target;
  RETURN target;
END $$;

-- BR-17: the pupils' and guardians' names, their photo, school of origin and own fields join the
-- personal data the journal masks; the search keys stay out of it. Replaces 019's.
CREATE OR REPLACE FUNCTION school.audit() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE row_data jsonb; old_data jsonb; changed jsonb; before_values jsonb; after_values jsonb; masked text[];
  ctx school.request_context := school.context();
BEGIN
  row_data := (CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END) - 'name_key';
  old_data := (CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE to_jsonb(OLD) END) - 'name_key';
  SELECT COALESCE(jsonb_agg(key), '[]') INTO changed FROM jsonb_each(row_data)
  WHERE TG_OP <> 'UPDATE' OR value IS DISTINCT FROM old_data -> key;
  masked := ARRAY['first_name', 'last_name', 'birth_date', 'birth_place', 'phone', 'email', 'address', 'matricule',
                  'photo', 'observation', 'body', 'payload', 'rendered', 'rules', 'calculation', 'password',
                  'last_name_ar', 'first_name_ar', 'last_name_lat', 'first_name_lat', 'photo_file_id', 'origin_school', 'profession', 'custom'];
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
  VALUES ((row_data ->> 'tenant_id')::uuid, ctx.user_id, TG_OP, TG_TABLE_NAME, (row_data ->> 'id')::uuid, changed,
          before_values, after_values, nullif(current_setting('app.reason', true), ''), ctx.request_id, ctx.ip);
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

-- The journal's readers by domain (010): the pupils' records and enrolments.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('student', 'pedagogy', '{student.record.read,audit.log.read}'),
  ('guardian', 'pedagogy', '{guardian.record.read,audit.log.read}'),
  ('student_guardian', 'pedagogy', '{guardian.record.read,audit.log.read}'),
  ('program', 'pedagogy', '{program.program.read,audit.log.read}'),
  ('study_group', 'pedagogy', '{group.group.read,audit.log.read}'),
  ('enrollment', 'pedagogy', '{enrollment.enrollment.read,audit.log.read}'),
  ('group_membership', 'pedagogy', '{group.group.read,enrollment.enrollment.read,audit.log.read}');

-- FR-IAM-04: IDs within an action's scope, computed once per query by each policy, with the
-- provisioner's rights to avoid recursive RLS and to see links the caller cannot read.
-- No role code is tested. Personal access needs an explicit permission and an account linked
-- to the pupil or guardian; a classmate's account grants nothing. Subject scopes stay closed
-- until programmes have their subject links. Staff's implicit teaching scopes come with staff.
CREATE FUNCTION school.core_ids(p_permission text, p_entity text) RETURNS uuid[]
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); u bigint := school.actor(); ids uuid[];
BEGIN
  IF t IS NULL OR school.can_everywhere(p_permission) THEN RETURN '{}'::uuid[]; END IF;
  WITH RECURSIVE mine AS MATERIALIZED (
    SELECT e.scope_type, e.scope_ids FROM school.effective_permissions(u, t) e WHERE e.permission = p_permission
  ), levels(id) AS (
    SELECT unnest(scope_ids) FROM mine WHERE scope_type = 'level'
    UNION SELECT n.id FROM school.ref_node n JOIN levels l ON n.parent_id = l.id WHERE n.tenant_id = t
  ), own_students AS (
    SELECT s.id FROM school.student s WHERE s.tenant_id = t AND s.user_id = u
    UNION SELECT l.student_id FROM school.student_guardian l JOIN school.guardian g ON g.tenant_id = l.tenant_id AND g.id = l.guardian_id
      WHERE l.tenant_id = t AND l.archived_at IS NULL AND g.archived_at IS NULL AND g.user_id = u
  ), resources AS (
    SELECT s.id, s.id AS student_id, p.node_id, g.id AS group_id, g.site_id, s.user_id AS own_user
    FROM school.student s
    LEFT JOIN school.enrollment e ON e.tenant_id = t AND e.student_id = s.id AND e.status <> 'cancelled'
    LEFT JOIN school.program p ON p.tenant_id = t AND p.id = e.program_id
    LEFT JOIN school.group_membership m ON m.tenant_id = t AND m.enrollment_id = e.id AND m.valid_to IS NULL
    LEFT JOIN school.study_group g ON g.tenant_id = t AND g.id = m.group_id
    WHERE p_entity = 'student' AND s.tenant_id = t
    UNION ALL
    SELECT h.id, l.student_id, p.node_id, g.id, g.site_id, h.user_id
    FROM school.guardian h
    LEFT JOIN school.student_guardian l ON l.tenant_id = t AND l.guardian_id = h.id AND l.archived_at IS NULL
    LEFT JOIN school.enrollment e ON e.tenant_id = t AND e.student_id = l.student_id AND e.status <> 'cancelled'
    LEFT JOIN school.program p ON p.tenant_id = t AND p.id = e.program_id
    LEFT JOIN school.group_membership m ON m.tenant_id = t AND m.enrollment_id = e.id AND m.valid_to IS NULL
    LEFT JOIN school.study_group g ON g.tenant_id = t AND g.id = m.group_id
    WHERE p_entity = 'guardian' AND h.tenant_id = t
    UNION ALL
    SELECT p.id, e.student_id, p.node_id, g.id, g.site_id, NULL::bigint
    FROM school.program p
    LEFT JOIN school.study_group g ON g.tenant_id = t AND g.program_id = p.id
    LEFT JOIN school.enrollment e ON e.tenant_id = t AND e.program_id = p.id
    WHERE p_entity = 'program' AND p.tenant_id = t
    UNION ALL
    SELECT g.id, e.student_id, p.node_id, g.id, g.site_id, NULL::bigint
    FROM school.study_group g LEFT JOIN school.program p ON p.tenant_id = t AND p.id = g.program_id
    LEFT JOIN school.group_membership m ON m.tenant_id = t AND m.group_id = g.id AND m.valid_to IS NULL
    LEFT JOIN school.enrollment e ON e.tenant_id = t AND e.id = m.enrollment_id
    WHERE p_entity = 'study_group' AND g.tenant_id = t
    UNION ALL
    SELECT e.id, e.student_id, p.node_id, g.id, g.site_id, NULL::bigint
    FROM school.enrollment e JOIN school.program p ON p.tenant_id = t AND p.id = e.program_id
    LEFT JOIN school.group_membership m ON m.tenant_id = t AND m.enrollment_id = e.id AND m.valid_to IS NULL
    LEFT JOIN school.study_group g ON g.tenant_id = t AND g.id = m.group_id
    WHERE p_entity = 'enrollment' AND e.tenant_id = t
    UNION ALL
    SELECT m.id, e.student_id, p.node_id, g.id, g.site_id, NULL::bigint
    FROM school.group_membership m JOIN school.enrollment e ON e.tenant_id = t AND e.id = m.enrollment_id
    JOIN school.study_group g ON g.tenant_id = t AND g.id = m.group_id
    JOIN school.program p ON p.tenant_id = t AND p.id = e.program_id
    WHERE p_entity = 'group_membership' AND m.tenant_id = t
  )
  SELECT array_agg(DISTINCT r.id) INTO ids FROM resources r WHERE EXISTS (
    SELECT 1 FROM mine a WHERE CASE a.scope_type
      WHEN 'site' THEN r.site_id = ANY (a.scope_ids)
      WHEN 'group' THEN r.group_id = ANY (a.scope_ids)
      WHEN 'level' THEN r.node_id IN (SELECT id FROM levels)
      WHEN 'personal' THEN r.own_user = u OR r.student_id IN (SELECT id FROM own_students)
      ELSE false END);
  RETURN coalesce(ids, '{}'::uuid[]);
END $$;

-- A write must match that action's scope, not just another action held on the same row.
CREATE FUNCTION school.require_core(p_permission text, p_entity text, p_id uuid) RETURNS void
LANGUAGE plpgsql STABLE AS $$
BEGIN
  IF NOT school.is_system() AND NOT (school.can_everywhere(p_permission) OR p_id = ANY (school.core_ids(p_permission, p_entity))) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
END $$;

-- Replaces 008's assignment guard: group IDs now exist, and must belong to this school.
-- Role managers can validate a group scope without gaining access to pupils or group rosters.
CREATE FUNCTION school.group_scope_valid(p_tenant uuid, p_groups uuid[]) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT p_tenant = school.allowed_tenant() AND school.can_everywhere('role.assignment.manage')
    AND NOT EXISTS (SELECT 1 FROM unnest(p_groups) g WHERE g IS NULL OR NOT EXISTS (
      SELECT 1 FROM school.study_group s WHERE s.tenant_id = p_tenant AND s.id = g)) $$;

CREATE OR REPLACE FUNCTION school.guard_role_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE role_kind text; role_archived timestamptz;
BEGIN
  IF NOT school.is_system() THEN
    IF NOT school.can_everywhere('role.assignment.manage') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
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
  IF NEW.scope_type = 'group' AND NOT school.is_system() AND NOT school.group_scope_valid(NEW.tenant_id, NEW.scope_ids) THEN
    RAISE EXCEPTION 'scope_unknown' USING ERRCODE = '23514';
  END IF;
  IF NEW.scope_type IN ('site', 'level', 'subject', 'group') AND EXISTS (SELECT 1 FROM unnest(NEW.scope_ids) s WHERE s IS NULL OR NOT (CASE NEW.scope_type
       WHEN 'site' THEN EXISTS (SELECT 1 FROM school.site WHERE tenant_id = NEW.tenant_id AND id = s)
       WHEN 'level' THEN EXISTS (SELECT 1 FROM school.ref_node WHERE tenant_id = NEW.tenant_id AND id = s)
       WHEN 'subject' THEN EXISTS (SELECT 1 FROM school.subject WHERE tenant_id = NEW.tenant_id AND id = s)
       WHEN 'group' THEN NOT school.is_system() OR EXISTS (SELECT 1 FROM school.study_group WHERE tenant_id = NEW.tenant_id AND id = s) END)) THEN
    RAISE EXCEPTION 'scope_unknown' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'INSERT' AND EXISTS (SELECT 1 FROM school.role_permission rp JOIN platform.permission p ON p.code = rp.permission_code AND p.sensitive
       WHERE rp.tenant_id = NEW.tenant_id AND rp.role_id = NEW.role_id)
     AND (SELECT email FROM public.users WHERE id = NEW.user_id) IS NULL THEN
    RAISE EXCEPTION 'email_required' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;

-- Keep the tenant policy, and intersect it with resource permissions. SELECT, INSERT and
-- UPDATE have separate restrictive policies: a read grant never opens a write. Creating an
-- unlinked record initially needs a tenant scope; scoped admission comes with its API workflow.
DO $$ DECLARE spec record; action text; read_rule text; write_rule text; BEGIN
  FOR spec IN SELECT * FROM (VALUES
    ('student', 'student.record.read', 'student.record.create', ARRAY['student.record.update', 'student.record.archive']),
    ('guardian', 'guardian.record.read', 'guardian.record.create', ARRAY['guardian.record.update']),
    ('program', 'program.program.read', 'program.program.manage', ARRAY['program.program.manage']),
    ('study_group', 'group.group.read', 'group.group.manage', ARRAY['group.group.manage']),
    ('enrollment', 'enrollment.enrollment.read', 'enrollment.enrollment.create', ARRAY['enrollment.enrollment.update',
      'enrollment.enrollment.validate', 'enrollment.enrollment.suspend', 'enrollment.enrollment.cancel']),
    ('group_membership', 'group.group.read', 'group.membership.manage', ARRAY['group.membership.manage'])
  ) AS s(tbl, reader, creator, writers) LOOP
    read_rule := format('(SELECT school.can_everywhere(%L)) OR id = ANY ((SELECT school.core_ids(%L, %L))::uuid[])', spec.reader, spec.reader, spec.tbl);
    EXECUTE format('CREATE POLICY resource_reader ON school.%I AS RESTRICTIVE FOR SELECT TO school_runtime USING (%s)', spec.tbl, read_rule);
    EXECUTE format('CREATE POLICY resource_creator ON school.%I AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere(%L)))', spec.tbl, spec.creator);
    write_rule := 'false';
    FOREACH action IN ARRAY spec.writers LOOP
      write_rule := write_rule || format(' OR (SELECT school.can_everywhere(%L)) OR id = ANY ((SELECT school.core_ids(%L, %L))::uuid[])', action, action, spec.tbl);
    END LOOP;
    EXECUTE format('CREATE POLICY resource_writer ON school.%I AS RESTRICTIVE FOR UPDATE TO school_runtime USING (%s) WITH CHECK (%s)', spec.tbl, write_rule, write_rule);
  END LOOP;
END $$;

CREATE POLICY resource_reader ON school.student_guardian AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('guardian.record.read')) OR
  (student_id = ANY ((SELECT school.core_ids('student.record.read', 'student'))::uuid[])
   AND guardian_id = ANY ((SELECT school.core_ids('guardian.record.read', 'guardian'))::uuid[])));
CREATE POLICY resource_creator ON school.student_guardian AS RESTRICTIVE FOR INSERT TO school_runtime
  WITH CHECK ((SELECT school.can_everywhere('guardian.record.create')));
CREATE POLICY resource_writer ON school.student_guardian AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('guardian.record.update')))
  WITH CHECK ((SELECT school.can_everywhere('guardian.record.update')));
CREATE POLICY resource_reader ON school.program_price AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('program.program.read')) OR program_id = ANY ((SELECT school.core_ids('program.program.read', 'program'))::uuid[]));
CREATE POLICY resource_reader ON school.enrollment_status_history AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('enrollment.enrollment.read')) OR enrollment_id = ANY ((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment'))::uuid[]));

-- A scoped reader cannot use the journal as a back door to the other pupils' IDs or states.
CREATE POLICY core_resource_reader ON school.audit_log AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  object_type NOT IN ('student', 'guardian', 'student_guardian', 'program', 'study_group', 'enrollment', 'group_membership') OR
  (SELECT school.can_everywhere('audit.log.read')) OR
  (object_type = 'student' AND ((SELECT school.can_everywhere('student.record.read')) OR object_id = ANY ((SELECT school.core_ids('student.record.read', 'student'))::uuid[]))) OR
  (object_type = 'guardian' AND ((SELECT school.can_everywhere('guardian.record.read')) OR object_id = ANY ((SELECT school.core_ids('guardian.record.read', 'guardian'))::uuid[]))) OR
  (object_type = 'student_guardian' AND (SELECT school.can_everywhere('guardian.record.read'))) OR
  (object_type = 'program' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id = ANY ((SELECT school.core_ids('program.program.read', 'program'))::uuid[]))) OR
  (object_type = 'study_group' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id = ANY ((SELECT school.core_ids('group.group.read', 'study_group'))::uuid[]))) OR
  (object_type = 'enrollment' AND ((SELECT school.can_everywhere('enrollment.enrollment.read')) OR object_id = ANY ((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment'))::uuid[]))) OR
  (object_type = 'group_membership' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id = ANY ((SELECT school.core_ids('group.group.read', 'group_membership'))::uuid[]))));
