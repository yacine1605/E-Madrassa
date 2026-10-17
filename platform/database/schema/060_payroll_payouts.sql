-- Lot 3, the payouts of the pays and what a member sees of theirs (SRS 1.2, FR-REM-04, FR-REM-05;
-- BR-05, BR-06, BR-08, BR-15; TST-13): a validated pay is paid at once or in parts, never beyond
-- its net, once per key, on a day neither to come nor in a closed month; its state follows
-- (`validated`, `partially_paid`, `paid`). A payout writes, in its own transaction (BR-06), the
-- expense of the payroll's category `salaries` with its payment, which no one enters by hand nor
-- pays, corrects or takes back from the expenses' screens: the month's `salaries` expenses are its
-- payouts, never counted twice (TST-13). A payout never changes: it is undone by its linked
-- reversal, with its reason, validated by someone else than whoever asked for it (BR-08), which
-- takes back the expense's payment and cancels the expense. The month's closure waits for them and
-- its reconciliation reports them. When the school says so, a member reads their own validated
-- pays, their hours and their payouts, never anyone else's (FR-REM-05).

-- FR-REM-05: a member reads their own pays only when the school says so.
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('payroll.self_view', 'boolean', 'false', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Les enseignants consultent leurs propres rémunérations", "ar": "يطّلع الأساتذة على أجورهم الخاصة"}');

-- BR-08: a payout's reversal is never validated by whoever asked for it.
INSERT INTO platform.sod_rule (entity, entry_action, approve_action, exception_setting) VALUES
  ('payroll_payout_reversal', 'payroll.payout.record', 'payroll.run.validate', NULL);

-- A payout of a validated pay (`payout`, effective once recorded), or the reversal of one
-- (`reversal`, its whole amount, with its reason), waiting for its validation by someone else, then
-- validated or rejected; a payout whose reversal is validated becomes `reversed`.
CREATE TABLE school.payroll_payout (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  run_id uuid NOT NULL,
  kind text NOT NULL DEFAULT 'payout' CHECK (kind IN ('payout', 'reversal')),
  reverses_payout_id uuid,
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
  CHECK ((kind = 'reversal') = (reverses_payout_id IS NOT NULL)),
  CHECK (CASE kind WHEN 'payout' THEN status IN ('validated', 'reversed') AND reason IS NULL
                   ELSE status IN ('pending_validation', 'validated', 'rejected') AND reason IS NOT NULL END),
  CHECK ((kind = 'reversal' AND status <> 'pending_validation') = (decided_at IS NOT NULL)),
  CHECK (status <> 'rejected' OR decision_reason IS NOT NULL),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, idempotency_key),
  FOREIGN KEY (tenant_id, run_id) REFERENCES school.payroll_run (tenant_id, id),
  FOREIGN KEY (tenant_id, reverses_payout_id) REFERENCES school.payroll_payout (tenant_id, id)
);
-- One reversal at a time of a payout: a rejected one leaves room for the next.
CREATE UNIQUE INDEX payroll_payout_one_reversal ON school.payroll_payout (tenant_id, reverses_payout_id) WHERE status <> 'rejected';
CREATE INDEX payroll_payout_run ON school.payroll_payout (tenant_id, run_id);
CREATE INDEX payroll_payout_waiting ON school.payroll_payout (tenant_id, created_at) WHERE status = 'pending_validation';
CALL school.protect('school.payroll_payout');

-- BR-06: the expense a payout wrote, one per payout; none else names one.
ALTER TABLE school.expense ADD COLUMN payroll_payout_id uuid;
ALTER TABLE school.expense ADD FOREIGN KEY (tenant_id, payroll_payout_id) REFERENCES school.payroll_payout (tenant_id, id);
CREATE UNIQUE INDEX expense_payroll_payout ON school.expense (tenant_id, payroll_payout_id) WHERE payroll_payout_id IS NOT NULL;

-- What is paid of a pay: its payouts, less their validated reversals (computed, never entered).
CREATE FUNCTION school.payroll_paid(p_tenant uuid, p_run uuid) RETURNS numeric LANGUAGE sql STABLE AS $$
  SELECT coalesce(sum(CASE WHEN o.kind = 'payout' THEN o.amount ELSE -o.amount END), 0)::numeric(14, 2) FROM school.payroll_payout o
  WHERE o.tenant_id = p_tenant AND o.run_id = p_run AND (o.status = 'validated' OR (o.kind = 'payout' AND o.status = 'reversed')) $$;

