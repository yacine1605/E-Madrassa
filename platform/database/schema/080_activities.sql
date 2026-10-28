-- Lot 5, activities (SRS 1.2, FR-LMS-02, FR-LMS-03; SPECIFICATION_LOT5_PEDAGOGIE.md §1, §3): a quiz
-- or a homework for a group's subject, written as a draft by the teacher assigned to it that day
-- (077) or by the pedagogy manager, published in frozen versions. Never published to a group or a
-- subject the writer does not teach (`lms_group_forbidden`). Its states are told by its dates:
-- scheduled until it opens, open until its deadline (or the late acceptance's end), closed after,
-- archived on demand; a published activity is never deleted. Its audience is the group's pupils
-- with access at its first publication; a pupil added later is added explicitly, and one may get a
-- deadline of their own, with its reason. Attempts, submissions and marks come next and name the
-- version they used.

INSERT INTO platform.permission (code, module_key, sensitive, labels) VALUES
  ('lms.activity.manage', 'lms', false, '{"fr": "Créer et publier des activités", "ar": "إنشاء الأنشطة ونشرها"}'),
  ('lms.activity.take', 'lms', false, '{"fr": "Réaliser ses activités", "ar": "إنجاز أنشطته"}');
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES
  ('teacher', 'lms.activity.manage'), ('academic_hr', 'lms.activity.manage'), ('student', 'lms.activity.take');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, p.permission_code FROM school.role r
JOIN platform.role_template_permission p ON p.role_code = r.template_code AND p.permission_code IN ('lms.activity.manage', 'lms.activity.take')
WHERE platform.tenant_mode(r.tenant_id) = 'active'
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = p.permission_code);

-- An activity's working copy. Its group, subject and kind are fixed by its first publication; its
-- dates, points and rules may change, each publication a new version.
CREATE TABLE school.activity (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  kind text NOT NULL CHECK (kind IN ('quiz', 'homework')),
  title text NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
  instructions jsonb NOT NULL DEFAULT '{"type": "doc", "content": [{"type": "paragraph"}]}',
  lesson_id uuid,
  opens_at timestamptz,
  deadline_at timestamptz NOT NULL,
  duration_minutes integer CHECK (duration_minutes BETWEEN 1 AND 600),
  max_points numeric(7, 2) NOT NULL DEFAULT 20 CHECK (max_points > 0),
  coefficient numeric(5, 2) NOT NULL DEFAULT 1 CHECK (coefficient > 0),
  max_attempts integer NOT NULL DEFAULT 1 CHECK (max_attempts BETWEEN 1 AND 20),
  selection text NOT NULL DEFAULT 'latest' CHECK (selection IN ('latest', 'best', 'average')),
  late_policy text NOT NULL DEFAULT 'refuse' CHECK (late_policy IN ('refuse', 'accept_until')),
  late_until timestamptz,
  results_visibility text NOT NULL DEFAULT 'on_teacher_publish' CHECK (results_visibility IN ('immediate', 'after_deadline', 'on_teacher_publish')),
  key_visibility text NOT NULL DEFAULT 'after_deadline' CHECK (key_visibility IN ('immediate', 'after_deadline', 'on_teacher_publish')),
  replacement_hours integer NOT NULL DEFAULT 48 CHECK (replacement_hours BETWEEN 1 AND 720),
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published')),
  published_version integer CHECK (published_version >= 1),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  -- A quiz is timed; a homework is handed in by links.
  CHECK ((kind = 'quiz') = (duration_minutes IS NOT NULL)),
  CHECK (kind = 'quiz' OR (max_attempts = 1 AND selection = 'latest')),
  CHECK ((late_policy = 'accept_until') = (late_until IS NOT NULL)),
  CHECK (opens_at IS NULL OR opens_at < deadline_at),
  CHECK (late_until IS NULL OR late_until > deadline_at),
  CHECK ((status = 'published') = (published_version IS NOT NULL)),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, group_id) REFERENCES school.study_group (tenant_id, id),
  FOREIGN KEY (tenant_id, subject_id) REFERENCES school.subject (tenant_id, id),
  FOREIGN KEY (tenant_id, lesson_id) REFERENCES school.lesson (tenant_id, id)
);
CREATE INDEX activity_group ON school.activity (tenant_id, group_id, subject_id);
CALL school.protect('school.activity');

