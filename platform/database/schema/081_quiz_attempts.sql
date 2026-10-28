-- Lot 5, quiz attempts (SRS 1.2, FR-LMS-03; SPECIFICATION_LOT5_PEDAGOGIE.md §4): a pupil of the audience
-- starts an attempt between the quiz's opening and their deadline; the server sets its end,
-- `min(start + duration, deadline)`, and the browser's clock only shows it. Answers are saved as they
-- go and refused after the end; a cut connection resumes the same attempt with its time left, never a
-- new one. The pupil submits it, or the server closes it at its end (a background run, and any read of
-- it). Starting and submitting are idempotent: the same key gives back the same attempt, another
-- content under a key is refused. Each attempt started counts against the quiz's maximum. Choice
-- questions are graded all or nothing, no negative points, nothing chosen is zero; an open question
-- waits for its teacher. Attempts name the version they used.

CREATE TABLE school.quiz_attempt (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  activity_id uuid NOT NULL,
  version_id uuid NOT NULL,
  student_id uuid NOT NULL,
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  number integer NOT NULL CHECK (number >= 1),
  start_key uuid NOT NULL,
  started_at timestamptz NOT NULL DEFAULT now(),
  ends_at timestamptz NOT NULL,
  status text NOT NULL DEFAULT 'in_progress' CHECK (status IN ('in_progress', 'submitted')),
  submitted_at timestamptz,
  submit_key uuid,
  closed_by text CHECK (closed_by IN ('pupil', 'timer')),
  max_points numeric(7, 2) NOT NULL,
  auto_points numeric(7, 2),
  pending_questions integer,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (ends_at > started_at),
  CHECK ((status = 'submitted') = (submitted_at IS NOT NULL AND closed_by IS NOT NULL AND auto_points IS NOT NULL AND pending_questions IS NOT NULL)),
  CHECK (submitted_at IS NULL OR submitted_at <= ends_at),
  UNIQUE (tenant_id, activity_id, student_id, number),
  UNIQUE (tenant_id, start_key),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, activity_id) REFERENCES school.activity (tenant_id, id),
  FOREIGN KEY (tenant_id, version_id) REFERENCES school.activity_version (tenant_id, id),
  FOREIGN KEY (tenant_id, student_id) REFERENCES school.student (tenant_id, id)
);
-- One attempt in progress at a time per pupil and quiz, even for two requests at once.
CREATE UNIQUE INDEX quiz_attempt_in_progress ON school.quiz_attempt (tenant_id, activity_id, student_id) WHERE status = 'in_progress';
CREATE INDEX quiz_attempt_expiring ON school.quiz_attempt (ends_at) WHERE status = 'in_progress';
CALL school.protect('school.quiz_attempt');

-- An attempt's answers, one per question of its version: the choices' ids, or an open answer's text;
-- the points the grading gave (none yet for an open answer waiting for its teacher).
CREATE TABLE school.quiz_answer (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  attempt_id uuid NOT NULL,
  question_id uuid NOT NULL,
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  choices jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(choices) = 'array'),
  answer_text text CHECK (length(answer_text) <= 20000),
  saved_at timestamptz NOT NULL DEFAULT now(),
  points numeric(7, 2) CHECK (points >= 0),
  PRIMARY KEY (tenant_id, attempt_id, question_id),
  FOREIGN KEY (tenant_id, attempt_id) REFERENCES school.quiz_attempt (tenant_id, id)
);
ALTER TABLE school.quiz_answer ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.quiz_answer FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.quiz_answer USING (tenant_id = (SELECT school.allowed_tenant())) WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON school.quiz_answer FOR EACH ROW EXECUTE FUNCTION school.guard_writable();

-- The caller's own pupil record, if any.
CREATE FUNCTION school.my_student() RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT s.id FROM school.student s WHERE s.tenant_id = school.allowed_tenant() AND s.user_id = school.actor() AND s.status = 'active' AND s.archived_at IS NULL $$;

