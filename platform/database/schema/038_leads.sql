-- Lot 2, admission (SRS 1.2, module CRM; FR-CRM-01, FR-CRM-02): the first contacts of a family
-- until the pupil's enrolment. A prospect is no pupil: no matricule, no enrolment, in no group's
-- headcount (AC3). Its contact, its children with the level they aim at, its source and status
-- among the school's lists, whom it is assigned to; the interactions with it (calls, visits,
-- meetings), never changed; its reminders, which notify the assignee once due (AC1). Closed as
-- lost with its reason (AC2) or registered by its conversion, which makes the pupil, their
-- guardian and enrolment in one go and only once (FR-CRM-02 AC1).

-- The sources and statuses a school names (« liste paramétrable »): its own lists, with the
-- publisher's defaults given with the pupils and admission module, as the rooms' (037). A code
-- never changes; an entry is archived, never deleted, and the prospects keep it. Three statuses
-- are the pipeline's own: `new` (where a prospect starts), `registered` and `lost` (where it
-- ends); they are never archived. The others are steps the school orders as it likes.
CREATE TABLE platform.lead_option_default (
  kind text NOT NULL CHECK (kind IN ('source', 'status')),
  code text NOT NULL CHECK (code ~ '^[a-z0-9_]{1,30}$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  sort_order integer NOT NULL DEFAULT 0,
  PRIMARY KEY (kind, code)
);
INSERT INTO platform.lead_option_default (kind, code, labels, sort_order) VALUES
  ('source', 'word_of_mouth', '{"fr": "Bouche-à-oreille", "ar": "التوصية الشفهية"}', 1),
  ('source', 'social_media', '{"fr": "Réseaux sociaux", "ar": "شبكات التواصل الاجتماعي"}', 2),
  ('source', 'poster', '{"fr": "Affichage", "ar": "الملصقات"}', 3),
  ('source', 'other', '{"fr": "Autre", "ar": "أخرى"}', 4),
  ('status', 'new', '{"fr": "Nouveau", "ar": "جديد"}', 1),
  ('status', 'contacted', '{"fr": "Contacté", "ar": "تم الاتصال"}', 2),
  ('status', 'visit_scheduled', '{"fr": "Visite prévue", "ar": "زيارة مبرمجة"}', 3),
  ('status', 'visited', '{"fr": "Visite faite", "ar": "تمت الزيارة"}', 4),
  ('status', 'registered', '{"fr": "Inscrit", "ar": "مسجل"}', 5),
  ('status', 'lost', '{"fr": "Perdu", "ar": "ضائع"}', 6);

CREATE FUNCTION school.lead_status_reserved(p_code text) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
  SELECT p_code IN ('new', 'registered', 'lost') $$;
CREATE FUNCTION school.lead_status_closed(p_code text) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
  SELECT p_code IN ('registered', 'lost') $$;

CREATE TABLE school.lead_option (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  kind text NOT NULL CHECK (kind IN ('source', 'status')),
  code text NOT NULL CHECK (code ~ '^[a-z0-9_]{1,30}$'),
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
CALL school.protect('school.lead_option');

-- The school's lists are the whole school's: `crm.lead.update` for the whole school changes them,
-- the provisioner gives the publisher's. An entry keeps its kind and code; archived, it stays
-- archived; the pipeline's own statuses stay in use.
CREATE FUNCTION school.guard_lead_option() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT school.is_system() AND NOT school.can_everywhere('crm.lead.update') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF TG_OP = 'UPDATE' THEN
    IF (NEW.kind, NEW.code) IS DISTINCT FROM (OLD.kind, OLD.code) THEN RAISE EXCEPTION 'code_immutable' USING ERRCODE = '23514'; END IF;
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'lead_option_archived' USING ERRCODE = '23514'; END IF;
    IF NEW.archived_at IS NOT NULL AND NEW.kind = 'status' AND school.lead_status_reserved(NEW.code) THEN
      RAISE EXCEPTION 'lead_status_reserved' USING ERRCODE = '23514';
    END IF;
  ELSE
    NEW.archived_at := NULL;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.lead_option FOR EACH ROW EXECUTE FUNCTION school.guard_lead_option();

-- A school in use receives the publisher's lists with the pupils and admission module, at its
-- creation or later, as the rooms' (037); one that is not in use takes them back itself once in
-- use again (`/api/lead-options/defaults`). Replaces 037's.
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
  IF platform.tenant_mode(p_tenant) = 'active'
     AND EXISTS (SELECT 1 FROM platform.tenant_module m WHERE m.tenant_id = p_tenant AND m.module_key = 'students_crm') THEN
    INSERT INTO school.lead_option (tenant_id, kind, code, labels, sort_order)
    SELECT p_tenant, d.kind, d.code, d.labels, d.sort_order FROM platform.lead_option_default d
    WHERE NOT EXISTS (SELECT 1 FROM school.lead_option x WHERE x.tenant_id = p_tenant AND x.kind = d.kind AND x.code = d.code)
    ORDER BY d.kind, d.sort_order;
  END IF;
END $$;
-- The schools in use that have the module already.
INSERT INTO school.lead_option (tenant_id, kind, code, labels, sort_order, created_at, updated_at)
SELECT m.tenant_id, d.kind, d.code, d.labels, d.sort_order, now(), now() FROM platform.tenant_module m CROSS JOIN platform.lead_option_default d
WHERE m.module_key = 'students_crm' AND platform.tenant_mode(m.tenant_id) = 'active';

-- Whether a member of a school reads a site's prospects: an active member whose roles today give
-- `crm.lead.read` for the whole school or for that site. Whom a prospect is assigned to, whom its
-- reminders notify.
CREATE FUNCTION platform.lead_reader(p_tenant uuid, p_user bigint, p_site uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM school.effective_permissions(p_user, p_tenant) e WHERE e.permission = 'crm.lead.read'
    AND (e.scope_type = 'tenant' OR (e.scope_type = 'site' AND p_site = ANY (e.scope_ids)))) $$;

-- The members a prospect of a site may be assigned to (all sites: any of them), for whoever reads
-- prospects somewhere: their identifiers, which the people of the school name.
CREATE FUNCTION school.lead_assignees(p_site uuid) RETURNS SETOF bigint
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant();
BEGIN
  IF t IS NULL OR NOT school.can('crm.lead.read') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  RETURN QUERY SELECT m.user_id FROM school.membership m WHERE m.tenant_id = t AND m.status = 'active'
    AND EXISTS (SELECT 1 FROM school.effective_permissions(m.user_id, t) e WHERE e.permission = 'crm.lead.read'
      AND (e.scope_type = 'tenant' OR (e.scope_type = 'site' AND (p_site IS NULL OR p_site = ANY (e.scope_ids)))))
    ORDER BY m.user_id;
END $$;

CREATE FUNCTION school.lead_assignee_valid(p_user bigint, p_site uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT school.allowed_tenant() IS NOT NULL AND platform.lead_reader(school.allowed_tenant(), p_user, p_site) $$;

-- Whether the writer's grant of an action reaches a site: the whole school, or that site.
CREATE FUNCTION school.lead_in_scope(p_permission text, p_site uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT school.is_system() OR school.can_everywhere(p_permission) OR p_site = ANY (school.site_scope_ids(p_permission, 'site')) $$;

-- FR-CRM-01: a prospect. Its contact, by a name in any alphabet, with a phone or an e-mail to call
-- back; how they are related to the children (`self` for an adult who enrols); its source and
-- status, codes of the school's lists; the reason it was lost; whom it is assigned to. Like a
-- pupil (029), it belongs to a site, whose grants follow it. Never deleted: closed.
CREATE TABLE school.lead (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  site_id uuid NOT NULL,
  last_name text CHECK (length(last_name) BETWEEN 1 AND 80),
  first_name text CHECK (length(first_name) BETWEEN 1 AND 80),
  phone text CHECK (length(phone) BETWEEN 1 AND 40),
  email text CHECK (length(email) BETWEEN 3 AND 200),
  relationship text CHECK (relationship IN ('father', 'mother', 'guardian', 'self', 'other')),
  source text NOT NULL,
  status text NOT NULL DEFAULT 'new',
  lost_reason text CHECK (length(lost_reason) BETWEEN 3 AND 500),
  closed_at timestamptz,
  assigned_to bigint,
  custom jsonb NOT NULL DEFAULT '{}',
  name_key text GENERATED ALWAYS AS (school.search_key(coalesce(last_name, '') || ' ' || coalesce(first_name, ''))) STORED,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK (last_name IS NOT NULL OR first_name IS NOT NULL),
  CHECK (phone IS NOT NULL OR email IS NOT NULL),
  CHECK ((status = 'lost') = (lost_reason IS NOT NULL)),
  CHECK (school.lead_status_closed(status) = (closed_at IS NOT NULL)),
  UNIQUE (tenant_id, id),
  -- Its source and status are codes of the school's lists (checked by its guard), whose entries are never deleted.
  FOREIGN KEY (tenant_id, site_id) REFERENCES school.site (tenant_id, id),
  FOREIGN KEY (tenant_id, assigned_to) REFERENCES school.membership (tenant_id, user_id)
);
CREATE INDEX lead_site ON school.lead (tenant_id, site_id);
CREATE INDEX lead_assigned ON school.lead (tenant_id, assigned_to) WHERE assigned_to IS NOT NULL;
CALL school.protect('school.lead');

-- A prospect's child: their name, birth date if known, and the level they aim at, a node of the
-- referential (BR-13). Converted once into a pupil and their enrolment, linked here for good.
-- Archived when recorded by mistake, unless converted.
CREATE TABLE school.lead_child (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  lead_id uuid NOT NULL,
  last_name text CHECK (length(last_name) BETWEEN 1 AND 80),
  first_name text NOT NULL CHECK (length(first_name) BETWEEN 1 AND 80),
  birth_date date,
  level_id uuid,
  student_id uuid,
  enrollment_id uuid,
  conversion_key uuid,
  converted_at timestamptz,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK ((student_id IS NULL) = (converted_at IS NULL) AND (student_id IS NULL) = (conversion_key IS NULL)
    AND (student_id IS NULL) = (enrollment_id IS NULL)),
  CHECK (student_id IS NULL OR archived_at IS NULL),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, student_id),
  UNIQUE (tenant_id, conversion_key),
  FOREIGN KEY (tenant_id, lead_id) REFERENCES school.lead (tenant_id, id),
  FOREIGN KEY (tenant_id, level_id) REFERENCES school.ref_node (tenant_id, id),
  FOREIGN KEY (tenant_id, student_id) REFERENCES school.student (tenant_id, id),
  FOREIGN KEY (tenant_id, enrollment_id) REFERENCES school.enrollment (tenant_id, id)
);
CREATE INDEX lead_child_lead ON school.lead_child (tenant_id, lead_id);
CALL school.protect('school.lead_child');

-- FR-CRM-01, step 2: what happened with a prospect (a call, a visit, a meeting, a message, a
-- note), when, and what was said. Kept as written, never changed nor deleted (FR-CRM-02 AC2: the
-- pupil's sheet shows them).
CREATE TABLE school.lead_interaction (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  lead_id uuid NOT NULL,
  kind text NOT NULL CHECK (kind IN ('call', 'visit', 'meeting', 'message', 'note')),
  occurred_at timestamptz NOT NULL,
  body text NOT NULL CHECK (length(body) BETWEEN 1 AND 2000),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, lead_id) REFERENCES school.lead (tenant_id, id)
);
CREATE INDEX lead_interaction_lead ON school.lead_interaction (tenant_id, lead_id, occurred_at DESC);
CALL school.protect('school.lead_interaction');

-- A reminder: when to call the prospect back and why. Done or cancelled, never deleted; those
-- of a prospect closed are cancelled with it.
CREATE TABLE school.lead_reminder (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  lead_id uuid NOT NULL,
  due_at timestamptz NOT NULL,
  body text NOT NULL CHECK (length(body) BETWEEN 3 AND 500),
  status text NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'done', 'cancelled')),
  closed_at timestamptz,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK ((status = 'planned') = (closed_at IS NULL)),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, lead_id) REFERENCES school.lead (tenant_id, id)
);
CREATE INDEX lead_reminder_lead ON school.lead_reminder (tenant_id, lead_id, due_at);
CREATE INDEX lead_reminder_due ON school.lead_reminder (due_at) WHERE status = 'planned';
CALL school.protect('school.lead_reminder');

