-- Lot 4, the hours delivered (SRS 1.2, FR-PTG-03; FR-REM-02, lot 4): the validated attendance of
-- each session gives its hours to the teacher who held it, the substitute when replaced: the hours
-- actually held when entered, else those planned less a delay; an absence or a session not entered
-- or not validated gives none, and hours planned alone never pay. A validated entry no longer
-- changes: a correction is a trace of its own, the former and the new values, its reason and
-- author (AC2), refused once the month is in a validated pay of the teacher before or after
-- (corrected then by a pay's adjustment, FR-REM-03). A month's pays now count these sessions as
-- their units, beside those entered by hand; its hours are the sum of its validated sessions (AC1).

-- A validated entry's correction: its new values, the former ones as they were, its reason and author.
CREATE TABLE school.attendance_correction (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  attendance_id uuid NOT NULL,
  group_id uuid NOT NULL,
  status text NOT NULL CHECK (status IN ('present', 'late', 'absent', 'replaced')),
  late_minutes integer CHECK (late_minutes BETWEEN 1 AND 600),
  actual_start time,
  actual_end time,
  replacement_teacher_id uuid,
  previous jsonb NOT NULL CHECK (jsonb_typeof(previous) = 'object'),
  reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 1000),
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by bigint REFERENCES public.users(id),
  CHECK ((status = 'late') = (late_minutes IS NOT NULL)),
  CHECK ((status = 'replaced') = (replacement_teacher_id IS NOT NULL)),
  CHECK ((actual_start IS NULL) = (actual_end IS NULL) AND (actual_end IS NULL OR actual_end > actual_start)),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, attendance_id) REFERENCES school.teacher_attendance (tenant_id, id),
  FOREIGN KEY (tenant_id, group_id) REFERENCES school.study_group (tenant_id, id),
  FOREIGN KEY (tenant_id, replacement_teacher_id) REFERENCES school.staff (tenant_id, id)
);
CREATE INDEX attendance_correction_entry ON school.attendance_correction (tenant_id, attendance_id, created_at);
ALTER TABLE school.attendance_correction ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.attendance_correction FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.attendance_correction USING (tenant_id = (SELECT school.allowed_tenant()))
  WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.attendance_correction FOR EACH ROW EXECUTE FUNCTION school.immutable();

-- An entry as it stands: its latest correction's values, else its own. With the reader's rights.
CREATE VIEW school.attendance_effective WITH (security_invoker = true) AS
  SELECT a.id, a.tenant_id, a.session_id, a.group_id, a.teacher_id, a.validation_status, a.note, a.reject_reason, a.decided_by, a.decided_at,
    a.created_at, a.created_by, a.version,
    coalesce(k.status, a.status) AS status,
    CASE WHEN k.id IS NULL THEN a.late_minutes ELSE k.late_minutes END AS late_minutes,
    CASE WHEN k.id IS NULL THEN a.actual_start ELSE k.actual_start END AS actual_start,
    CASE WHEN k.id IS NULL THEN a.actual_end ELSE k.actual_end END AS actual_end,
    CASE WHEN k.id IS NULL THEN a.replacement_teacher_id ELSE k.replacement_teacher_id END AS replacement_teacher_id,
    k.id AS correction_id, k.created_at AS corrected_at
  FROM school.teacher_attendance a
  LEFT JOIN LATERAL (SELECT * FROM school.attendance_correction x WHERE x.tenant_id = a.tenant_id AND x.attendance_id = a.id
    ORDER BY x.created_at DESC, x.id DESC LIMIT 1) k ON true;

-- Whether a teacher's pay of a day's month is validated: the month is then corrected by its pay.
CREATE FUNCTION school.month_paid(p_staff uuid, p_day date) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT p_staff IS NOT NULL AND EXISTS (SELECT 1 FROM school.payroll_run r WHERE r.tenant_id = school.allowed_tenant() AND r.staff_id = p_staff
    AND r.period = date_trunc('month', p_day)::date AND r.status = 'validated') $$;

