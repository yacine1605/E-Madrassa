-- Lot 3, the school's expenses (SRS 1.2, FR-DEP-01, FR-DEP-02; BR-04, BR-05, BR-08, BR-15): their
-- categories, which the school names, `salaries` being the payroll's own (AC1); an expense entered
-- with its reason and documents, submitted by its author, validated by someone else (AC1), by the
-- direction beyond the school's threshold, never changed once validated (AC2); its documents read
-- only with `expense.attachment.read` (AC3). Its payments and linked corrections come next (FR-DEP-03).

-- « Approbation direction au-delà du seuil »: beyond it, the validation is the direction's.
INSERT INTO platform.permission (code, module_key, sensitive, labels) VALUES
  ('expense.expense.approve', 'expenses', true, '{"fr": "Approuver une dépense au-delà du seuil", "ar": "الموافقة على نفقة تتجاوز الحد"}');
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES ('direction', 'expense.expense.approve');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, 'expense.expense.approve' FROM school.role r
WHERE r.template_code = 'direction' AND platform.tenant_mode(r.tenant_id) = 'active'
  AND EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = r.tenant_id AND m.module_key = 'expenses')
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = 'expense.expense.approve');

-- OD-09: the amount beyond which an expense is the direction's to validate. A proposal each school changes.
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('expense.approval_amount', 'integer', '50000', 0, 1000000000, 'tenant.settings.manage',
   '{"fr": "Approbation de la direction au-delà de (DA, par dépense)", "ar": "موافقة الإدارة بعد تجاوز (دج، لكل نفقة)"}');

