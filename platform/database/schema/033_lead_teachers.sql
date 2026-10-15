-- Lot 2, a group's lead teacher and what it opens to them (SRS 1.2, FR-GRP-01, FR-IAM-04, TST-03):
-- the teacher in charge of a class, a support or option group or a training session; a teacher's
-- personal grant then reads the groups they lead, with their members, pupils and programmes, as
-- a grant on these groups would (AC1: no group they neither lead nor replace in). A replacement's
-- groups come with the sessions of lot 4.

-- FR-GRP-01: optional, one per group; changed while the group is open, as its other fields.
ALTER TABLE school.study_group ADD COLUMN lead_teacher_id uuid,
  ADD FOREIGN KEY (tenant_id, lead_teacher_id) REFERENCES school.staff (tenant_id, id);
CREATE INDEX study_group_lead_teacher ON school.study_group (tenant_id, lead_teacher_id) WHERE lead_teacher_id IS NOT NULL;

-- Who names a lead teacher reads their record (`staff.profile.read` within its scope): a grant
-- limited to sites is told nothing of the others' staff. Removing one needs the group's grant only.
CREATE FUNCTION school.guard_lead_teacher() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.lead_teacher_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.lead_teacher_id IS DISTINCT FROM OLD.lead_teacher_id) THEN
    PERFORM school.require_staff('staff.profile.read', NEW.lead_teacher_id);
  END IF;
  RETURN NEW;
END $$;
-- After `guard`: the group's permission first.
CREATE TRIGGER lead_teacher BEFORE INSERT OR UPDATE OF lead_teacher_id ON school.study_group
  FOR EACH ROW EXECUTE FUNCTION school.guard_lead_teacher();

-- A teacher, internal or external, still with the school (FR-STF-01). Who leaves later stays the
-- group's lead teacher in its history, without the access it gave.
CREATE FUNCTION school.check_lead_teacher() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE s school.staff;
BEGIN
  IF NEW.lead_teacher_id IS NULL OR (TG_OP = 'UPDATE' AND NEW.lead_teacher_id IS NOT DISTINCT FROM OLD.lead_teacher_id) THEN RETURN NEW; END IF;
  SELECT * INTO s FROM school.staff WHERE tenant_id = NEW.tenant_id AND id = NEW.lead_teacher_id;
  -- An unknown member is the foreign key's to refuse.
  IF s.id IS NULL THEN RETURN NEW; END IF;
  IF s.archived_at IS NOT NULL THEN RAISE EXCEPTION 'staff_archived' USING ERRCODE = '23514'; END IF;
  IF s.status = 'left' THEN RAISE EXCEPTION 'staff_left' USING ERRCODE = '23514'; END IF;
  IF s.staff_type NOT IN ('teacher_internal', 'teacher_external') THEN RAISE EXCEPTION 'lead_teacher_not_teacher' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER lead_teacher_rules BEFORE INSERT OR UPDATE OF lead_teacher_id ON school.study_group
  FOR EACH ROW EXECUTE FUNCTION school.check_lead_teacher();

-- 029's rules, and a personal grant's groups: those its holder leads while with the school, read
-- as a grant on them (FR-IAM-04: « un enseignant voit ses groupes »). Replaces 029's.
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

-- Section 3.3: a teacher reads, within their own scope (P), the records of their pupils and of
-- these pupils' guardians, and their own staff record. A role assigned with the personal scope
-- reaches their groups' pupils only; the school's copies of the role receive them as the
-- template does, as 015 did for its permission.
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES
  ('teacher', 'student.record.read'), ('teacher', 'guardian.record.read'), ('teacher', 'staff.profile.read');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, p.code FROM school.role r
CROSS JOIN (VALUES ('student.record.read'), ('guardian.record.read'), ('staff.profile.read')) p(code)
WHERE r.template_code = 'teacher' AND platform.tenant_mode(r.tenant_id) = 'active'
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = p.code);