-- Grades an attempt and closes it, at the moment given: each choice question all or nothing on the
-- version's answers (nothing chosen is zero), an open question unanswered zero, one answered waiting
-- for its teacher. With its owner's rights; called by the functions below only.
CREATE FUNCTION school.close_attempt(p_attempt uuid, p_by text, p_at timestamptz) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE a school.quiz_attempt; q jsonb; given jsonb; text_given text; correct jsonb; pts numeric; auto numeric := 0; pending integer := 0;
BEGIN
  SELECT * INTO a FROM school.quiz_attempt x WHERE x.id = p_attempt FOR UPDATE;
  IF a.id IS NULL OR a.status <> 'in_progress' THEN RETURN; END IF;
  FOR q IN SELECT x FROM school.activity_version v, jsonb_array_elements(v.questions) x WHERE v.tenant_id = a.tenant_id AND v.id = a.version_id LOOP
    SELECT w.choices, w.answer_text INTO given, text_given FROM school.quiz_answer w
    WHERE w.tenant_id = a.tenant_id AND w.attempt_id = a.id AND w.question_id = (q ->> 'id')::uuid;
    IF q ->> 'kind' = 'open' THEN
      pts := CASE WHEN nullif(btrim(text_given), '') IS NULL THEN 0 END;
      IF pts IS NULL THEN pending := pending + 1; END IF;
    ELSE
      SELECT coalesce(jsonb_agg(c ->> 'id' ORDER BY c ->> 'id'), '[]') INTO correct FROM jsonb_array_elements(q -> 'choices') c WHERE (c -> 'correct')::boolean;
      pts := CASE WHEN (SELECT coalesce(jsonb_agg(g ORDER BY g), '[]') FROM jsonb_array_elements_text(coalesce(given, '[]')) g) = correct
        THEN (q ->> 'points')::numeric ELSE 0 END;
    END IF;
    INSERT INTO school.quiz_answer (tenant_id, attempt_id, question_id, group_id, subject_id, points)
    VALUES (a.tenant_id, a.id, (q ->> 'id')::uuid, a.group_id, a.subject_id, pts)
    ON CONFLICT (tenant_id, attempt_id, question_id) DO UPDATE SET points = EXCLUDED.points;
    auto := auto + coalesce(pts, 0);
  END LOOP;
  UPDATE school.quiz_attempt SET status = 'submitted', submitted_at = least(p_at, a.ends_at), closed_by = p_by, auto_points = auto, pending_questions = pending
  WHERE tenant_id = a.tenant_id AND id = a.id;
END $$;

-- The caller's attempts of a quiz that ran out, closed by the timer at their end.
CREATE FUNCTION school.close_my_expired(p_activity uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE r record;
BEGIN
  FOR r IN SELECT x.id, x.ends_at FROM school.quiz_attempt x WHERE x.tenant_id = school.allowed_tenant() AND x.activity_id = p_activity
           AND x.student_id = school.my_student() AND x.status = 'in_progress' AND x.ends_at <= now() LOOP
    PERFORM school.close_attempt(r.id, 'timer', r.ends_at);
  END LOOP;
END $$;

-- FR-LMS-03: starts, or resumes, the caller's attempt of a quiz of theirs that is open, before their
-- deadline. The same key gives back the same attempt; an attempt in progress is resumed, never a
-- second one; each attempt started counts.
CREATE FUNCTION school.start_attempt(p_activity uuid, p_key uuid) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); me uuid := school.my_student(); r record; found_attempt school.quiz_attempt; n integer;
BEGIN
  IF p_key IS NULL THEN RAISE EXCEPTION 'idempotency_key_required' USING ERRCODE = '23514'; END IF;
  SELECT * INTO found_attempt FROM school.quiz_attempt x WHERE x.tenant_id = t AND x.start_key = p_key;
  IF found_attempt.id IS NOT NULL THEN
    IF (found_attempt.activity_id, found_attempt.student_id) IS DISTINCT FROM (p_activity, me) THEN
      RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514';
    END IF;
    RETURN found_attempt.id;
  END IF;
  SELECT * INTO r FROM school.my_activities() m WHERE m.activity_id = p_activity AND m.kind = 'quiz';
  IF r.activity_id IS NULL OR me IS NULL THEN RAISE EXCEPTION 'lms_access_denied' USING ERRCODE = '42501'; END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended('quiz_attempt:' || p_activity::text || ':' || me::text, 81));
  PERFORM school.close_my_expired(p_activity);
  SELECT * INTO found_attempt FROM school.quiz_attempt x WHERE x.tenant_id = t AND x.activity_id = p_activity AND x.student_id = me AND x.status = 'in_progress';
  IF found_attempt.id IS NOT NULL THEN RETURN found_attempt.id; END IF;
  IF now() < r.opens_at THEN RAISE EXCEPTION 'lms_activity_not_open' USING ERRCODE = '23514'; END IF;
  IF now() >= r.deadline_at THEN RAISE EXCEPTION 'lms_deadline_passed' USING ERRCODE = '23514'; END IF;
  SELECT count(*) INTO n FROM school.quiz_attempt x WHERE x.tenant_id = t AND x.activity_id = p_activity AND x.student_id = me;
  IF n >= r.max_attempts THEN RAISE EXCEPTION 'lms_attempts_exhausted' USING ERRCODE = '23514'; END IF;
  INSERT INTO school.quiz_attempt (tenant_id, activity_id, version_id, student_id, group_id, subject_id, number, start_key, ends_at, max_points)
  VALUES (t, p_activity, r.version_id, me, r.group_id, r.subject_id, n + 1, p_key, least(now() + make_interval(mins => r.duration_minutes), r.deadline_at),
    r.max_points)
  RETURNING id INTO found_attempt.id;
  RETURN found_attempt.id;
