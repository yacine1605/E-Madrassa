-- Lot 3, payments (SRS 1.2, FR-FIN-02, FR-FIN-03; BR-03, BR-04, BR-05, BR-06, BR-08, BR-12;
-- TST-09, TST-10, TST-11): a payment always belongs to an enrolment, enters its balance once
-- validated by someone else than its author (unless the school allows it, marked), and never
-- changes afterwards: an error is undone by a linked reversal, itself validated, the original kept.

-- The school's means of payment (« paramétrables par tenant »), among the publisher's; one currency, DZD.
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels, allowed_values) VALUES
  ('finance.payment.methods', 'code_set', '["cash", "cheque", "transfer", "ccp", "other"]', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Moyens de paiement acceptés", "ar": "وسائل الدفع المقبولة"}', '{cash,cheque,transfer,ccp,card,other}');

-- FR-FIN-02 AC3: a reference per payment, `PAY-{year}-{six digits}`, unique in the school and never
-- given again, a cancelled payment keeping its own.
CREATE TABLE school.payment_counter (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  year integer NOT NULL,
  last integer NOT NULL CHECK (last >= 1),
  PRIMARY KEY (tenant_id, year)
);
ALTER TABLE school.payment_counter ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.payment_counter FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.payment_counter USING (tenant_id = (SELECT school.allowed_tenant()))
  WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON school.payment_counter FOR EACH ROW EXECUTE FUNCTION school.guard_writable();

-- A payment (`payment`) or the reversal of a validated one (`reversal`, its whole amount, with its
-- reason). Recorded `pending_validation`, then `validated`; a payment whose reversal is validated
-- becomes `reversed`; its author may correct or cancel it while it waits.
CREATE TABLE school.payment (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  enrollment_id uuid NOT NULL,
  kind text NOT NULL DEFAULT 'payment' CHECK (kind IN ('payment', 'reversal')),
  reverses_payment_id uuid,
  reference text NOT NULL CHECK (reference ~ '^PAY-[0-9]{4}-[0-9]{6}$'),
  amount numeric(14, 2) NOT NULL CHECK (amount > 0),
  paid_on date NOT NULL,
  method_code text CHECK (method_code ~ '^[a-z_]{1,20}$'),
  external_ref text CHECK (length(external_ref) BETWEEN 1 AND 100),
  -- FR-FIN-04: entered knowingly beyond what is left to pay; the credit's decision comes with refunds.
  overpayment boolean NOT NULL DEFAULT false,
  reason text CHECK (length(reason) BETWEEN 3 AND 500),
  status text NOT NULL DEFAULT 'pending_validation' CHECK (status IN ('pending_validation', 'validated', 'reversed', 'cancelled')),
  -- Recorded with `finance.payment.create.delegated` only (the front office's cash desk).
  delegated boolean NOT NULL DEFAULT false,
  validated_by bigint REFERENCES public.users(id),
  validated_at timestamptz,
  -- BR-08's single exception: validated by its own author, the school allowing it.
  self_validated boolean NOT NULL DEFAULT false,
  cancel_reason text CHECK (length(cancel_reason) BETWEEN 3 AND 500),
  idempotency_key uuid NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK ((kind = 'reversal') = (reverses_payment_id IS NOT NULL)),
  CHECK (kind = 'payment' OR (reason IS NOT NULL AND NOT overpayment AND status <> 'reversed')),
  CHECK (kind = 'reversal' OR method_code IS NOT NULL),
  CHECK ((status IN ('validated', 'reversed')) = (validated_at IS NOT NULL)),
  CHECK ((status = 'cancelled') = (cancel_reason IS NOT NULL)),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, reference),
  UNIQUE (tenant_id, idempotency_key),
  FOREIGN KEY (tenant_id, enrollment_id) REFERENCES school.enrollment (tenant_id, id),
  FOREIGN KEY (tenant_id, reverses_payment_id) REFERENCES school.payment (tenant_id, id)
);
-- One reversal at a time of a payment: a cancelled one leaves room for the next.
CREATE UNIQUE INDEX payment_one_reversal ON school.payment (tenant_id, reverses_payment_id) WHERE status <> 'cancelled';
CREATE INDEX payment_enrollment ON school.payment (tenant_id, enrollment_id);
CREATE INDEX payment_pending ON school.payment (tenant_id, created_at) WHERE status = 'pending_validation';
CALL school.protect('school.payment');

-- What is paid: the validated payments, less their validated reversals (TST-09: computed, never
-- entered). A reversed payment counts with its reversal, which takes it back.
CREATE FUNCTION platform.enrollment_paid_of(p_tenant uuid, p_enrollment uuid) RETURNS numeric
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT coalesce(sum(CASE WHEN p.kind = 'payment' THEN p.amount ELSE -p.amount END), 0)::numeric(14, 2) FROM school.payment p
  WHERE p.tenant_id = p_tenant AND p.enrollment_id = p_enrollment
    AND (p.status = 'validated' OR (p.kind = 'payment' AND p.status = 'reversed')) $$;

-- FR-FIN-02 step 1: what the enrolment owes, has paid, waits for, and its financial state
-- (FR-INS-02: `unpaid`, `partially_paid`, `paid`, computed), for whoever reads its finance or
-- records its payments. `p_except` leaves one waiting payment out (its own correction).
CREATE FUNCTION school.payment_balance(p_enrollment uuid, p_except uuid DEFAULT NULL)
RETURNS TABLE (due numeric, paid numeric, pending numeric, balance numeric, state text)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); d numeric; p numeric; w numeric;
BEGIN
  IF NOT (school.finance_in_scope('finance.payment.read', p_enrollment) OR school.finance_in_scope('finance.payment.create', p_enrollment)
          OR school.finance_in_scope('finance.payment.create.delegated', p_enrollment) OR school.finance_in_scope('finance.payment.validate', p_enrollment)
          OR school.finance_in_scope('finance.payment.reverse', p_enrollment) OR school.finance_in_scope('finance.discount.grant', p_enrollment)
          OR school.finance_in_scope('finance.schedule.manage', p_enrollment) OR school.finance_in_scope('finance.discount.approve', p_enrollment)) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  d := platform.enrollment_due_of(t, p_enrollment);
  IF d IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  p := platform.enrollment_paid_of(t, p_enrollment);
  SELECT coalesce(sum(x.amount), 0)::numeric(14, 2) INTO w FROM school.payment x WHERE x.tenant_id = t AND x.enrollment_id = p_enrollment
    AND x.kind = 'payment' AND x.status = 'pending_validation' AND x.id IS DISTINCT FROM p_except;
  RETURN QUERY SELECT d, p, w, d - p, CASE WHEN p >= d THEN 'paid' WHEN p > 0 THEN 'partially_paid' ELSE 'unpaid' END;
