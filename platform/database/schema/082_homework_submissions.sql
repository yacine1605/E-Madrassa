-- Lot 5, homework submissions (SRS 1.2, FR-LMS-02; SPECIFICATION_LOT5_PEDAGOGIE.md §5): a pupil of the
-- audience hands in one to five HTTPS links of the school's allow-list (078), never a file. Each
-- submission is a version, dated by the server, kept: a new one replaces the links to be corrected,
-- the previous ones stay in the history. Sent again with the same key, the same submission; another
-- content under that key is refused. After the deadline (the pupil's own if extended), a submission
-- is refused unless the activity accepts it late until a date, then marked late. The teacher flags a
-- link that does not open and asks for another: the pupil hands in a new version within the
-- activity's delay (48 hours by default), which keeps the first one's punctuality. The timestamp
-- proves a link was handed in at that moment, not that what it shows has not changed since.

CREATE TABLE school.homework_submission (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  activity_id uuid NOT NULL,
  version_id uuid NOT NULL,
  student_id uuid NOT NULL,
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  number integer NOT NULL CHECK (number >= 1),
  idempotency_key uuid NOT NULL,
  links jsonb NOT NULL CHECK (jsonb_typeof(links) = 'array' AND jsonb_array_length(links) BETWEEN 1 AND 5),
  submitted_at timestamptz NOT NULL DEFAULT now(),
  late boolean NOT NULL,
  status text NOT NULL DEFAULT 'current' CHECK (status IN ('current', 'replaced', 'inaccessible')),
  flagged_at timestamptz,
  flagged_by bigint REFERENCES public.users(id),
  flag_reason text CHECK (length(flag_reason) BETWEEN 3 AND 500),
  replace_until timestamptz,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK ((flagged_at IS NULL) = (flag_reason IS NULL) AND (flagged_at IS NULL) = (replace_until IS NULL)),
  UNIQUE (tenant_id, activity_id, student_id, number),
  UNIQUE (tenant_id, idempotency_key),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, activity_id) REFERENCES school.activity (tenant_id, id),
  FOREIGN KEY (tenant_id, version_id) REFERENCES school.activity_version (tenant_id, id),
  FOREIGN KEY (tenant_id, student_id) REFERENCES school.student (tenant_id, id)
);
-- One version to correct at a time per pupil and homework.
CREATE UNIQUE INDEX homework_submission_current ON school.homework_submission (tenant_id, activity_id, student_id) WHERE status = 'current';
CALL school.protect('school.homework_submission');

-- What is wrong with a submission's links, if anything: 1 to 5 addresses, each an HTTPS address of
-- the allow-list (078), each once.
CREATE FUNCTION school.links_error(p_links jsonb) RETURNS text LANGUAGE sql STABLE AS $$
  SELECT CASE
    WHEN jsonb_typeof(p_links) IS DISTINCT FROM 'array' OR jsonb_array_length(p_links) NOT BETWEEN 1 AND 5
      OR EXISTS (SELECT 1 FROM jsonb_array_elements(p_links) l WHERE jsonb_typeof(l) <> 'string')
      OR (SELECT count(DISTINCT l) FROM jsonb_array_elements(p_links) l) <> jsonb_array_length(p_links) THEN 'lms_link_invalid'
    ELSE (SELECT e FROM jsonb_array_elements_text(p_links) l, school.lms_link_error(l) e WHERE e IS NOT NULL LIMIT 1)
  END $$;

-- FR-LMS-02: the caller hands in their homework's links. Before their deadline, on time; after it,
-- within the replacement delay of a link flagged, with the punctuality of the version flagged;
-- otherwise late until the activity's late acceptance ends, or refused. Each one a version; the
-- same key, the same submission.
CREATE FUNCTION school.submit_homework(p_activity uuid, p_key uuid, p_links jsonb) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); me uuid := school.my_student(); r record; s school.homework_submission; last_one school.homework_submission;
  v_late boolean; e text; n integer;
