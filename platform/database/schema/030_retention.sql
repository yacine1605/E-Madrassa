-- Lot 2, a retention policy per category (decision of 13 October 2026; NFR-DATA-03, FR-ELV-03,
-- BR-17). Each school sets how long it keeps each category of data; a daily run archives the
-- pupils who left, anonymises the records kept long enough and purges the journal's old entries.
-- The default durations below are proposals, to be validated by each school and a lawyer (OD-07).

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('retention.former_archive_months', 'integer', '12', 1, 120, 'tenant.settings.manage',
   '{"fr": "Archiver un ancien élève après (mois)", "ar": "أرشفة التلميذ القديم بعد (أشهر)"}'),
  ('retention.pupil_years', 'integer', '10', 1, 50, 'tenant.settings.manage',
   '{"fr": "Anonymiser un dossier élève archivé après (années)", "ar": "إخفاء هوية ملف التلميذ المؤرشف بعد (سنوات)"}'),
  ('retention.minor_years', 'integer', '10', 1, 50, 'tenant.settings.manage',
   '{"fr": "Anonymiser le dossier d’un élève parti mineur après (années)", "ar": "إخفاء هوية ملف تلميذ غادر قاصرًا بعد (سنوات)"}'),
  ('retention.journal_years', 'integer', '10', 5, 50, 'tenant.settings.manage',
   '{"fr": "Conserver le journal (années)", "ar": "مدة حفظ السجل (سنوات)"}'),
  ('retention.finance_years', 'integer', '10', 10, 50, 'tenant.settings.manage',
   '{"fr": "Conserver les données financières (années)", "ar": "مدة حفظ البيانات المالية (سنوات)"}');

-- When a pupil left (FR-ELV-03): the day they became a former pupil, set by the database. Pupils
-- who left before this migration: the day the journal recorded it, else their record's last change.
-- When a record was anonymised: the retention run's day.
ALTER TABLE school.student ADD COLUMN left_on date, ADD COLUMN anonymised_at timestamptz;
ALTER TABLE school.guardian ADD COLUMN anonymised_at timestamptz;
ALTER TABLE school.student DISABLE TRIGGER USER;
UPDATE school.student s SET left_on = coalesce(
  (SELECT max(a.occurred_at)::date FROM school.audit_log a WHERE a.tenant_id = s.tenant_id AND a.object_type = 'student' AND a.object_id = s.id
     AND a.after ->> 'status' = 'former'), s.updated_at::date)
WHERE s.status = 'former';
ALTER TABLE school.student ENABLE TRIGGER USER;
CREATE INDEX student_retention ON school.student (tenant_id, left_on) WHERE status = 'former' AND anonymised_at IS NULL;

CREATE FUNCTION school.track_departure() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.status = 'former' AND OLD.status IS DISTINCT FROM 'former' THEN NEW.left_on := current_date; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER track_departure BEFORE UPDATE OF status ON school.student FOR EACH ROW EXECUTE FUNCTION school.track_departure();