-- AC2: a validated entry corrected by `attendance.teacher.validation.approve`, with its reason;
-- its values checked as an entry's, the former kept; never once the month is paid to the teacher
-- credited before or after; never changed nor deleted.
CREATE FUNCTION school.guard_attendance_correction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e school.attendance_effective; c school.class_session; hit record;
BEGIN
  PERFORM school.require('attendance.teacher.validation.approve');
  SELECT * INTO e FROM school.attendance_effective x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.attendance_id;
  IF e.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF e.validation_status <> 'validated' THEN RAISE EXCEPTION 'attendance_not_validated' USING ERRCODE = '23514'; END IF;
  SELECT * INTO c FROM school.class_session x WHERE x.tenant_id = NEW.tenant_id AND x.id = e.session_id;
  PERFORM pg_advisory_xact_lock(hashtextextended('attendance_correction:' || NEW.attendance_id::text, 67));
  NEW.reason := nullif(btrim(NEW.reason), '');
  IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  IF (NEW.status = 'late') <> (NEW.late_minutes IS NOT NULL) THEN RAISE EXCEPTION 'attendance_late_minutes' USING ERRCODE = '23514'; END IF;
  IF (NEW.status = 'replaced') <> (NEW.replacement_teacher_id IS NOT NULL) THEN RAISE EXCEPTION 'attendance_replacement_required' USING ERRCODE = '23514'; END IF;
  IF (NEW.actual_start IS NULL) <> (NEW.actual_end IS NULL) OR NEW.actual_end <= NEW.actual_start OR (NEW.status = 'absent' AND NEW.actual_start IS NOT NULL) THEN
    RAISE EXCEPTION 'attendance_hours_invalid' USING ERRCODE = '23514';
  END IF;
  IF (NEW.status, NEW.late_minutes, NEW.actual_start, NEW.actual_end, NEW.replacement_teacher_id)
     IS NOT DISTINCT FROM (e.status, e.late_minutes, e.actual_start, e.actual_end, e.replacement_teacher_id) THEN
    RAISE EXCEPTION 'attendance_correction_same' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = 'replaced' AND NEW.replacement_teacher_id IS DISTINCT FROM e.replacement_teacher_id THEN
    IF NEW.replacement_teacher_id = e.teacher_id THEN RAISE EXCEPTION 'session_replacement_same' USING ERRCODE = '23514'; END IF;
    FOR hit IN SELECT x.conflict FROM school.session_conflicts(c.group_id, c.subject_id, NEW.replacement_teacher_id, NULL, c.starts_at, c.ends_at, c.id) x
             WHERE x.blocking AND x.conflict IN ('session_not_teacher', 'session_teacher_left', 'session_teacher_not_qualified', 'session_teacher_busy') LIMIT 1 LOOP
      RAISE EXCEPTION '%', hit.conflict USING ERRCODE = '23514';
    END LOOP;
  END IF;
  -- A month already paid to whoever held the session, before or after: corrected by the pay (FR-REM-03).
  IF school.month_paid(coalesce(e.replacement_teacher_id, e.teacher_id), school.local_day(c.starts_at))
     OR school.month_paid(coalesce(NEW.replacement_teacher_id, e.teacher_id), school.local_day(c.starts_at)) THEN
    RAISE EXCEPTION 'attendance_month_paid' USING ERRCODE = '23514';
  END IF;
  NEW.group_id := e.group_id;
  NEW.previous := jsonb_build_object('status', e.status, 'late_minutes', e.late_minutes, 'actual_start', to_char(e.actual_start, 'HH24:MI'),
    'actual_end', to_char(e.actual_end, 'HH24:MI'), 'replacement_teacher_id', e.replacement_teacher_id);
  NEW.created_at := now(); NEW.created_by := school.actor();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT ON school.attendance_correction FOR EACH ROW EXECUTE FUNCTION school.guard_attendance_correction();

