-- Lot 5, correction and results (SRS 1.2, FR-LMS-02, FR-LMS-03, FR-LMS-04; SPECIFICATION_LOT5_PEDAGOGIE.md
-- §4 to §6): each pupil's result for an activity, from their attempts (the activity's selection:
-- the latest, the best ratio, the average of ratios) or their homework's version to correct, once
-- every open answer is corrected; raw points and the maximum kept exact. A missing work is
-- `not_submitted`, never a zero unless the teacher says so with a reason; an excused absence is
-- neither a mark nor a zero. A result is shown to the pupil only once published: at once when
-- complete (`immediate`), after the deadline (`after_deadline`), or when the teacher publishes it;
-- each publication a version kept, the pupil reading the last one published until the next. The
-- answer key is shown on its own rule, never during an attempt, and with several attempts not
-- before the last one allowed or the quiz's close. A re-correction is the pedagogy manager's, with
-- its reason. The pupil is told a result is published, never the mark in the message.

INSERT INTO platform.notification_event (code, labels, email_default) VALUES
  ('lms.result_published', '{"fr": "Un résultat a été publié", "ar": "نُشرت نتيجة"}', true);

-- The answer key published by the teacher, when the activity says so.
ALTER TABLE school.activity ADD COLUMN key_published_at timestamptz;
-- The teacher's correction of an open answer: who, when, a comment for the pupil.
ALTER TABLE school.quiz_answer ADD COLUMN corrected_by bigint REFERENCES public.users(id), ADD COLUMN corrected_at timestamptz,
  ADD COLUMN comment text CHECK (length(comment) <= 2000);

-- A pupil's result for an activity: its working state and points, the teacher's decisions and
-- feedback, and what was last published. Kept by the database from the attempts, submissions and
-- corrections; every change journaled with its old and new values.
CREATE TABLE school.activity_mark (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  activity_id uuid NOT NULL,
  student_id uuid NOT NULL,
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  status text NOT NULL CHECK (status IN ('not_submitted', 'absent_excused', 'to_grade', 'graded', 'published')),
  points numeric(7, 2) CHECK (points >= 0),
  max_points numeric(7, 2) NOT NULL CHECK (max_points > 0),
  coefficient numeric(5, 2) NOT NULL CHECK (coefficient > 0),
  version_id uuid,
  late boolean NOT NULL DEFAULT false,
  decision text CHECK (decision IN ('zero_missing', 'excused')),
  decision_reason text CHECK (length(decision_reason) BETWEEN 3 AND 500),
  manual_points numeric(7, 2) CHECK (manual_points >= 0),
  graded_number integer,
  feedback text CHECK (length(feedback) <= 4000),
  published_status text CHECK (published_status IN ('absent_excused', 'graded')),
  published_points numeric(7, 2),
  published_feedback text,
  published_at timestamptz,
  publications integer NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (points IS NULL OR points <= max_points),
  CHECK ((decision IS NULL) = (decision_reason IS NULL)),
  CHECK ((status IN ('graded', 'published') AND points IS NOT NULL) OR (status NOT IN ('graded', 'published'))),
  UNIQUE (tenant_id, activity_id, student_id),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, activity_id) REFERENCES school.activity (tenant_id, id),
  FOREIGN KEY (tenant_id, student_id) REFERENCES school.student (tenant_id, id),
  FOREIGN KEY (tenant_id, version_id) REFERENCES school.activity_version (tenant_id, id)
);
CALL school.protect('school.activity_mark');

-- Each publication of a result, never changed: what the pupil was shown, by whom, why when it
-- changed a result already published.
CREATE TABLE school.activity_mark_publication (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  mark_id uuid NOT NULL,
  activity_id uuid NOT NULL,
  student_id uuid NOT NULL,
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  number integer NOT NULL CHECK (number >= 1),
  status text NOT NULL,
  points numeric(7, 2),
  max_points numeric(7, 2) NOT NULL,
  coefficient numeric(5, 2) NOT NULL,
  feedback text,
  reason text,
  published_at timestamptz NOT NULL DEFAULT now(),
  published_by bigint REFERENCES public.users(id),
  UNIQUE (tenant_id, mark_id, number),
  FOREIGN KEY (tenant_id, mark_id) REFERENCES school.activity_mark (tenant_id, id)
);
ALTER TABLE school.activity_mark_publication ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.activity_mark_publication FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.activity_mark_publication USING (tenant_id = (SELECT school.allowed_tenant())) WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON school.activity_mark_publication FOR EACH ROW EXECUTE FUNCTION school.guard_writable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.activity_mark_publication FOR EACH ROW EXECUTE FUNCTION school.immutable();

