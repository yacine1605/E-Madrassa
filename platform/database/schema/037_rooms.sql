-- Lot 2, rooms (SRS 1.2, FR-SLL-02, FR-SLL-01, FR-GRP-01): a site's rooms with their capacity,
-- type, equipment and state, their dated closures (works), and a group's main room. Lot 4's
-- sessions will check them: a room's capacity against the group's (AC1, a blocking warning), a
-- room unavailable or closed on a day takes no session (AC2).

-- The kinds of rooms and of equipment a school names (« paramétrable »): its own lists, with
-- the publisher's defaults given when the school receives the sites and rooms module. A code
-- never changes; an entry is archived, never deleted, and the rooms keep it.
CREATE TABLE platform.room_option_default (
  kind text NOT NULL CHECK (kind IN ('room_type', 'equipment')),
  code text NOT NULL CHECK (code ~ '^[A-Z0-9_]{1,20}$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  sort_order integer NOT NULL DEFAULT 0,
  PRIMARY KEY (kind, code)
);
INSERT INTO platform.room_option_default (kind, code, labels, sort_order) VALUES
  ('room_type', 'CLASSROOM', '{"fr": "Salle de classe", "ar": "قاعة دراسة"}', 1),
  ('room_type', 'LAB', '{"fr": "Laboratoire", "ar": "مخبر"}', 2),
  ('room_type', 'COMPUTER', '{"fr": "Salle informatique", "ar": "قاعة إعلام آلي"}', 3),
  ('room_type', 'AMPHI', '{"fr": "Amphithéâtre", "ar": "مدرج"}', 4),
  ('room_type', 'OTHER', '{"fr": "Autre", "ar": "أخرى"}', 5),
  ('equipment', 'PROJECTOR', '{"fr": "Vidéoprojecteur", "ar": "جهاز عرض"}', 1),
  ('equipment', 'WHITEBOARD', '{"fr": "Tableau blanc", "ar": "سبورة بيضاء"}', 2),
  ('equipment', 'SMARTBOARD', '{"fr": "Tableau interactif", "ar": "سبورة تفاعلية"}', 3),
  ('equipment', 'COMPUTERS', '{"fr": "Ordinateurs", "ar": "حواسيب"}', 4),
  ('equipment', 'AIR_CONDITIONING', '{"fr": "Climatisation", "ar": "تكييف"}', 5),
  ('equipment', 'LAB_BENCHES', '{"fr": "Paillasses", "ar": "طاولات مخبرية"}', 6);

CREATE TABLE school.room_option (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  kind text NOT NULL CHECK (kind IN ('room_type', 'equipment')),
  code text NOT NULL CHECK (code ~ '^[A-Z0-9_]{1,20}$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  sort_order integer NOT NULL DEFAULT 0 CHECK (sort_order BETWEEN 0 AND 100000),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, kind, code)
);
CALL school.protect('school.room_option');

-- The school's lists are the whole school's: `room.room.manage` for the whole school changes
-- them, the provisioner gives the publisher's. An entry keeps its kind and code; archived, it
-- stays archived.
CREATE FUNCTION school.guard_room_option() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT school.is_system() AND NOT school.can_everywhere('room.room.manage') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF TG_OP = 'UPDATE' THEN
    IF (NEW.kind, NEW.code) IS DISTINCT FROM (OLD.kind, OLD.code) THEN RAISE EXCEPTION 'code_immutable' USING ERRCODE = '23514'; END IF;
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'room_option_archived' USING ERRCODE = '23514'; END IF;
  ELSE
    NEW.archived_at := NULL;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.room_option FOR EACH ROW EXECUTE FUNCTION school.guard_room_option();

-- A school in use receives the publisher's lists with the sites and rooms module, at its creation
-- or later; an entry it has already, archived or not, stays as it is. A school that is not in use
-- writes nothing (suspended, expired, archived): it receives the module without them, and takes
-- them back itself once in use again (`/api/room-options/defaults`). Replaces 002's.
CREATE OR REPLACE FUNCTION platform.enable_module(p_tenant uuid, p_module text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM platform.module m WHERE m.key = p_module) THEN
    RAISE EXCEPTION 'unknown_module' USING ERRCODE = '23514';
  END IF;
  INSERT INTO platform.tenant_module (tenant_id, module_key)
  SELECT p_tenant, needed.key FROM (
    WITH RECURSIVE chain (key, depth) AS (
      SELECT p_module, 0
      UNION ALL
      SELECT d.requires_key, c.depth + 1 FROM platform.module_dependency d JOIN chain c ON d.module_key = c.key WHERE c.depth < 20
    ) SELECT key, max(depth) AS depth FROM chain GROUP BY key
  ) needed
  WHERE NOT EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = p_tenant AND m.module_key = needed.key)
  ORDER BY needed.depth DESC;
  IF platform.tenant_mode(p_tenant) = 'active'
     AND EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = p_tenant AND m.module_key = 'sites_rooms') THEN
    INSERT INTO school.room_option (tenant_id, kind, code, labels, sort_order)
    SELECT p_tenant, d.kind, d.code, d.labels, d.sort_order FROM platform.room_option_default d
    WHERE NOT EXISTS (SELECT 1 FROM school.room_option x WHERE x.tenant_id = p_tenant AND x.kind = d.kind AND x.code = d.code)
    ORDER BY d.kind, d.sort_order;
  END IF;
