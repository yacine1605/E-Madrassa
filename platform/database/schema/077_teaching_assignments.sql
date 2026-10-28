-- Lot 5, the pedagogy's base (SRS 1.2, FR-LMS-*; SPECIFICATION_LOT5_PEDAGOGIE.md §1): who
-- teaches a group's subject, and who learns in it. An assignment names the teacher of a group's
-- subject from a day until another (« l'affectation en vigueur »): lot 5's activities are
-- published by the teacher assigned that day or by the pedagogy manager, never by a colleague.
-- A pupil's record is linked to the account they sign in with; their courses open only through a
-- group they are in now, by an enrolment that grants access (FR-INS-04, `access_granted`).

-- Who assigns the teachers: the pedagogy manager, for the whole school or on the groups' sites.
INSERT INTO platform.permission (code, module_key, sensitive, labels) VALUES
  ('lms.assignment.manage', 'lms', false, '{"fr": "Gérer les affectations d’enseignement", "ar": "تسيير إسناد التدريس"}');
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES ('academic_hr', 'lms.assignment.manage');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, 'lms.assignment.manage' FROM school.role r
WHERE r.template_code = 'academic_hr' AND platform.tenant_mode(r.tenant_id) = 'active'
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = 'lms.assignment.manage');

-- A group's subject taught by a teacher from `valid_from` until `valid_to` (included). One teacher
-- at a time per group and subject, even for two requests at once: the next one is assigned from
-- the day after the previous one ends. An assignment keeps its group, subject, teacher and first
-- day; its end is final. One that has not begun is deleted; one that has is ended, never deleted.
CREATE TABLE school.teaching_assignment (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  staff_id uuid NOT NULL,
  valid_from date NOT NULL,
  valid_to date,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (valid_to IS NULL OR valid_to >= valid_from),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, group_id) REFERENCES school.study_group (tenant_id, id),
  FOREIGN KEY (tenant_id, subject_id) REFERENCES school.subject (tenant_id, id),
  FOREIGN KEY (tenant_id, staff_id) REFERENCES school.staff (tenant_id, id),
  CONSTRAINT teaching_assignment_once EXCLUDE USING gist (tenant_id WITH =, group_id WITH =, subject_id WITH =,
    daterange(valid_from, valid_to, '[]') WITH &&)
);
CREATE INDEX teaching_assignment_staff ON school.teaching_assignment (tenant_id, staff_id);
CALL school.protect('school.teaching_assignment');

-- Who writes: `lms.assignment.manage` for the whole school or on the group's site. What never changes.
CREATE FUNCTION school.guard_teaching_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a school.teaching_assignment := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
BEGIN
  PERFORM school.require('lms.assignment.manage');
  IF NOT school.is_system() AND NOT (school.can_everywhere('lms.assignment.manage')
       OR a.group_id = ANY (school.site_scope_ids('lms.assignment.manage', 'study_group'))) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF TG_OP = 'DELETE' THEN
    IF OLD.valid_from <= current_date THEN RAISE EXCEPTION 'assignment_begun' USING ERRCODE = '23514'; END IF;
    RETURN OLD;
  END IF;
  IF TG_OP = 'INSERT' THEN
    NEW.valid_from := coalesce(NEW.valid_from, current_date); NEW.archived_at := NULL;
    IF NEW.valid_from < current_date THEN RAISE EXCEPTION 'assignment_past' USING ERRCODE = '23514'; END IF;
  ELSE
    IF (NEW.group_id, NEW.subject_id, NEW.staff_id, NEW.valid_from, NEW.archived_at)
       IS DISTINCT FROM (OLD.group_id, OLD.subject_id, OLD.staff_id, OLD.valid_from, OLD.archived_at) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.valid_to IS NOT NULL AND NEW.valid_to IS DISTINCT FROM OLD.valid_to THEN RAISE EXCEPTION 'assignment_ended' USING ERRCODE = '23514'; END IF;
    -- Ended from yesterday at the earliest: what was taught stays attributed to whoever taught it.
    IF NEW.valid_to IS DISTINCT FROM OLD.valid_to AND NEW.valid_to < current_date - 1 THEN RAISE EXCEPTION 'assignment_end_past' USING ERRCODE = '23514'; END IF;
  END IF;
  IF NEW.valid_to < NEW.valid_from THEN RAISE EXCEPTION 'assignment_end_before_start' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.teaching_assignment FOR EACH ROW EXECUTE FUNCTION school.guard_teaching_assignment();

