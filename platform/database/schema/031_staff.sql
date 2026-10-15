-- Lot 2, the staff (SRS 1.2, module STF; FR-STF-01): the school's teachers, internal or external,
-- its administrative staff and the others, each with a record whether or not they have an account
-- (principle P7). A staff number given once; a departure kept with its day, never a deletion
-- (BR-02): the sessions, hours and pay of lots 3 and 4 will keep pointing at the record.

-- FR-STF-01: a name in Arabic or Latin letters, or both; the kind of work; the day they were
-- hired; their state, active, on leave or left (with the day). Pay is never here (module REM, lot
-- 3). Like a pupil (029), a member of staff belongs to a site, administratively: the site's grants
-- record and follow them. Their account, when they have one, is the one they sign in with.
CREATE TABLE school.staff (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  staff_number text NOT NULL CHECK (staff_number ~ '^[A-Za-z0-9_-]{1,30}$'),
  last_name_ar text CHECK (length(last_name_ar) BETWEEN 1 AND 80),
  first_name_ar text CHECK (length(first_name_ar) BETWEEN 1 AND 80),
  last_name_lat text CHECK (length(last_name_lat) BETWEEN 1 AND 80),
  first_name_lat text CHECK (length(first_name_lat) BETWEEN 1 AND 80),
  staff_type text NOT NULL CHECK (staff_type IN ('teacher_internal', 'teacher_external', 'administrative', 'other')),
  phone text CHECK (length(phone) BETWEEN 1 AND 40),
  email text CHECK (length(email) BETWEEN 3 AND 200),
  hire_date date,
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'on_leave', 'left')),
  left_on date,
  site_id uuid NOT NULL,
  custom jsonb NOT NULL DEFAULT '{}',
  user_id bigint,
  name_key text GENERATED ALWAYS AS (school.search_key(coalesce(last_name_ar, '') || ' ' || coalesce(first_name_ar, '') || ' '
    || coalesce(last_name_lat, '') || ' ' || coalesce(first_name_lat, ''))) STORED,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (school.person_name_valid(last_name_ar, first_name_ar, last_name_lat, first_name_lat)),
  CHECK ((status = 'left') = (left_on IS NOT NULL)),
  UNIQUE (tenant_id, staff_number),
  UNIQUE (tenant_id, user_id),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, site_id) REFERENCES school.site (tenant_id, id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES school.membership (tenant_id, user_id)
);
CREATE INDEX staff_name ON school.staff (tenant_id, name_key);
CREATE INDEX staff_site ON school.staff (tenant_id, site_id);
CREATE TRIGGER staff_number_frozen BEFORE UPDATE ON school.staff FOR EACH ROW EXECUTE FUNCTION school.freeze_code('staff_number');
CALL school.protect('school.staff');

-- The records an action's scope reaches (FR-IAM-04): those of the grant's sites, and the reader's
-- own for a personal grant (a teacher's record). Empty for a grant of the whole school, which the
-- policies read first; a grant limited to levels, groups or subjects reaches no record yet.
CREATE FUNCTION school.staff_ids(p_permission text) RETURNS uuid[]
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); u bigint := school.actor(); r record; sites uuid[]; personal boolean := false; ids uuid[];
BEGIN
  IF t IS NULL THEN RETURN '{}'::uuid[]; END IF;
  FOR r IN SELECT e.scope_type, e.scope_ids FROM school.effective_permissions(u, t) e WHERE e.permission = p_permission LOOP
    CASE r.scope_type
      WHEN 'tenant' THEN RETURN '{}'::uuid[];
      WHEN 'site' THEN sites := sites || r.scope_ids;
      WHEN 'personal' THEN personal := true;
      ELSE NULL;
    END CASE;
  END LOOP;
  IF sites IS NULL AND NOT personal THEN RETURN '{}'::uuid[]; END IF;
  SELECT array_agg(s.id) INTO ids FROM school.staff s WHERE s.tenant_id = t AND (s.site_id = ANY (sites) OR (personal AND s.user_id = u));
  RETURN coalesce(ids, '{}'::uuid[]);
END $$;

CREATE FUNCTION school.require_staff(p_permission text, p_id uuid) RETURNS void
LANGUAGE plpgsql STABLE AS $$
BEGIN
  IF NOT school.is_system() AND NOT (school.can_everywhere(p_permission) OR p_id = ANY (school.staff_ids(p_permission))) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
END $$;

-- A number for whoever may record a member of staff somewhere: `P` and four digits or more, after
-- the highest such number of the school, one creation at a time; a number brought by the school
-- is kept as it is.
CREATE FUNCTION school.next_staff_number(p_tenant uuid) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE n bigint;
BEGIN
  IF p_tenant IS DISTINCT FROM school.allowed_tenant() OR NOT school.can('staff.profile.create') THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended('staff_number:' || p_tenant::text, 0));
  SELECT coalesce(max(substr(staff_number, 2)::bigint), 0) + 1 INTO n FROM school.staff
  WHERE tenant_id = p_tenant AND staff_number ~ '^P[0-9]{1,15}$';
  RETURN 'P' || lpad(n::text, greatest(4, length(n::text)), '0');
