-- Lot 4, sessions (SRS 1.2, FR-PLN-02, FR-PLN-03): a group's weekly course planned once as a
-- series, which generates its dated sessions on the days the school is open (FR-PLN-01 AC1), and
-- each session changed or cancelled on its own. Conflicts are checked before writing: a teacher,
-- a room or a group never has two sessions at once, which PostgreSQL's exclusion constraints keep
-- even for requests at the same moment (TST-15); nor a pupil, in two groups of any programmes
-- (AC2); nor a teacher not qualified or unavailable, a room closed, elsewhere or too small, a day
-- closed. What is only a warning is told, never refused: a teacher's daily or weekly bounds, the
-- programme's volume, a change of site without time to travel. A planned session is not a session
-- held (BR-14): attendance, lot 4's next increments, says which were.

-- FR-PLN-03: below this time between two sessions on different sites, a teacher's change of site is a warning.
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('planning.travel_minutes', 'integer', '30', 0, 240, 'tenant.settings.manage',
   '{"fr": "Temps de trajet entre deux sites (minutes)", "ar": "مدة التنقل بين موقعين (بالدقائق)"}');

-- A weekly course: a group, a subject, a teacher, a room or none, a day of the week (0 Sunday) and
-- its hours (from a slot of the grid or typed), from one day until another; planned once per key.
CREATE TABLE school.session_series (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  teacher_id uuid NOT NULL,
  room_id uuid,
  weekday smallint NOT NULL CHECK (weekday BETWEEN 0 AND 6),
  start_time time NOT NULL,
  end_time time NOT NULL,
  slot_id uuid,
  date_from date NOT NULL,
  date_to date NOT NULL,
  idempotency_key uuid NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (end_time > start_time),
  CHECK (extract(second FROM start_time) = 0 AND extract(second FROM end_time) = 0),
  CHECK (date_to >= date_from AND date_to - date_from <= 400),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, idempotency_key),
  FOREIGN KEY (tenant_id, group_id) REFERENCES school.study_group (tenant_id, id),
  FOREIGN KEY (tenant_id, subject_id) REFERENCES school.subject (tenant_id, id),
  FOREIGN KEY (tenant_id, teacher_id) REFERENCES school.staff (tenant_id, id),
  FOREIGN KEY (tenant_id, room_id) REFERENCES school.room (tenant_id, id),
  FOREIGN KEY (tenant_id, slot_id) REFERENCES school.time_slot (tenant_id, id)
);
CREATE INDEX session_series_group ON school.session_series (tenant_id, group_id);
CALL school.protect('school.session_series');

-- A dated session: planned, then delivered or replaced as attendance says (lot 4's next
-- increments), or cancelled with its reason. One changed on its own leaves its series (`detached`):
-- a change of the series no longer touches it (AC2). One whose attendance is entered is `locked`.
CREATE TABLE school.class_session (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  group_id uuid NOT NULL,
  subject_id uuid NOT NULL,
  teacher_id uuid NOT NULL,
  room_id uuid,
  series_id uuid,
  starts_at timestamptz NOT NULL,
  ends_at timestamptz NOT NULL,
  status text NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'delivered', 'cancelled', 'replaced')),
  detached boolean NOT NULL DEFAULT false,
  locked boolean NOT NULL DEFAULT false,
  cancel_reason text CHECK (length(cancel_reason) BETWEEN 3 AND 1000),
  cancelled_at timestamptz,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (ends_at > starts_at AND ends_at - starts_at <= interval '24 hours'),
  CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL)),
  CHECK (status <> 'cancelled' OR cancel_reason IS NOT NULL),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, group_id) REFERENCES school.study_group (tenant_id, id),
  FOREIGN KEY (tenant_id, subject_id) REFERENCES school.subject (tenant_id, id),
  FOREIGN KEY (tenant_id, teacher_id) REFERENCES school.staff (tenant_id, id),
  FOREIGN KEY (tenant_id, room_id) REFERENCES school.room (tenant_id, id),
  FOREIGN KEY (tenant_id, series_id) REFERENCES school.session_series (tenant_id, id),
  -- TST-15: never two sessions at once for a teacher, a room or a group, even for requests at the same moment.
  CONSTRAINT class_session_teacher_free EXCLUDE USING gist (tenant_id WITH =, teacher_id WITH =, tstzrange(starts_at, ends_at) WITH &&)
    WHERE (status <> 'cancelled'),
  CONSTRAINT class_session_room_free EXCLUDE USING gist (tenant_id WITH =, room_id WITH =, tstzrange(starts_at, ends_at) WITH &&)
    WHERE (status <> 'cancelled' AND room_id IS NOT NULL),
  CONSTRAINT class_session_group_free EXCLUDE USING gist (tenant_id WITH =, group_id WITH =, tstzrange(starts_at, ends_at) WITH &&)
    WHERE (status <> 'cancelled')
);
CREATE INDEX class_session_teacher ON school.class_session (tenant_id, teacher_id, starts_at);
CREATE INDEX class_session_room ON school.class_session (tenant_id, room_id, starts_at) WHERE room_id IS NOT NULL;
CREATE INDEX class_session_group ON school.class_session (tenant_id, group_id, starts_at);
CREATE INDEX class_session_series ON school.class_session (tenant_id, series_id, starts_at) WHERE series_id IS NOT NULL;
CALL school.protect('school.class_session');

