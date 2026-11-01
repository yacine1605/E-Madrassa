-- Lot 5, FR-LMS-05 (SHOULD): a pupil's progression in a lesson, three facts kept apart (SRS 1.2):
-- opened (the server's first and last time the pupil read it), declared complete (by the pupil, who
-- may withdraw it), and mastery verified, which neither reading nor declaring gives (« une
-- consultation n'est pas une preuve de compréhension »): only the last published result of an
-- activity on that lesson, reaching its rule's pass mark, verifies it; one below it does not. Mastery
-- is computed when read, never stored. Opening and declaring are the pupil's own, on a lesson they may
-- read now. Read by the pupil for themselves, by the teacher of the group's subject for their group,
-- by the pedagogy manager for any; through functions only.

CREATE TABLE school.lesson_progress (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  student_id uuid NOT NULL,
  lesson_id uuid NOT NULL,
  course_id uuid NOT NULL,
  first_opened_at timestamptz NOT NULL,
  last_opened_at timestamptz NOT NULL,
  completed_at timestamptz,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (last_opened_at >= first_opened_at),
  UNIQUE (tenant_id, student_id, lesson_id),
  FOREIGN KEY (tenant_id, student_id) REFERENCES school.student (tenant_id, id),
  FOREIGN KEY (tenant_id, lesson_id) REFERENCES school.lesson (tenant_id, id),
  FOREIGN KEY (tenant_id, course_id) REFERENCES school.course (tenant_id, id)
);
CALL school.protect('school.lesson_progress');
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('lesson_progress', 'pedagogy', '{lms.activity.manage,audit.log.read}');

-- An activity's lesson is one of its group's course in its subject: the evidence of mastery counts
-- for that lesson only.
CREATE FUNCTION school.guard_activity_lesson() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NEW.lesson_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM school.lesson l
      JOIN school.course k ON k.tenant_id = l.tenant_id AND k.id = l.course_id AND k.subject_id = NEW.subject_id
      JOIN school.study_group g ON g.tenant_id = k.tenant_id AND g.program_id = k.program_id AND g.id = NEW.group_id
      WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.lesson_id) THEN
    RAISE EXCEPTION 'lms_lesson_outside_course' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER lesson_in_course BEFORE INSERT OR UPDATE OF lesson_id, group_id, subject_id ON school.activity
  FOR EACH ROW EXECUTE FUNCTION school.guard_activity_lesson();

-- The lessons an activity of a group's subject may be on: those published now of its course, in
-- their order, for whoever writes the group's activities.
CREATE FUNCTION school.activity_lessons(p_group uuid, p_subject uuid) RETURNS TABLE (lesson_id uuid, chapter_title text, title text)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant();
BEGIN
  IF NOT school.manages_activity(p_group, p_subject) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  RETURN QUERY SELECT DISTINCT ON (c.position, l.position) l.id, c.title, v.title
  FROM school.study_group g
  JOIN school.course k ON k.tenant_id = g.tenant_id AND k.program_id = g.program_id AND k.subject_id = p_subject AND k.archived_at IS NULL
  JOIN school.course_chapter c ON c.tenant_id = k.tenant_id AND c.course_id = k.id AND c.archived_at IS NULL
  JOIN school.lesson l ON l.tenant_id = c.tenant_id AND l.chapter_id = c.id AND l.archived_at IS NULL
  JOIN school.lesson_version v ON v.tenant_id = l.tenant_id AND v.lesson_id = l.id AND v.visible_from <= now()
  WHERE g.tenant_id = t AND g.id = p_group
  ORDER BY c.position, l.position, v.version DESC;
END $$;

-- The course of a lesson the caller, a pupil, may read now; nothing otherwise.
CREATE FUNCTION school.my_lesson_course(p_lesson uuid) RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT l.course_id FROM school.lesson l
  WHERE l.tenant_id = school.allowed_tenant() AND l.id = p_lesson AND school.my_student() IS NOT NULL AND school.can('lms.course.read')
    AND EXISTS (SELECT 1 FROM school.published_lessons(l.course_id) p WHERE p.lesson_id = l.id) $$;

