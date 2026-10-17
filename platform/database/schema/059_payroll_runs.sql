-- Lot 3, the monthly payroll (SRS 1.2, FR-REM-02, FR-REM-03; BR-03, BR-04, BR-05, BR-08, BR-16;
-- TST-12, TST-19): a member's pay for a month, one per (member, month) however often it is
-- generated (AC1), from the version of their rule in force that month. A draft is recomputed from
-- what holds: the fixed part, prorated over the days of a month begun or left in its course
-- (AC9), the units done entered by hand in lot 3 (a level, a day, hours or sessions), each paid at
-- its rate, the units a mix includes being the month's earliest (AC7, AC11, AC12), the recurring
-- bonuses, the one-off bonuses and the adjustments, each traced with its reason. Every line is
-- rounded to the cent, the total their sum (BR-04). A unit without a rate is marked so, never
-- counted at zero, and blocks the validation (AC8, BR-16). Validated by someone else than whoever
-- computed it (BR-08), a pay is frozen with the copy of what it applied (BR-03, FR-REM-03 AC2); a
-- correction then is an adjustment of a month to come linked to it (FR-REM-03 AC1).

-- AC9: the fixed part of a month begun or left in its course follows its days, unless the school says not.
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('payroll.prorate_fixed', 'boolean', 'true', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Proratiser la part fixe d’un mois commencé ou quitté en cours", "ar": "تناسب الجزء الثابت لشهر بدأ أو انتهى في أثنائه"}');

-- Whoever computes or validates a pay reads the rule it applies.
ALTER POLICY resource_reader ON school.pay_rule USING ((SELECT school.can('payroll.rule.manage')) OR (SELECT school.can('payroll.amount.read'))
  OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate')));
ALTER POLICY resource_reader ON school.pay_rule_rate USING ((SELECT school.can('payroll.rule.manage')) OR (SELECT school.can('payroll.amount.read'))
  OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate')));
ALTER POLICY resource_reader ON school.pay_rule_bonus USING ((SELECT school.can('payroll.rule.manage')) OR (SELECT school.can('payroll.amount.read'))
  OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate')));

-- A member's pay for a month: the version applied and the copy of what it says, the days paid of
-- the month, the parts and the net, how many units lack a rate; a draft, then validated.
CREATE TABLE school.payroll_run (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  staff_id uuid NOT NULL,
  period date NOT NULL CHECK (extract(day FROM period) = 1),
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'validated')),
  pay_rule_id uuid NOT NULL,
  kind text NOT NULL CHECK (kind IN ('fixed_monthly', 'hourly', 'per_session', 'mixed')),
  monthly_amount numeric(14, 2),
  variable_basis text,
  included_quantity numeric(8, 2),
  days_paid integer NOT NULL CHECK (days_paid BETWEEN 0 AND 31),
  days_in_month integer NOT NULL CHECK (days_in_month BETWEEN 28 AND 31),
  fixed_amount numeric(14, 2) NOT NULL DEFAULT 0,
  variable_amount numeric(14, 2) NOT NULL DEFAULT 0,
  bonus_amount numeric(14, 2) NOT NULL DEFAULT 0,
  adjustment_amount numeric(14, 2) NOT NULL DEFAULT 0,
  net_amount numeric(14, 2) NOT NULL DEFAULT 0,
  missing_rates integer NOT NULL DEFAULT 0,
  computed_at timestamptz NOT NULL,
  computed_by bigint REFERENCES public.users(id),
  validated_at timestamptz,
  validated_by bigint REFERENCES public.users(id),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK ((status = 'validated') = (validated_at IS NOT NULL)),
  UNIQUE (tenant_id, id),
  -- AC1, TST-12: one pay per member and month, however often it is generated.
  UNIQUE (tenant_id, staff_id, period),
  FOREIGN KEY (tenant_id, staff_id) REFERENCES school.staff (tenant_id, id),
  FOREIGN KEY (tenant_id, pay_rule_id) REFERENCES school.pay_rule (tenant_id, id)
);
CREATE INDEX payroll_run_period ON school.payroll_run (tenant_id, period);
CALL school.protect('school.payroll_run');

-- A unit done, entered by hand in lot 3 on a draft: its level (none: the default rate), its day in
-- the month, its hours or its sessions as the version counts them.
CREATE TABLE school.payroll_unit (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  run_id uuid NOT NULL,
  node_id uuid,
  worked_on date NOT NULL,
  quantity numeric(8, 2) NOT NULL CHECK (quantity > 0 AND quantity <= 999),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, run_id) REFERENCES school.payroll_run (tenant_id, id),
  FOREIGN KEY (tenant_id, node_id) REFERENCES school.ref_node (tenant_id, id)
);
CREATE INDEX payroll_unit_run ON school.payroll_unit (tenant_id, run_id);
CALL school.protect('school.payroll_unit');

-- A one-off bonus (`bonus`, above zero) or an adjustment (`adjustment`, above or below zero) of a
-- draft, with its reason and author, never changed; one correcting a pay validated before is linked to it.
CREATE TABLE school.payroll_adjustment (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  run_id uuid NOT NULL,
  kind text NOT NULL CHECK (kind IN ('bonus', 'adjustment')),
  amount numeric(14, 2) NOT NULL CHECK (amount <> 0),
  reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
  corrects_run_id uuid,
  idempotency_key uuid NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK (kind = 'adjustment' OR amount > 0),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, idempotency_key),
  FOREIGN KEY (tenant_id, run_id) REFERENCES school.payroll_run (tenant_id, id),
  FOREIGN KEY (tenant_id, corrects_run_id) REFERENCES school.payroll_run (tenant_id, id)
);
CREATE INDEX payroll_adjustment_run ON school.payroll_adjustment (tenant_id, run_id);
CALL school.protect('school.payroll_adjustment');

