-- What a restricted scope costs (NFR-PERF-01/03), measured by tests/sql/scope-cost.mjs on a
-- school of 2 000 pupils. A reader limited to a site, a level, some groups or their own record
-- paid 18 to 27 ms per list against 4 to 6 for a reader of the whole school, and the gap grew with the
-- school, not with the scope: each policy recomputed the reader's permissions twice, then joined
-- every pupil, enrolment, membership and group of the school before keeping those in scope.
-- The rules of 021 stay the same; only the way they are computed changes.

-- The union of the user's roles, unchanged since 019. In PL/pgSQL, whose plans are kept from
-- one call to the next within a connection, where SQL functions plan their query on each call:
-- 0.8 ms instead of 1.8, on every permission check of every request.
CREATE OR REPLACE FUNCTION school.effective_permissions(p_user bigint, p_tenant uuid)
RETURNS TABLE (permission text, scope_type text, scope_ids uuid[])
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
BEGIN
  RETURN QUERY
  SELECT rp.permission_code, a.scope_type, a.scope_ids
  FROM school.role_assignment a
  JOIN school.membership m ON m.tenant_id = a.tenant_id AND m.user_id = a.user_id AND m.status = 'active'
  JOIN school.role r ON r.tenant_id = a.tenant_id AND r.id = a.role_id AND r.archived_at IS NULL
  JOIN school.role_permission rp ON rp.tenant_id = a.tenant_id AND rp.role_id = a.role_id
  JOIN platform.permission p ON p.code = rp.permission_code
  JOIN platform.tenant_module tm ON tm.tenant_id = a.tenant_id AND tm.module_key = p.module_key
  WHERE a.tenant_id = p_tenant AND a.user_id = p_user AND a.archived_at IS NULL
    AND a.valid_from <= current_date AND (a.valid_until IS NULL OR a.valid_until >= current_date)
  UNION ALL
  SELECT p.code, 'tenant'::text, NULL::uuid[]
  FROM platform.permission p
  JOIN platform.tenant_module tm ON tm.tenant_id = p_tenant AND tm.module_key = p.module_key
  JOIN school.support_grant g ON g.tenant_id = p_tenant AND g.id = (SELECT school.support_grant_in_use())
  WHERE p_user = (SELECT school.actor()) AND p_tenant = (SELECT school.tenant()) AND p.code LIKE '%.read'
    AND (NOT p.sensitive OR p.code = 'audit.log.read' OR g.include_sensitive);
END $$;

-- The IDs an action's scope opens, from the scope rather than from the whole school: the groups,
-- sites, levels (with their descendants) and the reader's own pupils, then only what they reach.
-- Same rules as 021's: a site or a group opens the pupils and enrolments placed there now and the
-- programmes taught there; a level opens what its programmes hold; a personal scope opens the
-- reader's own record, or their children's, and what these are enrolled in. A school-wide scope
-- needs no list (the policies read can_everywhere first); a subject scope stays closed until the
-- programmes have their subjects. Replaces 021's.
CREATE OR REPLACE FUNCTION school.core_ids(p_permission text, p_entity text) RETURNS uuid[]
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); u bigint := school.actor(); r record;
  sites uuid[]; groups uuid[]; levels uuid[]; personal boolean := false; own uuid[]; scoped uuid[]; ids uuid[];
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
  END IF;
  IF sites IS NULL AND groups IS NULL AND levels IS NULL AND own IS NULL AND NOT (personal AND p_entity = 'guardian') THEN
    RETURN '{}'::uuid[];
  END IF;

  IF p_entity IN ('student', 'guardian') THEN
    -- Pupils placed now in a group of the scope, or enrolled in a programme of its levels.
    SELECT array_agg(x) INTO scoped FROM (
      SELECT e.student_id AS x FROM school.study_group g
      JOIN school.group_membership m ON m.tenant_id = g.tenant_id AND m.group_id = g.id AND m.valid_to IS NULL
      JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id AND e.status <> 'cancelled'
      WHERE g.tenant_id = t AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT e.student_id FROM school.program p
      JOIN school.enrollment e ON e.tenant_id = p.tenant_id AND e.program_id = p.id AND e.status <> 'cancelled'
      WHERE p.tenant_id = t AND p.node_id = ANY (levels)) s;
    IF p_entity = 'student' THEN
      SELECT array_agg(DISTINCT x) INTO ids FROM (SELECT unnest(scoped) AS x UNION ALL SELECT unnest(own)) a;
    ELSE
      SELECT array_agg(DISTINCT x) INTO ids FROM (
        SELECT l.guardian_id AS x FROM school.student_guardian l
        WHERE l.tenant_id = t AND l.archived_at IS NULL AND (l.student_id = ANY (scoped) OR l.student_id = ANY (own))
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
      UNION SELECT e.id FROM school.enrollment e WHERE e.tenant_id = t AND e.student_id = ANY (own)) a;
  ELSIF p_entity = 'group_membership' THEN
    SELECT array_agg(DISTINCT x) INTO ids FROM (
      SELECT m.id AS x FROM school.study_group g JOIN school.group_membership m ON m.tenant_id = g.tenant_id AND m.group_id = g.id
        WHERE g.tenant_id = t AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT m.id FROM school.program p
        JOIN school.enrollment e ON e.tenant_id = p.tenant_id AND e.program_id = p.id
        JOIN school.group_membership m ON m.tenant_id = e.tenant_id AND m.enrollment_id = e.id
        WHERE p.tenant_id = t AND p.node_id = ANY (levels)
      UNION SELECT m.id FROM school.enrollment e JOIN school.group_membership m ON m.tenant_id = e.tenant_id AND m.enrollment_id = e.id
        WHERE e.tenant_id = t AND e.student_id = ANY (own)) a;
  END IF;
  RETURN coalesce(ids, '{}'::uuid[]);
