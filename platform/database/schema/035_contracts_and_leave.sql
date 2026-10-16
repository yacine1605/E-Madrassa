-- Lot 2, the staff's contracts and leave (SRS 1.2, FR-STF-04): a member's contract, its type,
-- dates and contractual hours, in dated versions; their planned absences, a leave, a training,
-- a sickness. A contract carries no amount: pay is the remuneration rules' (FR-REM-01, lot 3),
-- under their own permission (AC1: academic_hr reads the contracts and no amount). A teacher's
-- leave makes them unavailable on its days (FR-STF-03), which lot 4's sessions will read to list
-- those to replace (AC2).

-- A contract's version: a type, from `valid_from` until `valid_to` (included; none for an open-
-- ended one), with the hours a week it provides for. One member's versions never overlap: an
-- amendment ends the version in force the day before its own first day. A version keeps what it
-- says; its end only comes earlier, with a reason if told. One not begun yet is deleted.
CREATE TABLE school.staff_contract (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  staff_id uuid NOT NULL,
  contract_type text NOT NULL CHECK (contract_type IN ('permanent', 'fixed_term', 'hourly', 'service', 'internship', 'other')),
  reference text CHECK (length(reference) BETWEEN 1 AND 60),
  valid_from date NOT NULL,
  valid_to date,
  weekly_hours numeric(5, 2) CHECK (weekly_hours > 0 AND weekly_hours <= 168),
  -- Masked in the journal (BR-17), as every `observation`.
  observation text CHECK (length(observation) BETWEEN 1 AND 1000),
  end_reason text CHECK (length(end_reason) BETWEEN 3 AND 500),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CHECK (valid_to IS NOT NULL OR contract_type NOT IN ('fixed_term', 'internship')),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, staff_id) REFERENCES school.staff (tenant_id, id),
  -- The rules say so first, with the advisory lock; this in the last resort.
  CONSTRAINT staff_contract_once EXCLUDE USING gist (tenant_id WITH =, staff_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&)
);
CALL school.protect('school.staff_contract');

-- A planned absence, from one day until another (included): its type, and what the school notes
-- of it, read with the leave (never by whoever reads the record only). Recorded by the staff's
-- manager, in force at once; cancelled until its last day, never deleted. Its days may have gone
-- by: a sickness is often recorded afterwards.
CREATE TABLE school.staff_leave (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  staff_id uuid NOT NULL,
  leave_type text NOT NULL CHECK (leave_type IN ('annual', 'sick', 'training', 'maternity', 'unpaid', 'exceptional', 'other')),
  date_from date NOT NULL,
  date_to date NOT NULL,
  observation text CHECK (length(observation) BETWEEN 1 AND 1000),
  status text NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'cancelled')),
  cancelled_at timestamptz,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (date_to >= date_from),
  CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL)),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, staff_id) REFERENCES school.staff (tenant_id, id),
  CONSTRAINT staff_leave_once EXCLUDE USING gist (tenant_id WITH =, staff_id WITH =, daterange(date_from, date_to, '[]') WITH &&)
    WHERE (status = 'planned')
);
CALL school.protect('school.staff_leave');