-- A pupil's deadline for an activity: their own if extended, the last version's otherwise.
CREATE FUNCTION school.pupil_deadline(p_tenant uuid, p_activity uuid, p_student uuid) RETURNS timestamptz
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT coalesce((SELECT au.deadline_at FROM school.activity_audience au WHERE au.tenant_id = p_tenant AND au.activity_id = p_activity AND au.student_id = p_student),
    (SELECT v.deadline_at FROM school.activity a JOIN school.activity_version v ON v.tenant_id = a.tenant_id AND v.activity_id = a.id AND v.version = a.published_version
     WHERE a.tenant_id = p_tenant AND a.id = p_activity)) $$;

-- Tells a pupil one of their results is published: the activity only, never the mark (lot 5 §6).
-- Also from the background run, outside any school's request.
CREATE FUNCTION school.tell_result(p_tenant uuid, p_student uuid, p_activity uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE recipient bigint; note uuid;
BEGIN
  SELECT s.user_id INTO recipient FROM school.student s JOIN school.membership m ON m.tenant_id = s.tenant_id AND m.user_id = s.user_id AND m.status = 'active'
  WHERE s.tenant_id = p_tenant AND s.id = p_student;
  IF recipient IS NULL THEN RETURN; END IF;
  INSERT INTO school.notification (tenant_id, user_id, event, data) VALUES (p_tenant, recipient, 'lms.result_published', jsonb_build_object('activity_id', p_activity))
  RETURNING id INTO note;
  INSERT INTO school.outbox (tenant_id, notification_id, user_id, event)
  SELECT p_tenant, note, recipient, e.code FROM platform.notification_event e JOIN public.users u ON u.id = recipient AND u.email IS NOT NULL
  LEFT JOIN school.notification_preference p ON p.tenant_id = p_tenant AND p.user_id = recipient AND p.event = e.code
  WHERE e.code = 'lms.result_published' AND coalesce(p.email, e.email_default);
END $$;

-- Publishes a result as it stands, as a new version of what the pupil reads, and tells them.
CREATE FUNCTION school.publish_mark(p_mark uuid, p_reason text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE m school.activity_mark;
BEGIN
  SELECT * INTO m FROM school.activity_mark x WHERE x.id = p_mark FOR UPDATE;
  IF m.status NOT IN ('graded', 'absent_excused') THEN RETURN; END IF;
  INSERT INTO school.activity_mark_publication (tenant_id, mark_id, activity_id, student_id, group_id, subject_id, number, status, points, max_points,
    coefficient, feedback, reason, published_by)
  VALUES (m.tenant_id, m.id, m.activity_id, m.student_id, m.group_id, m.subject_id, m.publications + 1, m.status, m.points, m.max_points, m.coefficient,
    m.feedback, nullif(btrim(p_reason), ''), school.actor());
  UPDATE school.activity_mark SET published_status = CASE WHEN m.status = 'absent_excused' THEN 'absent_excused' ELSE 'graded' END,
    published_points = m.points, published_feedback = m.feedback, published_at = now(), publications = m.publications + 1,
    status = CASE WHEN m.status = 'graded' THEN 'published' ELSE m.status END
  WHERE tenant_id = m.tenant_id AND id = m.id;
  PERFORM school.tell_result(m.tenant_id, m.student_id, m.activity_id);
END $$;

-- Recomputes a pupil's result from their work and the teacher's decisions, and publishes it when
-- the activity's rule says so: complete and `immediate`, or complete and `after_deadline` once the
-- pupil's deadline passed. A result already published and changed is published again only so; with
-- `on_teacher_publish`, it waits, the last version published staying the pupil's.
CREATE FUNCTION school.refresh_mark(p_tenant uuid, p_activity uuid, p_student uuid, p_reason text DEFAULT NULL) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE a school.activity; v school.activity_version; m school.activity_mark; v_status text; v_points numeric; v_version uuid; v_late boolean := false;
  v_number integer; deadline timestamptz; pending boolean; last_sub school.homework_submission; v_max numeric;
BEGIN
  SELECT * INTO a FROM school.activity x WHERE x.tenant_id = p_tenant AND x.id = p_activity;
  SELECT * INTO v FROM school.activity_version x WHERE x.tenant_id = p_tenant AND x.activity_id = p_activity AND x.version = a.published_version;
  IF v.id IS NULL THEN RETURN; END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended('activity_mark:' || p_activity::text || ':' || p_student::text, 83));
  SELECT * INTO m FROM school.activity_mark x WHERE x.tenant_id = p_tenant AND x.activity_id = p_activity AND x.student_id = p_student FOR UPDATE;
  deadline := school.pupil_deadline(p_tenant, p_activity, p_student);
  v_max := v.max_points;
  IF a.kind = 'quiz' THEN
    SELECT bool_or(x.pending_questions > 0) FILTER (WHERE v.selection <> 'latest' OR x.number = (SELECT max(y.number) FROM school.quiz_attempt y
      WHERE y.tenant_id = p_tenant AND y.activity_id = p_activity AND y.student_id = p_student AND y.status = 'submitted')),
      count(*) > 0
      INTO pending, v_late FROM school.quiz_attempt x WHERE x.tenant_id = p_tenant AND x.activity_id = p_activity AND x.student_id = p_student AND x.status = 'submitted';
    IF NOT v_late THEN
      v_status := 'not_submitted';
    ELSIF pending THEN
      v_status := 'to_grade';
    ELSE
      v_status := 'graded';
      IF v.selection = 'average' THEN
        SELECT round(avg(x.auto_points / x.max_points) * v.max_points, 2), max(x.version_id::text)::uuid INTO v_points, v_version FROM school.quiz_attempt x
        WHERE x.tenant_id = p_tenant AND x.activity_id = p_activity AND x.student_id = p_student AND x.status = 'submitted';
      ELSE
        SELECT x.auto_points, x.max_points, x.version_id, x.number INTO v_points, v_max, v_version, v_number FROM school.quiz_attempt x
        WHERE x.tenant_id = p_tenant AND x.activity_id = p_activity AND x.student_id = p_student AND x.status = 'submitted'
        ORDER BY CASE WHEN v.selection = 'best' THEN x.auto_points / x.max_points END DESC NULLS LAST, x.number DESC LIMIT 1;
      END IF;
    END IF;
    v_late := false;
  ELSE
    SELECT * INTO last_sub FROM school.homework_submission x WHERE x.tenant_id = p_tenant AND x.activity_id = p_activity AND x.student_id = p_student
      ORDER BY x.number DESC LIMIT 1;
    IF last_sub.id IS NULL THEN
      v_status := 'not_submitted';
    ELSE
      v_late := last_sub.late; v_version := last_sub.version_id;
      -- A grade is for the version it was given on; a newer one waits for its own.
      IF m.manual_points IS NOT NULL AND m.graded_number = last_sub.number THEN
        v_status := 'graded'; v_points := m.manual_points;
      ELSE
        v_status := 'to_grade';
      END IF;
    END IF;
  END IF;
  -- The teacher's decisions: an excused absence prevails; a zero for a missing work only while nothing was handed in.
  IF m.decision = 'excused' THEN v_status := 'absent_excused'; v_points := NULL;
  ELSIF m.decision = 'zero_missing' AND v_status = 'not_submitted' THEN v_status := 'graded'; v_points := 0; v_max := v.max_points;
  END IF;
  IF m.id IS NULL THEN
    IF v_status = 'not_submitted' THEN RETURN; END IF;
    INSERT INTO school.activity_mark (tenant_id, activity_id, student_id, group_id, subject_id, status, points, max_points, coefficient, version_id, late)
    VALUES (p_tenant, p_activity, p_student, a.group_id, a.subject_id, v_status, v_points, v_max, v.coefficient, v_version, v_late)
    RETURNING * INTO m;
  ELSE
    -- What the pupil was last shown stays theirs: the working copy says whether it is still what they read.
    IF v_status = 'graded' AND m.publications > 0 AND m.published_status = 'graded' AND m.published_points = v_points
       AND m.published_feedback IS NOT DISTINCT FROM m.feedback THEN
      v_status := 'published';
    END IF;
    UPDATE school.activity_mark SET status = v_status, points = v_points, max_points = v_max, coefficient = v.coefficient, version_id = v_version, late = v_late
    WHERE tenant_id = m.tenant_id AND id = m.id RETURNING * INTO m;
  END IF;
  IF (m.status = 'graded' OR (m.status = 'absent_excused' AND m.published_status IS DISTINCT FROM 'absent_excused'))
     AND (v.results_visibility = 'immediate' OR (v.results_visibility = 'after_deadline' AND now() >= deadline)) THEN
    PERFORM school.publish_mark(m.id, p_reason);
  END IF;
END $$;

-- A pupil's result is recomputed whenever their attempt closes or their homework gets a version.
CREATE FUNCTION school.follow_work() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  PERFORM school.refresh_mark(NEW.tenant_id, NEW.activity_id, NEW.student_id);
  RETURN NULL;
END $$;
CREATE TRIGGER follow_work AFTER UPDATE OF status ON school.quiz_attempt FOR EACH ROW WHEN (NEW.status = 'submitted' AND OLD.status <> 'submitted')
  EXECUTE FUNCTION school.follow_work();
CREATE TRIGGER follow_work AFTER INSERT ON school.homework_submission FOR EACH ROW EXECUTE FUNCTION school.follow_work();

-- The teacher corrects an open answer of a submitted attempt: points between zero and the
-- question's, a comment for the pupil; the attempt's points and the result follow.
CREATE FUNCTION school.correct_answer(p_attempt uuid, p_question uuid, p_points numeric, p_comment text DEFAULT NULL) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); x school.quiz_attempt; q jsonb;
BEGIN
  SELECT * INTO x FROM school.quiz_attempt y WHERE y.tenant_id = t AND y.id = p_attempt FOR UPDATE;
  IF x.id IS NULL OR NOT school.manages_activity(x.group_id, x.subject_id) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  IF x.status <> 'submitted' THEN RAISE EXCEPTION 'lms_attempt_in_progress' USING ERRCODE = '23514'; END IF;
  SELECT e INTO q FROM school.activity_version v, jsonb_array_elements(v.questions) e WHERE v.tenant_id = t AND v.id = x.version_id AND e ->> 'id' = p_question::text;
  IF q IS NULL OR q ->> 'kind' <> 'open' THEN RAISE EXCEPTION 'lms_answer_invalid' USING ERRCODE = '23514'; END IF;
  IF p_points IS NULL OR p_points < 0 OR p_points > (q ->> 'points')::numeric OR p_points <> round(p_points, 2) THEN
    RAISE EXCEPTION 'lms_points_out_of_range' USING ERRCODE = '23514';
  END IF;
  INSERT INTO school.quiz_answer (tenant_id, attempt_id, question_id, group_id, subject_id, points, corrected_by, corrected_at, comment)
  VALUES (t, x.id, p_question, x.group_id, x.subject_id, p_points, school.actor(), now(), nullif(btrim(p_comment), ''))
  ON CONFLICT (tenant_id, attempt_id, question_id) DO UPDATE SET points = EXCLUDED.points, corrected_by = EXCLUDED.corrected_by,
    corrected_at = EXCLUDED.corrected_at, comment = EXCLUDED.comment;
  UPDATE school.quiz_attempt SET auto_points = (SELECT coalesce(sum(w.points), 0) FROM school.quiz_answer w WHERE w.tenant_id = t AND w.attempt_id = x.id),
    pending_questions = (SELECT count(*) FROM school.activity_version v, jsonb_array_elements(v.questions) e
      WHERE v.tenant_id = t AND v.id = x.version_id AND e ->> 'kind' = 'open'
        AND NOT EXISTS (SELECT 1 FROM school.quiz_answer w WHERE w.tenant_id = t AND w.attempt_id = x.id AND w.question_id = (e ->> 'id')::uuid AND w.points IS NOT NULL))
  WHERE tenant_id = t AND id = x.id;
  PERFORM school.refresh_mark(t, x.activity_id, x.student_id);
