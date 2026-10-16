-- Lot 3, refunds and overpayments (SRS 1.2, FR-FIN-04; BR-03, BR-05, BR-06, BR-08): a circuit of
-- its own, distinct from payments. The finance asks (`finance.refund.request`), the direction
-- approves or rejects (`finance.refund.approve`), never the one who asked; once approved, the
-- finance records it, which takes it out of what is paid. An enrolment's credit (paid beyond what
-- it owes) is decided explicitly: refunded, carried over to another enrolment of the same pupil
-- (a linked payment there, validated by the finance), or kept with its reason. Cancelling an
-- enrolment moves no money (AC1).

-- A reference per refund recorded, `RMB-{year}-{six digits}`, unique in the school, never given again.
CREATE TABLE school.refund_counter (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  year integer NOT NULL,
  last integer NOT NULL CHECK (last >= 1),
  PRIMARY KEY (tenant_id, year)
);
ALTER TABLE school.refund_counter ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.refund_counter FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.refund_counter USING (tenant_id = (SELECT school.allowed_tenant()))
  WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON school.refund_counter FOR EACH ROW EXECUTE FUNCTION school.guard_writable();

-- A refund (`refund`, money given back) or a carry-over (`carry_over`, to another enrolment of the
-- same pupil), asked with its reason, decided, then executed. Neither is ever deleted.
CREATE TABLE school.refund (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  enrollment_id uuid NOT NULL,
  kind text NOT NULL DEFAULT 'refund' CHECK (kind IN ('refund', 'carry_over')),
  target_enrollment_id uuid,
  amount numeric(14, 2) NOT NULL CHECK (amount > 0),
  reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
  status text NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'approved', 'rejected', 'executed')),
  decided_by bigint REFERENCES public.users(id),
  decided_at timestamptz,
  decision_reason text CHECK (length(decision_reason) BETWEEN 3 AND 500),
  -- Its execution: a refund's means, date and outside reference; its own reference.
  reference text CHECK (reference ~ '^RMB-[0-9]{4}-[0-9]{6}$'),
  method_code text CHECK (method_code ~ '^[a-z_]{1,20}$'),
  paid_on date,
  external_ref text CHECK (length(external_ref) BETWEEN 1 AND 100),
  executed_by bigint REFERENCES public.users(id),
  executed_at timestamptz,
  idempotency_key uuid NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK ((kind = 'carry_over') = (target_enrollment_id IS NOT NULL)),
  CHECK (target_enrollment_id IS DISTINCT FROM enrollment_id),
  CHECK ((status = 'requested') = (decided_at IS NULL)),
  CHECK (status <> 'rejected' OR decision_reason IS NOT NULL),
  CHECK ((status = 'executed') = (executed_at IS NOT NULL AND reference IS NOT NULL)),
  CHECK (status <> 'executed' OR kind = 'carry_over' OR (method_code IS NOT NULL AND paid_on IS NOT NULL)),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, reference),
  UNIQUE (tenant_id, idempotency_key),
  FOREIGN KEY (tenant_id, enrollment_id) REFERENCES school.enrollment (tenant_id, id),
  FOREIGN KEY (tenant_id, target_enrollment_id) REFERENCES school.enrollment (tenant_id, id)
);
CREATE INDEX refund_enrollment ON school.refund (tenant_id, enrollment_id);
CREATE INDEX refund_requested ON school.refund (tenant_id, created_at) WHERE status = 'requested';
CALL school.protect('school.refund');

-- A carry-over's payment on the target enrolment, linked to it, once.
ALTER TABLE school.payment ADD COLUMN carried_from_refund_id uuid;
ALTER TABLE school.payment ADD FOREIGN KEY (tenant_id, carried_from_refund_id) REFERENCES school.refund (tenant_id, id);
CREATE UNIQUE INDEX payment_one_carry ON school.payment (tenant_id, carried_from_refund_id) WHERE carried_from_refund_id IS NOT NULL;