END $$;
-- The schools in use that have the module already, as 033 did for its permissions.
INSERT INTO school.room_option (tenant_id, kind, code, labels, sort_order, created_at, updated_at)
SELECT m.tenant_id, d.kind, d.code, d.labels, d.sort_order, now(), now() FROM platform.tenant_module m CROSS JOIN platform.room_option_default d
WHERE m.module_key = 'sites_rooms' AND platform.tenant_mode(m.tenant_id) = 'active';

-- A site's room: its code, unique on the site and never changed; its name; how many it seats;
-- its type and equipment among the school's lists; active or unavailable (repairs, no new
-- session); archived when it is no longer used, never deleted.
CREATE TABLE school.room (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  site_id uuid NOT NULL,
  code text NOT NULL CHECK (code ~ '^[A-Za-z0-9_-]{1,20}$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  capacity integer NOT NULL CHECK (capacity BETWEEN 1 AND 10000),
  room_type text NOT NULL,
  equipment text[] NOT NULL DEFAULT '{}',
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'unavailable')),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, site_id, code),
  -- Its type and equipment are codes of the school's lists (checked by its guard), whose entries are never deleted.
  FOREIGN KEY (tenant_id, site_id) REFERENCES school.site (tenant_id, id)
);
CALL school.protect('school.room');

-- Dated closures of a room (works): from one day until another, with the reason; never two at
-- a time; cancelled until their last day, never deleted.
CREATE TABLE school.room_closure (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  room_id uuid NOT NULL,
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
  FOREIGN KEY (tenant_id, room_id) REFERENCES school.room (tenant_id, id),
  CONSTRAINT room_closure_once EXCLUDE USING gist (tenant_id WITH =, room_id WITH =, daterange(date_from, date_to, '[]') WITH &&)
    WHERE (status = 'planned')
);
CREATE INDEX room_closure_room ON school.room_closure (tenant_id, room_id, date_from);
CALL school.protect('school.room_closure');

-- FR-GRP-01: a group's optional main room, on its site.
ALTER TABLE school.study_group ADD COLUMN main_room_id uuid, ADD FOREIGN KEY (tenant_id, main_room_id) REFERENCES school.room (tenant_id, id);
CREATE INDEX study_group_main_room ON school.study_group (tenant_id, main_room_id) WHERE main_room_id IS NOT NULL;