END $$;

-- The teacher's mark of a homework, on the version to correct, from zero to its maximum, with feedback.
CREATE FUNCTION school.grade_homework(p_activity uuid, p_student uuid, p_points numeric, p_feedback text DEFAULT NULL) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); a school.activity; v school.activity_version; n integer;
BEGIN
  SELECT * INTO a FROM school.activity x WHERE x.tenant_id = t AND x.id = p_activity;
  IF a.id IS NULL OR NOT school.manages_activity(a.group_id, a.subject_id) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  IF a.kind <> 'homework' THEN RAISE EXCEPTION 'lms_answer_invalid' USING ERRCODE = '23514'; END IF;
  SELECT * INTO v FROM school.activity_version x WHERE x.tenant_id = t AND x.activity_id = a.id AND x.version = a.published_version;
  SELECT max(x.number) INTO n FROM school.homework_submission x WHERE x.tenant_id = t AND x.activity_id = a.id AND x.student_id = p_student;
  IF n IS NULL THEN RAISE EXCEPTION 'lms_nothing_submitted' USING ERRCODE = '23514'; END IF;
  IF p_points IS NULL OR p_points < 0 OR p_points > v.max_points OR p_points <> round(p_points, 2) THEN RAISE EXCEPTION 'lms_points_out_of_range' USING ERRCODE = '23514'; END IF;
  PERFORM school.refresh_mark(t, a.id, p_student);
  UPDATE school.activity_mark SET manual_points = p_points, graded_number = n, feedback = nullif(btrim(p_feedback), '')
  WHERE tenant_id = t AND activity_id = a.id AND student_id = p_student;
  PERFORM school.refresh_mark(t, a.id, p_student);
