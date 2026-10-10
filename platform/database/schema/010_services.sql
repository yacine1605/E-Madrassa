-- Shared services, part 1 (W1.6): reading the audit journal by domain (FR-AUD-01), background
-- jobs (FR-JOB-01), notifications and their email outbox (FR-NOT-01, ARC-05).

-- FR-AUD-01: the journal is read with audit.log.read, and each domain's entries only with a
-- permission of that domain: technical access does not open the financial journals. Every
-- audited table has its line here (checked by the tests); an unknown type is read by nobody.
CREATE TABLE platform.audit_domain (
  object_type text PRIMARY KEY,
  domain text NOT NULL CHECK (domain IN ('access', 'settings', 'referential', 'pedagogy', 'finance', 'staff', 'stock')),
  read_any text[] NOT NULL CHECK (cardinality(read_any) >= 1)
);
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES
  ('membership', 'access', '{user.account.read,role.assignment.manage}'),
  ('role', 'access', '{role.definition.manage,role.assignment.manage}'),
  ('role_permission', 'access', '{role.definition.manage,role.assignment.manage}'),
  ('role_assignment', 'access', '{role.assignment.manage,user.account.read}'),
  ('tenant', 'settings', '{tenant.settings.manage}'),
  ('setting', 'settings', '{tenant.settings.manage}'),
  ('site', 'referential', '{referential.node.read}'),
  ('node_type', 'referential', '{referential.node.read}'),
  ('ref_node', 'referential', '{referential.node.read}'),
  ('subject', 'referential', '{referential.node.read}'),
  ('academic_year', 'referential', '{referential.node.read}'),
  ('period', 'referential', '{referential.node.read}'),
  ('custom_field', 'referential', '{custom_field.definition.manage,referential.node.manage}');

