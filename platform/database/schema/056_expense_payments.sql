-- Lot 3, an expense's payments (SRS 1.2, FR-DEP-02; BR-04, BR-05, BR-06, BR-08, BR-15; TST-10,
-- TST-11): the expense committed (validated) told apart from what is actually paid. A validated
-- expense is paid at once or in parts, never beyond its amount, its state computed (`unpaid`,
-- `partially_paid`, `paid`); a payment is recorded once per key, dated neither to come nor in a
-- closed month, and never changes: an error is undone by its linked reversal, validated by
-- someone else (BR-08), the original kept. A month's closure waits for the expenses dated in it
-- and reports them with its reconciliation. The expense's own correction comes next (FR-DEP-03).

-- BR-08: a reversal is never validated by whoever asked for it.
INSERT INTO platform.sod_rule (entity, entry_action, approve_action, exception_setting) VALUES
  ('expense_payment_reversal', 'expense.expense.reverse', 'expense.expense.validate', NULL);

-- A payment of a validated expense (`payment`, effective once recorded), or the reversal of one
-- (`reversal`, its whole amount, with its reason), waiting for its validation by someone else,
-- then validated or rejected; a payment whose reversal is validated becomes `reversed`.
CREATE TABLE school.expense_payment (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  expense_id uuid NOT NULL,
  kind text NOT NULL DEFAULT 'payment' CHECK (kind IN ('payment', 'reversal')),
  reverses_payment_id uuid,
  amount numeric(14, 2) NOT NULL CHECK (amount > 0),
  paid_on date NOT NULL,
  method_code text NOT NULL CHECK (method_code ~ '^[a-z_]{1,20}$'),
  external_ref text CHECK (length(external_ref) BETWEEN 1 AND 100),
  reason text CHECK (length(reason) BETWEEN 3 AND 500),
  status text NOT NULL DEFAULT 'validated' CHECK (status IN ('validated', 'reversed', 'pending_validation', 'rejected')),
  decided_by bigint REFERENCES public.users(id),
  decided_at timestamptz,
  decision_reason text CHECK (length(decision_reason) BETWEEN 3 AND 500),
  idempotency_key uuid NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK ((kind = 'reversal') = (reverses_payment_id IS NOT NULL)),
  CHECK (CASE kind WHEN 'payment' THEN status IN ('validated', 'reversed') AND reason IS NULL
                   ELSE status IN ('pending_validation', 'validated', 'rejected') AND reason IS NOT NULL END),
  CHECK ((kind = 'reversal' AND status <> 'pending_validation') = (decided_at IS NOT NULL)),
  CHECK (status <> 'rejected' OR decision_reason IS NOT NULL),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, idempotency_key),
  FOREIGN KEY (tenant_id, expense_id) REFERENCES school.expense (tenant_id, id),
  FOREIGN KEY (tenant_id, reverses_payment_id) REFERENCES school.expense_payment (tenant_id, id)
);
-- One reversal at a time of a payment: a rejected one leaves room for the next.
CREATE UNIQUE INDEX expense_payment_one_reversal ON school.expense_payment (tenant_id, reverses_payment_id) WHERE status <> 'rejected';
CREATE INDEX expense_payment_expense ON school.expense_payment (tenant_id, expense_id);
CREATE INDEX expense_payment_waiting ON school.expense_payment (tenant_id, created_at) WHERE status = 'pending_validation';
CALL school.protect('school.expense_payment');

-- What is paid of an expense: its payments, less their validated reversals (computed, never
-- entered). A reversed payment counts with its reversal, which takes it back.
CREATE FUNCTION school.expense_paid(p_tenant uuid, p_expense uuid) RETURNS numeric LANGUAGE sql STABLE AS $$
  SELECT coalesce(sum(CASE WHEN y.kind = 'payment' THEN y.amount ELSE -y.amount END), 0)::numeric(14, 2) FROM school.expense_payment y
  WHERE y.tenant_id = p_tenant AND y.expense_id = p_expense AND (y.status = 'validated' OR (y.kind = 'payment' AND y.status = 'reversed')) $$;

