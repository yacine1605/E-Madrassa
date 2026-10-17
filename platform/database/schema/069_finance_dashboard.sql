-- Lot 4, the finance dashboard (SRS 1.2, FR-TDB-01; TST-16, BR-20): month by month, each figure the
-- sum of its source operations, computed again on each reading: billed (the instalments of each
-- enrolment's latest schedule, by month due), payments validated, reversals and refunds (by the day
-- paid), expenses committed and paid other than the pays (by their day), the pays committed and paid
-- out (by their month, by the day paid), the arrears at the period's end. A salary payout writes a
-- `salaries` expense (SQL 060): those expenses are left out of the expenses, so that a payout is
-- never counted twice (AC2). The figures of a module the school does not use do not appear.
-- Read by `dashboard.finance.read` for the whole school, with the function's owner's rights.

CREATE FUNCTION school.finance_dashboard(p_from date, p_to date)
RETURNS TABLE (month date, indicator text, amount numeric, operations integer)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant(); first_day date := date_trunc('month', p_from)::date;
  last_day date := (date_trunc('month', p_to) + interval '1 month' - interval '1 day')::date; as_of date;
BEGIN
  IF t IS NULL OR NOT school.can_everywhere('dashboard.finance.read') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF p_from IS NULL OR p_to IS NULL OR p_to < p_from OR last_day - first_day > 400 THEN RAISE EXCEPTION 'dashboard_range_invalid' USING ERRCODE = '23514'; END IF;
  IF platform.module_enabled(t, 'student_finance') THEN
    RETURN QUERY SELECT date_trunc('month', i.due_date)::date, 'billed'::text, sum(i.amount), count(*)::integer
      FROM (SELECT DISTINCT ON (ps.enrollment_id) ps.id, ps.enrollment_id FROM school.payment_schedule ps WHERE ps.tenant_id = t
            ORDER BY ps.enrollment_id, ps.version DESC) s
      JOIN school.enrollment e ON e.tenant_id = t AND e.id = s.enrollment_id AND e.status <> 'cancelled'
      JOIN school.installment i ON i.tenant_id = t AND i.schedule_id = s.id
      WHERE i.due_date BETWEEN first_day AND last_day GROUP BY 1;
    RETURN QUERY SELECT date_trunc('month', p.paid_on)::date, 'payments'::text, sum(p.amount), count(*)::integer FROM school.payment p
      WHERE p.tenant_id = t AND p.kind = 'payment' AND p.status IN ('validated', 'reversed') AND p.carried_from_refund_id IS NULL
        AND p.paid_on BETWEEN first_day AND last_day GROUP BY 1;
    RETURN QUERY SELECT date_trunc('month', p.paid_on)::date, 'reversals'::text, sum(p.amount), count(*)::integer FROM school.payment p
      WHERE p.tenant_id = t AND p.kind = 'reversal' AND p.status = 'validated' AND p.paid_on BETWEEN first_day AND last_day GROUP BY 1;
    RETURN QUERY SELECT date_trunc('month', r.paid_on)::date, 'refunds'::text, sum(r.amount), count(*)::integer FROM school.refund r
      WHERE r.tenant_id = t AND r.kind = 'refund' AND r.status = 'executed' AND r.paid_on BETWEEN first_day AND last_day GROUP BY 1;
    -- The arrears as they stand at the period's end, or today.
    as_of := least(p_to + 1, current_date);
    RETURN QUERY SELECT date_trunc('month', p_to)::date, 'arrears'::text, coalesce(sum(a.overdue), 0), (count(*) FILTER (WHERE a.overdue > 0))::integer
      FROM platform.arrears_of(t, (SELECT coalesce(array_agg(e.id), '{}') FROM school.enrollment e WHERE e.tenant_id = t AND e.status <> 'cancelled'), as_of) a;
  END IF;
  IF platform.module_enabled(t, 'expenses') THEN
    RETURN QUERY SELECT date_trunc('month', x.day)::date, 'expenses_committed'::text, sum(x.amount), count(*)::integer FROM (
        SELECT x.expense_date AS day, x.amount FROM school.expense x
        WHERE x.tenant_id = t AND x.status = 'validated' AND x.payroll_payout_id IS NULL AND x.expense_date BETWEEN first_day AND last_day
        UNION ALL SELECT k.corrected_on, k.amount FROM school.expense_correction k JOIN school.expense x ON x.tenant_id = k.tenant_id AND x.id = k.expense_id
        WHERE k.tenant_id = t AND k.status = 'validated' AND x.payroll_payout_id IS NULL AND k.corrected_on BETWEEN first_day AND last_day) x GROUP BY 1;
    RETURN QUERY SELECT date_trunc('month', y.paid_on)::date, 'expenses_paid'::text, sum(CASE y.kind WHEN 'payment' THEN y.amount ELSE -y.amount END), count(*)::integer
      FROM school.expense_payment y JOIN school.expense x ON x.tenant_id = y.tenant_id AND x.id = y.expense_id
      WHERE y.tenant_id = t AND x.payroll_payout_id IS NULL AND y.paid_on BETWEEN first_day AND last_day
        AND ((y.kind = 'payment' AND y.status IN ('validated', 'reversed')) OR (y.kind = 'reversal' AND y.status = 'validated')) GROUP BY 1;
  END IF;
  IF platform.module_enabled(t, 'payroll') THEN
    RETURN QUERY SELECT r.period, 'salaries_committed'::text, sum(r.net_amount), count(*)::integer FROM school.payroll_run r
      WHERE r.tenant_id = t AND r.status <> 'draft' AND r.period BETWEEN first_day AND last_day GROUP BY 1;
    RETURN QUERY SELECT date_trunc('month', o.paid_on)::date, 'salaries_paid'::text, sum(CASE o.kind WHEN 'payout' THEN o.amount ELSE -o.amount END), count(*)::integer
      FROM school.payroll_payout o WHERE o.tenant_id = t AND o.paid_on BETWEEN first_day AND last_day
        AND ((o.kind = 'payout' AND o.status IN ('validated', 'reversed')) OR (o.kind = 'reversal' AND o.status = 'validated')) GROUP BY 1;
  END IF;