-- The object types the signed-in user may read in the journal; evaluated once per query.
CREATE FUNCTION school.audit_readable_types() RETURNS text[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH mine AS (SELECT DISTINCT e.permission FROM school.effective_permissions(school.actor(), school.tenant()) e
                WHERE school.allowed(school.tenant()))
  SELECT coalesce(array_agg(d.object_type), '{}') FROM platform.audit_domain d
  WHERE EXISTS (SELECT 1 FROM mine WHERE permission = 'audit.log.read')
    AND EXISTS (SELECT 1 FROM mine WHERE permission = ANY (d.read_any)) $$;

DROP POLICY tenant_read ON school.audit_log;
CREATE POLICY reader ON school.audit_log FOR SELECT
  USING (tenant_id = school.tenant() AND object_type = ANY ((SELECT school.audit_readable_types())::text[]));

-- FR-JOB-01: a background job of a school, started by a user, seen by that user ("Mes
-- tâches"). Started once per idempotency key; a finished job never changes again.
CREATE TABLE school.job (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  user_id bigint NOT NULL REFERENCES public.users(id),
  kind text NOT NULL CHECK (kind ~ '^[a-z_]+(\.[a-z_]+)+$'),
  idempotency_key text NOT NULL CHECK (length(idempotency_key) BETWEEN 1 AND 100),
  status text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled')),
  progress smallint NOT NULL DEFAULT 0 CHECK (progress BETWEEN 0 AND 100),
  input jsonb NOT NULL DEFAULT '{}',
  result jsonb,
  error_code text,
  error_message text CHECK (length(error_message) <= 1000),
  attempts smallint NOT NULL DEFAULT 0,
  cancel_requested_at timestamptz,
  started_at timestamptz,
  finished_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, user_id, kind, idempotency_key),
  UNIQUE (tenant_id, id)
);
CREATE INDEX job_user ON school.job (tenant_id, user_id, created_at DESC);
ALTER TABLE school.job ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.job FORCE ROW LEVEL SECURITY;
-- The user's own jobs in the school of the request or of the running job.
CREATE POLICY own_jobs ON school.job USING (tenant_id = school.tenant() AND user_id = school.actor())
  WITH CHECK (tenant_id = school.tenant() AND user_id = school.actor());

CREATE FUNCTION school.guard_job() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.status <> 'queued' OR NEW.progress <> 0 OR NEW.attempts <> 0 THEN RAISE EXCEPTION 'status_invalid' USING ERRCODE = '23514'; END IF;
    RETURN NEW;
  END IF;
  IF (NEW.tenant_id, NEW.user_id, NEW.kind, NEW.idempotency_key, NEW.input, NEW.created_at)
     IS DISTINCT FROM (OLD.tenant_id, OLD.user_id, OLD.kind, OLD.idempotency_key, OLD.input, OLD.created_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF OLD.status IN ('succeeded', 'cancelled') THEN RAISE EXCEPTION 'job_finished' USING ERRCODE = '23514'; END IF;
  IF NEW.status <> OLD.status AND (OLD.status, NEW.status) NOT IN (('queued', 'running'), ('queued', 'cancelled'),
       ('running', 'succeeded'), ('running', 'failed'), ('running', 'cancelled'), ('running', 'queued'), ('failed', 'queued')) THEN
    RAISE EXCEPTION 'status_invalid' USING ERRCODE = '23514';
  END IF;
  IF NEW.status = 'running' AND OLD.status <> 'running' THEN NEW.started_at := now(); NEW.attempts := OLD.attempts + 1; END IF;
  IF NEW.status IN ('succeeded', 'failed', 'cancelled') AND NEW.status <> OLD.status THEN NEW.finished_at := now(); END IF;
  IF NEW.status = 'succeeded' THEN NEW.progress := 100; END IF;
  -- Retried: the error of the previous attempt goes, the attempts stay counted.
  IF NEW.status = 'queued' AND OLD.status = 'failed' THEN NEW.error_code := NULL; NEW.error_message := NULL; NEW.finished_at := NULL; END IF;
  NEW.updated_at := now();
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.job FOR EACH ROW EXECUTE FUNCTION school.guard_job();

-- FR-NOT-01: notification events. A notification carries its event and minimal data; the
-- text is the event's, in the reader's language. Email only when wanted (BR-19: content
-- minimal, nothing an unauthorized reader should see).
CREATE TABLE platform.notification_event (
  code text PRIMARY KEY CHECK (code ~ '^[a-z_]+(\.[a-z_]+)+$'),
  labels jsonb NOT NULL CHECK (school.valid_labels(labels)),
  email_default boolean NOT NULL
);
INSERT INTO platform.notification_event (code, labels, email_default) VALUES
  ('access.sensitive_permission', '{"fr": "Une permission sensible a été attribuée", "ar": "تم منح صلاحية حساسة"}', true),
  ('job.succeeded', '{"fr": "Une tâche de fond est terminée", "ar": "انتهت مهمة في الخلفية"}', false),
  ('job.failed', '{"fr": "Une tâche de fond a échoué", "ar": "فشلت مهمة في الخلفية"}', true);

CREATE TABLE school.notification (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  user_id bigint NOT NULL REFERENCES public.users(id),
  event text NOT NULL REFERENCES platform.notification_event(code),
  data jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(data) = 'object'),
  read_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id)
);
CREATE INDEX notification_user ON school.notification (tenant_id, user_id, created_at DESC);
ALTER TABLE school.notification ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.notification FORCE ROW LEVEL SECURITY;
-- FR-NOT-01 AC2: a person only ever sees their own notifications.
CREATE POLICY own_notifications ON school.notification USING (tenant_id = school.tenant() AND user_id = school.actor());

CREATE TABLE school.notification_preference (
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  user_id bigint NOT NULL REFERENCES public.users(id),
  event text NOT NULL REFERENCES platform.notification_event(code),
  email boolean NOT NULL,
  PRIMARY KEY (tenant_id, user_id, event)
);
ALTER TABLE school.notification_preference ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.notification_preference FORCE ROW LEVEL SECURITY;
CREATE POLICY own_preferences ON school.notification_preference USING (tenant_id = school.tenant() AND user_id = school.actor())
  WITH CHECK (tenant_id = school.tenant() AND user_id = school.actor());