-- The rules that read beyond what the writer may see, on the assignment's first day: a group in
-- use, with a programme teaching the subject that day; a teacher still with the school, qualified
-- for the subject at the programme's level (FR-STF-02).
CREATE FUNCTION school.check_teaching_assignment() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE g school.study_group; s school.staff; node uuid;
BEGIN
  IF TG_OP = 'UPDATE' THEN RETURN NEW; END IF;
  SELECT * INTO g FROM school.study_group WHERE tenant_id = NEW.tenant_id AND id = NEW.group_id;
  IF g.archived_at IS NOT NULL OR g.status = 'closed' THEN RAISE EXCEPTION 'assignment_group_closed' USING ERRCODE = '23514'; END IF;
  IF g.program_id IS NULL OR NOT EXISTS (SELECT 1 FROM school.program_subject ps WHERE ps.tenant_id = NEW.tenant_id AND ps.program_id = g.program_id
       AND ps.subject_id = NEW.subject_id AND ps.archived_at IS NULL AND ps.valid_from <= NEW.valid_from AND (ps.valid_to IS NULL OR ps.valid_to >= NEW.valid_from)) THEN
    RAISE EXCEPTION 'assignment_subject_outside_program' USING ERRCODE = '23514';
  END IF;
  SELECT * INTO s FROM school.staff WHERE tenant_id = NEW.tenant_id AND id = NEW.staff_id;
  IF s.staff_type NOT IN ('teacher_internal', 'teacher_external') THEN RAISE EXCEPTION 'assignment_not_teacher' USING ERRCODE = '23514'; END IF;
  IF s.archived_at IS NOT NULL OR s.status = 'left' THEN RAISE EXCEPTION 'assignment_teacher_left' USING ERRCODE = '23514'; END IF;
  SELECT p.node_id INTO node FROM school.program p WHERE p.tenant_id = NEW.tenant_id AND p.id = g.program_id;
  IF NOT school.qualified(NEW.staff_id, NEW.subject_id, node, NEW.valid_from) THEN
    RAISE EXCEPTION 'assignment_teacher_not_qualified' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER rules BEFORE INSERT OR UPDATE ON school.teaching_assignment FOR EACH ROW EXECUTE FUNCTION school.check_teaching_assignment();

-- Whether the caller teaches a group's subject on a day: their own record, still with the school,
-- assigned that day. Lot 5's activities ask it before a teacher publishes or corrects.
CREATE FUNCTION school.teaches(p_group uuid, p_subject uuid, p_day date DEFAULT current_date) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM school.teaching_assignment a JOIN school.staff s ON s.tenant_id = a.tenant_id AND s.id = a.staff_id
    WHERE a.tenant_id = school.allowed_tenant() AND a.group_id = p_group AND a.subject_id = p_subject
      AND a.valid_from <= p_day AND (a.valid_to IS NULL OR a.valid_to >= p_day)
      AND s.user_id = school.actor() AND s.status <> 'left' AND s.archived_at IS NULL) $$;

