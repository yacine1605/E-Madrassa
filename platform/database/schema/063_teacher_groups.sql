-- Lot 4, the planning as a teacher reads it (SRS 1.2, FR-PLN-04, FR-IAM-04): a teacher's personal
-- grant reads the groups they teach, as it reads those they lead (033): the groups of their
-- sessions not cancelled, from a month ago on, with their members and pupils (« un enseignant
-- voit ses groupes », « un enseignant [voit] le sien »). A session they no longer teach (another
-- teacher, cancelled) or a month gone opens nothing more.

-- 033's rules, and the groups of the holder's own sessions. Replaces 033's.
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