-- ARC-05: the email outbox. Written with the notification, in the same transaction; sent by
-- the worker, which claims rows without blocking other workers. Removed once sent; after five
-- failed attempts it stays as a failure the school can see and send again (FR-NOT-01 AC1).
CREATE TABLE school.outbox (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  notification_id uuid NOT NULL,
  user_id bigint NOT NULL REFERENCES public.users(id),
  -- The event, kept here so that the school sees what failed without reading anyone's notifications.
  event text NOT NULL REFERENCES platform.notification_event(code),
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sending', 'failed')),
  attempts smallint NOT NULL DEFAULT 0,
  next_attempt_at timestamptz NOT NULL DEFAULT now(),
  locked_until timestamptz,
  last_error text CHECK (length(last_error) <= 500),
  created_at timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (tenant_id, notification_id) REFERENCES school.notification (tenant_id, id)
);
CREATE INDEX outbox_due ON school.outbox (next_attempt_at) WHERE status <> 'failed';
ALTER TABLE school.outbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.outbox FORCE ROW LEVEL SECURITY;
CREATE POLICY settings_reader ON school.outbox FOR SELECT
  USING (tenant_id = school.tenant() AND (SELECT school.can_everywhere('tenant.settings.manage')));

-- Notifies active members of the school: the notification, and its email if the person wants
-- one and has an address. Called by the database's own rules and by the application.
CREATE FUNCTION school.notify(p_event text, p_users bigint[], p_data jsonb DEFAULT '{}') RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); sent integer;
BEGIN
  IF t IS NULL THEN RAISE EXCEPTION 'tenant_required' USING ERRCODE = '23514'; END IF;
  WITH recipients AS (
    SELECT DISTINCT m.user_id FROM school.membership m WHERE m.tenant_id = t AND m.status = 'active' AND m.user_id = ANY (p_users)),
  created AS (
    INSERT INTO school.notification (tenant_id, user_id, event, data) SELECT t, r.user_id, p_event, p_data FROM recipients r
    RETURNING id, user_id),
  emailed AS (
    INSERT INTO school.outbox (tenant_id, notification_id, user_id, event)
    SELECT t, c.id, c.user_id, p_event FROM created c
    JOIN public.users u ON u.id = c.user_id AND u.email IS NOT NULL
    JOIN platform.notification_event e ON e.code = p_event
    LEFT JOIN school.notification_preference p ON p.tenant_id = t AND p.user_id = c.user_id AND p.event = p_event
    WHERE coalesce(p.email, e.email_default)
    RETURNING 1)
  SELECT count(*) INTO sent FROM created;
  RETURN sent;
END $$;