-- Its state: none before its validation, then `unpaid`, `partially_paid` or `paid`.
CREATE FUNCTION school.expense_payment_state(p_status text, p_amount numeric, p_paid numeric) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE WHEN p_status <> 'validated' THEN NULL WHEN p_paid >= p_amount THEN 'paid' WHEN p_paid > 0 THEN 'partially_paid' ELSE 'unpaid' END $$;

CREATE FUNCTION school.guard_expense_payment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e school.expense; target school.expense_payment; paid numeric; day date;
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  -- One change at a time of an expense's payments: the second reads the first's (BR-06).
  PERFORM pg_advisory_xact_lock(hashtextextended('expense_payment:' || NEW.expense_id::text, 56));
  IF TG_OP = 'INSERT' THEN
    SELECT * INTO e FROM school.expense WHERE tenant_id = NEW.tenant_id AND id = NEW.expense_id;
    IF e.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    NEW.external_ref := nullif(btrim(NEW.external_ref), ''); NEW.reason := nullif(btrim(NEW.reason), '');
    NEW.decided_by := NULL; NEW.decided_at := NULL; NEW.decision_reason := NULL;
    IF NEW.kind = 'payment' THEN
      -- A validated expense, by a means the school accepts, on a day not to come, never beyond what is left.
      IF NOT school.can_everywhere('expense.expense.pay') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
      IF e.status <> 'validated' THEN RAISE EXCEPTION 'expense_not_payable' USING ERRCODE = '23514'; END IF;
      IF NEW.paid_on > current_date THEN RAISE EXCEPTION 'payment_future_date' USING ERRCODE = '23514'; END IF;
      IF NOT (school.setting_value('finance.payment.methods') ? NEW.method_code) THEN RAISE EXCEPTION 'payment_method_unknown' USING ERRCODE = '23514'; END IF;
      paid := school.expense_paid(NEW.tenant_id, NEW.expense_id);
      IF NEW.amount > e.amount - paid THEN
        RAISE EXCEPTION 'payment_exceeds_balance' USING ERRCODE = '23514', HINT = greatest(e.amount - paid, 0)::text;
      END IF;
      NEW.reason := NULL; NEW.status := 'validated';
    ELSE
      -- The reversal of a payment of the same expense, its whole amount, dated the day it is asked for, with its reason.
      IF NOT school.can_everywhere('expense.expense.reverse') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
      IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
      SELECT * INTO target FROM school.expense_payment WHERE tenant_id = NEW.tenant_id AND id = NEW.reverses_payment_id;
      IF target.id IS NULL OR target.expense_id <> NEW.expense_id OR target.kind <> 'payment' THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
      IF target.status <> 'validated' THEN RAISE EXCEPTION 'payment_not_reversible' USING ERRCODE = '23514'; END IF;
      NEW.amount := target.amount; NEW.method_code := target.method_code; NEW.external_ref := target.external_ref;
      NEW.paid_on := current_date; NEW.status := 'pending_validation';
    END IF;
    day := NEW.paid_on;
  ELSE
    -- What a payment is never changes.
    IF (NEW.expense_id, NEW.kind, NEW.reverses_payment_id, NEW.amount, NEW.paid_on, NEW.method_code, NEW.external_ref, NEW.reason, NEW.idempotency_key)
       IS DISTINCT FROM (OLD.expense_id, OLD.kind, OLD.reverses_payment_id, OLD.amount, OLD.paid_on, OLD.method_code, OLD.external_ref, OLD.reason, OLD.idempotency_key) THEN
      RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
    END IF;
    IF OLD.kind = 'payment' THEN
      -- TST-11: a payment never changes, but becomes reversed when its reversal is validated.
      IF OLD.status = 'validated' AND NEW.status = 'reversed'
         AND (NEW.decided_by, NEW.decided_at, NEW.decision_reason) IS NOT DISTINCT FROM (OLD.decided_by, OLD.decided_at, OLD.decision_reason)
         AND EXISTS (SELECT 1 FROM school.expense_payment r WHERE r.tenant_id = OLD.tenant_id AND r.reverses_payment_id = OLD.id AND r.status = 'validated') THEN
        RETURN NEW;
      END IF;
      RAISE EXCEPTION 'expense_payment_immutable' USING ERRCODE = '23514';
    END IF;
    -- A reversal waiting: validated or rejected with its reason by `expense.expense.validate`,
    -- never by whoever asked for it (BR-08); decided, it never changes.
    IF OLD.status <> 'pending_validation' OR NEW.status NOT IN ('validated', 'rejected') THEN
      RAISE EXCEPTION 'expense_payment_immutable' USING ERRCODE = '23514';
    END IF;
    IF NOT school.can_everywhere('expense.expense.validate') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    PERFORM school.check_segregation('expense_payment_reversal', OLD.created_by);
    NEW.decision_reason := nullif(btrim(NEW.decision_reason), '');
    IF NEW.status = 'rejected' THEN
      IF NEW.decision_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    ELSE
      SELECT * INTO target FROM school.expense_payment WHERE tenant_id = OLD.tenant_id AND id = OLD.reverses_payment_id;
      IF target.status <> 'validated' THEN RAISE EXCEPTION 'payment_not_reversible' USING ERRCODE = '23514'; END IF;
      day := OLD.paid_on;
    END IF;
    NEW.decided_by := school.actor(); NEW.decided_at := now();
  END IF;
  -- BR-15: nothing paid nor taken back with a day in a closed month; the closure waits for it.
  IF day IS NOT NULL THEN
    PERFORM pg_advisory_xact_lock_shared(hashtextextended('finance_period:' || NEW.tenant_id::text, 53));
    IF school.period_closed(NEW.tenant_id, day) THEN RAISE EXCEPTION 'period_closed' USING ERRCODE = '23514'; END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.expense_payment FOR EACH ROW EXECUTE FUNCTION school.guard_expense_payment();