-- FR-DEP-01: the publisher's categories, given with the expenses module as the rooms' lists (037).
-- `salaries` is the payroll's (FR-REM-04), never entered by hand; `other` asks what it is.
CREATE TABLE platform.expense_category_default (
  code text PRIMARY KEY CHECK (code ~ '^[a-z0-9_]{1,30}$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  needs_label boolean NOT NULL DEFAULT false,
  sort_order integer NOT NULL DEFAULT 0
);
INSERT INTO platform.expense_category_default (code, labels, needs_label, sort_order) VALUES
  ('salaries', '{"fr": "Salaires", "ar": "الأجور"}', false, 10),
  ('advertising', '{"fr": "Publicité", "ar": "الإشهار"}', false, 20),
  ('missions', '{"fr": "Missions", "ar": "المهمات"}', false, 30),
  ('rent', '{"fr": "Loyer", "ar": "الإيجار"}', false, 40),
  ('equipment', '{"fr": "Équipement", "ar": "التجهيز"}', false, 50),
  ('other', '{"fr": "Autre", "ar": "أخرى"}', true, 60);

-- The school's categories: a code never changed, its labels, whether an expense of it names what it
-- is; archived rather than deleted, the expenses keep it. `salaries` stays.
CREATE TABLE school.expense_category (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  code text NOT NULL CHECK (code ~ '^[a-z0-9_]{1,30}$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  needs_label boolean NOT NULL DEFAULT false,
  sort_order integer NOT NULL DEFAULT 0 CHECK (sort_order BETWEEN 0 AND 100000),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, code)
);
CALL school.protect('school.expense_category');

-- FR-DEP-01 AC1: the payroll's category, never entered by hand.
CREATE FUNCTION school.expense_category_system(p_code text) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$ SELECT p_code = 'salaries' $$;

-- The categories are the whole school's: whoever validates its expenses for the whole school
-- names them, the provisioner gives the publisher's. A code never changes; archived stays archived.
CREATE FUNCTION school.guard_expense_category() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT school.is_system() AND NOT school.can_everywhere('expense.expense.validate') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF TG_OP = 'UPDATE' THEN
    IF NEW.code IS DISTINCT FROM OLD.code THEN RAISE EXCEPTION 'code_immutable' USING ERRCODE = '23514'; END IF;
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'expense_category_archived' USING ERRCODE = '23514'; END IF;
    IF NEW.archived_at IS NOT NULL AND school.expense_category_system(NEW.code) THEN RAISE EXCEPTION 'expense_category_system' USING ERRCODE = '23514'; END IF;
  ELSE
    NEW.archived_at := NULL;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.expense_category FOR EACH ROW EXECUTE FUNCTION school.guard_expense_category();

-- A school in use receives the publisher's categories with the expenses module, at its creation or
-- later; one it has already stays as it is. A school not in use writes nothing and takes them back
-- itself once in use again (`/api/expense-categories/defaults`). Replaces 038's.
CREATE OR REPLACE FUNCTION platform.enable_module(p_tenant uuid, p_module text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM platform.module m WHERE m.key = p_module) THEN
    RAISE EXCEPTION 'unknown_module' USING ERRCODE = '23514';
  END IF;
  INSERT INTO platform.tenant_module (tenant_id, module_key)
  SELECT p_tenant, needed.key FROM (
    WITH RECURSIVE chain (key, depth) AS (
      SELECT p_module, 0
      UNION ALL
      SELECT d.requires_key, c.depth + 1 FROM platform.module_dependency d JOIN chain c ON d.module_key = c.key WHERE c.depth < 20
    ) SELECT key, max(depth) AS depth FROM chain GROUP BY key
  ) needed
  WHERE NOT EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = p_tenant AND m.module_key = needed.key)
  ORDER BY needed.depth DESC;
  IF platform.tenant_mode(p_tenant) = 'active'
     AND EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = p_tenant AND m.module_key = 'sites_rooms') THEN
    INSERT INTO school.room_option (tenant_id, kind, code, labels, sort_order)
    SELECT p_tenant, d.kind, d.code, d.labels, d.sort_order FROM platform.room_option_default d
    WHERE NOT EXISTS (SELECT 1 FROM school.room_option x WHERE x.tenant_id = p_tenant AND x.kind = d.kind AND x.code = d.code)
    ORDER BY d.kind, d.sort_order;
  END IF;
  IF platform.tenant_mode(p_tenant) = 'active'
     AND EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = p_tenant AND m.module_key = 'students_crm') THEN
    INSERT INTO school.lead_option (tenant_id, kind, code, labels, sort_order)
    SELECT p_tenant, d.kind, d.code, d.labels, d.sort_order FROM platform.lead_option_default d
    WHERE NOT EXISTS (SELECT 1 FROM school.lead_option x WHERE x.tenant_id = p_tenant AND x.kind = d.kind AND x.code = d.code)
    ORDER BY d.kind, d.sort_order;
  END IF;
  IF platform.tenant_mode(p_tenant) = 'active'
     AND EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = p_tenant AND m.module_key = 'expenses') THEN
    INSERT INTO school.expense_category (tenant_id, code, labels, needs_label, sort_order)
    SELECT p_tenant, d.code, d.labels, d.needs_label, d.sort_order FROM platform.expense_category_default d
    WHERE NOT EXISTS (SELECT 1 FROM school.expense_category x WHERE x.tenant_id = p_tenant AND x.code = d.code)
    ORDER BY d.sort_order;
  END IF;
END $$;
-- The schools in use that have the module already.
INSERT INTO school.expense_category (tenant_id, code, labels, needs_label, sort_order, created_at, updated_at)
SELECT m.tenant_id, d.code, d.labels, d.needs_label, d.sort_order, now(), now() FROM platform.tenant_module m CROSS JOIN platform.expense_category_default d
WHERE m.module_key = 'expenses' AND platform.tenant_mode(m.tenant_id) = 'active';

