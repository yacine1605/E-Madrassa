-- Lot 3, an expense's corrections (SRS 1.2, FR-DEP-03; BR-05, BR-06, BR-08, BR-15; TST-11): a
-- validated expense never changes nor is erased; it is corrected by its linked counter-entry, a
-- negative amount with its reason, or cancelled by one of its whole committed amount, validated by
-- someone else than whoever asked for it (BR-08), the original kept. What is committed of an
-- expense is its amount and its validated corrections, never below what is paid of it: an expense
-- paid is cancelled once its payments are taken back. A correction is dated the day it is asked
-- for, never in a closed month (BR-15), and a month's closure waits for it and reports it.

-- BR-08: a correction is never validated by whoever asked for it.
INSERT INTO platform.sod_rule (entity, entry_action, approve_action, exception_setting) VALUES
  ('expense_correction', 'expense.expense.reverse', 'expense.expense.validate', NULL);

-- A correction (`correction`, part of its amount) or a cancellation (`cancellation`, all that is
-- committed of it) of a validated expense, a negative amount with its reason, waiting for its
-- validation by someone else, then validated or rejected with its reason.
CREATE TABLE school.expense_correction (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  expense_id uuid NOT NULL,
  kind text NOT NULL CHECK (kind IN ('correction', 'cancellation')),
  amount numeric(14, 2) NOT NULL CHECK (amount < 0),
  corrected_on date NOT NULL,
  reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
  status text NOT NULL DEFAULT 'pending_validation' CHECK (status IN ('pending_validation', 'validated', 'rejected')),
  decided_by bigint REFERENCES public.users(id),
  decided_at timestamptz,
  decision_reason text CHECK (length(decision_reason) BETWEEN 3 AND 500),
  idempotency_key uuid NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK ((status <> 'pending_validation') = (decided_at IS NOT NULL)),
  CHECK (status <> 'rejected' OR decision_reason IS NOT NULL),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, idempotency_key),
  FOREIGN KEY (tenant_id, expense_id) REFERENCES school.expense (tenant_id, id)
);
-- One correction waiting at a time of an expense.
CREATE UNIQUE INDEX expense_correction_one_waiting ON school.expense_correction (tenant_id, expense_id) WHERE status = 'pending_validation';
CREATE INDEX expense_correction_expense ON school.expense_correction (tenant_id, expense_id);
CREATE INDEX expense_correction_waiting ON school.expense_correction (tenant_id, created_at) WHERE status = 'pending_validation';
CALL school.protect('school.expense_correction');

-- What is committed of an expense: its amount and its validated corrections (computed, never entered).
CREATE FUNCTION school.expense_committed(p_tenant uuid, p_expense uuid) RETURNS numeric LANGUAGE sql STABLE AS $$
  SELECT (x.amount + coalesce((SELECT sum(c.amount) FROM school.expense_correction c
    WHERE c.tenant_id = x.tenant_id AND c.expense_id = x.id AND c.status = 'validated'), 0))::numeric(14, 2)
  FROM school.expense x WHERE x.tenant_id = p_tenant AND x.id = p_expense $$;

-- Its payment state, from what is committed of it: `cancelled` once nothing is. Replaces 056's.
CREATE OR REPLACE FUNCTION school.expense_payment_state(p_status text, p_amount numeric, p_paid numeric) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE WHEN p_status <> 'validated' THEN NULL WHEN p_amount = 0 THEN 'cancelled' WHEN p_paid >= p_amount THEN 'paid'
    WHEN p_paid > 0 THEN 'partially_paid' ELSE 'unpaid' END $$;