-- The groups the caller learns in today: their own pupil record, active, placed now in the group
-- by an enrolment that grants access to courses (FR-INS-04). A suspended or cancelled enrolment,
-- a payment the school asks for and missing, a membership ended or a record blocked opens nothing.
CREATE FUNCTION school.learner_groups() RETURNS TABLE (group_id uuid, student_id uuid, enrollment_id uuid)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT m.group_id, s.id, e.id FROM school.student s
  JOIN school.enrollment e ON e.tenant_id = s.tenant_id AND e.student_id = s.id
  JOIN school.group_membership m ON m.tenant_id = e.tenant_id AND m.enrollment_id = e.id
    AND m.valid_from <= current_date AND (m.valid_to IS NULL OR m.valid_to > current_date)
  WHERE s.tenant_id = school.allowed_tenant() AND s.user_id = school.actor() AND s.status = 'active' AND s.archived_at IS NULL
    AND school.access_open(e.status, e.id) $$;

-- A teacher's personal grant reads the groups they are assigned to, as those they teach (063) and
-- lead (033): those of their assignments in force or to come. Replaces 063's.
CREATE OR REPLACE FUNCTION school.core_ids(p_permission text, p_entity text) RETURNS uuid[]
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); u bigint := school.actor(); r record;
  sites uuid[]; groups uuid[]; levels uuid[]; personal boolean := false; own uuid[]; scoped uuid[]; attached uuid[]; ids uuid[];