-- The detail of a pay (FR-REM-03), line by line, with the copy of the level, the quantity and the
-- rate applied (BR-03): rewritten while a draft, frozen once validated. A unit line without a rate
-- has no amount (BR-16); a unit a mix includes is paid by its fixed part (`included`).
CREATE TABLE school.payroll_line (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  run_id uuid NOT NULL,
  line_no integer NOT NULL CHECK (line_no > 0),
  kind text NOT NULL CHECK (kind IN ('fixed', 'unit', 'bonus', 'adjustment')),
  node_id uuid,
  node_code text,
  node_labels jsonb,
  worked_on date,
  quantity numeric(8, 2),
  included boolean NOT NULL DEFAULT false,
  unit_amount numeric(14, 2),
  amount numeric(14, 2),
  label text,
  adjustment_id uuid,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK (kind = 'unit' OR amount IS NOT NULL),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, run_id, line_no),
  FOREIGN KEY (tenant_id, run_id) REFERENCES school.payroll_run (tenant_id, id),
  FOREIGN KEY (tenant_id, node_id) REFERENCES school.ref_node (tenant_id, id),
  FOREIGN KEY (tenant_id, adjustment_id) REFERENCES school.payroll_adjustment (tenant_id, id)
);
CALL school.protect('school.payroll_line');

-- One change at a time of a member's pay for a month: its generation, its units, its adjustments, its validation.
CREATE FUNCTION school.lock_payroll_run(p_staff uuid, p_period date) RETURNS void LANGUAGE sql AS $$
  SELECT pg_advisory_xact_lock(hashtextextended('payroll_run:' || p_staff::text || ':' || p_period::text, 59)) $$;

-- What a pay reads of its member beyond what its reader may see: when they joined and left.
CREATE FUNCTION school.payroll_staff(p_tenant uuid, p_staff uuid, OUT hire_date date, OUT left_on date)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT s.hire_date, s.left_on FROM school.staff s
  WHERE s.tenant_id = p_tenant AND p_tenant = school.allowed_tenant() AND s.id = p_staff $$;

