-- Lot 2, the prospects' retention (NFR-DATA-03, BR-17): a lost prospect's personal data are kept
-- as long as the school says (`retention.lead_months`, 24 months after its closure by default, a
-- proposal to validate with OD-07), then anonymised by the daily run: the contact's names, phone,
-- e-mail and the school's fields, the reason of its loss, its children's names and birth dates,
-- what was said in its interactions and reminders. What stays: its site, source, step, dates and
-- counts. A registered prospect stays with its pupil, whose own policy applies (030).

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('retention.lead_months', 'integer', '24', 1, 120, 'tenant.settings.manage',
   '{"fr": "Anonymiser un prospect perdu après (mois)", "ar": "إخفاء هوية مترشح ضائع بعد (أشهر)"}');

-- When a prospect was anonymised; one anonymised has neither phone nor e-mail.
ALTER TABLE school.lead ADD COLUMN anonymised_at timestamptz;
DO $$ DECLARE c text; BEGIN
  SELECT conname INTO c FROM pg_constraint WHERE conrelid = 'school.lead'::regclass AND contype = 'c'
    AND pg_get_constraintdef(oid) LIKE 'CHECK (((phone IS NOT NULL) OR (email IS NOT NULL)))%'; -- NOT VALID after a rollback
  EXECUTE format('ALTER TABLE school.lead DROP CONSTRAINT %I', c);
END $$;
ALTER TABLE school.lead ADD CONSTRAINT lead_reachable CHECK (phone IS NOT NULL OR email IS NOT NULL OR anonymised_at IS NOT NULL);
CREATE INDEX lead_retention ON school.lead (tenant_id, closed_at) WHERE status = 'lost' AND anonymised_at IS NULL;

-- 038's guards, with the retention run let through; an anonymised prospect changes no more.
CREATE OR REPLACE FUNCTION school.guard_lead() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE sites uuid[];
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
  IF TG_OP = 'UPDATE' AND OLD.anonymised_at IS NOT NULL THEN RAISE EXCEPTION 'lead_anonymised' USING ERRCODE = '23514'; END IF;
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

CREATE OR REPLACE FUNCTION school.guard_lead_child() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE l school.lead;
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
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

CREATE OR REPLACE FUNCTION school.guard_lead_interaction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE site uuid;
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
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

CREATE OR REPLACE FUNCTION school.guard_lead_reminder() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE l school.lead;
BEGIN
  IF school.retention_run() THEN RETURN NEW; END IF;
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

-- The lost prospects due in a school: closed for longer than its duration.
CREATE FUNCTION platform.lead_retention_candidates(p_tenant uuid) RETURNS TABLE (id uuid, due timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT l.id, l.closed_at + make_interval(months => platform.retention_value(p_tenant, 'retention.lead_months')) FROM school.lead l
  WHERE l.tenant_id = p_tenant AND l.status = 'lost' AND l.anonymised_at IS NULL
    AND l.closed_at + make_interval(months => platform.retention_value(p_tenant, 'retention.lead_months')) <= now() $$;

-- The run, after 030's (`platform:retention`, daily): at most `p_limit` prospects per school, each
-- anonymised with its children, interactions and reminders; its counts in the school's journal.
CREATE FUNCTION platform.apply_lead_retention(p_limit integer) RETURNS TABLE (tenant_id uuid, leads integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid; c record; n integer;
BEGIN
  PERFORM set_config('app.retention', 'on', true);
  PERFORM set_config('app.reason', 'retention', true);
  FOR t IN SELECT x.id FROM platform.tenant x ORDER BY x.id LOOP
    n := 0;
    FOR c IN SELECT r.id FROM platform.lead_retention_candidates(t) r ORDER BY r.due, r.id LIMIT p_limit LOOP
      UPDATE school.lead SET last_name = 'ANONYME', first_name = NULL, phone = NULL, email = NULL, custom = '{}', lost_reason = 'ANONYME',
        anonymised_at = now() WHERE school.lead.tenant_id = t AND school.lead.id = c.id;
      UPDATE school.lead_child SET first_name = 'ANONYME', last_name = NULL, birth_date = NULL WHERE school.lead_child.tenant_id = t AND lead_id = c.id;
      UPDATE school.lead_interaction SET body = 'ANONYME' WHERE school.lead_interaction.tenant_id = t AND lead_id = c.id;
      UPDATE school.lead_reminder SET body = 'ANONYME' WHERE school.lead_reminder.tenant_id = t AND lead_id = c.id;
      n := n + 1;
    END LOOP;
    IF n > 0 THEN
      INSERT INTO school.audit_log (tenant_id, action, object_type, object_id, after, reason)
      VALUES (t, 'RETENTION', 'tenant', t, jsonb_build_object('leads', n), 'retention');
      tenant_id := t; leads := n;
      RETURN NEXT;
    END IF;
  END LOOP;
  PERFORM set_config('app.retention', '', true);
  PERFORM set_config('app.reason', '', true);
END $$;

-- What the next run will do in the reader's school, prospects included: counts only. Replaces 030's.
CREATE OR REPLACE FUNCTION school.retention_due() RETURNS TABLE (category text, due integer)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NOT school.can_everywhere('tenant.settings.manage') THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  RETURN QUERY SELECT k.category, (SELECT count(*)::integer FROM platform.retention_candidates(school.tenant()) r WHERE r.category = k.category)
    FROM unnest(ARRAY['archive', 'pupil', 'guardian', 'journal']) AS k(category)
    UNION ALL SELECT 'lead', (SELECT count(*)::integer FROM platform.lead_retention_candidates(school.tenant()));
END $$;
