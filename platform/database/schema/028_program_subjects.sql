-- Lot 2, a programme's subjects (FR-PRG-02): the subjects or modules it teaches, each with its
-- volume (total hours, weekly hours, number of sessions); a changed volume is a new version, the
-- previous ones kept.

-- The school's length of a session (FR-PLN-01), the default of a subject's sessions here.
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('planning.session_minutes', 'integer', '60', 15, 480, 'tenant.settings.manage', '{"fr": "Durée d’une séance (minutes)", "ar": "مدة الحصة (بالدقائق)"}');

-- FR-PRG-02: a subject of the catalogue (BR-13) taught in a programme from `valid_from` until
-- `valid_to` (included), with at least one of its total hours, weekly hours or number of
-- sessions. Hours have two decimal places: 1.50 is an hour and a half. A subject appears once in
-- a programme at any date, even when two requests add it at once. A programme may have none (AC4).
CREATE TABLE school.program_subject (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  program_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  total_hours numeric(6, 2) CHECK (total_hours > 0),
  weekly_hours numeric(5, 2) CHECK (weekly_hours > 0 AND weekly_hours <= 168),
  session_count integer CHECK (session_count BETWEEN 1 AND 10000),
  session_duration_minutes integer NOT NULL CHECK (session_duration_minutes BETWEEN 15 AND 1440),
  coefficient numeric(5, 2) CHECK (coefficient > 0),
  valid_from date NOT NULL,
  valid_to date,
  volume_version integer NOT NULL DEFAULT 1,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (total_hours IS NOT NULL OR weekly_hours IS NOT NULL OR session_count IS NOT NULL),
  CHECK (valid_to IS NULL OR valid_to >= valid_from),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, program_id) REFERENCES school.program (tenant_id, id),
  FOREIGN KEY (tenant_id, subject_id) REFERENCES school.subject (tenant_id, id),
  CONSTRAINT program_subject_once EXCLUDE USING gist (tenant_id WITH =, program_id WITH =, subject_id WITH =,
    daterange(valid_from, valid_to, '[]') WITH &&)
);
-- A subject's lines, for the check that refuses to delete a subject in use (FR-REF-03, BR-02).
CREATE INDEX program_subject_subject ON school.program_subject (tenant_id, subject_id);
CALL school.protect('school.program_subject');