-- FR-IAM-05: assigning a sensitive permission notifies those who watch the journal for the
-- whole school (holders of audit.log.read), the author aside. A role code is never named (P4).
CREATE FUNCTION school.journal_watchers(p_tenant uuid) RETURNS bigint[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT coalesce(array_agg(DISTINCT m.user_id), '{}') FROM school.membership m
  WHERE m.tenant_id = p_tenant AND m.status = 'active' AND m.user_id IS DISTINCT FROM school.actor()
    AND EXISTS (SELECT 1 FROM school.effective_permissions(m.user_id, p_tenant) e WHERE e.permission = 'audit.log.read' AND e.scope_type = 'tenant') $$;

CREATE FUNCTION school.notify_sensitive() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF school.tenant() IS DISTINCT FROM NEW.tenant_id THEN RETURN NEW; END IF;
  -- One branch per table: each reads only the columns of its own rows.
  IF TG_TABLE_NAME = 'role_assignment' THEN
    IF EXISTS (SELECT 1 FROM school.role_permission rp JOIN platform.permission p ON p.code = rp.permission_code AND p.sensitive
               WHERE rp.tenant_id = NEW.tenant_id AND rp.role_id = NEW.role_id) THEN
      PERFORM school.notify('access.sensitive_permission', school.journal_watchers(NEW.tenant_id),
        jsonb_build_object('assignment_id', NEW.id, 'user_id', NEW.user_id, 'role_id', NEW.role_id));
    END IF;
  ELSE
    IF EXISTS (SELECT 1 FROM platform.permission WHERE code = NEW.permission_code AND sensitive)
       AND EXISTS (SELECT 1 FROM school.role_assignment a WHERE a.tenant_id = NEW.tenant_id AND a.role_id = NEW.role_id AND a.archived_at IS NULL) THEN
      PERFORM school.notify('access.sensitive_permission', school.journal_watchers(NEW.tenant_id),
        jsonb_build_object('role_id', NEW.role_id, 'permission', NEW.permission_code));
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER notify_sensitive AFTER INSERT ON school.role_assignment FOR EACH ROW EXECUTE FUNCTION school.notify_sensitive();
CREATE TRIGGER notify_sensitive AFTER INSERT ON school.role_permission FOR EACH ROW EXECUTE FUNCTION school.notify_sensitive();

-- The worker's side, across schools: claims due emails (a lease, so that a crashed worker's
-- rows come back), then reports each one. Emails of people no longer active are dropped.
CREATE FUNCTION platform.claim_outbox(p_limit integer, p_lease_seconds integer)
RETURNS TABLE (id uuid, tenant_id uuid, email text, name text, event text, school text, locale text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  DELETE FROM school.outbox o WHERE o.status <> 'failed' AND NOT EXISTS (
    SELECT 1 FROM school.membership m WHERE m.tenant_id = o.tenant_id AND m.user_id = o.user_id AND m.status = 'active');
  RETURN QUERY
  WITH picked AS (
    SELECT o.id FROM school.outbox o
    WHERE (o.status = 'pending' AND o.next_attempt_at <= now()) OR (o.status = 'sending' AND o.locked_until < now())
    ORDER BY o.next_attempt_at LIMIT p_limit FOR UPDATE SKIP LOCKED),
  claimed AS (
    UPDATE school.outbox o SET status = 'sending', locked_until = now() + make_interval(secs => p_lease_seconds), attempts = o.attempts + 1
    FROM picked WHERE o.id = picked.id RETURNING o.id, o.tenant_id, o.user_id, o.event)
  SELECT c.id, c.tenant_id, u.email::text, u.name::text, c.event, t.name, t.default_locale
  FROM claimed c JOIN public.users u ON u.id = c.user_id JOIN platform.tenant t ON t.id = c.tenant_id;
END $$;

CREATE FUNCTION platform.finish_outbox(p_id uuid, p_error text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF p_error IS NULL THEN DELETE FROM school.outbox WHERE id = p_id AND status = 'sending'; RETURN; END IF;
  UPDATE school.outbox SET last_error = left(p_error, 500), locked_until = NULL,
    status = CASE WHEN attempts >= 5 THEN 'failed' ELSE 'pending' END,
    next_attempt_at = now() + make_interval(mins => (2 ^ attempts)::integer)
  WHERE id = p_id AND status = 'sending';
END $$;

-- FR-NOT-01: a failed email sent again, on request of whoever manages the school's settings.
CREATE FUNCTION school.retry_delivery(p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  PERFORM school.require_everywhere('tenant.settings.manage');
  UPDATE school.outbox SET status = 'pending', attempts = 0, next_attempt_at = now(), last_error = NULL
  WHERE tenant_id = school.tenant() AND id = p_id AND status = 'failed';
  IF NOT FOUND THEN RAISE EXCEPTION 'not_failed' USING ERRCODE = '23514'; END IF;
END $$;
