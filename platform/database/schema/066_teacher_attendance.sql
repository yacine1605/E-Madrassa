-- Lot 4, the teachers' attendance (SRS 1.2, FR-PTG-01, FR-PTG-02; BR-08, BR-14): the supervisor
-- enters, session by session, whether its teacher was present, late (minutes), absent or replaced
-- (by whom), with the hours actually held when they differ; manually only. A session not entered
-- stays « not entered », never counted as an absence (AC1); a session to come is not entered (AC2),
-- nor one older than the school's delay. Its author corrects it until it is decided. Another person
-- validates or rejects it, never its author (TST-05); a rejection, with its reason, goes back to its
-- author. The entry locks the session; its validation makes it delivered. Hours (FR-PTG-03) next.

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('attendance.entry_days', 'integer', '7', 0, 60, 'tenant.settings.manage',
   '{"fr": "Délai de saisie du pointage (jours après la séance)", "ar": "مهلة تسجيل الحضور (أيام بعد الحصة)"}');
INSERT INTO platform.notification_event (code, labels, email_default) VALUES
  ('attendance.teacher_rejected', '{"fr": "Un pointage que vous avez saisi a été rejeté", "ar": "رُفض تسجيل حضور أدخلته"}', true);

CREATE TABLE school.teacher_attendance (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  session_id uuid NOT NULL,
  -- The session's group and teacher when entered, kept by the database.
  group_id uuid NOT NULL,
  teacher_id uuid NOT NULL,
  status text NOT NULL CHECK (status IN ('present', 'late', 'absent', 'replaced')),
  late_minutes integer CHECK (late_minutes BETWEEN 1 AND 600),
  actual_start time,
  actual_end time,
  replacement_teacher_id uuid,
  note text CHECK (length(note) <= 1000),
  validation_status text NOT NULL DEFAULT 'submitted' CHECK (validation_status IN ('submitted', 'validated', 'rejected')),
  decided_by bigint REFERENCES public.users(id),
  decided_at timestamptz,
  reject_reason text CHECK (length(reject_reason) BETWEEN 3 AND 1000),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK ((status = 'late') = (late_minutes IS NOT NULL)),
  CHECK ((status = 'replaced') = (replacement_teacher_id IS NOT NULL)),
  CHECK ((actual_start IS NULL) = (actual_end IS NULL) AND (actual_end IS NULL OR actual_end > actual_start)),
  CHECK ((validation_status = 'submitted') = (decided_at IS NULL)),
  CHECK ((validation_status = 'rejected') = (reject_reason IS NOT NULL)),
  UNIQUE (tenant_id, session_id),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, session_id) REFERENCES school.class_session (tenant_id, id),
  FOREIGN KEY (tenant_id, group_id) REFERENCES school.study_group (tenant_id, id),
  FOREIGN KEY (tenant_id, teacher_id) REFERENCES school.staff (tenant_id, id),
  FOREIGN KEY (tenant_id, replacement_teacher_id) REFERENCES school.staff (tenant_id, id)
);
CREATE INDEX teacher_attendance_teacher ON school.teacher_attendance (tenant_id, teacher_id);
CREATE INDEX teacher_attendance_replacement ON school.teacher_attendance (tenant_id, replacement_teacher_id) WHERE replacement_teacher_id IS NOT NULL;
CREATE INDEX teacher_attendance_pending ON school.teacher_attendance (tenant_id, validation_status) WHERE validation_status = 'submitted';
CALL school.protect('school.teacher_attendance');

