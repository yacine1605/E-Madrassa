-- Lot 4, the operational dashboard (SRS 1.2, FR-TDB-02; FR-TDB-03, BR-20): a group's fill (its
-- members now over its capacity, counted as its composition list counts them: AC1), and over a
-- period its sessions held, their teacher's attendance entered and validated, those entered by
-- no one, their roll calls taken and the pupils' absences; for the whole school, the enrolments
-- waiting and the prospects converted. Each figure computed again on each reading, from the rows.
-- Scoped by role (« un enseignant voit ses groupes, le surveillant la présence »): the groups of
-- the reader's `dashboard.operations.read`, the whole school's, its sites', levels', groups', a
-- teacher's personal grant their own (063); the fill also within their `group.group.read`; the
-- enrolments and prospects for whoever holds both grants for the whole school. The figures of a
-- module the school does not use do not appear.

-- Who reads it: besides the front office and the supervisors (006), the direction, the academic
-- head and the teachers, within their grant's scope.
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES
  ('direction', 'dashboard.operations.read'), ('academic_hr', 'dashboard.operations.read'), ('teacher', 'dashboard.operations.read');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, 'dashboard.operations.read' FROM school.role r
WHERE r.template_code IN ('direction', 'academic_hr', 'teacher') AND platform.tenant_mode(r.tenant_id) = 'active'
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = 'dashboard.operations.read');