-- FR-DEP-02: an expense. Its day (never to come, never in a closed month), amount, category, what
-- it is when the category asks, beneficiary, reason, programme it is charged to if any; a draft
-- its author corrects, submitted, then validated or rejected with its reason by someone else.
CREATE TABLE school.expense (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  expense_date date NOT NULL,
  amount numeric(14, 2) NOT NULL CHECK (amount > 0),
  category_id uuid NOT NULL,
  label text CHECK (length(label) BETWEEN 2 AND 200),
  beneficiary text NOT NULL CHECK (length(beneficiary) BETWEEN 2 AND 200),
  reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
  program_id uuid,
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'validated', 'rejected')),
  needs_approval boolean NOT NULL DEFAULT false,
  submitted_at timestamptz,
  decided_by bigint REFERENCES public.users(id),
  decided_at timestamptz,
  decision_reason text CHECK (length(decision_reason) BETWEEN 3 AND 500),
  idempotency_key uuid NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK ((status IN ('validated', 'rejected')) = (decided_at IS NOT NULL)),
  CHECK (status <> 'rejected' OR decision_reason IS NOT NULL),
  CHECK ((status = 'draft') = (submitted_at IS NULL)),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, idempotency_key),
  FOREIGN KEY (tenant_id, category_id) REFERENCES school.expense_category (tenant_id, id),
  FOREIGN KEY (tenant_id, program_id) REFERENCES school.program (tenant_id, id)
);
CREATE INDEX expense_date ON school.expense (tenant_id, expense_date DESC);
CREATE INDEX expense_waiting ON school.expense (tenant_id, submitted_at) WHERE status = 'submitted';
CALL school.protect('school.expense');

-- What an expense is, checked whenever it is written by its author: a day not to come nor in a
-- closed month (BR-15), a category in use and not the payroll's (AC1 of FR-DEP-01), what it is
-- when the category asks.
CREATE FUNCTION school.check_expense(e school.expense) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE c school.expense_category;
BEGIN
  IF e.expense_date > current_date THEN RAISE EXCEPTION 'expense_future_date' USING ERRCODE = '23514'; END IF;
  IF school.period_closed(e.tenant_id, e.expense_date) THEN RAISE EXCEPTION 'period_closed' USING ERRCODE = '23514'; END IF;
  SELECT * INTO c FROM school.expense_category WHERE tenant_id = e.tenant_id AND id = e.category_id;
  IF c.id IS NULL OR c.archived_at IS NOT NULL THEN RAISE EXCEPTION 'expense_category_archived' USING ERRCODE = '23514'; END IF;
  IF school.expense_category_system(c.code) THEN RAISE EXCEPTION 'expense_category_system' USING ERRCODE = '23514'; END IF;
  IF c.needs_label AND e.label IS NULL THEN RAISE EXCEPTION 'expense_label_required' USING ERRCODE = '23514'; END IF;
  RETURN true;
END $$;

-- The cycle: entered and corrected by its author while a draft, submitted by its author, then
-- validated by `expense.expense.validate` (by `expense.expense.approve` beyond the threshold) or
-- rejected with its reason, never by its author (BR-08, AC1); decided, it never changes (AC2),
-- nor is it deleted. The day of an expense validated falls in no closed month (BR-15).
CREATE FUNCTION school.guard_expense() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE threshold numeric;
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'INSERT' THEN
    IF NOT school.can_everywhere('expense.expense.create') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    NEW.label := nullif(btrim(NEW.label), ''); NEW.beneficiary := btrim(NEW.beneficiary); NEW.reason := btrim(NEW.reason);
    NEW.status := 'draft'; NEW.needs_approval := false; NEW.submitted_at := NULL;
    NEW.decided_by := NULL; NEW.decided_at := NULL; NEW.decision_reason := NULL;
    PERFORM school.check_expense(NEW);
    RETURN NEW;
  END IF;
  IF (NEW.idempotency_key, NEW.created_by) IS DISTINCT FROM (OLD.idempotency_key, OLD.created_by) THEN
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
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.expense FOR EACH ROW EXECUTE FUNCTION school.guard_expense();