-- A quiz's questions in their order: a single or multiple choice, graded all or nothing, or an open
-- question the teacher corrects. A choice is `{id, text, correct}`; points are exact decimals.
CREATE TABLE school.activity_question (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  activity_id uuid NOT NULL,
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  position integer NOT NULL CHECK (position >= 1),
  kind text NOT NULL CHECK (kind IN ('single', 'multiple', 'open')),
  prompt text NOT NULL CHECK (length(prompt) BETWEEN 1 AND 4000),
  points numeric(7, 2) NOT NULL CHECK (points > 0),
  choices jsonb NOT NULL DEFAULT '[]',
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CONSTRAINT activity_question_position UNIQUE (tenant_id, activity_id, position) DEFERRABLE INITIALLY DEFERRED,
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, activity_id) REFERENCES school.activity (tenant_id, id)
);
CALL school.protect('school.activity_question');

-- Each published version, never changed: what an attempt or a submission used. Its questions are
-- frozen with their correct answers, which a pupil never reads before their visibility.
CREATE TABLE school.activity_version (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  activity_id uuid NOT NULL,
  version integer NOT NULL CHECK (version >= 1),
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  kind text NOT NULL,
  title text NOT NULL,
  instructions jsonb NOT NULL,
  lesson_version_id uuid,
  opens_at timestamptz NOT NULL,
  deadline_at timestamptz NOT NULL,
  closes_at timestamptz NOT NULL,
  duration_minutes integer,
  max_points numeric(7, 2) NOT NULL,
  coefficient numeric(5, 2) NOT NULL,
  max_attempts integer NOT NULL,
  selection text NOT NULL,
  late_policy text NOT NULL,
  results_visibility text NOT NULL,
  key_visibility text NOT NULL,
  replacement_hours integer NOT NULL,
  questions jsonb NOT NULL,
  published_at timestamptz NOT NULL DEFAULT now(),
  published_by bigint REFERENCES public.users(id),
  UNIQUE (tenant_id, activity_id, version),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, activity_id) REFERENCES school.activity (tenant_id, id),
  FOREIGN KEY (tenant_id, lesson_version_id) REFERENCES school.lesson_version (tenant_id, id)
);
ALTER TABLE school.activity_version ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.activity_version FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.activity_version USING (tenant_id = (SELECT school.allowed_tenant())) WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON school.activity_version FOR EACH ROW EXECUTE FUNCTION school.guard_writable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.activity_version FOR EACH ROW EXECUTE FUNCTION school.immutable();

-- Who the activity is for: the group's pupils with access at its first publication, and those added
-- explicitly afterwards; one may have a deadline of their own (an extension), with its reason.
CREATE TABLE school.activity_audience (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  activity_id uuid NOT NULL,
  student_id uuid NOT NULL,
  enrollment_id uuid NOT NULL,
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  added_later boolean NOT NULL DEFAULT false,
  deadline_at timestamptz,
  extension_reason text CHECK (length(extension_reason) BETWEEN 3 AND 500),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK ((deadline_at IS NULL) = (extension_reason IS NULL)),
  UNIQUE (tenant_id, activity_id, student_id),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, activity_id) REFERENCES school.activity (tenant_id, id),
  FOREIGN KEY (tenant_id, student_id) REFERENCES school.student (tenant_id, id),
  FOREIGN KEY (tenant_id, enrollment_id) REFERENCES school.enrollment (tenant_id, id)
);
CREATE INDEX activity_audience_student ON school.activity_audience (tenant_id, student_id);
CALL school.protect('school.activity_audience');