-- A session's day and hours in the school's time zone, Africa/Algiers.
CREATE FUNCTION school.local_day(p timestamptz) RETURNS date LANGUAGE sql IMMUTABLE AS $$ SELECT (p AT TIME ZONE 'Africa/Algiers')::date $$;
CREATE FUNCTION school.local_at(p_day date, p_time time) RETURNS timestamptz LANGUAGE sql IMMUTABLE AS $$ SELECT (p_day + p_time) AT TIME ZONE 'Africa/Algiers' $$;

-- FR-PLN-03: what a session would conflict with, from a group, a subject, a teacher, a room or
-- none and its hours; `p_except` the session itself when it changes. Each conflict blocking or a
-- warning, with what it collides with. With its owner's rights, limited to the school in context:
-- a pupil's other groups, a teacher's other sessions, are counted whoever plans. Volatile: run
-- after the locks of a check, it reads what the request before committed.
CREATE FUNCTION school.session_conflicts(p_group uuid, p_subject uuid, p_teacher uuid, p_room uuid, p_starts timestamptz, p_ends timestamptz,
  p_except uuid DEFAULT NULL)
RETURNS TABLE (conflict text, blocking boolean, detail jsonb)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE
  t uuid := school.allowed_tenant(); g school.study_group; s school.staff; r school.room; a school.availability; ps school.program_subject;
  day date; from_time time; until_time time; node uuid; members integer; minutes numeric := extract(epoch FROM p_ends - p_starts) / 60;
  week_start date; travel integer; hit record; n numeric;