-- An expense entered once per key (BR-05): the same key again answers the first one, another
-- expense with it is refused.
CREATE FUNCTION school.record_expense(p_date date, p_amount numeric, p_category uuid, p_label text, p_beneficiary text, p_reason text,
  p_program uuid, p_key uuid) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE e school.expense;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('expense_key:' || p_key::text, 55));
  SELECT * INTO e FROM school.expense WHERE tenant_id = school.tenant() AND idempotency_key = p_key;
  IF e.id IS NOT NULL THEN
    IF (e.expense_date, e.amount, e.category_id, e.beneficiary) IS DISTINCT FROM (p_date, p_amount, p_category, btrim(p_beneficiary)) THEN
      RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514';
    END IF;
    RETURN e.id;
  END IF;
  INSERT INTO school.expense (tenant_id, expense_date, amount, category_id, label, beneficiary, reason, program_id, idempotency_key)
  VALUES (school.tenant(), p_date, p_amount, p_category, p_label, p_beneficiary, p_reason, p_program, p_key) RETURNING * INTO e;
  RETURN e.id;
END $$;

-- Who reads the expenses: `expense.expense.read`, whoever validates or approves them, their author.
CREATE POLICY resource_reader ON school.expense AS RESTRICTIVE FOR SELECT TO school_runtime
  USING (created_by = (SELECT school.actor()) OR (SELECT school.can('expense.expense.read')) OR (SELECT school.can('expense.expense.validate'))
    OR (SELECT school.can('expense.expense.approve')));
CREATE POLICY resource_writer ON school.expense AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING (created_by = (SELECT school.actor()) OR (SELECT school.can_everywhere('expense.expense.validate')) OR (SELECT school.can_everywhere('expense.expense.approve')))
  WITH CHECK (created_by = (SELECT school.actor()) OR (SELECT school.can_everywhere('expense.expense.validate')) OR (SELECT school.can_everywhere('expense.expense.approve')));
CREATE POLICY resource_reader ON school.expense_category AS RESTRICTIVE FOR SELECT TO school_runtime
  USING ((SELECT school.can('expense.expense.read')) OR (SELECT school.can('expense.expense.create')) OR (SELECT school.can('expense.expense.validate'))
    OR (SELECT school.can('expense.expense.approve')));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('expense', 'finance', '{expense.expense.read,expense.expense.validate}'),
  ('expense_category', 'finance', '{expense.expense.read,expense.expense.validate}');

-- FR-DEP-02 AC3: an expense's documents (files of `finance_attachment` owned by it) are read only
-- with `expense.attachment.read`, whatever the payments' permission says; added or withdrawn by
-- its author while it is a draft, never once submitted. Replaces 054's reader policy.
ALTER POLICY reader ON school.file_object USING (tenant_id = (SELECT school.tenant())
  AND (created_by = (SELECT school.actor()) OR category <> ALL ((SELECT school.file_categories('author'))::text[]))
  AND (category <> 'student_photo' OR EXISTS (SELECT 1 FROM school.student s WHERE s.tenant_id = file_object.tenant_id AND s.id = file_object.owner_id))
  AND CASE WHEN owner_type = 'payment' THEN category = 'finance_attachment'
             AND EXISTS (SELECT 1 FROM school.payment p WHERE p.tenant_id = file_object.tenant_id AND p.id = file_object.owner_id)
           WHEN owner_type = 'expense' THEN category = 'finance_attachment' AND (SELECT school.can('expense.attachment.read'))
           ELSE category = ANY ((SELECT school.file_categories('read'))::text[]) END);

CREATE FUNCTION school.guard_expense_file() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE e school.expense;
BEGIN
  IF NEW.owner_type IS DISTINCT FROM 'expense' OR (TG_OP = 'UPDATE' AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at) THEN RETURN NEW; END IF;
  SELECT * INTO e FROM school.expense WHERE tenant_id = NEW.tenant_id AND id = NEW.owner_id;
  IF e.id IS NULL OR NEW.category <> 'finance_attachment' THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF e.status <> 'draft' THEN RAISE EXCEPTION 'expense_immutable' USING ERRCODE = '23514'; END IF;
  IF e.created_by IS DISTINCT FROM school.actor() THEN RAISE EXCEPTION 'expense_not_author' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER expense BEFORE INSERT OR UPDATE ON school.file_object FOR EACH ROW EXECUTE FUNCTION school.guard_expense_file();
