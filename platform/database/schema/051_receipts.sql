-- Lot 3, receipts (SRS 1.2, FR-FIN-05; FR-PDF-01, BR-05, TST-17): one receipt per validated
-- payment and per executed refund, numbered `REC-{year}-{six digits}` in the school, never twice
-- the same number even issued at the same moment (AC1). Its content is frozen when it is issued
-- (the pupil, the amount, the means, the reference, the balance after it, the school's identity),
-- in the school's receipt language; printed again, it is the same receipt marked « duplicata ».

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels, allowed_values) VALUES
  ('finance.receipt.locale', 'code', '"fr"', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Langue des reçus", "ar": "لغة الوصولات"}', '{fr,ar}');

CREATE TABLE school.receipt_counter (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  year integer NOT NULL,
  last integer NOT NULL CHECK (last >= 1),
  PRIMARY KEY (tenant_id, year)
);
ALTER TABLE school.receipt_counter ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.receipt_counter FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.receipt_counter USING (tenant_id = (SELECT school.allowed_tenant()))
  WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON school.receipt_counter FOR EACH ROW EXECUTE FUNCTION school.guard_writable();

-- A receipt: its number, what it acknowledges (a payment or a refund), its language and its frozen content.
CREATE TABLE school.receipt (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  number text NOT NULL CHECK (number ~ '^REC-[0-9]{4}-[0-9]{6}$'),
  payment_id uuid,
  refund_id uuid,
  locale text NOT NULL CHECK (locale IN ('fr', 'ar')),
  content jsonb NOT NULL CHECK (jsonb_typeof(content) = 'object'),
  issued_at timestamptz NOT NULL DEFAULT now(),
  issued_by bigint DEFAULT school.actor() REFERENCES public.users(id),
  CHECK (num_nonnulls(payment_id, refund_id) = 1),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, number),
  UNIQUE (tenant_id, payment_id),
  UNIQUE (tenant_id, refund_id),
  FOREIGN KEY (tenant_id, payment_id) REFERENCES school.payment (tenant_id, id),
  FOREIGN KEY (tenant_id, refund_id) REFERENCES school.refund (tenant_id, id)
);
ALTER TABLE school.receipt ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.receipt FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.receipt USING (tenant_id = (SELECT school.allowed_tenant())) WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON school.receipt FOR EACH ROW EXECUTE FUNCTION school.guard_writable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.receipt FOR EACH ROW EXECUTE FUNCTION school.immutable();
CREATE TRIGGER audit AFTER INSERT ON school.receipt FOR EACH ROW EXECUTE FUNCTION school.audit();

-- What a payment or a refund leaves to pay just after it: what is owed now, less what was paid,
-- reversed and refunded up to that moment.
CREATE FUNCTION platform.balance_at(p_tenant uuid, p_enrollment uuid, p_at timestamptz) RETURNS numeric
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT (platform.enrollment_due_of(p_tenant, p_enrollment)
    - coalesce((SELECT sum(CASE WHEN p.kind = 'payment' THEN p.amount ELSE -p.amount END) FROM school.payment p
        WHERE p.tenant_id = p_tenant AND p.enrollment_id = p_enrollment AND p.validated_at <= p_at
          AND (p.status = 'validated' OR (p.kind = 'payment' AND p.status = 'reversed'))), 0)
    + coalesce((SELECT sum(r.amount) FROM school.refund r WHERE r.tenant_id = p_tenant AND r.enrollment_id = p_enrollment AND r.executed_at <= p_at), 0))::numeric(14, 2) $$;

-- FR-FIN-05: the receipt of a validated payment (`p_payment`) or an executed refund (`p_refund`),
-- issued once: the first call numbers it and freezes its content, the next ones answer it
-- (`issued` false: a duplicate), even at the same moment. For whoever reads the payments, or
-- records them (the cash desk gives the receipt), or records refunds.
CREATE FUNCTION school.issue_receipt(p_payment uuid, p_refund uuid) RETURNS TABLE (id uuid, issued boolean)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); p school.payment; r school.refund; e school.enrollment; s school.student; existing uuid; n integer;
  y integer := extract(year FROM current_date)::integer; number text; content jsonb; at timestamptz; lang text;