-- Who changes a contract: `staff.contract.manage` within its scope (the member's site, the whole
-- school). A version keeps its member, type, reference, first day, hours and notes; its end comes
-- earlier only, never before its first day; one not begun yet is deleted, the journal keeping it.
CREATE FUNCTION school.guard_staff_contract() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require('staff.contract.manage');
  PERFORM school.require_staff('staff.contract.manage', CASE WHEN TG_OP = 'INSERT' THEN NEW.staff_id ELSE OLD.staff_id END);
  IF TG_OP = 'DELETE' THEN
    IF OLD.valid_from <= current_date THEN RAISE EXCEPTION 'contract_started' USING ERRCODE = '23514'; END IF;
    RETURN OLD;
  END IF;
  IF TG_OP = 'INSERT' THEN
    NEW.valid_from := coalesce(NEW.valid_from, current_date);
    NEW.reference := nullif(btrim(NEW.reference), ''); NEW.observation := nullif(btrim(NEW.observation), '');
    NEW.end_reason := NULL; NEW.archived_at := NULL;
  ELSE
    IF (NEW.staff_id, NEW.contract_type, NEW.reference, NEW.valid_from, NEW.weekly_hours, NEW.observation, NEW.archived_at) IS DISTINCT FROM
       (OLD.staff_id, OLD.contract_type, OLD.reference, OLD.valid_from, OLD.weekly_hours, OLD.observation, OLD.archived_at) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF NEW.valid_to IS NOT DISTINCT FROM OLD.valid_to THEN
      IF NEW.end_reason IS DISTINCT FROM OLD.end_reason THEN RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514'; END IF;
      RETURN NEW;
    END IF;
    IF NEW.valid_to IS NULL OR NEW.valid_to > OLD.valid_to THEN RAISE EXCEPTION 'contract_end_later' USING ERRCODE = '23514'; END IF;
    NEW.end_reason := nullif(btrim(NEW.end_reason), '');
  END IF;
  IF NEW.valid_to < NEW.valid_from THEN RAISE EXCEPTION 'contract_end_before_start' USING ERRCODE = '23514'; END IF;
  IF NEW.valid_to IS NULL AND NEW.contract_type IN ('fixed_term', 'internship') THEN RAISE EXCEPTION 'contract_end_required' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.staff_contract FOR EACH ROW EXECUTE FUNCTION school.guard_staff_contract();