BEGIN
  IF t IS NULL THEN RETURN '{}'::uuid[]; END IF;
  FOR r IN SELECT e.scope_type, e.scope_ids FROM school.effective_permissions(u, t) e WHERE e.permission = p_permission LOOP
    CASE r.scope_type
      WHEN 'tenant' THEN RETURN '{}'::uuid[];
      WHEN 'site' THEN sites := sites || r.scope_ids;
      WHEN 'group' THEN groups := groups || r.scope_ids;
      WHEN 'level' THEN levels := levels || r.scope_ids;
      WHEN 'personal' THEN personal := true;
      ELSE NULL;
    END CASE;
  END LOOP;
  IF levels IS NOT NULL THEN
    WITH RECURSIVE tree(id) AS (
      SELECT unnest(levels)
      UNION SELECT n.id FROM school.ref_node n JOIN tree ON n.parent_id = tree.id WHERE n.tenant_id = t)
    SELECT array_agg(id) INTO levels FROM tree;
  END IF;
  IF personal THEN
    SELECT array_agg(x) INTO own FROM (
      SELECT s.id AS x FROM school.student s WHERE s.tenant_id = t AND s.user_id = u
      UNION SELECT l.student_id FROM school.student_guardian l JOIN school.guardian g ON g.tenant_id = l.tenant_id AND g.id = l.guardian_id
        WHERE l.tenant_id = t AND l.archived_at IS NULL AND g.archived_at IS NULL AND g.user_id = u) o;
    -- The groups the holder leads, from their own staff record, until they leave.
    SELECT groups || array_agg(g.id) INTO groups FROM school.staff s
      JOIN school.study_group g ON g.tenant_id = s.tenant_id AND g.lead_teacher_id = s.id
      WHERE s.tenant_id = t AND s.user_id = u AND s.status <> 'left' AND s.archived_at IS NULL;
    -- The groups they teach: those of their sessions not cancelled, from a month ago on.
    SELECT groups || array_agg(DISTINCT c.group_id) INTO groups FROM school.staff s
      JOIN school.class_session c ON c.tenant_id = s.tenant_id AND c.teacher_id = s.id AND c.status <> 'cancelled' AND c.ends_at > now() - interval '31 days'
      WHERE s.tenant_id = t AND s.user_id = u AND s.status <> 'left' AND s.archived_at IS NULL;
    -- And those they are assigned to, now or later.
    SELECT groups || array_agg(DISTINCT a.group_id) INTO groups FROM school.staff s
      JOIN school.teaching_assignment a ON a.tenant_id = s.tenant_id AND a.staff_id = s.id AND (a.valid_to IS NULL OR a.valid_to >= current_date)
      WHERE s.tenant_id = t AND s.user_id = u AND s.status <> 'left' AND s.archived_at IS NULL;
  END IF;
  IF sites IS NULL AND groups IS NULL AND levels IS NULL AND own IS NULL AND NOT (personal AND p_entity = 'guardian') THEN
    RETURN '{}'::uuid[];
  END IF;
  -- The pupils recorded on the scope's sites.
  IF sites IS NOT NULL AND p_entity IN ('student', 'guardian', 'enrollment', 'group_membership') THEN
    SELECT array_agg(s.id) INTO attached FROM school.student s WHERE s.tenant_id = t AND s.site_id = ANY (sites);
  END IF;

  IF p_entity IN ('student', 'guardian') THEN
    -- Pupils of the scope's sites, placed now in a group of the scope, or enrolled in a programme of its levels.
    SELECT array_agg(x) INTO scoped FROM (
      SELECT e.student_id AS x FROM school.study_group g
      JOIN school.group_membership m ON m.tenant_id = g.tenant_id AND m.group_id = g.id AND m.valid_to IS NULL
      JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id AND e.status <> 'cancelled'
      WHERE g.tenant_id = t AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT e.student_id FROM school.program p
      JOIN school.enrollment e ON e.tenant_id = p.tenant_id AND e.program_id = p.id AND e.status <> 'cancelled'
      WHERE p.tenant_id = t AND p.node_id = ANY (levels)
      UNION SELECT unnest(attached)) s;
    IF p_entity = 'student' THEN
      SELECT array_agg(DISTINCT x) INTO ids FROM (SELECT unnest(scoped) AS x UNION ALL SELECT unnest(own)) a;
    ELSE
      SELECT array_agg(DISTINCT x) INTO ids FROM (
        SELECT l.guardian_id AS x FROM school.student_guardian l
        WHERE l.tenant_id = t AND l.archived_at IS NULL AND (l.student_id = ANY (scoped) OR l.student_id = ANY (own))
        UNION SELECT h.id FROM school.guardian h WHERE h.tenant_id = t AND h.site_id = ANY (sites)
        UNION SELECT h.id FROM school.guardian h WHERE personal AND h.tenant_id = t AND h.user_id = u) a;
    END IF;
  ELSIF p_entity = 'program' THEN
    SELECT array_agg(DISTINCT x) INTO ids FROM (
      SELECT g.program_id AS x FROM school.study_group g
      WHERE g.tenant_id = t AND g.program_id IS NOT NULL AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT p.id FROM school.program p WHERE p.tenant_id = t AND p.node_id = ANY (levels)
      UNION SELECT e.program_id FROM school.enrollment e WHERE e.tenant_id = t AND e.student_id = ANY (own)) a;
  ELSIF p_entity = 'study_group' THEN
    SELECT array_agg(DISTINCT x) INTO ids FROM (
      SELECT g.id AS x FROM school.study_group g WHERE g.tenant_id = t AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT g.id FROM school.study_group g JOIN school.program p ON p.tenant_id = g.tenant_id AND p.id = g.program_id
        WHERE g.tenant_id = t AND p.node_id = ANY (levels)
      UNION SELECT m.group_id FROM school.group_membership m JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id
        WHERE m.tenant_id = t AND m.valid_to IS NULL AND e.student_id = ANY (own)) a;
  ELSIF p_entity = 'enrollment' THEN
    SELECT array_agg(DISTINCT x) INTO ids FROM (
      SELECT m.enrollment_id AS x FROM school.study_group g
      JOIN school.group_membership m ON m.tenant_id = g.tenant_id AND m.group_id = g.id AND m.valid_to IS NULL
      WHERE g.tenant_id = t AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT e.id FROM school.program p JOIN school.enrollment e ON e.tenant_id = p.tenant_id AND e.program_id = p.id
        WHERE p.tenant_id = t AND p.node_id = ANY (levels)
      UNION SELECT e.id FROM school.enrollment e WHERE e.tenant_id = t AND (e.student_id = ANY (own) OR e.student_id = ANY (attached))) a;
  ELSIF p_entity = 'group_membership' THEN
    SELECT array_agg(DISTINCT x) INTO ids FROM (
      SELECT m.id AS x FROM school.study_group g JOIN school.group_membership m ON m.tenant_id = g.tenant_id AND m.group_id = g.id
        WHERE g.tenant_id = t AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT m.id FROM school.program p
        JOIN school.enrollment e ON e.tenant_id = p.tenant_id AND e.program_id = p.id
        JOIN school.group_membership m ON m.tenant_id = e.tenant_id AND m.enrollment_id = e.id
        WHERE p.tenant_id = t AND p.node_id = ANY (levels)
      UNION SELECT m.id FROM school.enrollment e JOIN school.group_membership m ON m.tenant_id = e.tenant_id AND m.enrollment_id = e.id
        WHERE e.tenant_id = t AND (e.student_id = ANY (own) OR e.student_id = ANY (attached))) a;
  END IF;
  RETURN coalesce(ids, '{}'::uuid[]);