-- The pupil opened a lesson: the first time kept, the last one once a day at most.
CREATE FUNCTION school.open_lesson(p_lesson uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE v_course uuid := school.my_lesson_course(p_lesson);
BEGIN
  IF v_course IS NULL THEN RAISE EXCEPTION 'lms_access_denied' USING ERRCODE = '42501'; END IF;
  INSERT INTO school.lesson_progress (tenant_id, student_id, lesson_id, course_id, first_opened_at, last_opened_at)
  VALUES (school.allowed_tenant(), school.my_student(), p_lesson, v_course, now(), now())
  ON CONFLICT (tenant_id, student_id, lesson_id) DO UPDATE SET last_opened_at = now()
    WHERE school.local_day(lesson_progress.last_opened_at) < school.local_day(now());
END $$;

-- The pupil declares a lesson complete, or no longer; declaring opens it too. Never a mastery.
CREATE FUNCTION school.declare_lesson(p_lesson uuid, p_done boolean) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE v_course uuid := school.my_lesson_course(p_lesson);
BEGIN
  IF v_course IS NULL THEN RAISE EXCEPTION 'lms_access_denied' USING ERRCODE = '42501'; END IF;
  INSERT INTO school.lesson_progress (tenant_id, student_id, lesson_id, course_id, first_opened_at, last_opened_at, completed_at)
  VALUES (school.allowed_tenant(), school.my_student(), p_lesson, v_course, now(), now(), CASE WHEN p_done THEN now() END)
  ON CONFLICT (tenant_id, student_id, lesson_id) DO UPDATE
    SET completed_at = CASE WHEN p_done THEN coalesce(lesson_progress.completed_at, now()) END
    WHERE (lesson_progress.completed_at IS NOT NULL) IS DISTINCT FROM p_done;
END $$;

-- A pupil's mastery of a lesson: from the last published result of an activity on it, `verified`
-- when it reaches the pass mark of the activity's rule, `not_verified` below it; nothing without one.
-- Internal: called by the readings below, which check who may read it.
CREATE FUNCTION school.lesson_mastery(p_tenant uuid, p_student uuid, p_lesson uuid) RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT CASE WHEN p.points / p.max_points >= rv.pass_ratio THEN 'verified' ELSE 'not_verified' END
  FROM school.activity a
  JOIN school.activity_mark m ON m.tenant_id = a.tenant_id AND m.activity_id = a.id AND m.student_id = p_student
  JOIN school.activity_mark_publication p ON p.tenant_id = m.tenant_id AND p.mark_id = m.id AND p.number = m.publications AND p.status = 'graded'
  JOIN school.activity_version v ON v.tenant_id = a.tenant_id AND v.activity_id = a.id AND v.version = 1
  CROSS JOIN LATERAL school.rule_values(v.grading_rule_id) rv
  WHERE a.tenant_id = p_tenant AND a.lesson_id = p_lesson AND a.archived_at IS NULL
  ORDER BY p.published_at DESC, p.id LIMIT 1 $$;

-- The caller's own progression in a course they may read, lesson by lesson.
CREATE FUNCTION school.my_progress(p_course uuid) RETURNS TABLE (lesson_id uuid, first_opened_at timestamptz, last_opened_at timestamptz,
  completed_at timestamptz, mastery text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT p.lesson_id, x.first_opened_at, x.last_opened_at, x.completed_at, school.lesson_mastery(school.allowed_tenant(), school.my_student(), p.lesson_id)
  FROM school.published_lessons(p_course) p
  LEFT JOIN school.lesson_progress x ON x.tenant_id = school.allowed_tenant() AND x.student_id = school.my_student() AND x.lesson_id = p.lesson_id
  WHERE school.my_student() IS NOT NULL AND school.can('lms.course.read')
  ORDER BY p.chapter_position, p.lesson_position $$;

-- A group's progression in a subject, for the teacher of it today or the pedagogy manager: each
-- pupil of the group with access now, each lesson published of its course, what they did of it.
CREATE FUNCTION school.course_progress(p_group uuid, p_subject uuid) RETURNS TABLE (student_id uuid, lesson_id uuid, first_opened_at timestamptz,
  completed_at timestamptz, mastery text)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant();
BEGIN
  IF NOT school.manages_activity(p_group, p_subject) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  RETURN QUERY
  WITH pupils AS (
    SELECT DISTINCT e.student_id FROM school.group_membership m
    JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id
    JOIN school.student s ON s.tenant_id = e.tenant_id AND s.id = e.student_id AND s.status = 'active' AND s.archived_at IS NULL
    WHERE m.tenant_id = t AND m.group_id = p_group AND m.valid_from <= current_date AND (m.valid_to IS NULL OR m.valid_to > current_date)
      AND school.access_open(e.status, e.id))
  SELECT u.student_id, l.lesson_id, x.first_opened_at, x.completed_at, school.lesson_mastery(t, u.student_id, l.lesson_id)
  FROM pupils u CROSS JOIN school.activity_lessons(p_group, p_subject) l
  LEFT JOIN school.lesson_progress x ON x.tenant_id = t AND x.student_id = u.student_id AND x.lesson_id = l.lesson_id
  ORDER BY u.student_id;
END $$;
