-- Lot 2, the reference import (FR-IMP-01; lot 2 exit: 500 pupils and their data; R02, TST-14).
-- Pupils and guardians keep the reference of the file they came from, so that the same file
-- imported again updates them or leaves them as they are, never duplicates them (BR-05). The
-- reference never changes once given.
ALTER TABLE school.student ADD COLUMN external_ref text CHECK (length(btrim(external_ref)) BETWEEN 1 AND 100);
CREATE UNIQUE INDEX student_external_ref ON school.student (tenant_id, external_ref) WHERE external_ref IS NOT NULL;
CREATE TRIGGER external_ref_frozen BEFORE UPDATE ON school.student FOR EACH ROW EXECUTE FUNCTION school.freeze_code('external_ref');
ALTER TABLE school.guardian ADD COLUMN external_ref text CHECK (length(btrim(external_ref)) BETWEEN 1 AND 100);
CREATE UNIQUE INDEX guardian_external_ref ON school.guardian (tenant_id, external_ref) WHERE external_ref IS NOT NULL;
CREATE TRIGGER external_ref_frozen BEFORE UPDATE ON school.guardian FOR EACH ROW EXECUTE FUNCTION school.freeze_code('external_ref');

-- BR-17: a reference from another system often is a former matricule; the journal masks it like
-- one. Replaces 021's.
CREATE OR REPLACE FUNCTION school.audit() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE row_data jsonb; old_data jsonb; changed jsonb; before_values jsonb; after_values jsonb; masked text[];
  ctx school.request_context := school.context();
BEGIN
  row_data := (CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END) - 'name_key';
  old_data := (CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE to_jsonb(OLD) END) - 'name_key';
  SELECT COALESCE(jsonb_agg(key), '[]') INTO changed FROM jsonb_each(row_data)
  WHERE TG_OP <> 'UPDATE' OR value IS DISTINCT FROM old_data -> key;
  masked := ARRAY['first_name', 'last_name', 'birth_date', 'birth_place', 'phone', 'email', 'address', 'matricule',
                  'photo', 'observation', 'body', 'payload', 'rendered', 'rules', 'calculation', 'password',
                  'last_name_ar', 'first_name_ar', 'last_name_lat', 'first_name_lat', 'photo_file_id', 'origin_school', 'profession', 'custom',
                  'external_ref'];
  IF TG_OP = 'UPDATE' THEN
    SELECT jsonb_object_agg(key, CASE WHEN key = ANY (masked) THEN to_jsonb('[masqué]'::text) ELSE old_data -> key END),
           jsonb_object_agg(key, CASE WHEN key = ANY (masked) THEN to_jsonb('[masqué]'::text) ELSE value END)
    INTO before_values, after_values
    FROM jsonb_each(row_data)
    WHERE value IS DISTINCT FROM old_data -> key AND key NOT IN ('version', 'updated_at', 'updated_by') AND key NOT LIKE 'label\_%\_key';
  ELSIF TG_OP = 'DELETE' THEN
    SELECT jsonb_object_agg(key, CASE WHEN key = ANY (masked) THEN to_jsonb('[masqué]'::text) ELSE value END) INTO before_values
    FROM jsonb_each(old_data) WHERE key NOT LIKE 'label\_%\_key';
  END IF;
  INSERT INTO school.audit_log (tenant_id, actor_id, action, object_type, object_id, changed_fields, before, after, reason, request_id, ip)
  VALUES ((row_data ->> 'tenant_id')::uuid, ctx.user_id, TG_OP, TG_TABLE_NAME, (row_data ->> 'id')::uuid, changed,
          before_values, after_values, nullif(current_setting('app.reason', true), ''), ctx.request_id, ctx.ip);
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