-- Its state (FR-REM-02): `draft`, `validated` while nothing is paid, then `partially_paid` or `paid`.
CREATE FUNCTION school.payroll_state(p_status text, p_net numeric, p_paid numeric) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE WHEN p_status = 'draft' THEN 'draft' WHEN p_paid > 0 AND p_paid >= p_net THEN 'paid' WHEN p_paid > 0 THEN 'partially_paid'
    ELSE 'validated' END $$;

-- A payout of a validated pay by `payroll.payout.record`, by a means the school accepts, on a day of
-- its month or after and not to come, never beyond what is left of its net; a reversal of one, its
-- whole amount, dated the day it is asked for, with its reason, validated or rejected by
-- `payroll.run.validate`, never by whoever asked for it (BR-08). Nothing changes otherwise.
CREATE FUNCTION school.guard_payroll_payout() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE run school.payroll_run; target school.payroll_payout; paid numeric; day date;
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  SELECT * INTO run FROM school.payroll_run r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.run_id;
  IF run.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  -- One change at a time of a pay and its payouts: the second reads the first's (BR-06).
  PERFORM school.lock_payroll_run(run.staff_id, run.period);
  IF TG_OP = 'INSERT' THEN
    IF NOT school.can_everywhere('payroll.payout.record') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    NEW.external_ref := nullif(btrim(NEW.external_ref), ''); NEW.reason := nullif(btrim(NEW.reason), '');
    NEW.decided_by := NULL; NEW.decided_at := NULL; NEW.decision_reason := NULL;
    IF NEW.kind = 'payout' THEN
      IF run.status <> 'validated' THEN RAISE EXCEPTION 'payroll_run_not_payable' USING ERRCODE = '23514'; END IF;
      IF NEW.paid_on > current_date THEN RAISE EXCEPTION 'payment_future_date' USING ERRCODE = '23514'; END IF;
      IF NEW.paid_on < run.period THEN RAISE EXCEPTION 'payroll_payout_date' USING ERRCODE = '23514'; END IF;
      IF NOT (school.setting_value('finance.payment.methods') ? NEW.method_code) THEN RAISE EXCEPTION 'payment_method_unknown' USING ERRCODE = '23514'; END IF;
      paid := school.payroll_paid(NEW.tenant_id, NEW.run_id);
      IF NEW.amount > run.net_amount - paid THEN
        RAISE EXCEPTION 'payment_exceeds_balance' USING ERRCODE = '23514', HINT = greatest(run.net_amount - paid, 0)::text;
      END IF;
      NEW.reason := NULL; NEW.status := 'validated';
    ELSE
      IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
      SELECT * INTO target FROM school.payroll_payout o WHERE o.tenant_id = NEW.tenant_id AND o.id = NEW.reverses_payout_id;
      IF target.id IS NULL OR target.run_id <> NEW.run_id OR target.kind <> 'payout' THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
      IF target.status <> 'validated' THEN RAISE EXCEPTION 'payment_not_reversible' USING ERRCODE = '23514'; END IF;
      NEW.amount := target.amount; NEW.method_code := target.method_code; NEW.external_ref := target.external_ref;
      NEW.paid_on := current_date; NEW.status := 'pending_validation';
    END IF;
    day := NEW.paid_on;
  ELSE
    IF (NEW.run_id, NEW.kind, NEW.reverses_payout_id, NEW.amount, NEW.paid_on, NEW.method_code, NEW.external_ref, NEW.reason, NEW.idempotency_key)
       IS DISTINCT FROM (OLD.run_id, OLD.kind, OLD.reverses_payout_id, OLD.amount, OLD.paid_on, OLD.method_code, OLD.external_ref, OLD.reason, OLD.idempotency_key) THEN
      RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
    END IF;
    IF OLD.kind = 'payout' THEN
      -- A payout never changes, but becomes reversed when its reversal is validated.
      IF OLD.status = 'validated' AND NEW.status = 'reversed'
         AND (NEW.decided_by, NEW.decided_at, NEW.decision_reason) IS NOT DISTINCT FROM (OLD.decided_by, OLD.decided_at, OLD.decision_reason)
         AND EXISTS (SELECT 1 FROM school.payroll_payout r WHERE r.tenant_id = OLD.tenant_id AND r.reverses_payout_id = OLD.id AND r.status = 'validated') THEN
        RETURN NEW;
      END IF;
      RAISE EXCEPTION 'payroll_payout_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.status <> 'pending_validation' OR NEW.status NOT IN ('validated', 'rejected') THEN
      RAISE EXCEPTION 'payroll_payout_immutable' USING ERRCODE = '23514';
    END IF;
    IF NOT school.can_everywhere('payroll.run.validate') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    PERFORM school.check_segregation('payroll_payout_reversal', OLD.created_by);
    NEW.decision_reason := nullif(btrim(NEW.decision_reason), '');
    IF NEW.status = 'rejected' THEN
      IF NEW.decision_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    ELSE
      SELECT * INTO target FROM school.payroll_payout o WHERE o.tenant_id = OLD.tenant_id AND o.id = OLD.reverses_payout_id;
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
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.payroll_payout FOR EACH ROW EXECUTE FUNCTION school.guard_payroll_payout();