-- The groups a permission opens to the reader: all the school's for a grant of the whole school,
-- else those of its scopes (core_ids, 063).
CREATE FUNCTION school.dashboard_group_ids(p_permission text) RETURNS uuid[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT CASE WHEN school.can_everywhere(p_permission)
    THEN (SELECT coalesce(array_agg(g.id), '{}'::uuid[]) FROM school.study_group g WHERE g.tenant_id = school.allowed_tenant())
    ELSE school.core_ids(p_permission, 'study_group') END $$;

-- The figures, one row each: a group's (`group_id`) or the school's (none). Fill: `capacity` and
-- `members` of each open group. Over the period's days: `sessions_held` (begun, not cancelled),
-- `teacher_entered`, `teacher_validated`, `teacher_missing` (held, entered by no one),
-- `roll_taken`, `roll_missing`, the pupils' `marks`, `absent`, `late`, `excused`. The school's:
-- `enrollments_pending` now, `leads_created`, `leads_registered`, `leads_lost` of the period.
CREATE FUNCTION school.operations_dashboard(p_from date, p_to date)
RETURNS TABLE (group_id uuid, indicator text, value integer)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant(); scope uuid[]; fill uuid[]; attendance boolean := platform.module_enabled(t, 'attendance');
BEGIN
  IF t IS NULL OR NOT school.can('dashboard.operations.read') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF p_from IS NULL OR p_to IS NULL OR p_to < p_from OR p_to - p_from > 400 THEN RAISE EXCEPTION 'dashboard_range_invalid' USING ERRCODE = '23514'; END IF;
  scope := school.dashboard_group_ids('dashboard.operations.read');
  -- AC1: the members as the composition list and the capacity rule count them.
  IF platform.module_enabled(t, 'enrollment_groups') AND school.can('group.group.read') THEN
    fill := ARRAY(SELECT unnest(scope) INTERSECT SELECT unnest(school.dashboard_group_ids('group.group.read')));
    RETURN QUERY SELECT g.id, 'capacity'::text, g.capacity FROM school.study_group g
      WHERE g.tenant_id = t AND g.id = ANY (fill) AND g.status = 'active' AND g.archived_at IS NULL;
    RETURN QUERY SELECT g.id, 'members'::text, (SELECT count(*)::integer FROM school.group_membership m
        WHERE m.tenant_id = g.tenant_id AND m.group_id = g.id AND m.valid_to IS NULL)
      FROM school.study_group g WHERE g.tenant_id = t AND g.id = ANY (fill) AND g.status = 'active' AND g.archived_at IS NULL;
  END IF;
  IF platform.module_enabled(t, 'scheduling_manual') THEN
    RETURN QUERY WITH held AS (
        SELECT c.group_id, a.validation_status AS teacher, EXISTS (SELECT 1 FROM school.student_attendance s
          WHERE s.tenant_id = t AND s.session_id = c.id AND s.archived_at IS NULL) AS roll
        FROM school.class_session c LEFT JOIN school.teacher_attendance a ON a.tenant_id = t AND a.session_id = c.id
        WHERE c.tenant_id = t AND c.group_id = ANY (scope) AND c.status <> 'cancelled' AND c.starts_at <= now()
          AND school.local_day(c.starts_at) BETWEEN p_from AND p_to)
      SELECT h.group_id, x.indicator, x.value FROM (SELECT held.group_id, count(*)::integer AS held,
          count(*) FILTER (WHERE held.teacher IS NOT NULL)::integer AS entered, count(*) FILTER (WHERE held.teacher = 'validated')::integer AS validated,
          count(*) FILTER (WHERE held.roll)::integer AS roll FROM held GROUP BY 1) h
      CROSS JOIN LATERAL (VALUES ('sessions_held', h.held), ('teacher_entered', CASE WHEN attendance THEN h.entered END),
        ('teacher_validated', CASE WHEN attendance THEN h.validated END), ('teacher_missing', CASE WHEN attendance THEN h.held - h.entered END),
        ('roll_taken', CASE WHEN attendance THEN h.roll END), ('roll_missing', CASE WHEN attendance THEN h.held - h.roll END)) x (indicator, value)
      WHERE x.value IS NOT NULL;
    IF attendance THEN
      RETURN QUERY SELECT m.group_id, x.indicator, x.value FROM (SELECT c.group_id, count(*)::integer AS marks,
          count(*) FILTER (WHERE s.status = 'absent')::integer AS absent, count(*) FILTER (WHERE s.status = 'late')::integer AS late,
          count(*) FILTER (WHERE s.status = 'excused')::integer AS excused
        FROM school.class_session c JOIN school.student_attendance s ON s.tenant_id = c.tenant_id AND s.session_id = c.id AND s.archived_at IS NULL
        WHERE c.tenant_id = t AND c.group_id = ANY (scope) AND c.status <> 'cancelled' AND c.starts_at <= now()
          AND school.local_day(c.starts_at) BETWEEN p_from AND p_to GROUP BY 1) m
      CROSS JOIN LATERAL (VALUES ('marks', m.marks), ('absent', m.absent), ('late', m.late), ('excused', m.excused)) x (indicator, value);
    END IF;
  END IF;
  IF school.can_everywhere('dashboard.operations.read') THEN
    IF platform.module_enabled(t, 'enrollment_groups') AND school.can_everywhere('enrollment.enrollment.read') THEN
      RETURN QUERY SELECT NULL::uuid, 'enrollments_pending'::text, count(*)::integer FROM school.enrollment e
        WHERE e.tenant_id = t AND e.status = 'pending' AND e.archived_at IS NULL;
    END IF;
    IF platform.module_enabled(t, 'students_crm') AND school.can_everywhere('crm.lead.read') THEN
      RETURN QUERY SELECT NULL::uuid, x.indicator, x.value FROM (SELECT count(*)::integer AS created,
          count(*) FILTER (WHERE l.status = 'registered')::integer AS registered, count(*) FILTER (WHERE l.status = 'lost')::integer AS lost
        FROM school.lead l WHERE l.tenant_id = t AND school.local_day(l.created_at) BETWEEN p_from AND p_to) a
      CROSS JOIN LATERAL (VALUES ('leads_created', a.created), ('leads_registered', a.registered), ('leads_lost', a.lost)) x (indicator, value);
    END IF;
  END IF;
END $$;

-- FR-TDB-03: a group's sessions held in the period behind its figures, each with its teacher's
-- attendance and its roll call as they stand, for whoever reads the group's figures.
CREATE FUNCTION school.operations_sessions(p_group uuid, p_from date, p_to date)
RETURNS TABLE (session_id uuid, teacher_status text, roll_marks integer, absent integer)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant();
BEGIN
  IF t IS NULL OR NOT school.can('dashboard.operations.read') OR NOT p_group = ANY (school.dashboard_group_ids('dashboard.operations.read')) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF p_from IS NULL OR p_to IS NULL OR p_to < p_from OR p_to - p_from > 400 THEN RAISE EXCEPTION 'dashboard_range_invalid' USING ERRCODE = '23514'; END IF;
  RETURN QUERY SELECT c.id, CASE WHEN platform.module_enabled(t, 'attendance') THEN coalesce(a.validation_status, 'not_entered') END,
      (SELECT count(*)::integer FROM school.student_attendance s WHERE s.tenant_id = t AND s.session_id = c.id AND s.archived_at IS NULL),
      (SELECT count(*)::integer FROM school.student_attendance s WHERE s.tenant_id = t AND s.session_id = c.id AND s.archived_at IS NULL AND s.status = 'absent')
    FROM school.class_session c LEFT JOIN school.teacher_attendance a ON a.tenant_id = t AND a.session_id = c.id
    WHERE c.tenant_id = t AND c.group_id = p_group AND c.status <> 'cancelled' AND c.starts_at <= now()
      AND school.local_day(c.starts_at) BETWEEN p_from AND p_to;
END $$;