BEGIN
  IF t IS NULL THEN RETURN; END IF;
  IF p_ends <= p_starts THEN
    RETURN QUERY SELECT 'session_hours_invalid', true, '{}'::jsonb;
    RETURN;
  END IF;
  day := school.local_day(p_starts);
  from_time := (p_starts AT TIME ZONE 'Africa/Algiers')::time;
  until_time := CASE WHEN school.local_day(p_ends) > day THEN '24:00'::time ELSE (p_ends AT TIME ZONE 'Africa/Algiers')::time END;
  IF school.local_day(p_ends) > day AND (p_ends AT TIME ZONE 'Africa/Algiers')::time <> '00:00' THEN
    RETURN QUERY SELECT 'session_spans_days', true, '{}'::jsonb;
  END IF;
  -- The group: active, within its dates; the day open on its site.
  SELECT * INTO g FROM school.study_group WHERE tenant_id = t AND id = p_group;
  IF g.id IS NULL THEN RETURN; END IF;
  IF g.archived_at IS NOT NULL OR g.status <> 'active' THEN RETURN QUERY SELECT 'session_group_not_active', true, '{}'::jsonb; END IF;
  IF day < g.valid_from OR day > g.valid_to THEN RETURN QUERY SELECT 'session_outside_group_dates', true, '{}'::jsonb; END IF;
  IF NOT school.day_open(day, g.site_id) THEN RETURN QUERY SELECT 'session_day_closed', true, jsonb_build_object('day', day); END IF;
  -- The subject: one the group's programme teaches that day.
  IF g.program_id IS NOT NULL THEN
    SELECT p.node_id INTO node FROM school.program p WHERE p.tenant_id = t AND p.id = g.program_id;
    SELECT * INTO ps FROM school.program_subject x WHERE x.tenant_id = t AND x.program_id = g.program_id AND x.subject_id = p_subject
      AND x.archived_at IS NULL AND x.valid_from <= day AND (x.valid_to IS NULL OR x.valid_to >= day);
    IF ps.id IS NULL THEN RETURN QUERY SELECT 'session_subject_outside_program', true, '{}'::jsonb; END IF;
  END IF;
  -- The teacher: a teacher still with the school, qualified (FR-STF-02), available (FR-STF-03), free.
  SELECT * INTO s FROM school.staff WHERE tenant_id = t AND id = p_teacher;
  IF s.id IS NOT NULL THEN
    IF s.staff_type NOT IN ('teacher_internal', 'teacher_external') THEN RETURN QUERY SELECT 'session_not_teacher', true, '{}'::jsonb; END IF;
    IF s.archived_at IS NOT NULL OR s.status = 'left' THEN RETURN QUERY SELECT 'session_teacher_left', true, '{}'::jsonb; END IF;
    IF NOT (CASE WHEN node IS NOT NULL THEN school.qualified(p_teacher, p_subject, node, day)
                 ELSE EXISTS (SELECT 1 FROM school.qualification q WHERE q.tenant_id = t AND q.staff_id = p_teacher AND q.subject_id = p_subject
                   AND q.valid_from <= day AND (q.valid_to IS NULL OR q.valid_to >= day)) END) THEN
      RETURN QUERY SELECT 'session_teacher_not_qualified', true, '{}'::jsonb;
    END IF;
    IF NOT school.available(p_teacher, day, from_time, until_time) THEN RETURN QUERY SELECT 'session_teacher_unavailable', true, '{}'::jsonb; END IF;
  END IF;
  FOR hit IN SELECT c.id, c.starts_at, c.ends_at, gr.code FROM school.class_session c JOIN school.study_group gr ON gr.tenant_id = c.tenant_id AND gr.id = c.group_id
           WHERE c.tenant_id = t AND c.teacher_id = p_teacher AND c.status <> 'cancelled' AND c.id IS DISTINCT FROM p_except
             AND tstzrange(c.starts_at, c.ends_at) && tstzrange(p_starts, p_ends) LIMIT 1 LOOP
    RETURN QUERY SELECT 'session_teacher_busy', true, jsonb_build_object('session', hit.id, 'group', hit.code, 'starts_at', hit.starts_at);
  END LOOP;
  FOR hit IN SELECT c.id, c.starts_at FROM school.class_session c
           WHERE c.tenant_id = t AND c.group_id = p_group AND c.status <> 'cancelled' AND c.id IS DISTINCT FROM p_except
             AND tstzrange(c.starts_at, c.ends_at) && tstzrange(p_starts, p_ends) LIMIT 1 LOOP
    RETURN QUERY SELECT 'session_group_busy', true, jsonb_build_object('session', hit.id, 'starts_at', hit.starts_at);
  END LOOP;
  -- The room: on the group's site, available that day (FR-SLL-02 AC2), seating the group, free.
  IF p_room IS NOT NULL THEN
    SELECT * INTO r FROM school.room WHERE tenant_id = t AND id = p_room;
    IF r.id IS NOT NULL THEN
      IF r.site_id <> g.site_id THEN RETURN QUERY SELECT 'session_room_other_site', true, '{}'::jsonb; END IF;
      IF NOT school.room_available(p_room, day) THEN RETURN QUERY SELECT 'session_room_unavailable', true, '{}'::jsonb; END IF;
      SELECT count(*) INTO members FROM school.group_membership m WHERE m.tenant_id = t AND m.group_id = p_group
        AND m.valid_from <= day AND (m.valid_to IS NULL OR m.valid_to >= day);
      IF r.capacity < greatest(g.capacity, members) THEN
        RETURN QUERY SELECT 'session_room_too_small', true, jsonb_build_object('places', r.capacity, 'needed', greatest(g.capacity, members));
      END IF;
      FOR hit IN SELECT c.id, c.starts_at, gr.code FROM school.class_session c JOIN school.study_group gr ON gr.tenant_id = c.tenant_id AND gr.id = c.group_id
               WHERE c.tenant_id = t AND c.room_id = p_room AND c.status <> 'cancelled' AND c.id IS DISTINCT FROM p_except
                 AND tstzrange(c.starts_at, c.ends_at) && tstzrange(p_starts, p_ends) LIMIT 1 LOOP
        RETURN QUERY SELECT 'session_room_busy', true, jsonb_build_object('session', hit.id, 'group', hit.code, 'starts_at', hit.starts_at);
      END LOOP;
    END IF;
  END IF;
  -- AC2: a pupil of the group that day, in another group of any programme with a session at the same time.
  FOR hit IN SELECT count(DISTINCT e.student_id) AS pupils, min(og.code) AS code FROM school.group_membership m
           JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id
           JOIN school.enrollment oe ON oe.tenant_id = e.tenant_id AND oe.student_id = e.student_id
           JOIN school.group_membership om ON om.tenant_id = oe.tenant_id AND om.enrollment_id = oe.id AND om.group_id <> p_group
             AND om.valid_from <= day AND (om.valid_to IS NULL OR om.valid_to >= day)
           JOIN school.class_session c ON c.tenant_id = om.tenant_id AND c.group_id = om.group_id AND c.status <> 'cancelled' AND c.id IS DISTINCT FROM p_except
             AND tstzrange(c.starts_at, c.ends_at) && tstzrange(p_starts, p_ends)
           JOIN school.study_group og ON og.tenant_id = c.tenant_id AND og.id = c.group_id
           WHERE m.tenant_id = t AND m.group_id = p_group AND m.valid_from <= day AND (m.valid_to IS NULL OR m.valid_to >= day)
           HAVING count(DISTINCT e.student_id) > 0 LOOP
    RETURN QUERY SELECT 'session_pupil_busy', true, jsonb_build_object('pupils', hit.pupils, 'group', hit.code);
  END LOOP;
  -- Warnings. The teacher's bounds (FR-STF-03): hours a day and a week of the week the school starts on.
  SELECT * INTO a FROM school.availability_on(p_teacher, day);
  IF a.id IS NOT NULL THEN
    SELECT coalesce(sum(extract(epoch FROM c.ends_at - c.starts_at) / 3600), 0) INTO n FROM school.class_session c
      WHERE c.tenant_id = t AND c.teacher_id = p_teacher AND c.status <> 'cancelled' AND c.id IS DISTINCT FROM p_except AND school.local_day(c.starts_at) = day;
    IF a.max_daily_hours IS NOT NULL AND n + minutes / 60 > a.max_daily_hours THEN
      RETURN QUERY SELECT 'teacher_daily_hours', false, jsonb_build_object('hours', round(n + minutes / 60, 2), 'bound', a.max_daily_hours);
    END IF;
    week_start := day - ((extract(dow FROM day)::integer - coalesce((school.setting_value('calendar.first_day_of_week') #>> '{}')::integer, 0) + 7) % 7);
    SELECT coalesce(sum(extract(epoch FROM c.ends_at - c.starts_at) / 3600), 0) INTO n FROM school.class_session c
      WHERE c.tenant_id = t AND c.teacher_id = p_teacher AND c.status <> 'cancelled' AND c.id IS DISTINCT FROM p_except
        AND school.local_day(c.starts_at) BETWEEN week_start AND week_start + 6;
    IF a.max_weekly_hours IS NOT NULL AND n + minutes / 60 > a.max_weekly_hours THEN
      RETURN QUERY SELECT 'teacher_weekly_hours', false, jsonb_build_object('hours', round(n + minutes / 60, 2), 'bound', a.max_weekly_hours);
    END IF;
  END IF;
  -- The programme's volume (FR-PRG-02): sessions, hours in all, hours that week.
  IF ps.id IS NOT NULL THEN
    week_start := day - ((extract(dow FROM day)::integer - coalesce((school.setting_value('calendar.first_day_of_week') #>> '{}')::integer, 0) + 7) % 7);
    SELECT count(*), coalesce(sum(extract(epoch FROM c.ends_at - c.starts_at) / 3600), 0) INTO members, n FROM school.class_session c
      WHERE c.tenant_id = t AND c.group_id = p_group AND c.subject_id = p_subject AND c.status <> 'cancelled' AND c.id IS DISTINCT FROM p_except;
    IF ps.session_count IS NOT NULL AND members + 1 > ps.session_count THEN
      RETURN QUERY SELECT 'volume_sessions_exceeded', false, jsonb_build_object('sessions', members + 1, 'volume', ps.session_count);
    END IF;
    IF ps.total_hours IS NOT NULL AND n + minutes / 60 > ps.total_hours THEN
      RETURN QUERY SELECT 'volume_hours_exceeded', false, jsonb_build_object('hours', round(n + minutes / 60, 2), 'volume', ps.total_hours);
    END IF;
    SELECT coalesce(sum(extract(epoch FROM c.ends_at - c.starts_at) / 3600), 0) INTO n FROM school.class_session c
      WHERE c.tenant_id = t AND c.group_id = p_group AND c.subject_id = p_subject AND c.status <> 'cancelled' AND c.id IS DISTINCT FROM p_except
        AND school.local_day(c.starts_at) BETWEEN week_start AND week_start + 6;
    IF ps.weekly_hours IS NOT NULL AND n + minutes / 60 > ps.weekly_hours THEN
      RETURN QUERY SELECT 'volume_weekly_exceeded', false, jsonb_build_object('hours', round(n + minutes / 60, 2), 'volume', ps.weekly_hours);
    END IF;
  END IF;
  -- A change of site without the time to travel.
  travel := coalesce((school.setting_value('planning.travel_minutes') #>> '{}')::integer, 30);
  FOR hit IN SELECT gr.code FROM school.class_session c JOIN school.study_group gr ON gr.tenant_id = c.tenant_id AND gr.id = c.group_id
           WHERE c.tenant_id = t AND c.teacher_id = p_teacher AND c.status <> 'cancelled' AND c.id IS DISTINCT FROM p_except AND gr.site_id <> g.site_id
             AND tstzrange(c.starts_at - make_interval(mins => travel), c.ends_at + make_interval(mins => travel)) && tstzrange(p_starts, p_ends) LIMIT 1 LOOP
    RETURN QUERY SELECT 'teacher_site_change', false, jsonb_build_object('group', hit.code, 'minutes', travel);
  END LOOP;
END $$;

-- Who plans: `schedule.session.create` to plan, `schedule.session.update` to change,
-- `schedule.session.cancel` to cancel, on the group's site or within the grant's scope (the
-- policies). A session keeps its series; changed on its own, it leaves it (AC2). Only a planned
-- session changes, never one locked by its attendance; it is cancelled with its reason. A session
-- is deleted only by its series' end, while planned and with it.
CREATE FUNCTION school.guard_class_session() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE by_series boolean := coalesce(current_setting('app.series_change', true), '') = 'on';
BEGIN
  IF TG_OP = 'DELETE' THEN
    PERFORM school.require('schedule.session.update');
    IF NOT by_series OR OLD.status <> 'planned' OR OLD.locked OR OLD.detached THEN RAISE EXCEPTION 'session_not_deletable' USING ERRCODE = '23514'; END IF;
    RETURN OLD;
  END IF;
  IF TG_OP = 'INSERT' THEN
    PERFORM school.require('schedule.session.create');
    IF NEW.series_id IS NOT NULL AND NOT by_series THEN RAISE EXCEPTION 'session_series_refused' USING ERRCODE = '23514'; END IF;
    NEW.status := 'planned'; NEW.cancel_reason := NULL; NEW.cancelled_at := NULL; NEW.detached := false; NEW.locked := false; NEW.archived_at := NULL;
    RETURN NEW;
  END IF;
  IF NEW.series_id IS DISTINCT FROM OLD.series_id OR NEW.archived_at IS DISTINCT FROM OLD.archived_at THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF OLD.status <> 'planned' THEN RAISE EXCEPTION 'session_immutable' USING ERRCODE = '23514'; END IF;
  IF OLD.locked THEN RAISE EXCEPTION 'session_locked' USING ERRCODE = '23514'; END IF;
  IF NEW.status = 'cancelled' THEN
    PERFORM school.require('schedule.session.cancel');
    IF (NEW.group_id, NEW.subject_id, NEW.teacher_id, NEW.room_id, NEW.starts_at, NEW.ends_at)
       IS DISTINCT FROM (OLD.group_id, OLD.subject_id, OLD.teacher_id, OLD.room_id, OLD.starts_at, OLD.ends_at) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    NEW.cancel_reason := nullif(btrim(NEW.cancel_reason), '');
    IF NEW.cancel_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    NEW.cancelled_at := now();
    RETURN NEW;
  END IF;
  IF NEW.status <> 'planned' THEN RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514'; END IF;
  PERFORM school.require('schedule.session.update');
  IF NEW.group_id IS DISTINCT FROM OLD.group_id OR NEW.subject_id IS DISTINCT FROM OLD.subject_id THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF (NEW.teacher_id, NEW.room_id, NEW.starts_at, NEW.ends_at) IS DISTINCT FROM (OLD.teacher_id, OLD.room_id, OLD.starts_at, OLD.ends_at) THEN
    -- AC2: changed on its own, it leaves its series; the series' own change keeps it in.
    IF NEW.series_id IS NOT NULL AND NOT by_series THEN NEW.detached := true; ELSE NEW.detached := OLD.detached; END IF;
  ELSE
    NEW.detached := OLD.detached;
  END IF;
  NEW.locked := OLD.locked; NEW.cancel_reason := OLD.cancel_reason; NEW.cancelled_at := OLD.cancelled_at;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.class_session FOR EACH ROW EXECUTE FUNCTION school.guard_class_session();

-- FR-PLN-03: no blocking conflict is written. The pupils of the group are locked first, in their
-- order, so that two groups sharing a pupil are planned one after the other: the second sees the
-- first (AC2). A teacher, a room and a group are the exclusion constraints' (TST-15).
CREATE FUNCTION school.check_class_session() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE c record; day date := school.local_day(NEW.starts_at);
BEGIN
  IF NEW.status = 'cancelled' THEN RETURN NEW; END IF;
  IF TG_OP = 'UPDATE' AND (NEW.group_id, NEW.subject_id, NEW.teacher_id, NEW.room_id, NEW.starts_at, NEW.ends_at)
     IS NOT DISTINCT FROM (OLD.group_id, OLD.subject_id, OLD.teacher_id, OLD.room_id, OLD.starts_at, OLD.ends_at) THEN
    RETURN NEW;
  END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended('session_pupil:' || p.student_id::text, 62)) FROM (
    SELECT DISTINCT e.student_id FROM school.group_membership m JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id
    WHERE m.tenant_id = NEW.tenant_id AND m.group_id = NEW.group_id AND m.valid_from <= day AND (m.valid_to IS NULL OR m.valid_to >= day)
    ORDER BY e.student_id) p;
  FOR c IN SELECT * FROM school.session_conflicts(NEW.group_id, NEW.subject_id, NEW.teacher_id, NEW.room_id, NEW.starts_at, NEW.ends_at, NEW.id)
           WHERE blocking LIMIT 1 LOOP
    RAISE EXCEPTION '%', c.conflict USING ERRCODE = '23514', DETAIL = c.detail::text;
  END LOOP;
  RETURN NEW;
END $$;
-- After `guard`: the permission first.
CREATE TRIGGER rules BEFORE INSERT OR UPDATE ON school.class_session FOR EACH ROW EXECUTE FUNCTION school.check_class_session();

-- A series keeps its group, subject, day of the week, first day and key; it changes only through
-- the functions below, by `schedule.session.update`, and is planned by `schedule.session.create`.
CREATE FUNCTION school.guard_session_series() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    PERFORM school.require('schedule.session.create');
    NEW.archived_at := NULL;
    RETURN NEW;
  END IF;
  PERFORM school.require('schedule.session.update');
  IF (NEW.group_id, NEW.subject_id, NEW.weekday, NEW.date_from, NEW.idempotency_key, NEW.archived_at)
     IS DISTINCT FROM (OLD.group_id, OLD.subject_id, OLD.weekday, OLD.date_from, OLD.idempotency_key, OLD.archived_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF coalesce(current_setting('app.series_change', true), '') <> 'on' THEN RAISE EXCEPTION 'session_series_refused' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.session_series FOR EACH ROW EXECUTE FUNCTION school.guard_session_series();

-- The days a series falls on: its day of the week from its first day until its last, open or not.
CREATE FUNCTION school.series_days(p_weekday integer, p_from date, p_to date) RETURNS SETOF date LANGUAGE sql IMMUTABLE AS $$
  SELECT d::date FROM generate_series(p_from, p_to, interval '1 day') d WHERE extract(dow FROM d) = p_weekday ORDER BY 1 $$;

-- FR-PLN-02: what a series would plan, day by day: each day closed on the group's site skipped
-- (AC1), each other day's conflicts. Read before planning it.
CREATE FUNCTION school.series_conflicts(p_group uuid, p_subject uuid, p_teacher uuid, p_room uuid, p_weekday integer, p_start time, p_end time,
  p_from date, p_to date)
RETURNS TABLE (day date, conflict text, blocking boolean, detail jsonb) LANGUAGE plpgsql AS $$
DECLARE d date; site uuid;
BEGIN
  SELECT g.site_id INTO site FROM school.study_group g WHERE g.tenant_id = school.tenant() AND g.id = p_group;
  FOR d IN SELECT * FROM school.series_days(p_weekday, p_from, p_to) LOOP
    IF NOT school.day_open(d, site) THEN
      RETURN QUERY SELECT d, 'session_day_skipped'::text, false, '{}'::jsonb;
      CONTINUE;
    END IF;
    RETURN QUERY SELECT d, c.conflict, c.blocking, c.detail FROM school.session_conflicts(p_group, p_subject, p_teacher, p_room,
      school.local_at(d, p_start), school.local_at(d, p_end)) c;
  END LOOP;
END $$;

-- FR-PLN-02: plans a weekly course and generates its sessions on the open days of its site,
-- once per key; a blocking conflict on any day refuses the whole series. The hours are the slot's
-- when one is given.
CREATE FUNCTION school.plan_series(p_group uuid, p_subject uuid, p_teacher uuid, p_room uuid, p_weekday integer, p_start time, p_end time,
  p_slot uuid, p_from date, p_to date, p_key uuid)
RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE s school.session_series; sl school.time_slot; site uuid; d date; planned integer := 0;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('session_series_key:' || p_key::text, 62));
  SELECT * INTO s FROM school.session_series x WHERE x.tenant_id = school.tenant() AND x.idempotency_key = p_key;
  IF s.id IS NOT NULL THEN
    IF (s.group_id, s.subject_id, s.weekday, s.date_from) IS DISTINCT FROM (p_group, p_subject, p_weekday::smallint, p_from) THEN
      RAISE EXCEPTION 'idempotency_key_reused' USING ERRCODE = '23514';
    END IF;
    RETURN s.id;
  END IF;
  IF p_slot IS NOT NULL THEN
    SELECT * INTO sl FROM school.time_slot x WHERE x.tenant_id = school.tenant() AND x.id = p_slot;
    IF sl.id IS NULL OR sl.archived_at IS NOT NULL THEN RAISE EXCEPTION 'time_slot_archived' USING ERRCODE = '23514'; END IF;
    p_start := sl.start_time; p_end := sl.end_time;
  END IF;
  IF p_start IS NULL OR p_end IS NULL OR p_end <= p_start THEN RAISE EXCEPTION 'session_hours_invalid' USING ERRCODE = '23514'; END IF;
  PERFORM set_config('app.series_change', 'on', true);
  INSERT INTO school.session_series (tenant_id, group_id, subject_id, teacher_id, room_id, weekday, start_time, end_time, slot_id, date_from, date_to, idempotency_key)
  VALUES (school.tenant(), p_group, p_subject, p_teacher, p_room, p_weekday, p_start, p_end, p_slot, p_from, p_to, p_key) RETURNING * INTO s;
  SELECT g.site_id INTO site FROM school.study_group g WHERE g.tenant_id = s.tenant_id AND g.id = p_group;
  FOR d IN SELECT * FROM school.series_days(p_weekday, p_from, p_to) LOOP
    CONTINUE WHEN NOT school.day_open(d, site);
    INSERT INTO school.class_session (tenant_id, group_id, subject_id, teacher_id, room_id, series_id, starts_at, ends_at)
    VALUES (s.tenant_id, p_group, p_subject, p_teacher, p_room, s.id, school.local_at(d, p_start), school.local_at(d, p_end));
    planned := planned + 1;
  END LOOP;
  PERFORM set_config('app.series_change', '', true);
  IF planned = 0 THEN RAISE EXCEPTION 'session_series_empty' USING ERRCODE = '23514'; END IF;
  RETURN s.id;
END $$;

-- FR-PLN-02: changes a series from a day on (today or later): its teacher, room and hours, for
-- its sessions still planned from that day, neither changed on their own (AC2) nor locked by their
-- attendance. Returns how many changed.
CREATE FUNCTION school.change_series(p_series uuid, p_from date, p_teacher uuid, p_room uuid, p_start time, p_end time, p_version integer)
RETURNS integer LANGUAGE plpgsql AS $$
DECLARE s school.session_series; changed integer;
BEGIN
  SELECT * INTO s FROM school.session_series x WHERE x.tenant_id = school.tenant() AND x.id = p_series FOR UPDATE;
  IF s.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF s.version <> p_version THEN RAISE EXCEPTION 'stale_version' USING ERRCODE = '23514'; END IF;
  IF p_from < current_date THEN RAISE EXCEPTION 'session_series_past' USING ERRCODE = '23514'; END IF;
  IF p_end <= p_start THEN RAISE EXCEPTION 'session_hours_invalid' USING ERRCODE = '23514'; END IF;
  PERFORM set_config('app.series_change', 'on', true);
  UPDATE school.session_series SET teacher_id = p_teacher, room_id = p_room, start_time = p_start, end_time = p_end,
    slot_id = CASE WHEN (p_start, p_end) = (start_time, end_time) THEN slot_id END, version = p_version WHERE tenant_id = s.tenant_id AND id = s.id;
  UPDATE school.class_session c SET teacher_id = p_teacher, room_id = p_room,
    starts_at = school.local_at(school.local_day(c.starts_at), p_start), ends_at = school.local_at(school.local_day(c.starts_at), p_end)
  WHERE c.tenant_id = s.tenant_id AND c.series_id = s.id AND c.status = 'planned' AND NOT c.detached AND NOT c.locked
    AND school.local_day(c.starts_at) >= p_from;
  GET DIAGNOSTICS changed = ROW_COUNT;
  PERFORM set_config('app.series_change', '', true);
  RETURN changed;
END $$;

-- Ends a series after a day (yesterday or later), sooner than planned: its sessions still planned
-- after that day go, neither changed on their own nor locked. Returns how many went.
CREATE FUNCTION school.end_series(p_series uuid, p_last date, p_version integer) RETURNS integer LANGUAGE plpgsql AS $$
DECLARE s school.session_series; gone integer;
BEGIN
  SELECT * INTO s FROM school.session_series x WHERE x.tenant_id = school.tenant() AND x.id = p_series FOR UPDATE;
  IF s.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF s.version <> p_version THEN RAISE EXCEPTION 'stale_version' USING ERRCODE = '23514'; END IF;
  IF p_last < current_date - 1 OR p_last < s.date_from - 1 THEN RAISE EXCEPTION 'session_series_past' USING ERRCODE = '23514'; END IF;
  IF p_last >= s.date_to THEN RAISE EXCEPTION 'session_series_end_later' USING ERRCODE = '23514'; END IF;
  PERFORM set_config('app.series_change', 'on', true);
  DELETE FROM school.class_session c WHERE c.tenant_id = s.tenant_id AND c.series_id = s.id AND c.status = 'planned' AND NOT c.detached AND NOT c.locked
    AND school.local_day(c.starts_at) > p_last;
  GET DIAGNOSTICS gone = ROW_COUNT;
  UPDATE school.session_series SET date_to = greatest(p_last, date_from), version = p_version WHERE tenant_id = s.tenant_id AND id = s.id;
  PERFORM set_config('app.series_change', '', true);
  RETURN gone;
END $$;

-- FR-PLN-02: a group's volume follow-up: by subject of its programme (and any other it has
-- sessions of), the sessions and hours planned, delivered (attendance, next increments) and the
-- programme's volume in force today. With the reader's rights.
CREATE FUNCTION school.session_volume(p_group uuid)
RETURNS TABLE (subject_id uuid, planned_sessions integer, planned_hours numeric, delivered_sessions integer, delivered_hours numeric,
  volume_sessions integer, volume_hours numeric, weekly_hours numeric)
LANGUAGE sql STABLE AS $$
  WITH g AS (SELECT * FROM school.study_group WHERE tenant_id = school.tenant() AND id = p_group),
  subjects AS (
    SELECT ps.subject_id FROM school.program_subject ps, g WHERE ps.tenant_id = g.tenant_id AND ps.program_id = g.program_id AND ps.archived_at IS NULL
      AND ps.valid_from <= current_date AND (ps.valid_to IS NULL OR ps.valid_to >= current_date)
    UNION SELECT c.subject_id FROM school.class_session c, g WHERE c.tenant_id = g.tenant_id AND c.group_id = g.id)
  SELECT s.subject_id,
    count(c.id) FILTER (WHERE c.status <> 'cancelled')::integer,
    round(coalesce(sum(extract(epoch FROM c.ends_at - c.starts_at) / 3600) FILTER (WHERE c.status <> 'cancelled'), 0), 2),
    count(c.id) FILTER (WHERE c.status IN ('delivered', 'replaced'))::integer,
    round(coalesce(sum(extract(epoch FROM c.ends_at - c.starts_at) / 3600) FILTER (WHERE c.status IN ('delivered', 'replaced')), 0), 2),
    max(ps.session_count), max(ps.total_hours), max(ps.weekly_hours)
  FROM subjects s CROSS JOIN g
  LEFT JOIN school.class_session c ON c.tenant_id = g.tenant_id AND c.group_id = g.id AND c.subject_id = s.subject_id
  LEFT JOIN school.program_subject ps ON ps.tenant_id = g.tenant_id AND ps.program_id = g.program_id AND ps.subject_id = s.subject_id AND ps.archived_at IS NULL
    AND ps.valid_from <= current_date AND (ps.valid_to IS NULL OR ps.valid_to >= current_date)
  GROUP BY s.subject_id $$;

-- FR-PLN-01 AC1, now that sessions exist: a day is closed only while no session is planned on it.
CREATE OR REPLACE FUNCTION school.guard_calendar_exception() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require('schedule.calendar.manage');
  IF TG_OP = 'INSERT' THEN
    IF NEW.date_from < current_date THEN RAISE EXCEPTION 'calendar_exception_past' USING ERRCODE = '23514'; END IF;
    IF NEW.date_to < NEW.date_from THEN RAISE EXCEPTION 'calendar_exception_end_before_start' USING ERRCODE = '23514'; END IF;
    NEW.reason := nullif(btrim(NEW.reason), '');
    IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    NEW.status := 'planned'; NEW.cancelled_at := NULL; NEW.archived_at := NULL;
    IF NEW.site_id IS NOT NULL AND EXISTS (SELECT 1 FROM school.site s WHERE s.tenant_id = NEW.tenant_id AND s.id = NEW.site_id AND s.archived_at IS NOT NULL) THEN
      RAISE EXCEPTION 'site_archived' USING ERRCODE = '23514';
    END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('calendar_exception:' || NEW.tenant_id::text || ':' || coalesce(NEW.site_id::text, ''), 61));
    IF EXISTS (SELECT 1 FROM school.calendar_exception x WHERE x.tenant_id = NEW.tenant_id AND x.site_id IS NOT DISTINCT FROM NEW.site_id
               AND x.status = 'planned' AND daterange(x.date_from, x.date_to, '[]') && daterange(NEW.date_from, NEW.date_to, '[]')) THEN
      RAISE EXCEPTION 'calendar_exception_overlap' USING ERRCODE = '23514';
    END IF;
    IF NEW.kind <> 'opening' AND school.sessions_planned_on(NEW.site_id, NEW.date_from, NEW.date_to) THEN
      RAISE EXCEPTION 'calendar_sessions_planned' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF (NEW.site_id, NEW.kind, NEW.date_from, NEW.date_to, NEW.reason, NEW.cancelled_at, NEW.archived_at)
     IS DISTINCT FROM (OLD.site_id, OLD.kind, OLD.date_from, OLD.date_to, OLD.reason, OLD.cancelled_at, OLD.archived_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = OLD.status THEN RETURN NEW; END IF;
  IF (OLD.status, NEW.status) IS DISTINCT FROM ('planned', 'cancelled') THEN RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514'; END IF;
  IF OLD.date_to < current_date THEN RAISE EXCEPTION 'calendar_exception_ended' USING ERRCODE = '23514'; END IF;
  -- An opening cancelled closes its days again: only while none of their sessions is planned.
  IF OLD.kind = 'opening' AND school.sessions_planned_on(OLD.site_id, greatest(OLD.date_from, current_date), OLD.date_to) THEN
    RAISE EXCEPTION 'calendar_sessions_planned' USING ERRCODE = '23514';
  END IF;
  NEW.cancelled_at := now();
  RETURN NEW;
END $$;

-- Whether a session not cancelled falls on these days, on a site or anywhere in the school. With
-- its owner's rights: the calendar's keeper may not read every group's sessions.
CREATE FUNCTION school.sessions_planned_on(p_site uuid, p_from date, p_to date) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM school.class_session c JOIN school.study_group g ON g.tenant_id = c.tenant_id AND g.id = c.group_id
    WHERE c.tenant_id = school.allowed_tenant() AND c.status <> 'cancelled' AND (p_site IS NULL OR g.site_id = p_site)
      AND c.starts_at < school.local_at(p_to + 1, '00:00') AND c.ends_at > school.local_at(p_from, '00:00')) $$;

-- Who reads the sessions: `schedule.session.read` for the whole school, on the group's site, for
-- the groups of the grant's scope (a lead teacher's, a pupil's or a parent's), or the teacher's own;
-- planned, changed, cancelled within the scope of the matching permission.
CREATE POLICY resource_reader ON school.class_session AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('schedule.session.read'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.read', 'study_group'))))
  OR group_id IN (SELECT unnest((SELECT school.core_ids('schedule.session.read', 'study_group'))))
  OR school.is_own_staff(teacher_id));
CREATE POLICY resource_creator ON school.class_session AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('schedule.session.create'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.create', 'study_group')))));
CREATE POLICY resource_writer ON school.class_session AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('schedule.session.update')) OR (SELECT school.can_everywhere('schedule.session.cancel'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.update', 'study_group'))))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.cancel', 'study_group')))))
  WITH CHECK ((SELECT school.can_everywhere('schedule.session.update')) OR (SELECT school.can_everywhere('schedule.session.cancel'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.update', 'study_group'))))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.cancel', 'study_group')))));
CREATE POLICY resource_deleter ON school.class_session AS RESTRICTIVE FOR DELETE TO school_runtime USING (
  (SELECT school.can_everywhere('schedule.session.update'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.update', 'study_group')))));
CREATE POLICY resource_reader ON school.session_series AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('schedule.session.read'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.read', 'study_group'))))
  OR group_id IN (SELECT unnest((SELECT school.core_ids('schedule.session.read', 'study_group'))))
  OR school.is_own_staff(teacher_id));
CREATE POLICY resource_creator ON school.session_series AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('schedule.session.create'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.create', 'study_group')))));
CREATE POLICY resource_writer ON school.session_series AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('schedule.session.update'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.update', 'study_group')))))
  WITH CHECK ((SELECT school.can_everywhere('schedule.session.update'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.update', 'study_group')))));

-- The journal: in the pedagogy's domain, read with the planning.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('class_session', 'pedagogy', '{schedule.session.read}'), ('session_series', 'pedagogy', '{schedule.session.read}');