END $$;

-- The teacher's decision for a pupil, with its reason: a zero for a missing work, once the deadline
-- passed and nothing handed in; an excused absence, neither a mark nor a zero; or none any more.
CREATE FUNCTION school.decide_mark(p_activity uuid, p_student uuid, p_decision text, p_reason text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); a school.activity; v_reason text := nullif(btrim(p_reason), '');
BEGIN
  SELECT * INTO a FROM school.activity x WHERE x.tenant_id = t AND x.id = p_activity;
  IF a.id IS NULL OR NOT school.manages_activity(a.group_id, a.subject_id) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  IF p_decision IS NOT NULL AND p_decision NOT IN ('zero_missing', 'excused') THEN RAISE EXCEPTION 'status_invalid' USING ERRCODE = '23514'; END IF;
  IF p_decision IS NOT NULL AND (v_reason IS NULL OR length(v_reason) < 3) THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  IF NOT EXISTS (SELECT 1 FROM school.activity_audience x WHERE x.tenant_id = t AND x.activity_id = a.id AND x.student_id = p_student) THEN
    RAISE EXCEPTION 'lms_audience_not_member' USING ERRCODE = '23514';
  END IF;
  IF p_decision = 'zero_missing' THEN
    IF now() < school.pupil_deadline(t, a.id, p_student) THEN RAISE EXCEPTION 'lms_deadline_not_passed' USING ERRCODE = '23514'; END IF;
    IF EXISTS (SELECT 1 FROM school.quiz_attempt x WHERE x.tenant_id = t AND x.activity_id = a.id AND x.student_id = p_student)
       OR EXISTS (SELECT 1 FROM school.homework_submission x WHERE x.tenant_id = t AND x.activity_id = a.id AND x.student_id = p_student) THEN
      RAISE EXCEPTION 'lms_work_submitted' USING ERRCODE = '23514';
    END IF;
  END IF;
  INSERT INTO school.activity_mark (tenant_id, activity_id, student_id, group_id, subject_id, status, max_points, coefficient, decision, decision_reason)
  SELECT t, a.id, p_student, a.group_id, a.subject_id, 'not_submitted', v.max_points, v.coefficient, p_decision, CASE WHEN p_decision IS NOT NULL THEN v_reason END
  FROM school.activity_version v WHERE v.tenant_id = t AND v.activity_id = a.id AND v.version = a.published_version
  ON CONFLICT (tenant_id, activity_id, student_id) DO UPDATE SET decision = EXCLUDED.decision, decision_reason = EXCLUDED.decision_reason;
  PERFORM school.refresh_mark(t, a.id, p_student);
