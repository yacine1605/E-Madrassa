-- Lot 3, what an enrolment owes and when (SRS 1.2, FR-FIN-01; BR-03, BR-04, BR-05, BR-08): its
-- adjustments (discounts, debt waivers, penalties), each with its reason, approved by the direction
-- beyond the school's threshold; and its schedule of instalments, versioned, whose sum is always
-- what is owed (AC1). Amounts are exact, two decimals, in dinars (BR-04).

INSERT INTO platform.permission (code, module_key, sensitive, labels) VALUES
  ('finance.discount.approve', 'student_finance', true, '{"fr": "Approuver une remise ou une annulation de dette", "ar": "الموافقة على تخفيض أو إلغاء دين"}');
-- Annex A: the accountant grants, the direction approves beyond the threshold (« + approbation direction »).
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES ('direction', 'finance.discount.approve');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, 'finance.discount.approve' FROM school.role r
WHERE r.template_code = 'direction' AND platform.tenant_mode(r.tenant_id) = 'active'
  AND EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = r.tenant_id AND m.module_key = 'student_finance')
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = 'finance.discount.approve');
-- BR-08: whoever grants an adjustment does not approve it.
INSERT INTO platform.sod_rule (entity, entry_action, approve_action, exception_setting) VALUES
  ('adjustment', 'finance.discount.grant', 'finance.discount.approve', NULL);

-- OD-09: the threshold beyond which the direction approves, as a share of the agreed price that the
-- enrolment's discounts and waivers reach together. A proposal each school changes.
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('finance.discount.approval_percent', 'integer', '10', 0, 100, 'tenant.settings.manage',
   '{"fr": "Approbation de la direction au-delà de (% du prix convenu, remises cumulées)", "ar": "موافقة الإدارة بعد تجاوز (% من السعر المتفق عليه، تخفيضات مجمعة)"}');

-- Whether the writer's grant of an action reaches an enrolment: the whole school, or its pupil's site.
CREATE FUNCTION school.finance_in_scope(p_permission text, p_enrollment uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT school.allowed_tenant() IS NOT NULL AND (school.can_everywhere(p_permission) OR EXISTS (
    SELECT 1 FROM school.enrollment e JOIN school.student s ON s.tenant_id = e.tenant_id AND s.id = e.student_id
    WHERE e.tenant_id = school.allowed_tenant() AND e.id = p_enrollment AND s.site_id = ANY (school.site_scope_ids(p_permission, 'site')))) $$;

-- FR-FIN-01: an adjustment of what an enrolment owes. Granted with its reason, approved at once
-- within the threshold, else pending the direction's decision (approved or rejected, with its
-- reason, by someone else than its author). Decided, it never changes (BR-03).
CREATE TABLE school.adjustment (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  enrollment_id uuid NOT NULL,
  kind text NOT NULL CHECK (kind IN ('discount', 'debt_waiver', 'penalty')),
  amount numeric(14, 2) NOT NULL CHECK (amount > 0),
  reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
  decided_by bigint REFERENCES public.users(id),
  decided_at timestamptz,
  decision_reason text CHECK (length(decision_reason) BETWEEN 3 AND 500),
  idempotency_key uuid NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK ((status = 'pending') = (decided_at IS NULL)),
  CHECK (status <> 'rejected' OR decision_reason IS NOT NULL),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, idempotency_key),
  FOREIGN KEY (tenant_id, enrollment_id) REFERENCES school.enrollment (tenant_id, id)
);
CREATE INDEX adjustment_enrollment ON school.adjustment (tenant_id, enrollment_id);
CREATE INDEX adjustment_pending ON school.adjustment (tenant_id, created_at) WHERE status = 'pending';
CALL school.protect('school.adjustment');

-- What an enrolment owes: its agreed price, less its approved discounts and waivers, plus its
-- approved penalties. Never stored as truth (BR-12). The amount is the finance's; the access to
-- courses learns only whether it is paid (`enrollment_paid`).
CREATE FUNCTION platform.enrollment_due_of(p_tenant uuid, p_enrollment uuid) RETURNS numeric
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT e.agreed_price - coalesce((SELECT sum(CASE WHEN a.kind = 'penalty' THEN -a.amount ELSE a.amount END) FROM school.adjustment a
    WHERE a.tenant_id = e.tenant_id AND a.enrollment_id = e.id AND a.status = 'approved'), 0)
  FROM school.enrollment e WHERE e.tenant_id = p_tenant AND e.id = p_enrollment $$;

CREATE FUNCTION school.enrollment_due(p_enrollment uuid) RETURNS numeric
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NOT (school.finance_in_scope('finance.payment.read', p_enrollment) OR school.finance_in_scope('finance.discount.grant', p_enrollment)
          OR school.finance_in_scope('finance.schedule.manage', p_enrollment) OR school.finance_in_scope('finance.discount.approve', p_enrollment)) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  RETURN platform.enrollment_due_of(school.allowed_tenant(), p_enrollment);
