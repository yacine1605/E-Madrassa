-- Lot 3, the remuneration rules (SRS 1.2, FR-REM-01; BR-03, BR-05, BR-10, BR-13, BR-16): how each
-- member of staff is paid, in versions from the first day of a month. A teacher by the hour, the
-- session, a fixed monthly salary or a mix of a fixed part and units beyond those included; the
-- administrative staff by a fixed monthly salary only (AC3). Unit rates by default and by node of
-- the school's referential (a level, a stream, a speciality), the most specific winning (AC5);
-- recurring bonuses. A version in force never changes: a change is a new version from the first
-- day of a month to come or of the current one, ending the one before (AC1, AC4). Read and written
-- with `payroll.rule.manage` only, read with `payroll.amount.read` (AC2: never by academic_hr).

-- A version: its kind and what it asks (AC6, AC7), from the first day of a month until the last
-- day of one (none for an open one). One member's versions never overlap.
CREATE TABLE school.pay_rule (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  staff_id uuid NOT NULL,
  kind text NOT NULL CHECK (kind IN ('fixed_monthly', 'hourly', 'per_session', 'mixed')),
  monthly_amount numeric(14, 2) CHECK (monthly_amount > 0),
  variable_basis text CHECK (variable_basis IN ('hourly', 'per_session')),
  included_quantity numeric(8, 2) CHECK (included_quantity >= 0),
  valid_from date NOT NULL CHECK (extract(day FROM valid_from) = 1),
  valid_to date CHECK (extract(day FROM valid_to + 1) = 1),
  end_reason text CHECK (length(end_reason) BETWEEN 3 AND 500),
  idempotency_key uuid NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK (valid_to IS NULL OR valid_to > valid_from),
  CHECK (CASE kind WHEN 'fixed_monthly' THEN monthly_amount IS NOT NULL AND variable_basis IS NULL AND included_quantity IS NULL
                   WHEN 'mixed' THEN monthly_amount IS NOT NULL AND variable_basis IS NOT NULL AND included_quantity IS NOT NULL
                   ELSE monthly_amount IS NULL AND variable_basis IS NULL AND included_quantity IS NULL END),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, idempotency_key),
  FOREIGN KEY (tenant_id, staff_id) REFERENCES school.staff (tenant_id, id),
  -- The rules say so first, with the advisory lock; this in the last resort.
  CONSTRAINT pay_rule_once EXCLUDE USING gist (tenant_id WITH =, staff_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&)
);
CALL school.protect('school.pay_rule');

-- A unit rate of a version: by default (no node) or for a node of the school's referential (BR-13),
-- one per (version, node) (AC8), in the unit of its version (an hour, a session).
CREATE TABLE school.pay_rule_rate (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  pay_rule_id uuid NOT NULL,
  node_id uuid,
  unit_amount numeric(14, 2) NOT NULL CHECK (unit_amount > 0),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, pay_rule_id) REFERENCES school.pay_rule (tenant_id, id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id, node_id) REFERENCES school.ref_node (tenant_id, id)
);
CREATE UNIQUE INDEX pay_rule_rate_once ON school.pay_rule_rate (tenant_id, pay_rule_id, coalesce(node_id, '00000000-0000-0000-0000-000000000000'));
CALL school.protect('school.pay_rule_rate');

-- A recurring bonus of a version, added every month it is in force.
CREATE TABLE school.pay_rule_bonus (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  pay_rule_id uuid NOT NULL,
  label text NOT NULL CHECK (length(label) BETWEEN 2 AND 100),
  amount numeric(14, 2) NOT NULL CHECK (amount > 0),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, pay_rule_id) REFERENCES school.pay_rule (tenant_id, id) ON DELETE CASCADE
);
CALL school.protect('school.pay_rule_bonus');

-- The first day of the current month: no version changes what came before (no retroactive change).
CREATE FUNCTION school.pay_month_start() RETURNS date LANGUAGE sql STABLE AS $$ SELECT date_trunc('month', current_date)::date $$;