CREATE FUNCTION school.guard_expense_correction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e school.expense; committed numeric; paid numeric; day date;
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  -- One change at a time of what is committed and paid of an expense (BR-06), with its payments.
  PERFORM pg_advisory_xact_lock(hashtextextended('expense_payment:' || NEW.expense_id::text, 56));
  committed := school.expense_committed(NEW.tenant_id, NEW.expense_id);
  paid := school.expense_paid(NEW.tenant_id, NEW.expense_id);
  IF TG_OP = 'INSERT' THEN
    -- A validated expense, by `expense.expense.reverse`, with its reason, dated the day it is asked for.
    IF NOT school.can_everywhere('expense.expense.reverse') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    SELECT * INTO e FROM school.expense WHERE tenant_id = NEW.tenant_id AND id = NEW.expense_id;
    IF e.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    IF e.status <> 'validated' OR committed = 0 THEN RAISE EXCEPTION 'expense_not_correctable' USING ERRCODE = '23514'; END IF;
    IF EXISTS (SELECT 1 FROM school.expense_correction c WHERE c.tenant_id = NEW.tenant_id AND c.expense_id = NEW.expense_id AND c.status = 'pending_validation') THEN
      RAISE EXCEPTION 'expense_correction_pending' USING ERRCODE = '23514';
    END IF;
    NEW.reason := nullif(btrim(NEW.reason), '');
    IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    IF NEW.kind = 'cancellation' THEN
      NEW.amount := -committed;
    ELSIF -NEW.amount >= committed THEN
      -- A correction leaves part of it; taking all of it is its cancellation.
      RAISE EXCEPTION 'correction_exceeds_amount' USING ERRCODE = '23514', HINT = committed::text;
    END IF;
    NEW.corrected_on := current_date; NEW.status := 'pending_validation';
    NEW.decided_by := NULL; NEW.decided_at := NULL; NEW.decision_reason := NULL;
  ELSE
    -- What a correction is never changes; waiting, it is validated or rejected with its reason by
    -- `expense.expense.validate`, never by whoever asked for it (BR-08); decided, it never changes.
    IF (NEW.expense_id, NEW.kind, NEW.amount, NEW.corrected_on, NEW.reason, NEW.idempotency_key, NEW.created_by)
       IS DISTINCT FROM (OLD.expense_id, OLD.kind, OLD.amount, OLD.corrected_on, OLD.reason, OLD.idempotency_key, OLD.created_by) THEN
      RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
    END IF;
    IF OLD.status <> 'pending_validation' OR NEW.status NOT IN ('validated', 'rejected') THEN
      RAISE EXCEPTION 'expense_correction_immutable' USING ERRCODE = '23514';
    END IF;
    IF NOT school.can_everywhere('expense.expense.validate') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    PERFORM school.check_segregation('expense_correction', OLD.created_by);
    NEW.decision_reason := nullif(btrim(NEW.decision_reason), '');
    IF NEW.status = 'rejected' AND NEW.decision_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    NEW.decided_by := school.actor(); NEW.decided_at := now();
  END IF;
  -- Never below what is paid of it: paid, an expense is cancelled once its payments are taken back.
  IF (TG_OP = 'INSERT' OR NEW.status = 'validated') AND committed + NEW.amount < paid THEN
    RAISE EXCEPTION 'correction_below_paid' USING ERRCODE = '23514', HINT = greatest(committed - paid, 0)::text;
  END IF;
  -- BR-15: nothing corrected with a day in a closed month; the closure waits for it.
  IF TG_OP = 'INSERT' OR NEW.status = 'validated' THEN
    day := NEW.corrected_on;
    PERFORM pg_advisory_xact_lock_shared(hashtextextended('finance_period:' || NEW.tenant_id::text, 53));
    IF school.period_closed(NEW.tenant_id, day) THEN RAISE EXCEPTION 'period_closed' USING ERRCODE = '23514'; END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.expense_correction FOR EACH ROW EXECUTE FUNCTION school.guard_expense_correction();

-- A payment never beyond what is left of what is committed, after 056's guard has taken the
-- expense's lock (triggers run by name: `guard`, then `limit`).
CREATE FUNCTION school.limit_expense_payment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE left_to_pay numeric;
BEGIN
  left_to_pay := school.expense_committed(NEW.tenant_id, NEW.expense_id) - school.expense_paid(NEW.tenant_id, NEW.expense_id);
  IF NEW.amount > left_to_pay THEN RAISE EXCEPTION 'payment_exceeds_balance' USING ERRCODE = '23514', HINT = greatest(left_to_pay, 0)::text; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER "limit" BEFORE INSERT ON school.expense_payment FOR EACH ROW WHEN (NEW.kind = 'payment') EXECUTE FUNCTION school.limit_expense_payment();

-- BR-05: a correction asked for once whatever the number of sendings of its key, even at the same
-- moment: the second waits for the first and answers it. A key given again for another is refused.
CREATE FUNCTION school.correct_expense(p_expense uuid, p_kind text, p_amount numeric, p_reason text, p_key uuid) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE c school.expense_correction;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('expense_correction_key:' || p_key::text, 57));
  SELECT * INTO c FROM school.expense_correction WHERE tenant_id = school.tenant() AND idempotency_key = p_key;
  IF c.id IS NULL THEN
    INSERT INTO school.expense_correction (tenant_id, expense_id, kind, amount, corrected_on, reason, idempotency_key)
    VALUES (school.tenant(), p_expense, p_kind, CASE WHEN p_kind = 'correction' THEN -p_amount ELSE -1 END, current_date, p_reason, p_key) RETURNING * INTO c;
  ELSIF (c.expense_id, c.kind) IS DISTINCT FROM (p_expense, p_kind) OR (p_kind = 'correction' AND c.amount <> -p_amount) THEN
    RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514';
  END IF;
  RETURN c.id;
