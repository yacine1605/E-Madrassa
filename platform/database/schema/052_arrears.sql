-- Lot 3, arrears (SRS 1.2, FR-FIN-06; FR-INS-02): the open balances and the overdue instalments of
-- the enrolments a reader may see, computed from the schedules in force and the date asked for,
-- never stored; the finance's daily reminder of the instalments falling due. No sanction is ever
-- automatic: a reminder informs, people decide.

-- When the finance hears of an instalment: so many days before it, on its day, or after it unpaid
-- (« J-3, J, J+7, paramétrables »).
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels, allowed_values) VALUES
  ('finance.reminder.offsets', 'code_set', '["before_3", "due", "after_7"]', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Rappels des échéances à la finance", "ar": "تذكيرات المالية بالأقساط"}', '{before_7,before_3,before_1,due,after_1,after_7,after_15,after_30}');

-- An offset's number of days: negative before the instalment, positive after.
CREATE FUNCTION platform.reminder_days(p_offset text) RETURNS integer LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE WHEN p_offset = 'due' THEN 0 WHEN p_offset LIKE 'before\_%' THEN -substr(p_offset, 8)::integer ELSE substr(p_offset, 7)::integer END $$;

-- The enrolments' open money as at a date: what is owed, paid, left; the instalments of the
-- schedule in force fallen due (their date passed) and not covered by what is paid (`overdue`,
-- from `overdue_since`); the next one to come. An enrolment without a schedule has nothing
-- overdue. Internal: the tenant and the enrolments are the caller's.
CREATE FUNCTION platform.arrears_of(p_tenant uuid, p_enrollments uuid[], p_as_of date)
RETURNS TABLE (enrollment_id uuid, due numeric, paid numeric, balance numeric, overdue numeric, overdue_since date, next_due_date date)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH money AS (
    SELECT x.id, platform.enrollment_due_of(p_tenant, x.id) AS due, platform.enrollment_paid_of(p_tenant, x.id) AS paid FROM unnest(p_enrollments) AS x(id)
  ), lines AS (
    SELECT s.enrollment_id, i.due_date, sum(i.amount) OVER (PARTITION BY s.enrollment_id ORDER BY i.seq) AS covered_by
    FROM (SELECT DISTINCT ON (ps.enrollment_id) ps.id, ps.enrollment_id FROM school.payment_schedule ps
          WHERE ps.tenant_id = p_tenant AND ps.enrollment_id = ANY (p_enrollments) ORDER BY ps.enrollment_id, ps.version DESC) s
    JOIN school.installment i ON i.tenant_id = p_tenant AND i.schedule_id = s.id
  )
  SELECT m.id, m.due, m.paid, (m.due - m.paid)::numeric(14, 2),
    greatest(coalesce((SELECT max(l.covered_by) FROM lines l WHERE l.enrollment_id = m.id AND l.due_date < p_as_of), 0) - m.paid, 0)::numeric(14, 2),
    (SELECT min(l.due_date) FROM lines l WHERE l.enrollment_id = m.id AND l.covered_by > m.paid AND l.due_date < p_as_of),
    (SELECT min(l.due_date) FROM lines l WHERE l.enrollment_id = m.id AND l.covered_by > m.paid AND l.due_date >= p_as_of)
  FROM money m $$;

-- FR-FIN-06: the arrears of the enrolments the reader may see (`finance.payment.read` for the whole
-- school or the pupil's site), the cancelled ones left out; one of them (`p_enrollment`) or all.
-- AC2: nothing for whoever reads no payment.
CREATE FUNCTION school.arrears(p_as_of date, p_enrollment uuid DEFAULT NULL)
RETURNS TABLE (enrollment_id uuid, due numeric, paid numeric, balance numeric, overdue numeric, overdue_since date, next_due_date date)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); everywhere boolean; sites uuid[]; ids uuid[];
BEGIN
  IF t IS NULL OR NOT school.can('finance.payment.read') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  everywhere := school.can_everywhere('finance.payment.read');
  sites := CASE WHEN everywhere THEN NULL ELSE school.site_scope_ids('finance.payment.read', 'site') END;
  SELECT coalesce(array_agg(e.id), '{}') INTO ids FROM school.enrollment e JOIN school.student s ON s.tenant_id = e.tenant_id AND s.id = e.student_id
  WHERE e.tenant_id = t AND e.status <> 'cancelled' AND (p_enrollment IS NULL OR e.id = p_enrollment) AND (everywhere OR s.site_id = ANY (sites));
  RETURN QUERY SELECT * FROM platform.arrears_of(t, ids, coalesce(p_as_of, current_date));
END $$;

-- The finance's daily reminder: for each school in use with student finance, the instalments not
-- yet covered that fall on the school's offsets today, counted by offset, in one notification to
-- each member who reads the payments of the whole school. Once a day per school, even run twice
-- (the second finds the first's run).
CREATE TABLE school.finance_reminder_run (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  day date NOT NULL,
  counts jsonb NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, day)
);
ALTER TABLE school.finance_reminder_run ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.finance_reminder_run FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.finance_reminder_run USING (tenant_id = (SELECT school.allowed_tenant())) WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));

