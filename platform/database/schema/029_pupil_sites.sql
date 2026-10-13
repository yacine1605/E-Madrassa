-- Lot 2, a pupil's site (decision of 13 October 2026: « rattachement administratif au site dès la
-- création »). A pupil and a guardian belong to a site from the day they are recorded, so that a
-- secretary or a manager whose grants name sites records and follows the pupils of their sites:
-- before any enrolment or class, as afterwards (FR-IAM-04). What a site's grant opens otherwise
-- (the groups on the site and their members, 021) stays as it was.

-- Records already there: the site of the pupil's current class, else the school's default site; a
-- guardian takes the site of the first pupil linked to them. Written as the migrations' owner,
-- outside the rules and the journal of the application.
ALTER TABLE school.student ADD COLUMN site_id uuid;
ALTER TABLE school.guardian ADD COLUMN site_id uuid;
ALTER TABLE school.student DISABLE TRIGGER USER;
UPDATE school.student s SET site_id = coalesce(
  (SELECT g.site_id FROM school.enrollment e
   JOIN school.group_membership m ON m.tenant_id = e.tenant_id AND m.enrollment_id = e.id AND m.is_main AND m.valid_to IS NULL
   JOIN school.study_group g ON g.tenant_id = m.tenant_id AND g.id = m.group_id
   WHERE e.tenant_id = s.tenant_id AND e.student_id = s.id AND e.status <> 'cancelled' ORDER BY m.valid_from DESC, m.id LIMIT 1),
  (SELECT d.id FROM school.site d WHERE d.tenant_id = s.tenant_id AND d.is_default));
ALTER TABLE school.student ENABLE TRIGGER USER;
ALTER TABLE school.guardian DISABLE TRIGGER USER;
UPDATE school.guardian h SET site_id = coalesce(
  (SELECT s.site_id FROM school.student_guardian l JOIN school.student s ON s.tenant_id = l.tenant_id AND s.id = l.student_id
   WHERE l.tenant_id = h.tenant_id AND l.guardian_id = h.id ORDER BY l.archived_at IS NOT NULL, l.created_at, l.id LIMIT 1),
  (SELECT d.id FROM school.site d WHERE d.tenant_id = h.tenant_id AND d.is_default));
ALTER TABLE school.guardian ENABLE TRIGGER USER;
ALTER TABLE school.student ALTER COLUMN site_id SET NOT NULL,
  ADD FOREIGN KEY (tenant_id, site_id) REFERENCES school.site (tenant_id, id);
ALTER TABLE school.guardian ALTER COLUMN site_id SET NOT NULL,
  ADD FOREIGN KEY (tenant_id, site_id) REFERENCES school.site (tenant_id, id);
CREATE INDEX student_site ON school.student (tenant_id, site_id);
CREATE INDEX guardian_site ON school.guardian (tenant_id, site_id);

-- The sites an action's grants name (FR-IAM-04), and what belongs to them: pupils and guardians by
-- their site, groups by theirs. Empty for a grant of the whole school, which the policies read first.
CREATE FUNCTION school.site_scope_ids(p_permission text, p_entity text) RETURNS uuid[]
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); sites uuid[]; ids uuid[];
BEGIN
  IF t IS NULL THEN RETURN '{}'::uuid[]; END IF;
  SELECT array_agg(DISTINCT s) INTO sites FROM school.effective_permissions(school.actor(), t) e, unnest(e.scope_ids) s
  WHERE e.permission = p_permission AND e.scope_type = 'site';
  IF sites IS NULL THEN RETURN '{}'::uuid[]; END IF;
  CASE p_entity
    WHEN 'site' THEN ids := sites;
    WHEN 'student' THEN SELECT array_agg(id) INTO ids FROM school.student WHERE tenant_id = t AND site_id = ANY (sites);
    WHEN 'guardian' THEN SELECT array_agg(id) INTO ids FROM school.guardian WHERE tenant_id = t AND site_id = ANY (sites);
    WHEN 'study_group' THEN SELECT array_agg(id) INTO ids FROM school.study_group WHERE tenant_id = t AND site_id = ANY (sites);
  END CASE;
  RETURN coalesce(ids, '{}'::uuid[]);
END $$;

-- A new pupil or guardian is recorded on a site the writer's grant names: the one given, else the
-- writer's only site, else (a grant of the whole school) the school's default site. Moving them
-- to another site needs the update's grant on that site.
CREATE FUNCTION school.attach_site() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE action text := CASE TG_OP WHEN 'INSERT' THEN 'create' ELSE 'update' END;
  permission text := TG_TABLE_NAME || '.record.' || action; sites uuid[];
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
-- Before `guard`: the site, then the record's own rules (and the matricule it takes).
CREATE TRIGGER attach_site BEFORE INSERT OR UPDATE OF site_id ON school.student FOR EACH ROW EXECUTE FUNCTION school.attach_site();
CREATE TRIGGER attach_site BEFORE INSERT OR UPDATE OF site_id ON school.guardian FOR EACH ROW EXECUTE FUNCTION school.attach_site();

