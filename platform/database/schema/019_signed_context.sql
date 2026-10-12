-- The request's context, signed (SECURITY-REVIEW.md, finding F7). Row-level security and the
-- definer functions learn the school, the person and the support consent of a transaction from
-- `app.context`, which the application sets at its start. Any SQL running as the application's
-- role may set it too; so the application signs it with a key that role cannot read, and the
-- database believes it only with a valid signature, made for this connection, since this
-- transaction began. An SQL injection can then name neither another school nor another person,
-- nor replay another request's context: it can only clear it, which closes everything.

-- The key, derived from the application's (App\Support\DatabaseContext) and installed by
-- `platform:roles` on every deployment. Only the definer functions' owner reads it.
CREATE SCHEMA secret;
REVOKE ALL ON SCHEMA secret FROM PUBLIC;
CREATE TABLE secret.context_key (
  id boolean PRIMARY KEY DEFAULT true CHECK (id),
  -- HMAC-SHA256's two padded keys (RFC 2104): a check costs two hashes, nothing to prepare.
  inner_pad bytea NOT NULL CHECK (length(inner_pad) = 64),
  outer_pad bytea NOT NULL CHECK (length(outer_pad) = 64)
);

CREATE PROCEDURE secret.set_context_key(p_key bytea) LANGUAGE plpgsql AS $$
DECLARE k bytea; ipad bytea; opad bytea;
BEGIN
  IF length(p_key) NOT BETWEEN 32 AND 64 THEN RAISE EXCEPTION 'context_key_length' USING ERRCODE = '22023'; END IF;
  k := p_key || decode(repeat('00', 64 - length(p_key)), 'hex');
  ipad := k; opad := k;
  FOR i IN 0..63 LOOP
    ipad := set_byte(ipad, i, get_byte(k, i) # 54);  -- 0x36
    opad := set_byte(opad, i, get_byte(k, i) # 92);  -- 0x5c
  END LOOP;
  INSERT INTO secret.context_key (inner_pad, outer_pad) VALUES (ipad, opad)
  ON CONFLICT (id) DO UPDATE SET inner_pad = EXCLUDED.inner_pad, outer_pad = EXCLUDED.outer_pad;
END $$;

CREATE TYPE school.request_context AS (tenant_id uuid, user_id bigint, support_grant uuid, request_id text, ip text);

-- This transaction's context, or none when none was set. A context forged, altered, made for
-- another connection or before this transaction began is refused, never ignored. The token is
-- the JSON the application wrote, a dot, and its HMAC in hexadecimal. Five seconds allow for
-- the clocks of the application's host and the database's. Parallel unsafe, as by default: a
-- parallel worker is another server process.
CREATE FUNCTION school.context() RETURNS school.request_context
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE token text := current_setting('app.context', true); payload text; claims jsonb; k secret.context_key;
BEGIN
  IF coalesce(token, '') = '' THEN RETURN NULL; END IF;
  SELECT * INTO k FROM secret.context_key;
  payload := left(token, -65);
  -- Compared through a hash, so that the time taken tells nothing of the expected signature.
  IF k.inner_pad IS NULL OR token !~ '[.][0-9a-f]{64}$' OR sha256(decode(right(token, 64), 'hex'))
     IS DISTINCT FROM sha256(sha256(k.outer_pad || sha256(k.inner_pad || convert_to(payload, 'UTF8')))) THEN
    RAISE EXCEPTION 'context_invalid' USING ERRCODE = '42501';
  END IF;
  claims := payload::jsonb;
  IF (claims ->> 'pid')::integer IS DISTINCT FROM pg_backend_pid()
     OR coalesce((claims ->> 'at')::bigint, 0) < ((extract(epoch FROM transaction_timestamp()) - 5) * 1000000)::bigint THEN
    RAISE EXCEPTION 'context_invalid' USING ERRCODE = '42501';
  END IF;
  RETURN ROW((claims ->> 'tenant')::uuid, (claims ->> 'user')::bigint, (claims ->> 'support')::uuid, claims ->> 'request',
             claims ->> 'ip')::school.request_context;
END $$;

-- Replaces 001's, which believed `app.tenant_id` and `app.user_id`.
CREATE OR REPLACE FUNCTION school.tenant() RETURNS uuid LANGUAGE sql STABLE AS $$
  SELECT (school.context()).tenant_id $$;
CREATE OR REPLACE FUNCTION school.actor() RETURNS bigint LANGUAGE sql STABLE AS $$
  SELECT (school.context()).user_id $$;

-- The consent the current session works under: the context's, of this school, in its validity,
-- not revoked, used by an operator. None otherwise. Replaces 015's.
CREATE OR REPLACE FUNCTION school.support_grant_in_use() RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT g.id FROM school.context() c JOIN school.support_grant g ON g.id = c.support_grant AND g.tenant_id = c.tenant_id
  WHERE g.revoked_at IS NULL AND g.archived_at IS NULL AND now() >= g.starts_at AND now() < g.expires_at
    AND EXISTS (SELECT 1 FROM platform.operator o WHERE o.user_id = c.user_id AND o.revoked_at IS NULL) $$;

-- The second barrier: the school whose rows this transaction reaches, the context's when it is
-- open to an active member, or to the operator under a valid consent, and not suspended.
-- Policies read it once per query, as `(SELECT school.allowed_tenant())`, never once per row.
-- With its owner's rights: the person is signed, so their own membership is read directly,
-- without the membership's policies and the permission checks they make.
CREATE FUNCTION school.allowed_tenant() RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT c.tenant_id FROM school.context() c
  WHERE platform.tenant_mode(c.tenant_id) IS DISTINCT FROM 'suspended'
    AND CASE WHEN c.support_grant IS NULL
      THEN EXISTS (SELECT 1 FROM school.membership m WHERE m.tenant_id = c.tenant_id AND m.user_id = c.user_id AND m.status = 'active')
      ELSE school.support_grant_in_use() IS NOT NULL END $$;

-- Replaces 015's.
CREATE OR REPLACE FUNCTION school.allowed(t uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT coalesce(t = school.allowed_tenant(), false) $$;

-- A support session writes nothing (FR-PLT-04: read-only). Replaces 015's.
CREATE OR REPLACE FUNCTION school.guard_writable() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.tenant_id ELSE NEW.tenant_id END;
BEGIN
  IF (school.context()).support_grant IS NOT NULL THEN
    RAISE EXCEPTION 'support_read_only' USING ERRCODE = '25006';
  END IF;
  IF platform.tenant_mode(t) IS DISTINCT FROM 'active' THEN
    RAISE EXCEPTION 'tenant_read_only' USING ERRCODE = '25006';
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

-- The journal takes its author, request and address from the signed context. Replaces 008's.
CREATE OR REPLACE FUNCTION school.audit() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE row_data jsonb; old_data jsonb; changed jsonb; before_values jsonb; after_values jsonb; masked text[];
  ctx school.request_context := school.context();
BEGIN
  row_data := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
  old_data := CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE to_jsonb(OLD) END;
  SELECT COALESCE(jsonb_agg(key), '[]') INTO changed FROM jsonb_each(row_data)
  WHERE TG_OP <> 'UPDATE' OR value IS DISTINCT FROM old_data -> key;
  masked := ARRAY['first_name', 'last_name', 'birth_date', 'birth_place', 'phone', 'email', 'address', 'matricule',
                  'photo', 'observation', 'body', 'payload', 'rendered', 'rules', 'calculation', 'password'];
  IF TG_OP = 'UPDATE' THEN
    SELECT jsonb_object_agg(key, CASE WHEN key = ANY (masked) THEN to_jsonb('[masqué]'::text) ELSE old_data -> key END),
           jsonb_object_agg(key, CASE WHEN key = ANY (masked) THEN to_jsonb('[masqué]'::text) ELSE value END)
    INTO before_values, after_values
    FROM jsonb_each(row_data)
    WHERE value IS DISTINCT FROM old_data -> key AND key NOT IN ('version', 'updated_at', 'updated_by') AND key NOT LIKE 'label\_%\_key';
  ELSIF TG_OP = 'DELETE' THEN
    SELECT jsonb_object_agg(key, CASE WHEN key = ANY (masked) THEN to_jsonb('[masqué]'::text) ELSE value END) INTO before_values
    FROM jsonb_each(old_data) WHERE key NOT LIKE 'label\_%\_key';
  END IF;
  INSERT INTO school.audit_log (tenant_id, actor_id, action, object_type, object_id, changed_fields, before, after, reason, request_id, ip)
  VALUES ((row_data ->> 'tenant_id')::uuid, ctx.user_id, TG_OP, TG_TABLE_NAME, (row_data ->> 'id')::uuid, changed,
          before_values, after_values, nullif(current_setting('app.reason', true), ''), ctx.request_id, ctx.ip);
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

-- Replaces 008's.
CREATE OR REPLACE FUNCTION platform.audit_tenant() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE tracked text[] := ARRAY['name', 'status', 'default_locale', 'profile_code', 'profile_version']; changed jsonb;
  ctx school.request_context := school.context();
BEGIN
  SELECT COALESCE(jsonb_agg(k), '[]') INTO changed FROM unnest(tracked) k
  WHERE TG_OP = 'INSERT' OR to_jsonb(NEW) -> k IS DISTINCT FROM to_jsonb(OLD) -> k;
  IF changed = '[]' THEN RETURN NEW; END IF;
  INSERT INTO school.audit_log (tenant_id, actor_id, action, object_type, object_id, changed_fields, before, after, reason, request_id, ip)
  VALUES (NEW.id, ctx.user_id, TG_OP, 'tenant', NEW.id, changed,
          CASE WHEN TG_OP = 'UPDATE' THEN (SELECT jsonb_object_agg(k, to_jsonb(OLD) -> k) FROM jsonb_array_elements_text(changed) k) END,
          (SELECT jsonb_object_agg(k, to_jsonb(NEW) -> k) FROM jsonb_array_elements_text(changed) k),
          nullif(current_setting('app.reason', true), ''), ctx.request_id, ctx.ip);
  RETURN NEW;
END $$;

-- Replaces 007's.
CREATE OR REPLACE FUNCTION school.audit_account(p_tenant uuid, p_user bigint, p_action text) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  INSERT INTO school.audit_log (tenant_id, actor_id, action, object_type, object_id, reason, request_id, ip)
  SELECT p_tenant, c.user_id, p_action, 'membership', m.id, nullif(current_setting('app.reason', true), ''), c.request_id, c.ip
  FROM school.context() c, school.membership m WHERE m.tenant_id = p_tenant AND m.user_id = p_user $$;

-- Replaces 015's.
CREATE OR REPLACE FUNCTION school.log_support_access(p_what text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE g uuid := school.support_grant_in_use(); ctx school.request_context := school.context();
BEGIN
  IF g IS NULL THEN RAISE EXCEPTION 'support_not_granted' USING ERRCODE = '42501'; END IF;
  INSERT INTO school.audit_log (tenant_id, actor_id, action, object_type, object_id, reason, request_id, ip)
  VALUES (ctx.tenant_id, ctx.user_id, 'SUPPORT_READ', 'support_grant', g, left(p_what, 300), ctx.request_id, ctx.ip);
END $$;

-- A check of the signature costs about 13 µs where reading a setting cost nothing: a query reads
-- the context once, as `(SELECT school.actor())`, which is evaluated once before the rows, never
-- as a bare call compared with each row. The functions that did follow, each replacing the
-- version named (tests/sql/context-cost.mjs measures them).

-- 004's. The person's schools, by an index rather than a pass over every school's members.
CREATE OR REPLACE FUNCTION school.my_schools() RETURNS TABLE (tenant_id uuid, slug text, name text, default_locale text, mode text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT t.id, t.slug, t.name, t.default_locale, platform.tenant_mode(t.id)
  FROM school.membership m JOIN platform.tenant t ON t.id = m.tenant_id
  WHERE m.user_id = (SELECT school.actor()) AND m.status = 'active'
    AND platform.tenant_mode(t.id) IS DISTINCT FROM 'suspended'
  ORDER BY t.name, t.slug $$;
CREATE INDEX membership_user ON school.membership (user_id);

-- 008's.
CREATE OR REPLACE FUNCTION school.node_subtree(p_nodes uuid[]) RETURNS TABLE (id uuid) LANGUAGE sql STABLE AS $$
  WITH RECURSIVE tree AS (
    SELECT n.id FROM school.ref_node n WHERE n.tenant_id = (SELECT school.tenant()) AND n.id = ANY (p_nodes)
    UNION SELECT c.id FROM school.ref_node c JOIN tree ON c.parent_id = tree.id WHERE c.tenant_id = (SELECT school.tenant()))
  SELECT id FROM tree $$;

-- 010's.
CREATE OR REPLACE FUNCTION school.journal_watchers(p_tenant uuid) RETURNS bigint[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT coalesce(array_agg(DISTINCT m.user_id), '{}') FROM school.membership m
  WHERE m.tenant_id = p_tenant AND m.status = 'active' AND m.user_id IS DISTINCT FROM (SELECT school.actor())
    AND EXISTS (SELECT 1 FROM school.effective_permissions(m.user_id, p_tenant) e WHERE e.permission = 'audit.log.read' AND e.scope_type = 'tenant') $$;

-- 015's. Every permission check passes here: the consent is read once, not once per permission.
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
  JOIN school.support_grant g ON g.tenant_id = p_tenant AND g.id = (SELECT school.support_grant_in_use())
  WHERE p_user = (SELECT school.actor()) AND p_tenant = (SELECT school.tenant()) AND p.code LIKE '%.read'
    AND (NOT p.sensitive OR p.code = 'audit.log.read' OR g.include_sensitive) $$;

-- 015's.
CREATE OR REPLACE FUNCTION school.file_categories(p_mode text) RETURNS text[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH mine AS (SELECT DISTINCT e.permission FROM school.effective_permissions(school.actor(), school.tenant()) e
                WHERE school.allowed(school.tenant())),
  support AS (SELECT g.include_sensitive FROM school.support_grant g WHERE g.id = (SELECT school.support_grant_in_use()))
  SELECT coalesce(array_agg(c.code), '{}') FROM platform.file_category c
  WHERE CASE p_mode
    WHEN 'read' THEN c.author_only OR EXISTS (SELECT 1 FROM mine WHERE permission = ANY (c.read_any))
    WHEN 'write' THEN EXISTS (SELECT 1 FROM mine WHERE permission = ANY (c.write_any))
    WHEN 'author' THEN c.author_only END
    AND NOT (c.code = 'finance_attachment' AND EXISTS (SELECT 1 FROM support WHERE NOT include_sensitive)) $$;

-- Policies too read the context once per query. Tables protected from now on get the same
-- policy. Replaces 003's.
CREATE OR REPLACE PROCEDURE school.protect(tbl regclass) LANGUAGE plpgsql AS $$
BEGIN
  EXECUTE format('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', tbl);
  EXECUTE format('ALTER TABLE %s FORCE ROW LEVEL SECURITY', tbl);
  EXECUTE format('CREATE POLICY tenant ON %s USING (tenant_id = (SELECT school.allowed_tenant())) WITH CHECK (tenant_id = (SELECT school.allowed_tenant()))', tbl);
  EXECUTE format('CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION school.guard_writable()', tbl);
  EXECUTE format('CREATE TRIGGER stamp BEFORE INSERT OR UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION school.stamp()', tbl);
  EXECUTE format('CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION school.audit()', tbl);
END $$;

DO $$ DECLARE p record; BEGIN
  FOR p IN SELECT tablename, qual, with_check FROM pg_policies WHERE schemaname = 'school' AND policyname = 'tenant' LOOP
    IF (p.qual, p.with_check) IS DISTINCT FROM ('school.allowed(tenant_id)', 'school.allowed(tenant_id)') THEN
      RAISE EXCEPTION 'unexpected tenant policy on school.%', p.tablename;
    END IF;
    EXECUTE format('ALTER POLICY tenant ON school.%I USING (tenant_id = (SELECT school.allowed_tenant())) WITH CHECK (tenant_id = (SELECT school.allowed_tenant()))',
                   p.tablename);
  END LOOP;
END $$;
ALTER POLICY own_school ON platform.tenant USING (id = (SELECT school.allowed_tenant()));
ALTER POLICY own_membership ON school.membership USING (user_id = (SELECT school.actor()));
ALTER POLICY school_members ON school.membership USING (
  tenant_id = (SELECT school.tenant()) AND ((SELECT school.can('user.account.read')) OR (SELECT school.can('role.assignment.manage'))));
ALTER POLICY reader ON school.audit_log
  USING (tenant_id = (SELECT school.tenant()) AND object_type = ANY ((SELECT school.audit_readable_types())::text[]));
ALTER POLICY own_jobs ON school.job USING (tenant_id = (SELECT school.tenant()) AND user_id = (SELECT school.actor()))
  WITH CHECK (tenant_id = (SELECT school.tenant()) AND user_id = (SELECT school.actor()));
ALTER POLICY own_notifications ON school.notification USING (tenant_id = (SELECT school.tenant()) AND user_id = (SELECT school.actor()));
ALTER POLICY own_preferences ON school.notification_preference USING (tenant_id = (SELECT school.tenant()) AND user_id = (SELECT school.actor()))
  WITH CHECK (tenant_id = (SELECT school.tenant()) AND user_id = (SELECT school.actor()));
ALTER POLICY settings_reader ON school.outbox
  USING (tenant_id = (SELECT school.tenant()) AND (SELECT school.can_everywhere('tenant.settings.manage')));
ALTER POLICY reader ON school.file_object USING (tenant_id = (SELECT school.tenant())
  AND category = ANY ((SELECT school.file_categories('read'))::text[])
  AND (created_by = (SELECT school.actor()) OR category <> ALL ((SELECT school.file_categories('author'))::text[])));
ALTER POLICY writer ON school.file_object WITH CHECK (tenant_id = (SELECT school.tenant())
  AND category = ANY ((SELECT school.file_categories('write'))::text[]));
ALTER POLICY updater ON school.file_object USING (tenant_id = (SELECT school.tenant())
  AND category = ANY ((SELECT school.file_categories('write'))::text[])
  AND (created_by = (SELECT school.actor()) OR category <> ALL ((SELECT school.file_categories('author'))::text[])));
ALTER POLICY author_writes ON school.export_log WITH CHECK (tenant_id = (SELECT school.tenant()) AND user_id = (SELECT school.actor()));
ALTER POLICY readers ON school.export_log USING (tenant_id = (SELECT school.tenant())
  AND (user_id = (SELECT school.actor()) OR (SELECT school.can_everywhere('audit.log.read'))));
ALTER POLICY author ON school.import_batch USING (tenant_id = (SELECT school.tenant()) AND user_id = (SELECT school.actor()))
  WITH CHECK (tenant_id = (SELECT school.tenant()) AND user_id = (SELECT school.actor()));
ALTER POLICY follower ON school.import_batch USING (tenant_id = (SELECT school.tenant()) AND (SELECT school.can_everywhere('import.job.read')));
ALTER POLICY rows ON school.import_row USING (tenant_id = (SELECT school.tenant())
  AND EXISTS (SELECT 1 FROM school.import_batch b WHERE b.tenant_id = import_row.tenant_id AND b.id = import_row.batch_id));
