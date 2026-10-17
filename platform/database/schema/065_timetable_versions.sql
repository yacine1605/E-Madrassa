-- Lot 4, versions and publication (SRS 1.2, FR-PLN-05): the school's timetable over a period is
-- prepared as a version, a copy of its sessions with its reason and author; validated, then
-- published by `schedule.timetable.publish`, never with a blocking conflict (AC1) nor with sessions
-- changed since its copy. Publishing tells the teachers and pupils whose sessions it changes; a
-- session's cancellation or replacement is told only once its day is published (AC2, BR-19).
-- Versions are kept, never deleted, and compared with one another or with the sessions as they are.

INSERT INTO platform.notification_event (code, labels, email_default) VALUES
  ('schedule.timetable_published', '{"fr": "Un emploi du temps a été publié", "ar": "نُشر جدول التوقيت"}', true);

-- A version: its number in the school, its period, its reason; draft, validated, published,
-- superseded by a later publication over its days, or discarded before publication.
CREATE TABLE school.timetable_version (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  number integer NOT NULL CHECK (number > 0),
  date_from date NOT NULL,
  date_to date NOT NULL,
  reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 1000),
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'validated', 'published', 'superseded', 'discarded')),
  copied_at timestamptz NOT NULL,
  validated_at timestamptz,
  validated_by bigint REFERENCES public.users(id),
  published_at timestamptz,
  published_by bigint REFERENCES public.users(id),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (date_to >= date_from AND date_to - date_from <= 400),
  CHECK ((status IN ('validated', 'published', 'superseded')) = (validated_at IS NOT NULL)),
  CHECK ((status IN ('published', 'superseded')) = (published_at IS NOT NULL)),
  UNIQUE (tenant_id, number),
  UNIQUE (tenant_id, id)
);
CALL school.protect('school.timetable_version');

-- Its copy of the sessions not cancelled over its period, as they stood when copied. A session's
-- identifier only: a session its series removed since stays in the versions that had it.
CREATE TABLE school.timetable_version_session (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  version_id uuid NOT NULL,
  session_id uuid NOT NULL,
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  teacher_id uuid NOT NULL,
  room_id uuid,
  starts_at timestamptz NOT NULL,
  ends_at timestamptz NOT NULL,
  status text NOT NULL,
  PRIMARY KEY (tenant_id, version_id, session_id),
  FOREIGN KEY (tenant_id, version_id) REFERENCES school.timetable_version (tenant_id, id)
);
CREATE INDEX timetable_version_session_group ON school.timetable_version_session (tenant_id, group_id, starts_at);
ALTER TABLE school.timetable_version_session ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.timetable_version_session FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.timetable_version_session USING (tenant_id = (SELECT school.allowed_tenant()))
  WITH CHECK (tenant_id = (SELECT school.allowed_tenant()));

-- A version and its copy change through the functions below only.
CREATE FUNCTION school.guard_timetable_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF coalesce(current_setting('app.timetable_change', true), '') <> 'on' THEN RAISE EXCEPTION 'timetable_version_refused' USING ERRCODE = '23514'; END IF;
  IF TG_TABLE_NAME = 'timetable_version' AND TG_OP = 'UPDATE' THEN
    IF (NEW.number, NEW.date_from, NEW.date_to, NEW.reason, NEW.archived_at) IS DISTINCT FROM (OLD.number, OLD.date_from, OLD.date_to, OLD.reason, OLD.archived_at) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.timetable_version FOR EACH ROW EXECUTE FUNCTION school.guard_timetable_version();
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.timetable_version_session FOR EACH ROW EXECUTE FUNCTION school.guard_timetable_version();

-- A day of a published timetable: covered by a version published, or published then superseded.
CREATE FUNCTION school.published_on(p_day date) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT EXISTS (SELECT 1 FROM school.timetable_version v WHERE v.tenant_id = school.tenant() AND v.status IN ('published', 'superseded')
    AND p_day BETWEEN v.date_from AND v.date_to) $$;