END $$;

CREATE FUNCTION school.guard_adjustment() RETURNS trigger LANGUAGE plpgsql AS $$
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
    -- Never below nothing owed: the discounts and waivers within the price and the penalties.
    IF NEW.kind <> 'penalty' AND school.enrollment_due(e.id) - coalesce((SELECT sum(a.amount) FROM school.adjustment a
        WHERE a.tenant_id = e.tenant_id AND a.enrollment_id = e.id AND a.status = 'pending' AND a.kind <> 'penalty'), 0) - NEW.amount < 0 THEN
      RAISE EXCEPTION 'adjustment_exceeds_due' USING ERRCODE = '23514';
    END IF;
    NEW.decided_by := NULL; NEW.decision_reason := NULL;
    -- Within the threshold (all discounts and waivers of the enrolment together), approved at once; a penalty always.
    threshold := (school.setting_value('finance.discount.approval_percent') #>> '{}')::integer;
    granted := coalesce((SELECT sum(a.amount) FROM school.adjustment a WHERE a.tenant_id = e.tenant_id AND a.enrollment_id = e.id
      AND a.status IN ('pending', 'approved') AND a.kind <> 'penalty'), 0) + CASE WHEN NEW.kind = 'penalty' THEN 0 ELSE NEW.amount END;
    IF NEW.kind = 'penalty' OR granted * 100 <= e.agreed_price * threshold THEN
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
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.adjustment FOR EACH ROW EXECUTE FUNCTION school.guard_adjustment();

-- FR-FIN-01: a schedule of an enrolment, its instalments. A new version replaces the previous one,
-- which stays (« modifications versionnées »); each is written whole, never changed.
CREATE TABLE school.payment_schedule (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  enrollment_id uuid NOT NULL,
  version integer NOT NULL CHECK (version >= 1),
  total numeric(14, 2) NOT NULL CHECK (total >= 0),
  reason text CHECK (length(reason) BETWEEN 3 AND 500),
  idempotency_key uuid NOT NULL,
  -- Written once, by whom and when, never changed: no lock, its `version` its number.
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by bigint DEFAULT school.actor() REFERENCES public.users(id),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, enrollment_id, version),
  UNIQUE (tenant_id, idempotency_key),
  FOREIGN KEY (tenant_id, enrollment_id) REFERENCES school.enrollment (tenant_id, id)
);
CREATE TABLE school.installment (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  schedule_id uuid NOT NULL,
  seq integer NOT NULL CHECK (seq BETWEEN 1 AND 120),
  due_date date NOT NULL,
  amount numeric(14, 2) NOT NULL CHECK (amount > 0),
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, schedule_id, seq),
  FOREIGN KEY (tenant_id, schedule_id) REFERENCES school.payment_schedule (tenant_id, id)
);
ALTER TABLE school.installment ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.installment FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.installment USING (tenant_id = (SELECT school.allowed_tenant())) WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.installment FOR EACH ROW EXECUTE FUNCTION school.immutable();
-- A schedule's version: written once, journalled, never changed.
ALTER TABLE school.payment_schedule ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.payment_schedule FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.payment_schedule USING (tenant_id = (SELECT school.allowed_tenant())) WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON school.payment_schedule FOR EACH ROW EXECUTE FUNCTION school.guard_writable();
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON school.installment FOR EACH ROW EXECUTE FUNCTION school.guard_writable();
CREATE TRIGGER audit AFTER INSERT ON school.payment_schedule FOR EACH ROW EXECUTE FUNCTION school.audit();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.payment_schedule FOR EACH ROW EXECUTE FUNCTION school.immutable();
CREATE INDEX payment_schedule_enrollment ON school.payment_schedule (tenant_id, enrollment_id, version DESC);
CREATE INDEX installment_due ON school.installment (tenant_id, due_date);

-- The schedule in force of an enrolment: its latest version.
CREATE FUNCTION school.current_schedule(p_enrollment uuid) RETURNS uuid LANGUAGE sql STABLE AS $$
  SELECT s.id FROM school.payment_schedule s WHERE s.tenant_id = school.tenant() AND s.enrollment_id = p_enrollment ORDER BY s.version DESC LIMIT 1 $$;