-- Undoing an import (FR-IMP-01: "retour arrière tant qu'aucune opération dépendante n'a été
-- saisie"). What an import still in place reported as created goes, provided nothing has been
-- built on it since; anything else is an operation that depends on the import. These rows are
-- deleted by the functions below alone, which run as the provisioner; the journal keeps both the
-- creation and the removal (BR-02: nothing that holds a history is deleted).
CREATE FUNCTION school.import_created(p_type text, p_id uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT EXISTS (SELECT 1 FROM school.import_row r JOIN school.import_batch b ON b.tenant_id = r.tenant_id AND b.id = r.batch_id
    WHERE r.tenant_id = school.tenant() AND b.status = 'imported' AND r.outcome = 'created'
      AND r.previous -> 'created' ->> p_type = p_id::text) $$;

-- A pupil without an enrolment, a guardian, an account or a photo, active as imported.
CREATE FUNCTION school.discard_student(p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); s school.student;
BEGIN
  PERFORM school.require_everywhere('student.record.create');
  SELECT * INTO s FROM school.student WHERE tenant_id = t AND id = p_id FOR UPDATE;
  IF s.id IS NULL OR NOT school.import_created('student', p_id) OR s.status <> 'active' OR s.archived_at IS NOT NULL
     OR s.user_id IS NOT NULL OR s.photo_file_id IS NOT NULL
     OR EXISTS (SELECT 1 FROM school.enrollment WHERE tenant_id = t AND student_id = p_id)
     OR EXISTS (SELECT 1 FROM school.student_guardian WHERE tenant_id = t AND student_id = p_id) THEN
    RAISE EXCEPTION 'dependent_operation' USING ERRCODE = '23514';
  END IF;
  PERFORM set_config('app.reason', 'import_rollback', true);
  DELETE FROM school.student WHERE tenant_id = t AND id = p_id;
END $$;

-- A guardian linked to no pupil any more, without an account.
CREATE FUNCTION school.discard_guardian(p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); g school.guardian;
BEGIN
  PERFORM school.require_everywhere('guardian.record.create');
  SELECT * INTO g FROM school.guardian WHERE tenant_id = t AND id = p_id FOR UPDATE;
  IF g.id IS NULL OR NOT school.import_created('guardian', p_id) OR g.archived_at IS NOT NULL OR g.user_id IS NOT NULL
     OR EXISTS (SELECT 1 FROM school.student_guardian WHERE tenant_id = t AND guardian_id = p_id) THEN
    RAISE EXCEPTION 'dependent_operation' USING ERRCODE = '23514';
  END IF;
  PERFORM set_config('app.reason', 'import_rollback', true);
  DELETE FROM school.guardian WHERE tenant_id = t AND id = p_id;
END $$;

-- A link still in place, unless it is the last primary contact with a phone of a pupil enrolled
-- since: their enrolment relied on it (FR-ELV-02 AC2).
CREATE FUNCTION school.discard_guardian_link(p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); l school.student_guardian;
BEGIN
  PERFORM school.require_everywhere('guardian.record.create');
  SELECT * INTO l FROM school.student_guardian WHERE tenant_id = t AND id = p_id FOR UPDATE;
  IF l.id IS NULL OR NOT school.import_created('student_guardian', p_id) OR l.archived_at IS NOT NULL
     OR (l.is_primary_contact
         AND EXISTS (SELECT 1 FROM school.enrollment WHERE tenant_id = t AND student_id = l.student_id AND status <> 'cancelled')
         AND NOT EXISTS (SELECT 1 FROM school.student_guardian o JOIN school.guardian g ON g.tenant_id = o.tenant_id AND g.id = o.guardian_id
                         WHERE o.tenant_id = t AND o.student_id = l.student_id AND o.id <> l.id AND o.is_primary_contact
                           AND o.archived_at IS NULL AND g.archived_at IS NULL AND g.phone IS NOT NULL)) THEN
    RAISE EXCEPTION 'dependent_operation' USING ERRCODE = '23514';
  END IF;
  PERFORM set_config('app.reason', 'import_rollback', true);
  DELETE FROM school.student_guardian WHERE tenant_id = t AND id = p_id;
END $$;

-- An enrolment in the state the import left it: pending, or validated by the import itself, in
-- its first group at most, never transferred; its history then holds the import's states only.
CREATE FUNCTION school.discard_enrollment(p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); e school.enrollment;
BEGIN
  PERFORM school.require_everywhere('enrollment.enrollment.create');
  SELECT * INTO e FROM school.enrollment WHERE tenant_id = t AND id = p_id FOR UPDATE;
  IF e.id IS NULL OR NOT school.import_created('enrollment', p_id) OR e.status NOT IN ('pending', 'validated')
     OR (SELECT count(*) FROM school.enrollment_status_history WHERE tenant_id = t AND enrollment_id = p_id)
        <> (CASE e.status WHEN 'pending' THEN 1 ELSE 2 END)
     OR EXISTS (SELECT 1 FROM school.group_membership WHERE tenant_id = t AND enrollment_id = p_id AND valid_to IS NOT NULL)
     OR (SELECT count(*) FROM school.group_membership WHERE tenant_id = t AND enrollment_id = p_id) > 1
     OR EXISTS (SELECT 1 FROM school.enrollment WHERE tenant_id = t AND transferred_from = p_id) THEN
    RAISE EXCEPTION 'dependent_operation' USING ERRCODE = '23514';
  END IF;
  PERFORM set_config('app.reason', 'import_rollback', true);
  DELETE FROM school.group_membership WHERE tenant_id = t AND enrollment_id = p_id;
  DELETE FROM school.enrollment_status_history WHERE tenant_id = t AND enrollment_id = p_id;
  DELETE FROM school.enrollment WHERE tenant_id = t AND id = p_id;
END $$;

-- An enrolment's history is never changed, and deleted by that function alone, as the provisioner.
CREATE FUNCTION school.guard_history_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP <> 'DELETE' OR current_user <> 'platform_provisioner' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  RETURN OLD;
END $$;
DROP TRIGGER immutable ON school.enrollment_status_history;
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.enrollment_status_history FOR EACH ROW EXECUTE FUNCTION school.guard_history_delete();