-- A validated reversal takes its payment back, the original kept, now `reversed`.
CREATE FUNCTION school.apply_expense_payment_reversal() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  UPDATE school.expense_payment SET status = 'reversed', version = version WHERE tenant_id = NEW.tenant_id AND id = NEW.reverses_payment_id;
  RETURN NULL;
END $$;
CREATE TRIGGER reversal AFTER UPDATE OF status ON school.expense_payment FOR EACH ROW
  WHEN (NEW.kind = 'reversal' AND NEW.status = 'validated' AND OLD.status = 'pending_validation') EXECUTE FUNCTION school.apply_expense_payment_reversal();

-- BR-05 (TST-10): a payment recorded once whatever the number of sendings of its key, even at the
-- same moment: the second waits for the first and answers it. A key given again for another
-- payment is refused. With the caller's rights.
CREATE FUNCTION school.record_expense_payment(p_expense uuid, p_amount numeric, p_paid_on date, p_method text, p_external_ref text, p_key uuid)
RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE y school.expense_payment;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('expense_payment_key:' || p_key::text, 56));
  SELECT * INTO y FROM school.expense_payment WHERE tenant_id = school.tenant() AND idempotency_key = p_key;
  IF y.id IS NULL THEN
    INSERT INTO school.expense_payment (tenant_id, expense_id, kind, amount, paid_on, method_code, external_ref, idempotency_key)
    VALUES (school.tenant(), p_expense, 'payment', p_amount, p_paid_on, p_method, p_external_ref, p_key) RETURNING * INTO y;
  ELSIF (y.expense_id, y.kind, y.amount) IS DISTINCT FROM (p_expense, 'payment', p_amount) THEN
    RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514';
  END IF;
  RETURN y.id;