-- FR-PTG-01: entered by `attendance.teacher.entry.create` for a session held or begun, neither
-- cancelled nor older than `attendance.entry_days`; corrected by its author alone with
-- `attendance.teacher.entry.update` until decided, then submitted again. FR-PTG-02: decided by
-- `attendance.teacher.validation.approve`, never by its author (BR-08), rejected with its reason;
-- validated, it changes no more here (corrections, FR-PTG-03). Never deleted.
CREATE FUNCTION school.guard_teacher_attendance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c school.class_session; days integer; hit record;
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  SELECT * INTO c FROM school.class_session x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.session_id;
  IF c.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF TG_OP = 'INSERT' THEN
    PERFORM school.require('attendance.teacher.entry.create');
    IF c.status = 'cancelled' THEN RAISE EXCEPTION 'attendance_session_cancelled' USING ERRCODE = '23514'; END IF;
    IF c.starts_at > now() THEN RAISE EXCEPTION 'attendance_future' USING ERRCODE = '23514'; END IF;
    days := coalesce((school.setting_value('attendance.entry_days') #>> '{}')::integer, 7);
    IF school.local_day(now()) - school.local_day(c.starts_at) > days THEN RAISE EXCEPTION 'attendance_too_late' USING ERRCODE = '23514'; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('teacher_attendance:' || NEW.session_id::text, 66));
    IF EXISTS (SELECT 1 FROM school.teacher_attendance x WHERE x.tenant_id = NEW.tenant_id AND x.session_id = NEW.session_id) THEN
      RAISE EXCEPTION 'attendance_exists' USING ERRCODE = '23514';
    END IF;
    NEW.group_id := c.group_id; NEW.teacher_id := c.teacher_id; NEW.archived_at := NULL;
    NEW.validation_status := 'submitted'; NEW.decided_by := NULL; NEW.decided_at := NULL; NEW.reject_reason := NULL;
  ELSE
    IF (NEW.session_id, NEW.group_id, NEW.teacher_id, NEW.archived_at) IS DISTINCT FROM (OLD.session_id, OLD.group_id, OLD.teacher_id, OLD.archived_at) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.validation_status = 'validated' THEN RAISE EXCEPTION 'attendance_validated' USING ERRCODE = '23514'; END IF;
    IF NEW.validation_status IS DISTINCT FROM OLD.validation_status THEN
      -- A decision on the entry as it is: validated, or rejected with its reason; never by its author (TST-05).
      IF OLD.validation_status <> 'submitted' OR NEW.validation_status NOT IN ('validated', 'rejected') THEN
        RAISE EXCEPTION 'attendance_not_submitted' USING ERRCODE = '23514';
      END IF;
      PERFORM school.require('attendance.teacher.validation.approve');
      PERFORM school.check_segregation('teacher_attendance', OLD.created_by);
      IF (NEW.status, NEW.late_minutes, NEW.actual_start, NEW.actual_end, NEW.replacement_teacher_id, NEW.note)
         IS DISTINCT FROM (OLD.status, OLD.late_minutes, OLD.actual_start, OLD.actual_end, OLD.replacement_teacher_id, OLD.note) THEN
        RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
      END IF;
      NEW.decided_by := school.actor(); NEW.decided_at := now();
      IF NEW.validation_status = 'rejected' THEN
        NEW.reject_reason := nullif(btrim(NEW.reject_reason), '');
        IF NEW.reject_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
      ELSE
        NEW.reject_reason := NULL;
      END IF;
      RETURN NEW;
    END IF;
    -- A correction by its author, before validation: submitted again.
    PERFORM school.require('attendance.teacher.entry.update');
    IF OLD.created_by IS DISTINCT FROM school.actor() THEN RAISE EXCEPTION 'attendance_not_author' USING ERRCODE = '23514'; END IF;
    NEW.validation_status := 'submitted'; NEW.decided_by := NULL; NEW.decided_at := NULL; NEW.reject_reason := NULL;
  END IF;
  -- The entry's own rules.
  NEW.note := nullif(btrim(NEW.note), '');
  IF (NEW.status = 'late') <> (NEW.late_minutes IS NOT NULL) THEN RAISE EXCEPTION 'attendance_late_minutes' USING ERRCODE = '23514'; END IF;
  IF (NEW.status = 'replaced') <> (NEW.replacement_teacher_id IS NOT NULL) THEN RAISE EXCEPTION 'attendance_replacement_required' USING ERRCODE = '23514'; END IF;
  IF (NEW.actual_start IS NULL) <> (NEW.actual_end IS NULL) OR NEW.actual_end <= NEW.actual_start OR (NEW.status = 'absent' AND NEW.actual_start IS NOT NULL) THEN
    RAISE EXCEPTION 'attendance_hours_invalid' USING ERRCODE = '23514';
  END IF;
  -- The substitute: a teacher still with the school, qualified, not elsewhere at those hours.
  IF NEW.status = 'replaced' AND (TG_OP = 'INSERT' OR NEW.replacement_teacher_id IS DISTINCT FROM OLD.replacement_teacher_id) THEN
    IF NEW.replacement_teacher_id = NEW.teacher_id THEN RAISE EXCEPTION 'session_replacement_same' USING ERRCODE = '23514'; END IF;
    FOR hit IN SELECT x.conflict FROM school.session_conflicts(c.group_id, c.subject_id, NEW.replacement_teacher_id, NULL, c.starts_at, c.ends_at, c.id) x
             WHERE x.blocking AND x.conflict IN ('session_not_teacher', 'session_teacher_left', 'session_teacher_not_qualified', 'session_teacher_busy') LIMIT 1 LOOP
      RAISE EXCEPTION '%', hit.conflict USING ERRCODE = '23514';
    END LOOP;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.teacher_attendance FOR EACH ROW EXECUTE FUNCTION school.guard_teacher_attendance();

-- 064's guard of a session, with the attendance's own changes: the entry locks the session, its
-- validation makes it held (`delivered`); nothing else of it changes that way.
CREATE OR REPLACE FUNCTION school.guard_class_session() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE by_series boolean := coalesce(current_setting('app.series_change', true), '') = 'on'; planned uuid;
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
    NEW.replaced_teacher_id := NULL; NEW.replace_reason := NULL; NEW.replaced_at := NULL;
    RETURN NEW;
  END IF;
  -- Attendance (066): its entry locks the session, its validation makes it held; by the database's own function alone.
  IF current_user = 'platform_provisioner' AND coalesce(current_setting('app.attendance_change', true), '') = 'on' THEN
    IF (NEW.group_id, NEW.subject_id, NEW.teacher_id, NEW.room_id, NEW.starts_at, NEW.ends_at, NEW.series_id, NEW.detached, NEW.cancel_reason,
        NEW.replaced_teacher_id, NEW.replace_reason, NEW.archived_at)
       IS DISTINCT FROM (OLD.group_id, OLD.subject_id, OLD.teacher_id, OLD.room_id, OLD.starts_at, OLD.ends_at, OLD.series_id, OLD.detached, OLD.cancel_reason,
        OLD.replaced_teacher_id, OLD.replace_reason, OLD.archived_at) OR NEW.status NOT IN (OLD.status, 'delivered') OR (OLD.locked AND NOT NEW.locked) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF NEW.series_id IS DISTINCT FROM OLD.series_id OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
     OR (NEW.replaced_teacher_id, NEW.replaced_at) IS DISTINCT FROM (OLD.replaced_teacher_id, OLD.replaced_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF OLD.status NOT IN ('planned', 'replaced') THEN RAISE EXCEPTION 'session_immutable' USING ERRCODE = '23514'; END IF;
  IF OLD.locked THEN RAISE EXCEPTION 'session_locked' USING ERRCODE = '23514'; END IF;
  IF NEW.status = 'cancelled' THEN
    PERFORM school.require('schedule.session.cancel');
    IF (NEW.group_id, NEW.subject_id, NEW.teacher_id, NEW.room_id, NEW.starts_at, NEW.ends_at)
       IS DISTINCT FROM (OLD.group_id, OLD.subject_id, OLD.teacher_id, OLD.room_id, OLD.starts_at, OLD.ends_at) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    NEW.cancel_reason := nullif(btrim(NEW.cancel_reason), '');
    IF NEW.cancel_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    NEW.cancelled_at := now(); NEW.replace_reason := OLD.replace_reason;
    RETURN NEW;
  END IF;
  -- FR-PLN-06: a replacement, another one, or the session given back to the teacher planned.
  IF NEW.status = 'replaced' OR OLD.status = 'replaced' THEN
    PERFORM school.require('schedule.session.update');
    IF NEW.status NOT IN ('planned', 'replaced') THEN RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514'; END IF;
    IF (NEW.group_id, NEW.subject_id, NEW.room_id, NEW.starts_at, NEW.ends_at)
       IS DISTINCT FROM (OLD.group_id, OLD.subject_id, OLD.room_id, OLD.starts_at, OLD.ends_at) THEN
      RAISE EXCEPTION 'session_replaced' USING ERRCODE = '23514';
    END IF;
    IF OLD.ends_at <= now() THEN RAISE EXCEPTION 'session_ended' USING ERRCODE = '23514'; END IF;
    IF NEW.teacher_id = OLD.teacher_id THEN RAISE EXCEPTION 'session_replacement_same' USING ERRCODE = '23514'; END IF;
    NEW.replace_reason := nullif(btrim(NEW.replace_reason), '');
    IF NEW.replace_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    planned := coalesce(OLD.replaced_teacher_id, OLD.teacher_id);
    IF NEW.teacher_id = planned THEN
      NEW.status := 'planned'; NEW.replaced_teacher_id := NULL; NEW.replaced_at := NULL;
    ELSE
      NEW.status := 'replaced'; NEW.replaced_teacher_id := planned; NEW.replaced_at := now();
    END IF;
    NEW.detached := OLD.detached OR OLD.series_id IS NOT NULL;
    NEW.locked := OLD.locked; NEW.cancel_reason := OLD.cancel_reason; NEW.cancelled_at := OLD.cancelled_at;
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
  NEW.locked := OLD.locked; NEW.cancel_reason := OLD.cancel_reason; NEW.cancelled_at := OLD.cancelled_at; NEW.replace_reason := OLD.replace_reason;
  RETURN NEW;
END $$;

-- After the entry: its session locked; validated, held (`delivered`) unless the teacher was absent;
-- rejected, its author told. With its owner's rights: the session changes by the database alone.
CREATE FUNCTION school.follow_teacher_attendance() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF school.tenant() IS DISTINCT FROM NEW.tenant_id THEN RETURN NULL; END IF;
  PERFORM set_config('app.attendance_change', 'on', true);
  IF TG_OP = 'INSERT' THEN
    UPDATE school.class_session SET locked = true WHERE tenant_id = NEW.tenant_id AND id = NEW.session_id AND NOT locked;
  ELSIF NEW.validation_status = 'validated' AND OLD.validation_status <> 'validated' AND NEW.status <> 'absent' THEN
    UPDATE school.class_session SET status = 'delivered' WHERE tenant_id = NEW.tenant_id AND id = NEW.session_id;
  ELSIF NEW.validation_status = 'rejected' AND OLD.validation_status <> 'rejected' AND OLD.created_by IS NOT NULL THEN
    PERFORM school.notify('attendance.teacher_rejected', ARRAY[OLD.created_by], jsonb_build_object('attendance_id', NEW.id, 'session_id', NEW.session_id));
  END IF;
  PERFORM set_config('app.attendance_change', '', true);
  RETURN NULL;
END $$;
CREATE TRIGGER follow AFTER INSERT OR UPDATE OF validation_status ON school.teacher_attendance FOR EACH ROW EXECUTE FUNCTION school.follow_teacher_attendance();

-- Who reads the entries: those who enter or validate them, for the whole school or the group's
-- site; a teacher their own, and those where they replaced. Who enters, corrects and decides: on
-- the group's site or for the whole school.
CREATE POLICY resource_reader ON school.teacher_attendance AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('attendance.teacher.entry.create')) OR (SELECT school.can_everywhere('attendance.teacher.validation.approve'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('attendance.teacher.entry.create', 'study_group'))))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('attendance.teacher.validation.approve', 'study_group'))))
  OR school.is_own_staff(teacher_id) OR school.is_own_staff(replacement_teacher_id));
CREATE POLICY resource_creator ON school.teacher_attendance AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('attendance.teacher.entry.create'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('attendance.teacher.entry.create', 'study_group')))));
CREATE POLICY resource_writer ON school.teacher_attendance AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('attendance.teacher.entry.update')) OR (SELECT school.can_everywhere('attendance.teacher.validation.approve'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('attendance.teacher.entry.update', 'study_group'))))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('attendance.teacher.validation.approve', 'study_group')))));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('teacher_attendance', 'pedagogy', '{attendance.teacher.entry.create,attendance.teacher.validation.approve}');