-- What is paid: the validated payments, less their validated reversals, less what was refunded
-- or carried over. Replaces 049's.
CREATE OR REPLACE FUNCTION platform.enrollment_paid_of(p_tenant uuid, p_enrollment uuid) RETURNS numeric
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT (coalesce((SELECT sum(CASE WHEN p.kind = 'payment' THEN p.amount ELSE -p.amount END) FROM school.payment p
    WHERE p.tenant_id = p_tenant AND p.enrollment_id = p_enrollment AND (p.status = 'validated' OR (p.kind = 'payment' AND p.status = 'reversed'))), 0)
    - coalesce((SELECT sum(r.amount) FROM school.refund r WHERE r.tenant_id = p_tenant AND r.enrollment_id = p_enrollment AND r.status = 'executed'), 0))::numeric(14, 2) $$;

-- What is owed: as 048's, a credit kept (`credit_retained`) counted as owed. Replaces 048's.
ALTER TABLE school.adjustment DROP CONSTRAINT adjustment_kind_check;
ALTER TABLE school.adjustment ADD CONSTRAINT adjustment_kind_check CHECK (kind IN ('discount', 'debt_waiver', 'penalty', 'credit_retained'));
CREATE OR REPLACE FUNCTION platform.enrollment_due_of(p_tenant uuid, p_enrollment uuid) RETURNS numeric
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT e.agreed_price - coalesce((SELECT sum(CASE WHEN a.kind IN ('penalty', 'credit_retained') THEN -a.amount ELSE a.amount END) FROM school.adjustment a
    WHERE a.tenant_id = e.tenant_id AND a.enrollment_id = e.id AND a.status = 'approved'), 0)
  FROM school.enrollment e WHERE e.tenant_id = p_tenant AND e.id = p_enrollment $$;

-- FR-FIN-02 step 1, FR-FIN-04: as 049's, with what was refunded or carried over, what waits to be,
-- and the credit (paid beyond what is owed). Replaces 049's.
DROP FUNCTION school.payment_balance(uuid, uuid);
CREATE FUNCTION school.payment_balance(p_enrollment uuid, p_except uuid DEFAULT NULL)
RETURNS TABLE (due numeric, paid numeric, pending numeric, balance numeric, state text, refunded numeric, refunds_pending numeric, credit numeric)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); d numeric; p numeric; w numeric; r numeric; rw numeric;
BEGIN
  IF NOT (school.finance_in_scope('finance.payment.read', p_enrollment) OR school.finance_in_scope('finance.payment.create', p_enrollment)
          OR school.finance_in_scope('finance.payment.create.delegated', p_enrollment) OR school.finance_in_scope('finance.payment.validate', p_enrollment)
          OR school.finance_in_scope('finance.payment.reverse', p_enrollment) OR school.finance_in_scope('finance.discount.grant', p_enrollment)
          OR school.finance_in_scope('finance.schedule.manage', p_enrollment) OR school.finance_in_scope('finance.discount.approve', p_enrollment)
          OR school.finance_in_scope('finance.refund.request', p_enrollment) OR school.finance_in_scope('finance.refund.approve', p_enrollment)) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  d := platform.enrollment_due_of(t, p_enrollment);
  IF d IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  p := platform.enrollment_paid_of(t, p_enrollment);
  SELECT coalesce(sum(x.amount), 0)::numeric(14, 2) INTO w FROM school.payment x WHERE x.tenant_id = t AND x.enrollment_id = p_enrollment
    AND x.kind = 'payment' AND x.status = 'pending_validation' AND x.id IS DISTINCT FROM p_except;
  SELECT coalesce(sum(x.amount) FILTER (WHERE x.status = 'executed'), 0)::numeric(14, 2), coalesce(sum(x.amount) FILTER (WHERE x.status IN ('requested', 'approved')), 0)::numeric(14, 2)
    INTO r, rw FROM school.refund x WHERE x.tenant_id = t AND x.enrollment_id = p_enrollment;
  RETURN QUERY SELECT d, p, w, d - p, CASE WHEN p >= d THEN 'paid' WHEN p > 0 THEN 'partially_paid' ELSE 'unpaid' END, r, rw, greatest(p - d, 0)::numeric(14, 2);
END $$;