END $$;

-- Read with the groups (FR-IAM-04): the whole school, the group's site, the groups of the grant's
-- scope, or one's own; written within the scope of `lms.assignment.manage`.
CREATE POLICY resource_reader ON school.teaching_assignment AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('group.group.read'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('group.group.read', 'study_group'))))
  OR group_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'study_group'))))
  OR school.is_own_staff(staff_id));
CREATE POLICY resource_creator ON school.teaching_assignment AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('lms.assignment.manage'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('lms.assignment.manage', 'study_group')))));
CREATE POLICY resource_writer ON school.teaching_assignment AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('lms.assignment.manage'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('lms.assignment.manage', 'study_group')))));
CREATE POLICY resource_deleter ON school.teaching_assignment AS RESTRICTIVE FOR DELETE TO school_runtime USING (
  (SELECT school.can_everywhere('lms.assignment.manage'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('lms.assignment.manage', 'study_group')))));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('teaching_assignment', 'pedagogy', '{group.group.read,audit.log.read}');

-- The pupil's own account (role `student`, scope `personal`), linked by whoever changes the pupil's
-- record: never one's own (as nobody assigns themselves a role, FR-IAM-05), nor a revoked
-- membership's, nor a member of staff's: an account is one person's.
CREATE FUNCTION school.guard_student_account() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.user_id IS NULL OR NEW.user_id IS NOT DISTINCT FROM (CASE WHEN TG_OP = 'UPDATE' THEN OLD.user_id END) THEN RETURN NEW; END IF;
  IF NOT school.is_system() AND NEW.user_id = school.actor() THEN RAISE EXCEPTION 'self_account' USING ERRCODE = '23514'; END IF;
  IF EXISTS (SELECT 1 FROM school.membership WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id AND status = 'revoked') THEN
    RAISE EXCEPTION 'membership_revoked' USING ERRCODE = '23514';
  END IF;
  IF school.staff_account(NEW.user_id) THEN RAISE EXCEPTION 'pupil_account_staff' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard_account BEFORE INSERT OR UPDATE OF user_id ON school.student FOR EACH ROW EXECUTE FUNCTION school.guard_student_account();

-- Whether an account is a member of staff's, whatever records the writer may read.
CREATE FUNCTION school.staff_account(p_user bigint) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM school.staff WHERE tenant_id = school.allowed_tenant() AND user_id = p_user) $$;

-- A pupil's account is linked once: the unique key says so, whatever the writer may read.
CREATE FUNCTION school.check_student_account() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NEW.user_id IS NOT NULL AND EXISTS (SELECT 1 FROM school.student WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id AND id <> NEW.id) THEN
    RAISE EXCEPTION 'pupil_account_linked' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER rules_account BEFORE INSERT OR UPDATE OF user_id ON school.student FOR EACH ROW EXECUTE FUNCTION school.check_student_account();
