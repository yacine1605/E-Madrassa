-- Lot 2, a teacher's availability (SRS 1.2, FR-STF-03): the hours of the week they may teach,
-- declared from a day on, with the hours a day and a week they teach at most and a week at least;
-- the days they may not, which prevail. Entered by the teacher on their own record or by their
-- manager; when the school asks for it, a change waits for the academic manager's approval. Lot
-- 4's sessions are checked against them (AC1: a session outside them is a conflict; AC2: more
-- than the hours a day allow, a warning).

-- Off by default: a change is in force as soon as it is recorded.
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('staff.availability.require_approval', 'boolean', 'false', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Disponibilités du personnel soumises à validation", "ar": "أوقات إتاحة الموظفين خاضعة للموافقة"}');

-- A weekly availability: its slots, each a day of the week (0 Sunday to 6 Saturday, as the
-- school's open days) from a time until another, in force from `valid_from` until a later
-- declaration approved; and its bounds. A change is a new declaration, the earlier ones kept
-- (BR-10); one with no slot says the teacher is available at no time. A declaration in force
-- never changes; a pending one is approved, refused with a reason, or withdrawn.
CREATE TABLE school.availability (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  staff_id uuid NOT NULL,
  valid_from date NOT NULL,
  slots jsonb NOT NULL DEFAULT '[]',
  min_weekly_hours numeric(5, 2) CHECK (min_weekly_hours >= 0 AND min_weekly_hours <= 168),
  max_daily_hours numeric(4, 2) CHECK (max_daily_hours > 0 AND max_daily_hours <= 24),
  max_weekly_hours numeric(5, 2) CHECK (max_weekly_hours > 0 AND max_weekly_hours <= 168),
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
  decided_at timestamptz,
  decided_by bigint REFERENCES public.users(id),
  decision_reason text CHECK (length(decision_reason) BETWEEN 3 AND 500),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (min_weekly_hours <= max_weekly_hours),
  CHECK (max_daily_hours <= max_weekly_hours),
  CHECK ((status = 'pending') = (decided_at IS NULL)),
  CHECK (status <> 'rejected' OR decision_reason IS NOT NULL),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, staff_id) REFERENCES school.staff (tenant_id, id)
);
-- One declaration a day for a teacher, refused ones aside: the rules say so first, this in the last resort.
CREATE UNIQUE INDEX availability_once ON school.availability (tenant_id, staff_id, valid_from) WHERE status <> 'rejected';
CALL school.protect('school.availability');

-- Days the teacher may not teach, from one day until another (included), with the reason; they
-- prevail over the weekly availability. A pending one is approved, refused or withdrawn; one
-- approved is cancelled until its last day, never deleted.
CREATE TABLE school.unavailability (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  staff_id uuid NOT NULL,
  date_from date NOT NULL,
  date_to date NOT NULL,
  reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
  decided_at timestamptz,
  decided_by bigint REFERENCES public.users(id),
  decision_reason text CHECK (length(decision_reason) BETWEEN 3 AND 500),
  cancelled_at timestamptz,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (date_to >= date_from),
  CHECK ((status = 'pending') = (decided_at IS NULL)),
  CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL)),
  CHECK (status <> 'rejected' OR decision_reason IS NOT NULL),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, staff_id) REFERENCES school.staff (tenant_id, id),
  CONSTRAINT unavailability_once EXCLUDE USING gist (tenant_id WITH =, staff_id WITH =, daterange(date_from, date_to, '[]') WITH &&)
    WHERE (status IN ('pending', 'approved'))
);
CREATE INDEX unavailability_staff ON school.unavailability (tenant_id, staff_id, date_from);
CALL school.protect('school.unavailability');

-- Whether a record is the caller's own: they never approve their own change (as nobody assigns
-- themselves a role, FR-IAM-05). Says nothing else of a record they may not read.
CREATE FUNCTION school.is_own_staff(p_staff uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM school.staff s WHERE s.tenant_id = school.allowed_tenant() AND s.id = p_staff AND s.user_id = school.actor()) $$;

