-- Lot 4, absences per pupil and their trend (SRS 1.2, FR-TDB-02; FR-TDB-03): over a period, a
-- group's pupils with their roll-call marks, absences, delays and excuses and the day of their
-- last absence; and week by week, the sessions held, marks and absences of the reader's groups, or
-- of one of them, to read the trend. Counted from the rows on each reading, as 070's figures, for
-- whoever reads the group's figures; the pupils named by the reader only where they read their
-- record (029, 063), the function giving no name.

-- A group's pupils marked over the period, with their tallies.
CREATE FUNCTION school.operations_pupils(p_group uuid, p_from date, p_to date)
RETURNS TABLE (student_id uuid, marks integer, absent integer, late integer, excused integer, last_absence date)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant();
BEGIN
  IF t IS NULL OR NOT school.can('dashboard.operations.read') OR NOT p_group = ANY (school.dashboard_group_ids('dashboard.operations.read')) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF p_from IS NULL OR p_to IS NULL OR p_to < p_from OR p_to - p_from > 400 THEN RAISE EXCEPTION 'dashboard_range_invalid' USING ERRCODE = '23514'; END IF;
  IF NOT platform.module_enabled(t, 'scheduling_manual') OR NOT platform.module_enabled(t, 'attendance') THEN RETURN; END IF;
  RETURN QUERY SELECT s.student_id, count(*)::integer, count(*) FILTER (WHERE s.status = 'absent')::integer,
      count(*) FILTER (WHERE s.status = 'late')::integer, count(*) FILTER (WHERE s.status = 'excused')::integer,
      max(school.local_day(c.starts_at)) FILTER (WHERE s.status = 'absent')
    FROM school.class_session c JOIN school.student_attendance s ON s.tenant_id = c.tenant_id AND s.session_id = c.id AND s.archived_at IS NULL
    WHERE c.tenant_id = t AND c.group_id = p_group AND c.status <> 'cancelled' AND c.starts_at <= now()
      AND school.local_day(c.starts_at) BETWEEN p_from AND p_to
    GROUP BY s.student_id;
END $$;

-- The trend: a row per week of the period, weeks beginning on Sunday as the school's, the first
-- dated from the period's start; every week, even one without a session. The reader's groups
-- (`p_group` none) or one of them.
CREATE FUNCTION school.operations_weeks(p_group uuid, p_from date, p_to date)
RETURNS TABLE (week date, sessions_held integer, marks integer, absent integer, late integer, excused integer)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant(); scope uuid[]; first date;
BEGIN
  IF t IS NULL OR NOT school.can('dashboard.operations.read') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  scope := school.dashboard_group_ids('dashboard.operations.read');
  IF p_group IS NOT NULL THEN
    IF NOT p_group = ANY (scope) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    scope := ARRAY[p_group];
  END IF;
  IF p_from IS NULL OR p_to IS NULL OR p_to < p_from OR p_to - p_from > 400 THEN RAISE EXCEPTION 'dashboard_range_invalid' USING ERRCODE = '23514'; END IF;
  IF NOT platform.module_enabled(t, 'scheduling_manual') OR NOT platform.module_enabled(t, 'attendance') THEN RETURN; END IF;
  first := p_from - extract(dow FROM p_from)::integer;
  RETURN QUERY WITH held AS (
      SELECT c.id, school.local_day(c.starts_at) AS day FROM school.class_session c
      WHERE c.tenant_id = t AND c.group_id = ANY (scope) AND c.status <> 'cancelled' AND c.starts_at <= now()
        AND school.local_day(c.starts_at) BETWEEN p_from AND p_to),
    weeks AS (SELECT first + 7 * k AS start FROM generate_series(0, (p_to - first) / 7) k)
    SELECT greatest(w.start, p_from), count(DISTINCT h.id)::integer, count(s.id)::integer,
      count(s.id) FILTER (WHERE s.status = 'absent')::integer, count(s.id) FILTER (WHERE s.status = 'late')::integer,
      count(s.id) FILTER (WHERE s.status = 'excused')::integer
    FROM weeks w LEFT JOIN held h ON h.day BETWEEN w.start AND w.start + 6
      LEFT JOIN school.student_attendance s ON s.tenant_id = t AND s.session_id = h.id AND s.archived_at IS NULL
    GROUP BY w.start ORDER BY w.start;
END $$;