-- The version's copy taken again from the sessions not cancelled over its period.
CREATE FUNCTION school.copy_timetable(p_version uuid) RETURNS integer LANGUAGE plpgsql AS $$
DECLARE v school.timetable_version; copied integer;
BEGIN
  PERFORM school.require_everywhere('schedule.session.update');
  SELECT * INTO v FROM school.timetable_version x WHERE x.tenant_id = school.tenant() AND x.id = p_version;
  IF v.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF v.status <> 'draft' THEN RAISE EXCEPTION 'timetable_version_closed' USING ERRCODE = '23514'; END IF;
  PERFORM set_config('app.timetable_change', 'on', true);
  DELETE FROM school.timetable_version_session WHERE tenant_id = v.tenant_id AND version_id = v.id;
  INSERT INTO school.timetable_version_session (tenant_id, version_id, session_id, group_id, subject_id, teacher_id, room_id, starts_at, ends_at, status)
  SELECT c.tenant_id, v.id, c.id, c.group_id, c.subject_id, c.teacher_id, c.room_id, c.starts_at, c.ends_at, c.status FROM school.class_session c
  WHERE c.tenant_id = v.tenant_id AND c.status <> 'cancelled'
    AND c.starts_at >= school.local_at(v.date_from, '00:00') AND c.starts_at < school.local_at(v.date_to + 1, '00:00');
  GET DIAGNOSTICS copied = ROW_COUNT;
  PERFORM set_config('app.timetable_change', '', true);
  RETURN copied;
END $$;

-- FR-PLN-05: the differences of a version with another, or with the sessions as they are now
-- (`p_other` null), over the first one's period: each session added, removed or changed, as it was
-- in the first and as it is in the second.
CREATE FUNCTION school.timetable_diff(p_version uuid, p_other uuid)
RETURNS TABLE (session_id uuid, change text, before jsonb, after jsonb) LANGUAGE sql STABLE AS $$
  WITH v AS (SELECT * FROM school.timetable_version x WHERE x.tenant_id = school.tenant() AND x.id = p_version),
  a AS (SELECT s.session_id, jsonb_build_object('group_id', s.group_id, 'subject_id', s.subject_id, 'teacher_id', s.teacher_id, 'room_id', s.room_id,
          'starts_at', s.starts_at, 'ends_at', s.ends_at, 'status', s.status) AS row
        FROM school.timetable_version_session s JOIN v ON s.tenant_id = v.tenant_id AND s.version_id = v.id),
  b AS (SELECT s.session_id, jsonb_build_object('group_id', s.group_id, 'subject_id', s.subject_id, 'teacher_id', s.teacher_id, 'room_id', s.room_id,
          'starts_at', s.starts_at, 'ends_at', s.ends_at, 'status', s.status) AS row
        FROM school.timetable_version_session s JOIN v ON s.tenant_id = v.tenant_id
        WHERE p_other IS NOT NULL AND s.version_id = p_other
          AND s.starts_at >= school.local_at(v.date_from, '00:00') AND s.starts_at < school.local_at(v.date_to + 1, '00:00')
        UNION ALL
        SELECT c.id, jsonb_build_object('group_id', c.group_id, 'subject_id', c.subject_id, 'teacher_id', c.teacher_id, 'room_id', c.room_id,
          'starts_at', c.starts_at, 'ends_at', c.ends_at, 'status', c.status)
        FROM school.class_session c JOIN v ON c.tenant_id = v.tenant_id
        WHERE p_other IS NULL AND c.status <> 'cancelled'
          AND c.starts_at >= school.local_at(v.date_from, '00:00') AND c.starts_at < school.local_at(v.date_to + 1, '00:00'))
  SELECT coalesce(a.session_id, b.session_id), CASE WHEN a.session_id IS NULL THEN 'added' WHEN b.session_id IS NULL THEN 'removed' ELSE 'changed' END,
    a.row, b.row
  FROM a FULL JOIN b ON b.session_id = a.session_id WHERE a.row IS DISTINCT FROM b.row $$;

-- AC1: the blocking conflicts of a version's sessions, checked again as things are now (a pupil
-- joined a group since, a qualification ended, a room closed).
CREATE FUNCTION school.timetable_conflicts(p_version uuid) RETURNS TABLE (session_id uuid, conflict text, detail jsonb) LANGUAGE sql AS $$
  SELECT s.session_id, x.conflict, x.detail FROM school.timetable_version v
  JOIN school.timetable_version_session s ON s.tenant_id = v.tenant_id AND s.version_id = v.id
  CROSS JOIN LATERAL school.session_conflicts(s.group_id, s.subject_id, s.teacher_id, s.room_id, s.starts_at, s.ends_at, s.session_id) x
  WHERE v.tenant_id = school.tenant() AND v.id = p_version AND x.blocking
  ORDER BY s.starts_at, s.session_id, x.conflict $$;