INSERT INTO platform.notification_event (code, labels, email_default) VALUES
  ('finance.installments_due', '{"fr": "Des échéances arrivent ou sont en retard", "ar": "أقساط حان موعدها أو متأخرة"}', false);

CREATE FUNCTION platform.notify_installments(p_day date) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE r record; offsets jsonb; counts jsonb; total integer; recipient bigint; note uuid; sent integer := 0; recorded integer;
BEGIN
  FOR r IN SELECT t.id FROM platform.tenant t
      WHERE platform.tenant_mode(t.id) = 'active' AND EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = t.id AND m.module_key = 'student_finance')
      ORDER BY t.id LOOP
    offsets := coalesce((SELECT s.value FROM school.setting s WHERE s.tenant_id = r.id AND s.key = 'finance.reminder.offsets' AND s.archived_at IS NULL),
                        (SELECT d.default_value FROM platform.setting_definition d WHERE d.key = 'finance.reminder.offsets'));
    SELECT coalesce(jsonb_object_agg(o.code, o.n) FILTER (WHERE o.n > 0), '{}'), coalesce(sum(o.n), 0) INTO counts, total FROM (
      SELECT x.code, (SELECT count(*) FROM (
          SELECT s.enrollment_id, i.due_date, sum(i.amount) OVER (PARTITION BY s.enrollment_id ORDER BY i.seq) AS covered_by
          FROM (SELECT DISTINCT ON (ps.enrollment_id) ps.id, ps.enrollment_id FROM school.payment_schedule ps
                JOIN school.enrollment e ON e.tenant_id = ps.tenant_id AND e.id = ps.enrollment_id AND e.status <> 'cancelled'
                WHERE ps.tenant_id = r.id ORDER BY ps.enrollment_id, ps.version DESC) s
          JOIN school.installment i ON i.tenant_id = r.id AND i.schedule_id = s.id) l
        WHERE l.due_date = p_day - platform.reminder_days(x.code) AND l.covered_by > platform.enrollment_paid_of(r.id, l.enrollment_id))::integer AS n
      FROM jsonb_array_elements_text(offsets) AS x(code)) o;
    INSERT INTO school.finance_reminder_run (tenant_id, day, counts) VALUES (r.id, p_day, counts) ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS recorded = ROW_COUNT;
    IF recorded = 0 OR total = 0 THEN CONTINUE; END IF;
    FOR recipient IN SELECT m.user_id FROM school.membership m WHERE m.tenant_id = r.id AND m.status = 'active'
        AND EXISTS (SELECT 1 FROM school.effective_permissions(m.user_id, r.id) e WHERE e.permission = 'finance.payment.read' AND e.scope_type = 'tenant') LOOP
      INSERT INTO school.notification (tenant_id, user_id, event, data)
      VALUES (r.id, recipient, 'finance.installments_due', jsonb_build_object('arrears', true, 'day', p_day, 'counts', counts)) RETURNING id INTO note;
      INSERT INTO school.outbox (tenant_id, notification_id, user_id, event)
      SELECT r.id, note, recipient, e.code FROM platform.notification_event e JOIN public.users u ON u.id = recipient AND u.email IS NOT NULL
      LEFT JOIN school.notification_preference p ON p.tenant_id = r.id AND p.user_id = recipient AND p.event = e.code
      WHERE e.code = 'finance.installments_due' AND coalesce(p.email, e.email_default);
      sent := sent + 1;
    END LOOP;
  END LOOP;
  RETURN sent;
END $$;