-- 049's checks, but for a carry-over's payment: its means is the carry-over, and beyond what is left
-- to pay it is an overpayment of the target, never refused. Replaces 049's.
CREATE OR REPLACE FUNCTION school.check_payment(p school.payment) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE b record;
BEGIN
  IF p.paid_on > current_date THEN RAISE EXCEPTION 'payment_future_date' USING ERRCODE = '23514'; END IF;
  IF p.kind = 'payment' AND p.carried_from_refund_id IS NULL AND NOT (school.setting_value('finance.payment.methods') ? p.method_code) THEN
    RAISE EXCEPTION 'payment_method_unknown' USING ERRCODE = '23514';
  END IF;
  IF p.kind = 'reversal' THEN RETURN false; END IF;
  SELECT * INTO b FROM school.payment_balance(p.enrollment_id, p.id);
  IF p.amount > b.balance - b.pending THEN
    IF NOT p.overpayment AND p.carried_from_refund_id IS NULL THEN
      RAISE EXCEPTION 'payment_exceeds_balance' USING ERRCODE = '23514', HINT = greatest(b.balance - b.pending, 0)::text;
    END IF;
    RETURN true;
  END IF;
  RETURN false;
END $$;

-- A carry-over's payment is made by its execution only, as it says: its amount, today, its means.
CREATE FUNCTION school.guard_carried_payment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r school.refund;
BEGIN
  IF TG_OP = 'UPDATE' THEN
    IF NEW.carried_from_refund_id IS DISTINCT FROM OLD.carried_from_refund_id THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
    -- Its author does not correct it: it is what the refund carried.
    -- nor withdraws it: the money left the source enrolment.
    IF NEW.carried_from_refund_id IS NOT NULL AND (NEW.status = 'cancelled'
       OR (NEW.amount, NEW.paid_on, NEW.method_code, NEW.external_ref) IS DISTINCT FROM (OLD.amount, OLD.paid_on, OLD.method_code, OLD.external_ref)) THEN
      RAISE EXCEPTION 'payment_carried' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF NEW.carried_from_refund_id IS NULL THEN
    IF NEW.method_code = 'carry_over' THEN RAISE EXCEPTION 'payment_method_unknown' USING ERRCODE = '23514'; END IF;
    -- A carried payment is not reversed either: what the source gave stays given.
    IF NEW.kind = 'reversal' AND EXISTS (SELECT 1 FROM school.payment x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.reverses_payment_id
        AND x.carried_from_refund_id IS NOT NULL) THEN
      RAISE EXCEPTION 'payment_carried' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  SELECT * INTO r FROM school.refund WHERE tenant_id = NEW.tenant_id AND id = NEW.carried_from_refund_id;
  IF r.id IS NULL OR r.kind <> 'carry_over' OR r.status <> 'executed' OR r.target_enrollment_id <> NEW.enrollment_id OR NEW.kind <> 'payment' THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  NEW.amount := r.amount; NEW.paid_on := current_date; NEW.method_code := 'carry_over'; NEW.external_ref := r.reference;
  RETURN NEW;
END $$;
-- Before 049's guard (« carried » sorts before « guard »), which then checks it as any payment.
CREATE TRIGGER carried BEFORE INSERT OR UPDATE ON school.payment FOR EACH ROW EXECUTE FUNCTION school.guard_carried_payment();

-- The next refund reference of the school's year, for whoever records refunds.
CREATE FUNCTION school.next_refund_reference(p_tenant uuid) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE y integer := extract(year FROM current_date)::integer; n integer;
BEGIN
  IF p_tenant IS DISTINCT FROM school.allowed_tenant() OR NOT school.can('finance.refund.request') THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  INSERT INTO school.refund_counter AS c (tenant_id, year, last) VALUES (p_tenant, y, 1)
  ON CONFLICT (tenant_id, year) DO UPDATE SET last = c.last + 1 RETURNING c.last INTO n;
  IF n > 999999 THEN RAISE EXCEPTION 'payment_reference_exhausted' USING ERRCODE = '23514'; END IF;
  RETURN 'RMB-' || y::text || '-' || lpad(n::text, 6, '0');
END $$;