-- A version, written by `payroll.rule.manage` for the whole school. Begun, it keeps what it says;
-- its end only comes earlier, at the end of a month not gone by, with its reason. One not begun
-- yet is deleted, the version it ended in force again until its own end.
CREATE FUNCTION school.guard_pay_rule() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT school.is_system() AND NOT school.can_everywhere('payroll.rule.manage') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF TG_OP = 'DELETE' THEN
    IF OLD.valid_from <= current_date THEN RAISE EXCEPTION 'pay_rule_started' USING ERRCODE = '23514'; END IF;
    RETURN OLD;
  END IF;
  IF TG_OP = 'INSERT' THEN
    NEW.end_reason := NULL;
    IF NEW.kind = 'mixed' THEN NEW.included_quantity := coalesce(NEW.included_quantity, 0); END IF;
    -- AC6: a mix asks its fixed part and its variable basis.
    IF NEW.kind = 'mixed' AND (NEW.monthly_amount IS NULL OR NEW.variable_basis IS NULL) THEN RAISE EXCEPTION 'pay_rule_incomplete' USING ERRCODE = '23514'; END IF;
    IF NEW.kind = 'fixed_monthly' AND NEW.monthly_amount IS NULL THEN RAISE EXCEPTION 'pay_rule_incomplete' USING ERRCODE = '23514'; END IF;
    IF NEW.kind IN ('hourly', 'per_session') AND (NEW.monthly_amount, NEW.variable_basis, NEW.included_quantity) IS DISTINCT FROM (NULL::numeric, NULL::text, NULL::numeric) THEN
      RAISE EXCEPTION 'pay_rule_incomplete' USING ERRCODE = '23514';
    END IF;
    IF extract(day FROM NEW.valid_from) <> 1 OR (NEW.valid_to IS NOT NULL AND extract(day FROM NEW.valid_to + 1) <> 1) THEN
      RAISE EXCEPTION 'pay_rule_month' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF (NEW.staff_id, NEW.kind, NEW.monthly_amount, NEW.variable_basis, NEW.included_quantity, NEW.valid_from, NEW.idempotency_key)
     IS DISTINCT FROM (OLD.staff_id, OLD.kind, OLD.monthly_amount, OLD.variable_basis, OLD.included_quantity, OLD.valid_from, OLD.idempotency_key) THEN
    RAISE EXCEPTION 'pay_rule_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.valid_to IS NOT DISTINCT FROM OLD.valid_to THEN
    IF NEW.end_reason IS DISTINCT FROM OLD.end_reason THEN RAISE EXCEPTION 'pay_rule_immutable' USING ERRCODE = '23514'; END IF;
    RETURN NEW;
  END IF;
  -- Reopened only when the version after it, not begun, is deleted: ended by it, nothing after it now.
  IF OLD.valid_to IS NOT NULL AND (NEW.valid_to IS NULL OR NEW.valid_to > OLD.valid_to) THEN
    IF OLD.end_reason IS NOT NULL OR NEW.end_reason IS NOT NULL OR OLD.valid_to + 1 <= current_date
       OR EXISTS (SELECT 1 FROM school.pay_rule x WHERE x.tenant_id = OLD.tenant_id AND x.staff_id = OLD.staff_id AND x.valid_from = OLD.valid_to + 1) THEN
      RAISE EXCEPTION 'pay_rule_end_later' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  -- Ended earlier, at the end of a month, never in a month gone by.
  IF NEW.valid_to IS NULL THEN RAISE EXCEPTION 'pay_rule_end_later' USING ERRCODE = '23514'; END IF;
  IF extract(day FROM NEW.valid_to + 1) <> 1 THEN RAISE EXCEPTION 'pay_rule_month' USING ERRCODE = '23514'; END IF;
  IF NEW.valid_to < school.pay_month_start() - 1 THEN RAISE EXCEPTION 'pay_rule_retroactive' USING ERRCODE = '23514'; END IF;
  IF NEW.valid_to < NEW.valid_from THEN RAISE EXCEPTION 'pay_rule_end_before_start' USING ERRCODE = '23514'; END IF;
  NEW.end_reason := nullif(btrim(NEW.end_reason), '');
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.pay_rule FOR EACH ROW EXECUTE FUNCTION school.guard_pay_rule();