END $$;

-- The reversal of a payment, with its reason, once per key; one at a time of a payment.
CREATE FUNCTION school.reverse_expense_payment(p_payment uuid, p_reason text, p_key uuid) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE y school.expense_payment; target school.expense_payment;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('expense_payment_key:' || p_key::text, 56));
  SELECT * INTO y FROM school.expense_payment WHERE tenant_id = school.tenant() AND idempotency_key = p_key;
  IF y.id IS NOT NULL THEN
    IF y.reverses_payment_id IS DISTINCT FROM p_payment THEN RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514'; END IF;
    RETURN y.id;
  END IF;
  SELECT * INTO target FROM school.expense_payment WHERE tenant_id = school.tenant() AND id = p_payment;
  IF target.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended('expense_payment:' || target.expense_id::text, 56));
  IF EXISTS (SELECT 1 FROM school.expense_payment r WHERE r.tenant_id = target.tenant_id AND r.reverses_payment_id = target.id AND r.status <> 'rejected') THEN
    RAISE EXCEPTION 'payment_not_reversible' USING ERRCODE = '23514';
  END IF;
  INSERT INTO school.expense_payment (tenant_id, expense_id, kind, reverses_payment_id, amount, paid_on, method_code, reason, idempotency_key)
  VALUES (target.tenant_id, target.expense_id, 'reversal', target.id, target.amount, current_date, target.method_code, p_reason, p_key) RETURNING * INTO y;
  RETURN y.id;
END $$;

-- Who reads the payments: whoever reads the expenses, pays or takes them back; their author.
-- Paying and taking back are for the whole school, deciding a reversal is the validator's.
CREATE POLICY resource_reader ON school.expense_payment AS RESTRICTIVE FOR SELECT TO school_runtime
  USING (created_by = (SELECT school.actor()) OR (SELECT school.can('expense.expense.read')) OR (SELECT school.can('expense.expense.validate'))
    OR (SELECT school.can('expense.expense.approve')) OR (SELECT school.can('expense.expense.pay')) OR (SELECT school.can('expense.expense.reverse')));
CREATE POLICY resource_creator ON school.expense_payment AS RESTRICTIVE FOR INSERT TO school_runtime
  WITH CHECK ((SELECT school.can_everywhere('expense.expense.pay')) OR (SELECT school.can_everywhere('expense.expense.reverse')));
CREATE POLICY resource_writer ON school.expense_payment AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('expense.expense.validate'))) WITH CHECK ((SELECT school.can_everywhere('expense.expense.validate')));
-- Whoever pays or takes back an expense reads it, and its categories. Replaces 055's.
ALTER POLICY resource_reader ON school.expense
  USING (created_by = (SELECT school.actor()) OR (SELECT school.can('expense.expense.read')) OR (SELECT school.can('expense.expense.validate'))
    OR (SELECT school.can('expense.expense.approve')) OR (SELECT school.can('expense.expense.pay')) OR (SELECT school.can('expense.expense.reverse')));
ALTER POLICY resource_reader ON school.expense_category
  USING ((SELECT school.can('expense.expense.read')) OR (SELECT school.can('expense.expense.create')) OR (SELECT school.can('expense.expense.validate'))
    OR (SELECT school.can('expense.expense.approve')) OR (SELECT school.can('expense.expense.pay')) OR (SELECT school.can('expense.expense.reverse')));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('expense_payment', 'finance', '{expense.expense.read,expense.expense.validate}');

