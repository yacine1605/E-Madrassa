-- Lot 5, grading rules and averages (SRS 1.2, FR-LMS-04; SPECIFICATION_LOT5_PEDAGOGIE.md §6): a rule
-- per programme or per level of the referential, never for the whole school: the scale's maximum
-- (10, 20, 100…), the step a mark is shown to (0.25, 0.5 or 1), the rounding (`half_up` by default)
-- and the pass mark (50 % by default), from a day until another. Its versions never change and never
-- overlap; one starts today or later and ends yesterday at the earliest, so that no result already
-- shown changes (AC: a rule changed for a later period leaves the past as it was). An activity keeps
-- the rule of its first publication. A mark is shown from its raw ratio, converted to the scale then
-- rounded to the step, never stored rounded. A period's weighted average takes the published marks
-- only, `sum(ratio × coefficient) / sum(coefficients)`, converted and rounded once, on the rule in
-- force at the period's end; a missing work, an excused absence or a mark not published is left out,
-- the pass mark never making a missing mark a zero. Without a rule, 20 points, a quarter point,
-- `half_up`, half the scale.

INSERT INTO platform.permission (code, module_key, sensitive, labels) VALUES
  ('lms.grading.manage', 'lms', false, '{"fr": "Gérer les règles de notation", "ar": "تسيير قواعد التنقيط"}');
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES ('academic_hr', 'lms.grading.manage');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, 'lms.grading.manage' FROM school.role r
WHERE r.template_code = 'academic_hr' AND platform.tenant_mode(r.tenant_id) = 'active'
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = 'lms.grading.manage');

CREATE TABLE school.grading_rule (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  program_id uuid,
  node_id uuid,
  scale_max numeric(6, 2) NOT NULL CHECK (scale_max > 0 AND scale_max <= 1000),
  step numeric(4, 2) NOT NULL DEFAULT 0.25 CHECK (step IN (0.25, 0.5, 1)),
  rounding text NOT NULL DEFAULT 'half_up' CHECK (rounding IN ('half_up', 'down', 'up')),
  pass_ratio numeric(4, 3) NOT NULL DEFAULT 0.5 CHECK (pass_ratio > 0 AND pass_ratio <= 1),
  valid_from date NOT NULL,
  valid_to date,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK ((program_id IS NULL) <> (node_id IS NULL)),
  CHECK (valid_to IS NULL OR valid_to >= valid_from),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, program_id) REFERENCES school.program (tenant_id, id),
  FOREIGN KEY (tenant_id, node_id) REFERENCES school.ref_node (tenant_id, id),
  CONSTRAINT grading_rule_program_once EXCLUDE USING gist (tenant_id WITH =, program_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&)
    WHERE (program_id IS NOT NULL),
  CONSTRAINT grading_rule_node_once EXCLUDE USING gist (tenant_id WITH =, node_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&)
    WHERE (node_id IS NOT NULL)
);
CALL school.protect('school.grading_rule');

-- Kept by `lms.grading.manage` for the whole school: a version starts today or later and keeps its
-- values; its end, yesterday at the earliest, is final.
CREATE FUNCTION school.guard_grading_rule() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require_everywhere('lms.grading.manage');
  IF TG_OP = 'DELETE' THEN
    IF OLD.valid_from <= current_date THEN RAISE EXCEPTION 'grading_rule_begun' USING ERRCODE = '23514'; END IF;
    RETURN OLD;
  END IF;
  IF TG_OP = 'INSERT' THEN
    NEW.valid_from := coalesce(NEW.valid_from, current_date); NEW.archived_at := NULL;
    IF NEW.valid_from < current_date THEN RAISE EXCEPTION 'grading_rule_past' USING ERRCODE = '23514'; END IF;
  ELSE
    IF (NEW.program_id, NEW.node_id, NEW.scale_max, NEW.step, NEW.rounding, NEW.pass_ratio, NEW.valid_from, NEW.archived_at)
       IS DISTINCT FROM (OLD.program_id, OLD.node_id, OLD.scale_max, OLD.step, OLD.rounding, OLD.pass_ratio, OLD.valid_from, OLD.archived_at) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.valid_to IS NOT NULL AND NEW.valid_to IS DISTINCT FROM OLD.valid_to THEN RAISE EXCEPTION 'grading_rule_ended' USING ERRCODE = '23514'; END IF;
    IF NEW.valid_to IS DISTINCT FROM OLD.valid_to AND NEW.valid_to < current_date - 1 THEN RAISE EXCEPTION 'grading_rule_end_past' USING ERRCODE = '23514'; END IF;
  END IF;
  IF NEW.valid_to < NEW.valid_from THEN RAISE EXCEPTION 'grading_rule_end_before_start' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.grading_rule FOR EACH ROW EXECUTE FUNCTION school.guard_grading_rule();