-- What a version reads of its member beyond what its writer may see: their type, whether they left.
CREATE FUNCTION school.pay_staff(p_tenant uuid, p_staff uuid, OUT staff_type text, OUT status text, OUT archived boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT s.staff_type, s.status, s.archived_at IS NOT NULL FROM school.staff s
  WHERE s.tenant_id = p_tenant AND p_tenant = school.allowed_tenant() AND s.id = p_staff $$;

-- A member with the school receives a version, of a kind their type allows (AC3); a member's
-- versions never overlap, one change at a time; a version from a month where another is in force
-- ends it the day before, never in a month gone by.
CREATE FUNCTION school.check_pay_rule_staff() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s record; previous school.pay_rule;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('pay_rule:' || CASE WHEN TG_OP = 'DELETE' THEN OLD.staff_id ELSE NEW.staff_id END::text, 58));
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  SELECT * INTO s FROM school.pay_staff(NEW.tenant_id, NEW.staff_id);
  -- An unknown member is the foreign key's to refuse.
  IF s.staff_type IS NULL THEN RETURN NEW; END IF;
  IF s.archived THEN RAISE EXCEPTION 'staff_archived' USING ERRCODE = '23514'; END IF;
  IF s.status = 'left' THEN RAISE EXCEPTION 'staff_left' USING ERRCODE = '23514'; END IF;
  IF s.staff_type IN ('administrative', 'other') AND NEW.kind <> 'fixed_monthly' THEN RAISE EXCEPTION 'pay_rule_kind_refused' USING ERRCODE = '23514'; END IF;
  IF EXISTS (SELECT 1 FROM school.pay_rule x WHERE x.tenant_id = NEW.tenant_id AND x.staff_id = NEW.staff_id AND x.valid_from >= NEW.valid_from) THEN
    RAISE EXCEPTION 'pay_rule_overlap' USING ERRCODE = '23514';
  END IF;
  SELECT * INTO previous FROM school.pay_rule x WHERE x.tenant_id = NEW.tenant_id AND x.staff_id = NEW.staff_id
    AND daterange(x.valid_from, x.valid_to, '[]') @> NEW.valid_from;
  IF previous.id IS NOT NULL THEN
    -- AC1, AC4: a change from the first day of this month or of one to come; what came before kept.
    IF NEW.valid_from < school.pay_month_start() THEN RAISE EXCEPTION 'pay_rule_retroactive' USING ERRCODE = '23514'; END IF;
    UPDATE school.pay_rule SET valid_to = NEW.valid_from - 1, version = version WHERE tenant_id = previous.tenant_id AND id = previous.id;
    IF NEW.valid_to IS NULL AND previous.valid_to IS NOT NULL THEN NEW.valid_to := previous.valid_to; END IF;
  END IF;
  RETURN NEW;
END $$;
-- After `guard`: the permission first.
CREATE TRIGGER rules BEFORE INSERT OR DELETE ON school.pay_rule FOR EACH ROW EXECUTE FUNCTION school.check_pay_rule_staff();

-- A version not begun deleted: the one it ended in force again, until its own end.
CREATE FUNCTION school.reopen_pay_rule() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  UPDATE school.pay_rule SET valid_to = OLD.valid_to, version = version
  WHERE tenant_id = OLD.tenant_id AND staff_id = OLD.staff_id AND valid_to = OLD.valid_from - 1 AND end_reason IS NULL;
  RETURN NULL;
END $$;
CREATE TRIGGER reopen AFTER DELETE ON school.pay_rule FOR EACH ROW EXECUTE FUNCTION school.reopen_pay_rule();

-- A version's rates and bonuses: given with it, or changed while it has not begun; a rate's node
-- in use in the school's referential (BR-13). A version begun keeps them (AC1).
CREATE FUNCTION school.guard_pay_rule_part() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; rule school.pay_rule;
BEGIN
  IF NOT school.is_system() AND NOT school.can_everywhere('payroll.rule.manage') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  SELECT * INTO rule FROM school.pay_rule WHERE tenant_id = r.tenant_id AND id = r.pay_rule_id;
  -- Deleted with its version.
  IF rule.id IS NULL THEN RETURN r; END IF;
  IF TG_OP = 'UPDATE' AND NEW.pay_rule_id IS DISTINCT FROM OLD.pay_rule_id THEN RAISE EXCEPTION 'pay_rule_immutable' USING ERRCODE = '23514'; END IF;
  IF rule.valid_from <= current_date AND rule.created_at <> now() THEN RAISE EXCEPTION 'pay_rule_immutable' USING ERRCODE = '23514'; END IF;
  IF TG_TABLE_NAME = 'pay_rule_rate' AND TG_OP <> 'DELETE' THEN
    IF rule.kind = 'fixed_monthly' THEN RAISE EXCEPTION 'pay_rule_rate_refused' USING ERRCODE = '23514'; END IF;
    IF NEW.node_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM school.ref_node n WHERE n.tenant_id = NEW.tenant_id AND n.id = NEW.node_id AND n.archived_at IS NULL) THEN
      RAISE EXCEPTION 'pay_rule_node_unknown' USING ERRCODE = '23514';
    END IF;
    IF EXISTS (SELECT 1 FROM school.pay_rule_rate x WHERE x.tenant_id = NEW.tenant_id AND x.pay_rule_id = NEW.pay_rule_id
               AND x.node_id IS NOT DISTINCT FROM NEW.node_id AND x.id <> NEW.id) THEN
      RAISE EXCEPTION 'pay_rule_rate_duplicate' USING ERRCODE = '23514';
    END IF;
  ELSIF TG_TABLE_NAME = 'pay_rule_bonus' AND TG_OP <> 'DELETE' THEN
    NEW.label := btrim(NEW.label);
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.pay_rule_rate FOR EACH ROW EXECUTE FUNCTION school.guard_pay_rule_part();
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.pay_rule_bonus FOR EACH ROW EXECUTE FUNCTION school.guard_pay_rule_part();

