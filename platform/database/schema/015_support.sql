-- Support access (FR-PLT-04): the school consents, for 72 hours at most; the publisher's
-- operator then reads the school's data, read-only, without its sensitive data unless the
-- school consented to that separately; every consultation goes to the school's journal.

-- Who may consent: the school's administrator and its direction by default. Not in annex A,
-- which FR-PLT-04 needs: the code asks for a permission, never for a role (P4).
INSERT INTO platform.permission (code, module_key, sensitive, labels) VALUES
  ('support.access.grant', 'core', true, '{"fr": "Accorder un accès au support de l’éditeur", "ar": "منح وصول لدعم الناشر"}');
INSERT INTO platform.role_template_permission (role_code, permission_code) VALUES
  ('tenant_admin', 'support.access.grant'), ('direction', 'support.access.grant');
INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, 'support.access.grant' FROM school.role r
WHERE r.template_code IN ('tenant_admin', 'direction') AND platform.tenant_mode(r.tenant_id) = 'active'
  AND NOT EXISTS (SELECT 1 FROM school.role_permission x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = 'support.access.grant');

CREATE TABLE school.support_grant (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  -- Salaries, financial attachments and the other sensitive data of annex A: a distinct consent.
  include_sensitive boolean NOT NULL DEFAULT false,
  reason text NOT NULL CHECK (length(btrim(reason)) BETWEEN 3 AND 1000),
  starts_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz NOT NULL,
  revoked_at timestamptz,
  revoked_by bigint REFERENCES public.users(id),
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (expires_at > starts_at AND expires_at <= starts_at + interval '72 hours'),
  UNIQUE (tenant_id, id)
);
CALL school.protect('school.support_grant');
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('support_grant', 'access', '{support.access.grant,audit.log.read}');

CREATE FUNCTION school.guard_support_grant() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT school.is_system() AND NOT school.can_everywhere('support.access.grant') THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF TG_OP = 'UPDATE' THEN
    IF (NEW.include_sensitive, NEW.reason, NEW.starts_at, NEW.expires_at) IS DISTINCT FROM (OLD.include_sensitive, OLD.reason, OLD.starts_at, OLD.expires_at) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
    IF NEW.revoked_at IS NOT NULL THEN NEW.revoked_at := now(); NEW.revoked_by := school.actor(); END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard_support_grant BEFORE INSERT OR UPDATE ON school.support_grant FOR EACH ROW EXECUTE FUNCTION school.guard_support_grant();

-- The consent the current session works under: named by the request (`app.support_grant`), of
-- this school, in its validity, not revoked, used by an operator. None otherwise.
CREATE FUNCTION school.support_grant_in_use() RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT g.id FROM school.support_grant g
  -- CASE, not AND: SQL does not promise to test the format before the cast.
  WHERE g.id = CASE WHEN coalesce(current_setting('app.support_grant', true), '') ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                    THEN current_setting('app.support_grant', true)::uuid END
    AND g.tenant_id = school.tenant()
    AND g.revoked_at IS NULL AND g.archived_at IS NULL AND now() >= g.starts_at AND now() < g.expires_at
    AND EXISTS (SELECT 1 FROM platform.operator o WHERE o.user_id = school.actor() AND o.revoked_at IS NULL) $$;