CREATE FUNCTION school.guard_refund() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e school.enrollment; target school.enrollment; asked numeric;
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  -- One change at a time of an enrolment's finance (BR-06).
  PERFORM pg_advisory_xact_lock(hashtextextended('enrollment_finance:' || NEW.enrollment_id::text, 48));
  IF TG_OP = 'INSERT' THEN
    IF NOT school.finance_in_scope('finance.refund.request', NEW.enrollment_id) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    NEW.reason := nullif(btrim(NEW.reason), '');
    IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    SELECT * INTO e FROM school.enrollment WHERE tenant_id = NEW.tenant_id AND id = NEW.enrollment_id;
    IF e.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    IF NEW.kind = 'carry_over' THEN
      -- To another enrolment of the same pupil, still open.
      SELECT * INTO target FROM school.enrollment WHERE tenant_id = NEW.tenant_id AND id = NEW.target_enrollment_id;
      IF target.id IS NULL OR target.student_id <> e.student_id OR target.status = 'cancelled' THEN
        RAISE EXCEPTION 'refund_target_invalid' USING ERRCODE = '23514';
      END IF;
    END IF;
    -- Never beyond what is paid, the refunds asked for and approved counted.
    SELECT coalesce(sum(r.amount), 0) INTO asked FROM school.refund r WHERE r.tenant_id = NEW.tenant_id AND r.enrollment_id = NEW.enrollment_id
      AND r.status IN ('requested', 'approved');
    IF NEW.amount > (SELECT b.paid FROM school.payment_balance(NEW.enrollment_id) b) - asked THEN
      RAISE EXCEPTION 'refund_exceeds_paid' USING ERRCODE = '23514';
    END IF;
    NEW.status := 'requested'; NEW.decided_by := NULL; NEW.decided_at := NULL; NEW.decision_reason := NULL; NEW.reference := NULL;
    NEW.method_code := NULL; NEW.paid_on := NULL; NEW.external_ref := NULL; NEW.executed_by := NULL; NEW.executed_at := NULL;
    RETURN NEW;
  END IF;
  IF (NEW.enrollment_id, NEW.kind, NEW.target_enrollment_id, NEW.amount, NEW.reason, NEW.idempotency_key)
     IS DISTINCT FROM (OLD.enrollment_id, OLD.kind, OLD.target_enrollment_id, OLD.amount, OLD.reason, OLD.idempotency_key) THEN
    RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
  END IF;
  -- AC2: never executed without its approval.
  IF NEW.status = 'executed' AND OLD.status IN ('requested', 'rejected') THEN RAISE EXCEPTION 'refund_not_approved' USING ERRCODE = '23514'; END IF;
  IF OLD.status IN ('rejected', 'executed') OR NEW.status = OLD.status THEN
    IF NEW IS DISTINCT FROM OLD AND (to_jsonb(NEW) - ARRAY['version', 'updated_at', 'updated_by']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['version', 'updated_at', 'updated_by']) THEN
      RAISE EXCEPTION 'refund_decided' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF OLD.status = 'requested' AND NEW.status IN ('approved', 'rejected') THEN
    -- The direction decides, never the one who asked (BR-08).
    IF NOT school.finance_in_scope('finance.refund.approve', OLD.enrollment_id) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    PERFORM school.check_segregation('refund', OLD.created_by);
    NEW.decision_reason := nullif(btrim(NEW.decision_reason), '');
    IF NEW.status = 'rejected' AND NEW.decision_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    IF (NEW.reference, NEW.method_code, NEW.paid_on, NEW.external_ref) IS DISTINCT FROM (OLD.reference, OLD.method_code, OLD.paid_on, OLD.external_ref) THEN
      RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
    END IF;
    NEW.decided_by := school.actor(); NEW.decided_at := now();
    RETURN NEW;
  END IF;
  IF NEW.status <> 'executed' THEN RAISE EXCEPTION 'refund_decided' USING ERRCODE = '23514'; END IF;
  IF NOT school.finance_in_scope('finance.refund.request', OLD.enrollment_id) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF (NEW.decided_by, NEW.decided_at, NEW.decision_reason) IS DISTINCT FROM (OLD.decided_by, OLD.decided_at, OLD.decision_reason) THEN
    RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
  END IF;
  -- What is paid may have changed since (a reversal): never beyond it.
  IF OLD.amount > (SELECT b.paid FROM school.payment_balance(OLD.enrollment_id) b) THEN RAISE EXCEPTION 'refund_exceeds_paid' USING ERRCODE = '23514'; END IF;
  IF OLD.kind = 'refund' THEN
    NEW.external_ref := nullif(btrim(NEW.external_ref), '');
    IF NEW.method_code IS NULL OR NEW.paid_on IS NULL THEN RAISE EXCEPTION 'refund_execution_incomplete' USING ERRCODE = '23514'; END IF;
    IF NEW.paid_on > current_date THEN RAISE EXCEPTION 'payment_future_date' USING ERRCODE = '23514'; END IF;
    IF NOT (school.setting_value('finance.payment.methods') ? NEW.method_code) THEN RAISE EXCEPTION 'payment_method_unknown' USING ERRCODE = '23514'; END IF;
  ELSE
    SELECT * INTO target FROM school.enrollment WHERE tenant_id = OLD.tenant_id AND id = OLD.target_enrollment_id;
    IF target.status = 'cancelled' THEN RAISE EXCEPTION 'refund_target_invalid' USING ERRCODE = '23514'; END IF;
    NEW.method_code := NULL; NEW.paid_on := current_date; NEW.external_ref := NULL;
  END IF;
  NEW.reference := school.next_refund_reference(OLD.tenant_id);
  NEW.executed_by := school.actor(); NEW.executed_at := now();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.refund FOR EACH ROW EXECUTE FUNCTION school.guard_refund();

