-- Lot 2, access to courses (SRS 1.2, FR-INS-04, OD-05): whether an enrolment opens its pupil's
-- courses (lot 5's LMS, the parents' portal), computed when asked, never stored. A validated
-- enrolment opens them; the school may also ask for its payment, the first instalment or all of
-- it (`enrollment.access.require_payment`, `none` by default, each school's to decide). A
-- suspended or cancelled enrolment opens nothing (FR-INS-03).

-- One code among a definition's list (a set of them is `code_set`, 011).
ALTER TABLE platform.setting_definition DROP CONSTRAINT setting_definition_value_type_check;
ALTER TABLE platform.setting_definition ADD CONSTRAINT setting_definition_value_type_check
  CHECK (value_type IN ('integer', 'boolean', 'text', 'day_set', 'language_set', 'code_set', 'code'));
DO $$ DECLARE c text; BEGIN
  SELECT conname INTO c FROM pg_constraint WHERE conrelid = 'platform.setting_definition'::regclass AND contype = 'c'
    AND pg_get_constraintdef(oid) LIKE '%allowed_values IS NOT NULL%';
  EXECUTE format('ALTER TABLE platform.setting_definition DROP CONSTRAINT %I', c);
END $$;
ALTER TABLE platform.setting_definition ADD CONSTRAINT setting_definition_allowed_values
  CHECK ((value_type IN ('code_set', 'code')) = (allowed_values IS NOT NULL));

-- Replaces 011's: a code is one of its list's.
CREATE OR REPLACE FUNCTION school.guard_setting() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE def platform.setting_definition; n numeric;
BEGIN
  SELECT * INTO def FROM platform.setting_definition WHERE key = NEW.key;
  IF NOT school.is_system() AND NOT school.can_everywhere(def.permission) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF NOT (CASE def.value_type
       WHEN 'boolean' THEN jsonb_typeof(NEW.value) = 'boolean'
       WHEN 'integer' THEN jsonb_typeof(NEW.value) = 'number'
       WHEN 'text' THEN jsonb_typeof(NEW.value) = 'string' AND length(NEW.value #>> '{}') <= coalesce(def.max_value, 1000)
       -- Days 0 (Sunday) to 6 (Saturday), each once, at least one.
       WHEN 'day_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) BETWEEN 1 AND 7
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) d WHERE jsonb_typeof(d) <> 'number' OR (d #>> '{}') NOT IN ('0', '1', '2', '3', '4', '5', '6'))
         AND (SELECT count(DISTINCT d #>> '{}') FROM jsonb_array_elements(NEW.value) d) = jsonb_array_length(NEW.value)
       WHEN 'language_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) BETWEEN 1 AND 3
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) l WHERE jsonb_typeof(l) <> 'string' OR (l #>> '{}') NOT IN ('fr', 'ar', 'en'))
         AND (SELECT count(DISTINCT l #>> '{}') FROM jsonb_array_elements(NEW.value) l) = jsonb_array_length(NEW.value)
       -- Codes of the definition's list, each once, at least one.
       WHEN 'code_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) >= 1
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) v WHERE jsonb_typeof(v) <> 'string' OR NOT ((v #>> '{}') = ANY (def.allowed_values)))
         AND (SELECT count(DISTINCT v #>> '{}') FROM jsonb_array_elements(NEW.value) v) = jsonb_array_length(NEW.value)
       WHEN 'code' THEN jsonb_typeof(NEW.value) = 'string' AND (NEW.value #>> '{}') = ANY (def.allowed_values)
     END) THEN
    RAISE EXCEPTION 'setting_invalid' USING ERRCODE = '23514';
  END IF;
  IF def.value_type = 'integer' THEN
    n := (NEW.value #>> '{}')::numeric;
    IF n <> trunc(n) OR n < coalesce(def.min_value, n) OR n > coalesce(def.max_value, n) THEN
      RAISE EXCEPTION 'setting_invalid' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels, allowed_values) VALUES
  ('enrollment.access.require_payment', 'code', '"none"', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Paiement exigé pour l’accès aux cours", "ar": "الدفع المطلوب للوصول إلى الدروس"}', '{none,first_installment,full}');

-- What an enrolment's validated payments cover, for the access's policy: `full` when nothing is
-- left to pay, `first_installment` when its first instalment is paid. Lot 3's finance records the
-- payments and the schedules and replaces this function; until then no payment is validated, and
-- only an enrolment that owes nothing (a free programme, a full discount) counts as paid.
CREATE FUNCTION school.enrollment_paid(p_enrollment uuid, p_level text) RETURNS boolean
LANGUAGE sql STABLE AS $$
  SELECT CASE p_level
    WHEN 'none' THEN true
    ELSE EXISTS (SELECT 1 FROM school.enrollment e WHERE e.tenant_id = school.tenant() AND e.id = p_enrollment
      AND e.agreed_price - e.discount_total <= 0)
  END $$;

-- FR-INS-04: whether an enrolment in this state opens its pupil's courses today: validated, and
-- paid as far as the school asks. For a row being read or written (the API's answer).
CREATE FUNCTION school.access_open(p_status text, p_enrollment uuid) RETURNS boolean
LANGUAGE sql STABLE AS $$
  SELECT p_status = 'validated' AND school.enrollment_paid(p_enrollment, school.setting_value('enrollment.access.require_payment') #>> '{}') $$;

-- The indicator lot 5's courses and the portal ask for, as the reader may see the enrolment (one
-- out of their reach opens nothing).
CREATE FUNCTION school.access_granted(p_enrollment uuid) RETURNS boolean
LANGUAGE sql STABLE AS $$
  SELECT coalesce((SELECT school.access_open(e.status, e.id) FROM school.enrollment e WHERE e.tenant_id = school.tenant() AND e.id = p_enrollment), false) $$;