END $$;

-- The teacher publishes the results complete, of the pupils given or all of them; a result already
-- published and changed since needs the change's reason. Returns how many were published.
CREATE FUNCTION school.publish_marks(p_activity uuid, p_students uuid[] DEFAULT NULL, p_reason text DEFAULT NULL) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); a school.activity; m record; n integer := 0;
BEGIN
  SELECT * INTO a FROM school.activity x WHERE x.tenant_id = t AND x.id = p_activity;
  IF a.id IS NULL OR NOT school.manages_activity(a.group_id, a.subject_id) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  FOR m IN SELECT x.id, x.publications FROM school.activity_mark x WHERE x.tenant_id = t AND x.activity_id = a.id
           AND (p_students IS NULL OR x.student_id = ANY (p_students))
           AND (x.status = 'graded' OR (x.status = 'absent_excused' AND x.published_status IS DISTINCT FROM 'absent_excused')) LOOP
    IF m.publications > 0 AND length(btrim(coalesce(p_reason, ''))) < 3 THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    PERFORM school.publish_mark(m.id, p_reason);
    n := n + 1;
  END LOOP;
  RETURN n;
END $$;

-- The teacher publishes the answer key, when the activity says the teacher does.
CREATE FUNCTION school.publish_key(p_activity uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); a school.activity;
BEGIN
  SELECT * INTO a FROM school.activity x WHERE x.tenant_id = t AND x.id = p_activity FOR UPDATE;
  IF a.id IS NULL OR NOT school.manages_activity(a.group_id, a.subject_id) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  IF a.published_version IS NULL THEN RAISE EXCEPTION 'lms_activity_draft' USING ERRCODE = '23514'; END IF;
  UPDATE school.activity SET key_published_at = coalesce(key_published_at, now()) WHERE tenant_id = t AND id = a.id;