-- FR-FIN-07: a month's reconciliation tells, besides what came in, the expenses validated with a
-- day in it (by category), what was paid of them (by means), the reversals, and the month's cash
-- balance. Replaces 053's.
CREATE OR REPLACE FUNCTION platform.reconciliation(p_tenant uuid, p_from date, p_to date) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH payments AS (
    SELECT p.method_code, count(*) AS n, sum(p.amount) AS amount FROM school.payment p
    WHERE p.tenant_id = p_tenant AND p.kind = 'payment' AND p.status IN ('validated', 'reversed') AND p.paid_on BETWEEN p_from AND p_to
      AND p.carried_from_refund_id IS NULL GROUP BY p.method_code
  ), reversals AS (
    SELECT count(*) AS n, coalesce(sum(p.amount), 0) AS amount FROM school.payment p
    WHERE p.tenant_id = p_tenant AND p.kind = 'reversal' AND p.status = 'validated' AND p.paid_on BETWEEN p_from AND p_to
  ), refunds AS (
    SELECT r.method_code, count(*) AS n, sum(r.amount) AS amount FROM school.refund r
    WHERE r.tenant_id = p_tenant AND r.kind = 'refund' AND r.status = 'executed' AND r.paid_on BETWEEN p_from AND p_to GROUP BY r.method_code
  ), carried AS (
    SELECT count(*) AS n, coalesce(sum(r.amount), 0) AS amount FROM school.refund r
    WHERE r.tenant_id = p_tenant AND r.kind = 'carry_over' AND r.status = 'executed' AND r.paid_on BETWEEN p_from AND p_to
  ), expenses AS (
    SELECT c.code, count(*) AS n, sum(x.amount) AS amount FROM school.expense x JOIN school.expense_category c ON c.tenant_id = x.tenant_id AND c.id = x.category_id
    WHERE x.tenant_id = p_tenant AND x.status = 'validated' AND x.expense_date BETWEEN p_from AND p_to GROUP BY c.code
  ), expense_payments AS (
    SELECT y.method_code, count(*) AS n, sum(y.amount) AS amount FROM school.expense_payment y
    WHERE y.tenant_id = p_tenant AND y.kind = 'payment' AND y.paid_on BETWEEN p_from AND p_to GROUP BY y.method_code
  ), expense_reversals AS (
    SELECT count(*) AS n, coalesce(sum(y.amount), 0) AS amount FROM school.expense_payment y
    WHERE y.tenant_id = p_tenant AND y.kind = 'reversal' AND y.status = 'validated' AND y.paid_on BETWEEN p_from AND p_to
  ), net AS (
    SELECT (SELECT coalesce(sum(amount), 0) FROM payments) - (SELECT amount FROM reversals) - (SELECT coalesce(sum(amount), 0) FROM refunds) AS came_in,
           (SELECT coalesce(sum(amount), 0) FROM expense_payments) - (SELECT amount FROM expense_reversals) AS went_out
  )
  SELECT jsonb_build_object('from', p_from, 'to', p_to,
    'payments', coalesce((SELECT jsonb_object_agg(method_code, jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text)) FROM payments), '{}'),
    'payments_total', (SELECT coalesce(sum(amount), 0)::numeric(14, 2)::text FROM payments),
    'reversals', (SELECT jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text) FROM reversals),
    'refunds', coalesce((SELECT jsonb_object_agg(method_code, jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text)) FROM refunds), '{}'),
    'refunds_total', (SELECT coalesce(sum(amount), 0)::numeric(14, 2)::text FROM refunds),
    'carry_overs', (SELECT jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text) FROM carried),
    'net', (SELECT came_in::numeric(14, 2)::text FROM net),
    'expenses', coalesce((SELECT jsonb_object_agg(code, jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text)) FROM expenses), '{}'),
    'expenses_total', (SELECT coalesce(sum(amount), 0)::numeric(14, 2)::text FROM expenses),
    'expense_payments', coalesce((SELECT jsonb_object_agg(method_code, jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text)) FROM expense_payments), '{}'),
    'expense_reversals', (SELECT jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text) FROM expense_reversals),
    'expenses_paid', (SELECT went_out::numeric(14, 2)::text FROM net),
    'cash_balance', (SELECT (came_in - went_out)::numeric(14, 2)::text FROM net)) $$;