BEGIN
  IF t IS NULL OR num_nonnulls(p_payment, p_refund) <> 1 THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF p_payment IS NOT NULL THEN
    SELECT * INTO p FROM school.payment x WHERE x.tenant_id = t AND x.id = p_payment;
    IF p.id IS NULL OR NOT (school.finance_in_scope('finance.payment.read', p.enrollment_id) OR school.finance_in_scope('finance.payment.validate', p.enrollment_id)
       OR p.created_by = school.actor()) THEN
      RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
    END IF;
    IF p.kind <> 'payment' OR p.validated_at IS NULL THEN RAISE EXCEPTION 'receipt_not_validated' USING ERRCODE = '23514'; END IF;
    SELECT * INTO e FROM school.enrollment x WHERE x.tenant_id = t AND x.id = p.enrollment_id;
  ELSE
    SELECT * INTO r FROM school.refund x WHERE x.tenant_id = t AND x.id = p_refund;
    IF r.id IS NULL OR NOT (school.finance_in_scope('finance.payment.read', r.enrollment_id) OR school.finance_in_scope('finance.refund.request', r.enrollment_id)) THEN
      RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
    END IF;
    IF r.kind <> 'refund' OR r.status <> 'executed' THEN RAISE EXCEPTION 'receipt_not_validated' USING ERRCODE = '23514'; END IF;
    SELECT * INTO e FROM school.enrollment x WHERE x.tenant_id = t AND x.id = r.enrollment_id;
  END IF;
  -- Once, even asked for twice at the same moment.
  PERFORM pg_advisory_xact_lock(hashtextextended('receipt:' || coalesce(p_payment, p_refund)::text, 51));
  SELECT x.id INTO existing FROM school.receipt x WHERE x.tenant_id = t AND (x.payment_id = p_payment OR x.refund_id = p_refund);
  IF existing IS NOT NULL THEN RETURN QUERY SELECT existing, false; RETURN; END IF;
  -- AC1: the counter's row, locked, gives each receipt its own number.
  INSERT INTO school.receipt_counter AS c (tenant_id, year, last) VALUES (t, y, 1)
  ON CONFLICT (tenant_id, year) DO UPDATE SET last = c.last + 1 RETURNING c.last INTO n;
  IF n > 999999 THEN RAISE EXCEPTION 'payment_reference_exhausted' USING ERRCODE = '23514'; END IF;
  number := 'REC-' || y::text || '-' || lpad(n::text, 6, '0');
  SELECT * INTO s FROM school.student x WHERE x.tenant_id = t AND x.id = e.student_id;
  at := coalesce(p.validated_at, r.executed_at);
  lang := coalesce(school.setting_value('finance.receipt.locale') #>> '{}', 'fr');
  content := jsonb_build_object(
    'kind', CASE WHEN p.id IS NOT NULL THEN 'payment' ELSE 'refund' END,
    'school', (SELECT jsonb_build_object('name', tn.name, 'address', school.setting_value('identity.address') #>> '{}',
       'phone', school.setting_value('identity.phone') #>> '{}', 'email', school.setting_value('identity.email') #>> '{}') FROM platform.tenant tn WHERE tn.id = t),
    'pupil', jsonb_build_object('matricule', s.matricule, 'last_name_lat', s.last_name_lat, 'first_name_lat', s.first_name_lat,
       'last_name_ar', s.last_name_ar, 'first_name_ar', s.first_name_ar),
    'program', jsonb_build_object('code', e.terms_snapshot ->> 'code', 'labels', e.terms_snapshot -> 'labels'),
    'reference', coalesce(p.reference, r.reference),
    'amount', coalesce(p.amount, r.amount)::text,
    'method_code', coalesce(p.method_code, r.method_code),
    'external_ref', coalesce(p.external_ref, r.external_ref),
    'date', coalesce(p.paid_on, r.paid_on)::text,
    'reason', r.reason,
    'balance_after', platform.balance_at(t, e.id, at)::text,
    'issued_on', current_date::text);
  INSERT INTO school.receipt (tenant_id, number, payment_id, refund_id, locale, content) VALUES (t, number, p_payment, p_refund, lang, content)
  RETURNING school.receipt.id INTO existing;
  RETURN QUERY SELECT existing, true;
END $$;

-- Whoever reads the payment or the refund reads its receipt, as their own policies decide; issuing is the function's.
CREATE POLICY resource_reader ON school.receipt AS RESTRICTIVE FOR SELECT TO school_runtime
  USING (payment_id IN (SELECT x.id FROM school.payment x) OR refund_id IN (SELECT x.id FROM school.refund x));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('receipt', 'finance', '{finance.payment.read}');