-- Whether a change the caller makes to a teacher's availability is in force at once: always,
-- unless the school asks for an approval (`staff.availability.require_approval`) that the caller
-- may not give, on that record and not their own; it then waits for an approver.
CREATE FUNCTION school.availability_in_force(p_staff uuid) RETURNS boolean
LANGUAGE plpgsql STABLE AS $$
BEGIN
  IF school.is_system() OR NOT coalesce((school.setting_value('staff.availability.require_approval') #>> '{}')::boolean, false) THEN
    RETURN true;
  END IF;
  RETURN (school.can_everywhere('staff.availability.approve') OR p_staff = ANY (school.staff_ids('staff.availability.approve')))
    AND NOT school.is_own_staff(p_staff);
END $$;

-- A week's slots as the database keeps them: each an object of a day of the week (0 to 6) and
-- two times `HH:MM`, the first before the second (`24:00` ends a day); none overlapping on the
-- same day, adjacent ones allowed; ordered by day and time.
CREATE FUNCTION school.availability_slots(p_slots jsonb) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
  IF jsonb_typeof(p_slots) IS DISTINCT FROM 'array' OR jsonb_array_length(p_slots) > 70 OR EXISTS (
       SELECT 1 FROM jsonb_array_elements(p_slots) s
       WHERE jsonb_typeof(s) <> 'object'
         OR (SELECT array_agg(k ORDER BY k) FROM jsonb_object_keys(s) k) IS DISTINCT FROM ARRAY['end_time', 'start_time', 'weekday']
         OR jsonb_typeof(s -> 'weekday') <> 'number' OR (s ->> 'weekday') !~ '^[0-6]$'
         OR jsonb_typeof(s -> 'start_time') <> 'string' OR (s ->> 'start_time') !~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'
         OR jsonb_typeof(s -> 'end_time') <> 'string' OR (s ->> 'end_time') !~ '^(([01][0-9]|2[0-3]):[0-5][0-9]|24:00)$'
         OR (s ->> 'start_time') >= (s ->> 'end_time')) THEN
    RAISE EXCEPTION 'availability_slots_invalid' USING ERRCODE = '23514';
  END IF;
  IF EXISTS (SELECT 1 FROM (
       SELECT s ->> 'start_time' AS starts, lag(s ->> 'end_time') OVER (PARTITION BY s ->> 'weekday' ORDER BY s ->> 'start_time') AS previous_end
       FROM jsonb_array_elements(p_slots) s) x WHERE x.previous_end > x.starts) THEN
    RAISE EXCEPTION 'availability_slots_overlap' USING ERRCODE = '23514';
  END IF;
  RETURN (SELECT coalesce(jsonb_agg(jsonb_build_object('weekday', (s ->> 'weekday')::integer, 'start_time', s ->> 'start_time', 'end_time', s ->> 'end_time')
    ORDER BY (s ->> 'weekday')::integer, s ->> 'start_time'), '[]') FROM jsonb_array_elements(p_slots) s);
END $$;

-- The hours a week the slots offer.
CREATE FUNCTION school.availability_hours(p_slots jsonb) RETURNS numeric LANGUAGE sql IMMUTABLE AS $$
  SELECT coalesce(round(sum(extract(epoch FROM ((s ->> 'end_time')::time - (s ->> 'start_time')::time))) / 3600, 2), 0)
  FROM jsonb_array_elements(p_slots) s $$;

-- Who may write: `staff.availability.manage` on the record (the teacher's own with a personal
-- grant, the site's or the school's); who decides: `staff.availability.approve`, never on their
-- own record. A declaration keeps what it says; a pending one is decided once, an approved one
-- after its first day takes effect from the day it is approved.
CREATE FUNCTION school.guard_availability() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    PERFORM school.require('staff.availability.manage');
    PERFORM school.require_staff('staff.availability.manage', NEW.staff_id);
    NEW.valid_from := coalesce(NEW.valid_from, current_date);
    IF NEW.valid_from < current_date THEN RAISE EXCEPTION 'availability_past' USING ERRCODE = '23514'; END IF;
    NEW.slots := school.availability_slots(NEW.slots);
    IF NEW.min_weekly_hours > school.availability_hours(NEW.slots) THEN
      RAISE EXCEPTION 'availability_below_minimum' USING ERRCODE = '23514';
    END IF;
    NEW.archived_at := NULL;
    IF school.availability_in_force(NEW.staff_id) THEN
      NEW.status := 'approved'; NEW.decided_at := now(); NEW.decided_by := school.actor();
    ELSE
      NEW.status := 'pending'; NEW.decided_at := NULL; NEW.decided_by := NULL;
    END IF;
    NEW.decision_reason := NULL;
    RETURN NEW;
  END IF;
  IF TG_OP = 'DELETE' THEN
    PERFORM school.require_staff('staff.availability.manage', OLD.staff_id);
    IF OLD.status <> 'pending' THEN RAISE EXCEPTION 'availability_decided' USING ERRCODE = '23514'; END IF;
    RETURN OLD;
  END IF;
  IF (NEW.staff_id, NEW.valid_from, NEW.slots, NEW.min_weekly_hours, NEW.max_daily_hours, NEW.max_weekly_hours, NEW.decided_at, NEW.decided_by, NEW.archived_at)
     IS DISTINCT FROM (OLD.staff_id, OLD.valid_from, OLD.slots, OLD.min_weekly_hours, OLD.max_daily_hours, OLD.max_weekly_hours, OLD.decided_at, OLD.decided_by, OLD.archived_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = OLD.status AND NEW.decision_reason IS NOT DISTINCT FROM OLD.decision_reason THEN RETURN NEW; END IF;
  IF OLD.status <> 'pending' THEN RAISE EXCEPTION 'availability_decided' USING ERRCODE = '23514'; END IF;
  IF NEW.status NOT IN ('approved', 'rejected') THEN RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514'; END IF;
  PERFORM school.require_staff('staff.availability.approve', OLD.staff_id);
  IF NOT school.is_system() AND school.is_own_staff(OLD.staff_id) THEN RAISE EXCEPTION 'self_approval' USING ERRCODE = '23514'; END IF;
  IF NEW.status = 'rejected' AND NEW.decision_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  NEW.decided_at := now(); NEW.decided_by := school.actor();
  IF NEW.status = 'approved' THEN NEW.valid_from := greatest(OLD.valid_from, current_date); END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.availability FOR EACH ROW EXECUTE FUNCTION school.guard_availability();

-- The same for the days off. One approved is cancelled until its last day by whoever may put a
-- change in force at once; a teacher whose changes wait asks their manager.
CREATE FUNCTION school.guard_unavailability() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    PERFORM school.require('staff.availability.manage');
    PERFORM school.require_staff('staff.availability.manage', NEW.staff_id);
    IF NEW.date_from < current_date THEN RAISE EXCEPTION 'availability_past' USING ERRCODE = '23514'; END IF;
    IF NEW.date_to < NEW.date_from THEN RAISE EXCEPTION 'unavailability_end_before_start' USING ERRCODE = '23514'; END IF;
    NEW.reason := nullif(btrim(NEW.reason), '');
    IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    NEW.archived_at := NULL; NEW.cancelled_at := NULL; NEW.decision_reason := NULL;
    IF school.availability_in_force(NEW.staff_id) THEN
      NEW.status := 'approved'; NEW.decided_at := now(); NEW.decided_by := school.actor();
    ELSE
      NEW.status := 'pending'; NEW.decided_at := NULL; NEW.decided_by := NULL;
    END IF;
    RETURN NEW;
  END IF;
  IF TG_OP = 'DELETE' THEN
    PERFORM school.require_staff('staff.availability.manage', OLD.staff_id);
    IF OLD.status <> 'pending' THEN RAISE EXCEPTION 'availability_decided' USING ERRCODE = '23514'; END IF;
    RETURN OLD;
  END IF;
  IF (NEW.staff_id, NEW.date_from, NEW.date_to, NEW.reason, NEW.decided_at, NEW.decided_by, NEW.cancelled_at, NEW.archived_at)
     IS DISTINCT FROM (OLD.staff_id, OLD.date_from, OLD.date_to, OLD.reason, OLD.decided_at, OLD.decided_by, OLD.cancelled_at, OLD.archived_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = OLD.status AND NEW.decision_reason IS NOT DISTINCT FROM OLD.decision_reason THEN RETURN NEW; END IF;
  IF OLD.status = 'approved' AND NEW.status = 'cancelled' THEN
    PERFORM school.require_staff('staff.availability.manage', OLD.staff_id);
    IF NEW.decision_reason IS DISTINCT FROM OLD.decision_reason THEN RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514'; END IF;
    IF OLD.date_to < current_date THEN RAISE EXCEPTION 'unavailability_ended' USING ERRCODE = '23514'; END IF;
    IF NOT school.availability_in_force(OLD.staff_id) THEN RAISE EXCEPTION 'approval_required' USING ERRCODE = '23514'; END IF;
    NEW.cancelled_at := now();
    RETURN NEW;
  END IF;
  IF OLD.status <> 'pending' THEN RAISE EXCEPTION 'availability_decided' USING ERRCODE = '23514'; END IF;
  IF NEW.status NOT IN ('approved', 'rejected') THEN RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514'; END IF;
  PERFORM school.require_staff('staff.availability.approve', OLD.staff_id);
  IF NOT school.is_system() AND school.is_own_staff(OLD.staff_id) THEN RAISE EXCEPTION 'self_approval' USING ERRCODE = '23514'; END IF;
  IF NEW.status = 'rejected' AND NEW.decision_reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  IF NEW.status = 'approved' AND OLD.date_to < current_date THEN RAISE EXCEPTION 'unavailability_ended' USING ERRCODE = '23514'; END IF;
  NEW.decided_at := now(); NEW.decided_by := school.actor();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.unavailability FOR EACH ROW EXECUTE FUNCTION school.guard_unavailability();

-- The rules that read beyond what the writer may see: a teacher, internal or external, still
-- with the school (a member who left receives no availability, and none of theirs is approved);
-- an archived record's no longer change; one declaration a day, one unavailability at a time,
-- even for two requests at once.
CREATE FUNCTION school.check_availability() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE r record := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; s school.staff;
BEGIN
  SELECT * INTO s FROM school.staff WHERE tenant_id = r.tenant_id AND id = r.staff_id;
  -- An unknown member is the foreign key's to refuse.
  IF s.id IS NULL THEN RETURN r; END IF;
  IF s.archived_at IS NOT NULL THEN RAISE EXCEPTION 'staff_archived' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'INSERT' AND s.staff_type NOT IN ('teacher_internal', 'teacher_external') THEN
    RAISE EXCEPTION 'availability_not_teacher' USING ERRCODE = '23514';
  END IF;
  IF s.status = 'left' AND (TG_OP = 'INSERT' OR (TG_OP = 'UPDATE' AND NEW.status = 'approved' AND OLD.status = 'pending')) THEN
    RAISE EXCEPTION 'staff_left' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'DELETE' OR NEW.status NOT IN ('pending', 'approved') OR (TG_OP = 'UPDATE' AND NOT (OLD.status = 'pending' AND NEW.status = 'approved')) THEN
    RETURN r;
  END IF;
  -- The same teacher's availability, one change at a time: the second waits for the first, then finds it.
  PERFORM pg_advisory_xact_lock(hashtextextended('availability:' || r.staff_id::text, 34));
  IF TG_TABLE_NAME = 'availability' THEN
    IF EXISTS (SELECT 1 FROM school.availability x WHERE x.tenant_id = NEW.tenant_id AND x.staff_id = NEW.staff_id AND x.id <> NEW.id
               AND x.valid_from = NEW.valid_from AND x.status <> 'rejected') THEN
      RAISE EXCEPTION 'availability_same_day' USING ERRCODE = '23514';
    END IF;
  ELSIF TG_OP = 'INSERT' AND EXISTS (SELECT 1 FROM school.unavailability x WHERE x.tenant_id = NEW.tenant_id AND x.staff_id = NEW.staff_id
               AND x.status IN ('pending', 'approved') AND daterange(x.date_from, x.date_to, '[]') && daterange(NEW.date_from, NEW.date_to, '[]')) THEN
    RAISE EXCEPTION 'unavailability_overlap' USING ERRCODE = '23514';
  END IF;
  RETURN r;
END $$;
-- After `guard`: the permission first.
CREATE TRIGGER rules BEFORE INSERT OR UPDATE OR DELETE ON school.availability FOR EACH ROW EXECUTE FUNCTION school.check_availability();
CREATE TRIGGER rules BEFORE INSERT OR UPDATE OR DELETE ON school.unavailability FOR EACH ROW EXECUTE FUNCTION school.check_availability();

-- The weekly availability in force on a day: the approved declaration of the latest first day
-- not after it; none before the first one. With the caller's rights.
CREATE FUNCTION school.availability_on(p_staff uuid, p_day date DEFAULT current_date) RETURNS SETOF school.availability
LANGUAGE sql STABLE AS $$
  SELECT a.* FROM school.availability a WHERE a.tenant_id = school.tenant() AND a.staff_id = p_staff AND a.status = 'approved' AND a.valid_from <= p_day
  ORDER BY a.valid_from DESC, a.decided_at DESC LIMIT 1 $$;

-- FR-STF-03 AC1, for lot 4's sessions: whether a teacher may teach on a day from a time until
-- another. Never on a day of an approved unavailability, which prevails; else within the slots
-- of that day of the week in force (adjacent slots join), or at any time when they declared
-- none yet: their availability is then unknown, not empty. With the caller's rights, as
-- school.qualified (lot 4's rules read them all).
CREATE FUNCTION school.available(p_staff uuid, p_day date, p_start time, p_end time) RETURNS boolean
LANGUAGE sql STABLE AS $$
  WITH in_force AS (SELECT a.slots FROM school.availability_on(p_staff, p_day) a)
  SELECT NOT EXISTS (SELECT 1 FROM school.unavailability u WHERE u.tenant_id = school.tenant() AND u.staff_id = p_staff AND u.status = 'approved'
           AND p_day BETWEEN u.date_from AND u.date_to)
    AND (NOT EXISTS (SELECT 1 FROM in_force) OR coalesce((
      SELECT range_agg(int4range((extract(epoch FROM (s ->> 'start_time')::time) / 60)::integer, (extract(epoch FROM (s ->> 'end_time')::time) / 60)::integer))
             @> int4range((extract(epoch FROM p_start) / 60)::integer, (extract(epoch FROM p_end) / 60)::integer)
      FROM in_force, jsonb_array_elements(in_force.slots) s WHERE (s ->> 'weekday')::integer = extract(dow FROM p_day)), false)) $$;

-- Read with the teacher's record (FR-IAM-04): the whole school, the record's site, one's own;
-- declared and withdrawn within the scope of `staff.availability.manage`, decided within that of
-- `staff.availability.approve`; an unavailability cancelled within the first.
CREATE POLICY resource_reader ON school.availability AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('staff.profile.read')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.profile.read')))));
CREATE POLICY resource_creator ON school.availability AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage')))));
CREATE POLICY resource_writer ON school.availability AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('staff.availability.approve')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.approve')))))
  WITH CHECK ((SELECT school.can_everywhere('staff.availability.approve')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.approve')))));
CREATE POLICY resource_deleter ON school.availability AS RESTRICTIVE FOR DELETE TO school_runtime USING (
  (SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage')))));
CREATE POLICY resource_reader ON school.unavailability AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('staff.profile.read')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.profile.read')))));
CREATE POLICY resource_creator ON school.unavailability AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage')))));
CREATE POLICY resource_writer ON school.unavailability AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage'))))
  OR (SELECT school.can_everywhere('staff.availability.approve')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.approve')))))
  WITH CHECK ((SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage'))))
  OR (SELECT school.can_everywhere('staff.availability.approve')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.approve')))));
CREATE POLICY resource_deleter ON school.unavailability AS RESTRICTIVE FOR DELETE TO school_runtime USING (
  (SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage')))));

-- The journal: in the staff's domain, read with the records; a scoped reader reads the entries of
-- the declarations and days off they can see. Replaces 032's.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('availability', 'staff', '{staff.profile.read}'), ('unavailability', 'staff', '{staff.profile.read}');
ALTER POLICY core_resource_reader ON school.audit_log USING (
  object_type NOT IN ('student', 'guardian', 'student_guardian', 'program', 'program_subject', 'study_group', 'enrollment', 'group_membership', 'staff',
    'qualification', 'availability', 'unavailability') OR
  (SELECT school.can_everywhere('audit.log.read')) OR
  (object_type = 'student' AND ((SELECT school.can_everywhere('student.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('student.record.read', 'student')))))) OR
  (object_type = 'guardian' AND ((SELECT school.can_everywhere('guardian.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.read', 'guardian')))))) OR
  (object_type = 'student_guardian' AND (SELECT school.can_everywhere('guardian.record.read'))) OR
  (object_type = 'program' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('program.program.read', 'program')))))) OR
  (object_type = 'program_subject' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id IN (SELECT s.id FROM school.program_subject s))) OR
  (object_type = 'study_group' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'study_group')))))) OR
  (object_type = 'enrollment' AND ((SELECT school.can_everywhere('enrollment.enrollment.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment')))))) OR
  (object_type = 'group_membership' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'group_membership')))))) OR
  (object_type = 'staff' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT s.id FROM school.staff s))) OR
  (object_type = 'qualification' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT q.id FROM school.qualification q))) OR
  (object_type = 'availability' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT a.id FROM school.availability a))) OR
  (object_type = 'unavailability' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT u.id FROM school.unavailability u))));