-- What a version asks, once written: a default rate for every kind but the fixed salary (AC6),
-- no rate for the fixed salary (AC7). Checked by `save_pay_rule`, and at the end of the
-- transaction whatever wrote it.
CREATE FUNCTION school.check_pay_rule(p_tenant uuid, p_rule uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE rule school.pay_rule;
BEGIN
  SELECT * INTO rule FROM school.pay_rule WHERE tenant_id = p_tenant AND id = p_rule;
  IF rule.id IS NULL THEN RETURN; END IF;
  IF rule.kind = 'fixed_monthly' THEN
    IF EXISTS (SELECT 1 FROM school.pay_rule_rate x WHERE x.tenant_id = p_tenant AND x.pay_rule_id = p_rule) THEN
      RAISE EXCEPTION 'pay_rule_rate_refused' USING ERRCODE = '23514';
    END IF;
  ELSIF NOT EXISTS (SELECT 1 FROM school.pay_rule_rate x WHERE x.tenant_id = p_tenant AND x.pay_rule_id = p_rule AND x.node_id IS NULL) THEN
    RAISE EXCEPTION 'pay_rule_default_rate_required' USING ERRCODE = '23514';
  END IF;
END $$;
CREATE FUNCTION school.check_pay_rule_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
BEGIN
  IF TG_TABLE_NAME = 'pay_rule' THEN PERFORM school.check_pay_rule(r.tenant_id, r.id);
  ELSE PERFORM school.check_pay_rule(r.tenant_id, r.pay_rule_id);
  END IF;
  RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER complete AFTER INSERT OR UPDATE ON school.pay_rule DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION school.check_pay_rule_complete();
CREATE CONSTRAINT TRIGGER complete AFTER INSERT OR UPDATE OR DELETE ON school.pay_rule_rate DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION school.check_pay_rule_complete();

-- BR-05: a version written once per key, with its rates and bonuses, all or nothing; the same key
-- again answers the first one, another version with it is refused. With the caller's rights.
-- `p_rates`: [{"node_id": uuid|null, "unit_amount": "1500.00"}]; `p_bonuses`: [{"label": text, "amount": "2000"}].
CREATE FUNCTION school.save_pay_rule(p_staff uuid, p_kind text, p_monthly numeric, p_basis text, p_included numeric, p_from date,
  p_rates jsonb, p_bonuses jsonb, p_key uuid) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE rule school.pay_rule; item jsonb;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('pay_rule_key:' || p_key::text, 58));
  SELECT * INTO rule FROM school.pay_rule WHERE tenant_id = school.tenant() AND idempotency_key = p_key;
  IF rule.id IS NOT NULL THEN
    IF (rule.staff_id, rule.kind, rule.valid_from) IS DISTINCT FROM (p_staff, p_kind, p_from) THEN RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514'; END IF;
    RETURN rule.id;
  END IF;
  INSERT INTO school.pay_rule (tenant_id, staff_id, kind, monthly_amount, variable_basis, included_quantity, valid_from, idempotency_key)
  VALUES (school.tenant(), p_staff, p_kind, p_monthly, p_basis, p_included, p_from, p_key) RETURNING * INTO rule;
  FOR item IN SELECT * FROM jsonb_array_elements(coalesce(p_rates, '[]')) LOOP
    INSERT INTO school.pay_rule_rate (tenant_id, pay_rule_id, node_id, unit_amount)
    VALUES (rule.tenant_id, rule.id, nullif(item ->> 'node_id', '')::uuid, (item ->> 'unit_amount')::numeric);
  END LOOP;
  FOR item IN SELECT * FROM jsonb_array_elements(coalesce(p_bonuses, '[]')) LOOP
    INSERT INTO school.pay_rule_bonus (tenant_id, pay_rule_id, label, amount) VALUES (rule.tenant_id, rule.id, item ->> 'label', (item ->> 'amount')::numeric);
  END LOOP;
  PERFORM school.check_pay_rule(rule.tenant_id, rule.id);
  RETURN rule.id;