END $$;

-- A re-correction, by the pedagogy manager alone, with its reason: the choice answers of every
-- attempt graded again on the activity's last version (a question or a key corrected), the results
-- recomputed, the old and new values journaled, each published again as the activity's rule says.
CREATE FUNCTION school.regrade_activity(p_activity uuid, p_reason text) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); a school.activity; v school.activity_version; x record; n integer := 0;
BEGIN
  IF NOT school.can_everywhere('lms.activity.manage') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF length(btrim(coalesce(p_reason, ''))) < 3 THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  SELECT * INTO a FROM school.activity y WHERE y.tenant_id = t AND y.id = p_activity;
  SELECT * INTO v FROM school.activity_version y WHERE y.tenant_id = t AND y.activity_id = a.id AND y.version = a.published_version;
  IF v.id IS NULL OR a.kind <> 'quiz' THEN RAISE EXCEPTION 'lms_activity_draft' USING ERRCODE = '23514'; END IF;
  PERFORM set_config('app.reason', btrim(p_reason), true);
  UPDATE school.quiz_answer w SET points = CASE WHEN (SELECT coalesce(jsonb_agg(g ORDER BY g), '[]') FROM jsonb_array_elements_text(w.choices) g)
      = (SELECT coalesce(jsonb_agg(c ->> 'id' ORDER BY c ->> 'id'), '[]') FROM jsonb_array_elements(q -> 'choices') c WHERE (c -> 'correct')::boolean)
      THEN (q ->> 'points')::numeric ELSE 0 END
  FROM school.quiz_attempt y, jsonb_array_elements(v.questions) q
  WHERE y.tenant_id = t AND y.activity_id = a.id AND y.status = 'submitted' AND w.tenant_id = t AND w.attempt_id = y.id
    AND w.question_id = (q ->> 'id')::uuid AND q ->> 'kind' <> 'open';
  FOR x IN SELECT y.id, y.student_id FROM school.quiz_attempt y WHERE y.tenant_id = t AND y.activity_id = a.id AND y.status = 'submitted' LOOP
    UPDATE school.quiz_attempt SET auto_points = (SELECT coalesce(sum(w.points), 0) FROM school.quiz_answer w WHERE w.tenant_id = t AND w.attempt_id = x.id)
    WHERE tenant_id = t AND id = x.id;
  END LOOP;
  FOR x IN SELECT DISTINCT y.student_id FROM school.quiz_attempt y WHERE y.tenant_id = t AND y.activity_id = a.id AND y.status = 'submitted' LOOP
    PERFORM school.refresh_mark(t, a.id, x.student_id, btrim(p_reason));
    n := n + 1;
  END LOOP;
  RETURN n;