-- The retention run alone (the provisioner's functions below, with their flag) passes the rules
-- that keep a record as it is: a former pupil's record, a read-only school, the school's
-- required fields, the journal's entries. Neither the application nor a migration does.
CREATE FUNCTION school.retention_run() RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT current_user = 'platform_provisioner' AND coalesce(current_setting('app.retention', true), '') = 'on' $$;

-- 019's, with the retention run let through.
CREATE OR REPLACE FUNCTION school.guard_writable() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.tenant_id ELSE NEW.tenant_id END;
BEGIN
  IF school.retention_run() THEN RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; END IF;
  IF (school.context()).support_grant IS NOT NULL THEN
    RAISE EXCEPTION 'support_read_only' USING ERRCODE = '25006';
  END IF;
  IF platform.tenant_mode(t) IS DISTINCT FROM 'active' THEN
    RAISE EXCEPTION 'tenant_read_only' USING ERRCODE = '25006';
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

-- 008's, with the retention run let through: an anonymised record keeps none of the school's fields.
CREATE OR REPLACE FUNCTION school.check_custom_values(p_entity text, p_values jsonb) RETURNS void LANGUAGE plpgsql STABLE AS $$
DECLARE f school.custom_field; v jsonb; k text;
BEGIN
  IF school.retention_run() THEN RETURN; END IF;
  IF p_values IS NOT NULL AND jsonb_typeof(p_values) <> 'object' THEN RAISE EXCEPTION 'custom_value_invalid' USING ERRCODE = '23514'; END IF;
  FOR k IN SELECT jsonb_object_keys(coalesce(p_values, '{}')) LOOP
    IF NOT EXISTS (SELECT 1 FROM school.custom_field WHERE tenant_id = school.tenant() AND entity = p_entity AND key = k) THEN
      RAISE EXCEPTION 'custom_field_unknown' USING ERRCODE = '23514', HINT = k;
    END IF;
  END LOOP;
  FOR f IN SELECT * FROM school.custom_field WHERE tenant_id = school.tenant() AND entity = p_entity AND archived_at IS NULL LOOP
    v := p_values -> f.key;
    IF v IS NULL OR jsonb_typeof(v) = 'null' OR v = '""' THEN
      IF f.required THEN RAISE EXCEPTION 'custom_value_required' USING ERRCODE = '23514', HINT = f.key; END IF;
      CONTINUE;
    END IF;
    IF NOT (CASE f.field_type
         WHEN 'text' THEN jsonb_typeof(v) = 'string' AND length(v #>> '{}') <= 1000
         WHEN 'number' THEN jsonb_typeof(v) = 'number'
         WHEN 'boolean' THEN jsonb_typeof(v) = 'boolean'
         WHEN 'date' THEN jsonb_typeof(v) = 'string' AND (v #>> '{}') ~ '^\d{4}-\d{2}-\d{2}$' AND (v #>> '{}') IS NOT NULL
         WHEN 'select' THEN jsonb_typeof(v) = 'string' AND EXISTS (SELECT 1 FROM jsonb_array_elements(f.options) o WHERE o ->> 'code' = v #>> '{}')
       END) THEN
      RAISE EXCEPTION 'custom_value_invalid' USING ERRCODE = '23514', HINT = f.key;
    END IF;
    IF f.field_type = 'date' THEN PERFORM (v #>> '{}')::date; END IF;
  END LOOP;
END $$;

-- 021's, with the retention run let through a former pupil's record; the departure and the
-- anonymisation dates are the database's.
CREATE OR REPLACE FUNCTION school.guard_student() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    PERFORM school.require('student.record.create');
    NEW.left_on := NULL; NEW.anonymised_at := NULL;
  ELSIF NEW.archived_at IS DISTINCT FROM OLD.archived_at THEN
    PERFORM school.require_core('student.record.archive', 'student', OLD.id);
    IF (to_jsonb(NEW) - 'archived_at' - 'version') IS DISTINCT FROM (to_jsonb(OLD) - 'archived_at' - 'version') THEN
      PERFORM school.require_core('student.record.update', 'student', OLD.id);
    END IF;
  ELSE
    PERFORM school.require_core('student.record.update', 'student', OLD.id);
  END IF;
  IF TG_OP = 'UPDATE' AND OLD.status = 'former' AND NOT school.retention_run()
     AND (to_jsonb(NEW) - 'archived_at' - 'version' - 'updated_at' - 'updated_by' - 'name_key')
     IS DISTINCT FROM (to_jsonb(OLD) - 'archived_at' - 'version' - 'updated_at' - 'updated_by' - 'name_key') THEN
    RAISE EXCEPTION 'student_former' USING ERRCODE = '23514';
  END IF;
  NEW.last_name_ar := nullif(btrim(NEW.last_name_ar), ''); NEW.first_name_ar := nullif(btrim(NEW.first_name_ar), '');
  NEW.last_name_lat := nullif(btrim(NEW.last_name_lat), ''); NEW.first_name_lat := nullif(btrim(NEW.first_name_lat), '');
  NEW.email := nullif(lower(btrim(NEW.email)), '');
  NEW.phone := nullif(btrim(NEW.phone), '');
  IF NEW.birth_date > current_date THEN RAISE EXCEPTION 'birth_date_future' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'UPDATE' AND NEW.status IS DISTINCT FROM OLD.status
     AND (OLD.status, NEW.status) NOT IN (('active', 'former'), ('active', 'blocked'), ('blocked', 'active')) THEN
    RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514';
  END IF;
  IF NEW.status <> 'blocked' THEN NEW.status_reason := NULL; END IF;
  -- The school's own fields, when they change: a field added later does not hold up other changes.
  IF TG_OP = 'INSERT' OR NEW.custom IS DISTINCT FROM OLD.custom THEN PERFORM school.check_custom_values('student', NEW.custom); END IF;
  IF TG_OP = 'INSERT' AND NEW.matricule IS NULL THEN
    NEW.matricule := school.next_matricule(NEW.tenant_id);
  END IF;
  RETURN NEW;
END $$;

-- The journal stays as written (BR-09); its entries older than the school's retention go, by
-- the retention run alone. Replaces 003's trigger.
CREATE FUNCTION school.guard_audit_log() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' AND school.retention_run() THEN RETURN OLD; END IF;
  RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
END $$;
DROP TRIGGER immutable ON school.audit_log;
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.audit_log FOR EACH ROW EXECUTE FUNCTION school.guard_audit_log();

-- A school's duration for a category: its own value, else the catalogue's default.
CREATE FUNCTION platform.retention_value(p_tenant uuid, p_key text) RETURNS integer
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT coalesce((SELECT (s.value #>> '{}')::integer FROM school.setting s WHERE s.tenant_id = p_tenant AND s.key = p_key AND s.archived_at IS NULL),
                  (SELECT (d.default_value #>> '{}')::integer FROM platform.setting_definition d WHERE d.key = p_key)) $$;

-- What the next run will do for a school, category by category; the anonymisation of a pupil
-- follows their age when they left (the school's majority age).
CREATE FUNCTION platform.retention_candidates(p_tenant uuid)
RETURNS TABLE (category text, id uuid, due timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH durations AS (
    SELECT platform.retention_value(p_tenant, 'retention.former_archive_months') AS archive_months,
      platform.retention_value(p_tenant, 'retention.pupil_years') AS pupil_years,
      platform.retention_value(p_tenant, 'retention.minor_years') AS minor_years,
      platform.retention_value(p_tenant, 'retention.journal_years') AS journal_years,
      coalesce((SELECT (v.value #>> '{}')::integer FROM school.setting v WHERE v.tenant_id = p_tenant AND v.key = 'student.majority_age' AND v.archived_at IS NULL),
               (SELECT (d.default_value #>> '{}')::integer FROM platform.setting_definition d WHERE d.key = 'student.majority_age')) AS majority)
  SELECT 'archive', s.id, (s.left_on + make_interval(months => d.archive_months))::timestamptz FROM school.student s, durations d
  WHERE s.tenant_id = p_tenant AND s.status = 'former' AND s.archived_at IS NULL AND s.left_on + make_interval(months => d.archive_months) <= current_date
  UNION ALL
  SELECT 'pupil', s.id, s.archived_at + make_interval(years => CASE WHEN s.birth_date > coalesce(s.left_on, s.archived_at::date) - make_interval(years => d.majority)
                                                                 THEN d.minor_years ELSE d.pupil_years END) FROM school.student s, durations d
  WHERE s.tenant_id = p_tenant AND s.archived_at IS NOT NULL AND s.anonymised_at IS NULL
    AND s.archived_at + make_interval(years => CASE WHEN s.birth_date > coalesce(s.left_on, s.archived_at::date) - make_interval(years => d.majority)
                                                    THEN d.minor_years ELSE d.pupil_years END) <= now()
  UNION ALL
  -- A guardian once every pupil linked to them, now or before, is anonymised.
  SELECT 'guardian', g.id, NULL FROM school.guardian g
  WHERE g.tenant_id = p_tenant AND g.anonymised_at IS NULL
    AND EXISTS (SELECT 1 FROM school.student_guardian l WHERE l.tenant_id = g.tenant_id AND l.guardian_id = g.id)
    AND NOT EXISTS (SELECT 1 FROM school.student_guardian l JOIN school.student s ON s.tenant_id = l.tenant_id AND s.id = l.student_id
                    WHERE l.tenant_id = g.tenant_id AND l.guardian_id = g.id AND s.anonymised_at IS NULL)
  UNION ALL
  SELECT 'journal', a.id, a.occurred_at FROM school.audit_log a, durations d
  WHERE a.tenant_id = p_tenant AND a.occurred_at < now() - make_interval(years => d.journal_years) $$;

-- The run: for each school, at most `p_limit` records of each category. A record a rule still
-- keeps (an enrolled minor's last contact, for one) stays, counted, for the next run; any other
-- error stops the run, rather than keep records forever unnoticed. What is
-- kept of an anonymised pupil: their matricule, birth year, gender, site, enrolments and groups;
-- of a guardian, nothing but their links. Each run that changes something leaves its counts in
-- the school's journal.
CREATE FUNCTION platform.apply_retention(p_limit integer)
RETURNS TABLE (tenant_id uuid, archived integer, anonymised integer, guardians integer, journal integer, kept integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid; c record; n_archived integer; n_anonymised integer; n_guardians integer; n_journal integer; n_kept integer;
BEGIN
  PERFORM set_config('app.retention', 'on', true);
  PERFORM set_config('app.reason', 'retention', true);
  FOR t IN SELECT x.id FROM platform.tenant x ORDER BY x.id LOOP
    n_archived := 0; n_anonymised := 0; n_guardians := 0; n_journal := 0; n_kept := 0;
    FOR c IN SELECT r.category, r.id FROM platform.retention_candidates(t) r WHERE r.category IN ('archive', 'pupil')
             ORDER BY r.category, r.due, r.id LOOP
      BEGIN
        IF c.category = 'archive' THEN
          UPDATE school.student SET archived_at = now() WHERE school.student.tenant_id = t AND id = c.id;
          n_archived := n_archived + 1;
        ELSE
          UPDATE school.student SET last_name_lat = 'ANONYME', first_name_lat = matricule, last_name_ar = NULL, first_name_ar = NULL,
            birth_date = make_date(extract(year FROM birth_date)::integer, 1, 1), birth_place = NULL, phone = NULL, email = NULL, address = NULL,
            origin_school = NULL, custom = '{}', external_ref = NULL, user_id = NULL, photo_file_id = NULL, photo_thumb_file_id = NULL,
            status_reason = NULL, anonymised_at = now()
          WHERE school.student.tenant_id = t AND id = c.id;
          n_anonymised := n_anonymised + 1;
        END IF;
      EXCEPTION WHEN integrity_constraint_violation THEN n_kept := n_kept + 1;
      END;
      EXIT WHEN n_archived + n_anonymised + n_kept >= 2 * p_limit;
    END LOOP;
    -- After the pupils: a guardian's last pupil may have been anonymised just now.
    FOR c IN SELECT r.id FROM platform.retention_candidates(t) r WHERE r.category = 'guardian' ORDER BY r.id LIMIT p_limit LOOP
      BEGIN
        UPDATE school.guardian SET last_name_lat = 'ANONYME', first_name_lat = 'ANONYME', last_name_ar = NULL, first_name_ar = NULL,
          phone = NULL, email = NULL, profession = NULL, custom = '{}', external_ref = NULL, user_id = NULL,
          archived_at = coalesce(archived_at, now()), anonymised_at = now()
        WHERE school.guardian.tenant_id = t AND id = c.id;
        n_guardians := n_guardians + 1;
      EXCEPTION WHEN integrity_constraint_violation THEN n_kept := n_kept + 1;
      END;
    END LOOP;
    DELETE FROM school.audit_log a WHERE a.tenant_id = t AND a.id IN (
      SELECT x.id FROM school.audit_log x WHERE x.tenant_id = t
        AND x.occurred_at < now() - make_interval(years => platform.retention_value(t, 'retention.journal_years')) ORDER BY x.occurred_at LIMIT p_limit);
    GET DIAGNOSTICS n_journal = ROW_COUNT;
    IF n_archived + n_anonymised + n_guardians + n_journal + n_kept > 0 THEN
      INSERT INTO school.audit_log (tenant_id, action, object_type, object_id, after, reason)
      VALUES (t, 'RETENTION', 'tenant', t, jsonb_build_object('archived', n_archived, 'anonymised', n_anonymised, 'guardians', n_guardians,
        'journal', n_journal, 'kept', n_kept), 'retention');
      tenant_id := t; archived := n_archived; anonymised := n_anonymised; guardians := n_guardians; journal := n_journal; kept := n_kept;
      RETURN NEXT;
    END IF;
  END LOOP;
  PERFORM set_config('app.retention', '', true);
  PERFORM set_config('app.reason', '', true);
END $$;

-- What the next run will do in the reader's school, for whoever manages its settings: counts only.
CREATE FUNCTION school.retention_due() RETURNS TABLE (category text, due integer)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NOT school.can_everywhere('tenant.settings.manage') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  RETURN QUERY SELECT k.category, (SELECT count(*)::integer FROM platform.retention_candidates(school.tenant()) r WHERE r.category = k.category)
    FROM unnest(ARRAY['archive', 'pupil', 'guardian', 'journal']) AS k(category);
END $$;