END $$;

-- FR-STF-01: who may write, what a record holds, the transitions of its state. A departure has
-- its day, today unless told, never after today nor before the hiring; coming back clears it.
-- Only a member who left is archived, and an archived record no longer changes.
CREATE FUNCTION school.guard_staff() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
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

-- An account is one member of staff's, whatever records the writer may read.
CREATE FUNCTION school.check_staff_account() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NEW.user_id IS NOT NULL AND EXISTS (SELECT 1 FROM school.staff WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id AND id <> NEW.id) THEN
    RAISE EXCEPTION 'staff_account_linked' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;

-- A site's grants record a member of staff on their sites, as a pupil (029): the permission is
-- the staff's. Replaces 029's.
CREATE OR REPLACE FUNCTION school.attach_site() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE action text := CASE TG_OP WHEN 'INSERT' THEN 'create' ELSE 'update' END;
  permission text := CASE TG_TABLE_NAME WHEN 'staff' THEN 'staff.profile.' ELSE TG_TABLE_NAME || '.record.' END || action; sites uuid[];
BEGIN
  IF TG_OP = 'UPDATE' AND NEW.site_id IS NOT DISTINCT FROM OLD.site_id THEN RETURN NEW; END IF;
  IF NOT school.is_system() AND NOT school.can_everywhere(permission) THEN
    sites := school.site_scope_ids(permission, 'site');
    IF NEW.site_id IS NULL AND cardinality(sites) = 1 THEN NEW.site_id := sites[1]; END IF;
    IF NEW.site_id IS NULL OR NOT NEW.site_id = ANY (sites) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  END IF;
  IF NEW.site_id IS NULL THEN
    SELECT id INTO NEW.site_id FROM school.site WHERE tenant_id = NEW.tenant_id AND is_default AND archived_at IS NULL;
    IF NEW.site_id IS NULL THEN RAISE EXCEPTION 'site_required' USING ERRCODE = '23514'; END IF;
  END IF;
  IF EXISTS (SELECT 1 FROM school.site WHERE tenant_id = NEW.tenant_id AND id = NEW.site_id AND archived_at IS NOT NULL) THEN
    RAISE EXCEPTION 'site_archived' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
-- The site first, then the record's own rules (its number), then the account's.
CREATE TRIGGER attach_site BEFORE INSERT OR UPDATE OF site_id ON school.staff FOR EACH ROW EXECUTE FUNCTION school.attach_site();
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.staff FOR EACH ROW EXECUTE FUNCTION school.guard_staff();
CREATE TRIGGER rules BEFORE INSERT OR UPDATE OF user_id ON school.staff FOR EACH ROW EXECUTE FUNCTION school.check_staff_account();

-- Who reads and writes which records: the whole school, the grant's sites (the row's own, so that
-- a record just created is read back at once), or one's own record.
CREATE POLICY resource_reader ON school.staff AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('staff.profile.read'))
  OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('staff.profile.read', 'site'))))
  OR id IN (SELECT unnest((SELECT school.staff_ids('staff.profile.read')))));
CREATE POLICY resource_creator ON school.staff AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('staff.profile.create'))
  OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('staff.profile.create', 'site')))));
CREATE POLICY resource_writer ON school.staff AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('staff.profile.update'))
    OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('staff.profile.update', 'site'))))
    OR id IN (SELECT unnest((SELECT school.staff_ids('staff.profile.update')))))
  WITH CHECK ((SELECT school.can_everywhere('staff.profile.update'))
    OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('staff.profile.update', 'site'))))
    OR id IN (SELECT unnest((SELECT school.staff_ids('staff.profile.update')))));

-- The journal (FR-AUD-01): the staff's domain, read by whoever reads the journal and the staff's
-- records (direction), not by the technical administrator; a scoped reader reads the entries of
-- the records they reach. Names, phone, e-mail and the school's fields are masked (BR-17).
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('staff', 'staff', '{staff.profile.read}');
ALTER POLICY core_resource_reader ON school.audit_log USING (
  object_type NOT IN ('student', 'guardian', 'student_guardian', 'program', 'program_subject', 'study_group', 'enrollment', 'group_membership', 'staff') OR
  (SELECT school.can_everywhere('audit.log.read')) OR
  (object_type = 'student' AND ((SELECT school.can_everywhere('student.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('student.record.read', 'student')))))) OR
  (object_type = 'guardian' AND ((SELECT school.can_everywhere('guardian.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.read', 'guardian')))))) OR
  (object_type = 'student_guardian' AND (SELECT school.can_everywhere('guardian.record.read'))) OR
  (object_type = 'program' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('program.program.read', 'program')))))) OR
  (object_type = 'program_subject' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id IN (SELECT s.id FROM school.program_subject s))) OR
  (object_type = 'study_group' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'study_group')))))) OR
  (object_type = 'enrollment' AND ((SELECT school.can_everywhere('enrollment.enrollment.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment')))))) OR
  (object_type = 'group_membership' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'group_membership')))))) OR
  (object_type = 'staff' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT s.id FROM school.staff s))));