-- BR-06, TST-13: in the payout's transaction, with the system's rights, the expense of the payroll's
-- category `salaries`, validated, and its payment by the payout's means, the same day; a validated
-- reversal takes back the payout, the expense's payment and the expense itself (its cancellation),
-- dated the day the reversal was asked for, with its reason. The originals are kept.
CREATE FUNCTION school.book_payroll_payout() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE run school.payroll_run; s school.staff; category uuid; expense uuid; paid school.expense_payment;
BEGIN
  IF NEW.kind = 'payout' THEN
    SELECT * INTO run FROM school.payroll_run r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.run_id;
    SELECT * INTO s FROM school.staff x WHERE x.tenant_id = NEW.tenant_id AND x.id = run.staff_id;
    SELECT c.id INTO category FROM school.expense_category c WHERE c.tenant_id = NEW.tenant_id AND c.code = 'salaries';
    IF category IS NULL THEN RAISE EXCEPTION 'expense_category_missing' USING ERRCODE = '23514'; END IF;
    INSERT INTO school.expense (tenant_id, expense_date, amount, category_id, beneficiary, reason, payroll_payout_id, idempotency_key)
    VALUES (NEW.tenant_id, NEW.paid_on, NEW.amount, category,
      left(coalesce(nullif(concat_ws(' ', s.last_name_lat, s.first_name_lat), ''), concat_ws(' ', s.last_name_ar, s.first_name_ar)) || ' (' || s.staff_number || ')', 200),
      'Rémunération ' || to_char(run.period, 'MM/YYYY'), NEW.id, NEW.id)
    RETURNING id INTO expense;
    INSERT INTO school.expense_payment (tenant_id, expense_id, kind, amount, paid_on, method_code, external_ref, idempotency_key)
    VALUES (NEW.tenant_id, expense, 'payment', NEW.amount, NEW.paid_on, NEW.method_code, NEW.external_ref, NEW.id);
  ELSE
    UPDATE school.payroll_payout SET status = 'reversed', version = version WHERE tenant_id = NEW.tenant_id AND id = NEW.reverses_payout_id;
    SELECT x.id INTO expense FROM school.expense x WHERE x.tenant_id = NEW.tenant_id AND x.payroll_payout_id = NEW.reverses_payout_id;
    SELECT * INTO paid FROM school.expense_payment y WHERE y.tenant_id = NEW.tenant_id AND y.expense_id = expense AND y.kind = 'payment';
    INSERT INTO school.expense_payment (tenant_id, expense_id, kind, reverses_payment_id, amount, paid_on, method_code, external_ref, reason, idempotency_key)
    VALUES (NEW.tenant_id, expense, 'reversal', paid.id, paid.amount, NEW.paid_on, paid.method_code, paid.external_ref, NEW.reason, NEW.id);
    UPDATE school.expense_payment SET status = 'reversed', version = version WHERE tenant_id = NEW.tenant_id AND id = paid.id;
    INSERT INTO school.expense_correction (tenant_id, expense_id, kind, amount, corrected_on, reason, idempotency_key)
    VALUES (NEW.tenant_id, expense, 'cancellation', -NEW.amount, NEW.paid_on, NEW.reason, NEW.id);
  END IF;
  RETURN NULL;