-- Who changes a room: `room.room.manage`, on the room's site or for the whole school (the
-- policies). A room keeps its site and code; its type and equipment are entries of the school's
-- lists still in use when given, each once; it never seats fewer than a group whose main room it
-- is, nor is archived while a group still in use has it as main room; archived, it stays as it is.
CREATE FUNCTION school.guard_room() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require('room.room.manage');
  IF TG_OP = 'INSERT' THEN
    NEW.archived_at := NULL;
  ELSE
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'room_archived' USING ERRCODE = '23514'; END IF;
    IF (NEW.site_id, NEW.code) IS DISTINCT FROM (OLD.site_id, OLD.code) THEN RAISE EXCEPTION 'code_immutable' USING ERRCODE = '23514'; END IF;
  END IF;
  IF (TG_OP = 'INSERT' OR NEW.room_type IS DISTINCT FROM OLD.room_type) AND NOT EXISTS (SELECT 1 FROM school.room_option o
      WHERE o.tenant_id = NEW.tenant_id AND o.kind = 'room_type' AND o.code = NEW.room_type AND o.archived_at IS NULL) THEN
    RAISE EXCEPTION 'room_option_unknown' USING ERRCODE = '23514';
  END IF;
  NEW.equipment := ARRAY(SELECT DISTINCT e FROM unnest(NEW.equipment) e ORDER BY e);
  IF EXISTS (SELECT 1 FROM unnest(NEW.equipment) e WHERE (TG_OP = 'INSERT' OR NOT e = ANY (OLD.equipment)) AND NOT EXISTS (
      SELECT 1 FROM school.room_option o WHERE o.tenant_id = NEW.tenant_id AND o.kind = 'equipment' AND o.code = e AND o.archived_at IS NULL)) THEN
    RAISE EXCEPTION 'room_option_unknown' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'UPDATE' AND NEW.archived_at IS NOT NULL AND EXISTS (SELECT 1 FROM school.study_group g
      WHERE g.tenant_id = NEW.tenant_id AND g.main_room_id = NEW.id AND g.archived_at IS NULL AND g.status <> 'closed') THEN
    RAISE EXCEPTION 'room_in_use' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'UPDATE' AND NEW.capacity < OLD.capacity AND EXISTS (SELECT 1 FROM school.study_group g
      WHERE g.tenant_id = NEW.tenant_id AND g.main_room_id = NEW.id AND g.archived_at IS NULL AND g.status <> 'closed' AND g.capacity > NEW.capacity) THEN
    RAISE EXCEPTION 'room_too_small' USING ERRCODE = '23514';
  END IF;
  IF EXISTS (SELECT 1 FROM school.site s WHERE s.tenant_id = NEW.tenant_id AND s.id = NEW.site_id AND s.archived_at IS NOT NULL) THEN
    RAISE EXCEPTION 'site_archived' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.room FOR EACH ROW EXECUTE FUNCTION school.guard_room();