-- Who records a leave: `staff.leave.manage` within its scope. A leave keeps what it says; it is
-- cancelled until its last day.
CREATE FUNCTION school.guard_staff_leave() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require('staff.leave.manage');
  PERFORM school.require_staff('staff.leave.manage', CASE WHEN TG_OP = 'INSERT' THEN NEW.staff_id ELSE OLD.staff_id END);
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'leave_kept' USING ERRCODE = '23514'; END IF;
  IF TG_OP = 'INSERT' THEN
    IF NEW.date_to < NEW.date_from THEN RAISE EXCEPTION 'leave_end_before_start' USING ERRCODE = '23514'; END IF;
    NEW.observation := nullif(btrim(NEW.observation), '');
    NEW.status := 'planned'; NEW.cancelled_at := NULL; NEW.archived_at := NULL;
    RETURN NEW;
  END IF;
  IF (NEW.staff_id, NEW.leave_type, NEW.date_from, NEW.date_to, NEW.observation, NEW.cancelled_at, NEW.archived_at) IS DISTINCT FROM
     (OLD.staff_id, OLD.leave_type, OLD.date_from, OLD.date_to, OLD.observation, OLD.cancelled_at, OLD.archived_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = OLD.status THEN RETURN NEW; END IF;
  IF (OLD.status, NEW.status) IS DISTINCT FROM ('planned', 'cancelled') THEN RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514'; END IF;
  IF OLD.date_to < current_date THEN RAISE EXCEPTION 'leave_ended' USING ERRCODE = '23514'; END IF;
  NEW.cancelled_at := now();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.staff_leave FOR EACH ROW EXECUTE FUNCTION school.guard_staff_leave();

-- The rules that read beyond what the writer may see: a member still with the school receives a
-- contract or a leave (one who left keeps theirs as history), an archived record's no longer
-- change; a member's contracts and planned leaves never overlap, even for two requests at once.
CREATE FUNCTION school.check_staff_terms() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE r record := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; s school.staff;
BEGIN
  SELECT * INTO s FROM school.staff WHERE tenant_id = r.tenant_id AND id = r.staff_id;
  -- An unknown member is the foreign key's to refuse.
  IF s.id IS NULL THEN RETURN r; END IF;
  IF s.archived_at IS NOT NULL THEN RAISE EXCEPTION 'staff_archived' USING ERRCODE = '23514'; END IF;
  IF TG_OP <> 'INSERT' THEN RETURN r; END IF;
  IF s.status = 'left' THEN RAISE EXCEPTION 'staff_left' USING ERRCODE = '23514'; END IF;
  -- The same member's terms, one change at a time: the second waits for the first, then finds it.
  PERFORM pg_advisory_xact_lock(hashtextextended(TG_TABLE_NAME || ':' || r.staff_id::text, 35));
  IF TG_TABLE_NAME = 'staff_contract' THEN
    IF EXISTS (SELECT 1 FROM school.staff_contract x WHERE x.tenant_id = NEW.tenant_id AND x.staff_id = NEW.staff_id
               AND daterange(x.valid_from, x.valid_to, '[]') && daterange(NEW.valid_from, NEW.valid_to, '[]')) THEN
      RAISE EXCEPTION 'contract_overlap' USING ERRCODE = '23514';
    END IF;
  ELSIF EXISTS (SELECT 1 FROM school.staff_leave x WHERE x.tenant_id = NEW.tenant_id AND x.staff_id = NEW.staff_id AND x.status = 'planned'
               AND daterange(x.date_from, x.date_to, '[]') && daterange(NEW.date_from, NEW.date_to, '[]')) THEN
    RAISE EXCEPTION 'leave_overlap' USING ERRCODE = '23514';
  END IF;
  RETURN r;
END $$;
-- After `guard`: the permission first.
CREATE TRIGGER rules BEFORE INSERT OR UPDATE OR DELETE ON school.staff_contract FOR EACH ROW EXECUTE FUNCTION school.check_staff_terms();
CREATE TRIGGER rules BEFORE INSERT OR UPDATE ON school.staff_leave FOR EACH ROW EXECUTE FUNCTION school.check_staff_terms();

-- A teacher's leave makes them unavailable on its days (FR-STF-03, FR-STF-04): an unavailability
-- of the leave, in force at once whatever the school asks of declared ones, cancelled with it.
-- It has no reason of its own: whoever reads the record reads that the teacher is absent, never
-- why (a sickness); the leave's type and notes are read with the leave.
ALTER TABLE school.unavailability ADD COLUMN leave_id uuid, ADD FOREIGN KEY (tenant_id, leave_id) REFERENCES school.staff_leave (tenant_id, id),
  ADD UNIQUE (tenant_id, leave_id), ALTER COLUMN reason DROP NOT NULL, ADD CHECK ((leave_id IS NULL) = (reason IS NOT NULL));
-- A leave may cover days a teacher declared already; declared ones still never overlap.
ALTER TABLE school.unavailability DROP CONSTRAINT unavailability_once, ADD CONSTRAINT unavailability_once
  EXCLUDE USING gist (tenant_id WITH =, staff_id WITH =, daterange(date_from, date_to, '[]') WITH &&) WHERE (status IN ('pending', 'approved') AND leave_id IS NULL);

-- With the leave, in the writer's rights: written by whoever records the leave, every time, and
-- kept for a teacher only by the rules (a member who does not teach has no timetable).
CREATE FUNCTION school.follow_leave() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    INSERT INTO school.unavailability (tenant_id, staff_id, date_from, date_to, leave_id) VALUES (NEW.tenant_id, NEW.staff_id, NEW.date_from, NEW.date_to, NEW.id);
  ELSIF NEW.status = 'cancelled' AND OLD.status <> 'cancelled' THEN
    UPDATE school.unavailability SET status = 'cancelled' WHERE tenant_id = NEW.tenant_id AND leave_id = NEW.id AND status = 'approved';
  END IF;
  RETURN NULL;
END $$;
CREATE TRIGGER unavailability AFTER INSERT OR UPDATE OF status ON school.staff_leave FOR EACH ROW EXECUTE FUNCTION school.follow_leave();

-- 034's, and a leave's unavailability: written and cancelled with its leave, by `staff.leave.manage`,
-- never through the availability's own routes. Replaces 034's.
CREATE OR REPLACE FUNCTION school.guard_unavailability() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.leave_id IS NOT NULL THEN
      PERFORM school.require('staff.leave.manage');
      PERFORM school.require_staff('staff.leave.manage', NEW.staff_id);
      NEW.reason := NULL; NEW.archived_at := NULL; NEW.cancelled_at := NULL; NEW.decision_reason := NULL;
      NEW.status := 'approved'; NEW.decided_at := now(); NEW.decided_by := school.actor();
      RETURN NEW;
    END IF;
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
  IF (NEW.staff_id, NEW.date_from, NEW.date_to, NEW.reason, NEW.decided_at, NEW.decided_by, NEW.cancelled_at, NEW.archived_at, NEW.leave_id)
     IS DISTINCT FROM (OLD.staff_id, OLD.date_from, OLD.date_to, OLD.reason, OLD.decided_at, OLD.decided_by, OLD.cancelled_at, OLD.archived_at, OLD.leave_id) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = OLD.status AND NEW.decision_reason IS NOT DISTINCT FROM OLD.decision_reason THEN RETURN NEW; END IF;
  IF OLD.leave_id IS NOT NULL THEN
    -- Its leave cancelled, which the rules check.
    PERFORM school.require_staff('staff.leave.manage', OLD.staff_id);
    IF (OLD.status, NEW.status) IS DISTINCT FROM ('approved', 'cancelled') OR NEW.decision_reason IS DISTINCT FROM OLD.decision_reason THEN
      RAISE EXCEPTION 'unavailability_from_leave' USING ERRCODE = '23514';
    END IF;
    NEW.cancelled_at := now();
    RETURN NEW;
  END IF;
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

-- 034's, and a leave's unavailability: the leave's own days, while it is planned, for a teacher
-- only (a BEFORE trigger's NULL keeps no row for another member); cancelled once the leave is.
-- Declared ones overlap declared ones only. Replaces 034's.
CREATE OR REPLACE FUNCTION school.check_availability() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE r record := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; s school.staff;
BEGIN
  SELECT * INTO s FROM school.staff WHERE tenant_id = r.tenant_id AND id = r.staff_id;
  -- An unknown member is the foreign key's to refuse.
  IF s.id IS NULL THEN RETURN r; END IF;
  IF s.archived_at IS NOT NULL THEN RAISE EXCEPTION 'staff_archived' USING ERRCODE = '23514'; END IF;
  IF TG_TABLE_NAME = 'unavailability' THEN
    IF r.leave_id IS NOT NULL THEN
      IF TG_OP = 'INSERT' THEN
        IF s.staff_type NOT IN ('teacher_internal', 'teacher_external') THEN RETURN NULL; END IF;
        IF NOT EXISTS (SELECT 1 FROM school.staff_leave l WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.leave_id AND l.staff_id = NEW.staff_id
                       AND l.date_from = NEW.date_from AND l.date_to = NEW.date_to AND l.status = 'planned') THEN
          RAISE EXCEPTION 'unavailability_from_leave' USING ERRCODE = '23514';
        END IF;
      ELSIF TG_OP = 'UPDATE' AND NEW.status IS DISTINCT FROM OLD.status AND NOT EXISTS (
              SELECT 1 FROM school.staff_leave l WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.leave_id AND l.status = 'cancelled') THEN
        RAISE EXCEPTION 'unavailability_from_leave' USING ERRCODE = '23514';
      END IF;
      RETURN r;
    END IF;
  END IF;
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
               AND x.leave_id IS NULL AND x.status IN ('pending', 'approved') AND daterange(x.date_from, x.date_to, '[]') && daterange(NEW.date_from, NEW.date_to, '[]')) THEN
    RAISE EXCEPTION 'unavailability_overlap' USING ERRCODE = '23514';
  END IF;
  RETURN r;
END $$;

-- A leave's unavailability is written, and cancelled, by whoever records the leave. Replaces 034's.
ALTER POLICY resource_creator ON school.unavailability WITH CHECK (
  (SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage'))))
  OR (leave_id IS NOT NULL AND ((SELECT school.can_everywhere('staff.leave.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.leave.manage')))))));