-- AC1: a due reminder notifies, once, the prospect's assignee then (its author when nobody is
-- assigned), if they still read the prospect's site; none otherwise. Written by the run alone.
CREATE TABLE school.lead_reminder_notice (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  reminder_id uuid NOT NULL,
  user_id bigint REFERENCES public.users(id),
  notified_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, reminder_id),
  FOREIGN KEY (tenant_id, reminder_id) REFERENCES school.lead_reminder (tenant_id, id)
);
ALTER TABLE school.lead_reminder_notice ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.lead_reminder_notice FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.lead_reminder_notice FOR SELECT USING (tenant_id = (SELECT school.allowed_tenant()));

-- FR-CRM-01: who writes a prospect and what it holds. Recorded on a site the writer's grant names
-- (the one given, else their only one, else the school's default site), with a status still in
-- use that is not closed. Its rules: a source and status of the school's lists; lost with its
-- reason (AC2); registered by its conversion only; a closed prospect changes no more, unless
-- reopened with a reason; assigned to a member who reads its site. The conversion's writer
-- (`crm.lead.convert`) registers it; any other change is `crm.lead.update`'s.
CREATE FUNCTION school.guard_lead() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE sites uuid[];
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NOT school.is_system() AND NOT school.can_everywhere('crm.lead.create') THEN
      sites := school.site_scope_ids('crm.lead.create', 'site');
      IF NEW.site_id IS NULL AND cardinality(sites) = 1 THEN NEW.site_id := sites[1]; END IF;
      IF NEW.site_id IS NULL OR NOT NEW.site_id = ANY (sites) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    END IF;
    IF NEW.site_id IS NULL THEN
      SELECT id INTO NEW.site_id FROM school.site WHERE tenant_id = NEW.tenant_id AND is_default AND archived_at IS NULL;
      IF NEW.site_id IS NULL THEN RAISE EXCEPTION 'site_required' USING ERRCODE = '23514'; END IF;
    END IF;
    NEW.status := coalesce(NEW.status, 'new');
    IF school.lead_status_closed(NEW.status) THEN RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514'; END IF;
    NEW.lost_reason := NULL; NEW.closed_at := NULL;
  ELSE
    IF (NEW.site_id, NEW.last_name, NEW.first_name, NEW.phone, NEW.email, NEW.relationship, NEW.source, NEW.assigned_to, NEW.custom)
       IS DISTINCT FROM (OLD.site_id, OLD.last_name, OLD.first_name, OLD.phone, OLD.email, OLD.relationship, OLD.source, OLD.assigned_to, OLD.custom)
       OR (NEW.status IS DISTINCT FROM OLD.status AND NEW.status <> 'registered') OR NEW.lost_reason IS DISTINCT FROM OLD.lost_reason THEN
      IF NOT school.lead_in_scope('crm.lead.update', OLD.site_id) OR NOT school.lead_in_scope('crm.lead.update', NEW.site_id) THEN
        RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
      END IF;
    END IF;
    IF NEW.status = 'registered' AND OLD.status <> 'registered'
       AND NOT school.lead_in_scope('crm.lead.convert', OLD.site_id) AND NOT school.lead_in_scope('crm.lead.update', OLD.site_id) THEN
      RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
    END IF;
    IF NEW.status IS DISTINCT FROM OLD.status THEN
      -- A closed prospect reopens with a reason, which the journal keeps.
      IF school.lead_status_closed(OLD.status) AND nullif(btrim(current_setting('app.reason', true)), '') IS NULL THEN
        RAISE EXCEPTION 'reopen_reason_required' USING ERRCODE = '23514';
      END IF;
      IF NEW.status = 'registered' AND NOT EXISTS (SELECT 1 FROM school.lead_child c
          WHERE c.tenant_id = NEW.tenant_id AND c.lead_id = NEW.id AND c.student_id IS NOT NULL) THEN
        RAISE EXCEPTION 'lead_not_converted' USING ERRCODE = '23514';
      END IF;
    ELSIF school.lead_status_closed(OLD.status)
       AND (NEW.site_id, NEW.last_name, NEW.first_name, NEW.phone, NEW.email, NEW.relationship, NEW.source, NEW.assigned_to, NEW.custom, NEW.lost_reason)
       IS DISTINCT FROM (OLD.site_id, OLD.last_name, OLD.first_name, OLD.phone, OLD.email, OLD.relationship, OLD.source, OLD.assigned_to, OLD.custom, OLD.lost_reason) THEN
      RAISE EXCEPTION 'lead_closed' USING ERRCODE = '23514';
    END IF;
  END IF;
  NEW.last_name := nullif(btrim(NEW.last_name), ''); NEW.first_name := nullif(btrim(NEW.first_name), '');
  NEW.email := nullif(lower(btrim(NEW.email)), ''); NEW.phone := nullif(btrim(NEW.phone), '');
  IF TG_OP = 'INSERT' OR NEW.status IS DISTINCT FROM OLD.status THEN
    IF NOT EXISTS (SELECT 1 FROM school.lead_option o WHERE o.tenant_id = NEW.tenant_id AND o.kind = 'status' AND o.code = NEW.status AND o.archived_at IS NULL) THEN
      RAISE EXCEPTION 'lead_option_unknown' USING ERRCODE = '23514';
    END IF;
    IF NEW.status = 'lost' THEN
      NEW.lost_reason := nullif(btrim(NEW.lost_reason), '');
      IF NEW.lost_reason IS NULL THEN RAISE EXCEPTION 'lost_reason_required' USING ERRCODE = '23514'; END IF;
    ELSE
      NEW.lost_reason := NULL;
    END IF;
    NEW.closed_at := CASE WHEN school.lead_status_closed(NEW.status) THEN now() END;
  END IF;
  IF (TG_OP = 'INSERT' OR NEW.source IS DISTINCT FROM OLD.source) AND NOT EXISTS (SELECT 1 FROM school.lead_option o
      WHERE o.tenant_id = NEW.tenant_id AND o.kind = 'source' AND o.code = NEW.source AND o.archived_at IS NULL) THEN
    RAISE EXCEPTION 'lead_option_unknown' USING ERRCODE = '23514';
  END IF;
  IF NEW.assigned_to IS NOT NULL AND (TG_OP = 'INSERT' OR (NEW.assigned_to, NEW.site_id) IS DISTINCT FROM (OLD.assigned_to, OLD.site_id))
     AND NOT school.lead_assignee_valid(NEW.assigned_to, NEW.site_id) THEN
    RAISE EXCEPTION 'lead_assignee_invalid' USING ERRCODE = '23514';
  END IF;
  IF (TG_OP = 'INSERT' OR NEW.site_id IS DISTINCT FROM OLD.site_id)
     AND EXISTS (SELECT 1 FROM school.site s WHERE s.tenant_id = NEW.tenant_id AND s.id = NEW.site_id AND s.archived_at IS NOT NULL) THEN
    RAISE EXCEPTION 'site_archived' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'INSERT' OR NEW.custom IS DISTINCT FROM OLD.custom THEN PERFORM school.check_custom_values('lead', NEW.custom); END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.lead FOR EACH ROW EXECUTE FUNCTION school.guard_lead();

-- A prospect closed (registered or lost) has nothing more to call back for. With the run's
-- rights: the conversion's writer may not change reminders.
CREATE FUNCTION school.close_lead_reminders() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF school.lead_status_closed(NEW.status) AND NOT school.lead_status_closed(OLD.status) THEN
    UPDATE school.lead_reminder SET status = 'cancelled' WHERE tenant_id = NEW.tenant_id AND lead_id = NEW.id AND status = 'planned';
  END IF;
  RETURN NULL;
END $$;
CREATE TRIGGER close_reminders AFTER UPDATE OF status ON school.lead FOR EACH ROW EXECUTE FUNCTION school.close_lead_reminders();

-- A prospect's child: written while the prospect is open by whoever records or follows it on
-- its site, with a level still in use. FR-CRM-02: converted once, by `crm.lead.convert`, unless
-- the prospect was lost; a converted child is the pupil's record now, and stays as it is.
CREATE FUNCTION school.guard_lead_child() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE l school.lead;
BEGIN
  SELECT * INTO l FROM school.lead WHERE tenant_id = NEW.tenant_id AND id = NEW.lead_id;
  -- An unknown prospect is the foreign key's to refuse.
  IF l.id IS NULL THEN RETURN NEW; END IF;
  IF TG_OP = 'UPDATE' AND OLD.student_id IS NOT NULL THEN
    IF (NEW.lead_id, NEW.last_name, NEW.first_name, NEW.birth_date, NEW.level_id, NEW.student_id,
        NEW.enrollment_id, NEW.conversion_key, NEW.converted_at, NEW.archived_at) IS DISTINCT FROM (OLD.lead_id, OLD.last_name, OLD.first_name,
        OLD.birth_date, OLD.level_id, OLD.student_id, OLD.enrollment_id, OLD.conversion_key, OLD.converted_at, OLD.archived_at) THEN
      RAISE EXCEPTION 'lead_already_converted' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF TG_OP = 'UPDATE' AND NEW.student_id IS NOT NULL THEN
    -- The conversion: these columns only, the child as it was.
    IF (NEW.lead_id, NEW.last_name, NEW.first_name, NEW.birth_date, NEW.level_id, NEW.archived_at)
       IS DISTINCT FROM (OLD.lead_id, OLD.last_name, OLD.first_name, OLD.birth_date, OLD.level_id, OLD.archived_at) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF NOT school.lead_in_scope('crm.lead.convert', l.site_id) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'lead_child_archived' USING ERRCODE = '23514'; END IF;
    IF l.status = 'lost' THEN RAISE EXCEPTION 'lead_closed' USING ERRCODE = '23514'; END IF;
    -- The pupil's own enrolment, as the conversion made them.
    IF NOT EXISTS (SELECT 1 FROM school.enrollment e WHERE e.tenant_id = NEW.tenant_id AND e.id = NEW.enrollment_id AND e.student_id = NEW.student_id) THEN
      RAISE EXCEPTION 'lead_conversion_mismatch' USING ERRCODE = '23514';
    END IF;
    NEW.converted_at := now();
    RETURN NEW;
  END IF;
  IF NOT school.lead_in_scope('crm.lead.update', l.site_id) AND NOT (TG_OP = 'INSERT' AND school.lead_in_scope('crm.lead.create', l.site_id)) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF TG_OP = 'INSERT' THEN
    NEW.student_id := NULL; NEW.enrollment_id := NULL; NEW.conversion_key := NULL; NEW.converted_at := NULL; NEW.archived_at := NULL;
  ELSE
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'lead_child_archived' USING ERRCODE = '23514'; END IF;
    IF NEW.lead_id IS DISTINCT FROM OLD.lead_id THEN RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514'; END IF;
  END IF;
  IF school.lead_status_closed(l.status) THEN RAISE EXCEPTION 'lead_closed' USING ERRCODE = '23514'; END IF;
  NEW.last_name := nullif(btrim(NEW.last_name), ''); NEW.first_name := nullif(btrim(NEW.first_name), '');
  IF NEW.birth_date > current_date THEN RAISE EXCEPTION 'birth_date_future' USING ERRCODE = '23514'; END IF;
  IF NEW.level_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.level_id IS DISTINCT FROM OLD.level_id)
     AND EXISTS (SELECT 1 FROM school.ref_node n WHERE n.tenant_id = NEW.tenant_id AND n.id = NEW.level_id AND n.archived_at IS NOT NULL) THEN
    RAISE EXCEPTION 'node_archived' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.lead_child FOR EACH ROW EXECUTE FUNCTION school.guard_lead_child();