-- Who closes a room: `room.room.manage` on its site. A closure keeps its room, days and reason;
-- it is cancelled until its last day. An archived room is closed no more.
CREATE FUNCTION school.guard_room_closure() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM school.require('room.room.manage');
  IF TG_OP = 'INSERT' THEN
    IF NEW.date_from < current_date THEN RAISE EXCEPTION 'room_closure_past' USING ERRCODE = '23514'; END IF;
    IF NEW.date_to < NEW.date_from THEN RAISE EXCEPTION 'room_closure_end_before_start' USING ERRCODE = '23514'; END IF;
    NEW.reason := nullif(btrim(NEW.reason), '');
    IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    NEW.status := 'planned'; NEW.cancelled_at := NULL; NEW.archived_at := NULL;
    IF EXISTS (SELECT 1 FROM school.room r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.room_id AND r.archived_at IS NOT NULL) THEN
      RAISE EXCEPTION 'room_archived' USING ERRCODE = '23514';
    END IF;
    -- One closure of a room at a time, even for two requests at once: the second finds the first.
    PERFORM pg_advisory_xact_lock(hashtextextended('room_closure:' || NEW.room_id::text, 37));
    IF EXISTS (SELECT 1 FROM school.room_closure x WHERE x.tenant_id = NEW.tenant_id AND x.room_id = NEW.room_id AND x.status = 'planned'
               AND daterange(x.date_from, x.date_to, '[]') && daterange(NEW.date_from, NEW.date_to, '[]')) THEN
      RAISE EXCEPTION 'room_closure_overlap' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF (NEW.room_id, NEW.date_from, NEW.date_to, NEW.reason, NEW.cancelled_at, NEW.archived_at)
     IS DISTINCT FROM (OLD.room_id, OLD.date_from, OLD.date_to, OLD.reason, OLD.cancelled_at, OLD.archived_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = OLD.status THEN RETURN NEW; END IF;
  IF (OLD.status, NEW.status) IS DISTINCT FROM ('planned', 'cancelled') THEN RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514'; END IF;
  IF OLD.date_to < current_date THEN RAISE EXCEPTION 'room_closure_ended' USING ERRCODE = '23514'; END IF;
  NEW.cancelled_at := now();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.room_closure FOR EACH ROW EXECUTE FUNCTION school.guard_room_closure();

-- FR-GRP-01, FR-SLL-02 AC1: a group's main room is on its site, still in use, available when
-- named, and seats the group: never fewer places than the group's capacity, whichever changes.
CREATE FUNCTION school.check_main_room() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r school.room;
BEGIN
  IF NEW.main_room_id IS NULL THEN RETURN NEW; END IF;
  IF TG_OP = 'UPDATE' AND NEW.main_room_id IS NOT DISTINCT FROM OLD.main_room_id AND NEW.capacity <= OLD.capacity THEN RETURN NEW; END IF;
  SELECT * INTO r FROM school.room WHERE tenant_id = NEW.tenant_id AND id = NEW.main_room_id;
  -- An unknown room is the foreign key's to refuse.
  IF r.id IS NULL THEN RETURN NEW; END IF;
  IF TG_OP = 'INSERT' OR NEW.main_room_id IS DISTINCT FROM OLD.main_room_id THEN
    IF r.site_id <> NEW.site_id THEN RAISE EXCEPTION 'room_other_site' USING ERRCODE = '23514'; END IF;
    IF r.archived_at IS NOT NULL THEN RAISE EXCEPTION 'room_archived' USING ERRCODE = '23514'; END IF;
    IF r.status <> 'active' THEN RAISE EXCEPTION 'room_unavailable' USING ERRCODE = '23514'; END IF;
  END IF;
  IF r.capacity < NEW.capacity THEN RAISE EXCEPTION 'room_too_small' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
-- After `guard`: the group's permission first.
CREATE TRIGGER main_room BEFORE INSERT OR UPDATE OF main_room_id, capacity ON school.study_group FOR EACH ROW EXECUTE FUNCTION school.check_main_room();

-- FR-SLL-01: a site whose rooms are still in use is not archived.
CREATE FUNCTION school.guard_site_rooms() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.archived_at IS NOT NULL AND OLD.archived_at IS NULL
     AND EXISTS (SELECT 1 FROM school.room r WHERE r.tenant_id = NEW.tenant_id AND r.site_id = NEW.id AND r.archived_at IS NULL) THEN
    RAISE EXCEPTION 'site_has_rooms' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER rooms BEFORE UPDATE OF archived_at ON school.site FOR EACH ROW EXECUTE FUNCTION school.guard_site_rooms();

-- FR-SLL-02 AC2, for lot 4's sessions: whether a room takes a session on a day: in use, not
-- unavailable, not closed that day. With the caller's rights.
CREATE FUNCTION school.room_available(p_room uuid, p_day date) RETURNS boolean
LANGUAGE sql STABLE AS $$
  SELECT EXISTS (SELECT 1 FROM school.room r WHERE r.tenant_id = school.tenant() AND r.id = p_room AND r.archived_at IS NULL AND r.status = 'active')
    AND NOT EXISTS (SELECT 1 FROM school.room_closure c WHERE c.tenant_id = school.tenant() AND c.room_id = p_room AND c.status = 'planned'
      AND p_day BETWEEN c.date_from AND c.date_to) $$;

-- Read by every member who reads the referential, as the sites (the API asks for
-- `referential.node.read`); written within the scope of `room.room.manage`: the room's site, or
-- the whole school, which alone changes the lists.
CREATE POLICY resource_creator ON school.room AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('room.room.manage')) OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('room.room.manage', 'site')))));
CREATE POLICY resource_writer ON school.room AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('room.room.manage')) OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('room.room.manage', 'site')))))
  WITH CHECK ((SELECT school.can_everywhere('room.room.manage')) OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('room.room.manage', 'site')))));
CREATE POLICY resource_creator ON school.room_closure AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('room.room.manage')) OR room_id IN (SELECT r.id FROM school.room r
    WHERE r.site_id IN (SELECT unnest((SELECT school.site_scope_ids('room.room.manage', 'site'))))));
CREATE POLICY resource_writer ON school.room_closure AS RESTRICTIVE FOR UPDATE TO school_runtime USING (
  (SELECT school.can_everywhere('room.room.manage')) OR room_id IN (SELECT r.id FROM school.room r
    WHERE r.site_id IN (SELECT unnest((SELECT school.site_scope_ids('room.room.manage', 'site'))))))
  WITH CHECK ((SELECT school.can_everywhere('room.room.manage')) OR room_id IN (SELECT r.id FROM school.room r
    WHERE r.site_id IN (SELECT unnest((SELECT school.site_scope_ids('room.room.manage', 'site'))))));
CREATE POLICY resource_creator ON school.room_option AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere('room.room.manage')));
CREATE POLICY resource_writer ON school.room_option AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('room.room.manage'))) WITH CHECK ((SELECT school.can_everywhere('room.room.manage')));

-- The journal: in the referential's domain, as the sites.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('room', 'referential', '{referential.node.read}'), ('room_closure', 'referential', '{referential.node.read}'),
  ('room_option', 'referential', '{referential.node.read}');
