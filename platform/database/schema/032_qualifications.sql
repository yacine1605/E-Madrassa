-- Lot 2, a teacher's qualifications (SRS 1.2, FR-STF-02): the subjects of the catalogue a teacher
-- may teach, each at a node of the referential, a level (1AS), a cycle (Primaire) or a specialty
-- (Data Science), from `valid_from` until `valid_to` (included). A qualification at a node covers
-- its descendants: computed when asked, never copied (AC2: Primaire covers 3AP). Lot 4's sessions
-- will take only a teacher qualified for their subject and their group's level on their day
-- (AC1), and keep a qualification they used from being deleted: it is ended instead.
CREATE TABLE school.qualification (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  staff_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  node_id uuid NOT NULL,
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
  FOREIGN KEY (tenant_id, staff_id) REFERENCES school.staff (tenant_id, id),
  FOREIGN KEY (tenant_id, subject_id) REFERENCES school.subject (tenant_id, id),
  FOREIGN KEY (tenant_id, node_id) REFERENCES school.ref_node (tenant_id, id),
  CONSTRAINT qualification_once EXCLUDE USING gist (tenant_id WITH =, staff_id WITH =, subject_id WITH =, node_id WITH =,
    daterange(valid_from, valid_to, '[]') WITH &&)
);
CREATE INDEX qualification_subject ON school.qualification (tenant_id, subject_id);
CALL school.protect('school.qualification');

-- Who changes a teacher's qualifications: `staff.qualification.manage`, within its scope (the
-- teacher's site, or the whole school). A line keeps its teacher, subject, node and first day; its
-- end is final, the qualification is given again for a later period.
CREATE FUNCTION school.guard_qualification() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require('staff.qualification.manage');
  PERFORM school.require_staff('staff.qualification.manage', CASE WHEN TG_OP = 'INSERT' THEN NEW.staff_id ELSE OLD.staff_id END);
  IF TG_OP = 'UPDATE' THEN
    IF (NEW.staff_id, NEW.subject_id, NEW.node_id, NEW.valid_from, NEW.archived_at) IS DISTINCT FROM
       (OLD.staff_id, OLD.subject_id, OLD.node_id, OLD.valid_from, OLD.archived_at) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.valid_to IS NOT NULL THEN RAISE EXCEPTION 'qualification_ended' USING ERRCODE = '23514'; END IF;
  END IF;
  IF TG_OP = 'INSERT' THEN NEW.valid_from := coalesce(NEW.valid_from, current_date); NEW.archived_at := NULL; END IF;
  IF TG_OP <> 'DELETE' AND NEW.valid_to < NEW.valid_from THEN RAISE EXCEPTION 'qualification_end_before_start' USING ERRCODE = '23514'; END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.qualification FOR EACH ROW EXECUTE FUNCTION school.guard_qualification();

-- The rules that read beyond what the writer may see: a teacher still with the school (a member
-- who left is no longer qualified anew, FR-STF-01), the subject and the node still in use, the
-- lines already there; an archived record's qualifications no longer change.
CREATE FUNCTION school.check_qualification() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE q school.qualification := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; s school.staff;
BEGIN
  SELECT * INTO s FROM school.staff WHERE tenant_id = q.tenant_id AND id = q.staff_id;
  -- An unknown teacher is the foreign key's to refuse.
  IF s.id IS NULL THEN RETURN q; END IF;
  IF s.archived_at IS NOT NULL THEN RAISE EXCEPTION 'staff_archived' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'INSERT' THEN
    IF s.staff_type NOT IN ('teacher_internal', 'teacher_external') THEN RAISE EXCEPTION 'qualification_not_teacher' USING ERRCODE = '23514'; END IF;
    IF s.status = 'left' THEN RAISE EXCEPTION 'staff_left' USING ERRCODE = '23514'; END IF;
    IF EXISTS (SELECT 1 FROM school.subject WHERE tenant_id = q.tenant_id AND id = q.subject_id AND archived_at IS NOT NULL) THEN
      RAISE EXCEPTION 'subject_archived' USING ERRCODE = '23514';
    END IF;
    IF EXISTS (SELECT 1 FROM school.ref_node WHERE tenant_id = q.tenant_id AND id = q.node_id AND archived_at IS NOT NULL) THEN
      RAISE EXCEPTION 'node_archived' USING ERRCODE = '23514';
    END IF;
    -- The same qualification given twice at once: the second waits for the first, then finds it.
    PERFORM pg_advisory_xact_lock(hashtextextended(q.staff_id::text || q.subject_id::text || q.node_id::text, 32));
    IF EXISTS (SELECT 1 FROM school.qualification x WHERE x.tenant_id = q.tenant_id AND x.staff_id = q.staff_id AND x.subject_id = q.subject_id
               AND x.node_id = q.node_id AND daterange(x.valid_from, x.valid_to, '[]') && daterange(q.valid_from, q.valid_to, '[]')) THEN
      RAISE EXCEPTION 'qualification_overlap' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN q;