-- The rule in force on a day for a programme: its own, or the nearest of its level's ancestors'.
CREATE FUNCTION school.grading_rule_for(p_tenant uuid, p_program uuid, p_day date) RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH RECURSIVE up(id, depth) AS (
    SELECT p.node_id, 0 FROM school.program p WHERE p.tenant_id = p_tenant AND p.id = p_program
    UNION ALL SELECT n.parent_id, up.depth + 1 FROM school.ref_node n JOIN up ON n.tenant_id = p_tenant AND n.id = up.id WHERE n.parent_id IS NOT NULL)
  SELECT r.id FROM school.grading_rule r LEFT JOIN up ON up.id = r.node_id
  WHERE r.tenant_id = p_tenant AND r.archived_at IS NULL AND r.valid_from <= p_day AND (r.valid_to IS NULL OR r.valid_to >= p_day)
    AND (r.program_id = p_program OR up.id IS NOT NULL)
  ORDER BY r.program_id IS NULL, up.depth NULLS FIRST LIMIT 1 $$;

-- A rule's values, the platform's default for none.
CREATE FUNCTION school.rule_values(p_rule uuid) RETURNS TABLE (scale_max numeric, step numeric, rounding text, pass_ratio numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT coalesce(r.scale_max, 20), coalesce(r.step, 0.25), coalesce(r.rounding, 'half_up'), coalesce(r.pass_ratio, 0.5)
  FROM (SELECT 1) one LEFT JOIN school.grading_rule r ON r.id = p_rule $$;

-- A value rounded to the step by the method: half up, down or up.
CREATE FUNCTION school.round_mark(p_value numeric, p_step numeric, p_method text) RETURNS numeric LANGUAGE sql IMMUTABLE AS $$
  SELECT (CASE p_method WHEN 'down' THEN floor(p_value / p_step) WHEN 'up' THEN ceil(p_value / p_step) ELSE round(p_value / p_step) END * p_step)::numeric(10, 2) $$;

-- A ratio shown on a rule's scale, rounded once.
CREATE FUNCTION school.on_scale(p_ratio numeric, p_rule uuid) RETURNS numeric LANGUAGE sql STABLE AS $$
  SELECT CASE WHEN p_ratio IS NULL THEN NULL ELSE school.round_mark(p_ratio * v.scale_max, v.step, v.rounding) END FROM school.rule_values(p_rule) v $$;

-- An activity keeps the rule of its first publication: the one in force that day for its group's programme.
ALTER TABLE school.activity_version ADD COLUMN grading_rule_id uuid;
ALTER TABLE school.activity_version ADD CONSTRAINT activity_version_grading_rule FOREIGN KEY (tenant_id, grading_rule_id) REFERENCES school.grading_rule (tenant_id, id);
CREATE FUNCTION school.keep_grading_rule() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NEW.version = 1 THEN
    NEW.grading_rule_id := school.grading_rule_for(NEW.tenant_id, (SELECT g.program_id FROM school.study_group g WHERE g.tenant_id = NEW.tenant_id AND g.id = NEW.group_id),
      school.local_day(NEW.published_at));
  ELSE
    NEW.grading_rule_id := (SELECT v.grading_rule_id FROM school.activity_version v WHERE v.tenant_id = NEW.tenant_id AND v.activity_id = NEW.activity_id AND v.version = 1);
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER grading_rule BEFORE INSERT ON school.activity_version FOR EACH ROW EXECUTE FUNCTION school.keep_grading_rule();

-- A pupil's weighted average of a group's subject over a period: the published marks of the
-- activities whose deadline falls in it, on the rule in force at its end. Their number, the raw
-- ratio, the average shown on the scale, the scale, whether it passes. Nothing for no published mark.
CREATE FUNCTION school.average_of(p_tenant uuid, p_student uuid, p_group uuid, p_subject uuid, p_from date, p_to date)
RETURNS TABLE (marks integer, ratio numeric, average numeric, scale_max numeric, passed boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH published AS (
    SELECT p.points / p.max_points AS ratio, p.coefficient FROM school.activity_mark m
    JOIN school.activity_mark_publication p ON p.tenant_id = m.tenant_id AND p.mark_id = m.id AND p.number = m.publications
    JOIN school.activity a ON a.tenant_id = m.tenant_id AND a.id = m.activity_id AND a.archived_at IS NULL
    JOIN school.activity_version v ON v.tenant_id = a.tenant_id AND v.activity_id = a.id AND v.version = a.published_version
    WHERE m.tenant_id = p_tenant AND m.student_id = p_student AND m.group_id = p_group AND m.subject_id = p_subject
      AND p.status = 'graded' AND school.local_day(v.deadline_at) BETWEEN p_from AND p_to),
  rule AS (SELECT school.grading_rule_for(p_tenant, (SELECT g.program_id FROM school.study_group g WHERE g.tenant_id = p_tenant AND g.id = p_group), p_to) AS id),
  total AS (SELECT count(*)::integer AS n, sum(ratio * coefficient) / nullif(sum(coefficient), 0) AS r FROM published)
  SELECT total.n, round(total.r, 6), school.on_scale(total.r, rule.id), v.scale_max, total.r >= v.pass_ratio
  FROM total, rule, school.rule_values(rule.id) v WHERE total.n > 0 $$;

-- FR-LMS-04: a group's gradebook in a subject over a period, for the teacher of it today or the
-- pedagogy manager: each pupil of an activity's audience, each activity, its state, its raw points
-- and maximum, its coefficient, the mark on its own rule's scale, and what is published.
CREATE FUNCTION school.gradebook(p_group uuid, p_subject uuid, p_from date, p_to date) RETURNS TABLE (student_id uuid, activity_id uuid, title text, kind text,
  deadline_at timestamptz, status text, points numeric, max_points numeric, coefficient numeric, mark numeric, scale_max numeric, published_points numeric,
  published_mark numeric)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant();
BEGIN
  IF NOT school.manages_activity(p_group, p_subject) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  RETURN QUERY SELECT au.student_id, a.id, v.title, v.kind, v.deadline_at, coalesce(m.status, 'not_submitted'), m.points, coalesce(m.max_points, v.max_points),
    coalesce(m.coefficient, v.coefficient), school.on_scale(m.points / coalesce(m.max_points, v.max_points), v.grading_rule_id), rv.scale_max,
    CASE WHEN m.published_status = 'graded' THEN m.published_points END,
    CASE WHEN m.published_status = 'graded' THEN school.on_scale(m.published_points / coalesce(m.max_points, v.max_points), v.grading_rule_id) END
  FROM school.activity a
  JOIN school.activity_version v ON v.tenant_id = a.tenant_id AND v.activity_id = a.id AND v.version = a.published_version
  JOIN school.activity_audience au ON au.tenant_id = a.tenant_id AND au.activity_id = a.id
  LEFT JOIN school.activity_mark m ON m.tenant_id = a.tenant_id AND m.activity_id = a.id AND m.student_id = au.student_id
  CROSS JOIN LATERAL school.rule_values(v.grading_rule_id) rv
  WHERE a.tenant_id = t AND a.group_id = p_group AND a.subject_id = p_subject AND a.archived_at IS NULL
    AND school.local_day(v.deadline_at) BETWEEN p_from AND p_to
  ORDER BY au.student_id, v.deadline_at, a.id;
END $$;

-- The averages of that gradebook, pupil by pupil, from their published marks only.
CREATE FUNCTION school.gradebook_averages(p_group uuid, p_subject uuid, p_from date, p_to date) RETURNS TABLE (student_id uuid, marks integer, ratio numeric,
  average numeric, scale_max numeric, passed boolean)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant();
BEGIN
  IF NOT school.manages_activity(p_group, p_subject) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  RETURN QUERY SELECT s.id, x.marks, x.ratio, x.average, x.scale_max, x.passed
  FROM (SELECT DISTINCT au.student_id AS id FROM school.activity_audience au WHERE au.tenant_id = t AND au.group_id = p_group AND au.subject_id = p_subject) s
  CROSS JOIN LATERAL school.average_of(t, s.id, p_group, p_subject, p_from, p_to) x
  ORDER BY s.id;
END $$;

-- The caller's own marks on their scales and averages over a period, subject by subject, from what
-- is published only (lot 5 §6), even once their access is withdrawn.
CREATE FUNCTION school.my_averages(p_from date, p_to date) RETURNS TABLE (group_id uuid, subject_id uuid, marks integer, ratio numeric, average numeric,
  scale_max numeric, passed boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT k.group_id, k.subject_id, x.marks, x.ratio, x.average, x.scale_max, x.passed
  FROM (SELECT DISTINCT m.group_id, m.subject_id FROM school.activity_mark m
        WHERE m.tenant_id = school.allowed_tenant() AND m.student_id = school.my_student() AND m.publications > 0) k
  CROSS JOIN LATERAL school.average_of(school.allowed_tenant(), school.my_student(), k.group_id, k.subject_id, p_from, p_to) x
  WHERE school.can('lms.activity.take') $$;

-- A published mark of the caller's on its activity's scale.
CREATE FUNCTION school.my_marks() RETURNS TABLE (activity_id uuid, mark numeric, scale_max numeric, passed boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT m.activity_id, school.on_scale(p.points / p.max_points, v.grading_rule_id), rv.scale_max, p.points / p.max_points >= rv.pass_ratio
  FROM school.activity_mark m
  JOIN school.activity_mark_publication p ON p.tenant_id = m.tenant_id AND p.mark_id = m.id AND p.number = m.publications AND p.status = 'graded'
  JOIN school.activity a ON a.tenant_id = m.tenant_id AND a.id = m.activity_id
  JOIN school.activity_version v ON v.tenant_id = a.tenant_id AND v.activity_id = a.id AND v.version = 1
  CROSS JOIN LATERAL school.rule_values(v.grading_rule_id) rv
  WHERE m.tenant_id = school.allowed_tenant() AND m.student_id = school.my_student() AND school.can('lms.activity.take') $$;

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('grading_rule', 'pedagogy', '{lms.grading.manage,audit.log.read}');