ALTER POLICY resource_writer ON school.unavailability USING (
  (SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage'))))
  OR (SELECT school.can_everywhere('staff.availability.approve')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.approve'))))
  OR (leave_id IS NOT NULL AND ((SELECT school.can_everywhere('staff.leave.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.leave.manage')))))))
  WITH CHECK ((SELECT school.can_everywhere('staff.availability.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.manage'))))
  OR (SELECT school.can_everywhere('staff.availability.approve')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.availability.approve'))))
  OR (leave_id IS NOT NULL AND ((SELECT school.can_everywhere('staff.leave.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.leave.manage')))))));

-- Read with their permission (FR-IAM-04): the whole school, the record's site, one's own; written
-- within the scope of the permission to manage them.
CREATE POLICY resource_reader ON school.staff_contract AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('staff.contract.read')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.contract.read')))));
CREATE POLICY resource_creator ON school.staff_contract AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('staff.contract.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.contract.manage')))));
CREATE POLICY resource_writer ON school.staff_contract AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('staff.contract.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.contract.manage')))))
  WITH CHECK ((SELECT school.can_everywhere('staff.contract.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.contract.manage')))));
CREATE POLICY resource_deleter ON school.staff_contract AS RESTRICTIVE FOR DELETE TO school_runtime USING (
  (SELECT school.can_everywhere('staff.contract.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.contract.manage')))));