END $$;
-- After `guard`: the permission first.
CREATE TRIGGER rules BEFORE INSERT OR UPDATE OR DELETE ON school.qualification FOR EACH ROW EXECUTE FUNCTION school.check_qualification();

-- FR-STF-02 AC2: whether a member of staff may teach a subject at a node of the referential on a
-- day: a qualification for that subject at the node or one of its ancestors, valid that day. With
-- the caller's rights: what they may read of the qualifications (the rules of lot 4 read them all).
CREATE FUNCTION school.qualified(p_staff uuid, p_subject uuid, p_node uuid, p_day date DEFAULT current_date) RETURNS boolean
LANGUAGE sql STABLE AS $$
  WITH RECURSIVE up(id) AS (
    SELECT p_node
    UNION SELECT n.parent_id FROM school.ref_node n JOIN up ON n.id = up.id WHERE n.tenant_id = school.tenant() AND n.parent_id IS NOT NULL)
  SELECT EXISTS (SELECT 1 FROM school.qualification q WHERE q.tenant_id = school.tenant() AND q.staff_id = p_staff AND q.subject_id = p_subject
    AND q.node_id IN (SELECT id FROM up) AND q.valid_from <= p_day AND (q.valid_to IS NULL OR q.valid_to >= p_day)) $$;

-- Read with the teacher's record (FR-IAM-04): the whole school, the record's site, one's own;
-- changed, and deleted while nothing uses them, within the scope of `staff.qualification.manage`.
CREATE POLICY resource_reader ON school.qualification AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('staff.profile.read')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.profile.read')))));
CREATE POLICY resource_creator ON school.qualification AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('staff.qualification.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.qualification.manage')))));
CREATE POLICY resource_writer ON school.qualification AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('staff.qualification.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.qualification.manage')))))
  WITH CHECK ((SELECT school.can_everywhere('staff.qualification.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.qualification.manage')))));
CREATE POLICY resource_deleter ON school.qualification AS RESTRICTIVE FOR DELETE TO school_runtime USING (
  (SELECT school.can_everywhere('staff.qualification.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.qualification.manage')))));

-- The journal: in the staff's domain, read with the records; a scoped reader reads the entries of
-- the qualifications they can see. Replaces 031's.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('qualification', 'staff', '{staff.profile.read}');
ALTER POLICY core_resource_reader ON school.audit_log USING (
  object_type NOT IN ('student', 'guardian', 'student_guardian', 'program', 'program_subject', 'study_group', 'enrollment', 'group_membership', 'staff',
    'qualification') OR
  (SELECT school.can_everywhere('audit.log.read')) OR
  (object_type = 'student' AND ((SELECT school.can_everywhere('student.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('student.record.read', 'student')))))) OR
  (object_type = 'guardian' AND ((SELECT school.can_everywhere('guardian.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.read', 'guardian')))))) OR
  (object_type = 'student_guardian' AND (SELECT school.can_everywhere('guardian.record.read'))) OR
  (object_type = 'program' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('program.program.read', 'program')))))) OR
  (object_type = 'program_subject' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id IN (SELECT s.id FROM school.program_subject s))) OR
  (object_type = 'study_group' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'study_group')))))) OR
  (object_type = 'enrollment' AND ((SELECT school.can_everywhere('enrollment.enrollment.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment')))))) OR
  (object_type = 'group_membership' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'group_membership')))))) OR
  (object_type = 'staff' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT s.id FROM school.staff s))) OR
  (object_type = 'qualification' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT q.id FROM school.qualification q))));
