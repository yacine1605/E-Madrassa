-- Lot 3, the financial period's close (SRS 1.2, FR-FIN-07; BR-15, BR-08): a month closed by the
-- finance for the whole school, once nothing dated in it waits for its validation, with its
-- reconciliation report; afterwards nothing is recorded, corrected, validated or executed with a
-- date in it (AC1), corrections go through linked operations in the open period. Reopened only
-- by `finance.period.reopen`, with its reason, never by whoever closed it, journalled (AC2).

CREATE TABLE school.finance_period (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  period_start date NOT NULL CHECK (extract(day FROM period_start) = 1),
  period_end date NOT NULL,
  status text NOT NULL DEFAULT 'closed' CHECK (status IN ('closed', 'reopened')),
  report jsonb NOT NULL CHECK (jsonb_typeof(report) = 'object'),
  reopen_reason text CHECK (length(reopen_reason) BETWEEN 3 AND 500),
  reopened_by bigint REFERENCES public.users(id),
  reopened_at timestamptz,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK (period_end = (period_start + interval '1 month' - interval '1 day')::date),
  CHECK ((status = 'reopened') = (reopened_at IS NOT NULL AND reopen_reason IS NOT NULL)),
  UNIQUE (tenant_id, id)
);
-- A month closed once at a time; reopened, it may be closed again (a new closure, the former kept).
CREATE UNIQUE INDEX finance_period_closed ON school.finance_period (tenant_id, period_start) WHERE status = 'closed';
CALL school.protect('school.finance_period');

-- Whether a date falls in a closed month of the school.
CREATE FUNCTION school.period_closed(p_tenant uuid, p_day date) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT p_day IS NOT NULL AND EXISTS (SELECT 1 FROM school.finance_period f WHERE f.tenant_id = p_tenant AND f.status = 'closed'
    AND p_day BETWEEN f.period_start AND f.period_end) $$;

-- AC1: no payment recorded, moved or validated, no refund executed, with a date in a closed month.
-- A reversal is dated the day it is asked for, in the open period; a payment reversed keeps its date.
-- Each holds the school's closing lock shared, so a closure waits for it and then sees it.
CREATE FUNCTION school.guard_closed_period() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE day date;
BEGIN
  IF TG_TABLE_NAME = 'payment' THEN
    IF TG_OP = 'INSERT' OR NEW.paid_on IS DISTINCT FROM OLD.paid_on OR (NEW.status = 'validated' AND OLD.status = 'pending_validation') THEN
      day := NEW.paid_on;
    END IF;
  ELSIF NEW.status = 'executed' AND OLD.status <> 'executed' THEN
    day := coalesce(NEW.paid_on, current_date);
  END IF;
  IF day IS NULL THEN RETURN NEW; END IF;
  PERFORM pg_advisory_xact_lock_shared(hashtextextended('finance_period:' || NEW.tenant_id::text, 53));
  IF school.period_closed(NEW.tenant_id, day) THEN RAISE EXCEPTION 'period_closed' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
-- Before the payments' and refunds' own guards (« closed » sorts before « guard »).
CREATE TRIGGER closed BEFORE INSERT OR UPDATE ON school.payment FOR EACH ROW EXECUTE FUNCTION school.guard_closed_period();
CREATE TRIGGER closed BEFORE UPDATE ON school.refund FOR EACH ROW EXECUTE FUNCTION school.guard_closed_period();

-- The reconciliation of a month: the validated payments dated in it by means, the reversals and
-- the refunds dated in it, the carry-overs between enrolments, and what came in net.
CREATE FUNCTION platform.reconciliation(p_tenant uuid, p_from date, p_to date) RETURNS jsonb
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
  )
  SELECT jsonb_build_object('from', p_from, 'to', p_to,
    'payments', coalesce((SELECT jsonb_object_agg(method_code, jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text)) FROM payments), '{}'),
    'payments_total', (SELECT coalesce(sum(amount), 0)::numeric(14, 2)::text FROM payments),
    'reversals', (SELECT jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text) FROM reversals),
    'refunds', coalesce((SELECT jsonb_object_agg(method_code, jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text)) FROM refunds), '{}'),
    'refunds_total', (SELECT coalesce(sum(amount), 0)::numeric(14, 2)::text FROM refunds),
    'carry_overs', (SELECT jsonb_build_object('count', n, 'amount', amount::numeric(14, 2)::text) FROM carried),
    'net', ((SELECT coalesce(sum(amount), 0) FROM payments) - (SELECT amount FROM reversals) - (SELECT coalesce(sum(amount), 0) FROM refunds))::numeric(14, 2)::text) $$;

