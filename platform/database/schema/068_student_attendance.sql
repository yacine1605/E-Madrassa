-- Lot 4, the pupils' roll call (SRS 1.2, FR-PRE-01; BR-14): a session begun is called by its
-- teacher, or by the supervisor, from the pupils its group has that day (AC1: one who joined it
-- later is not called); each marked present, absent, late or excused, with a justification. A
-- roll not called is no absence. A change keeps the former value, who changed it and when (AC2).
-- A teacher calls their own sessions only. Notices to the families wait for the portal (lot 10).

CREATE TABLE school.student_attendance (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  session_id uuid NOT NULL,
  student_id uuid NOT NULL,
  -- The session's group and teacher, kept by the database.
  group_id uuid NOT NULL,
  teacher_id uuid NOT NULL,
  status text NOT NULL CHECK (status IN ('present', 'absent', 'late', 'excused')),
  late_minutes integer CHECK (late_minutes BETWEEN 1 AND 600),
  note text CHECK (length(note) <= 500),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (late_minutes IS NULL OR status = 'late'),
  UNIQUE (tenant_id, session_id, student_id),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, session_id) REFERENCES school.class_session (tenant_id, id),
  FOREIGN KEY (tenant_id, student_id) REFERENCES school.student (tenant_id, id),
  FOREIGN KEY (tenant_id, group_id) REFERENCES school.study_group (tenant_id, id),
  FOREIGN KEY (tenant_id, teacher_id) REFERENCES school.staff (tenant_id, id)
);
CREATE INDEX student_attendance_student ON school.student_attendance (tenant_id, student_id);
CALL school.protect('school.student_attendance');

-- AC2: each change of a mark, the former and the new value, who and when; never changed.
CREATE TABLE school.student_attendance_change (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  attendance_id uuid NOT NULL,
  session_id uuid NOT NULL,
  student_id uuid NOT NULL,
  group_id uuid NOT NULL,
  teacher_id uuid NOT NULL,
  previous_status text NOT NULL,
  previous_late_minutes integer,
  previous_note text,
  status text NOT NULL,
  late_minutes integer,
  note text,
  changed_at timestamptz NOT NULL DEFAULT now(),
  changed_by bigint REFERENCES public.users(id),
  FOREIGN KEY (tenant_id, attendance_id) REFERENCES school.student_attendance (tenant_id, id)
);
CREATE INDEX student_attendance_change_session ON school.student_attendance_change (tenant_id, session_id, changed_at);
ALTER TABLE school.student_attendance_change ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.student_attendance_change FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.student_attendance_change USING (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.student_attendance_change FOR EACH ROW EXECUTE FUNCTION school.immutable();

-- AC1: the pupils a session calls: those its group has on its day. For whoever calls it (its
-- teacher, the supervisor of the school or the group's site), with its owner's rights: a teacher
-- does not read the enrolments.
CREATE FUNCTION school.roll_pupils(p_session uuid) RETURNS TABLE (student_id uuid)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT DISTINCT e.student_id FROM school.class_session c
  JOIN school.group_membership m ON m.tenant_id = c.tenant_id AND m.group_id = c.group_id
    AND m.valid_from <= school.local_day(c.starts_at) AND (m.valid_to IS NULL OR m.valid_to >= school.local_day(c.starts_at))
  JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id
  WHERE c.tenant_id = school.allowed_tenant() AND c.id = p_session
    AND (school.can_everywhere('attendance.student.entry.create') OR school.is_own_staff(c.teacher_id)
      OR c.group_id = ANY (school.site_scope_ids('attendance.student.entry.create', 'study_group'))) $$;

-- FR-PRE-01: marked by `attendance.student.entry.create`, changed by `attendance.student.entry.update`,
-- for a session begun and not cancelled, a pupil its group has that day; late minutes for a delay
-- only. Never deleted.
CREATE FUNCTION school.guard_student_attendance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c school.class_session;
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'INSERT' THEN
    PERFORM school.require('attendance.student.entry.create');
    SELECT * INTO c FROM school.class_session x WHERE x.tenant_id = NEW.tenant_id AND x.id = NEW.session_id;
    IF c.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    IF c.status = 'cancelled' THEN RAISE EXCEPTION 'attendance_session_cancelled' USING ERRCODE = '23514'; END IF;
    IF c.starts_at > now() THEN RAISE EXCEPTION 'attendance_future' USING ERRCODE = '23514'; END IF;
    IF NOT EXISTS (SELECT 1 FROM school.roll_pupils(NEW.session_id) p WHERE p.student_id = NEW.student_id) THEN
      RAISE EXCEPTION 'roll_not_member' USING ERRCODE = '23514';
    END IF;
    NEW.group_id := c.group_id; NEW.teacher_id := c.teacher_id; NEW.archived_at := NULL;
  ELSE
    PERFORM school.require('attendance.student.entry.update');
    IF (NEW.session_id, NEW.student_id, NEW.group_id, NEW.teacher_id, NEW.archived_at)
       IS DISTINCT FROM (OLD.session_id, OLD.student_id, OLD.group_id, OLD.teacher_id, OLD.archived_at) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
  END IF;
  NEW.note := nullif(btrim(NEW.note), '');
  IF NEW.late_minutes IS NOT NULL AND NEW.status <> 'late' THEN RAISE EXCEPTION 'roll_late_minutes' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.student_attendance FOR EACH ROW EXECUTE FUNCTION school.guard_student_attendance();

-- AC2: a mark changed is written to its history. With its owner's rights: the history is the database's.
CREATE FUNCTION school.record_student_attendance() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF school.tenant() IS DISTINCT FROM NEW.tenant_id THEN RETURN NULL; END IF;
  IF (NEW.status, NEW.late_minutes, NEW.note) IS NOT DISTINCT FROM (OLD.status, OLD.late_minutes, OLD.note) THEN RETURN NULL; END IF;
  INSERT INTO school.student_attendance_change (tenant_id, attendance_id, session_id, student_id, group_id, teacher_id, previous_status, previous_late_minutes,
    previous_note, status, late_minutes, note, changed_by)
  VALUES (NEW.tenant_id, NEW.id, NEW.session_id, NEW.student_id, NEW.group_id, NEW.teacher_id, OLD.status, OLD.late_minutes, OLD.note, NEW.status,
    NEW.late_minutes, NEW.note, school.actor());
  RETURN NULL;
END $$;
CREATE TRIGGER record_change AFTER UPDATE ON school.student_attendance FOR EACH ROW EXECUTE FUNCTION school.record_student_attendance();

-- Who calls and reads a roll: the session's teacher, their own sessions only; whoever calls rolls
-- for the whole school or on the group's site.
CREATE POLICY resource_reader ON school.student_attendance AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('attendance.student.entry.create'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('attendance.student.entry.create', 'study_group'))))
  OR school.is_own_staff(teacher_id));
CREATE POLICY resource_creator ON school.student_attendance AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('attendance.student.entry.create'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('attendance.student.entry.create', 'study_group'))))
  OR school.is_own_staff(teacher_id));
CREATE POLICY resource_writer ON school.student_attendance AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('attendance.student.entry.update'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('attendance.student.entry.update', 'study_group'))))
  OR school.is_own_staff(teacher_id));
CREATE POLICY resource_reader ON school.student_attendance_change AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('attendance.student.entry.create'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('attendance.student.entry.create', 'study_group'))))
  OR school.is_own_staff(teacher_id));

INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('student_attendance', 'pedagogy', '{attendance.student.entry.create}');