-- What a pupil's site opens to the grants that name it, beside 021's: an enrolment needs its
-- pupil on the writer's site; a group, its own site; a place in a group, the group's site and an
-- enrolment the writer reaches; a guardian's link, its pupil and guardian within reach.
CREATE FUNCTION school.authorize_creation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE permission text; allowed boolean;
BEGIN
  IF school.is_system() THEN RETURN NEW; END IF;
  permission := CASE TG_TABLE_NAME WHEN 'enrollment' THEN 'enrollment.enrollment.create' WHEN 'study_group' THEN 'group.group.manage'
    WHEN 'group_membership' THEN 'group.membership.manage'
    ELSE CASE TG_OP WHEN 'INSERT' THEN 'guardian.record.create' ELSE 'guardian.record.update' END END;
  IF school.can_everywhere(permission) THEN RETURN NEW; END IF;
  -- One branch per table: a record has only its own fields.
  IF TG_TABLE_NAME = 'enrollment' THEN
    allowed := NEW.student_id = ANY (school.site_scope_ids(permission, 'student'));
  ELSIF TG_TABLE_NAME = 'study_group' THEN
    allowed := NEW.site_id = ANY (school.site_scope_ids(permission, 'site'));
  ELSIF TG_TABLE_NAME = 'group_membership' THEN
    allowed := NEW.group_id = ANY (school.site_scope_ids(permission, 'study_group')) AND NEW.enrollment_id = ANY (school.core_ids(permission, 'enrollment'));
  ELSE
    allowed := NEW.student_id = ANY (school.core_ids(permission, 'student')) AND NEW.guardian_id = ANY (school.core_ids(permission, 'guardian'));
  END IF;
  IF NOT allowed THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  RETURN NEW;
END $$;
-- Before the rules that read other rows: who may, first.
CREATE TRIGGER authorize BEFORE INSERT ON school.enrollment FOR EACH ROW EXECUTE FUNCTION school.authorize_creation();
CREATE TRIGGER authorize BEFORE INSERT ON school.study_group FOR EACH ROW EXECUTE FUNCTION school.authorize_creation();
CREATE TRIGGER authorize BEFORE INSERT ON school.group_membership FOR EACH ROW EXECUTE FUNCTION school.authorize_creation();
CREATE TRIGGER authorize BEFORE INSERT OR UPDATE ON school.student_guardian FOR EACH ROW EXECUTE FUNCTION school.authorize_creation();

-- A matricule for whoever may record a pupil somewhere; the site is checked by `attach_site`. Replaces 021's.
CREATE OR REPLACE FUNCTION school.next_matricule(p_tenant uuid) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE y integer := extract(year FROM current_date)::integer; n integer; candidate text;
BEGIN
  IF p_tenant IS DISTINCT FROM school.allowed_tenant() OR NOT school.can('student.record.create') THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  LOOP
    INSERT INTO school.matricule_counter AS c (tenant_id, year, last) VALUES (p_tenant, y, 1)
    ON CONFLICT (tenant_id, year) DO UPDATE SET last = c.last + 1 RETURNING c.last INTO n;
    IF n > 99999 THEN RAISE EXCEPTION 'matricule_exhausted' USING ERRCODE = '23514'; END IF;
    candidate := y::text || lpad(n::text, 5, '0');
    EXIT WHEN NOT EXISTS (SELECT 1 FROM school.student WHERE tenant_id = p_tenant AND matricule = candidate);
  END LOOP;
  RETURN candidate;
END $$;

-- 023's rules, and a site's pupils and guardians by their own site: their records, their
-- enrolments and these enrolments' places in groups. Replaces 023's.
CREATE OR REPLACE FUNCTION school.core_ids(p_permission text, p_entity text) RETURNS uuid[]
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); u bigint := school.actor(); r record;
  sites uuid[]; groups uuid[]; levels uuid[]; personal boolean := false; own uuid[]; scoped uuid[]; attached uuid[]; ids uuid[];