-- A carry-over executed pays the target enrolment: a payment linked to it, waiting for the
-- finance's validation as any other (BR-08), in the same transaction (BR-06).
CREATE FUNCTION school.apply_carry_over() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO school.payment (tenant_id, enrollment_id, kind, amount, paid_on, method_code, carried_from_refund_id, idempotency_key)
  VALUES (NEW.tenant_id, NEW.target_enrollment_id, 'payment', NEW.amount, current_date, 'carry_over', NEW.id, gen_random_uuid());
  RETURN NULL;
END $$;
CREATE TRIGGER carry_over AFTER UPDATE OF status ON school.refund FOR EACH ROW
  WHEN (NEW.kind = 'carry_over' AND NEW.status = 'executed' AND OLD.status = 'approved') EXECUTE FUNCTION school.apply_carry_over();

-- A refund asked for once per key (BR-05), even at the same moment.
CREATE FUNCTION school.request_refund(p_enrollment uuid, p_kind text, p_target uuid, p_amount numeric, p_reason text, p_key uuid) RETURNS uuid
LANGUAGE plpgsql AS $$
DECLARE r school.refund;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('refund_key:' || p_key::text, 50));
  SELECT * INTO r FROM school.refund WHERE tenant_id = school.tenant() AND idempotency_key = p_key;
  IF r.id IS NULL THEN
    INSERT INTO school.refund (tenant_id, enrollment_id, kind, target_enrollment_id, amount, reason, idempotency_key)
    VALUES (school.tenant(), p_enrollment, p_kind, p_target, p_amount, p_reason, p_key) RETURNING * INTO r;
  ELSIF (r.enrollment_id, r.kind, r.target_enrollment_id, r.amount) IS DISTINCT FROM (p_enrollment, p_kind, p_target, p_amount) THEN
    RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514';
  END IF;
  RETURN r.id;
END $$;