END $$;
CREATE TRIGGER book AFTER INSERT ON school.payroll_payout FOR EACH ROW WHEN (NEW.kind = 'payout') EXECUTE FUNCTION school.book_payroll_payout();
CREATE TRIGGER reversal AFTER UPDATE OF status ON school.payroll_payout FOR EACH ROW
  WHEN (NEW.kind = 'reversal' AND NEW.status = 'validated' AND OLD.status = 'pending_validation') EXECUTE FUNCTION school.book_payroll_payout();

-- FR-DEP-01 AC1: an expense of the payroll is written by its payout alone (BR-06), validated as it
-- is; then never changed, like any decided expense. Replaces 055's.
CREATE OR REPLACE FUNCTION school.guard_expense() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE threshold numeric;
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'INSERT' AND NEW.payroll_payout_id IS NOT NULL THEN
    IF NOT school.is_system() THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    NEW.label := NULL; NEW.program_id := NULL; NEW.status := 'validated'; NEW.needs_approval := false; NEW.submitted_at := now();
    NEW.decided_by := NULL; NEW.decided_at := now(); NEW.decision_reason := NULL;
    RETURN NEW;
  END IF;
  IF TG_OP = 'INSERT' THEN
    IF NOT school.can_everywhere('expense.expense.create') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    NEW.label := nullif(btrim(NEW.label), ''); NEW.beneficiary := btrim(NEW.beneficiary); NEW.reason := btrim(NEW.reason);
    NEW.status := 'draft'; NEW.needs_approval := false; NEW.submitted_at := NULL;
    NEW.decided_by := NULL; NEW.decided_at := NULL; NEW.decision_reason := NULL;
    PERFORM school.check_expense(NEW);
    RETURN NEW;
  END IF;
  IF (NEW.idempotency_key, NEW.created_by, NEW.payroll_payout_id) IS DISTINCT FROM (OLD.idempotency_key, OLD.created_by, OLD.payroll_payout_id) THEN
    RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
  END IF;
  IF OLD.status IN ('validated', 'rejected') THEN RAISE EXCEPTION 'expense_immutable' USING ERRCODE = '23514'; END IF;
  IF NEW.status IN ('draft', 'submitted') THEN
    -- Its author corrects a draft, or submits it; a submitted expense waits as it is.
    IF OLD.created_by IS DISTINCT FROM school.actor() THEN RAISE EXCEPTION 'expense_not_author' USING ERRCODE = '23514'; END IF;
    IF OLD.status = 'submitted' THEN RAISE EXCEPTION 'expense_immutable' USING ERRCODE = '23514'; END IF;
    NEW.label := nullif(btrim(NEW.label), ''); NEW.beneficiary := btrim(NEW.beneficiary); NEW.reason := btrim(NEW.reason);
    NEW.decided_by := NULL; NEW.decided_at := NULL; NEW.decision_reason := NULL;
    PERFORM school.check_expense(NEW);
    IF NEW.status = 'submitted' THEN
      threshold := (school.setting_value('expense.approval_amount') #>> '{}')::numeric;
      NEW.needs_approval := NEW.amount > threshold; NEW.submitted_at := now();
    ELSE
      NEW.needs_approval := false; NEW.submitted_at := NULL;
    END IF;
    RETURN NEW;
  END IF;
  -- Decided: what it is never changes on the way.
  IF (NEW.expense_date, NEW.amount, NEW.category_id, NEW.label, NEW.beneficiary, NEW.reason, NEW.program_id, NEW.needs_approval, NEW.submitted_at)
     IS DISTINCT FROM (OLD.expense_date, OLD.amount, OLD.category_id, OLD.label, OLD.beneficiary, OLD.reason, OLD.program_id, OLD.needs_approval, OLD.submitted_at)
     OR OLD.status <> 'submitted' THEN
    RAISE EXCEPTION 'expense_immutable' USING ERRCODE = '23514';
  END IF;
  IF NOT school.can_everywhere(CASE WHEN OLD.needs_approval THEN 'expense.expense.approve' ELSE 'expense.expense.validate' END) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  PERFORM school.check_segregation('expense', OLD.created_by);
  NEW.decision_reason := nullif(btrim(NEW.decision_reason), '');
  IF NEW.status = 'rejected' AND NEW.decision_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  IF NEW.status = 'validated' THEN
    PERFORM pg_advisory_xact_lock_shared(hashtextextended('finance_period:' || NEW.tenant_id::text, 53));
    IF school.period_closed(NEW.tenant_id, NEW.expense_date) THEN RAISE EXCEPTION 'period_closed' USING ERRCODE = '23514'; END IF;
  END IF;
  NEW.decided_by := school.actor(); NEW.decided_at := now();
  RETURN NEW;
END $$;

-- An expense of the payroll is paid and taken back by its payout alone, validated as it is written;
-- no one pays it nor takes its payment back by hand (`expense_payroll`). Replaces 056's.
CREATE OR REPLACE FUNCTION school.guard_expense_payment() RETURNS trigger LANGUAGE plpgsql AS $$
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
    IF e.payroll_payout_id IS NOT NULL THEN
      IF NOT school.is_system() THEN RAISE EXCEPTION 'expense_payroll' USING ERRCODE = '23514'; END IF;
      NEW.status := 'validated';
      IF NEW.kind = 'reversal' THEN NEW.decided_at := now(); END IF;
    ELSIF NEW.kind = 'payment' THEN
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

-- An expense of the payroll is cancelled by its payout's reversal alone, validated as it is written,
-- on the reversal's day; no one corrects it by hand (`expense_payroll`). Replaces 057's.
CREATE OR REPLACE FUNCTION school.guard_expense_correction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e school.expense; committed numeric; paid numeric; day date;
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  -- One change at a time of what is committed and paid of an expense (BR-06), with its payments.
  PERFORM pg_advisory_xact_lock(hashtextextended('expense_payment:' || NEW.expense_id::text, 56));
  committed := school.expense_committed(NEW.tenant_id, NEW.expense_id);
  paid := school.expense_paid(NEW.tenant_id, NEW.expense_id);
  IF TG_OP = 'INSERT' THEN
    SELECT * INTO e FROM school.expense WHERE tenant_id = NEW.tenant_id AND id = NEW.expense_id;
    IF e.payroll_payout_id IS NOT NULL THEN
      IF NOT school.is_system() THEN RAISE EXCEPTION 'expense_payroll' USING ERRCODE = '23514'; END IF;
      NEW.kind := 'cancellation'; NEW.amount := -committed; NEW.status := 'validated';
      NEW.decided_by := NULL; NEW.decided_at := now(); NEW.decision_reason := NULL;
    ELSE
      -- A validated expense, by `expense.expense.reverse`, with its reason, dated the day it is asked for.
      IF NOT school.can_everywhere('expense.expense.reverse') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
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
    END IF;
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

-- BR-05: a payout recorded once whatever the number of sendings of its key, even at the same
-- moment: the second waits for the first and answers it. A key given again for another payout is
-- refused. With the caller's rights.
CREATE FUNCTION school.record_payroll_payout(p_run uuid, p_amount numeric, p_paid_on date, p_method text, p_external_ref text, p_key uuid)
RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE o school.payroll_payout;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('payroll_payout_key:' || p_key::text, 60));
  SELECT * INTO o FROM school.payroll_payout x WHERE x.tenant_id = school.tenant() AND x.idempotency_key = p_key;
  IF o.id IS NULL THEN
    INSERT INTO school.payroll_payout (tenant_id, run_id, kind, amount, paid_on, method_code, external_ref, idempotency_key)
    VALUES (school.tenant(), p_run, 'payout', p_amount, p_paid_on, p_method, p_external_ref, p_key) RETURNING * INTO o;
  ELSIF (o.run_id, o.kind, o.amount) IS DISTINCT FROM (p_run, 'payout', p_amount) THEN
    RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514';
  END IF;
  RETURN o.id;
