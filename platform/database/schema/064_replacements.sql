-- Lot 4, replacements (SRS 1.2, FR-PLN-06): a session's teacher replaced by another, with the
-- reason, the replacement's conflicts checked again (qualified, available, free: AC1); the session
-- becomes `replaced` and keeps the teacher planned, who still sees it. Replacing it with the
-- teacher planned gives it back to them. Each cancellation, replacement and return is kept in the
-- group's history of its sessions (AC2) and told to the teachers and pupils concerned who read the
-- planning. A session with such a change is its own: its series no longer changes nor removes it.
-- Attendance, lot 4's next increments, credits the teacher who held it.

ALTER TABLE school.class_session
  ADD COLUMN replaced_teacher_id uuid,
  ADD COLUMN replace_reason text CHECK (length(replace_reason) BETWEEN 3 AND 1000),
  ADD COLUMN replaced_at timestamptz,
  ADD CONSTRAINT class_session_replaced_teacher FOREIGN KEY (tenant_id, replaced_teacher_id) REFERENCES school.staff (tenant_id, id),
  ADD CONSTRAINT class_session_replacement CHECK ((replaced_teacher_id IS NULL) = (replaced_at IS NULL)
    AND (status <> 'replaced' OR (replaced_teacher_id IS NOT NULL AND replace_reason IS NOT NULL))
    AND replaced_teacher_id IS DISTINCT FROM teacher_id);

-- The changes of a group's sessions, as they happened: cancelled, replaced, given back; the
-- teacher who held the session before, the one after, the reason, who and when. Never changed.
CREATE TABLE school.session_change (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  session_id uuid NOT NULL,
  group_id uuid NOT NULL,
  kind text NOT NULL CHECK (kind IN ('cancelled', 'replaced', 'restored')),
  teacher_id uuid NOT NULL,
  new_teacher_id uuid,
  reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 1000),
  changed_at timestamptz NOT NULL DEFAULT now(),
  changed_by bigint REFERENCES public.users(id),
  CHECK ((kind = 'cancelled') = (new_teacher_id IS NULL)),
  FOREIGN KEY (tenant_id, session_id) REFERENCES school.class_session (tenant_id, id),
  FOREIGN KEY (tenant_id, group_id) REFERENCES school.study_group (tenant_id, id),
  FOREIGN KEY (tenant_id, teacher_id) REFERENCES school.staff (tenant_id, id),
  FOREIGN KEY (tenant_id, new_teacher_id) REFERENCES school.staff (tenant_id, id)
);
CREATE INDEX session_change_group ON school.session_change (tenant_id, group_id, changed_at);
CREATE INDEX session_change_session ON school.session_change (tenant_id, session_id, changed_at);
ALTER TABLE school.session_change ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.session_change FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.session_change USING (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.session_change FOR EACH ROW EXECUTE FUNCTION school.immutable();

INSERT INTO platform.notification_event (code, labels, email_default) VALUES
  ('schedule.session_cancelled', '{"fr": "Une séance a été annulée", "ar": "أُلغيت حصة"}', true),
  ('schedule.session_replaced', '{"fr": "Un enseignant remplaçant a été désigné pour une séance", "ar": "عُيّن أستاذ مستخلف لحصة"}', true),
  ('schedule.session_restored', '{"fr": "Une séance retrouve son enseignant", "ar": "عادت حصة إلى أستاذها"}', true);

-- 062's guard, with the replacement: by `schedule.session.update`, before the session ends, with
-- its reason; only its teacher changes. The teacher planned is the database's to keep.
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

-- AC2 and the notice: each cancellation, replacement and return written to the history, and told,
-- for a session not yet over, to its teachers (the one planned, the one before, the one after) and
-- the pupils of the group that day, through their accounts, if they read the planning; the author
-- aside. With its owner's rights: the writer may not read the pupils nor the staff's accounts.
CREATE FUNCTION school.record_session_change() RETURNS trigger
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
  IF NEW.ends_at <= now() THEN RETURN NULL; END IF;
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
CREATE TRIGGER record_change AFTER UPDATE OF status, teacher_id ON school.class_session FOR EACH ROW EXECUTE FUNCTION school.record_session_change();

-- FR-PLN-06: the teachers who could replace a session's: still with the school, qualified,
-- available and free at its hours, with no other blocking conflict; the teacher planned among them
-- when it is replaced. With the reader's rights over the staff.
CREATE FUNCTION school.substitutes(p_session uuid) RETURNS SETOF uuid LANGUAGE sql STABLE AS $$
  SELECT s.id FROM school.class_session c JOIN school.staff s ON s.tenant_id = c.tenant_id AND s.id <> c.teacher_id
  WHERE c.tenant_id = school.tenant() AND c.id = p_session AND s.archived_at IS NULL AND s.status <> 'left'
    AND s.staff_type IN ('teacher_internal', 'teacher_external')
    AND NOT EXISTS (SELECT 1 FROM school.session_conflicts(c.group_id, c.subject_id, s.id, c.room_id, c.starts_at, c.ends_at, c.id) x WHERE x.blocking) $$;

-- The teacher replaced still reads the session; the history is read as the sessions are.
ALTER POLICY resource_reader ON school.class_session USING (
  (SELECT school.can_everywhere('schedule.session.read'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.read', 'study_group'))))
  OR group_id IN (SELECT unnest((SELECT school.core_ids('schedule.session.read', 'study_group'))))
  OR school.is_own_staff(teacher_id) OR school.is_own_staff(replaced_teacher_id));
CREATE POLICY resource_reader ON school.session_change AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('schedule.session.read'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.session.read', 'study_group'))))
  OR group_id IN (SELECT unnest((SELECT school.core_ids('schedule.session.read', 'study_group'))))
  OR school.is_own_staff(teacher_id) OR school.is_own_staff(new_teacher_id));