END $$;

-- The next reference of the school's year, for whoever records a payment or a reversal.
CREATE FUNCTION school.next_payment_reference(p_tenant uuid) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE y integer := extract(year FROM current_date)::integer; n integer;
BEGIN
  IF p_tenant IS DISTINCT FROM school.allowed_tenant() OR NOT (school.can('finance.payment.create') OR school.can('finance.payment.create.delegated')
     OR school.can('finance.payment.reverse')) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  INSERT INTO school.payment_counter AS c (tenant_id, year, last) VALUES (p_tenant, y, 1)
  ON CONFLICT (tenant_id, year) DO UPDATE SET last = c.last + 1 RETURNING c.last INTO n;
  IF n > 999999 THEN RAISE EXCEPTION 'payment_reference_exhausted' USING ERRCODE = '23514'; END IF;
  RETURN 'PAY-' || y::text || '-' || lpad(n::text, 6, '0');
END $$;

-- A payment's checks against the enrolment it pays: a date not to come, a means the school accepts,
-- never beyond what is left to pay (validated and waiting payments together) unless entered as an
-- overpayment, which it must then be.
CREATE FUNCTION school.check_payment(p school.payment) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE b record;
BEGIN
  IF p.paid_on > current_date THEN RAISE EXCEPTION 'payment_future_date' USING ERRCODE = '23514'; END IF;
  IF p.kind = 'payment' AND NOT (school.setting_value('finance.payment.methods') ? p.method_code) THEN
    RAISE EXCEPTION 'payment_method_unknown' USING ERRCODE = '23514';
  END IF;
  IF p.kind = 'reversal' THEN RETURN false; END IF;
  SELECT * INTO b FROM school.payment_balance(p.enrollment_id, p.id);
  IF p.amount > b.balance - b.pending THEN
    IF NOT p.overpayment THEN RAISE EXCEPTION 'payment_exceeds_balance' USING ERRCODE = '23514', HINT = greatest(b.balance - b.pending, 0)::text; END IF;
    RETURN true;
  END IF;
  RETURN false;
END $$;