END $$;

-- The background run: results complete whose activity shows them after the deadline, once the
-- pupil's deadline passed, published in schools in use.
CREATE FUNCTION platform.publish_due_marks(p_limit integer) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE r record; n integer := 0;
BEGIN
  FOR r IN SELECT m.id FROM school.activity_mark m
           JOIN school.activity a ON a.tenant_id = m.tenant_id AND a.id = m.activity_id
           JOIN school.activity_version v ON v.tenant_id = a.tenant_id AND v.activity_id = a.id AND v.version = a.published_version
           WHERE v.results_visibility = 'after_deadline' AND platform.tenant_mode(m.tenant_id) = 'active'
             AND (m.status = 'graded' OR (m.status = 'absent_excused' AND m.published_status IS DISTINCT FROM 'absent_excused'))
             AND now() >= school.pupil_deadline(m.tenant_id, m.activity_id, m.student_id)
           ORDER BY m.updated_at LIMIT p_limit FOR UPDATE OF m SKIP LOCKED LOOP
    PERFORM school.publish_mark(r.id, NULL);
    n := n + 1;
  END LOOP;
  RETURN n;
END $$;

-- What the teacher reads of an activity's results: each pupil of the audience, the state computed
-- (`not_submitted` for one without any result yet), the points, the decisions, what is published.
CREATE FUNCTION school.activity_results(p_activity uuid) RETURNS TABLE (student_id uuid, status text, points numeric, max_points numeric, coefficient numeric,
  late boolean, decision text, decision_reason text, feedback text, published_status text, published_points numeric, published_at timestamptz, publications integer,
  mark_id uuid)
LANGUAGE sql STABLE AS $$
  SELECT au.student_id, coalesce(m.status, 'not_submitted'), m.points, coalesce(m.max_points, v.max_points), coalesce(m.coefficient, v.coefficient), coalesce(m.late, false),
    m.decision, m.decision_reason, m.feedback, m.published_status, m.published_points, m.published_at, coalesce(m.publications, 0), m.id
  FROM school.activity_audience au
  JOIN school.activity a ON a.tenant_id = au.tenant_id AND a.id = au.activity_id
  JOIN school.activity_version v ON v.tenant_id = a.tenant_id AND v.activity_id = a.id AND v.version = a.published_version
  LEFT JOIN school.activity_mark m ON m.tenant_id = au.tenant_id AND m.activity_id = au.activity_id AND m.student_id = au.student_id
  WHERE au.tenant_id = school.allowed_tenant() AND au.activity_id = p_activity $$;