-- The reconciliation of a month of the school, read before its closure by whoever closes or reads
-- the whole school's payments; with what still waits for its validation in it.
CREATE FUNCTION school.reconciliation(p_month date) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); first_day date := date_trunc('month', p_month)::date; last_day date;
BEGIN
  IF t IS NULL OR NOT (school.can_everywhere('finance.period.close') OR school.can_everywhere('finance.payment.read')) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  last_day := (first_day + interval '1 month' - interval '1 day')::date;
  RETURN platform.reconciliation(t, first_day, last_day) || jsonb_build_object(
    'pending', (SELECT count(*) FROM school.payment p WHERE p.tenant_id = t AND p.status = 'pending_validation' AND p.paid_on BETWEEN first_day AND last_day),
    'closed', school.period_closed(t, first_day));
END $$;

-- FR-FIN-07: a past month closed for the whole school by `finance.period.close`, once no payment
-- dated in it waits for its validation; its reconciliation written with it. Once at a time.
CREATE FUNCTION school.close_period(p_month date) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); first_day date := date_trunc('month', p_month)::date; last_day date; id uuid;
BEGIN
  IF t IS NULL OR NOT school.can_everywhere('finance.period.close') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  last_day := (first_day + interval '1 month' - interval '1 day')::date;
  IF last_day >= current_date THEN RAISE EXCEPTION 'period_not_ended' USING ERRCODE = '23514'; END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended('finance_period:' || t::text, 53));
  IF school.period_closed(t, first_day) THEN RAISE EXCEPTION 'period_closed' USING ERRCODE = '23514'; END IF;
  IF EXISTS (SELECT 1 FROM school.payment p WHERE p.tenant_id = t AND p.status = 'pending_validation' AND p.paid_on BETWEEN first_day AND last_day) THEN
    RAISE EXCEPTION 'period_has_pending' USING ERRCODE = '23514';
  END IF;
  INSERT INTO school.finance_period (tenant_id, period_start, period_end, report) VALUES (t, first_day, last_day, platform.reconciliation(t, first_day, last_day))
  RETURNING school.finance_period.id INTO id;
  RETURN id;
END $$;

-- AC2: reopened by `finance.period.reopen` for the whole school, with its reason, never by whoever
-- closed it (sod rule `finance_period`); a closure never changes otherwise, nor is it deleted.
CREATE FUNCTION school.guard_finance_period() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'INSERT' THEN
    NEW.status := 'closed'; NEW.reopen_reason := NULL; NEW.reopened_by := NULL; NEW.reopened_at := NULL;
    RETURN NEW;
  END IF;
  IF (NEW.period_start, NEW.period_end, NEW.report) IS DISTINCT FROM (OLD.period_start, OLD.period_end, OLD.report) OR OLD.status <> 'closed' OR NEW.status <> 'reopened' THEN
    RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
  END IF;
  IF NOT school.can_everywhere('finance.period.reopen') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  PERFORM school.check_segregation('finance_period', OLD.created_by);
  NEW.reopen_reason := nullif(btrim(NEW.reopen_reason), '');
  IF NEW.reopen_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  NEW.reopened_by := school.actor(); NEW.reopened_at := now();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.finance_period FOR EACH ROW EXECUTE FUNCTION school.guard_finance_period();

-- Who reads the closures: whoever reads the payments, closes or reopens.
CREATE POLICY resource_reader ON school.finance_period AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.can('finance.payment.read')) OR (SELECT school.can('finance.period.close')) OR (SELECT school.can('finance.period.reopen')));
CREATE POLICY resource_writer ON school.finance_period AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('finance.period.reopen'))) WITH CHECK ((SELECT school.can_everywhere('finance.period.reopen')));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('finance_period', 'finance', '{finance.payment.read}');