END $$;

-- The version of a member in force on a day: the day decides it (AC1, AC4).
CREATE FUNCTION school.pay_rule_on(p_tenant uuid, p_staff uuid, p_day date) RETURNS uuid LANGUAGE sql STABLE AS $$
  SELECT x.id FROM school.pay_rule x WHERE x.tenant_id = p_tenant AND x.staff_id = p_staff AND daterange(x.valid_from, x.valid_to, '[]') @> p_day $$;

-- AC5: the rate of a version for a node: the node's own, else its nearest ancestor's, else the
-- default; none when nothing resolves (BR-16: a missing rate, never zero).
CREATE FUNCTION school.pay_rate(p_tenant uuid, p_rule uuid, p_node uuid) RETURNS numeric LANGUAGE sql STABLE AS $$
  WITH RECURSIVE up (id, parent_id, depth) AS (
    SELECT n.id, n.parent_id, 0 FROM school.ref_node n WHERE n.tenant_id = p_tenant AND n.id = p_node
    UNION ALL
    SELECT n.id, n.parent_id, up.depth + 1 FROM up JOIN school.ref_node n ON n.tenant_id = p_tenant AND n.id = up.parent_id WHERE up.depth < 20
  )
  SELECT coalesce(
    (SELECT r.unit_amount FROM up JOIN school.pay_rule_rate r ON r.tenant_id = p_tenant AND r.pay_rule_id = p_rule AND r.node_id = up.id ORDER BY up.depth LIMIT 1),
    (SELECT r.unit_amount FROM school.pay_rule_rate r WHERE r.tenant_id = p_tenant AND r.pay_rule_id = p_rule AND r.node_id IS NULL)) $$;

-- AC2: the rules read and written with `payroll.rule.manage`, read with `payroll.amount.read`; never by academic_hr.
CREATE POLICY resource_reader ON school.pay_rule AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.can('payroll.rule.manage')) OR (SELECT school.can('payroll.amount.read')));
CREATE POLICY resource_creator ON school.pay_rule AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere('payroll.rule.manage')));
CREATE POLICY resource_writer ON school.pay_rule AS RESTRICTIVE FOR UPDATE TO school_runtime USING ((SELECT school.can_everywhere('payroll.rule.manage'))) WITH CHECK ((SELECT school.can_everywhere('payroll.rule.manage')));
CREATE POLICY resource_deleter ON school.pay_rule AS RESTRICTIVE FOR DELETE TO school_runtime USING ((SELECT school.can_everywhere('payroll.rule.manage')));
CREATE POLICY resource_reader ON school.pay_rule_rate AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.can('payroll.rule.manage')) OR (SELECT school.can('payroll.amount.read')));
CREATE POLICY resource_creator ON school.pay_rule_rate AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere('payroll.rule.manage')));
CREATE POLICY resource_writer ON school.pay_rule_rate AS RESTRICTIVE FOR UPDATE TO school_runtime USING ((SELECT school.can_everywhere('payroll.rule.manage'))) WITH CHECK ((SELECT school.can_everywhere('payroll.rule.manage')));
CREATE POLICY resource_deleter ON school.pay_rule_rate AS RESTRICTIVE FOR DELETE TO school_runtime USING ((SELECT school.can_everywhere('payroll.rule.manage')));
CREATE POLICY resource_reader ON school.pay_rule_bonus AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.can('payroll.rule.manage')) OR (SELECT school.can('payroll.amount.read')));
CREATE POLICY resource_creator ON school.pay_rule_bonus AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere('payroll.rule.manage')));
CREATE POLICY resource_writer ON school.pay_rule_bonus AS RESTRICTIVE FOR UPDATE TO school_runtime USING ((SELECT school.can_everywhere('payroll.rule.manage'))) WITH CHECK ((SELECT school.can_everywhere('payroll.rule.manage')));
CREATE POLICY resource_deleter ON school.pay_rule_bonus AS RESTRICTIVE FOR DELETE TO school_runtime USING ((SELECT school.can_everywhere('payroll.rule.manage')));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('pay_rule', 'finance', '{payroll.rule.manage,payroll.amount.read}'),
  ('pay_rule_rate', 'finance', '{payroll.rule.manage,payroll.amount.read}'),
  ('pay_rule_bonus', 'finance', '{payroll.rule.manage,payroll.amount.read}');