BEGIN
  IF p_key IS NULL THEN RAISE EXCEPTION 'idempotency_key_required' USING ERRCODE = '23514'; END IF;
  SELECT * INTO s FROM school.homework_submission x WHERE x.tenant_id = t AND x.idempotency_key = p_key;
  IF s.id IS NOT NULL THEN
    IF (s.activity_id, s.student_id, s.links) IS DISTINCT FROM (p_activity, me, p_links) THEN RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514'; END IF;
    RETURN s.id;
  END IF;
  SELECT * INTO r FROM school.my_activities() m WHERE m.activity_id = p_activity AND m.kind = 'homework';
  IF r.activity_id IS NULL OR me IS NULL THEN RAISE EXCEPTION 'lms_access_denied' USING ERRCODE = '42501'; END IF;
  IF now() < r.opens_at THEN RAISE EXCEPTION 'lms_activity_not_open' USING ERRCODE = '23514'; END IF;
  e := school.links_error(p_links);
  IF e IS NOT NULL THEN RAISE EXCEPTION '%', e USING ERRCODE = '23514'; END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended('homework:' || p_activity::text || ':' || me::text, 82));
  SELECT * INTO last_one FROM school.homework_submission x WHERE x.tenant_id = t AND x.activity_id = p_activity AND x.student_id = me
    ORDER BY x.number DESC LIMIT 1;
  IF now() < r.deadline_at THEN
    v_late := false;
  ELSIF last_one.status = 'inaccessible' AND now() < last_one.replace_until THEN
    v_late := last_one.late;
  ELSIF r.late_policy = 'accept_until' AND now() < r.closes_at THEN
    v_late := true;
  ELSE
    RAISE EXCEPTION 'lms_deadline_passed' USING ERRCODE = '23514';
  END IF;
  n := coalesce(last_one.number, 0) + 1;
  UPDATE school.homework_submission SET status = 'replaced' WHERE tenant_id = t AND activity_id = p_activity AND student_id = me AND status = 'current';
  INSERT INTO school.homework_submission (tenant_id, activity_id, version_id, student_id, group_id, subject_id, number, idempotency_key, links, late)
  VALUES (t, p_activity, r.version_id, me, r.group_id, r.subject_id, n, p_key, p_links, v_late)
  RETURNING id INTO s.id;
  RETURN s.id;
END $$;

-- The teacher flags the version to correct as not opening, with the reason, and asks for another:
-- the pupil has the activity's delay to hand one in. The version is kept, marked inaccessible.
CREATE FUNCTION school.flag_submission(p_submission uuid, p_reason text) RETURNS timestamptz
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); s school.homework_submission; v_until timestamptz;
BEGIN
  SELECT * INTO s FROM school.homework_submission x WHERE x.tenant_id = t AND x.id = p_submission FOR UPDATE;
  IF s.id IS NULL OR NOT school.manages_activity(s.group_id, s.subject_id) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  IF s.status <> 'current' THEN RAISE EXCEPTION 'lms_submission_not_current' USING ERRCODE = '23514'; END IF;
  IF length(btrim(coalesce(p_reason, ''))) < 3 THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  SELECT now() + make_interval(hours => v.replacement_hours) INTO v_until FROM school.activity_version v WHERE v.tenant_id = t AND v.id = s.version_id;
  UPDATE school.homework_submission SET status = 'inaccessible', flagged_at = now(), flagged_by = school.actor(), flag_reason = btrim(p_reason),
    replace_until = v_until WHERE tenant_id = t AND id = s.id;
  RETURN v_until;
END $$;

-- The caller's own submissions of a homework, newest first, with what was flagged and until when to replace it.
CREATE FUNCTION school.my_submissions(p_activity uuid) RETURNS TABLE (id uuid, number integer, links jsonb, submitted_at timestamptz, late boolean,
  status text, flag_reason text, replace_until timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT x.id, x.number, x.links, x.submitted_at, x.late, x.status, x.flag_reason, x.replace_until FROM school.homework_submission x
  WHERE x.tenant_id = school.allowed_tenant() AND x.activity_id = p_activity AND x.student_id = school.my_student()
  ORDER BY x.number DESC $$;

-- Read by the pedagogy manager and the teachers of the group's subject today; the pupil through
-- their functions; written by those functions alone.
CREATE POLICY resource_reader ON school.homework_submission AS RESTRICTIVE FOR ALL TO school_runtime USING (
  (SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('homework_submission', 'pedagogy', '{lms.activity.manage,audit.log.read}');