END $$;

-- The lookups above, by index: a site's groups, a level's programmes, a pupil's enrolments of
-- any state, a group's memberships of any date.
CREATE INDEX study_group_site ON school.study_group (tenant_id, site_id);
CREATE INDEX program_node ON school.program (tenant_id, node_id);
CREATE INDEX enrollment_student ON school.enrollment (tenant_id, student_id);
CREATE INDEX group_membership_all ON school.group_membership (tenant_id, group_id);

-- Each row is then compared with the scope's IDs through a hash built once per query, where
-- `id = ANY (array)` compared it with each ID in turn: for the 5 000 pupils of one site among
-- 10 000, 61 ms instead of 2. Same rules as 021's policies, which these replace.
DO $$ DECLARE spec record; action text; read_rule text; write_rule text; BEGIN
  FOR spec IN SELECT * FROM (VALUES
    ('student', 'student.record.read', ARRAY['student.record.update', 'student.record.archive']),
    ('guardian', 'guardian.record.read', ARRAY['guardian.record.update']),
    ('program', 'program.program.read', ARRAY['program.program.manage']),
    ('study_group', 'group.group.read', ARRAY['group.group.manage']),
    ('enrollment', 'enrollment.enrollment.read', ARRAY['enrollment.enrollment.update',
      'enrollment.enrollment.validate', 'enrollment.enrollment.suspend', 'enrollment.enrollment.cancel']),
    ('group_membership', 'group.group.read', ARRAY['group.membership.manage'])
  ) AS s(tbl, reader, writers) LOOP
    read_rule := format('(SELECT school.can_everywhere(%L)) OR id IN (SELECT unnest((SELECT school.core_ids(%L, %L))))',
      spec.reader, spec.reader, spec.tbl);
    EXECUTE format('ALTER POLICY resource_reader ON school.%I USING (%s)', spec.tbl, read_rule);
    write_rule := 'false';
    FOREACH action IN ARRAY spec.writers LOOP
      write_rule := write_rule || format(' OR (SELECT school.can_everywhere(%L)) OR id IN (SELECT unnest((SELECT school.core_ids(%L, %L))))',
        action, action, spec.tbl);
    END LOOP;
    EXECUTE format('ALTER POLICY resource_writer ON school.%I USING (%s) WITH CHECK (%s)', spec.tbl, write_rule, write_rule);
  END LOOP;
END $$;
ALTER POLICY resource_reader ON school.student_guardian USING (
  (SELECT school.can_everywhere('guardian.record.read')) OR
  (student_id IN (SELECT unnest((SELECT school.core_ids('student.record.read', 'student'))))
   AND guardian_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.read', 'guardian'))))));
ALTER POLICY resource_reader ON school.program_price USING (
  (SELECT school.can_everywhere('program.program.read')) OR program_id IN (SELECT unnest((SELECT school.core_ids('program.program.read', 'program')))));
ALTER POLICY resource_reader ON school.enrollment_status_history USING (
  (SELECT school.can_everywhere('enrollment.enrollment.read')) OR enrollment_id IN (SELECT unnest((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment')))));
ALTER POLICY core_resource_reader ON school.audit_log USING (
  object_type NOT IN ('student', 'guardian', 'student_guardian', 'program', 'study_group', 'enrollment', 'group_membership') OR
  (SELECT school.can_everywhere('audit.log.read')) OR
  (object_type = 'student' AND ((SELECT school.can_everywhere('student.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('student.record.read', 'student')))))) OR
  (object_type = 'guardian' AND ((SELECT school.can_everywhere('guardian.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.read', 'guardian')))))) OR
  (object_type = 'student_guardian' AND (SELECT school.can_everywhere('guardian.record.read'))) OR
  (object_type = 'program' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('program.program.read', 'program')))))) OR
  (object_type = 'study_group' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'study_group')))))) OR
  (object_type = 'enrollment' AND ((SELECT school.can_everywhere('enrollment.enrollment.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment')))))) OR
  (object_type = 'group_membership' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'group_membership')))))));
