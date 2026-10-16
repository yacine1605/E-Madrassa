-- Lot 2, the staff's import (FR-IMP-01, FR-STF-01, FR-STF-02): members of staff and their
-- qualifications from the school's files, as 025 did for pupils. A member keeps the reference of
-- the file they came from, so that the same file imported again updates them or leaves them as
-- they are, never duplicates them (BR-05); the reference never changes once given, and the
-- journal masks it as it masks a pupil's (`external_ref`, 025).
ALTER TABLE school.staff ADD COLUMN external_ref text CHECK (length(btrim(external_ref)) BETWEEN 1 AND 100);
CREATE UNIQUE INDEX staff_external_ref ON school.staff (tenant_id, external_ref) WHERE external_ref IS NOT NULL;
CREATE TRIGGER external_ref_frozen BEFORE UPDATE ON school.staff FOR EACH ROW EXECUTE FUNCTION school.freeze_code('external_ref');

-- Undoing an import (FR-IMP-01): a member an import still in place created goes while nothing
-- has been built on them since: active as imported, without an account, a qualification, an
-- availability, a day off, a contract, a leave or a group they lead. Deleted by this function
-- alone, as the provisioner; the journal keeps both the creation and the removal.
CREATE FUNCTION school.discard_staff(p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); s school.staff;
BEGIN
  PERFORM school.require_everywhere('staff.profile.create');
  SELECT * INTO s FROM school.staff WHERE tenant_id = t AND id = p_id FOR UPDATE;
  IF s.id IS NULL OR NOT school.import_created('staff', p_id) OR s.status <> 'active' OR s.archived_at IS NOT NULL OR s.user_id IS NOT NULL
     OR EXISTS (SELECT 1 FROM school.qualification WHERE tenant_id = t AND staff_id = p_id)
     OR EXISTS (SELECT 1 FROM school.availability WHERE tenant_id = t AND staff_id = p_id)
     OR EXISTS (SELECT 1 FROM school.unavailability WHERE tenant_id = t AND staff_id = p_id)
     OR EXISTS (SELECT 1 FROM school.staff_contract WHERE tenant_id = t AND staff_id = p_id)
     OR EXISTS (SELECT 1 FROM school.staff_leave WHERE tenant_id = t AND staff_id = p_id)
     OR EXISTS (SELECT 1 FROM school.study_group WHERE tenant_id = t AND lead_teacher_id = p_id) THEN
    RAISE EXCEPTION 'dependent_operation' USING ERRCODE = '23514';
  END IF;
  PERFORM set_config('app.reason', 'import_rollback', true);
  DELETE FROM school.staff WHERE tenant_id = t AND id = p_id;
END $$;
