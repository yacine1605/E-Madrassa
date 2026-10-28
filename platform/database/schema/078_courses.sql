-- Lot 5, the course (SRS 1.2, FR-LMS-01; SPECIFICATION_LOT5_PEDAGOGIE.md §2): a programme's subject
-- taught in chapters and lessons, in an explicit order, kept by the pedagogy manager. A lesson's
-- working copy is edited as a draft; each publication freezes a new version, visible from a moment
-- (now or scheduled, Algiers' time on screen), so that what a pupil read and what an activity or an
-- attempt used stay identifiable, never rewritten. Its content is a structured document (the
-- ProseMirror/Tiptap schema), validated here: no HTML, no script, no style; its images and links are
-- HTTPS addresses of the school's allow-list, never uploaded nor fetched. A review by another
-- manager is the school's choice (`lms.lesson.review_required`, off). A pupil reads the published
-- lessons of the programmes they learn in (077); a teacher, those of the subjects they are assigned.

-- A list of domain names (`drive.google.com`), each once: the school's allow-list of links.
ALTER TABLE platform.setting_definition DROP CONSTRAINT setting_definition_value_type_check;
ALTER TABLE platform.setting_definition ADD CONSTRAINT setting_definition_value_type_check
  CHECK (value_type IN ('integer', 'boolean', 'text', 'day_set', 'language_set', 'code_set', 'code', 'domain_set'));

-- Replaces 039's: a domain set is 1 to 50 lowercase names with a dot, each once.
CREATE OR REPLACE FUNCTION school.guard_setting() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE def platform.setting_definition; n numeric;
BEGIN
  SELECT * INTO def FROM platform.setting_definition WHERE key = NEW.key;
  IF NOT school.is_system() AND NOT school.can_everywhere(def.permission) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF NOT (CASE def.value_type
       WHEN 'boolean' THEN jsonb_typeof(NEW.value) = 'boolean'
       WHEN 'integer' THEN jsonb_typeof(NEW.value) = 'number'
       WHEN 'text' THEN jsonb_typeof(NEW.value) = 'string' AND length(NEW.value #>> '{}') <= coalesce(def.max_value, 1000)
       -- Days 0 (Sunday) to 6 (Saturday), each once, at least one.
       WHEN 'day_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) BETWEEN 1 AND 7
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) d WHERE jsonb_typeof(d) <> 'number' OR (d #>> '{}') NOT IN ('0', '1', '2', '3', '4', '5', '6'))
         AND (SELECT count(DISTINCT d #>> '{}') FROM jsonb_array_elements(NEW.value) d) = jsonb_array_length(NEW.value)
       WHEN 'language_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) BETWEEN 1 AND 3
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) l WHERE jsonb_typeof(l) <> 'string' OR (l #>> '{}') NOT IN ('fr', 'ar', 'en'))
         AND (SELECT count(DISTINCT l #>> '{}') FROM jsonb_array_elements(NEW.value) l) = jsonb_array_length(NEW.value)
       -- Codes of the definition's list, each once, at least one.
       WHEN 'code_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) >= 1
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) v WHERE jsonb_typeof(v) <> 'string' OR NOT ((v #>> '{}') = ANY (def.allowed_values)))
         AND (SELECT count(DISTINCT v #>> '{}') FROM jsonb_array_elements(NEW.value) v) = jsonb_array_length(NEW.value)
       WHEN 'code' THEN jsonb_typeof(NEW.value) = 'string' AND (NEW.value #>> '{}') = ANY (def.allowed_values)
       WHEN 'domain_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) BETWEEN 1 AND 50
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) v WHERE jsonb_typeof(v) <> 'string'
           OR (v #>> '{}') !~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$' OR length(v #>> '{}') > 253)
         AND (SELECT count(DISTINCT v #>> '{}') FROM jsonb_array_elements(NEW.value) v) = jsonb_array_length(NEW.value)
     END) THEN
    RAISE EXCEPTION 'setting_invalid' USING ERRCODE = '23514';
  END IF;
  IF def.value_type = 'integer' THEN
    n := (NEW.value #>> '{}')::numeric;
    IF n <> trunc(n) OR n < coalesce(def.min_value, n) OR n > coalesce(def.max_value, n) THEN
      RAISE EXCEPTION 'setting_invalid' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('lms.link_domains', 'domain_set', '["drive.google.com", "docs.google.com", "github.com", "onedrive.live.com", "1drv.ms"]', NULL, NULL,
   'tenant.settings.manage', '{"fr": "Domaines autorisés pour les liens et images des cours", "ar": "النطاقات المسموح بها لروابط الدروس وصورها"}'),
  ('lms.lesson.review_required', 'boolean', 'false', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Relecture des leçons avant publication", "ar": "مراجعة الدروس قبل نشرها"}');

-- Who keeps the courses: the pedagogy manager, for the whole school. Who reads them: the manager,
-- the direction (the published lessons), a teacher their subjects', a pupil their programmes'.
INSERT INTO platform.permission (code, module_key, sensitive, labels) VALUES
  ('lms.course.manage', 'lms', false, '{"fr": "Gérer les cours et les leçons", "ar": "تسيير المقررات والدروس"}'),
  ('lms.course.read', 'lms', false, '{"fr": "Consulter les cours publiés", "ar": "الاطلاع على المقررات المنشورة"}');
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES
  ('academic_hr', 'lms.course.manage'), ('academic_hr', 'lms.course.read'), ('direction', 'lms.course.read'),
  ('teacher', 'lms.course.read'), ('student', 'lms.course.read');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, p.permission_code FROM school.role r
JOIN platform.role_template_permission p ON p.role_code = r.template_code AND p.permission_code IN ('lms.course.manage', 'lms.course.read')
WHERE platform.tenant_mode(r.tenant_id) = 'active'
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = p.permission_code);

-- An address a course may hold, or why not: HTTPS, a host name without credentials, on the
-- school's allow-list or below one of its domains (`docs.google.com` is not `google.com.evil.dz`).
-- Never fetched.
CREATE FUNCTION school.lms_link_error(p_url text) RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE authority text; host text;
BEGIN
  IF p_url IS NULL OR length(p_url) > 2000 OR p_url !~ '^https://[^[:space:][:cntrl:]\\<>"]+$' THEN RETURN 'lms_link_invalid'; END IF;
  authority := substring(p_url FROM '^https://([^/?#]*)');
  IF authority IS NULL OR authority ~ '@' THEN RETURN 'lms_link_invalid'; END IF;
  host := lower(regexp_replace(authority, ':[0-9]{1,5}$', ''));
  IF host !~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$' THEN RETURN 'lms_link_invalid'; END IF;
  IF NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(coalesce(school.setting_value('lms.link_domains'), '[]')) d
                 WHERE host = d OR right(host, length(d) + 1) = '.' || d) THEN
    RETURN 'lms_link_domain_blocked';
  END IF;
  RETURN NULL;
END $$;

-- A node of a lesson's document, or why it is refused: only the schema's nodes, marks and
-- attributes, in their places, 20 levels deep at most. `p_kind` is what its parent holds: the
-- document, blocks, inline content, list items, or a code block's plain text.
CREATE FUNCTION school.lms_node_error(p jsonb, p_kind text, p_depth integer) RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE t text; a jsonb; child jsonb; m jsonb; e text; children text;
  invalid constant text := 'lms_content_invalid';
  aligns constant text[] := ARRAY['start', 'left', 'center', 'right', 'end', 'justify'];
BEGIN
  IF p_depth > 20 OR jsonb_typeof(p) IS DISTINCT FROM 'object' OR jsonb_typeof(p -> 'type') IS DISTINCT FROM 'string' THEN RETURN invalid; END IF;
  IF EXISTS (SELECT 1 FROM jsonb_object_keys(p) k WHERE k NOT IN ('type', 'attrs', 'content', 'marks', 'text')) THEN RETURN invalid; END IF;
  t := p ->> 'type';
  -- No attributes may come as null, {} or [] (PHP's empty array).
  a := CASE WHEN p -> 'attrs' IS NULL OR p -> 'attrs' IN ('null', '[]') THEN '{}'::jsonb ELSE p -> 'attrs' END;
  IF jsonb_typeof(a) <> 'object' THEN RETURN invalid; END IF;
  IF NOT (CASE p_kind
       WHEN 'doc' THEN t = 'doc'
       WHEN 'block' THEN t IN ('paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'codeBlock', 'horizontalRule', 'image')
       WHEN 'inline' THEN t IN ('text', 'hardBreak')
       WHEN 'item' THEN t = 'listItem'
       WHEN 'code' THEN t = 'text'
       ELSE false END) THEN
    RETURN invalid;
  END IF;
  IF t = 'text' THEN
    IF jsonb_typeof(p -> 'text') IS DISTINCT FROM 'string' OR length(p ->> 'text') NOT BETWEEN 1 AND 20000 OR p ? 'content' OR a <> '{}' THEN RETURN invalid; END IF;
    IF p ? 'marks' AND p -> 'marks' <> 'null' THEN
      IF p_kind = 'code' OR jsonb_typeof(p -> 'marks') <> 'array' THEN RETURN invalid; END IF;
      FOR m IN SELECT x FROM jsonb_array_elements(p -> 'marks') x LOOP
        IF jsonb_typeof(m) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(m) k WHERE k NOT IN ('type', 'attrs')) THEN RETURN invalid; END IF;
        IF m ->> 'type' = 'link' THEN
          IF jsonb_typeof(m -> 'attrs') IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(m -> 'attrs') k WHERE k NOT IN ('href', 'target'))
             OR NOT (coalesce(m #> '{attrs,target}', 'null') IN ('null', '"_blank"')) THEN
            RETURN invalid;
          END IF;
          e := school.lms_link_error(m #>> '{attrs,href}');
          IF e IS NOT NULL THEN RETURN e; END IF;
        ELSIF NOT (m ->> 'type' IN ('bold', 'italic', 'underline', 'strike', 'code', 'subscript', 'superscript'))
              OR coalesce(m -> 'attrs', 'null') NOT IN ('null', '{}', '[]') THEN
          RETURN invalid;
        END IF;
      END LOOP;
    END IF;
    RETURN NULL;
  END IF;
  IF p ? 'text' OR (p ? 'marks' AND p -> 'marks' NOT IN ('null', '[]')) THEN RETURN invalid; END IF;
  -- Each node's attributes, typed.
  IF EXISTS (SELECT 1 FROM jsonb_object_keys(a) k WHERE k <> ALL (CASE t
       WHEN 'paragraph' THEN ARRAY['textAlign'] WHEN 'heading' THEN ARRAY['level', 'textAlign'] WHEN 'orderedList' THEN ARRAY['start']
       WHEN 'codeBlock' THEN ARRAY['language'] WHEN 'image' THEN ARRAY['src', 'alt', 'title'] ELSE ARRAY[]::text[] END)) THEN
    RETURN invalid;
  END IF;
  IF a ? 'textAlign' AND a -> 'textAlign' <> 'null' AND NOT (jsonb_typeof(a -> 'textAlign') = 'string' AND (a ->> 'textAlign') = ANY (aligns)) THEN RETURN invalid; END IF;
  IF t = 'heading' AND NOT (jsonb_typeof(a -> 'level') IS NOT DISTINCT FROM 'number' AND (a ->> 'level') IN ('1', '2', '3', '4')) THEN RETURN invalid; END IF;
  IF a ? 'start' AND a -> 'start' <> 'null' AND NOT (jsonb_typeof(a -> 'start') = 'number' AND (a ->> 'start') ~ '^[1-9][0-9]{0,4}$') THEN RETURN invalid; END IF;
  IF a ? 'language' AND a -> 'language' <> 'null' AND NOT (jsonb_typeof(a -> 'language') = 'string' AND (a ->> 'language') ~ '^[a-z0-9+#-]{1,30}$') THEN RETURN invalid; END IF;
  IF t = 'image' THEN
    IF EXISTS (SELECT 1 FROM unnest(ARRAY['alt', 'title']) k WHERE a ? k AND a -> k <> 'null'
               AND NOT (jsonb_typeof(a -> k) = 'string' AND length(a ->> k) <= 300)) THEN
      RETURN invalid;
    END IF;
    e := school.lms_link_error(CASE WHEN jsonb_typeof(a -> 'src') = 'string' THEN a ->> 'src' END);
    IF e IS NOT NULL THEN RETURN e; END IF;
  END IF;
  -- Its children, of the kind it holds.
  children := CASE t WHEN 'doc' THEN 'block' WHEN 'blockquote' THEN 'block' WHEN 'listItem' THEN 'block' WHEN 'paragraph' THEN 'inline'
    WHEN 'heading' THEN 'inline' WHEN 'bulletList' THEN 'item' WHEN 'orderedList' THEN 'item' WHEN 'codeBlock' THEN 'code' END;
  IF p ? 'content' AND p -> 'content' <> 'null' THEN
    IF children IS NULL OR jsonb_typeof(p -> 'content') <> 'array' THEN RETURN invalid; END IF;
    FOR child IN SELECT x FROM jsonb_array_elements(p -> 'content') x LOOP
      e := school.lms_node_error(child, children, p_depth + 1);
      IF e IS NOT NULL THEN RETURN e; END IF;
    END LOOP;
  END IF;
  IF t IN ('bulletList', 'orderedList', 'listItem', 'blockquote') AND jsonb_array_length(coalesce(nullif(p -> 'content', 'null'), '[]')) = 0 THEN RETURN invalid; END IF;
  RETURN NULL;
END $$;

-- A lesson's whole document, 512 KB at most, or why it is refused.
CREATE FUNCTION school.lms_content_error(p jsonb) RETURNS text LANGUAGE sql STABLE AS $$
  SELECT CASE WHEN p IS NULL OR length(p::text) > 524288 THEN 'lms_content_invalid' ELSE school.lms_node_error(p, 'doc', 0) END $$;

-- FR-LMS-01: a programme's subject, taught as one course. Its programme and subject never change.
CREATE TABLE school.course (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  program_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  description text CHECK (length(description) <= 2000),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, program_id, subject_id),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, program_id) REFERENCES school.program (tenant_id, id),
  FOREIGN KEY (tenant_id, subject_id) REFERENCES school.subject (tenant_id, id)
);
CALL school.protect('school.course');

-- A course's chapters, in their order: each a position from 1, never two the same (checked at the
-- transaction's end, so that a new order is written at once). Archived, it leaves the order and
-- the pupils' view, never deleted.
CREATE TABLE school.course_chapter (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  course_id uuid NOT NULL,
  program_id uuid NOT NULL,
  title text NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
  position integer CHECK (position >= 1),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK ((archived_at IS NULL) = (position IS NOT NULL)),
  CONSTRAINT course_chapter_position UNIQUE (tenant_id, course_id, position) DEFERRABLE INITIALLY DEFERRED,
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, course_id) REFERENCES school.course (tenant_id, id)
);
CALL school.protect('school.course_chapter');

-- A lesson's working copy, in its chapter's order. Its state is the working copy's: a draft, in
-- review (when the school asks for it), or published as it stands; its published versions are
-- `lesson_version`. The state, the review and the versions are the database's.
CREATE TABLE school.lesson (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  chapter_id uuid NOT NULL,
  course_id uuid NOT NULL,
  program_id uuid NOT NULL,
  position integer CHECK (position >= 1),
  title text NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
  objectives text CHECK (length(objectives) <= 2000),
  prerequisites text CHECK (length(prerequisites) <= 2000),
  duration_minutes integer CHECK (duration_minutes BETWEEN 1 AND 600),
  content jsonb NOT NULL DEFAULT '{"type": "doc", "content": [{"type": "paragraph"}]}',
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'in_review', 'published')),
  submitted_by bigint REFERENCES public.users(id),
  submitted_at timestamptz,
  published_version integer CHECK (published_version >= 1),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK ((archived_at IS NULL) = (position IS NOT NULL)),
  CHECK ((status = 'in_review') = (submitted_by IS NOT NULL AND submitted_at IS NOT NULL)),
  CHECK (status <> 'published' OR published_version IS NOT NULL),
  CONSTRAINT lesson_position UNIQUE (tenant_id, chapter_id, position) DEFERRABLE INITIALLY DEFERRED,
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, chapter_id) REFERENCES school.course_chapter (tenant_id, id)
);
CREATE INDEX lesson_course ON school.lesson (tenant_id, course_id);
CALL school.protect('school.lesson');

-- Each published version of a lesson, never changed: visible from `visible_from`, published by
-- whom, approved by whom when the school asks for a review. An activity or an attempt names the
-- version it used (lot 5's next increments).
CREATE TABLE school.lesson_version (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  lesson_id uuid NOT NULL,
  course_id uuid NOT NULL,
  program_id uuid NOT NULL,
  version integer NOT NULL CHECK (version >= 1),
  title text NOT NULL,
  objectives text,
  prerequisites text,
  duration_minutes integer,
  content jsonb NOT NULL,
  visible_from timestamptz NOT NULL,
  published_at timestamptz NOT NULL DEFAULT now(),
  published_by bigint REFERENCES public.users(id),
  submitted_by bigint REFERENCES public.users(id),
  approved_by bigint REFERENCES public.users(id),
  UNIQUE (tenant_id, lesson_id, version),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, lesson_id) REFERENCES school.lesson (tenant_id, id)
);
ALTER TABLE school.lesson_version ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.lesson_version FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.lesson_version USING (tenant_id = (SELECT school.allowed_tenant())) WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON school.lesson_version FOR EACH ROW EXECUTE FUNCTION school.guard_writable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.lesson_version FOR EACH ROW EXECUTE FUNCTION school.immutable();

-- The course: kept by `lms.course.manage` for the whole school; a subject its programme teaches.
CREATE FUNCTION school.guard_course() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require_everywhere('lms.course.manage');
  IF TG_OP = 'UPDATE' AND (NEW.program_id, NEW.subject_id) IS DISTINCT FROM (OLD.program_id, OLD.subject_id) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'INSERT' THEN NEW.archived_at := NULL; END IF;
  NEW.description := nullif(btrim(NEW.description), '');
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.course FOR EACH ROW EXECUTE FUNCTION school.guard_course();

-- Whatever the writer may read of the programmes: the subject is one the programme teaches, at
-- some date, still in the catalogue.
CREATE FUNCTION school.check_course() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM school.program_subject ps WHERE ps.tenant_id = NEW.tenant_id AND ps.program_id = NEW.program_id
                 AND ps.subject_id = NEW.subject_id AND ps.archived_at IS NULL) THEN
    RAISE EXCEPTION 'lms_subject_outside_program' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER rules BEFORE INSERT ON school.course FOR EACH ROW EXECUTE FUNCTION school.check_course();

-- A chapter: its course and programme kept, last in the order unless placed; archived for good.
CREATE FUNCTION school.guard_course_chapter() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE k school.course;
BEGIN
  PERFORM school.require_everywhere('lms.course.manage');
  IF TG_OP = 'INSERT' THEN
    SELECT * INTO k FROM school.course WHERE tenant_id = NEW.tenant_id AND id = NEW.course_id FOR UPDATE;
    IF k.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    NEW.program_id := k.program_id; NEW.archived_at := NULL;
    IF NEW.position IS NULL THEN
      SELECT coalesce(max(c.position), 0) + 1 INTO NEW.position FROM school.course_chapter c WHERE c.tenant_id = NEW.tenant_id AND c.course_id = NEW.course_id;
    END IF;
  ELSE
    IF (NEW.course_id, NEW.program_id) IS DISTINCT FROM (OLD.course_id, OLD.program_id) THEN RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514'; END IF;
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'lms_chapter_archived' USING ERRCODE = '23514'; END IF;
    IF NEW.archived_at IS NOT NULL THEN NEW.position := NULL; END IF;
  END IF;
  NEW.title := btrim(NEW.title);
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.course_chapter FOR EACH ROW EXECUTE FUNCTION school.guard_course_chapter();

-- A lesson's working copy: its chapter, course and programme kept, last in the order unless placed,
-- its document valid. A change to a lesson in review or published makes it a draft again; what
-- pupils read stays its last published version. Archived for good.
CREATE FUNCTION school.guard_lesson() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c school.course_chapter; e text;
BEGIN
  PERFORM school.require_everywhere('lms.course.manage');
  IF TG_OP = 'INSERT' THEN
    SELECT * INTO c FROM school.course_chapter WHERE tenant_id = NEW.tenant_id AND id = NEW.chapter_id;
    IF c.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    IF c.archived_at IS NOT NULL THEN RAISE EXCEPTION 'lms_chapter_archived' USING ERRCODE = '23514'; END IF;
    NEW.course_id := c.course_id; NEW.program_id := c.program_id; NEW.archived_at := NULL;
    NEW.status := 'draft'; NEW.submitted_by := NULL; NEW.submitted_at := NULL; NEW.published_version := NULL;
    IF NEW.position IS NULL THEN
      PERFORM 1 FROM school.course_chapter x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.chapter_id FOR UPDATE;
      SELECT coalesce(max(l.position), 0) + 1 INTO NEW.position FROM school.lesson l WHERE l.tenant_id = NEW.tenant_id AND l.chapter_id = NEW.chapter_id;
    END IF;
  ELSE
    IF (NEW.chapter_id, NEW.course_id, NEW.program_id) IS DISTINCT FROM (OLD.chapter_id, OLD.course_id, OLD.program_id) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'lms_lesson_archived' USING ERRCODE = '23514'; END IF;
    IF NEW.archived_at IS NOT NULL THEN NEW.position := NULL; END IF;
    IF NEW.status = OLD.status AND (NEW.title, NEW.objectives, NEW.prerequisites, NEW.duration_minutes, NEW.content)
       IS DISTINCT FROM (OLD.title, OLD.objectives, OLD.prerequisites, OLD.duration_minutes, OLD.content) THEN
      NEW.status := 'draft'; NEW.submitted_by := NULL; NEW.submitted_at := NULL;
    END IF;
  END IF;
  NEW.title := btrim(NEW.title);
  NEW.objectives := nullif(btrim(NEW.objectives), ''); NEW.prerequisites := nullif(btrim(NEW.prerequisites), '');
  IF TG_OP = 'INSERT' OR NEW.content IS DISTINCT FROM OLD.content THEN
    e := school.lms_content_error(NEW.content);
    IF e IS NOT NULL THEN RAISE EXCEPTION '%', e USING ERRCODE = '23514'; END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.lesson FOR EACH ROW EXECUTE FUNCTION school.guard_lesson();

-- The courses a reader reaches through their own records, as long as they read courses: a teacher
-- the subjects of the groups they are assigned to today (077), a pupil the programmes they learn
-- in by an enrolment granting access (FR-INS-04). Withdrawn access closes them at once.
CREATE FUNCTION school.lms_course_ids() RETURNS uuid[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT coalesce(array_agg(DISTINCT k.id), '{}') FROM school.course k
  WHERE k.tenant_id = school.allowed_tenant() AND k.archived_at IS NULL AND school.can('lms.course.read') AND (
    EXISTS (SELECT 1 FROM school.teaching_assignment a
      JOIN school.staff s ON s.tenant_id = a.tenant_id AND s.id = a.staff_id AND s.user_id = school.actor() AND s.status <> 'left' AND s.archived_at IS NULL
      JOIN school.study_group g ON g.tenant_id = a.tenant_id AND g.id = a.group_id
      WHERE a.tenant_id = k.tenant_id AND a.subject_id = k.subject_id AND g.program_id = k.program_id
        AND a.valid_from <= current_date AND (a.valid_to IS NULL OR a.valid_to >= current_date))
    OR EXISTS (SELECT 1 FROM school.learner_groups() lg JOIN school.enrollment e ON e.tenant_id = k.tenant_id AND e.id = lg.enrollment_id
      WHERE e.program_id = k.program_id)) $$;

-- Whether the caller reads a course's published lessons: the courses' manager or a reader for the
-- whole school (the direction), or one of the courses their own records reach.
CREATE FUNCTION school.course_readable(p_course uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT school.can_everywhere('lms.course.manage') OR school.can_everywhere('lms.course.read') OR p_course = ANY (school.lms_course_ids()) $$;

-- What a pupil reads of a course: each lesson in use, of a chapter in use, at its last version
-- visible now; nothing of a course out of the caller's reach. Drafts, reviews and versions to come
-- are the manager's only.
CREATE FUNCTION school.published_lessons(p_course uuid) RETURNS TABLE (lesson_id uuid, version_id uuid, version integer, chapter_id uuid,
  chapter_title text, chapter_position integer, lesson_position integer, title text, objectives text, prerequisites text, duration_minutes integer,
  content jsonb, visible_from timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT DISTINCT ON (c.position, l.position) l.id, v.id, v.version, c.id, c.title, c.position, l.position, v.title, v.objectives, v.prerequisites,
    v.duration_minutes, v.content, v.visible_from
  FROM school.course k
  JOIN school.course_chapter c ON c.tenant_id = k.tenant_id AND c.course_id = k.id AND c.archived_at IS NULL
  JOIN school.lesson l ON l.tenant_id = c.tenant_id AND l.chapter_id = c.id AND l.archived_at IS NULL
  JOIN school.lesson_version v ON v.tenant_id = l.tenant_id AND v.lesson_id = l.id AND v.visible_from <= now()
  WHERE k.tenant_id = school.allowed_tenant() AND k.id = p_course AND k.archived_at IS NULL AND school.course_readable(k.id)
  ORDER BY c.position, l.position, v.version DESC $$;

-- What a publication would publish, lesson by lesson, in the course's order, and what would stop
-- it: a lesson, a chapter or a whole course, its lessons in use. A lesson published as it stands
-- is left as it is; one with an invalid document or link (the allow-list may have changed), or
-- waiting for a review the school asks for, or submitted by the caller themselves, stops it all.
CREATE FUNCTION school.publication_preview(p_scope text, p_id uuid) RETURNS TABLE (lesson_id uuid, chapter_id uuid, title text, status text,
  published_version integer, publishes boolean, problem text)
LANGUAGE sql STABLE AS $$
  WITH review AS (SELECT coalesce((school.setting_value('lms.lesson.review_required') #>> '{}')::boolean, false) AS required)
  SELECT l.id, l.chapter_id, l.title, l.status, l.published_version, l.status <> 'published',
    CASE WHEN l.status = 'published' THEN NULL
         WHEN school.lms_content_error(l.content) IS NOT NULL THEN school.lms_content_error(l.content)
         WHEN r.required AND l.status <> 'in_review' THEN 'lms_review_required'
         WHEN r.required AND l.submitted_by = school.actor() THEN 'lms_review_self'
    END
  FROM school.lesson l
  JOIN school.course_chapter c ON c.tenant_id = l.tenant_id AND c.id = l.chapter_id AND c.archived_at IS NULL
  CROSS JOIN review r
  WHERE l.tenant_id = school.allowed_tenant() AND l.archived_at IS NULL
    AND CASE p_scope WHEN 'lesson' THEN l.id = p_id WHEN 'chapter' THEN l.chapter_id = p_id WHEN 'course' THEN l.course_id = p_id ELSE false END
  ORDER BY c.position, l.position $$;

-- FR-LMS-01: publishes a lesson, a chapter or a course, all of it or nothing, each lesson changed
-- since its last version as a new version, visible now or from a later moment. With its owner's
-- rights, for `lms.course.manage` holders only: the versions and the lessons' states are written by
-- the database alone. One publication at a time per course.
CREATE FUNCTION school.publish_lessons(p_scope text, p_id uuid, p_visible_from timestamptz DEFAULT NULL)
RETURNS TABLE (lesson_id uuid, version integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
#variable_conflict use_column
DECLARE t uuid := school.allowed_tenant(); v_at timestamptz := coalesce(p_visible_from, now()); v_course uuid; problem text; r record; n integer;
  reviewed boolean := coalesce((school.setting_value('lms.lesson.review_required') #>> '{}')::boolean, false);
BEGIN
  PERFORM school.require_everywhere('lms.course.manage');
  IF v_at < now() - interval '1 minute' THEN RAISE EXCEPTION 'lms_publish_past' USING ERRCODE = '23514'; END IF;
  v_course := CASE p_scope
    WHEN 'lesson' THEN (SELECT l.course_id FROM school.lesson l WHERE l.tenant_id = t AND l.id = p_id)
    WHEN 'chapter' THEN (SELECT c.course_id FROM school.course_chapter c WHERE c.tenant_id = t AND c.id = p_id)
    WHEN 'course' THEN (SELECT k.id FROM school.course k WHERE k.tenant_id = t AND k.id = p_id) END;
  IF v_course IS NULL THEN RAISE EXCEPTION 'lms_publication_empty' USING ERRCODE = '23514'; END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended('lms_publish:' || v_course::text, 78));
  SELECT p.problem INTO problem FROM school.publication_preview(p_scope, p_id) p WHERE p.problem IS NOT NULL LIMIT 1;
  IF problem IS NOT NULL THEN RAISE EXCEPTION '%', problem USING ERRCODE = '23514'; END IF;
  IF NOT EXISTS (SELECT 1 FROM school.publication_preview(p_scope, p_id)) THEN RAISE EXCEPTION 'lms_publication_empty' USING ERRCODE = '23514'; END IF;
  FOR r IN SELECT l.* FROM school.lesson l WHERE l.tenant_id = t AND l.id IN (SELECT p.lesson_id FROM school.publication_preview(p_scope, p_id) p WHERE p.publishes)
           ORDER BY l.id FOR UPDATE LOOP
    n := coalesce(r.published_version, 0) + 1;
    INSERT INTO school.lesson_version (tenant_id, lesson_id, course_id, program_id, version, title, objectives, prerequisites, duration_minutes, content,
      visible_from, published_by, submitted_by, approved_by)
    VALUES (t, r.id, r.course_id, r.program_id, n, r.title, r.objectives, r.prerequisites, r.duration_minutes, r.content,
      v_at, school.actor(), r.submitted_by, CASE WHEN reviewed THEN school.actor() END);
    UPDATE school.lesson SET status = 'published', published_version = n, submitted_by = NULL, submitted_at = NULL WHERE tenant_id = t AND id = r.id;
    lesson_id := r.id; version := n;
    RETURN NEXT;
  END LOOP;
END $$;

-- A draft submitted for review, when the school asks for one; by a manager, approved by another.
CREATE FUNCTION school.submit_lesson(p_lesson uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE l school.lesson; e text;
BEGIN
  PERFORM school.require_everywhere('lms.course.manage');
  IF NOT coalesce((school.setting_value('lms.lesson.review_required') #>> '{}')::boolean, false) THEN
    RAISE EXCEPTION 'lms_review_off' USING ERRCODE = '23514';
  END IF;
  SELECT * INTO l FROM school.lesson WHERE tenant_id = school.allowed_tenant() AND id = p_lesson FOR UPDATE;
  IF l.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF l.archived_at IS NOT NULL THEN RAISE EXCEPTION 'lms_lesson_archived' USING ERRCODE = '23514'; END IF;
  IF l.status <> 'draft' THEN RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514'; END IF;
  e := school.lms_content_error(l.content);
  IF e IS NOT NULL THEN RAISE EXCEPTION '%', e USING ERRCODE = '23514'; END IF;
  UPDATE school.lesson SET status = 'in_review', submitted_by = school.actor(), submitted_at = now() WHERE tenant_id = l.tenant_id AND id = l.id;
END $$;

-- Who reads: the courses through `lms.course.manage` or `lms.course.read` for the whole school, or
-- those the reader's own records reach; the chapters, working copies and versions, the manager
-- only (the others read the published lessons through `school.published_lessons`). Written by the
-- manager; the versions by the publication alone.
CREATE POLICY resource_reader ON school.course AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('lms.course.manage')) OR (SELECT school.can_everywhere('lms.course.read'))
  OR id IN (SELECT unnest((SELECT school.lms_course_ids()))));
CREATE POLICY resource_creator ON school.course AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere('lms.course.manage')));
CREATE POLICY resource_writer ON school.course AS RESTRICTIVE FOR UPDATE TO school_runtime USING ((SELECT school.can_everywhere('lms.course.manage')));
CREATE POLICY resource_reader ON school.course_chapter AS RESTRICTIVE FOR ALL TO school_runtime USING ((SELECT school.can_everywhere('lms.course.manage')))
  WITH CHECK ((SELECT school.can_everywhere('lms.course.manage')));
CREATE POLICY resource_reader ON school.lesson AS RESTRICTIVE FOR ALL TO school_runtime USING ((SELECT school.can_everywhere('lms.course.manage')))
  WITH CHECK ((SELECT school.can_everywhere('lms.course.manage')));
CREATE POLICY resource_reader ON school.lesson_version AS RESTRICTIVE FOR SELECT TO school_runtime USING ((SELECT school.can_everywhere('lms.course.manage')));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('course', 'pedagogy', '{lms.course.manage,audit.log.read}'), ('course_chapter', 'pedagogy', '{lms.course.manage,audit.log.read}'),
  ('lesson', 'pedagogy', '{lms.course.manage,audit.log.read}');