END $$;

-- A programme's profitability over a period: the revenue billed to its enrolments, the payments
-- validated less the reversals of them, its direct costs (the expenses committed to it). The
-- school's shared costs and the pays are not shared out among the programmes (the method shown):
-- the expenses committed to no programme are given as such, a cost missing said so.
CREATE FUNCTION school.program_profitability(p_from date, p_to date)
RETURNS TABLE (program_id uuid, billed numeric, received numeric, direct_costs numeric)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant(); first_day date := date_trunc('month', p_from)::date;
  last_day date := (date_trunc('month', p_to) + interval '1 month' - interval '1 day')::date;
BEGIN
  IF t IS NULL OR NOT school.can_everywhere('dashboard.finance.read') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF p_from IS NULL OR p_to IS NULL OR p_to < p_from OR last_day - first_day > 400 THEN RAISE EXCEPTION 'dashboard_range_invalid' USING ERRCODE = '23514'; END IF;
  RETURN QUERY
  WITH billed AS (
    SELECT e.program_id, sum(i.amount) AS amount
    FROM (SELECT DISTINCT ON (ps.enrollment_id) ps.id, ps.enrollment_id FROM school.payment_schedule ps WHERE ps.tenant_id = t
          ORDER BY ps.enrollment_id, ps.version DESC) s
    JOIN school.enrollment e ON e.tenant_id = t AND e.id = s.enrollment_id AND e.status <> 'cancelled'
    JOIN school.installment i ON i.tenant_id = t AND i.schedule_id = s.id
    WHERE i.due_date BETWEEN first_day AND last_day AND platform.module_enabled(t, 'student_finance') GROUP BY 1
  ), received AS (
    SELECT e.program_id, sum(CASE p.kind WHEN 'payment' THEN p.amount ELSE -p.amount END) AS amount FROM school.payment p
    JOIN school.enrollment e ON e.tenant_id = p.tenant_id AND e.id = p.enrollment_id
    WHERE p.tenant_id = t AND p.paid_on BETWEEN first_day AND last_day AND p.carried_from_refund_id IS NULL AND platform.module_enabled(t, 'student_finance')
      AND ((p.kind = 'payment' AND p.status IN ('validated', 'reversed')) OR (p.kind = 'reversal' AND p.status = 'validated')) GROUP BY 1
  ), costs AS (
    SELECT x.program_id, sum(x.amount) AS amount FROM (
      SELECT x.program_id, x.amount FROM school.expense x WHERE x.tenant_id = t AND x.status = 'validated' AND x.payroll_payout_id IS NULL
        AND x.expense_date BETWEEN first_day AND last_day
      UNION ALL SELECT x.program_id, k.amount FROM school.expense_correction k JOIN school.expense x ON x.tenant_id = k.tenant_id AND x.id = k.expense_id
        WHERE k.tenant_id = t AND k.status = 'validated' AND x.payroll_payout_id IS NULL AND k.corrected_on BETWEEN first_day AND last_day) x
    WHERE platform.module_enabled(t, 'expenses') GROUP BY 1
  )
  SELECT k.program_id, coalesce(b.amount, 0), coalesce(r.amount, 0), c.amount
  FROM (SELECT b.program_id FROM billed b UNION SELECT r.program_id FROM received r UNION SELECT c.program_id FROM costs c) k
  LEFT JOIN billed b ON b.program_id IS NOT DISTINCT FROM k.program_id
  LEFT JOIN received r ON r.program_id IS NOT DISTINCT FROM k.program_id
  LEFT JOIN costs c ON c.program_id IS NOT DISTINCT FROM k.program_id;
END $$;