CREATE FUNCTION school.guard_payment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e school.enrollment; target school.payment; allow_self boolean; self boolean; b record;
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  -- One change at a time of an enrolment's finance: the second reads the first's (BR-06).
  PERFORM pg_advisory_xact_lock(hashtextextended('enrollment_finance:' || NEW.enrollment_id::text, 48));
  IF TG_OP = 'INSERT' THEN
    SELECT * INTO e FROM school.enrollment WHERE tenant_id = NEW.tenant_id AND id = NEW.enrollment_id;
    IF e.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    NEW.reason := nullif(btrim(NEW.reason), '');
    NEW.external_ref := nullif(btrim(NEW.external_ref), '');
    IF NEW.kind = 'payment' THEN
      IF school.finance_in_scope('finance.payment.create', e.id) THEN NEW.delegated := false;
      ELSIF school.finance_in_scope('finance.payment.create.delegated', e.id) THEN NEW.delegated := true;
      ELSE RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
      -- FR-FIN-02: no new payment of a cancelled enrolment but a linked correction (BR-03).
      IF e.status = 'cancelled' THEN RAISE EXCEPTION 'enrollment_cancelled' USING ERRCODE = '23514'; END IF;
      NEW.reason := NULL;
      NEW.overpayment := school.check_payment(NEW);
    ELSE
      -- FR-FIN-03: the reversal of a validated payment of the same enrolment, its whole amount, with its reason.
      IF NOT school.finance_in_scope('finance.payment.reverse', e.id) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
      IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
      SELECT * INTO target FROM school.payment WHERE tenant_id = NEW.tenant_id AND id = NEW.reverses_payment_id;
      IF target.id IS NULL OR target.enrollment_id <> NEW.enrollment_id OR target.kind <> 'payment' THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
      IF target.status <> 'validated' THEN RAISE EXCEPTION 'payment_not_reversible' USING ERRCODE = '23514'; END IF;
      NEW.amount := target.amount; NEW.method_code := target.method_code; NEW.overpayment := false; NEW.delegated := false;
      NEW.paid_on := coalesce(NEW.paid_on, current_date);
      PERFORM school.check_payment(NEW);
    END IF;
    NEW.reference := school.next_payment_reference(NEW.tenant_id);
    NEW.status := 'pending_validation'; NEW.validated_by := NULL; NEW.validated_at := NULL; NEW.self_validated := false; NEW.cancel_reason := NULL;
    RETURN NEW;
  END IF;
  -- What a payment is, never changes.
  IF (NEW.enrollment_id, NEW.kind, NEW.reverses_payment_id, NEW.reference, NEW.idempotency_key, NEW.delegated)
     IS DISTINCT FROM (OLD.enrollment_id, OLD.kind, OLD.reverses_payment_id, OLD.reference, OLD.idempotency_key, OLD.delegated) THEN
    RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
  END IF;
  -- TST-11: a validated payment, a reversed or cancelled one, never changes; but a validated payment
  -- becomes reversed when its reversal is validated.
  IF OLD.status <> 'pending_validation' THEN
    IF OLD.status = 'validated' AND NEW.status = 'reversed' AND OLD.kind = 'payment'
       AND (NEW.amount, NEW.paid_on, NEW.method_code, NEW.external_ref, NEW.overpayment, NEW.reason, NEW.validated_by, NEW.validated_at, NEW.self_validated, NEW.cancel_reason)
           IS NOT DISTINCT FROM (OLD.amount, OLD.paid_on, OLD.method_code, OLD.external_ref, OLD.overpayment, OLD.reason, OLD.validated_by, OLD.validated_at, OLD.self_validated, OLD.cancel_reason)
       AND EXISTS (SELECT 1 FROM school.payment r WHERE r.tenant_id = OLD.tenant_id AND r.reverses_payment_id = OLD.id AND r.status = 'validated') THEN
      RETURN NEW;
    END IF;
    RAISE EXCEPTION 'payment_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = 'pending_validation' THEN
    -- FR-FIN-03: its author corrects it while it waits; a reversal, only its reason.
    IF OLD.created_by IS DISTINCT FROM school.actor() THEN RAISE EXCEPTION 'payment_not_author' USING ERRCODE = '23514'; END IF;
    IF (NEW.validated_by, NEW.validated_at, NEW.self_validated, NEW.cancel_reason) IS DISTINCT FROM (OLD.validated_by, OLD.validated_at, OLD.self_validated, OLD.cancel_reason) THEN
      RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
    END IF;
    NEW.external_ref := nullif(btrim(NEW.external_ref), '');
    IF OLD.kind = 'reversal' THEN
      IF (NEW.amount, NEW.paid_on, NEW.method_code, NEW.external_ref, NEW.overpayment) IS DISTINCT FROM (OLD.amount, OLD.paid_on, OLD.method_code, OLD.external_ref, OLD.overpayment) THEN
        RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
      END IF;
      NEW.reason := nullif(btrim(NEW.reason), '');
      IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    ELSE
      NEW.reason := NULL;
      NEW.overpayment := school.check_payment(NEW);
    END IF;
    RETURN NEW;
  END IF;
  IF (NEW.amount, NEW.paid_on, NEW.method_code, NEW.external_ref, NEW.overpayment, NEW.reason)
     IS DISTINCT FROM (OLD.amount, OLD.paid_on, OLD.method_code, OLD.external_ref, OLD.overpayment, OLD.reason) THEN
    RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = 'cancelled' THEN
    -- Before its validation, its author withdraws it, saying why; its reference is not given again.
    IF OLD.created_by IS DISTINCT FROM school.actor() THEN RAISE EXCEPTION 'payment_not_author' USING ERRCODE = '23514'; END IF;
    NEW.cancel_reason := nullif(btrim(NEW.cancel_reason), '');
    IF NEW.cancel_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    NEW.validated_by := NULL; NEW.validated_at := NULL; NEW.self_validated := false;
    RETURN NEW;
  END IF;
  IF NEW.status <> 'validated' THEN RAISE EXCEPTION 'payment_immutable' USING ERRCODE = '23514'; END IF;
  -- FR-FIN-03: validated by `finance.payment.validate`, never by its author (BR-08, AC3), but for a
  -- payment if the school allows it (`finance.allow_self_validation`), which it marks.
  IF NOT school.finance_in_scope('finance.payment.validate', OLD.enrollment_id) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  allow_self := OLD.kind = 'payment' AND coalesce((school.setting_value('finance.allow_self_validation') #>> '{}')::boolean, false);
  self := school.check_segregation(CASE OLD.kind WHEN 'payment' THEN 'payment' ELSE 'payment_reversal' END, OLD.created_by, allow_self);
  IF OLD.kind = 'payment' THEN
    -- What is left to pay may have changed since (an adjustment): an overpayment stays explicit. The
    -- means accepted at its recording stays accepted.
    SELECT * INTO b FROM school.payment_balance(OLD.enrollment_id, OLD.id);
    IF NOT OLD.overpayment AND OLD.amount > b.balance THEN
      RAISE EXCEPTION 'payment_exceeds_balance' USING ERRCODE = '23514', HINT = greatest(b.balance, 0)::text;
    END IF;
  ELSE
    SELECT * INTO target FROM school.payment WHERE tenant_id = OLD.tenant_id AND id = OLD.reverses_payment_id;
    IF target.status <> 'validated' THEN RAISE EXCEPTION 'payment_not_reversible' USING ERRCODE = '23514'; END IF;
  END IF;
  NEW.validated_by := school.actor(); NEW.validated_at := now(); NEW.self_validated := self; NEW.cancel_reason := NULL;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.payment FOR EACH ROW EXECUTE FUNCTION school.guard_payment();

-- AC2: a validated reversal takes its payment back, the original kept, now `reversed`.
CREATE FUNCTION school.apply_reversal() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  UPDATE school.payment SET status = 'reversed', version = version WHERE tenant_id = NEW.tenant_id AND id = NEW.reverses_payment_id;
  RETURN NULL;
END $$;
CREATE TRIGGER reversal AFTER UPDATE OF status ON school.payment FOR EACH ROW
  WHEN (NEW.kind = 'reversal' AND NEW.status = 'validated' AND OLD.status = 'pending_validation') EXECUTE FUNCTION school.apply_reversal();

-- FR-FIN-02 AC1 (TST-10, BR-05): a payment recorded once whatever the number of sendings of its
-- key, even at the same moment: the second waits for the first and answers it. A key given again
-- for another payment is refused. With the caller's rights.
CREATE FUNCTION school.record_payment(p_enrollment uuid, p_amount numeric, p_paid_on date, p_method text, p_external_ref text,
  p_overpayment boolean, p_key uuid) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE p school.payment;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('payment_key:' || p_key::text, 49));
  SELECT * INTO p FROM school.payment WHERE tenant_id = school.tenant() AND idempotency_key = p_key;
  IF p.id IS NULL THEN
    INSERT INTO school.payment (tenant_id, enrollment_id, kind, amount, paid_on, method_code, external_ref, overpayment, idempotency_key)
    VALUES (school.tenant(), p_enrollment, 'payment', p_amount, p_paid_on, p_method, p_external_ref, coalesce(p_overpayment, false), p_key) RETURNING * INTO p;
  ELSIF (p.enrollment_id, p.kind, p.amount) IS DISTINCT FROM (p_enrollment, 'payment', p_amount) THEN
    RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514';
  END IF;
  RETURN p.id;
END $$;

-- FR-FIN-03: the reversal of a validated payment, with its reason, once per key.
CREATE FUNCTION school.reverse_payment(p_payment uuid, p_reason text, p_key uuid) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE p school.payment; target school.payment;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('payment_key:' || p_key::text, 49));
  SELECT * INTO p FROM school.payment WHERE tenant_id = school.tenant() AND idempotency_key = p_key;
  IF p.id IS NOT NULL THEN
    IF p.reverses_payment_id IS DISTINCT FROM p_payment THEN RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514'; END IF;
    RETURN p.id;
  END IF;
  SELECT * INTO target FROM school.payment WHERE tenant_id = school.tenant() AND id = p_payment;
  IF target.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  -- One reversal at a time of a payment: the second one asked for reads the first.
  IF EXISTS (SELECT 1 FROM school.payment r WHERE r.tenant_id = target.tenant_id AND r.reverses_payment_id = target.id AND r.status <> 'cancelled') THEN
    RAISE EXCEPTION 'payment_not_reversible' USING ERRCODE = '23514';
  END IF;
  INSERT INTO school.payment (tenant_id, enrollment_id, kind, reverses_payment_id, amount, paid_on, reason, idempotency_key)
  VALUES (target.tenant_id, target.enrollment_id, 'reversal', target.id, target.amount, current_date, p_reason, p_key) RETURNING * INTO p;
  RETURN p.id;