-- The version of a member's rule in force a month (versions follow the months), and the days of it
-- with the school: from their hiring to their leaving (AC9), all of them when the school does not prorate.
CREATE FUNCTION school.payroll_basis(p_tenant uuid, p_staff uuid, p_period date, OUT rule_id uuid, OUT days_paid integer, OUT days_in_month integer)
LANGUAGE plpgsql STABLE AS $$
DECLARE s record; first_day date; last_day date := (p_period + interval '1 month')::date - 1;
BEGIN
  rule_id := school.pay_rule_on(p_tenant, p_staff, p_period);
  days_in_month := last_day - p_period + 1;
  SELECT * INTO s FROM school.payroll_staff(p_tenant, p_staff);
  first_day := greatest(p_period, coalesce(s.hire_date, p_period));
  last_day := least(last_day, coalesce(s.left_on, last_day));
  days_paid := greatest(last_day - first_day + 1, 0);
  IF days_paid > 0 AND NOT coalesce((school.setting_value('payroll.prorate_fixed') #>> '{}')::boolean, true) THEN days_paid := days_in_month; END IF;
END $$;

-- The unit a version counts: hours or sessions; none for a fixed salary.
CREATE FUNCTION school.payroll_unit_kind(p_kind text, p_basis text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_kind WHEN 'hourly' THEN 'hour' WHEN 'per_session' THEN 'session'
    WHEN 'mixed' THEN CASE p_basis WHEN 'hourly' THEN 'hour' ELSE 'session' END END $$;

-- FR-REM-02: a pay as it is computed now, line by line, each rounded to the cent (BR-04):
-- - `fixed`: the monthly amount of a fixed salary or a mix over the days paid of the month (AC4, AC9);
-- - `unit`: a unit done at the rate of its level, its nearest ancestor's or the default (AC3, AC5,
--   AC6, AC10), none when nothing resolves (AC8); a mix pays only the units beyond those it includes,
--   the month's earliest (AC7, AC11), never less than its fixed part (AC12);
-- - `bonus`: the version's recurring bonuses, then the one-off bonuses; `adjustment`: the adjustments.
CREATE FUNCTION school.payroll_detail(p_tenant uuid, p_run uuid) RETURNS TABLE (line_no integer, kind text, node_id uuid, worked_on date,
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
    FOR u IN SELECT y.node_id, y.worked_on, y.quantity FROM school.payroll_unit y WHERE y.tenant_id = p_tenant AND y.run_id = p_run
             ORDER BY y.worked_on, y.created_at, y.id LOOP
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

-- Whether a pay written differs from the pay computed now: its version and the copy of it, its
-- days, its lines, its parts and net as the sums of its lines.
CREATE FUNCTION school.payroll_stale(p_tenant uuid, p_run uuid) RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE run school.payroll_run; b record; rule school.pay_rule; sums record; written jsonb; computed jsonb;
BEGIN
  SELECT * INTO run FROM school.payroll_run r WHERE r.tenant_id = p_tenant AND r.id = p_run;
  SELECT * INTO b FROM school.payroll_basis(p_tenant, run.staff_id, run.period);
  SELECT * INTO rule FROM school.pay_rule r WHERE r.tenant_id = p_tenant AND r.id = b.rule_id;
  IF (run.pay_rule_id, run.kind, run.monthly_amount, run.variable_basis, run.included_quantity, run.days_paid, run.days_in_month)
     IS DISTINCT FROM (rule.id, rule.kind, rule.monthly_amount, rule.variable_basis, rule.included_quantity, b.days_paid, b.days_in_month) THEN
    RETURN true;
  END IF;
  SELECT coalesce(sum(l.amount) FILTER (WHERE l.kind = 'fixed'), 0) AS fixed, coalesce(sum(l.amount) FILTER (WHERE l.kind = 'unit'), 0) AS variable,
    coalesce(sum(l.amount) FILTER (WHERE l.kind = 'bonus'), 0) AS bonus, coalesce(sum(l.amount) FILTER (WHERE l.kind = 'adjustment'), 0) AS adjustment,
    coalesce(sum(l.amount), 0) AS net, count(*) FILTER (WHERE l.kind = 'unit' AND l.amount IS NULL)::integer AS missing
  INTO sums FROM school.payroll_line l WHERE l.tenant_id = p_tenant AND l.run_id = p_run;
  IF (run.fixed_amount, run.variable_amount, run.bonus_amount, run.adjustment_amount, run.net_amount, run.missing_rates)
     IS DISTINCT FROM (sums.fixed, sums.variable, sums.bonus, sums.adjustment, sums.net, sums.missing) THEN
    RETURN true;
  END IF;
  SELECT coalesce(jsonb_agg(jsonb_build_array(l.kind, l.node_id, l.worked_on, l.quantity::numeric(8, 2)::text, l.included, l.unit_amount::numeric(14, 2)::text,
    l.amount::numeric(14, 2)::text, l.label, l.adjustment_id) ORDER BY l.line_no), '[]') INTO written
  FROM school.payroll_line l WHERE l.tenant_id = p_tenant AND l.run_id = p_run;
  SELECT coalesce(jsonb_agg(jsonb_build_array(d.kind, d.node_id, d.worked_on, d.quantity::numeric(8, 2)::text, d.included, d.unit_amount::numeric(14, 2)::text,
    d.amount::numeric(14, 2)::text, d.label, d.adjustment_id) ORDER BY d.line_no), '[]') INTO computed
  FROM school.payroll_detail(p_tenant, p_run) d;
  RETURN written IS DISTINCT FROM computed;
END $$;

-- AC2: a draft recomputed from what holds now, its lines rewritten with the copy of their level;
-- unchanged, it is left as it is. A validated pay is never recomputed.
CREATE FUNCTION school.refresh_payroll_run(p_tenant uuid, p_run uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE run school.payroll_run; b record; rule school.pay_rule;
BEGIN
  SELECT * INTO run FROM school.payroll_run r WHERE r.tenant_id = p_tenant AND r.id = p_run;
  IF run.status <> 'draft' THEN RAISE EXCEPTION 'payroll_run_validated' USING ERRCODE = '23514'; END IF;
  IF NOT school.payroll_stale(p_tenant, p_run) THEN RETURN; END IF;
  SELECT * INTO b FROM school.payroll_basis(p_tenant, run.staff_id, run.period);
  SELECT * INTO rule FROM school.pay_rule r WHERE r.tenant_id = p_tenant AND r.id = b.rule_id;
  IF rule.id IS NULL THEN RAISE EXCEPTION 'payroll_no_rule' USING ERRCODE = '23514'; END IF;
  DELETE FROM school.payroll_line l WHERE l.tenant_id = p_tenant AND l.run_id = p_run;
  INSERT INTO school.payroll_line (tenant_id, run_id, line_no, kind, node_id, node_code, node_labels, worked_on, quantity, included, unit_amount, amount, label, adjustment_id)
  SELECT p_tenant, p_run, d.line_no, d.kind, d.node_id, nd.code, nd.labels, d.worked_on, d.quantity, d.included, d.unit_amount, d.amount, d.label, d.adjustment_id
  FROM school.payroll_detail(p_tenant, p_run) d LEFT JOIN school.ref_node nd ON nd.tenant_id = p_tenant AND nd.id = d.node_id;
  UPDATE school.payroll_run r SET pay_rule_id = rule.id, kind = rule.kind, monthly_amount = rule.monthly_amount, variable_basis = rule.variable_basis,
    included_quantity = rule.included_quantity, days_paid = b.days_paid, days_in_month = b.days_in_month,
    fixed_amount = t.fixed, variable_amount = t.variable, bonus_amount = t.bonus, adjustment_amount = t.adjustment, net_amount = t.net, missing_rates = t.missing,
    version = r.version
  FROM (SELECT coalesce(sum(l.amount) FILTER (WHERE l.kind = 'fixed'), 0) AS fixed, coalesce(sum(l.amount) FILTER (WHERE l.kind = 'unit'), 0) AS variable,
          coalesce(sum(l.amount) FILTER (WHERE l.kind = 'bonus'), 0) AS bonus, coalesce(sum(l.amount) FILTER (WHERE l.kind = 'adjustment'), 0) AS adjustment,
          coalesce(sum(l.amount), 0) AS net, count(*) FILTER (WHERE l.kind = 'unit' AND l.amount IS NULL) AS missing
        FROM school.payroll_line l WHERE l.tenant_id = p_tenant AND l.run_id = p_run) t
  WHERE r.tenant_id = p_tenant AND r.id = p_run;
END $$;

-- A pay, written by `payroll.run.generate` for the whole school, for a month begun; recomputed
-- while a draft. Validated by `payroll.run.validate`, never by whoever computed it (BR-08), when
-- no unit lacks a rate (AC8), on what holds now, its net not below zero; then frozen (AC2).
CREATE FUNCTION school.guard_payroll_run() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  PERFORM school.lock_payroll_run(NEW.staff_id, NEW.period);
  IF TG_OP = 'INSERT' THEN
    IF NOT school.is_system() AND NOT school.can_everywhere('payroll.run.generate') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    IF NEW.period > school.pay_month_start() THEN RAISE EXCEPTION 'payroll_period_future' USING ERRCODE = '23514'; END IF;
    NEW.status := 'draft'; NEW.validated_at := NULL; NEW.validated_by := NULL;
    NEW.computed_at := now(); NEW.computed_by := school.actor();
    RETURN NEW;
  END IF;
  IF (NEW.staff_id, NEW.period) IS DISTINCT FROM (OLD.staff_id, OLD.period) THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  IF OLD.status <> 'draft' THEN RAISE EXCEPTION 'payroll_run_validated' USING ERRCODE = '23514'; END IF;
  IF NEW.status = 'draft' THEN
    IF NOT school.is_system() AND NOT school.can_everywhere('payroll.run.generate') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    NEW.computed_at := now(); NEW.computed_by := school.actor();
    RETURN NEW;
  END IF;
  IF NOT school.can_everywhere('payroll.run.validate') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF (NEW.pay_rule_id, NEW.kind, NEW.monthly_amount, NEW.variable_basis, NEW.included_quantity, NEW.days_paid, NEW.days_in_month, NEW.fixed_amount,
      NEW.variable_amount, NEW.bonus_amount, NEW.adjustment_amount, NEW.net_amount, NEW.missing_rates, NEW.computed_at, NEW.computed_by)
     IS DISTINCT FROM (OLD.pay_rule_id, OLD.kind, OLD.monthly_amount, OLD.variable_basis, OLD.included_quantity, OLD.days_paid, OLD.days_in_month, OLD.fixed_amount,
      OLD.variable_amount, OLD.bonus_amount, OLD.adjustment_amount, OLD.net_amount, OLD.missing_rates, OLD.computed_at, OLD.computed_by) THEN
    RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
  END IF;
  PERFORM school.check_segregation('payroll_run', OLD.computed_by);
  IF OLD.missing_rates > 0 THEN RAISE EXCEPTION 'payroll_rate_missing' USING ERRCODE = '23514', HINT = OLD.missing_rates::text; END IF;
  IF school.payroll_stale(OLD.tenant_id, OLD.id) THEN RAISE EXCEPTION 'payroll_run_stale' USING ERRCODE = '23514'; END IF;
  IF OLD.net_amount < 0 THEN RAISE EXCEPTION 'payroll_net_negative' USING ERRCODE = '23514'; END IF;
  NEW.validated_at := now(); NEW.validated_by := school.actor();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.payroll_run FOR EACH ROW EXECUTE FUNCTION school.guard_payroll_run();

-- The units, adjustments and lines of a draft, by `payroll.run.generate`; a validated pay keeps
-- them (AC2). A unit of the month, not to come, on a level in use (BR-13), in sessions whole ones;
-- none on a fixed salary. An adjustment never changes; one correcting a pay validated before links it.
CREATE FUNCTION school.guard_payroll_part() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; run school.payroll_run; corrected school.payroll_run;
BEGIN
  IF TG_OP = 'UPDATE' OR (TG_OP = 'DELETE' AND TG_TABLE_NAME = 'payroll_adjustment') THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  IF NOT school.is_system() AND NOT school.can_everywhere('payroll.run.generate') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  SELECT * INTO run FROM school.payroll_run x WHERE x.tenant_id = r.tenant_id AND x.id = r.run_id;
  IF run.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  PERFORM school.lock_payroll_run(run.staff_id, run.period);
  IF run.status <> 'draft' THEN RAISE EXCEPTION 'payroll_run_validated' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  IF TG_TABLE_NAME = 'payroll_unit' THEN
    IF run.kind = 'fixed_monthly' THEN RAISE EXCEPTION 'payroll_units_refused' USING ERRCODE = '23514'; END IF;
    IF date_trunc('month', NEW.worked_on)::date <> run.period OR NEW.worked_on > current_date THEN RAISE EXCEPTION 'payroll_unit_date' USING ERRCODE = '23514'; END IF;
    IF school.payroll_unit_kind(run.kind, run.variable_basis) = 'session' AND NEW.quantity <> trunc(NEW.quantity) THEN
      RAISE EXCEPTION 'payroll_unit_sessions' USING ERRCODE = '23514';
    END IF;
    IF NEW.node_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM school.ref_node n WHERE n.tenant_id = NEW.tenant_id AND n.id = NEW.node_id AND n.archived_at IS NULL) THEN
      RAISE EXCEPTION 'pay_rule_node_unknown' USING ERRCODE = '23514';
    END IF;
  ELSIF TG_TABLE_NAME = 'payroll_adjustment' THEN
    NEW.reason := nullif(btrim(NEW.reason), '');
    IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    IF NEW.corrects_run_id IS NOT NULL THEN
      SELECT * INTO corrected FROM school.payroll_run x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.corrects_run_id;
      IF corrected.id IS NULL OR corrected.staff_id <> run.staff_id OR corrected.status = 'draft' OR corrected.period >= run.period THEN
        RAISE EXCEPTION 'payroll_correction_invalid' USING ERRCODE = '23514';
      END IF;
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.payroll_unit FOR EACH ROW EXECUTE FUNCTION school.guard_payroll_part();
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.payroll_adjustment FOR EACH ROW EXECUTE FUNCTION school.guard_payroll_part();
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.payroll_line FOR EACH ROW EXECUTE FUNCTION school.guard_payroll_part();

-- AC1, TST-12: the pays of a month begun, for every member with a version in force then or for one:
-- a draft each, generated again as often as asked, never twice; a validated pay left as it is (one
-- asked for alone is refused). A member with no day with the school that month receives none.
-- Returns how many drafts there are of it. With the caller's rights.
CREATE FUNCTION school.generate_payroll(p_period date, p_staff uuid DEFAULT NULL) RETURNS integer LANGUAGE plpgsql AS $$
DECLARE t uuid := school.tenant(); s record; run school.payroll_run; b record; rule school.pay_rule; n integer := 0;
BEGIN
  IF NOT school.can_everywhere('payroll.run.generate') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF p_period IS NULL OR extract(day FROM p_period) <> 1 THEN RAISE EXCEPTION 'pay_rule_month' USING ERRCODE = '23514'; END IF;
  IF p_period > school.pay_month_start() THEN RAISE EXCEPTION 'payroll_period_future' USING ERRCODE = '23514'; END IF;
  FOR s IN SELECT x.staff_id FROM school.pay_rule x WHERE x.tenant_id = t AND daterange(x.valid_from, x.valid_to, '[]') @> p_period
           AND (p_staff IS NULL OR x.staff_id = p_staff) ORDER BY x.staff_id LOOP
    PERFORM school.lock_payroll_run(s.staff_id, p_period);
    run := NULL;
    SELECT * INTO run FROM school.payroll_run x WHERE x.tenant_id = t AND x.staff_id = s.staff_id AND x.period = p_period;
    IF run.status = 'validated' THEN
      IF p_staff IS NOT NULL THEN RAISE EXCEPTION 'payroll_run_validated' USING ERRCODE = '23514'; END IF;
      CONTINUE;
    END IF;
    SELECT * INTO b FROM school.payroll_basis(t, s.staff_id, p_period);
    IF b.days_paid = 0 AND run.id IS NULL THEN CONTINUE; END IF;
    IF run.id IS NULL THEN
      SELECT * INTO rule FROM school.pay_rule x WHERE x.tenant_id = t AND x.id = b.rule_id;
      INSERT INTO school.payroll_run (tenant_id, staff_id, period, pay_rule_id, kind, monthly_amount, variable_basis, included_quantity, days_paid, days_in_month, computed_at)
      VALUES (t, s.staff_id, p_period, rule.id, rule.kind, rule.monthly_amount, rule.variable_basis, rule.included_quantity, b.days_paid, b.days_in_month, now())
      RETURNING * INTO run;
    END IF;
    PERFORM school.refresh_payroll_run(t, run.id);
    n := n + 1;
  END LOOP;
  IF p_staff IS NOT NULL AND n = 0 THEN RAISE EXCEPTION 'payroll_no_rule' USING ERRCODE = '23514'; END IF;
  RETURN n;
END $$;

-- A draft's units entered by hand, all of them at once, then the draft recomputed.
-- `p_units`: [{"node_id": uuid|null, "worked_on": "2026-10-05", "quantity": "2.5"}].
CREATE FUNCTION school.save_payroll_units(p_run uuid, p_units jsonb) RETURNS void LANGUAGE plpgsql AS $$
DECLARE t uuid := school.tenant(); run school.payroll_run; item jsonb;
BEGIN
  SELECT * INTO run FROM school.payroll_run x WHERE x.tenant_id = t AND x.id = p_run;
  IF run.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  PERFORM school.lock_payroll_run(run.staff_id, run.period);
  DELETE FROM school.payroll_unit x WHERE x.tenant_id = t AND x.run_id = p_run;
  FOR item IN SELECT * FROM jsonb_array_elements(coalesce(p_units, '[]')) LOOP
    INSERT INTO school.payroll_unit (tenant_id, run_id, node_id, worked_on, quantity)
    VALUES (t, p_run, nullif(item ->> 'node_id', '')::uuid, (item ->> 'worked_on')::date, (item ->> 'quantity')::numeric);
  END LOOP;
  PERFORM school.refresh_payroll_run(t, p_run);
END $$;

-- FR-REM-03 AC1: a one-off bonus or an adjustment of a draft, once per key (BR-05), then the draft
-- recomputed; the same key again answers the first one, another with it is refused.
CREATE FUNCTION school.adjust_payroll(p_run uuid, p_kind text, p_amount numeric, p_reason text, p_corrects uuid, p_key uuid) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE t uuid := school.tenant(); adj school.payroll_adjustment;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('payroll_adjustment_key:' || p_key::text, 59));
  SELECT * INTO adj FROM school.payroll_adjustment x WHERE x.tenant_id = t AND x.idempotency_key = p_key;
  IF adj.id IS NOT NULL THEN
    IF (adj.run_id, adj.kind, adj.amount) IS DISTINCT FROM (p_run, p_kind, p_amount) THEN RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514'; END IF;
    RETURN adj.id;
  END IF;
  INSERT INTO school.payroll_adjustment (tenant_id, run_id, kind, amount, reason, corrects_run_id, idempotency_key)
  VALUES (t, p_run, p_kind, p_amount, p_reason, p_corrects, p_key) RETURNING * INTO adj;
  PERFORM school.refresh_payroll_run(t, p_run);
  RETURN adj.id;
END $$;

-- The pays read with `payroll.amount.read`, by whoever computes or validates them; written by `payroll.run.generate`, validated by `payroll.run.validate`.
CREATE POLICY resource_reader ON school.payroll_run AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.can('payroll.amount.read')) OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate')));
CREATE POLICY resource_creator ON school.payroll_run AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere('payroll.run.generate')));
CREATE POLICY resource_writer ON school.payroll_run AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('payroll.run.generate')) OR (SELECT school.can_everywhere('payroll.run.validate')))
  WITH CHECK ((SELECT school.can_everywhere('payroll.run.generate')) OR (SELECT school.can_everywhere('payroll.run.validate')));