-- The caller's results: what was last published of each of their activities, even once their access
-- is withdrawn, as long as their account is (lot 5 §1); never another pupil's, never what is not
-- published.
CREATE FUNCTION school.my_results() RETURNS TABLE (activity_id uuid, title text, kind text, subject_id uuid, status text, points numeric, max_points numeric,
  coefficient numeric, feedback text, published_at timestamptz, late boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT a.id, a.title, a.kind, a.subject_id, m.published_status, m.published_points, p.max_points, p.coefficient, m.published_feedback, m.published_at, m.late
  FROM school.activity_mark m
  JOIN school.activity a ON a.tenant_id = m.tenant_id AND a.id = m.activity_id
  JOIN school.activity_mark_publication p ON p.tenant_id = m.tenant_id AND p.mark_id = m.id AND p.number = m.publications
  WHERE m.tenant_id = school.allowed_tenant() AND m.student_id = school.my_student() AND m.publications > 0 AND school.can('lms.activity.take') $$;

-- Whether the caller may see a quiz's answer key: never while an attempt of theirs runs; with
-- several attempts allowed, not before their last one or the quiz's close; then as the activity
-- says: at once, after their deadline once their correction is complete, or once the teacher
-- published it.
CREATE FUNCTION school.key_visible(p_tenant uuid, p_activity uuid, p_student uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH a AS (SELECT x.key_published_at, v.key_visibility, v.max_attempts, v.closes_at FROM school.activity x
    JOIN school.activity_version v ON v.tenant_id = x.tenant_id AND v.activity_id = x.id AND v.version = x.published_version
    WHERE x.tenant_id = p_tenant AND x.id = p_activity),
  t AS (SELECT count(*) AS started, count(*) FILTER (WHERE status = 'in_progress') AS running FROM school.quiz_attempt
    WHERE tenant_id = p_tenant AND activity_id = p_activity AND student_id = p_student)
  SELECT t.running = 0 AND t.started > 0 AND (t.started >= a.max_attempts OR now() >= greatest(a.closes_at, school.pupil_deadline(p_tenant, p_activity, p_student)))
    AND CASE a.key_visibility
      WHEN 'immediate' THEN true
      WHEN 'after_deadline' THEN now() >= school.pupil_deadline(p_tenant, p_activity, p_student)
        AND NOT EXISTS (SELECT 1 FROM school.quiz_attempt y WHERE y.tenant_id = p_tenant AND y.activity_id = p_activity AND y.student_id = p_student AND y.pending_questions > 0)
      ELSE a.key_published_at IS NOT NULL END
  FROM a, t $$;

-- The caller's submitted attempt read back: their answers, and, when visible, the answer key; the
-- points and comments once the result is published. Nothing of an attempt in progress.
CREATE FUNCTION school.my_review(p_attempt uuid) RETURNS TABLE (attempt_id uuid, activity_id uuid, number integer, key_visible boolean, result_published boolean,
  questions jsonb)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH x AS (SELECT y.* FROM school.quiz_attempt y WHERE y.tenant_id = school.allowed_tenant() AND y.id = p_attempt AND y.student_id = school.my_student()
    AND y.status = 'submitted' AND school.can('lms.activity.take')),
  k AS (SELECT school.key_visible(x.tenant_id, x.activity_id, x.student_id) AS visible,
    EXISTS (SELECT 1 FROM school.activity_mark m WHERE m.tenant_id = x.tenant_id AND m.activity_id = x.activity_id AND m.student_id = x.student_id
      AND m.publications > 0) AS published FROM x)
  SELECT x.id, x.activity_id, x.number, k.visible, k.published,
    (SELECT jsonb_agg(jsonb_build_object('id', q -> 'id', 'position', q -> 'position', 'kind', q -> 'kind', 'prompt', q -> 'prompt', 'points', q -> 'points',
      'choices', CASE WHEN k.visible THEN q -> 'choices' ELSE (SELECT coalesce(jsonb_agg(c - 'correct'), '[]') FROM jsonb_array_elements(q -> 'choices') c) END,
      'answer', (SELECT jsonb_build_object('choices', w.choices, 'answer_text', w.answer_text,
          'points', CASE WHEN k.published THEN w.points END, 'comment', CASE WHEN k.published THEN w.comment END)
        FROM school.quiz_answer w WHERE w.tenant_id = x.tenant_id AND w.attempt_id = x.id AND w.question_id = (q ->> 'id')::uuid))
      ORDER BY (q ->> 'position')::integer)
     FROM school.activity_version v, jsonb_array_elements(v.questions) q WHERE v.tenant_id = x.tenant_id AND v.id = x.version_id)
  FROM x, k $$;

-- Read by the pedagogy manager and the teachers of the group's subject today; written by the
-- functions above alone; the pupil reads their own through `school.my_results` and `school.my_review`.
CREATE POLICY resource_reader ON school.activity_mark AS RESTRICTIVE FOR ALL TO school_runtime USING (
  (SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]));
CREATE POLICY resource_reader ON school.activity_mark_publication AS RESTRICTIVE FOR ALL TO school_runtime USING (
  (SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('activity_mark', 'pedagogy', '{lms.activity.manage,audit.log.read}');