END $$;

-- An answer saved while the caller's attempt runs, on a question of its version: chosen ids of its
-- choices (one at most for a single choice), or an open answer's text. Refused after the end, which
-- the server alone decides, and once the access is withdrawn.
CREATE FUNCTION school.save_answer(p_attempt uuid, p_question uuid, p_choices jsonb, p_text text) RETURNS timestamptz
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); a school.quiz_attempt; q jsonb; picked jsonb := coalesce(p_choices, '[]');
BEGIN
  SELECT * INTO a FROM school.quiz_attempt x WHERE x.tenant_id = t AND x.id = p_attempt AND x.student_id = school.my_student() FOR UPDATE;
  IF a.id IS NULL OR NOT EXISTS (SELECT 1 FROM school.my_activities() m WHERE m.activity_id = a.activity_id) THEN
    RAISE EXCEPTION 'lms_access_denied' USING ERRCODE = '42501';
  END IF;
  IF a.status <> 'in_progress' THEN RAISE EXCEPTION 'lms_attempt_submitted' USING ERRCODE = '23514'; END IF;
  IF now() >= a.ends_at THEN RAISE EXCEPTION 'lms_attempt_expired' USING ERRCODE = '23514'; END IF;
  SELECT x INTO q FROM school.activity_version v, jsonb_array_elements(v.questions) x WHERE v.tenant_id = t AND v.id = a.version_id AND x ->> 'id' = p_question::text;
  IF q IS NULL THEN RAISE EXCEPTION 'lms_answer_invalid' USING ERRCODE = '23514'; END IF;
  IF jsonb_typeof(picked) <> 'array'
     OR (q ->> 'kind' = 'open' AND (jsonb_array_length(picked) > 0 OR length(p_text) > 20000))
     OR (q ->> 'kind' <> 'open' AND p_text IS NOT NULL)
     OR (q ->> 'kind' = 'single' AND jsonb_array_length(picked) > 1)
     OR EXISTS (SELECT 1 FROM jsonb_array_elements(picked) c WHERE jsonb_typeof(c) <> 'string'
       OR NOT EXISTS (SELECT 1 FROM jsonb_array_elements(q -> 'choices') k WHERE k ->> 'id' = c #>> '{}'))
     OR (SELECT count(DISTINCT c) FROM jsonb_array_elements(picked) c) <> jsonb_array_length(picked) THEN
    RAISE EXCEPTION 'lms_answer_invalid' USING ERRCODE = '23514';
  END IF;
  INSERT INTO school.quiz_answer (tenant_id, attempt_id, question_id, group_id, subject_id, choices, answer_text, saved_at)
  VALUES (t, a.id, p_question, a.group_id, a.subject_id, picked, p_text, now())
  ON CONFLICT (tenant_id, attempt_id, question_id) DO UPDATE SET choices = EXCLUDED.choices, answer_text = EXCLUDED.answer_text, saved_at = EXCLUDED.saved_at;
  RETURN now();
END $$;

-- The caller submits their attempt: graded and closed; after its end, closed by the timer at its
-- end with the answers saved before. Again with the same key, or after the timer, the attempt as it
-- is; another key once submitted is refused.
CREATE FUNCTION school.submit_attempt(p_attempt uuid, p_key uuid) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); a school.quiz_attempt;
BEGIN
  IF p_key IS NULL THEN RAISE EXCEPTION 'idempotency_key_required' USING ERRCODE = '23514'; END IF;
  SELECT * INTO a FROM school.quiz_attempt x WHERE x.tenant_id = t AND x.id = p_attempt AND x.student_id = school.my_student() FOR UPDATE;
  IF a.id IS NULL THEN RAISE EXCEPTION 'lms_access_denied' USING ERRCODE = '42501'; END IF;
  IF a.status = 'submitted' THEN
    IF a.submit_key IS NOT NULL AND a.submit_key <> p_key THEN RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514'; END IF;
    RETURN a.id;
  END IF;
  IF now() >= a.ends_at THEN
    PERFORM school.close_attempt(a.id, 'timer', a.ends_at);
  ELSE
    PERFORM school.close_attempt(a.id, 'pupil', now());
    UPDATE school.quiz_attempt SET submit_key = p_key WHERE tenant_id = t AND id = a.id;
  END IF;
  RETURN a.id;
END $$;

-- The background run (every minute): every attempt past its end, in schools in use, closed by the
-- timer at its end. With its owner's rights; the application calls it without a school.
CREATE FUNCTION platform.close_expired_attempts(p_limit integer) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE r record; n integer := 0;
BEGIN
  FOR r IN SELECT x.id, x.ends_at FROM school.quiz_attempt x
           WHERE x.status = 'in_progress' AND x.ends_at <= now() AND platform.tenant_mode(x.tenant_id) = 'active'
           ORDER BY x.ends_at LIMIT p_limit FOR UPDATE SKIP LOCKED LOOP
    PERFORM school.close_attempt(r.id, 'timer', r.ends_at);
    n := n + 1;
  END LOOP;
  RETURN n;
END $$;

-- The caller's attempts of a quiz, closing those that ran out first: their number, state and times,
-- never their points (the results' visibility comes with the correction).
CREATE FUNCTION school.my_attempts(p_activity uuid) RETURNS TABLE (id uuid, number integer, status text, started_at timestamptz, ends_at timestamptz,
  submitted_at timestamptz, closed_by text, version_id uuid)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
BEGIN
  PERFORM school.close_my_expired(p_activity);
  RETURN QUERY SELECT x.id, x.number, x.status, x.started_at, x.ends_at, x.submitted_at, x.closed_by, x.version_id FROM school.quiz_attempt x
    WHERE x.tenant_id = school.allowed_tenant() AND x.activity_id = p_activity AND x.student_id = school.my_student() ORDER BY x.number;
END $$;

-- The caller's attempt in progress as they take it: its questions without their answers, the
-- answers saved, the seconds left by the server's clock. Nothing once the access is withdrawn; an
-- attempt that ran out is closed first and shows no question any more.
CREATE FUNCTION school.my_attempt(p_attempt uuid) RETURNS TABLE (id uuid, activity_id uuid, number integer, status text, started_at timestamptz,
  ends_at timestamptz, seconds_left integer, questions jsonb, answers jsonb)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant(); a school.quiz_attempt;
BEGIN
  SELECT * INTO a FROM school.quiz_attempt x WHERE x.tenant_id = t AND x.id = p_attempt AND x.student_id = school.my_student();
  IF a.id IS NULL OR NOT EXISTS (SELECT 1 FROM school.my_activities() m WHERE m.activity_id = a.activity_id) THEN RETURN; END IF;
  PERFORM school.close_my_expired(a.activity_id);
  SELECT * INTO a FROM school.quiz_attempt x WHERE x.tenant_id = t AND x.id = p_attempt;
  RETURN QUERY SELECT a.id, a.activity_id, a.number, a.status, a.started_at, a.ends_at,
    greatest(0, ceil(extract(epoch FROM a.ends_at - now())))::integer,
    CASE WHEN a.status = 'in_progress' THEN (SELECT jsonb_agg(jsonb_build_object('id', q -> 'id', 'position', q -> 'position', 'kind', q -> 'kind', 'prompt', q -> 'prompt',
      'points', q -> 'points', 'choices', (SELECT coalesce(jsonb_agg(c - 'correct'), '[]') FROM jsonb_array_elements(q -> 'choices') c)) ORDER BY (q ->> 'position')::integer)
      FROM school.activity_version v, jsonb_array_elements(v.questions) q WHERE v.tenant_id = t AND v.id = a.version_id) END,
    (SELECT coalesce(jsonb_agg(jsonb_build_object('question_id', w.question_id, 'choices', w.choices, 'answer_text', w.answer_text, 'saved_at', w.saved_at)), '[]')
      FROM school.quiz_answer w WHERE w.tenant_id = t AND w.attempt_id = a.id AND (w.choices <> '[]' OR w.answer_text IS NOT NULL));
END $$;

-- Read by the pedagogy manager and the teachers of the group's subject today; the pupil through
-- the functions above; written by those functions alone.
CREATE POLICY resource_reader ON school.quiz_attempt AS RESTRICTIVE FOR ALL TO school_runtime USING (
  (SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]));
CREATE POLICY resource_reader ON school.quiz_answer AS RESTRICTIVE FOR ALL TO school_runtime USING (
  (SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('quiz_attempt', 'pedagogy', '{lms.activity.manage,audit.log.read}');