END $$;

-- Who reads the payments: `finance.payment.read`, `validate` or `reverse` for the whole school or the
-- pupil's site; whoever recorded one reads it. Recording, correcting and deciding are the trigger's.
CREATE POLICY resource_reader ON school.payment AS RESTRICTIVE FOR SELECT TO school_runtime
  USING (created_by = (SELECT school.actor()) OR (SELECT school.finance_in_scope('finance.payment.read', enrollment_id))
    OR (SELECT school.finance_in_scope('finance.payment.validate', enrollment_id)) OR (SELECT school.finance_in_scope('finance.payment.reverse', enrollment_id)));
CREATE POLICY resource_creator ON school.payment AS RESTRICTIVE FOR INSERT TO school_runtime
  WITH CHECK ((SELECT school.finance_in_scope('finance.payment.create', enrollment_id)) OR (SELECT school.finance_in_scope('finance.payment.create.delegated', enrollment_id))
    OR (SELECT school.finance_in_scope('finance.payment.reverse', enrollment_id)));
CREATE POLICY resource_writer ON school.payment AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING (created_by = (SELECT school.actor()) OR (SELECT school.finance_in_scope('finance.payment.validate', enrollment_id)))
  WITH CHECK (created_by = (SELECT school.actor()) OR (SELECT school.finance_in_scope('finance.payment.validate', enrollment_id)));