END $$;

-- The reversal of a payout, with its reason, once per key; one at a time of a payout.
CREATE FUNCTION school.reverse_payroll_payout(p_payout uuid, p_reason text, p_key uuid) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE o school.payroll_payout; target school.payroll_payout; run school.payroll_run;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('payroll_payout_key:' || p_key::text, 60));
  SELECT * INTO o FROM school.payroll_payout x WHERE x.tenant_id = school.tenant() AND x.idempotency_key = p_key;
  IF o.id IS NOT NULL THEN
    IF o.reverses_payout_id IS DISTINCT FROM p_payout THEN RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514'; END IF;
    RETURN o.id;
  END IF;
  SELECT * INTO target FROM school.payroll_payout x WHERE x.tenant_id = school.tenant() AND x.id = p_payout;
  IF target.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  SELECT * INTO run FROM school.payroll_run r WHERE r.tenant_id = target.tenant_id AND r.id = target.run_id;
  PERFORM school.lock_payroll_run(run.staff_id, run.period);
  IF EXISTS (SELECT 1 FROM school.payroll_payout r WHERE r.tenant_id = target.tenant_id AND r.reverses_payout_id = target.id AND r.status <> 'rejected') THEN
    RAISE EXCEPTION 'payment_not_reversible' USING ERRCODE = '23514';
  END IF;
  INSERT INTO school.payroll_payout (tenant_id, run_id, kind, reverses_payout_id, amount, paid_on, method_code, reason, idempotency_key)
  VALUES (target.tenant_id, target.run_id, 'reversal', target.id, target.amount, current_date, target.method_code, p_reason, p_key) RETURNING * INTO o;
  RETURN o.id;