-- What still waits for its decision with a day in a month: payments, expenses submitted, reversals of their payments.
CREATE FUNCTION platform.finance_pending(p_tenant uuid, p_from date, p_to date) RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT (SELECT count(*) FROM school.payment p WHERE p.tenant_id = p_tenant AND p.status = 'pending_validation' AND p.paid_on BETWEEN p_from AND p_to)
       + (SELECT count(*) FROM school.expense x WHERE x.tenant_id = p_tenant AND x.status = 'submitted' AND x.expense_date BETWEEN p_from AND p_to)
       + (SELECT count(*) FROM school.expense_payment y WHERE y.tenant_id = p_tenant AND y.status = 'pending_validation' AND y.paid_on BETWEEN p_from AND p_to) $$;

-- Replaces 053's: what waits now counts the expenses too.
CREATE OR REPLACE FUNCTION school.reconciliation(p_month date) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); first_day date := date_trunc('month', p_month)::date; last_day date;
BEGIN
  IF t IS NULL OR NOT (school.can_everywhere('finance.period.close') OR school.can_everywhere('finance.payment.read')) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  last_day := (first_day + interval '1 month' - interval '1 day')::date;
  RETURN platform.reconciliation(t, first_day, last_day) || jsonb_build_object(
    'pending', platform.finance_pending(t, first_day, last_day), 'closed', school.period_closed(t, first_day));
END $$;

-- Replaces 053's: a month closes once nothing dated in it waits, expenses and their reversals included.
CREATE OR REPLACE FUNCTION school.close_period(p_month date) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); first_day date := date_trunc('month', p_month)::date; last_day date; id uuid;
BEGIN
  IF t IS NULL OR NOT school.can_everywhere('finance.period.close') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  last_day := (first_day + interval '1 month' - interval '1 day')::date;
  IF last_day >= current_date THEN RAISE EXCEPTION 'period_not_ended' USING ERRCODE = '23514'; END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended('finance_period:' || t::text, 53));
  IF school.period_closed(t, first_day) THEN RAISE EXCEPTION 'period_closed' USING ERRCODE = '23514'; END IF;
  IF platform.finance_pending(t, first_day, last_day) > 0 THEN RAISE EXCEPTION 'period_has_pending' USING ERRCODE = '23514'; END IF;
  INSERT INTO school.finance_period (tenant_id, period_start, period_end, report) VALUES (t, first_day, last_day, platform.reconciliation(t, first_day, last_day))
  RETURNING school.finance_period.id INTO id;
  RETURN id;
END $$;

-- BR-15: an expense entered, corrected or submitted holds the closing lock shared like a payment, so
-- a closure waits for it and then counts it. Replaces 055's.
CREATE OR REPLACE FUNCTION school.check_expense(e school.expense) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE c school.expense_category;
BEGIN
  IF e.expense_date > current_date THEN RAISE EXCEPTION 'expense_future_date' USING ERRCODE = '23514'; END IF;
  PERFORM pg_advisory_xact_lock_shared(hashtextextended('finance_period:' || e.tenant_id::text, 53));
  IF school.period_closed(e.tenant_id, e.expense_date) THEN RAISE EXCEPTION 'period_closed' USING ERRCODE = '23514'; END IF;
  SELECT * INTO c FROM school.expense_category WHERE tenant_id = e.tenant_id AND id = e.category_id;
  IF c.id IS NULL OR c.archived_at IS NOT NULL THEN RAISE EXCEPTION 'expense_category_archived' USING ERRCODE = '23514'; END IF;
  IF school.expense_category_system(c.code) THEN RAISE EXCEPTION 'expense_category_system' USING ERRCODE = '23514'; END IF;
  IF c.needs_label AND e.label IS NULL THEN RAISE EXCEPTION 'expense_label_required' USING ERRCODE = '23514'; END IF;
  RETURN true;
END $$;