BEGIN
  IF t IS NULL THEN RETURN '{}'::uuid[]; END IF;
  FOR r IN SELECT e.scope_type, e.scope_ids FROM school.effective_permissions(u, t) e WHERE e.permission = p_permission LOOP
    CASE r.scope_type
      WHEN 'tenant' THEN RETURN '{}'::uuid[];
      WHEN 'site' THEN sites := sites || r.scope_ids;
      WHEN 'group' THEN groups := groups || r.scope_ids;
      WHEN 'level' THEN levels := levels || r.scope_ids;
      WHEN 'personal' THEN personal := true;
      ELSE NULL;
    END CASE;
  END LOOP;
  IF levels IS NOT NULL THEN
    WITH RECURSIVE tree(id) AS (
      SELECT unnest(levels)
      UNION SELECT n.id FROM school.ref_node n JOIN tree ON n.parent_id = tree.id WHERE n.tenant_id = t)
    SELECT array_agg(id) INTO levels FROM tree;
  END IF;
  IF personal THEN
    SELECT array_agg(x) INTO own FROM (
      SELECT s.id AS x FROM school.student s WHERE s.tenant_id = t AND s.user_id = u
      UNION SELECT l.student_id FROM school.student_guardian l JOIN school.guardian g ON g.tenant_id = l.tenant_id AND g.id = l.guardian_id
        WHERE l.tenant_id = t AND l.archived_at IS NULL AND g.archived_at IS NULL AND g.user_id = u) o;
  END IF;
  IF sites IS NULL AND groups IS NULL AND levels IS NULL AND own IS NULL AND NOT (personal AND p_entity = 'guardian') THEN
    RETURN '{}'::uuid[];
  END IF;
  -- The pupils recorded on the scope's sites.
  IF sites IS NOT NULL AND p_entity IN ('student', 'guardian', 'enrollment', 'group_membership') THEN
    SELECT array_agg(s.id) INTO attached FROM school.student s WHERE s.tenant_id = t AND s.site_id = ANY (sites);
  END IF;

  IF p_entity IN ('student', 'guardian') THEN
    -- Pupils of the scope's sites, placed now in a group of the scope, or enrolled in a programme of its levels.
    SELECT array_agg(x) INTO scoped FROM (
      SELECT e.student_id AS x FROM school.study_group g
      JOIN school.group_membership m ON m.tenant_id = g.tenant_id AND m.group_id = g.id AND m.valid_to IS NULL
      JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id AND e.status <> 'cancelled'
      WHERE g.tenant_id = t AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT e.student_id FROM school.program p
      JOIN school.enrollment e ON e.tenant_id = p.tenant_id AND e.program_id = p.id AND e.status <> 'cancelled'
      WHERE p.tenant_id = t AND p.node_id = ANY (levels)
      UNION SELECT unnest(attached)) s;
    IF p_entity = 'student' THEN
      SELECT array_agg(DISTINCT x) INTO ids FROM (SELECT unnest(scoped) AS x UNION ALL SELECT unnest(own)) a;
    ELSE
      SELECT array_agg(DISTINCT x) INTO ids FROM (
        SELECT l.guardian_id AS x FROM school.student_guardian l
        WHERE l.tenant_id = t AND l.archived_at IS NULL AND (l.student_id = ANY (scoped) OR l.student_id = ANY (own))
        UNION SELECT h.id FROM school.guardian h WHERE h.tenant_id = t AND h.site_id = ANY (sites)
        UNION SELECT h.id FROM school.guardian h WHERE personal AND h.tenant_id = t AND h.user_id = u) a;
    END IF;
  ELSIF p_entity = 'program' THEN
    SELECT array_agg(DISTINCT x) INTO ids FROM (
      SELECT g.program_id AS x FROM school.study_group g
      WHERE g.tenant_id = t AND g.program_id IS NOT NULL AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT p.id FROM school.program p WHERE p.tenant_id = t AND p.node_id = ANY (levels)
      UNION SELECT e.program_id FROM school.enrollment e WHERE e.tenant_id = t AND e.student_id = ANY (own)) a;
  ELSIF p_entity = 'study_group' THEN
    SELECT array_agg(DISTINCT x) INTO ids FROM (
      SELECT g.id AS x FROM school.study_group g WHERE g.tenant_id = t AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT g.id FROM school.study_group g JOIN school.program p ON p.tenant_id = g.tenant_id AND p.id = g.program_id
        WHERE g.tenant_id = t AND p.node_id = ANY (levels)
      UNION SELECT m.group_id FROM school.group_membership m JOIN school.enrollment e ON e.tenant_id = m.tenant_id AND e.id = m.enrollment_id
        WHERE m.tenant_id = t AND m.valid_to IS NULL AND e.student_id = ANY (own)) a;
  ELSIF p_entity = 'enrollment' THEN
    SELECT array_agg(DISTINCT x) INTO ids FROM (
      SELECT m.enrollment_id AS x FROM school.study_group g
      JOIN school.group_membership m ON m.tenant_id = g.tenant_id AND m.group_id = g.id AND m.valid_to IS NULL
      WHERE g.tenant_id = t AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT e.id FROM school.program p JOIN school.enrollment e ON e.tenant_id = p.tenant_id AND e.program_id = p.id
        WHERE p.tenant_id = t AND p.node_id = ANY (levels)
      UNION SELECT e.id FROM school.enrollment e WHERE e.tenant_id = t AND (e.student_id = ANY (own) OR e.student_id = ANY (attached))) a;
  ELSIF p_entity = 'group_membership' THEN
    SELECT array_agg(DISTINCT x) INTO ids FROM (
      SELECT m.id AS x FROM school.study_group g JOIN school.group_membership m ON m.tenant_id = g.tenant_id AND m.group_id = g.id
        WHERE g.tenant_id = t AND (g.id = ANY (groups) OR g.site_id = ANY (sites))
      UNION SELECT m.id FROM school.program p
        JOIN school.enrollment e ON e.tenant_id = p.tenant_id AND e.program_id = p.id
        JOIN school.group_membership m ON m.tenant_id = e.tenant_id AND m.enrollment_id = e.id
        WHERE p.tenant_id = t AND p.node_id = ANY (levels)
      UNION SELECT m.id FROM school.enrollment e JOIN school.group_membership m ON m.tenant_id = e.tenant_id AND m.enrollment_id = e.id
        WHERE e.tenant_id = t AND (e.student_id = ANY (own) OR e.student_id = ANY (attached))) a;
  END IF;
  RETURN coalesce(ids, '{}'::uuid[]);