END $$;

-- FR-REM-05: a member's own pay once validated, when the school lets its members read theirs and
-- they hold `payroll.self_view`.
CREATE FUNCTION school.own_pay(p_staff uuid, p_status text) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT p_status <> 'draft' AND school.can('payroll.self_view') AND coalesce((school.setting_value('payroll.self_view') #>> '{}')::boolean, false)
    AND school.is_own_staff(p_staff) $$;

-- Who reads the pays: `payroll.amount.read`, whoever computes, validates or pays them; a member
-- their own validated ones, their lines and their payouts (FR-REM-05). Replaces 059's.
ALTER POLICY resource_reader ON school.payroll_run
  USING ((SELECT school.can('payroll.amount.read')) OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate'))
    OR (SELECT school.can('payroll.payout.record')) OR school.own_pay(staff_id, status));
ALTER POLICY resource_reader ON school.payroll_line
  USING ((SELECT school.can('payroll.amount.read')) OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate'))
    OR (SELECT school.can('payroll.payout.record'))
    OR EXISTS (SELECT 1 FROM school.payroll_run r WHERE r.tenant_id = payroll_line.tenant_id AND r.id = payroll_line.run_id AND school.own_pay(r.staff_id, r.status)));
ALTER POLICY resource_reader ON school.payroll_unit
  USING ((SELECT school.can('payroll.amount.read')) OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate'))
    OR (SELECT school.can('payroll.payout.record')));
ALTER POLICY resource_reader ON school.payroll_adjustment
  USING ((SELECT school.can('payroll.amount.read')) OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate'))
    OR (SELECT school.can('payroll.payout.record')));
CREATE POLICY resource_reader ON school.payroll_payout AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.can('payroll.amount.read')) OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate'))
    OR (SELECT school.can('payroll.payout.record'))
    OR EXISTS (SELECT 1 FROM school.payroll_run r WHERE r.tenant_id = payroll_payout.tenant_id AND r.id = payroll_payout.run_id AND school.own_pay(r.staff_id, r.status)));
CREATE POLICY resource_creator ON school.payroll_payout AS RESTRICTIVE FOR INSERT TO school_runtime
  WITH CHECK ((SELECT school.can_everywhere('payroll.payout.record')));
CREATE POLICY resource_writer ON school.payroll_payout AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('payroll.run.validate'))) WITH CHECK ((SELECT school.can_everywhere('payroll.run.validate')));
-- What a member is paid is read with the pays' rights only, even as an expense.
CREATE POLICY payroll_reader ON school.expense AS RESTRICTIVE FOR SELECT TO school_runtime
  USING (payroll_payout_id IS NULL OR (SELECT school.can('payroll.amount.read')) OR (SELECT school.can('payroll.run.validate'))
    OR (SELECT school.can('payroll.payout.record')));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('payroll_payout', 'finance', '{payroll.amount.read,payroll.run.validate,payroll.payout.record}');