-- Prepares a version over a period, with its reason: one at a time open over the same days.
CREATE FUNCTION school.prepare_timetable(p_from date, p_to date, p_reason text) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE t uuid := school.tenant(); v uuid; n integer;
BEGIN
  PERFORM school.require_everywhere('schedule.session.update');
  PERFORM school.require_everywhere('schedule.session.read');
  IF p_from IS NULL OR p_to IS NULL OR p_to < p_from OR p_to - p_from > 400 THEN RAISE EXCEPTION 'timetable_period_invalid' USING ERRCODE = '23514'; END IF;
  IF nullif(btrim(p_reason), '') IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended('timetable_version:' || t::text, 65));
  IF EXISTS (SELECT 1 FROM school.timetable_version x WHERE x.tenant_id = t AND x.status IN ('draft', 'validated')
             AND daterange(x.date_from, x.date_to, '[]') && daterange(p_from, p_to, '[]')) THEN
    RAISE EXCEPTION 'timetable_version_open' USING ERRCODE = '23514';
  END IF;
  SELECT coalesce(max(x.number), 0) + 1 INTO n FROM school.timetable_version x WHERE x.tenant_id = t;
  PERFORM set_config('app.timetable_change', 'on', true);
  INSERT INTO school.timetable_version (tenant_id, number, date_from, date_to, reason, copied_at)
  VALUES (t, n, p_from, p_to, btrim(p_reason), now()) RETURNING id INTO v;
  PERFORM school.copy_timetable(v);
  RETURN v;
END $$;

-- The version's state changed by its author's functions, its version number checked.
CREATE FUNCTION school.timetable_version_for(p_version uuid, p_number integer) RETURNS school.timetable_version LANGUAGE plpgsql AS $$
DECLARE v school.timetable_version;
BEGIN
  SELECT * INTO v FROM school.timetable_version x WHERE x.tenant_id = school.tenant() AND x.id = p_version FOR UPDATE;
  IF v.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF v.version <> p_number THEN RAISE EXCEPTION 'stale_version' USING ERRCODE = '23514'; END IF;
  RETURN v;
END $$;

-- A draft or validated version copied again: it is a draft again, to validate anew.
CREATE FUNCTION school.refresh_timetable(p_version uuid, p_number integer) RETURNS integer LANGUAGE plpgsql AS $$
DECLARE v school.timetable_version;
BEGIN
  PERFORM school.require_everywhere('schedule.session.update');
  v := school.timetable_version_for(p_version, p_number);
  IF v.status NOT IN ('draft', 'validated') THEN RAISE EXCEPTION 'timetable_version_closed' USING ERRCODE = '23514'; END IF;
  PERFORM set_config('app.timetable_change', 'on', true);
  UPDATE school.timetable_version SET status = 'draft', validated_at = NULL, validated_by = NULL, copied_at = now(), version = p_number
  WHERE tenant_id = v.tenant_id AND id = v.id;
  RETURN school.copy_timetable(v.id);
END $$;