-- AC1: the sessions a teacher held from one day until another, by their validated entries as they
-- stand: the teacher credited (the substitute when replaced), the level the group's programme is
-- on, the hours held. Read by whoever reads the hours, computes or validates the pays, reads their
-- amounts, or the teacher their own; with its owner's rights, the payroll's readers not reading
-- the attendance.
CREATE FUNCTION school.delivered_sessions(p_staff uuid, p_from date, p_to date)
RETURNS TABLE (session_id uuid, staff_id uuid, day date, starts_at timestamptz, group_id uuid, node_id uuid, hours numeric, replaced boolean, corrected boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT c.id, coalesce(e.replacement_teacher_id, e.teacher_id), school.local_day(c.starts_at), c.starts_at, c.group_id, p.node_id,
    round(CASE WHEN e.actual_start IS NOT NULL THEN extract(epoch FROM e.actual_end - e.actual_start) / 3600
               ELSE greatest(extract(epoch FROM c.ends_at - c.starts_at) / 60 - coalesce(e.late_minutes, 0), 0) / 60 END, 2),
    e.status = 'replaced', e.correction_id IS NOT NULL
  FROM school.attendance_effective e
  JOIN school.class_session c ON c.tenant_id = e.tenant_id AND c.id = e.session_id
  JOIN school.study_group g ON g.tenant_id = c.tenant_id AND g.id = c.group_id
  LEFT JOIN school.program p ON p.tenant_id = g.tenant_id AND p.id = g.program_id
  WHERE e.tenant_id = school.allowed_tenant() AND e.validation_status = 'validated' AND e.status <> 'absent'
    AND c.starts_at >= school.local_at(p_from, '00:00') AND c.starts_at < school.local_at(p_to + 1, '00:00')
    AND (p_staff IS NULL OR coalesce(e.replacement_teacher_id, e.teacher_id) = p_staff)
    AND (school.can_everywhere('hours.validated.read') OR school.can_everywhere('payroll.run.generate')
      OR school.can_everywhere('payroll.run.validate') OR school.can_everywhere('payroll.amount.read')
      OR (p_staff IS NOT NULL AND school.is_own_staff(p_staff)))
  ORDER BY c.starts_at, c.id $$;

-- FR-REM-02, lot 4: 059's pay, its units now also the sessions held that month by the validated
-- attendance, at the rate of their group's level; planned sessions alone never pay.
CREATE OR REPLACE FUNCTION school.payroll_detail(p_tenant uuid, p_run uuid) RETURNS TABLE (line_no integer, kind text, node_id uuid, worked_on date,
  quantity numeric, included boolean, unit_amount numeric, amount numeric, label text, adjustment_id uuid)
LANGUAGE plpgsql STABLE AS $$
#variable_conflict use_column
DECLARE run school.payroll_run; b record; rule school.pay_rule; u record; x record; n integer := 0; left_included numeric; rate numeric; part numeric;
BEGIN
  SELECT * INTO run FROM school.payroll_run r WHERE r.tenant_id = p_tenant AND r.id = p_run;
  IF run.id IS NULL THEN RETURN; END IF;
  SELECT * INTO b FROM school.payroll_basis(p_tenant, run.staff_id, run.period);
  SELECT * INTO rule FROM school.pay_rule r WHERE r.tenant_id = p_tenant AND r.id = b.rule_id;
  IF rule.id IS NULL THEN RETURN; END IF;
  IF rule.kind IN ('fixed_monthly', 'mixed') THEN
    n := n + 1;
    RETURN QUERY SELECT n, 'fixed'::text, NULL::uuid, NULL::date, b.days_paid::numeric, false, rule.monthly_amount,
      round(rule.monthly_amount * b.days_paid / b.days_in_month, 2), NULL::text, NULL::uuid;
  END IF;
  IF rule.kind <> 'fixed_monthly' THEN
    left_included := CASE WHEN rule.kind = 'mixed' THEN rule.included_quantity ELSE 0 END;
    -- The units entered by hand, and the sessions held that month by the validated attendance (FR-PTG-03): hours, or one a session.
    FOR u IN SELECT y.node_id, y.worked_on, y.quantity, y.created_at AS at, y.id AS k FROM school.payroll_unit y WHERE y.tenant_id = p_tenant AND y.run_id = p_run
             UNION ALL
             SELECT d.node_id, d.day, CASE WHEN school.payroll_unit_kind(run.kind, run.variable_basis) = 'session' THEN 1 ELSE d.hours END, d.starts_at, d.session_id
             FROM school.delivered_sessions(run.staff_id, run.period, (run.period + interval '1 month' - interval '1 day')::date) d WHERE d.hours > 0
             ORDER BY 2, 4, 5 LOOP
      rate := school.pay_rate(p_tenant, rule.id, u.node_id);
      part := least(u.quantity, left_included);
      IF part > 0 THEN
        left_included := left_included - part;
        n := n + 1;
        RETURN QUERY SELECT n, 'unit'::text, u.node_id, u.worked_on, part, true, rate, 0::numeric, NULL::text, NULL::uuid;
      END IF;
      IF u.quantity > part THEN
        n := n + 1;
        RETURN QUERY SELECT n, 'unit'::text, u.node_id, u.worked_on, u.quantity - part, false, rate, round((u.quantity - part) * rate, 2), NULL::text, NULL::uuid;
      END IF;
    END LOOP;
  END IF;
  FOR x IN SELECT y.label, y.amount FROM school.pay_rule_bonus y WHERE y.tenant_id = p_tenant AND y.pay_rule_id = rule.id ORDER BY y.label, y.id LOOP
    n := n + 1;
    RETURN QUERY SELECT n, 'bonus'::text, NULL::uuid, NULL::date, NULL::numeric, false, NULL::numeric, x.amount, x.label, NULL::uuid;
  END LOOP;
  FOR x IN SELECT y.id, y.kind, y.amount, y.reason FROM school.payroll_adjustment y WHERE y.tenant_id = p_tenant AND y.run_id = p_run
           ORDER BY y.kind, y.created_at, y.id LOOP
    n := n + 1;
    RETURN QUERY SELECT n, x.kind, NULL::uuid, NULL::date, NULL::numeric, false, NULL::numeric, x.amount, x.reason, x.id;
  END LOOP;
END $$;

-- Who reads the corrections: as the entries are read.
CREATE POLICY resource_reader ON school.attendance_correction AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  attendance_id IN (SELECT a.id FROM school.teacher_attendance a));
CREATE POLICY resource_creator ON school.attendance_correction AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('attendance.teacher.validation.approve'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('attendance.teacher.validation.approve', 'study_group')))));
