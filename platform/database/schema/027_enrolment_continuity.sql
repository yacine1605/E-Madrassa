-- Lot 2, an enrolment's continuity: a minor stays with a primary contact while enrolled
-- (FR-ELV-02), and a programme left for another is one transfer, both enrolments linked (FR-INS-03).

-- FR-ELV-02: « au moins un contact principal avec téléphone pour un élève mineur ». The
-- enrolment checks it (021); afterwards, a minor enrolled in a year still open keeps at least one
-- primary contact with a phone. Checked after each change that could take the last one away,
-- with the pupil's row locked: two changes at once (two links unset by two secretaries) cannot
-- both remove one of the two contacts, and an enrolment being made waits for them.
CREATE FUNCTION school.keep_primary_contact(p_tenant uuid, p_student uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE s school.student; majority integer;
BEGIN
  SELECT * INTO s FROM school.student WHERE tenant_id = p_tenant AND id = p_student FOR NO KEY UPDATE;
  majority := coalesce((SELECT (v.value #>> '{}')::integer FROM school.setting v
                        WHERE v.tenant_id = p_tenant AND v.key = 'student.majority_age' AND v.archived_at IS NULL),
                       (SELECT (d.default_value #>> '{}')::integer FROM platform.setting_definition d WHERE d.key = 'student.majority_age'));
  IF s.id IS NULL OR s.birth_date <= current_date - make_interval(years => majority) THEN RETURN; END IF;
  IF EXISTS (SELECT 1 FROM school.enrollment e JOIN school.academic_year y ON y.tenant_id = e.tenant_id AND y.id = e.year_id
             WHERE e.tenant_id = p_tenant AND e.student_id = p_student AND e.status <> 'cancelled' AND y.status <> 'closed')
     AND NOT EXISTS (SELECT 1 FROM school.student_guardian l JOIN school.guardian g ON g.tenant_id = l.tenant_id AND g.id = l.guardian_id
                     WHERE l.tenant_id = p_tenant AND l.student_id = p_student AND l.is_primary_contact AND l.archived_at IS NULL
                       AND g.archived_at IS NULL AND g.phone IS NOT NULL) THEN
    RAISE EXCEPTION 'primary_contact_kept' USING ERRCODE = '23514';
  END IF;
END $$;

-- What may take a contact away: a link no longer primary, archived or deleted; a guardian without
-- a phone or archived; a birth date that makes the pupil a minor.
CREATE FUNCTION school.check_primary_contact() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE pupil uuid;
BEGIN
  IF TG_TABLE_NAME = 'student_guardian' THEN
    IF OLD.is_primary_contact AND OLD.archived_at IS NULL AND (TG_OP = 'DELETE' OR NOT NEW.is_primary_contact OR NEW.archived_at IS NOT NULL) THEN
      PERFORM school.keep_primary_contact(OLD.tenant_id, OLD.student_id);
    END IF;
  ELSIF TG_TABLE_NAME = 'guardian' THEN
    IF OLD.phone IS NOT NULL AND OLD.archived_at IS NULL AND (NEW.phone IS NULL OR NEW.archived_at IS NOT NULL) THEN
      FOR pupil IN SELECT student_id FROM school.student_guardian
                   WHERE tenant_id = OLD.tenant_id AND guardian_id = OLD.id AND is_primary_contact AND archived_at IS NULL LOOP
        PERFORM school.keep_primary_contact(OLD.tenant_id, pupil);
      END LOOP;
    END IF;
  ELSIF NEW.birth_date IS DISTINCT FROM OLD.birth_date THEN
    PERFORM school.keep_primary_contact(NEW.tenant_id, NEW.id);
  END IF;
  RETURN NULL;
END $$;
CREATE TRIGGER primary_contact AFTER UPDATE OF is_primary_contact, archived_at OR DELETE ON school.student_guardian
  FOR EACH ROW EXECUTE FUNCTION school.check_primary_contact();
CREATE TRIGGER primary_contact AFTER UPDATE OF phone, archived_at ON school.guardian FOR EACH ROW EXECUTE FUNCTION school.check_primary_contact();
CREATE TRIGGER primary_contact AFTER UPDATE OF birth_date ON school.student FOR EACH ROW EXECUTE FUNCTION school.check_primary_contact();

-- FR-INS-03: « transfert d'un programme à un autre : annule l'inscription source (cancelled),
-- crée la cible liée (transferred_from) ». One target per source.
CREATE UNIQUE INDEX enrollment_transfer_once ON school.enrollment (tenant_id, transferred_from) WHERE transferred_from IS NOT NULL;

-- The link is set by the database alone (the application has no privilege on the column), for a
-- source of the same pupil cancelled in this very transaction: the transfer's.
CREATE FUNCTION school.link_transfer() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE source uuid := nullif(current_setting('app.transfer_from', true), '')::uuid;
BEGIN
  IF source IS NULL THEN RETURN NEW; END IF;
  IF NOT EXISTS (SELECT 1 FROM school.enrollment WHERE tenant_id = NEW.tenant_id AND id = source AND student_id = NEW.student_id
                 AND status = 'cancelled' AND updated_at = now()) THEN
    RAISE EXCEPTION 'transfer_invalid' USING ERRCODE = '23514';
  END IF;
  NEW.transferred_from := source;
  RETURN NEW;
END $$;
-- After `rules` (021), which clears the column of every new enrolment.
CREATE TRIGGER transfer BEFORE INSERT ON school.enrollment FOR EACH ROW EXECUTE FUNCTION school.link_transfer();

-- The transfer, in one transaction (AC2): the source cancelled with its reason, its places given
-- back, then the target made by the enrolment's own rules (an active programme, the price of the
-- day, the quota, a minor's primary contact) and linked to it. With the writer's rights: each
-- step needs its permission and scope. Asked for twice with the same key (a double click, a
-- retry), the first transfer is the answer, as for an enrolment (TST-08).
CREATE FUNCTION school.transfer_enrollment(p_source uuid, p_program uuid, p_key uuid, p_reason text, p_version integer) RETURNS uuid
LANGUAGE plpgsql AS $$
DECLARE src school.enrollment; e school.enrollment;
BEGIN
  SELECT * INTO src FROM school.enrollment WHERE tenant_id = school.tenant() AND id = p_source FOR UPDATE;
  IF src.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  -- After the source's lock: a transfer made meanwhile with this key is seen.
  SELECT * INTO e FROM school.enrollment WHERE tenant_id = src.tenant_id AND idempotency_key = p_key;
  IF e.id IS NOT NULL THEN
    IF e.transferred_from IS DISTINCT FROM src.id OR e.program_id IS DISTINCT FROM p_program THEN
      RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514';
    END IF;
    RETURN e.id;
  END IF;
  IF p_program = src.program_id THEN RAISE EXCEPTION 'transfer_same_program' USING ERRCODE = '23514'; END IF;
  UPDATE school.enrollment SET status = 'cancelled', status_reason = p_reason, version = p_version WHERE tenant_id = src.tenant_id AND id = src.id;
  PERFORM set_config('app.transfer_from', src.id::text, true);
  INSERT INTO school.enrollment (tenant_id, student_id, program_id, idempotency_key)
  VALUES (src.tenant_id, src.student_id, p_program, p_key) RETURNING * INTO e;
  PERFORM set_config('app.transfer_from', '', true);
  RETURN e.id;
END $$;
