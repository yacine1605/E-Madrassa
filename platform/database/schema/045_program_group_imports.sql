-- Lot 2, programmes and groups from the school's files (FR-IMP-01, FR-PRG-01, FR-GRP-01; section
-- 3.3: the head of studies imports « staff, groupes »). A programme is found by its year and code,
-- a group by its year and code, both never changed (BR-18): a file imported again updates them
-- rather than records them twice (BR-05). One an import created is undone while nothing has been
-- built on it since.

-- A programme's prices are never changed nor deleted (021), but with the programme an import
-- created and undoes at once: its function alone, with its flag.
CREATE FUNCTION school.guard_program_price() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' AND current_user = 'platform_provisioner' AND coalesce(current_setting('app.discard_program', true), '') = OLD.program_id::text THEN
    RETURN OLD;
  END IF;
  RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514';
END $$;
DROP TRIGGER immutable ON school.program_price;
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.program_price FOR EACH ROW EXECUTE FUNCTION school.guard_program_price();

-- Undoing an import: a programme it created goes, with its prices, while nothing uses it: no
-- enrolment (whose price it keeps), group, subject or copy. Deleted by this function alone, as the provisioner.
CREATE FUNCTION school.discard_program(p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); p school.program;
BEGIN
  PERFORM school.require_everywhere('program.program.manage');
  SELECT * INTO p FROM school.program WHERE tenant_id = t AND id = p_id FOR UPDATE;
  IF p.id IS NULL OR NOT school.import_created('program', p_id) OR p.archived_at IS NOT NULL
     OR EXISTS (SELECT 1 FROM school.enrollment WHERE tenant_id = t AND program_id = p_id)
     OR EXISTS (SELECT 1 FROM school.study_group WHERE tenant_id = t AND program_id = p_id)
     OR EXISTS (SELECT 1 FROM school.program_subject WHERE tenant_id = t AND program_id = p_id)
     OR EXISTS (SELECT 1 FROM school.program WHERE tenant_id = t AND copied_from = p_id) THEN
    RAISE EXCEPTION 'dependent_operation' USING ERRCODE = '23514';
  END IF;
  PERFORM set_config('app.reason', 'import_rollback', true);
  PERFORM set_config('app.discard_program', p_id::text, true);
  DELETE FROM school.program_price WHERE tenant_id = t AND program_id = p_id;
  PERFORM set_config('app.discard_program', '', true);
  DELETE FROM school.program WHERE tenant_id = t AND id = p_id;
END $$;

-- A group it created goes while nobody has been placed in it, nor a promotion aimed at it.
CREATE FUNCTION school.discard_group(p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); g school.study_group;
BEGIN
  PERFORM school.require_everywhere('group.group.manage');
  SELECT * INTO g FROM school.study_group WHERE tenant_id = t AND id = p_id FOR UPDATE;
  IF g.id IS NULL OR NOT school.import_created('study_group', p_id) OR g.archived_at IS NOT NULL
     OR EXISTS (SELECT 1 FROM school.group_membership WHERE tenant_id = t AND group_id = p_id)
     OR EXISTS (SELECT 1 FROM school.progression WHERE tenant_id = t AND target_group_id = p_id) THEN
    RAISE EXCEPTION 'dependent_operation' USING ERRCODE = '23514';
  END IF;
  PERFORM set_config('app.reason', 'import_rollback', true);
  DELETE FROM school.study_group WHERE tenant_id = t AND id = p_id;
END $$;
