-- Lot 2, the prospects' import (FR-IMP-01; section 3.3 of the SRS, the front office imports « élèves,
-- CRM »): a prospect keeps the reference of the school's file, never changed, so that a file
-- imported again updates it rather than records it twice (BR-05); one an import created is undone
-- while nothing has been built on it since.
--
-- And a fix of the retention run (030, NFR-DATA-03): it clears the reference of an imported pupil
-- or guardian when it anonymises them, which the references' frozen code refused; the refusal was
-- counted as a rule keeping the record, and imported records were never anonymised. The run alone
-- (its flag) now passes the frozen codes; nobody else.

-- Replaces 001's: the retention run lets a frozen code go (a reference cleared with the person).
CREATE OR REPLACE FUNCTION school.freeze_code() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
  IF to_jsonb(NEW) -> TG_ARGV[0] IS DISTINCT FROM to_jsonb(OLD) -> TG_ARGV[0] THEN
    RAISE EXCEPTION 'code_immutable' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;

-- The reference of the school's file; masked in the journal as a pupil's (`external_ref`, 025).
ALTER TABLE school.lead ADD COLUMN external_ref text CHECK (length(btrim(external_ref)) BETWEEN 1 AND 100);
CREATE UNIQUE INDEX lead_external_ref ON school.lead (tenant_id, external_ref) WHERE external_ref IS NOT NULL;
CREATE TRIGGER external_ref_frozen BEFORE UPDATE ON school.lead FOR EACH ROW EXECUTE FUNCTION school.freeze_code('external_ref');

-- Undoing an import: a prospect an import still in place created goes, with its children, while
-- nothing has been built on it since: still open, no interaction, no reminder, no child converted.
-- Deleted by this function alone, as the provisioner; the journal keeps both the creation and the removal.
CREATE FUNCTION school.discard_lead(p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); l school.lead;
BEGIN
  PERFORM school.require_everywhere('crm.lead.create');
  SELECT * INTO l FROM school.lead WHERE tenant_id = t AND id = p_id FOR UPDATE;
  IF l.id IS NULL OR NOT school.import_created('lead', p_id) OR l.closed_at IS NOT NULL
     OR EXISTS (SELECT 1 FROM school.lead_interaction WHERE tenant_id = t AND lead_id = p_id)
     OR EXISTS (SELECT 1 FROM school.lead_reminder WHERE tenant_id = t AND lead_id = p_id)
     OR EXISTS (SELECT 1 FROM school.lead_child WHERE tenant_id = t AND lead_id = p_id AND student_id IS NOT NULL) THEN
    RAISE EXCEPTION 'dependent_operation' USING ERRCODE = '23514';
  END IF;
  PERFORM set_config('app.reason', 'import_rollback', true);
  DELETE FROM school.lead_child WHERE tenant_id = t AND lead_id = p_id;
  DELETE FROM school.lead WHERE tenant_id = t AND id = p_id;
END $$;

-- 042's run, the file's reference cleared with the rest.
CREATE OR REPLACE FUNCTION platform.apply_lead_retention(p_limit integer) RETURNS TABLE (tenant_id uuid, leads integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid; c record; n integer;
BEGIN
  PERFORM set_config('app.retention', 'on', true);
  PERFORM set_config('app.reason', 'retention', true);
  FOR t IN SELECT x.id FROM platform.tenant x ORDER BY x.id LOOP
    n := 0;
    FOR c IN SELECT r.id FROM platform.lead_retention_candidates(t) r ORDER BY r.due, r.id LIMIT p_limit LOOP
      UPDATE school.lead SET last_name = 'ANONYME', first_name = NULL, phone = NULL, email = NULL, custom = '{}', lost_reason = 'ANONYME',
        external_ref = NULL, anonymised_at = now() WHERE school.lead.tenant_id = t AND school.lead.id = c.id;
      UPDATE school.lead_child SET first_name = 'ANONYME', last_name = NULL, birth_date = NULL WHERE school.lead_child.tenant_id = t AND lead_id = c.id;
      UPDATE school.lead_interaction SET body = 'ANONYME' WHERE school.lead_interaction.tenant_id = t AND lead_id = c.id;
      UPDATE school.lead_reminder SET body = 'ANONYME' WHERE school.lead_reminder.tenant_id = t AND lead_id = c.id;
      n := n + 1;
    END LOOP;
    IF n > 0 THEN
      INSERT INTO school.audit_log (tenant_id, action, object_type, object_id, after, reason)
      VALUES (t, 'RETENTION', 'tenant', t, jsonb_build_object('leads', n), 'retention');
      tenant_id := t; leads := n;
      RETURN NEXT;
    END IF;
  END LOOP;
  PERFORM set_config('app.retention', '', true);
  PERFORM set_config('app.reason', '', true);
END $$;
