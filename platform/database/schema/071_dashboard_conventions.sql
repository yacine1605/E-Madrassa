-- Lot 4, the finance dashboard's conventions (SRS 1.2, FR-TDB-01): the management result on the
-- basis the school chooses, `cash` (net receipts − expenses paid − pays paid out, the default) or
-- `accrual` (revenue billed − expenses committed − pays committed); the common costs and the pays
-- kept out of a programme's profitability (the default) or shared among the programmes in
-- proportion to their revenue billed, the method shown either way. A programme's direct costs are
-- then the expenses committed to it, or those paid, with the result's basis.

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels, allowed_values) VALUES
  ('dashboard.result_basis', 'code', '"cash"', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Base du résultat de gestion des tableaux de bord", "ar": "أساس نتيجة التسيير في لوحات القيادة"}', '{cash,accrual}'),
  ('dashboard.common_costs', 'code', '"kept"', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Charges communes et rémunérations dans la rentabilité par programme", "ar": "الأعباء المشتركة والأجور في مردودية البرامج"}', '{kept,billed}');

-- The expenses paid over a period by programme, none for the school's common costs: payments less
-- their reversals, by the day paid, the pays' payouts left out as in the dashboard (SQL 069), so
-- that together they are the dashboard's expenses paid (TST-16).
CREATE FUNCTION school.program_costs_paid(p_from date, p_to date)
RETURNS TABLE (program_id uuid, amount numeric)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant(); first_day date := date_trunc('month', p_from)::date;
  last_day date := (date_trunc('month', p_to) + interval '1 month' - interval '1 day')::date;
BEGIN
  IF t IS NULL OR NOT school.can_everywhere('dashboard.finance.read') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF p_from IS NULL OR p_to IS NULL OR p_to < p_from OR last_day - first_day > 400 THEN RAISE EXCEPTION 'dashboard_range_invalid' USING ERRCODE = '23514'; END IF;
  IF NOT platform.module_enabled(t, 'expenses') THEN RETURN; END IF;
  RETURN QUERY SELECT x.program_id, sum(CASE y.kind WHEN 'payment' THEN y.amount ELSE -y.amount END)
    FROM school.expense_payment y JOIN school.expense x ON x.tenant_id = y.tenant_id AND x.id = y.expense_id
    WHERE y.tenant_id = t AND x.payroll_payout_id IS NULL AND y.paid_on BETWEEN first_day AND last_day
      AND ((y.kind = 'payment' AND y.status IN ('validated', 'reversed')) OR (y.kind = 'reversal' AND y.status = 'validated')) GROUP BY 1;
END $$;