-- What a version's validation and publication check: its copy still the sessions as they are,
-- and none of them in a blocking conflict (AC1).
CREATE FUNCTION school.check_timetable(p_version uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE conflicts integer;
BEGIN
  IF EXISTS (SELECT 1 FROM school.timetable_diff(p_version, NULL)) THEN RAISE EXCEPTION 'timetable_version_stale' USING ERRCODE = '23514'; END IF;
  SELECT count(DISTINCT session_id) INTO conflicts FROM school.timetable_conflicts(p_version);
  IF conflicts > 0 THEN
    RAISE EXCEPTION 'timetable_conflicts' USING ERRCODE = '23514', DETAIL = jsonb_build_object('sessions', conflicts)::text;
  END IF;
END $$;

CREATE FUNCTION school.validate_timetable(p_version uuid, p_number integer) RETURNS void LANGUAGE plpgsql AS $$
DECLARE v school.timetable_version;
BEGIN
  PERFORM school.require_everywhere('schedule.timetable.publish');
  v := school.timetable_version_for(p_version, p_number);
  IF v.status <> 'draft' THEN RAISE EXCEPTION 'timetable_version_closed' USING ERRCODE = '23514'; END IF;
  PERFORM school.check_timetable(v.id);
  PERFORM set_config('app.timetable_change', 'on', true);
  UPDATE school.timetable_version SET status = 'validated', validated_at = now(), validated_by = school.actor(), version = p_number
  WHERE tenant_id = v.tenant_id AND id = v.id;
  PERFORM set_config('app.timetable_change', '', true);
END $$;

-- Publishes a validated version: the versions published over its days are superseded, and the
-- teachers and pupils whose sessions it changes are told (AC2: nothing before).
CREATE FUNCTION school.publish_timetable(p_version uuid, p_number integer) RETURNS integer LANGUAGE plpgsql AS $$
DECLARE v school.timetable_version; previous uuid[];
BEGIN
  PERFORM school.require_everywhere('schedule.timetable.publish');
  v := school.timetable_version_for(p_version, p_number);
  IF v.status <> 'validated' THEN RAISE EXCEPTION 'timetable_version_not_validated' USING ERRCODE = '23514'; END IF;
  PERFORM school.check_timetable(v.id);
  PERFORM set_config('app.timetable_change', 'on', true);
  WITH superseded AS (
    UPDATE school.timetable_version x SET status = 'superseded', version = x.version
    WHERE x.tenant_id = v.tenant_id AND x.status = 'published' AND daterange(x.date_from, x.date_to, '[]') && daterange(v.date_from, v.date_to, '[]')
    RETURNING x.id)
  SELECT coalesce(array_agg(id), '{}') INTO previous FROM superseded;
  UPDATE school.timetable_version SET status = 'published', published_at = now(), published_by = school.actor(), version = p_number
  WHERE tenant_id = v.tenant_id AND id = v.id;
  PERFORM set_config('app.timetable_change', '', true);
  RETURN school.notify_timetable(v.id, previous);
END $$;

-- A version not yet published set aside; it stays, discarded.
CREATE FUNCTION school.discard_timetable(p_version uuid, p_number integer) RETURNS void LANGUAGE plpgsql AS $$
DECLARE v school.timetable_version;
BEGIN
  PERFORM school.require_everywhere('schedule.session.update');
  v := school.timetable_version_for(p_version, p_number);
  IF v.status NOT IN ('draft', 'validated') THEN RAISE EXCEPTION 'timetable_version_closed' USING ERRCODE = '23514'; END IF;
  PERFORM set_config('app.timetable_change', 'on', true);
  UPDATE school.timetable_version SET status = 'discarded', validated_at = NULL, validated_by = NULL, version = p_number WHERE tenant_id = v.tenant_id AND id = v.id;
  PERFORM set_config('app.timetable_change', '', true);
END $$;

-- Who a publication tells: the teachers (before and after) and the pupils of the groups, that day,
-- of each session it adds, removes or changes against the versions it supersedes, through their
-- accounts, if they read the planning; the publisher aside. With its owner's rights: the publisher
-- may not read the pupils nor the staff's accounts. Returns how many were told.
CREATE FUNCTION school.notify_timetable(p_version uuid, p_previous uuid[]) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE v school.timetable_version; people bigint[];
BEGIN
  -- Only within the publication's own transaction: a publication is told once.
  SELECT * INTO v FROM school.timetable_version x WHERE x.tenant_id = school.tenant() AND x.id = p_version AND x.status = 'published'
    AND x.published_at = now() AND school.can_everywhere('schedule.timetable.publish');
  IF v.id IS NULL THEN RETURN 0; END IF;
  WITH after AS (SELECT * FROM school.timetable_version_session s WHERE s.tenant_id = v.tenant_id AND s.version_id = v.id),
  before AS (SELECT DISTINCT ON (s.session_id) s.* FROM school.timetable_version_session s
    WHERE s.tenant_id = v.tenant_id AND s.version_id = ANY (p_previous)
      AND s.starts_at >= school.local_at(v.date_from, '00:00') AND s.starts_at < school.local_at(v.date_to + 1, '00:00')
    ORDER BY s.session_id, s.version_id),
  changed AS (SELECT n.teacher_id AS now_teacher, b.teacher_id AS was_teacher, coalesce(n.group_id, b.group_id) AS group_id,
      school.local_day(coalesce(n.starts_at, b.starts_at)) AS day
    FROM after n FULL JOIN before b ON b.session_id = n.session_id
    WHERE (n.group_id, n.subject_id, n.teacher_id, n.room_id, n.starts_at, n.ends_at, n.status)
      IS DISTINCT FROM (b.group_id, b.subject_id, b.teacher_id, b.room_id, b.starts_at, b.ends_at, b.status)),
  told AS (
    SELECT s.user_id AS u FROM school.staff s WHERE s.tenant_id = v.tenant_id AND s.user_id IS NOT NULL
      AND s.id IN (SELECT now_teacher FROM changed UNION SELECT was_teacher FROM changed)
    UNION SELECT st.user_id FROM changed c JOIN school.group_membership m ON m.tenant_id = v.tenant_id AND m.group_id = c.group_id
      AND m.valid_from <= c.day AND (m.valid_to IS NULL OR m.valid_to >= c.day)
      JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id
      JOIN school.student st ON st.tenant_id = e.tenant_id AND st.id = e.student_id AND st.user_id IS NOT NULL)
  SELECT array_agg(DISTINCT t.u) INTO people FROM told t
  WHERE t.u IS DISTINCT FROM school.actor()
    AND EXISTS (SELECT 1 FROM school.effective_permissions(t.u, v.tenant_id) p WHERE p.permission = 'schedule.session.read');
  IF people IS NULL THEN RETURN 0; END IF;
  RETURN school.notify('schedule.timetable_published', people, jsonb_build_object('version_id', v.id, 'number', v.number,
    'from', v.date_from, 'to', v.date_to));
END $$;

-- AC2: 064's notices, now only for a session whose day is published.
CREATE OR REPLACE FUNCTION school.record_session_change() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE kind text; day date := school.local_day(NEW.starts_at); people bigint[];
BEGIN
  IF school.tenant() IS DISTINCT FROM NEW.tenant_id THEN RETURN NULL; END IF;
  kind := CASE WHEN NEW.status = 'cancelled' AND OLD.status <> 'cancelled' THEN 'cancelled'
               WHEN NEW.status = 'replaced' AND NEW.teacher_id IS DISTINCT FROM OLD.teacher_id THEN 'replaced'
               WHEN OLD.status = 'replaced' AND NEW.status = 'planned' THEN 'restored' END;
  IF kind IS NULL THEN RETURN NULL; END IF;
  INSERT INTO school.session_change (tenant_id, session_id, group_id, kind, teacher_id, new_teacher_id, reason, changed_by)
  VALUES (NEW.tenant_id, NEW.id, NEW.group_id, kind, OLD.teacher_id, CASE WHEN kind <> 'cancelled' THEN NEW.teacher_id END,
    CASE WHEN kind = 'cancelled' THEN NEW.cancel_reason ELSE NEW.replace_reason END, school.actor());
  IF NEW.ends_at <= now() OR NOT school.published_on(day) THEN RETURN NULL; END IF;
  SELECT array_agg(DISTINCT x.u) INTO people FROM (
    SELECT s.user_id AS u FROM school.staff s WHERE s.tenant_id = NEW.tenant_id AND s.user_id IS NOT NULL
      AND s.id IN (OLD.teacher_id, NEW.teacher_id, OLD.replaced_teacher_id, NEW.replaced_teacher_id)
    UNION SELECT st.user_id FROM school.group_membership m JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id
      JOIN school.student st ON st.tenant_id = e.tenant_id AND st.id = e.student_id AND st.user_id IS NOT NULL
      WHERE m.tenant_id = NEW.tenant_id AND m.group_id = NEW.group_id AND m.valid_from <= day AND (m.valid_to IS NULL OR m.valid_to >= day)) x
  WHERE x.u IS DISTINCT FROM school.actor()
    AND EXISTS (SELECT 1 FROM school.effective_permissions(x.u, NEW.tenant_id) p WHERE p.permission = 'schedule.session.read');
  IF people IS NOT NULL THEN
    PERFORM school.notify('schedule.session_' || kind, people, jsonb_build_object('session_id', NEW.id, 'group_id', NEW.group_id, 'day', day));
  END IF;
  RETURN NULL;
END $$;

-- Who reads the versions: those who read the whole school's planning or publish it; their copies
-- as the sessions are read.
CREATE POLICY resource_reader ON school.timetable_version AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('schedule.session.read')) OR (SELECT school.can('schedule.timetable.publish')));
CREATE POLICY resource_reader ON school.timetable_version_session AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('schedule.session.read'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.read', 'study_group'))))
  OR group_id IN (SELECT unnest((SELECT school.core_ids('schedule.session.read', 'study_group'))))
  OR school.is_own_staff(teacher_id));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('timetable_version', 'pedagogy', '{schedule.session.read}');
