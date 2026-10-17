-- Lot 4, the school's calendar (SRS 1.2, FR-PLN-01): its open days of the week (`calendar.open_days`,
-- 008), the dated exceptions that prevail over them for the whole school or one of its sites
-- (closures for a public holiday, a vacation or another reason, and exceptional opening days), and
-- the grid of time slots its sessions are planned on. The sessions to come take no day the school
-- or their site is closed (AC1, `school.day_open`).

-- Who keeps the calendar: the school's administrator and whoever plans the sessions.
INSERT INTO platform.permission (code, module_key, sensitive, labels) VALUES
  ('schedule.calendar.manage', 'scheduling_manual', false, '{"fr": "Gérer le calendrier et les créneaux", "ar": "تسيير الرزنامة والفترات الزمنية"}');
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES
  ('tenant_admin', 'schedule.calendar.manage'), ('academic_hr', 'schedule.calendar.manage');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, 'schedule.calendar.manage' FROM school.role r
WHERE r.template_code IN ('tenant_admin', 'academic_hr') AND platform.tenant_mode(r.tenant_id) = 'active'
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = 'schedule.calendar.manage');

-- A dated exception to the week's rule, for the whole school (no site) or one site: closed on a
-- public holiday, a vacation or for another reason, or exceptionally open (a make-up Saturday).
-- From one day until another, with its reason; never two of the same scope on a day; cancelled
-- until its last day, never deleted.
CREATE TABLE school.calendar_exception (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  site_id uuid,
  kind text NOT NULL CHECK (kind IN ('holiday', 'vacation', 'closure', 'opening')),
  date_from date NOT NULL,
  date_to date NOT NULL,
  reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
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
  FOREIGN KEY (tenant_id, site_id) REFERENCES school.site (tenant_id, id),
  CONSTRAINT calendar_exception_once EXCLUDE USING gist (tenant_id WITH =, (coalesce(site_id, '00000000-0000-0000-0000-000000000000'::uuid)) WITH =,
    daterange(date_from, date_to, '[]') WITH &&) WHERE (status = 'planned')
);
CREATE INDEX calendar_exception_days ON school.calendar_exception (tenant_id, date_from, date_to) WHERE status = 'planned';
CALL school.protect('school.calendar_exception');

-- Who keeps it: `schedule.calendar.manage`, for the whole school or on the site's grant (the
-- policies). An exception keeps its scope, kind, days and reason; it starts today or later, on a
-- site still in use, and is cancelled until its last day.
CREATE FUNCTION school.guard_calendar_exception() RETURNS trigger LANGUAGE plpgsql AS $$
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
    -- One exception of a scope a day, even for two requests at once: the second finds the first.
    PERFORM pg_advisory_xact_lock(hashtextextended('calendar_exception:' || NEW.tenant_id::text || ':' || coalesce(NEW.site_id::text, ''), 61));
    IF EXISTS (SELECT 1 FROM school.calendar_exception x WHERE x.tenant_id = NEW.tenant_id AND x.site_id IS NOT DISTINCT FROM NEW.site_id
               AND x.status = 'planned' AND daterange(x.date_from, x.date_to, '[]') && daterange(NEW.date_from, NEW.date_to, '[]')) THEN
      RAISE EXCEPTION 'calendar_exception_overlap' USING ERRCODE = '23514';
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
  NEW.cancelled_at := now();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.calendar_exception FOR EACH ROW EXECUTE FUNCTION school.guard_calendar_exception();

-- FR-PLN-01: whether the school is open on a day, on a site or for the whole school. A dated
-- exception prevails over the week's rule, the site's over the school's. With the caller's rights:
-- every member reads the calendar.
CREATE FUNCTION school.day_open(p_day date, p_site uuid DEFAULT NULL) RETURNS boolean
LANGUAGE sql STABLE AS $$
  SELECT coalesce((SELECT e.kind = 'opening' FROM school.calendar_exception e WHERE e.tenant_id = school.tenant() AND e.status = 'planned'
      AND p_day BETWEEN e.date_from AND e.date_to AND (e.site_id IS NULL OR e.site_id = p_site)
      ORDER BY e.site_id IS NULL LIMIT 1), school.open_day(p_day)) $$;

-- The grid of time slots: a code, a start and an end within a day, to the minute. A slot keeps its
-- code and times (the sessions planned on it keep theirs); archived when no longer offered, never
-- deleted; never two in use with the same times.
CREATE TABLE school.time_slot (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  code text NOT NULL CHECK (code ~ '^[A-Za-z0-9_-]{1,20}$'),
  start_time time NOT NULL,
  end_time time NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (end_time > start_time),
  CHECK (extract(second FROM start_time) = 0 AND extract(second FROM end_time) = 0),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, code)
);
CREATE UNIQUE INDEX time_slot_once ON school.time_slot (tenant_id, start_time, end_time) WHERE archived_at IS NULL;
CALL school.protect('school.time_slot');

-- The grid is the whole school's: `schedule.calendar.manage` for the whole school changes it.
CREATE FUNCTION school.guard_time_slot() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT school.is_system() AND NOT school.can_everywhere('schedule.calendar.manage') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF TG_OP = 'INSERT' THEN
    NEW.archived_at := NULL;
    RETURN NEW;
  END IF;
  IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'time_slot_archived' USING ERRCODE = '23514'; END IF;
  IF (NEW.code, NEW.start_time, NEW.end_time) IS DISTINCT FROM (OLD.code, OLD.start_time, OLD.end_time) THEN
    RAISE EXCEPTION 'time_slot_immutable' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.time_slot FOR EACH ROW EXECUTE FUNCTION school.guard_time_slot();

-- Read by every member of the school (the API asks for `schedule.session.read` or the calendar's
-- permission); an exception written for the whole school by a school-wide grant, for a site within
-- the grant's sites; the grid by a school-wide grant.
CREATE POLICY resource_creator ON school.calendar_exception AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('schedule.calendar.manage'))
  OR (site_id IS NOT NULL AND site_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.calendar.manage', 'site'))))));
CREATE POLICY resource_writer ON school.calendar_exception AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('schedule.calendar.manage'))
  OR (site_id IS NOT NULL AND site_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.calendar.manage', 'site'))))))
  WITH CHECK ((SELECT school.can_everywhere('schedule.calendar.manage'))
  OR (site_id IS NOT NULL AND site_id IN (SELECT unnest((SELECT school.site_scope_ids('schedule.calendar.manage', 'site'))))));
CREATE POLICY resource_creator ON school.time_slot AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere('schedule.calendar.manage')));
CREATE POLICY resource_writer ON school.time_slot AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('schedule.calendar.manage'))) WITH CHECK ((SELECT school.can_everywhere('schedule.calendar.manage')));

-- The journal: in the referential's domain, as the rooms.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('calendar_exception', 'referential', '{referential.node.read}'), ('time_slot', 'referential', '{referential.node.read}');