-- Whether the caller may write a group's subject's activities: the pedagogy manager of the whole
-- school, or a teacher assigned to it today (077). A teacher whose assignment ended no longer does.
CREATE FUNCTION school.manages_activity(p_group uuid, p_subject uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT school.can_everywhere('lms.activity.manage') OR (school.can('lms.activity.manage') AND school.teaches(p_group, p_subject)) $$;

-- The groups' subjects the caller teaches today, for the activities' policies: `group:subject`.
CREATE FUNCTION school.taught_keys() RETURNS text[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT coalesce(array_agg(DISTINCT a.group_id::text || ':' || a.subject_id::text), '{}') FROM school.teaching_assignment a
  JOIN school.staff s ON s.tenant_id = a.tenant_id AND s.id = a.staff_id AND s.user_id = school.actor() AND s.status <> 'left' AND s.archived_at IS NULL
  WHERE a.tenant_id = school.allowed_tenant() AND school.can('lms.activity.manage')
    AND a.valid_from <= current_date AND (a.valid_to IS NULL OR a.valid_to >= current_date) $$;

-- Whether a group's programme teaches a subject today, whatever the writer may read of it.
CREATE FUNCTION school.group_subject(p_group uuid, p_subject uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM school.study_group g JOIN school.program_subject ps ON ps.tenant_id = g.tenant_id AND ps.program_id = g.program_id
    WHERE g.tenant_id = school.allowed_tenant() AND g.id = p_group AND ps.subject_id = p_subject AND ps.archived_at IS NULL
      AND ps.valid_from <= current_date AND (ps.valid_to IS NULL OR ps.valid_to >= current_date)) $$;

-- What is wrong with a question's choices, if anything: an open question has none; a choice
-- question 2 to 10 choices `{id, text, correct}`, ids unique, one correct for a single choice, at
-- least one for a multiple choice.
CREATE FUNCTION school.question_error(p_kind text, p_choices jsonb) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE
    WHEN jsonb_typeof(p_choices) IS DISTINCT FROM 'array' THEN 'lms_question_choices_invalid'
    WHEN p_kind = 'open' THEN CASE WHEN jsonb_array_length(p_choices) = 0 THEN NULL ELSE 'lms_question_choices_invalid' END
    WHEN jsonb_array_length(p_choices) NOT BETWEEN 2 AND 10 THEN 'lms_question_choices_invalid'
    WHEN EXISTS (SELECT 1 FROM jsonb_array_elements(p_choices) c WHERE jsonb_typeof(c) <> 'object'
      OR EXISTS (SELECT 1 FROM jsonb_object_keys(c) k WHERE k NOT IN ('id', 'text', 'correct'))
      OR jsonb_typeof(c -> 'id') IS DISTINCT FROM 'string' OR (c ->> 'id') !~ '^[A-Za-z0-9_-]{1,20}$'
      OR jsonb_typeof(c -> 'text') IS DISTINCT FROM 'string' OR length(c ->> 'text') NOT BETWEEN 1 AND 500
      OR jsonb_typeof(c -> 'correct') IS DISTINCT FROM 'boolean') THEN 'lms_question_choices_invalid'
    WHEN (SELECT count(DISTINCT c ->> 'id') FROM jsonb_array_elements(p_choices) c) <> jsonb_array_length(p_choices) THEN 'lms_question_choices_invalid'
    WHEN p_kind = 'single' AND (SELECT count(*) FROM jsonb_array_elements(p_choices) c WHERE (c -> 'correct')::boolean) <> 1 THEN 'lms_question_correct_count'
    WHEN p_kind = 'multiple' AND (SELECT count(*) FROM jsonb_array_elements(p_choices) c WHERE (c -> 'correct')::boolean) < 1 THEN 'lms_question_correct_count'
  END $$;

-- An activity's working copy: by whoever writes the group's subject's activities (lot 5 §1, §7),
-- a subject the group's programme teaches, valid instructions. Group, subject and kind fixed once
-- published; a draft never published is deleted, a published one archived for good.
CREATE FUNCTION school.guard_activity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a school.activity := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; e text;
BEGIN
  PERFORM school.require('lms.activity.manage');
  IF NOT school.manages_activity(CASE WHEN TG_OP = 'INSERT' THEN a.group_id ELSE OLD.group_id END,
                                 CASE WHEN TG_OP = 'INSERT' THEN a.subject_id ELSE OLD.subject_id END) THEN
    RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501';
  END IF;
  IF TG_OP = 'DELETE' THEN
    IF OLD.published_version IS NOT NULL THEN RAISE EXCEPTION 'lms_activity_published' USING ERRCODE = '23514'; END IF;
    RETURN OLD;
  END IF;
  IF TG_OP = 'INSERT' THEN
    IF NOT school.group_subject(NEW.group_id, NEW.subject_id) THEN RAISE EXCEPTION 'lms_subject_outside_program' USING ERRCODE = '23514'; END IF;
    NEW.status := 'draft'; NEW.published_version := NULL; NEW.archived_at := NULL;
  ELSE
    IF (NEW.group_id, NEW.subject_id) IS DISTINCT FROM (OLD.group_id, OLD.subject_id)
       OR (OLD.published_version IS NOT NULL AND NEW.kind IS DISTINCT FROM OLD.kind) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'lms_activity_archived' USING ERRCODE = '23514'; END IF;
    IF NEW.archived_at IS NOT NULL AND OLD.published_version IS NULL THEN RAISE EXCEPTION 'lms_activity_draft' USING ERRCODE = '23514'; END IF;
  END IF;
  NEW.title := btrim(NEW.title);
  IF NEW.kind = 'homework' THEN NEW.duration_minutes := NULL; NEW.max_attempts := 1; NEW.selection := 'latest'; END IF;
  IF NEW.late_policy = 'refuse' THEN NEW.late_until := NULL; END IF;
  IF TG_OP = 'INSERT' OR NEW.instructions IS DISTINCT FROM OLD.instructions THEN
    e := school.lms_content_error(NEW.instructions);
    IF e IS NOT NULL THEN RAISE EXCEPTION '%', e USING ERRCODE = '23514'; END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.activity FOR EACH ROW EXECUTE FUNCTION school.guard_activity();

-- The activity a question belongs to, whatever the writer may read: its group, subject and kind.
CREATE FUNCTION school.activity_keys(p_activity uuid) RETURNS TABLE (group_id uuid, subject_id uuid, kind text, archived boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT a.group_id, a.subject_id, a.kind, a.archived_at IS NOT NULL FROM school.activity a WHERE a.tenant_id = school.allowed_tenant() AND a.id = p_activity $$;

-- A quiz's question, by whoever writes its activity; its choices valid; last in the order unless placed.
CREATE FUNCTION school.guard_activity_question() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE q school.activity_question := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; k record; e text;
BEGIN
  PERFORM school.require('lms.activity.manage');
  SELECT * INTO k FROM school.activity_keys(q.activity_id);
  IF k.group_id IS NULL OR NOT school.manages_activity(k.group_id, k.subject_id) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  IF k.archived THEN RAISE EXCEPTION 'lms_activity_archived' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  IF k.kind <> 'quiz' THEN RAISE EXCEPTION 'lms_questions_quiz_only' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'UPDATE' AND NEW.activity_id IS DISTINCT FROM OLD.activity_id THEN RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514'; END IF;
  NEW.group_id := k.group_id; NEW.subject_id := k.subject_id; NEW.archived_at := NULL;
  NEW.prompt := btrim(NEW.prompt);
  IF TG_OP = 'INSERT' AND NEW.position IS NULL THEN
    PERFORM 1 FROM school.activity x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.activity_id FOR UPDATE;
    SELECT coalesce(max(x.position), 0) + 1 INTO NEW.position FROM school.activity_question x WHERE x.tenant_id = NEW.tenant_id AND x.activity_id = NEW.activity_id;
  END IF;
  e := school.question_error(NEW.kind, NEW.choices);
  IF e IS NOT NULL THEN RAISE EXCEPTION '%', e USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.activity_question FOR EACH ROW EXECUTE FUNCTION school.guard_activity_question();

-- What would stop a publication now: the dates (open before the deadline, a deadline to come at the
-- first publication, a late acceptance after it), a quiz with its questions whose points make its
-- total, a lesson pupils read.
CREATE FUNCTION school.activity_problems(p_activity uuid) RETURNS TABLE (problem text, question_id uuid)
LANGUAGE sql STABLE AS $$
  WITH a AS (SELECT * FROM school.activity WHERE tenant_id = school.allowed_tenant() AND id = p_activity),
  q AS (SELECT x.* FROM school.activity_question x JOIN a ON x.tenant_id = a.tenant_id AND x.activity_id = a.id)
  SELECT 'lms_deadline_past', NULL::uuid FROM a WHERE a.published_version IS NULL AND a.deadline_at <= now()
  UNION ALL SELECT 'lms_activity_dates_invalid', NULL FROM a WHERE coalesce(a.opens_at, now()) >= a.deadline_at
  UNION ALL SELECT 'lms_quiz_empty', NULL FROM a WHERE a.kind = 'quiz' AND NOT EXISTS (SELECT 1 FROM q)
  UNION ALL SELECT 'lms_points_mismatch', NULL FROM a WHERE a.kind = 'quiz' AND EXISTS (SELECT 1 FROM q)
    AND (SELECT sum(points) FROM q) <> a.max_points
  UNION ALL SELECT school.question_error(q.kind, q.choices), q.id FROM q WHERE school.question_error(q.kind, q.choices) IS NOT NULL
  UNION ALL SELECT 'lms_lesson_not_visible', NULL FROM a WHERE a.lesson_id IS NOT NULL AND NOT school.lesson_visible(a.lesson_id)
  UNION ALL SELECT e, NULL FROM a, school.lms_content_error(a.instructions) e WHERE e IS NOT NULL $$;

-- FR-LMS-02, FR-LMS-03: publishes the working copy as a new version, frozen with its questions and
-- the lesson's version pupils read now; open now or from `opens_at`. At the first publication, its
-- audience is the group's pupils placed in it today with access to courses. With its owner's rights,
-- for whoever writes the group's subject's activities only.
CREATE FUNCTION school.publish_activity(p_activity uuid) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant(); a school.activity; v_problem text; v_version integer; v_lesson uuid; v_opens timestamptz;
BEGIN
  SELECT * INTO a FROM school.activity x WHERE x.tenant_id = t AND x.id = p_activity FOR UPDATE;
  IF a.id IS NULL OR NOT school.manages_activity(a.group_id, a.subject_id) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  IF a.archived_at IS NOT NULL THEN RAISE EXCEPTION 'lms_activity_archived' USING ERRCODE = '23514'; END IF;
  SELECT p.problem INTO v_problem FROM school.activity_problems(a.id) p LIMIT 1;
  IF v_problem IS NOT NULL THEN RAISE EXCEPTION '%', v_problem USING ERRCODE = '23514'; END IF;
  v_version := coalesce(a.published_version, 0) + 1;
  v_opens := coalesce(a.opens_at, (SELECT v.opens_at FROM school.activity_version v WHERE v.tenant_id = t AND v.activity_id = a.id AND v.version = 1), now());
  IF a.lesson_id IS NOT NULL THEN
    SELECT p.version_id INTO v_lesson FROM school.lesson l, school.published_lessons(l.course_id) p
    WHERE l.tenant_id = t AND l.id = a.lesson_id AND p.lesson_id = a.lesson_id;
  END IF;
  INSERT INTO school.activity_version (tenant_id, activity_id, version, group_id, subject_id, kind, title, instructions, lesson_version_id, opens_at,
    deadline_at, closes_at, duration_minutes, max_points, coefficient, max_attempts, selection, late_policy, results_visibility, key_visibility,
    replacement_hours, questions, published_by)
  VALUES (t, a.id, v_version, a.group_id, a.subject_id, a.kind, a.title, a.instructions, v_lesson, v_opens, a.deadline_at,
    coalesce(a.late_until, a.deadline_at), a.duration_minutes, a.max_points, a.coefficient, a.max_attempts, a.selection, a.late_policy,
    a.results_visibility, a.key_visibility, a.replacement_hours,
    coalesce((SELECT jsonb_agg(jsonb_build_object('id', q.id, 'position', q.position, 'kind', q.kind, 'prompt', q.prompt, 'points', q.points, 'choices', q.choices)
      ORDER BY q.position) FROM school.activity_question q WHERE q.tenant_id = t AND q.activity_id = a.id), '[]'),
    school.actor());
  IF a.published_version IS NULL THEN
    INSERT INTO school.activity_audience (tenant_id, activity_id, student_id, enrollment_id, group_id, subject_id)
    SELECT DISTINCT ON (e.student_id) t, a.id, e.student_id, e.id, a.group_id, a.subject_id FROM school.group_membership m
    JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id
    JOIN school.student s ON s.tenant_id = e.tenant_id AND s.id = e.student_id AND s.status = 'active' AND s.archived_at IS NULL
    WHERE m.tenant_id = t AND m.group_id = a.group_id AND m.valid_from <= current_date AND (m.valid_to IS NULL OR m.valid_to > current_date)
      AND school.access_open(e.status, e.id)
    ORDER BY e.student_id, e.created_at DESC;
  END IF;
  UPDATE school.activity SET status = 'published', published_version = v_version, opens_at = v_opens WHERE tenant_id = t AND id = a.id;
  RETURN v_version;
END $$;

-- A pupil added to the audience after the first publication: one placed in the group today with
-- access, not there yet; with a deadline of their own if given. By whoever writes the activities.
CREATE FUNCTION school.add_to_audience(p_activity uuid, p_student uuid, p_deadline timestamptz DEFAULT NULL, p_reason text DEFAULT NULL) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); a school.activity; v_enrollment uuid; v_id uuid;
BEGIN
  SELECT * INTO a FROM school.activity x WHERE x.tenant_id = t AND x.id = p_activity FOR UPDATE;
  IF a.id IS NULL OR NOT school.manages_activity(a.group_id, a.subject_id) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  IF a.published_version IS NULL THEN RAISE EXCEPTION 'lms_activity_draft' USING ERRCODE = '23514'; END IF;
  IF a.archived_at IS NOT NULL THEN RAISE EXCEPTION 'lms_activity_archived' USING ERRCODE = '23514'; END IF;
  SELECT e.id INTO v_enrollment FROM school.group_membership m
  JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id AND e.student_id = p_student
  JOIN school.student s ON s.tenant_id = e.tenant_id AND s.id = e.student_id AND s.status = 'active' AND s.archived_at IS NULL
  WHERE m.tenant_id = t AND m.group_id = a.group_id AND m.valid_from <= current_date AND (m.valid_to IS NULL OR m.valid_to > current_date)
    AND school.access_open(e.status, e.id)
  ORDER BY e.created_at DESC LIMIT 1;
  IF v_enrollment IS NULL THEN RAISE EXCEPTION 'lms_audience_not_member' USING ERRCODE = '23514'; END IF;
  IF EXISTS (SELECT 1 FROM school.activity_audience x WHERE x.tenant_id = t AND x.activity_id = a.id AND x.student_id = p_student) THEN
    RAISE EXCEPTION 'lms_audience_exists' USING ERRCODE = '23514';
  END IF;
  IF p_deadline IS NOT NULL AND p_deadline <= now() THEN RAISE EXCEPTION 'lms_deadline_past' USING ERRCODE = '23514'; END IF;
  INSERT INTO school.activity_audience (tenant_id, activity_id, student_id, enrollment_id, group_id, subject_id, added_later, deadline_at, extension_reason)
  VALUES (t, a.id, p_student, v_enrollment, a.group_id, a.subject_id, true, p_deadline,
    CASE WHEN p_deadline IS NOT NULL THEN coalesce(nullif(btrim(p_reason), ''), 'Ajout après publication') END)
  RETURNING id INTO v_id;
  RETURN v_id;
END $$;

-- An individual extension: a later deadline for one pupil, with its reason; the journal keeps each one.
CREATE FUNCTION school.guard_activity_audience() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    -- Written by the publication and `add_to_audience` alone (no privilege to insert otherwise).
    RETURN NEW;
  END IF;
  PERFORM school.require('lms.activity.manage');
  IF NOT school.manages_activity(OLD.group_id, OLD.subject_id) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  IF (NEW.activity_id, NEW.student_id, NEW.enrollment_id, NEW.group_id, NEW.subject_id, NEW.added_later, NEW.archived_at)
     IS DISTINCT FROM (OLD.activity_id, OLD.student_id, OLD.enrollment_id, OLD.group_id, OLD.subject_id, OLD.added_later, OLD.archived_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  NEW.extension_reason := nullif(btrim(NEW.extension_reason), '');
  IF NEW.deadline_at IS DISTINCT FROM OLD.deadline_at THEN
    IF NEW.deadline_at IS NULL OR NEW.extension_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    IF NEW.deadline_at <= now() THEN RAISE EXCEPTION 'lms_deadline_past' USING ERRCODE = '23514'; END IF;
    IF NEW.deadline_at <= (SELECT v.deadline_at FROM school.activity_version v
        WHERE v.tenant_id = NEW.tenant_id AND v.activity_id = NEW.activity_id ORDER BY v.version DESC LIMIT 1) THEN
      RAISE EXCEPTION 'lms_extension_earlier' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.activity_audience FOR EACH ROW EXECUTE FUNCTION school.guard_activity_audience();

-- An activity's state at a moment, from its last version and the pupil's own deadline if any.
CREATE FUNCTION school.activity_state(p_published boolean, p_archived timestamptz, p_opens timestamptz, p_closes timestamptz, p_at timestamptz DEFAULT now())
RETURNS text LANGUAGE sql STABLE AS $$
  SELECT CASE WHEN p_archived IS NOT NULL THEN 'archived' WHEN NOT p_published THEN 'draft'
    WHEN p_at < p_opens THEN 'scheduled' WHEN p_at < p_closes THEN 'open' ELSE 'closed' END $$;

-- FR-LMS-02, FR-LMS-03 for the pupil: the activities they are in the audience of, of a group they
-- learn in today (077; a withdrawn access closes them at once), at their last version: their own
-- deadline, the state it gives, never a quiz's questions nor its answers (an attempt shows them).
CREATE FUNCTION school.my_activities() RETURNS TABLE (activity_id uuid, version_id uuid, version integer, group_id uuid, subject_id uuid, kind text,
  title text, instructions jsonb, lesson_version_id uuid, opens_at timestamptz, deadline_at timestamptz, closes_at timestamptz, duration_minutes integer,
  max_points numeric, coefficient numeric, max_attempts integer, late_policy text, state text, extended boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT a.id, v.id, v.version, v.group_id, v.subject_id, v.kind, v.title, v.instructions, v.lesson_version_id, v.opens_at,
    coalesce(au.deadline_at, v.deadline_at), greatest(v.closes_at, coalesce(au.deadline_at, v.closes_at)), v.duration_minutes, v.max_points,
    v.coefficient, v.max_attempts, v.late_policy,
    school.activity_state(true, a.archived_at, v.opens_at, greatest(v.closes_at, coalesce(au.deadline_at, v.closes_at))), au.deadline_at IS NOT NULL
  FROM school.learner_groups() lg
  JOIN school.activity_audience au ON au.tenant_id = school.allowed_tenant() AND au.student_id = lg.student_id AND au.group_id = lg.group_id
  JOIN school.activity a ON a.tenant_id = au.tenant_id AND a.id = au.activity_id AND a.archived_at IS NULL AND a.published_version IS NOT NULL
  JOIN school.activity_version v ON v.tenant_id = a.tenant_id AND v.activity_id = a.id AND v.version = a.published_version
  WHERE school.can('lms.activity.take') $$;

-- Read and written by the pedagogy manager and by the teachers of the group's subject today; a pupil
-- reads their activities through `school.my_activities`. The versions and the audience are written
-- by the database alone (the audience's own deadlines excepted).
CREATE POLICY resource_reader ON school.activity AS RESTRICTIVE FOR ALL TO school_runtime USING (
  (SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]))
  WITH CHECK ((SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]));
CREATE POLICY resource_reader ON school.activity_question AS RESTRICTIVE FOR ALL TO school_runtime USING (
  (SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]))
  WITH CHECK ((SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]));
CREATE POLICY resource_reader ON school.activity_version AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]));
CREATE POLICY resource_reader ON school.activity_audience AS RESTRICTIVE FOR ALL TO school_runtime USING (
  (SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]))
  WITH CHECK ((SELECT school.can_everywhere('lms.activity.manage')) OR (group_id::text || ':' || subject_id::text) = ANY ((SELECT school.taught_keys())::text[]));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('activity', 'pedagogy', '{lms.activity.manage,audit.log.read}'), ('activity_question', 'pedagogy', '{lms.activity.manage,audit.log.read}'),
  ('activity_audience', 'pedagogy', '{lms.activity.manage,audit.log.read}');