-- FR-FIN-07, TST-13: a month's reconciliation tells too the payouts with a day in it, their
-- validated reversals and what is paid of the pays, beside what is committed of the `salaries`
-- expenses, which equals it. Replaces 057's.
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
    SELECT count(*) AS n, coalesce(sum(k.amount), 0) AS amount,
      coalesce(sum(k.amount) FILTER (WHERE EXISTS (SELECT 1 FROM school.expense x JOIN school.expense_category c ON c.tenant_id = x.tenant_id AND c.id = x.category_id
        WHERE x.tenant_id = k.tenant_id AND x.id = k.expense_id AND c.code = 'salaries')), 0) AS salaries
    FROM school.expense_correction k
    WHERE k.tenant_id = p_tenant AND k.status = 'validated' AND k.corrected_on BETWEEN p_from AND p_to
  ), expense_payments AS (
    SELECT y.method_code, count(*) AS n, sum(y.amount) AS amount FROM school.expense_payment y
    WHERE y.tenant_id = p_tenant AND y.kind = 'payment' AND y.paid_on BETWEEN p_from AND p_to GROUP BY y.method_code
  ), expense_reversals AS (
    SELECT count(*) AS n, coalesce(sum(y.amount), 0) AS amount FROM school.expense_payment y
    WHERE y.tenant_id = p_tenant AND y.kind = 'reversal' AND y.status = 'validated' AND y.paid_on BETWEEN p_from AND p_to
  ), payroll_payouts AS (
    SELECT count(*) AS n, coalesce(sum(o.amount), 0) AS amount FROM school.payroll_payout o
    WHERE o.tenant_id = p_tenant AND o.kind = 'payout' AND o.paid_on BETWEEN p_from AND p_to
  ), payroll_reversals AS (
    SELECT count(*) AS n, coalesce(sum(o.amount), 0) AS amount FROM school.payroll_payout o
    WHERE o.tenant_id = p_tenant AND o.kind = 'reversal' AND o.status = 'validated' AND o.paid_on BETWEEN p_from AND p_to
  ), net AS (
    SELECT (SELECT coalesce(sum(amount), 0) FROM payments) - (SELECT amount FROM reversals) - (SELECT coalesce(sum(amount), 0) FROM refunds) AS came_in,
           (SELECT coalesce(sum(amount), 0) FROM expense_payments) - (SELECT amount FROM expense_reversals) AS went_out,
           (SELECT coalesce(sum(amount), 0) FROM expenses) + (SELECT amount FROM expense_corrections) AS committed,
           (SELECT coalesce(sum(amount), 0) FROM expenses WHERE code = 'salaries') + (SELECT salaries FROM expense_corrections) AS salaries,
           (SELECT amount FROM payroll_payouts) - (SELECT amount FROM payroll_reversals) AS payroll_paid
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
    'payroll_payouts', (SELECT jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text) FROM payroll_payouts),
    'payroll_reversals', (SELECT jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text) FROM payroll_reversals),
    'payroll_paid', (SELECT payroll_paid::numeric(14, 2)::text FROM net),
    'salaries_committed', (SELECT salaries::numeric(14, 2)::text FROM net),
    'cash_balance', (SELECT (came_in - went_out)::numeric(14, 2)::text FROM net)) $$;

-- What still waits for its decision with a day in a month, the payouts' reversals too. Replaces 057's.
CREATE OR REPLACE FUNCTION platform.finance_pending(p_tenant uuid, p_from date, p_to date) RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT (SELECT count(*) FROM school.payment p WHERE p.tenant_id = p_tenant AND p.status = 'pending_validation' AND p.paid_on BETWEEN p_from AND p_to)
       + (SELECT count(*) FROM school.expense x WHERE x.tenant_id = p_tenant AND x.status = 'submitted' AND x.expense_date BETWEEN p_from AND p_to)
       + (SELECT count(*) FROM school.expense_payment y WHERE y.tenant_id = p_tenant AND y.status = 'pending_validation' AND y.paid_on BETWEEN p_from AND p_to)
       + (SELECT count(*) FROM school.expense_correction k WHERE k.tenant_id = p_tenant AND k.status = 'pending_validation' AND k.corrected_on BETWEEN p_from AND p_to)
       + (SELECT count(*) FROM school.payroll_payout o WHERE o.tenant_id = p_tenant AND o.status = 'pending_validation' AND o.paid_on BETWEEN p_from AND p_to) $$;