-- An interaction is recorded by whoever follows the prospect on its site, closed or not (a lost
-- family that calls back is heard), at the time it happened, never later than now.
CREATE FUNCTION school.guard_lead_interaction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE site uuid;
BEGIN
  IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  SELECT l.site_id INTO site FROM school.lead l WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.lead_id;
  IF site IS NULL THEN RETURN NEW; END IF;
  IF NOT school.lead_in_scope('crm.lead.update', site) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  NEW.occurred_at := coalesce(NEW.occurred_at, now());
  IF NEW.occurred_at > now() + interval '5 minutes' THEN RAISE EXCEPTION 'interaction_future' USING ERRCODE = '23514'; END IF;
  NEW.body := nullif(btrim(NEW.body), '');
  IF NEW.body IS NULL THEN RAISE EXCEPTION 'interaction_body_required' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.lead_interaction FOR EACH ROW EXECUTE FUNCTION school.guard_lead_interaction();

-- A reminder is set on an open prospect, for later, by whoever follows it on its site; it is
-- then done or cancelled, nothing else. The run of a closed prospect cancels its own.
CREATE FUNCTION school.guard_lead_reminder() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE l school.lead;
BEGIN
  SELECT * INTO l FROM school.lead WHERE tenant_id = NEW.tenant_id AND id = NEW.lead_id;
  IF l.id IS NULL THEN RETURN NEW; END IF;
  IF NOT school.lead_in_scope('crm.lead.update', l.site_id) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF TG_OP = 'INSERT' THEN
    IF school.lead_status_closed(l.status) THEN RAISE EXCEPTION 'lead_closed' USING ERRCODE = '23514'; END IF;
    IF NEW.due_at < now() - interval '1 minute' THEN RAISE EXCEPTION 'reminder_past' USING ERRCODE = '23514'; END IF;
    NEW.body := nullif(btrim(NEW.body), '');
    IF NEW.body IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
    NEW.status := 'planned'; NEW.closed_at := NULL;
    RETURN NEW;
  END IF;
  IF (NEW.lead_id, NEW.due_at, NEW.body, NEW.closed_at) IS DISTINCT FROM (OLD.lead_id, OLD.due_at, OLD.body, OLD.closed_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = OLD.status THEN RETURN NEW; END IF;
  IF OLD.status <> 'planned' THEN RAISE EXCEPTION 'status_transition_refused' USING ERRCODE = '23514'; END IF;
  NEW.closed_at := now();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.lead_reminder FOR EACH ROW EXECUTE FUNCTION school.guard_lead_reminder();

-- AC1: the notification of a due reminder. The event says only that a prospect's reminder is due
-- and points to it (BR-19: no name, no phone); the reader opens it with their own rights.
INSERT INTO platform.notification_event (code, labels, email_default) VALUES
  ('crm.reminder_due', '{"fr": "Un rappel de prospect est échu", "ar": "حان موعد تذكير بخصوص مترشح"}', false);

-- The run, every minute (`platform:lead-reminders`): at most `p_limit` reminders due of the schools
-- in use, each notified once, even by two runs at once (the second finds the first's notice).
CREATE FUNCTION platform.notify_due_reminders(p_limit integer) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE r record; recipient bigint; note uuid; sent integer := 0; recorded integer;
BEGIN
  FOR r IN SELECT x.tenant_id, x.id, x.lead_id, x.created_by, l.assigned_to, l.site_id FROM school.lead_reminder x
      JOIN school.lead l ON l.tenant_id = x.tenant_id AND l.id = x.lead_id
      WHERE x.status = 'planned' AND x.due_at <= now() AND platform.tenant_mode(x.tenant_id) = 'active'
        AND NOT EXISTS (SELECT 1 FROM school.lead_reminder_notice o WHERE o.tenant_id = x.tenant_id AND o.reminder_id = x.id)
      ORDER BY x.due_at, x.id LIMIT p_limit LOOP
    recipient := coalesce(r.assigned_to, r.created_by);
    IF recipient IS NOT NULL AND NOT platform.lead_reader(r.tenant_id, recipient, r.site_id) THEN recipient := NULL; END IF;
    INSERT INTO school.lead_reminder_notice (tenant_id, reminder_id, user_id) VALUES (r.tenant_id, r.id, recipient) ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS recorded = ROW_COUNT;
    IF recorded = 0 OR recipient IS NULL THEN CONTINUE; END IF;
    INSERT INTO school.notification (tenant_id, user_id, event, data)
    VALUES (r.tenant_id, recipient, 'crm.reminder_due', jsonb_build_object('lead_id', r.lead_id, 'reminder_id', r.id)) RETURNING id INTO note;
    INSERT INTO school.outbox (tenant_id, notification_id, user_id, event)
    SELECT r.tenant_id, note, recipient, e.code FROM platform.notification_event e JOIN public.users u ON u.id = recipient AND u.email IS NOT NULL
    LEFT JOIN school.notification_preference p ON p.tenant_id = r.tenant_id AND p.user_id = recipient AND p.event = e.code
    WHERE e.code = 'crm.reminder_due' AND coalesce(p.email, e.email_default);
    sent := sent + 1;
  END LOOP;
  RETURN sent;
END $$;

-- Who reads and writes which prospects: the whole school or the grant's sites (the row's own, so
-- that a prospect just recorded is read back at once). Its children, interactions and reminders
-- follow it; the conversion's writer writes a child and registers the prospect.
CREATE POLICY resource_reader ON school.lead AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('crm.lead.read')) OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.read', 'site')))));
CREATE POLICY resource_creator ON school.lead AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('crm.lead.create')) OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.create', 'site')))));
CREATE POLICY resource_writer ON school.lead AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('crm.lead.update')) OR (SELECT school.can_everywhere('crm.lead.convert'))
    OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.update', 'site'))))
    OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.convert', 'site')))))
  WITH CHECK ((SELECT school.can_everywhere('crm.lead.update')) OR (SELECT school.can_everywhere('crm.lead.convert'))
    OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.update', 'site'))))
    OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.convert', 'site')))));
CREATE POLICY resource_reader ON school.lead_child AS RESTRICTIVE FOR SELECT TO school_runtime USING (lead_id IN (SELECT l.id FROM school.lead l));
CREATE POLICY resource_creator ON school.lead_child AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('crm.lead.update')) OR (SELECT school.can_everywhere('crm.lead.create')) OR lead_id IN (SELECT l.id FROM school.lead l
    WHERE l.site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.update', 'site'))))
       OR l.site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.create', 'site'))))));
CREATE POLICY resource_writer ON school.lead_child AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('crm.lead.update')) OR (SELECT school.can_everywhere('crm.lead.convert')) OR lead_id IN (SELECT l.id FROM school.lead l
    WHERE l.site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.update', 'site'))))
       OR l.site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.convert', 'site'))))))
  WITH CHECK ((SELECT school.can_everywhere('crm.lead.update')) OR (SELECT school.can_everywhere('crm.lead.convert')) OR lead_id IN (SELECT l.id FROM school.lead l
    WHERE l.site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.update', 'site'))))
       OR l.site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.convert', 'site'))))));
CREATE POLICY resource_reader ON school.lead_interaction AS RESTRICTIVE FOR SELECT TO school_runtime USING (lead_id IN (SELECT l.id FROM school.lead l));
CREATE POLICY resource_creator ON school.lead_interaction AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('crm.lead.update')) OR lead_id IN (SELECT l.id FROM school.lead l
    WHERE l.site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.update', 'site'))))));
CREATE POLICY resource_reader ON school.lead_reminder AS RESTRICTIVE FOR SELECT TO school_runtime USING (lead_id IN (SELECT l.id FROM school.lead l));
CREATE POLICY resource_creator ON school.lead_reminder AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('crm.lead.update')) OR lead_id IN (SELECT l.id FROM school.lead l
    WHERE l.site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.update', 'site'))))));
CREATE POLICY resource_writer ON school.lead_reminder AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('crm.lead.update')) OR lead_id IN (SELECT l.id FROM school.lead l
    WHERE l.site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.update', 'site'))))))
  WITH CHECK ((SELECT school.can_everywhere('crm.lead.update')) OR lead_id IN (SELECT l.id FROM school.lead l
    WHERE l.site_id IN (SELECT unnest((SELECT school.site_scope_ids('crm.lead.update', 'site'))))));
CREATE POLICY resource_creator ON school.lead_option AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK ((SELECT school.can_everywhere('crm.lead.update')));
CREATE POLICY resource_writer ON school.lead_option AS RESTRICTIVE FOR UPDATE TO school_runtime
  USING ((SELECT school.can_everywhere('crm.lead.update'))) WITH CHECK ((SELECT school.can_everywhere('crm.lead.update')));

-- Section 3.3 of the SRS: the direction and the head of studies read the admissions (L); the
-- front office keeps the rest (annex A). Their roles in the schools in use receive it, as 033's.
INSERT INTO platform.role_template_permission (role_code, permission_code)
SELECT r, 'crm.lead.read' FROM unnest(ARRAY['direction', 'academic_hr']) r
WHERE NOT EXISTS (SELECT 1 FROM platform.role_template_permission x WHERE x.role_code = r AND x.permission_code = 'crm.lead.read');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, 'crm.lead.read' FROM school.role r
WHERE r.template_code IN ('direction', 'academic_hr') AND platform.tenant_mode(r.tenant_id) = 'active'
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = 'crm.lead.read');

-- The journal: in the pupils' domain, read with the prospects; names, phone, e-mail, birth
-- dates, what was said and the school's fields are masked (BR-17). A scoped reader reads the
-- entries of those they can see. Replaces 035's.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('lead', 'pedagogy', '{crm.lead.read}'), ('lead_child', 'pedagogy', '{crm.lead.read}'),
  ('lead_interaction', 'pedagogy', '{crm.lead.read}'), ('lead_reminder', 'pedagogy', '{crm.lead.read}'),
  ('lead_option', 'pedagogy', '{crm.lead.read}');
ALTER POLICY core_resource_reader ON school.audit_log USING (
  object_type NOT IN ('student', 'guardian', 'student_guardian', 'program', 'program_subject', 'study_group', 'enrollment', 'group_membership', 'staff',
    'qualification', 'availability', 'unavailability', 'staff_contract', 'staff_leave', 'lead', 'lead_child', 'lead_interaction', 'lead_reminder') OR
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
  (object_type = 'staff_leave' AND ((SELECT school.can_everywhere('staff.leave.read')) OR object_id IN (SELECT l.id FROM school.staff_leave l))) OR
  (object_type = 'lead' AND ((SELECT school.can_everywhere('crm.lead.read')) OR object_id IN (SELECT l.id FROM school.lead l))) OR
  (object_type = 'lead_child' AND ((SELECT school.can_everywhere('crm.lead.read')) OR object_id IN (SELECT c.id FROM school.lead_child c))) OR
  (object_type = 'lead_interaction' AND ((SELECT school.can_everywhere('crm.lead.read')) OR object_id IN (SELECT i.id FROM school.lead_interaction i))) OR
  (object_type = 'lead_reminder' AND ((SELECT school.can_everywhere('crm.lead.read')) OR object_id IN (SELECT r.id FROM school.lead_reminder r))));