-- Each volume a line has had, never changed (AC1: 40 h, then 36 h, both kept), with the day it
-- was set: the planning (FR-PLN-02) compares the sessions held with the volume of their day. A
-- line never used is deleted with its volumes (BR-02); one with sessions ends instead (AC3).
CREATE TABLE school.program_subject_volume (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  program_subject_id uuid NOT NULL,
  program_id uuid NOT NULL,
  version integer NOT NULL CHECK (version >= 1),
  total_hours numeric(6, 2),
  weekly_hours numeric(5, 2),
  session_count integer,
  session_duration_minutes integer NOT NULL,
  coefficient numeric(5, 2),
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by bigint REFERENCES public.users(id),
  PRIMARY KEY (tenant_id, program_subject_id, version),
  FOREIGN KEY (tenant_id, program_subject_id) REFERENCES school.program_subject (tenant_id, id) ON DELETE CASCADE
);
ALTER TABLE school.program_subject_volume ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.program_subject_volume FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.program_subject_volume USING (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER immutable BEFORE UPDATE ON school.program_subject_volume FOR EACH ROW EXECUTE FUNCTION school.immutable();

-- Who changes a programme's subjects: `program.subject.manage`, within its scope (FR-IAM-04). A
-- line keeps its programme, subject and first day; an ended line is final, the subject is added
-- again for a later period.
CREATE FUNCTION school.guard_program_subject() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require('program.subject.manage');
  PERFORM school.require_core('program.subject.manage', 'program', CASE WHEN TG_OP = 'INSERT' THEN NEW.program_id ELSE OLD.program_id END);
  IF TG_OP = 'UPDATE' THEN
    IF (NEW.program_id, NEW.subject_id, NEW.valid_from) IS DISTINCT FROM (OLD.program_id, OLD.subject_id, OLD.valid_from) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.valid_to IS NOT NULL THEN RAISE EXCEPTION 'program_subject_ended' USING ERRCODE = '23514'; END IF;
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.program_subject FOR EACH ROW EXECUTE FUNCTION school.guard_program_subject();

-- The rules that read beyond what the writer may see: the programme's year (closed, it freezes
-- its subjects; its dates bound theirs), the subject still in the catalogue, the lines already
-- there. A first day defaults to today within the year, a session to the school's length; a
-- changed volume is the next version.
CREATE FUNCTION school.check_program_subject() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE y school.academic_year;
BEGIN
  SELECT a.* INTO y FROM school.program p JOIN school.academic_year a ON a.tenant_id = p.tenant_id AND a.id = p.year_id
  WHERE p.tenant_id = CASE WHEN TG_OP = 'DELETE' THEN OLD.tenant_id ELSE NEW.tenant_id END
    AND p.id = CASE WHEN TG_OP = 'DELETE' THEN OLD.program_id ELSE NEW.program_id END;
  -- An unknown programme is the foreign key's to refuse.
  IF y.id IS NULL THEN RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; END IF;
  IF y.status = 'closed' THEN RAISE EXCEPTION 'year_closed' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  IF TG_OP = 'INSERT' THEN
    IF EXISTS (SELECT 1 FROM school.subject WHERE tenant_id = NEW.tenant_id AND id = NEW.subject_id AND archived_at IS NOT NULL) THEN
      RAISE EXCEPTION 'subject_archived' USING ERRCODE = '23514';
    END IF;
    NEW.valid_from := coalesce(NEW.valid_from, greatest(y.start_date, least(current_date, y.end_date)));
  END IF;
  IF NEW.valid_to < NEW.valid_from THEN RAISE EXCEPTION 'program_subject_end_before_start' USING ERRCODE = '23514'; END IF;
  IF NEW.valid_from NOT BETWEEN y.start_date AND y.end_date OR NEW.valid_to > y.end_date THEN
    RAISE EXCEPTION 'program_subject_outside_year' USING ERRCODE = '23514';
  END IF;
  NEW.session_duration_minutes := coalesce(NEW.session_duration_minutes, (school.setting_value('planning.session_minutes') #>> '{}')::integer);
  IF NEW.total_hours IS NULL AND NEW.weekly_hours IS NULL AND NEW.session_count IS NULL THEN
    RAISE EXCEPTION 'program_subject_volume_required' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'INSERT' THEN
    IF EXISTS (SELECT 1 FROM school.program_subject x WHERE x.tenant_id = NEW.tenant_id AND x.program_id = NEW.program_id
               AND x.subject_id = NEW.subject_id AND daterange(x.valid_from, x.valid_to, '[]') && daterange(NEW.valid_from, NEW.valid_to, '[]')) THEN
      RAISE EXCEPTION 'program_subject_overlap' USING ERRCODE = '23514';
    END IF;
    NEW.volume_version := 1;
  ELSIF (NEW.total_hours, NEW.weekly_hours, NEW.session_count, NEW.session_duration_minutes, NEW.coefficient)
        IS DISTINCT FROM (OLD.total_hours, OLD.weekly_hours, OLD.session_count, OLD.session_duration_minutes, OLD.coefficient) THEN
    NEW.volume_version := OLD.volume_version + 1;
  ELSE
    NEW.volume_version := OLD.volume_version;
  END IF;
  RETURN NEW;
END $$;
-- After `guard`: the permission first.
CREATE TRIGGER rules BEFORE INSERT OR UPDATE OR DELETE ON school.program_subject FOR EACH ROW EXECUTE FUNCTION school.check_program_subject();

CREATE FUNCTION school.version_program_subject() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF TG_OP = 'INSERT' OR NEW.volume_version <> OLD.volume_version THEN
    INSERT INTO school.program_subject_volume (tenant_id, program_subject_id, program_id, version, total_hours, weekly_hours, session_count,
      session_duration_minutes, coefficient, created_by)
    VALUES (NEW.tenant_id, NEW.id, NEW.program_id, NEW.volume_version, NEW.total_hours, NEW.weekly_hours, NEW.session_count,
      NEW.session_duration_minutes, NEW.coefficient, school.actor());
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER volume_version AFTER INSERT OR UPDATE ON school.program_subject FOR EACH ROW EXECUTE FUNCTION school.version_program_subject();

-- Read with the programme (FR-IAM-04): a reader limited to a level or a site reads the subjects
-- of the programmes they reach. Written, and deleted, within the scope of `program.subject.manage`.
CREATE POLICY resource_reader ON school.program_subject AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('program.program.read')) OR program_id IN (SELECT unnest((SELECT school.core_ids('program.program.read', 'program')))));
CREATE POLICY resource_creator ON school.program_subject AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('program.subject.manage')) OR program_id IN (SELECT unnest((SELECT school.core_ids('program.subject.manage', 'program')))));
CREATE POLICY resource_writer ON school.program_subject AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('program.subject.manage')) OR program_id IN (SELECT unnest((SELECT school.core_ids('program.subject.manage', 'program')))))
  WITH CHECK ((SELECT school.can_everywhere('program.subject.manage')) OR program_id IN (SELECT unnest((SELECT school.core_ids('program.subject.manage', 'program')))));
CREATE POLICY resource_deleter ON school.program_subject AS RESTRICTIVE FOR DELETE TO school_runtime USING (
  (SELECT school.can_everywhere('program.subject.manage')) OR program_id IN (SELECT unnest((SELECT school.core_ids('program.subject.manage', 'program')))));
CREATE POLICY resource_reader ON school.program_subject_volume AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('program.program.read')) OR program_id IN (SELECT unnest((SELECT school.core_ids('program.program.read', 'program')))));

-- The journal: read with the programmes; a scoped reader reads the entries of the lines they can
-- see, never the others' (the rule of 021). Replaces 023's.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('program_subject', 'pedagogy', '{program.program.read,audit.log.read}');
ALTER POLICY core_resource_reader ON school.audit_log USING (
  object_type NOT IN ('student', 'guardian', 'student_guardian', 'program', 'program_subject', 'study_group', 'enrollment', 'group_membership') OR
  (SELECT school.can_everywhere('audit.log.read')) OR
  (object_type = 'student' AND ((SELECT school.can_everywhere('student.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('student.record.read', 'student')))))) OR
  (object_type = 'guardian' AND ((SELECT school.can_everywhere('guardian.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.read', 'guardian')))))) OR
  (object_type = 'student_guardian' AND (SELECT school.can_everywhere('guardian.record.read'))) OR
  (object_type = 'program' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('program.program.read', 'program')))))) OR
  (object_type = 'program_subject' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id IN (SELECT s.id FROM school.program_subject s))) OR
  (object_type = 'study_group' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'study_group')))))) OR
  (object_type = 'enrollment' AND ((SELECT school.can_everywhere('enrollment.enrollment.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment')))))) OR
  (object_type = 'group_membership' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'group_membership')))))));