-- FR-FIN-04: a credit kept by the school (`credit_retained`), with its reason, within the credit;
-- applied at once, as a penalty. 048's rules otherwise. Replaces 048's.
CREATE OR REPLACE FUNCTION school.guard_adjustment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e school.enrollment; granted numeric; threshold integer;
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'INSERT' THEN
    IF NOT school.finance_in_scope('finance.discount.grant', NEW.enrollment_id) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    NEW.reason := nullif(btrim(NEW.reason), '');
    -- AC2: no adjustment without its reason.
    IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    -- One adjustment at a time of an enrolment: the second reads the first's.
    PERFORM pg_advisory_xact_lock(hashtextextended('enrollment_finance:' || NEW.enrollment_id::text, 48));
    SELECT * INTO e FROM school.enrollment WHERE tenant_id = NEW.tenant_id AND id = NEW.enrollment_id;
    IF e.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    -- A credit kept: never more than the credit (paid beyond what is owed).
    IF NEW.kind = 'credit_retained' AND NEW.amount > (SELECT b.credit FROM school.payment_balance(e.id) b) THEN
      RAISE EXCEPTION 'credit_exceeded' USING ERRCODE = '23514';
    END IF;
    -- Never below nothing owed: the discounts and waivers within the price and the penalties.
    IF NEW.kind IN ('discount', 'debt_waiver') AND school.enrollment_due(e.id) - coalesce((SELECT sum(a.amount) FROM school.adjustment a
        WHERE a.tenant_id = e.tenant_id AND a.enrollment_id = e.id AND a.status = 'pending' AND a.kind IN ('discount', 'debt_waiver')), 0) - NEW.amount < 0 THEN
      RAISE EXCEPTION 'adjustment_exceeds_due' USING ERRCODE = '23514';
    END IF;
    NEW.decided_by := NULL; NEW.decision_reason := NULL;
    -- Within the threshold (all discounts and waivers of the enrolment together), approved at once; a penalty or a credit kept always.
    threshold := (school.setting_value('finance.discount.approval_percent') #>> '{}')::integer;
    granted := coalesce((SELECT sum(a.amount) FROM school.adjustment a WHERE a.tenant_id = e.tenant_id AND a.enrollment_id = e.id
      AND a.status IN ('pending', 'approved') AND a.kind IN ('discount', 'debt_waiver')), 0) + CASE WHEN NEW.kind IN ('discount', 'debt_waiver') THEN NEW.amount ELSE 0 END;
    IF NEW.kind IN ('penalty', 'credit_retained') OR granted * 100 <= e.agreed_price * threshold THEN
      NEW.status := 'approved'; NEW.decided_at := now();
    ELSE
      NEW.status := 'pending'; NEW.decided_at := NULL;
    END IF;
    RETURN NEW;
  END IF;
  IF (NEW.enrollment_id, NEW.kind, NEW.amount, NEW.reason, NEW.idempotency_key) IS DISTINCT FROM (OLD.enrollment_id, OLD.kind, OLD.amount, OLD.reason, OLD.idempotency_key) THEN
    RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = OLD.status THEN
    IF (NEW.decided_by, NEW.decided_at, NEW.decision_reason) IS DISTINCT FROM (OLD.decided_by, OLD.decided_at, OLD.decision_reason) THEN
      RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF OLD.status <> 'pending' THEN RAISE EXCEPTION 'adjustment_decided' USING ERRCODE = '23514'; END IF;
  IF NOT school.finance_in_scope('finance.discount.approve', NEW.enrollment_id) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  PERFORM school.check_segregation('adjustment', OLD.created_by);
  NEW.decision_reason := nullif(btrim(NEW.decision_reason), '');
  IF NEW.status = 'rejected' AND NEW.decision_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  NEW.decided_by := school.actor(); NEW.decided_at := now();
  RETURN NEW;
END $$;

-- Who reads the refunds: whoever reads the payments, asks for refunds or approves them, for the
-- whole school or the pupil's site. Asking, deciding and executing are the trigger's.
CREATE POLICY resource_reader ON school.refund AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.finance_in_scope('finance.payment.read', enrollment_id)) OR (SELECT school.finance_in_scope('finance.refund.request', enrollment_id))
    OR (SELECT school.finance_in_scope('finance.refund.approve', enrollment_id)));
CREATE POLICY resource_creator ON school.refund AS RESTRICTIVE FOR INSERT TO school_runtime
  WITH CHECK ((SELECT school.finance_in_scope('finance.refund.request', enrollment_id)));
CREATE POLICY resource_writer ON school.refund AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.finance_in_scope('finance.refund.request', enrollment_id)) OR (SELECT school.finance_in_scope('finance.refund.approve', enrollment_id)))
  WITH CHECK ((SELECT school.finance_in_scope('finance.refund.request', enrollment_id)) OR (SELECT school.finance_in_scope('finance.refund.approve', enrollment_id)));

-- The journal: in the finance's domain, read with the payments.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('refund', 'finance', '{finance.payment.read}');
