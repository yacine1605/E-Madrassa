-- Lot 2, the staff's retention (FR-STF-01, NFR-DATA-03, BR-17): a member of staff who left, once
-- archived, is kept as long as the school says (`retention.staff_years`, 10 years after the
-- archiving by default, as a pupil's record), then anonymised by the daily run (`platform:retention`):
-- the names, phone, e-mail, the school's fields, the file's reference and the account's link; the
-- reference, notes and end's reason of their contracts, the notes of their leaves, the reasons of
-- their days off and of the decisions on their availability.
-- What stays: their number, type, site, dates, qualifications, contracts' types and hours, so that
-- lot 3 and 4's sessions, hours and pay keep pointing at the record (BR-02).

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('retention.staff_years', 'integer', '10', 1, 50, 'tenant.settings.manage',
   '{"fr": "Anonymiser la fiche d’un membre du personnel archivé après (années)", "ar": "إخفاء هوية بطاقة موظف مؤرشفة بعد (سنوات)"}');

ALTER TABLE school.staff ADD COLUMN anonymised_at timestamptz;
CREATE INDEX staff_retention ON school.staff (tenant_id, archived_at) WHERE archived_at IS NOT NULL AND anonymised_at IS NULL;

-- 031's, 034's and 035's guards, with the retention run let through.
CREATE OR REPLACE FUNCTION school.guard_staff() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
  IF TG_OP = 'INSERT' THEN
    PERFORM school.require('staff.profile.create');
    NEW.status := 'active'; NEW.left_on := NULL; NEW.archived_at := NULL;
  ELSE
    PERFORM school.require_staff('staff.profile.update', OLD.id);
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'staff_archived' USING ERRCODE = '23514'; END IF;
    IF NEW.archived_at IS NOT NULL AND NEW.status <> 'left' THEN RAISE EXCEPTION 'staff_archive_left' USING ERRCODE = '23514'; END IF;
  END IF;
  NEW.last_name_ar := nullif(btrim(NEW.last_name_ar), ''); NEW.first_name_ar := nullif(btrim(NEW.first_name_ar), '');
  NEW.last_name_lat := nullif(btrim(NEW.last_name_lat), ''); NEW.first_name_lat := nullif(btrim(NEW.first_name_lat), '');
  NEW.email := nullif(lower(btrim(NEW.email)), '');
  NEW.phone := nullif(btrim(NEW.phone), '');
  IF TG_OP = 'UPDATE' AND NEW.status IS DISTINCT FROM OLD.status AND (OLD.status, NEW.status) NOT IN
     (('active', 'on_leave'), ('on_leave', 'active'), ('active', 'left'), ('on_leave', 'left'), ('left', 'active')) THEN
    RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514';
  END IF;
  IF NEW.status <> 'left' THEN
    NEW.left_on := NULL;
  ELSIF NEW.left_on IS NULL THEN
    NEW.left_on := current_date;
  END IF;
  IF NEW.left_on > current_date THEN RAISE EXCEPTION 'left_on_future' USING ERRCODE = '23514'; END IF;
  IF NEW.left_on < NEW.hire_date THEN RAISE EXCEPTION 'left_before_hire' USING ERRCODE = '23514'; END IF;
  -- Nobody links their own account to a record (as nobody assigns themselves a role, FR-IAM-05).
  IF NEW.user_id IS NOT NULL AND NEW.user_id IS DISTINCT FROM (CASE WHEN TG_OP = 'UPDATE' THEN OLD.user_id END) THEN
    IF NOT school.is_system() AND NEW.user_id = school.actor() THEN RAISE EXCEPTION 'self_account' USING ERRCODE = '23514'; END IF;
    IF EXISTS (SELECT 1 FROM school.membership WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id AND status = 'revoked') THEN
      RAISE EXCEPTION 'membership_revoked' USING ERRCODE = '23514';
    END IF;
  END IF;
  IF TG_OP = 'INSERT' OR NEW.custom IS DISTINCT FROM OLD.custom THEN PERFORM school.check_custom_values('staff', NEW.custom); END IF;
  IF TG_OP = 'INSERT' AND NEW.staff_number IS NULL THEN NEW.staff_number := school.next_staff_number(NEW.tenant_id); END IF;
  RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION school.guard_staff_contract() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
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

CREATE OR REPLACE FUNCTION school.guard_staff_leave() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
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

CREATE OR REPLACE FUNCTION school.guard_unavailability() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
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

CREATE OR REPLACE FUNCTION school.guard_availability() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
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

CREATE OR REPLACE FUNCTION school.check_staff_terms() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE r record := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; s school.staff;
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
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

CREATE OR REPLACE FUNCTION school.check_availability() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE r record := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; s school.staff;
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
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

-- The members due in a school: archived for longer than its duration.
CREATE FUNCTION platform.staff_retention_candidates(p_tenant uuid) RETURNS TABLE (id uuid, due timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT s.id, s.archived_at + make_interval(years => platform.retention_value(p_tenant, 'retention.staff_years')) FROM school.staff s
  WHERE s.tenant_id = p_tenant AND s.archived_at IS NOT NULL AND s.anonymised_at IS NULL
    AND s.archived_at + make_interval(years => platform.retention_value(p_tenant, 'retention.staff_years')) <= now() $$;

-- The run, after the pupils' and the prospects': at most `p_limit` members per school, each with
-- their contracts, leaves and days off; its counts in the school's journal.
CREATE FUNCTION platform.apply_staff_retention(p_limit integer) RETURNS TABLE (tenant_id uuid, staff integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid; c record; n integer;
BEGIN
  PERFORM set_config('app.retention', 'on', true);
  PERFORM set_config('app.reason', 'retention', true);
  FOR t IN SELECT x.id FROM platform.tenant x ORDER BY x.id LOOP
    n := 0;
    FOR c IN SELECT r.id FROM platform.staff_retention_candidates(t) r ORDER BY r.due, r.id LIMIT p_limit LOOP
      UPDATE school.staff SET last_name_lat = 'ANONYME', first_name_lat = staff_number, last_name_ar = NULL, first_name_ar = NULL, phone = NULL,
        email = NULL, custom = '{}', external_ref = NULL, user_id = NULL, anonymised_at = now() WHERE school.staff.tenant_id = t AND school.staff.id = c.id;
      UPDATE school.staff_contract SET reference = NULL, observation = NULL, end_reason = CASE WHEN end_reason IS NOT NULL THEN 'ANONYME' END
        WHERE school.staff_contract.tenant_id = t AND staff_id = c.id;
      UPDATE school.staff_leave SET observation = NULL WHERE school.staff_leave.tenant_id = t AND staff_id = c.id;
      UPDATE school.unavailability SET reason = CASE WHEN reason IS NOT NULL THEN 'ANONYME' END,
        decision_reason = CASE WHEN decision_reason IS NOT NULL THEN 'ANONYME' END WHERE school.unavailability.tenant_id = t AND staff_id = c.id;
      UPDATE school.availability SET decision_reason = CASE WHEN decision_reason IS NOT NULL THEN 'ANONYME' END
        WHERE school.availability.tenant_id = t AND staff_id = c.id AND decision_reason IS NOT NULL;
      n := n + 1;
    END LOOP;
    IF n > 0 THEN
      INSERT INTO school.audit_log (tenant_id, action, object_type, object_id, after, reason)
      VALUES (t, 'RETENTION', 'tenant', t, jsonb_build_object('staff', n), 'retention');
      tenant_id := t; staff := n;
      RETURN NEXT;
    END IF;
  END LOOP;
  PERFORM set_config('app.retention', '', true);
  PERFORM set_config('app.reason', '', true);
END $$;

-- What the next run will do in the reader's school, the staff included: counts only. Replaces 042's.
CREATE OR REPLACE FUNCTION school.retention_due() RETURNS TABLE (category text, due integer)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NOT school.can_everywhere('tenant.settings.manage') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  RETURN QUERY SELECT k.category, (SELECT count(*)::integer FROM platform.retention_candidates(school.tenant()) r WHERE r.category = k.category)
    FROM unnest(ARRAY['archive', 'pupil', 'guardian', 'journal']) AS k(category)
    UNION ALL SELECT 'lead', (SELECT count(*)::integer FROM platform.lead_retention_candidates(school.tenant()))
    UNION ALL SELECT 'staff', (SELECT count(*)::integer FROM platform.staff_retention_candidates(school.tenant()));
END $$;