-- The second barrier opens to an active member, or, in a support session, to the operator
-- working under a valid consent (FR-PLT-04 AC1, AC2). Replaces 003's.
CREATE OR REPLACE FUNCTION school.allowed(t uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT t = school.tenant()
     AND platform.tenant_mode(t) IS DISTINCT FROM 'suspended'
     AND CASE WHEN coalesce(current_setting('app.support_grant', true), '') = ''
       THEN EXISTS (SELECT 1 FROM school.membership m WHERE m.tenant_id = t AND m.user_id = school.actor() AND m.status = 'active')
       ELSE school.support_grant_in_use() IS NOT NULL END $$;

-- A support session writes nothing (FR-PLT-04: read-only): replaces 003's guard.
CREATE OR REPLACE FUNCTION school.guard_writable() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.tenant_id ELSE NEW.tenant_id END;
BEGIN
  IF coalesce(current_setting('app.support_grant', true), '') <> '' THEN
    RAISE EXCEPTION 'support_read_only' USING ERRCODE = '25006';
  END IF;
  IF platform.tenant_mode(t) IS DISTINCT FROM 'active' THEN
    RAISE EXCEPTION 'tenant_read_only' USING ERRCODE = '25006';
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

-- The union of the user's roles; in a support session, the reading permissions of the school's
-- modules, without the sensitive ones unless consented to separately (the journal stays
-- readable). Replaces 005's.
CREATE OR REPLACE FUNCTION school.effective_permissions(p_user bigint, p_tenant uuid)
RETURNS TABLE (permission text, scope_type text, scope_ids uuid[])
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT rp.permission_code, a.scope_type, a.scope_ids
  FROM school.role_assignment a
  JOIN school.membership m ON m.tenant_id = a.tenant_id AND m.user_id = a.user_id AND m.status = 'active'
  JOIN school.role r ON r.tenant_id = a.tenant_id AND r.id = a.role_id AND r.archived_at IS NULL
  JOIN school.role_permission rp ON rp.tenant_id = a.tenant_id AND rp.role_id = a.role_id
  JOIN platform.permission p ON p.code = rp.permission_code
  JOIN platform.tenant_module tm ON tm.tenant_id = a.tenant_id AND tm.module_key = p.module_key
  WHERE a.tenant_id = p_tenant AND a.user_id = p_user AND a.archived_at IS NULL
    AND a.valid_from <= current_date AND (a.valid_until IS NULL OR a.valid_until >= current_date)
  UNION ALL
  SELECT p.code, 'tenant', NULL::uuid[]
  FROM platform.permission p
  JOIN platform.tenant_module tm ON tm.tenant_id = p_tenant AND tm.module_key = p.module_key
  JOIN school.support_grant g ON g.tenant_id = p_tenant AND g.id = school.support_grant_in_use()
  WHERE p_user = school.actor() AND p_tenant = school.tenant() AND p.code LIKE '%.read'
    AND (NOT p.sensitive OR p.code = 'audit.log.read' OR g.include_sensitive) $$;

-- In a support session, financial attachments stay closed without the distinct consent: replaces 011's.
CREATE OR REPLACE FUNCTION school.file_categories(p_mode text) RETURNS text[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH mine AS (SELECT DISTINCT e.permission FROM school.effective_permissions(school.actor(), school.tenant()) e
                WHERE school.allowed(school.tenant())),
  support AS (SELECT g.include_sensitive FROM school.support_grant g WHERE g.id = school.support_grant_in_use())
  SELECT coalesce(array_agg(c.code), '{}') FROM platform.file_category c
  WHERE CASE p_mode
    WHEN 'read' THEN c.author_only OR EXISTS (SELECT 1 FROM mine WHERE permission = ANY (c.read_any))
    WHEN 'write' THEN EXISTS (SELECT 1 FROM mine WHERE permission = ANY (c.write_any))
    WHEN 'author' THEN c.author_only END
    AND NOT (c.code = 'finance_attachment' AND EXISTS (SELECT 1 FROM support WHERE NOT include_sensitive)) $$;

-- FR-PLT-04: each consultation of a support session, in the school's journal.
CREATE FUNCTION school.log_support_access(p_what text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE g uuid := school.support_grant_in_use();
BEGIN
  IF g IS NULL THEN RAISE EXCEPTION 'support_not_granted' USING ERRCODE = '42501'; END IF;
  INSERT INTO school.audit_log (tenant_id, actor_id, action, object_type, object_id, reason, request_id, ip)
  VALUES (school.tenant(), school.actor(), 'SUPPORT_READ', 'support_grant', g, left(p_what, 300),
          nullif(current_setting('app.request_id', true), ''), nullif(current_setting('app.ip', true), ''));
END $$;

-- The console sees each school's consents, their dates and author, never the school's data.
CREATE FUNCTION platform.support_grants(p_tenant uuid)
RETURNS TABLE (id uuid, include_sensitive boolean, reason text, starts_at timestamptz, expires_at timestamptz, revoked_at timestamptz,
               granted_by text, consultations bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT g.id, g.include_sensitive, g.reason, g.starts_at, g.expires_at, g.revoked_at, u.name::text,
         (SELECT count(*) FROM school.audit_log a WHERE a.tenant_id = g.tenant_id AND a.object_id = g.id AND a.action = 'SUPPORT_READ')
  FROM school.support_grant g LEFT JOIN public.users u ON u.id = g.created_by
  WHERE g.tenant_id = p_tenant ORDER BY g.starts_at DESC $$;