END $$;

-- What a site's grant creates, it reads back at once: a new row is not yet among the IDs a
-- policy computes for the statement that writes it, so these rules read the row itself (its site,
-- its pupil's, its group's). Same visibility as core_ids. Replaces 023's readers and 021's creators.
ALTER POLICY resource_reader ON school.student USING ((SELECT school.can_everywhere('student.record.read'))
  OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('student.record.read', 'site'))))
  OR id IN (SELECT unnest((SELECT school.core_ids('student.record.read', 'student')))));
ALTER POLICY resource_creator ON school.student WITH CHECK ((SELECT school.can_everywhere('student.record.create'))
  OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('student.record.create', 'site')))));
ALTER POLICY resource_reader ON school.guardian USING ((SELECT school.can_everywhere('guardian.record.read'))
  OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('guardian.record.read', 'site'))))
  OR id IN (SELECT unnest((SELECT school.core_ids('guardian.record.read', 'guardian')))));
ALTER POLICY resource_creator ON school.guardian WITH CHECK ((SELECT school.can_everywhere('guardian.record.create'))
  OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('guardian.record.create', 'site')))));
ALTER POLICY resource_creator ON school.student_guardian WITH CHECK ((SELECT school.can_everywhere('guardian.record.create'))
  OR (student_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.create', 'student'))))
      AND guardian_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.create', 'guardian'))))));
ALTER POLICY resource_writer ON school.student_guardian
  USING ((SELECT school.can_everywhere('guardian.record.update'))
    OR (student_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.update', 'student'))))
        AND guardian_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.update', 'guardian'))))))
  WITH CHECK ((SELECT school.can_everywhere('guardian.record.update'))
    OR (student_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.update', 'student'))))
        AND guardian_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.update', 'guardian'))))));
ALTER POLICY resource_reader ON school.enrollment USING ((SELECT school.can_everywhere('enrollment.enrollment.read'))
  OR student_id IN (SELECT unnest((SELECT school.site_scope_ids('enrollment.enrollment.read', 'student'))))
  OR id IN (SELECT unnest((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment')))));
ALTER POLICY resource_creator ON school.enrollment WITH CHECK ((SELECT school.can_everywhere('enrollment.enrollment.create'))
  OR student_id IN (SELECT unnest((SELECT school.site_scope_ids('enrollment.enrollment.create', 'student')))));
ALTER POLICY resource_reader ON school.study_group USING ((SELECT school.can_everywhere('group.group.read'))
  OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('group.group.read', 'site'))))
  OR id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'study_group')))));
ALTER POLICY resource_creator ON school.study_group WITH CHECK ((SELECT school.can_everywhere('group.group.manage'))
  OR site_id IN (SELECT unnest((SELECT school.site_scope_ids('group.group.manage', 'site')))));
ALTER POLICY resource_reader ON school.group_membership USING ((SELECT school.can_everywhere('group.group.read'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('group.group.read', 'study_group'))))
  OR id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'group_membership')))));
ALTER POLICY resource_creator ON school.group_membership WITH CHECK ((SELECT school.can_everywhere('group.membership.manage'))
  OR (group_id IN (SELECT unnest((SELECT school.site_scope_ids('group.membership.manage', 'study_group'))))
      AND enrollment_id IN (SELECT unnest((SELECT school.core_ids('group.membership.manage', 'enrollment'))))));