-- FR-FIN-01 AC1: a new version, from instalments `[{"due_date", "amount"}]` in order, whose sum is
-- what the enrolment owes now; refused otherwise. A version after the first says why. The same key
-- again answers the same version (BR-05). With the caller's rights: `finance.schedule.manage`
-- within its scope.
CREATE FUNCTION school.set_schedule(p_enrollment uuid, p_installments jsonb, p_reason text, p_key uuid) RETURNS uuid
LANGUAGE plpgsql AS $$
DECLARE e school.enrollment; s school.payment_schedule; due numeric; total numeric := 0; n integer := 0; item jsonb; previous date;
BEGIN
  SELECT * INTO s FROM school.payment_schedule WHERE tenant_id = school.tenant() AND idempotency_key = p_key;
  IF s.id IS NOT NULL THEN
    IF s.enrollment_id <> p_enrollment THEN RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514'; END IF;
    RETURN s.id;
  END IF;
  IF NOT school.finance_in_scope('finance.schedule.manage', p_enrollment) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  -- One version at a time of an enrolment's schedule, after its adjustments.
  PERFORM pg_advisory_xact_lock(hashtextextended('enrollment_finance:' || p_enrollment::text, 48));
  SELECT * INTO e FROM school.enrollment WHERE tenant_id = school.tenant() AND id = p_enrollment;
  IF e.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF e.status = 'cancelled' THEN RAISE EXCEPTION 'enrollment_cancelled' USING ERRCODE = '23514'; END IF;
  IF jsonb_typeof(p_installments) <> 'array' OR jsonb_array_length(p_installments) = 0 OR jsonb_array_length(p_installments) > 120 THEN
    RAISE EXCEPTION 'schedule_invalid' USING ERRCODE = '23514';
  END IF;
  FOR item IN SELECT value FROM jsonb_array_elements(p_installments) LOOP
    IF jsonb_typeof(item -> 'amount') NOT IN ('string', 'number') OR (item ->> 'amount') !~ '^[0-9]{1,12}(\.[0-9]{1,2})?$' OR (item ->> 'amount')::numeric <= 0
       OR (item ->> 'due_date') !~ '^\d{4}-\d{2}-\d{2}$' OR (previous IS NOT NULL AND (item ->> 'due_date')::date < previous) THEN
      RAISE EXCEPTION 'schedule_invalid' USING ERRCODE = '23514';
    END IF;
    previous := (item ->> 'due_date')::date;
    total := total + (item ->> 'amount')::numeric;
  END LOOP;
  due := school.enrollment_due(e.id);
  IF total <> due THEN RAISE EXCEPTION 'schedule_sum_mismatch' USING ERRCODE = '23514', HINT = due::text; END IF;
  SELECT coalesce(max(version), 0) + 1 INTO n FROM school.payment_schedule WHERE tenant_id = e.tenant_id AND enrollment_id = e.id;
  IF n > 1 AND nullif(btrim(p_reason), '') IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  INSERT INTO school.payment_schedule (tenant_id, enrollment_id, version, total, reason, idempotency_key)
  VALUES (e.tenant_id, e.id, n, total, nullif(btrim(p_reason), ''), p_key) RETURNING * INTO s;
  INSERT INTO school.installment (tenant_id, schedule_id, seq, due_date, amount)
  SELECT e.tenant_id, s.id, x.ordinality, (x.value ->> 'due_date')::date, (x.value ->> 'amount')::numeric
  FROM jsonb_array_elements(p_installments) WITH ORDINALITY x;
  RETURN s.id;
END $$;

-- Who reads an enrolment's finance: `finance.payment.read` for the whole school or the pupil's site.
CREATE POLICY resource_reader ON school.adjustment AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.finance_in_scope('finance.payment.read', enrollment_id)) OR (SELECT school.finance_in_scope('finance.discount.approve', enrollment_id)));
CREATE POLICY resource_creator ON school.adjustment AS RESTRICTIVE FOR INSERT TO school_runtime
  WITH CHECK ((SELECT school.finance_in_scope('finance.discount.grant', enrollment_id)));
CREATE POLICY resource_writer ON school.adjustment AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.finance_in_scope('finance.discount.approve', enrollment_id))) WITH CHECK ((SELECT school.finance_in_scope('finance.discount.approve', enrollment_id)));
CREATE POLICY resource_reader ON school.payment_schedule AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.finance_in_scope('finance.payment.read', enrollment_id)) OR (SELECT school.finance_in_scope('finance.schedule.manage', enrollment_id)));
CREATE POLICY resource_creator ON school.payment_schedule AS RESTRICTIVE FOR INSERT TO school_runtime
  WITH CHECK ((SELECT school.finance_in_scope('finance.schedule.manage', enrollment_id)));
CREATE POLICY resource_reader ON school.installment AS RESTRICTIVE FOR SELECT TO school_runtime
  USING (schedule_id IN (SELECT s.id FROM school.payment_schedule s));
CREATE POLICY resource_creator ON school.installment AS RESTRICTIVE FOR INSERT TO school_runtime
  WITH CHECK (schedule_id IN (SELECT s.id FROM school.payment_schedule s));

-- FR-INS-04: what is paid, until lot 3's payments: an enrolment owing nothing. Replaces 039's.
CREATE OR REPLACE FUNCTION school.enrollment_paid(p_enrollment uuid, p_level text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT CASE p_level WHEN 'none' THEN true ELSE coalesce(platform.enrollment_due_of(school.allowed_tenant(), p_enrollment) <= 0, false) END $$;

-- The journal: in the finance's domain, read with the payments.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('adjustment', 'finance', '{finance.payment.read}'), ('payment_schedule', 'finance', '{finance.payment.read}');
