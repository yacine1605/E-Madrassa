-- Lot 5, the teachers' own resources (SRS 1.2, FR-LMS-01; SPECIFICATION_LOT5_PEDAGOGIE.md §2): a
-- link a teacher adds to a lesson pupils can read, published at once without review. Never a file:
-- an HTTPS address of the school's allow-list (078), never fetched. A teacher adds them to the
-- courses of the subjects they are assigned to, and withdraws only their own; the pedagogy manager
-- withdraws any. Withdrawn, never deleted; the journal keeps who added and withdrew what.

INSERT INTO platform.permission (code, module_key, sensitive, labels) VALUES
  ('lms.resource.manage', 'lms', false, '{"fr": "Ajouter des ressources complémentaires", "ar": "إضافة موارد تكميلية"}');
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES ('teacher', 'lms.resource.manage'), ('academic_hr', 'lms.resource.manage');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, 'lms.resource.manage' FROM school.role r
WHERE r.template_code IN ('teacher', 'academic_hr') AND platform.tenant_mode(r.tenant_id) = 'active'
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = 'lms.resource.manage');

CREATE TABLE school.lesson_resource (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  lesson_id uuid NOT NULL,
  course_id uuid NOT NULL,
  title text NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
  url text NOT NULL CHECK (length(url) <= 2000),
  withdrawn_by bigint REFERENCES public.users(id),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK ((archived_at IS NULL) = (withdrawn_by IS NULL)),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, lesson_id) REFERENCES school.lesson (tenant_id, id)
);
CREATE INDEX lesson_resource_course ON school.lesson_resource (tenant_id, course_id) WHERE archived_at IS NULL;
CALL school.protect('school.lesson_resource');

-- Whether a lesson takes resources: in use, of a chapter in use, with a version pupils read now.
CREATE FUNCTION school.lesson_visible(p_lesson uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM school.lesson l
    JOIN school.course_chapter c ON c.tenant_id = l.tenant_id AND c.id = l.chapter_id AND c.archived_at IS NULL
    WHERE l.tenant_id = school.allowed_tenant() AND l.id = p_lesson AND l.archived_at IS NULL
      AND EXISTS (SELECT 1 FROM school.lesson_version v WHERE v.tenant_id = l.tenant_id AND v.lesson_id = l.id AND v.visible_from <= now())) $$;

-- Whether the caller teaches a course's subject today in one of its programme's groups (077).
CREATE FUNCTION school.teaches_course(p_course uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM school.course k
    JOIN school.study_group g ON g.tenant_id = k.tenant_id AND g.program_id = k.program_id
    WHERE k.tenant_id = school.allowed_tenant() AND k.id = p_course AND school.teaches(g.id, k.subject_id)) $$;

-- The course of a lesson, whatever the writer may read of the lessons.
CREATE FUNCTION school.lesson_course(p_lesson uuid) RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT l.course_id FROM school.lesson l WHERE l.tenant_id = school.allowed_tenant() AND l.id = p_lesson $$;

-- Added by `lms.resource.manage`, to a visible lesson of a course the writer teaches (or by the
-- courses' manager); its lesson, course, title and address never change; withdrawn by its author
-- or the manager, for good. With the writer's rights: the permission is theirs.
CREATE FUNCTION school.guard_lesson_resource() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e text;
BEGIN
  PERFORM school.require('lms.resource.manage');
  IF TG_OP = 'INSERT' THEN
    NEW.course_id := school.lesson_course(NEW.lesson_id);
    IF NEW.course_id IS NULL OR NOT (school.can_everywhere('lms.course.manage') OR school.teaches_course(NEW.course_id)) THEN
      RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501';
    END IF;
    IF NOT school.lesson_visible(NEW.lesson_id) THEN RAISE EXCEPTION 'lms_lesson_not_visible' USING ERRCODE = '23514'; END IF;
    NEW.title := btrim(NEW.title); NEW.url := btrim(NEW.url); NEW.archived_at := NULL; NEW.withdrawn_by := NULL;
    e := school.lms_link_error(NEW.url);
    IF e IS NOT NULL THEN RAISE EXCEPTION '%', e USING ERRCODE = '23514'; END IF;
    RETURN NEW;
  END IF;
  IF (NEW.lesson_id, NEW.course_id, NEW.title, NEW.url) IS DISTINCT FROM (OLD.lesson_id, OLD.course_id, OLD.title, OLD.url) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'lms_resource_withdrawn' USING ERRCODE = '23514'; END IF;
  IF NOT (school.can_everywhere('lms.course.manage') OR OLD.created_by = school.actor()) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF NEW.archived_at IS NOT NULL THEN NEW.withdrawn_by := school.actor(); END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.lesson_resource FOR EACH ROW EXECUTE FUNCTION school.guard_lesson_resource();

-- The resources of a course's visible lessons, for whoever reads its published lessons (078),
-- each with its author's name and whether the caller may withdraw it.
CREATE FUNCTION school.lesson_resources(p_course uuid) RETURNS TABLE (id uuid, lesson_id uuid, title text, url text, author text,
  created_at timestamptz, withdrawable boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT r.id, r.lesson_id, r.title, r.url, u.name, r.created_at,
    school.can_everywhere('lms.course.manage') OR (r.created_by = school.actor() AND school.can('lms.resource.manage'))
  FROM school.lesson_resource r LEFT JOIN public.users u ON u.id = r.created_by
  WHERE r.tenant_id = school.allowed_tenant() AND r.course_id = p_course AND r.archived_at IS NULL
    AND school.course_readable(p_course) AND school.lesson_visible(r.lesson_id)
  ORDER BY r.created_at, r.id $$;

-- Read directly by the manager and by each author for their own; everyone else through the function.
CREATE POLICY resource_reader ON school.lesson_resource AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('lms.course.manage')) OR created_by = (SELECT school.actor()));
CREATE POLICY resource_writer ON school.lesson_resource AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('lms.course.manage')) OR created_by = (SELECT school.actor()));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('lesson_resource', 'pedagogy', '{lms.course.manage,audit.log.read}');