CREATE POLICY resource_reader ON school.staff_leave AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('staff.leave.read')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.leave.read')))));
CREATE POLICY resource_creator ON school.staff_leave AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('staff.leave.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.leave.manage')))));
CREATE POLICY resource_writer ON school.staff_leave AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('staff.leave.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.leave.manage')))))
  WITH CHECK ((SELECT school.can_everywhere('staff.leave.manage')) OR staff_id IN (SELECT unnest((SELECT school.staff_ids('staff.leave.manage')))));

-- Section 3.3: a teacher reads, within their own scope (P), their contracts and their leave. The
-- school's copies of the role receive them as the template does, as 033 did.
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES ('teacher', 'staff.contract.read'), ('teacher', 'staff.leave.read');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, p.code FROM school.role r
CROSS JOIN (VALUES ('staff.contract.read'), ('staff.leave.read')) p(code)
WHERE r.template_code = 'teacher' AND platform.tenant_mode(r.tenant_id) = 'active'
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = p.code);

-- The journal: in the staff's domain, read with the contracts and with the leave; a scoped reader
-- reads the entries of those they can see. Replaces 034's.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('staff_contract', 'staff', '{staff.contract.read}'), ('staff_leave', 'staff', '{staff.leave.read}');
ALTER POLICY core_resource_reader ON school.audit_log USING (
  object_type NOT IN ('student', 'guardian', 'student_guardian', 'program', 'program_subject', 'study_group', 'enrollment', 'group_membership', 'staff',
    'qualification', 'availability', 'unavailability', 'staff_contract', 'staff_leave') OR
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
  (object_type = 'unavailability' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT u.id FROM school.unavailability u))) OR
  (object_type = 'staff_contract' AND ((SELECT school.can_everywhere('staff.contract.read')) OR object_id IN (SELECT c.id FROM school.staff_contract c))) OR
  (object_type = 'staff_leave' AND ((SELECT school.can_everywhere('staff.leave.read')) OR object_id IN (SELECT l.id FROM school.staff_leave l))));