-- FR-INS-04: what is paid for the access to courses: the validated payments net of their reversals,
-- `full` covering what is owed, `first_installment` the first instalment of the schedule in force
-- (without a schedule, any payment). An enrolment owing nothing is paid. Replaces 048's.
CREATE OR REPLACE FUNCTION school.enrollment_paid(p_enrollment uuid, p_level text) RETURNS boolean
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); d numeric; p numeric; first_amount numeric;
BEGIN
  IF p_level = 'none' THEN RETURN true; END IF;
  d := platform.enrollment_due_of(t, p_enrollment);
  IF d IS NULL THEN RETURN false; END IF;
  IF d <= 0 THEN RETURN true; END IF;
  p := platform.enrollment_paid_of(t, p_enrollment);
  IF p_level = 'full' THEN RETURN p >= d; END IF;
  SELECT i.amount INTO first_amount FROM school.installment i
  WHERE i.tenant_id = t AND i.seq = 1 AND i.schedule_id = (SELECT s.id FROM school.payment_schedule s WHERE s.tenant_id = t AND s.enrollment_id = p_enrollment
    ORDER BY s.version DESC LIMIT 1);
  RETURN CASE WHEN first_amount IS NULL THEN p > 0 ELSE p >= first_amount END;
END $$;

-- The journal: in the finance's domain, read with the payments.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('payment', 'finance', '{finance.payment.read}');