END $$;

-- Who reads the corrections: whoever reads the expenses, pays or corrects them; their author.
-- Correcting is for the whole school, deciding a correction is the validator's.
CREATE POLICY resource_reader ON school.expense_correction AS RESTRICTIVE FOR SELECT TO school_runtime
  USING (created_by = (SELECT school.actor()) OR (SELECT school.can('expense.expense.read')) OR (SELECT school.can('expense.expense.validate'))
    OR (SELECT school.can('expense.expense.approve')) OR (SELECT school.can('expense.expense.pay')) OR (SELECT school.can('expense.expense.reverse')));
CREATE POLICY resource_creator ON school.expense_correction AS RESTRICTIVE FOR INSERT TO school_runtime
  WITH CHECK ((SELECT school.can_everywhere('expense.expense.reverse')));
CREATE POLICY resource_writer ON school.expense_correction AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('expense.expense.validate'))) WITH CHECK ((SELECT school.can_everywhere('expense.expense.validate')));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('expense_correction', 'finance', '{expense.expense.read,expense.expense.validate}');

-- FR-FIN-07: a month's reconciliation tells too the corrections validated with a day in it and
-- what is committed of the expenses, once they are counted. Replaces 056's.
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
  ), expense_corrections AS (
    SELECT count(*) AS n, coalesce(sum(k.amount), 0) AS amount FROM school.expense_correction k
    WHERE k.tenant_id = p_tenant AND k.status = 'validated' AND k.corrected_on BETWEEN p_from AND p_to
  ), expense_payments AS (
    SELECT y.method_code, count(*) AS n, sum(y.amount) AS amount FROM school.expense_payment y
    WHERE y.tenant_id = p_tenant AND y.kind = 'payment' AND y.paid_on BETWEEN p_from AND p_to GROUP BY y.method_code
  ), expense_reversals AS (
    SELECT count(*) AS n, coalesce(sum(y.amount), 0) AS amount FROM school.expense_payment y
    WHERE y.tenant_id = p_tenant AND y.kind = 'reversal' AND y.status = 'validated' AND y.paid_on BETWEEN p_from AND p_to
  ), net AS (
    SELECT (SELECT coalesce(sum(amount), 0) FROM payments) - (SELECT amount FROM reversals) - (SELECT coalesce(sum(amount), 0) FROM refunds) AS came_in,
           (SELECT coalesce(sum(amount), 0) FROM expense_payments) - (SELECT amount FROM expense_reversals) AS went_out,
           (SELECT coalesce(sum(amount), 0) FROM expenses) + (SELECT amount FROM expense_corrections) AS committed
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
    'expense_corrections', (SELECT jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text) FROM expense_corrections),
    'expenses_committed', (SELECT committed::numeric(14, 2)::text FROM net),
    'expense_payments', coalesce((SELECT jsonb_object_agg(method_code, jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text)) FROM expense_payments), '{}'),
    'expense_reversals', (SELECT jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text) FROM expense_reversals),
    'expenses_paid', (SELECT went_out::numeric(14, 2)::text FROM net),
    'cash_balance', (SELECT (came_in - went_out)::numeric(14, 2)::text FROM net)) $$;

-- What still waits for its decision with a day in a month, the expenses' corrections too. Replaces 056's.
CREATE OR REPLACE FUNCTION platform.finance_pending(p_tenant uuid, p_from date, p_to date) RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT (SELECT count(*) FROM school.payment p WHERE p.tenant_id = p_tenant AND p.status = 'pending_validation' AND p.paid_on BETWEEN p_from AND p_to)
       + (SELECT count(*) FROM school.expense x WHERE x.tenant_id = p_tenant AND x.status = 'submitted' AND x.expense_date BETWEEN p_from AND p_to)
       + (SELECT count(*) FROM school.expense_payment y WHERE y.tenant_id = p_tenant AND y.status = 'pending_validation' AND y.paid_on BETWEEN p_from AND p_to)
       + (SELECT count(*) FROM school.expense_correction k WHERE k.tenant_id = p_tenant AND k.status = 'pending_validation' AND k.corrected_on BETWEEN p_from AND p_to) $$;