CREATE POLICY resource_reader ON school.payroll_unit AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.can('payroll.amount.read')) OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate')));
CREATE POLICY resource_creator ON school.payroll_unit AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere('payroll.run.generate')));
CREATE POLICY resource_deleter ON school.payroll_unit AS RESTRICTIVE FOR DELETE TO school_runtime USING ((SELECT school.can_everywhere('payroll.run.generate')));
CREATE POLICY resource_reader ON school.payroll_adjustment AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.can('payroll.amount.read')) OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate')));
CREATE POLICY resource_creator ON school.payroll_adjustment AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere('payroll.run.generate')));
CREATE POLICY resource_reader ON school.payroll_line AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.can('payroll.amount.read')) OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate')));
CREATE POLICY resource_creator ON school.payroll_line AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere('payroll.run.generate')));
CREATE POLICY resource_deleter ON school.payroll_line AS RESTRICTIVE FOR DELETE TO school_runtime USING ((SELECT school.can_everywhere('payroll.run.generate')));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('payroll_run', 'finance', '{payroll.amount.read,payroll.run.generate,payroll.run.validate}'),
  ('payroll_unit', 'finance', '{payroll.amount.read,payroll.run.generate,payroll.run.validate}'),
  ('payroll_adjustment', 'finance', '{payroll.amount.read,payroll.run.generate,payroll.run.validate}'),
  ('payroll_line', 'finance', '{payroll.amount.read,payroll.run.generate,payroll.run.validate}');
