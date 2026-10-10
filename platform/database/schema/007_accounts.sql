-- Accounts (FR-IAM-01, FR-IAM-02): a person signs in once and may belong to several schools.
-- The person (public.users) holds the credentials; each school holds its account of the
-- person (school.membership): status, username, import reference. Schools create, change,
-- suspend and invite accounts only through the functions below, which check the permission;
-- the application never writes accounts, memberships or tokens directly.

-- Email optional (students, parents), required for privileged roles; stored normalized and
-- unique. The password is empty until the account is activated.
ALTER TABLE public.users ALTER COLUMN email DROP NOT NULL;
ALTER TABLE public.users ALTER COLUMN password DROP NOT NULL;
ALTER TABLE public.users ADD CONSTRAINT users_email_normalized
  CHECK (email IS NULL OR (email = lower(btrim(email)) AND email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'));
ALTER TABLE public.users
  ADD COLUMN must_change_password boolean NOT NULL DEFAULT false,
  ADD COLUMN password_changed_at timestamptz,
  -- TOTP secret, encrypted by the application; the last time step used, so a code is never accepted twice.
  ADD COLUMN mfa_secret text,
  ADD COLUMN mfa_enabled_at timestamptz,
  ADD COLUMN mfa_last_step bigint,
  ADD CONSTRAINT users_mfa_secret CHECK (mfa_enabled_at IS NULL OR mfa_secret IS NOT NULL);

-- Single-use recovery codes, generated when MFA is enabled; only their hash is kept.
CREATE TABLE public.mfa_recovery_code (
  user_id bigint NOT NULL REFERENCES public.users(id),
  code_hash text NOT NULL CHECK (code_hash ~ '^[0-9a-f]{64}$'),
  used_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, code_hash)
);

-- Activation (72 h, for one school) and recovery (1 h) links. Only the hash of the token is
-- stored; a token is used once, and a new one replaces the previous.
CREATE TABLE public.account_token (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id bigint NOT NULL REFERENCES public.users(id),
  tenant_id uuid REFERENCES platform.tenant(id),
  purpose text NOT NULL CHECK (purpose IN ('activation', 'recovery')),
  token_hash text NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
  expires_at timestamptz NOT NULL,
  used_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by bigint REFERENCES public.users(id),
  CHECK ((purpose = 'activation') = (tenant_id IS NOT NULL))
);
CREATE INDEX account_token_user ON public.account_token (user_id, purpose) WHERE used_at IS NULL;

-- The school's account of the person. A username is unique in the school (students sign in
-- with it), and so is the stable reference used by imports (FR-IMP-01, BR-05). A revoked
-- account keeps both: they are never given to someone else.
ALTER TABLE school.membership
  ADD COLUMN id uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE,
  ADD COLUMN username text CHECK (username ~ '^[a-z0-9][a-z0-9._-]{2,39}$'),
  ADD COLUMN external_ref text CHECK (length(btrim(external_ref)) BETWEEN 1 AND 100),
  ADD COLUMN invited_by bigint REFERENCES public.users(id),
  ADD COLUMN activated_at timestamptz,
  ADD CONSTRAINT membership_username UNIQUE (tenant_id, username),
  ADD CONSTRAINT membership_external_ref UNIQUE (tenant_id, external_ref);
-- An expired or archived school adds and changes no account; every change is audited.
CREATE TRIGGER guard_writable BEFORE INSERT OR UPDATE ON school.membership FOR EACH ROW EXECUTE FUNCTION school.guard_writable();
CREATE TRIGGER audit AFTER INSERT OR UPDATE ON school.membership FOR EACH ROW EXECUTE FUNCTION school.audit();

-- An account event that no row change describes (invitation sent, password reset), in the
-- school's journal. Called by the functions below only; never granted to the application.
CREATE FUNCTION school.audit_account(p_tenant uuid, p_user bigint, p_action text) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  INSERT INTO school.audit_log (tenant_id, actor_id, action, object_type, object_id, reason, request_id, ip)
  SELECT p_tenant, school.actor(), p_action, 'membership', m.id, nullif(current_setting('app.reason', true), ''),
         nullif(current_setting('app.request_id', true), ''), nullif(current_setting('app.ip', true), '')
  FROM school.membership m WHERE m.tenant_id = p_tenant AND m.user_id = p_user $$;

CREATE FUNCTION school.require_everywhere(p_permission text) RETURNS void LANGUAGE plpgsql STABLE AS $$
BEGIN
  IF NOT school.can_everywhere(p_permission) THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
END $$;

-- FR-IAM-01: privileged roles (any sensitive permission) need an email, for MFA recovery.
CREATE FUNCTION school.holds_sensitive_role(p_tenant uuid, p_user bigint) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM school.role_assignment a
    JOIN school.role_permission rp ON rp.tenant_id = a.tenant_id AND rp.role_id = a.role_id
    JOIN platform.permission p ON p.code = rp.permission_code AND p.sensitive
    WHERE a.tenant_id = p_tenant AND a.user_id = p_user AND a.archived_at IS NULL
      AND (a.valid_until IS NULL OR a.valid_until >= current_date)) $$;

-- Whether this person belongs to another school too: then their name, email and password
-- are theirs, and no single school changes them.
CREATE FUNCTION school.shared_account(p_tenant uuid, p_user bigint) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM school.membership WHERE user_id = p_user AND tenant_id <> p_tenant) $$;

-- Creates the school's account. A person already known by this email is invited as they are:
-- their name and password stay theirs. Otherwise the person is created, either without a
-- password (activation link) or with a provisional one, changed at first sign-in.
CREATE FUNCTION school.create_account(p_name text, p_email text, p_username text, p_external_ref text, p_password_hash text)
RETURNS TABLE (user_id bigint, linked boolean, status text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); u bigint; email_norm text := lower(btrim(p_email)); is_linked boolean := false; s text;
BEGIN
  PERFORM school.require_everywhere('user.account.create');
  IF email_norm IS NULL AND p_username IS NULL THEN RAISE EXCEPTION 'login_required' USING ERRCODE = '23514'; END IF;
  IF email_norm IS NOT NULL THEN
    SELECT id INTO u FROM public.users WHERE email = email_norm FOR UPDATE;
  END IF;
  IF u IS NOT NULL THEN
    IF EXISTS (SELECT 1 FROM school.membership m WHERE m.tenant_id = t AND m.user_id = u) THEN
      RAISE EXCEPTION 'account_exists' USING ERRCODE = '23505';
    END IF;
    is_linked := true;
  ELSE
    IF length(btrim(coalesce(p_name, ''))) = 0 THEN RAISE EXCEPTION 'name_required' USING ERRCODE = '23514'; END IF;
    INSERT INTO public.users (name, email, password, must_change_password, created_at, updated_at)
    VALUES (btrim(p_name), email_norm, p_password_hash, p_password_hash IS NOT NULL, now(), now()) RETURNING id INTO u;
  END IF;
  s := CASE WHEN p_password_hash IS NOT NULL AND NOT is_linked THEN 'active' ELSE 'invited' END;
  INSERT INTO school.membership (tenant_id, user_id, status, username, external_ref, invited_by, activated_at)
  VALUES (t, u, s, lower(btrim(p_username)), nullif(btrim(p_external_ref), ''), school.actor(), CASE WHEN s = 'active' THEN now() END);
  RETURN QUERY SELECT u, is_linked, s;
END $$;

-- Changes the account: username and reference always; name and email only when the person
-- belongs to this school alone. A privileged account keeps an email.
CREATE FUNCTION school.update_account(p_user bigint, p_name text, p_email text, p_username text, p_external_ref text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); person public.users; email_norm text := lower(btrim(p_email));
BEGIN
  PERFORM school.require_everywhere('user.account.update');
  SELECT * INTO person FROM public.users WHERE id = p_user FOR UPDATE;
  IF NOT FOUND OR NOT EXISTS (SELECT 1 FROM school.membership WHERE tenant_id = t AND user_id = p_user) THEN
    RAISE EXCEPTION 'account_unknown' USING ERRCODE = '23514';
  END IF;
  IF (btrim(p_name), email_norm) IS DISTINCT FROM (person.name, person.email) THEN
    IF school.shared_account(t, p_user) THEN RAISE EXCEPTION 'account_shared' USING ERRCODE = '23514'; END IF;
    IF email_norm IS NULL AND school.holds_sensitive_role(t, p_user) THEN RAISE EXCEPTION 'email_required' USING ERRCODE = '23514'; END IF;
    IF length(btrim(coalesce(p_name, ''))) = 0 THEN RAISE EXCEPTION 'name_required' USING ERRCODE = '23514'; END IF;
    UPDATE public.users SET name = btrim(p_name), email = email_norm, updated_at = now() WHERE id = p_user;
    PERFORM school.audit_account(t, p_user, 'IDENTITY_CHANGE');
  END IF;
  UPDATE school.membership SET username = lower(btrim(p_username)), external_ref = nullif(btrim(p_external_ref), ''), updated_at = now()
  WHERE tenant_id = t AND user_id = p_user
    AND (username, external_ref) IS DISTINCT FROM (lower(btrim(p_username)), nullif(btrim(p_external_ref), ''));
END $$;

-- A provisional password given by the school, changed at the next sign-in: for accounts
-- without email, which have no recovery link. Never for a person of several schools.
CREATE FUNCTION school.reset_password(p_user bigint, p_password_hash text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant();
BEGIN
  PERFORM school.require_everywhere('user.account.update');
  IF p_user = school.actor() THEN RAISE EXCEPTION 'self_account' USING ERRCODE = '23514'; END IF;
  IF NOT EXISTS (SELECT 1 FROM school.membership WHERE tenant_id = t AND user_id = p_user AND status IN ('invited', 'active')) THEN
    RAISE EXCEPTION 'account_unknown' USING ERRCODE = '23514';
  END IF;
  IF school.shared_account(t, p_user) THEN RAISE EXCEPTION 'account_shared' USING ERRCODE = '23514'; END IF;
  UPDATE public.users SET password = p_password_hash, must_change_password = true, password_changed_at = now(),
    remember_token = NULL, updated_at = now() WHERE id = p_user;
  UPDATE school.membership SET status = 'active', activated_at = coalesce(activated_at, now()) WHERE tenant_id = t AND user_id = p_user AND status = 'invited';
  PERFORM school.audit_account(t, p_user, 'PASSWORD_RESET');
END $$;

-- FR-IAM-01: suspend, reactivate, revoke, with a reason. Never one's own account; revoked is
-- final (guard_membership); the last administrator stays (guard_last_manager). Pending
-- activation links die with the account.
CREATE FUNCTION school.set_account_status(p_user bigint, p_status text, p_reason text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); current_status text;
BEGIN
  PERFORM school.require_everywhere('user.account.suspend');
  IF p_user = school.actor() THEN RAISE EXCEPTION 'self_account' USING ERRCODE = '23514'; END IF;
  IF length(btrim(coalesce(p_reason, ''))) < 3 THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  SELECT status INTO current_status FROM school.membership WHERE tenant_id = t AND user_id = p_user FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'account_unknown' USING ERRCODE = '23514'; END IF;
  IF p_status = 'active' AND current_status = 'invited' THEN RAISE EXCEPTION 'activation_required' USING ERRCODE = '23514'; END IF;
  IF p_status NOT IN ('active', 'suspended', 'revoked') THEN RAISE EXCEPTION 'status_invalid' USING ERRCODE = '23514'; END IF;
  PERFORM set_config('app.reason', btrim(p_reason), true);
  UPDATE school.membership SET status = p_status WHERE tenant_id = t AND user_id = p_user AND status <> p_status;
  IF p_status <> 'active' THEN
    UPDATE public.account_token SET expires_at = least(expires_at, now())
    WHERE user_id = p_user AND tenant_id = t AND purpose = 'activation' AND used_at IS NULL;
  END IF;
END $$;

-- A new activation link (72 h) for an invited account; the previous one stops working.
CREATE FUNCTION school.issue_activation(p_user bigint, p_token_hash text) RETURNS timestamptz
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant(); expiry timestamptz := now() + interval '72 hours';
BEGIN
  PERFORM school.require_everywhere('user.account.create');
  IF NOT EXISTS (SELECT 1 FROM school.membership WHERE tenant_id = t AND user_id = p_user AND status = 'invited') THEN
    RAISE EXCEPTION 'not_invited' USING ERRCODE = '23514';
  END IF;
  UPDATE public.account_token SET expires_at = least(expires_at, now())
  WHERE user_id = p_user AND tenant_id = t AND purpose = 'activation' AND used_at IS NULL;
  INSERT INTO public.account_token (user_id, tenant_id, purpose, token_hash, expires_at, created_by)
  VALUES (p_user, t, 'activation', p_token_hash, expiry, school.actor());
  PERFORM school.audit_account(t, p_user, 'INVITATION');
  RETURN expiry;
END $$;

-- FR-PLT-01: the console invites the administrator named when the school was created.
CREATE FUNCTION platform.invite_admin(p_tenant uuid, p_token_hash text)
RETURNS TABLE (user_id bigint, email text, name text, expires_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE u bigint; expiry timestamptz := now() + interval '72 hours';
BEGIN
  SELECT m.user_id INTO u FROM school.membership m
  JOIN school.role_assignment a ON a.tenant_id = m.tenant_id AND a.user_id = m.user_id AND a.archived_at IS NULL
  JOIN school.role r ON r.tenant_id = a.tenant_id AND r.id = a.role_id
  JOIN platform.role_template rt ON rt.code = r.template_code AND rt.initial_admin
  WHERE m.tenant_id = p_tenant AND m.status = 'invited' ORDER BY m.created_at LIMIT 1;
  IF u IS NULL THEN RAISE EXCEPTION 'not_invited' USING ERRCODE = '23514'; END IF;
  UPDATE public.account_token SET expires_at = least(public.account_token.expires_at, now())
  WHERE public.account_token.user_id = u AND tenant_id = p_tenant AND purpose = 'activation' AND used_at IS NULL;
  INSERT INTO public.account_token (user_id, tenant_id, purpose, token_hash, expires_at, created_by)
  VALUES (u, p_tenant, 'activation', p_token_hash, expiry, school.actor());
  PERFORM school.audit_account(p_tenant, u, 'INVITATION');
  RETURN QUERY SELECT x.id, x.email::text, x.name::text, expiry FROM public.users x WHERE x.id = u;
END $$;

-- FR-IAM-02: a recovery link (1 h), by email only. Nothing tells the caller whether the
-- person exists: the application answers the same either way.
CREATE FUNCTION platform.issue_recovery(p_email text, p_token_hash text) RETURNS TABLE (user_id bigint, email text, name text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE person public.users;
BEGIN
  SELECT * INTO person FROM public.users x WHERE x.email = lower(btrim(p_email)) AND x.password IS NOT NULL;
  IF NOT FOUND THEN RETURN; END IF;
  UPDATE public.account_token SET expires_at = least(public.account_token.expires_at, now())
  WHERE public.account_token.user_id = person.id AND purpose = 'recovery' AND used_at IS NULL;
  INSERT INTO public.account_token (user_id, purpose, token_hash, expires_at) VALUES (person.id, 'recovery', p_token_hash, now() + interval '1 hour');
  RETURN QUERY SELECT person.id, person.email::text, person.name::text;
END $$;

-- Uses a link, once (FR-IAM-02 AC3), whatever the concurrency. Activation opens the account
-- and sets the first password; a person who already has one keeps it and just accepts.
-- Recovery sets a new password. Expired, used and unknown links get the same answer.
CREATE FUNCTION platform.redeem_token(p_purpose text, p_token_hash text, p_password_hash text)
RETURNS TABLE (user_id bigint, tenant_id uuid)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE tok public.account_token; has_password boolean;
BEGIN
  SELECT * INTO tok FROM public.account_token x WHERE x.token_hash = p_token_hash AND x.purpose = p_purpose FOR UPDATE;
  IF NOT FOUND OR tok.used_at IS NOT NULL OR tok.expires_at <= now() THEN RAISE EXCEPTION 'token_invalid' USING ERRCODE = '23514'; END IF;
  SELECT x.password IS NOT NULL INTO has_password FROM public.users x WHERE x.id = tok.user_id FOR UPDATE;
  IF p_purpose = 'activation' THEN
    IF NOT EXISTS (SELECT 1 FROM school.membership m WHERE m.tenant_id = tok.tenant_id AND m.user_id = tok.user_id AND m.status = 'invited') THEN
      RAISE EXCEPTION 'token_invalid' USING ERRCODE = '23514';
    END IF;
    IF has_password AND p_password_hash IS NOT NULL THEN RAISE EXCEPTION 'password_already_set' USING ERRCODE = '23514'; END IF;
    UPDATE school.membership m SET status = 'active', activated_at = now() WHERE m.tenant_id = tok.tenant_id AND m.user_id = tok.user_id;
  END IF;
  IF NOT has_password OR p_purpose = 'recovery' THEN
    IF p_password_hash IS NULL THEN RAISE EXCEPTION 'password_required' USING ERRCODE = '23514'; END IF;
    UPDATE public.users SET password = p_password_hash, must_change_password = false, password_changed_at = now(),
      remember_token = NULL, updated_at = now() WHERE id = tok.user_id;
  END IF;
  UPDATE public.account_token SET used_at = now() WHERE id = tok.id;
  RETURN QUERY SELECT tok.user_id, tok.tenant_id;
END $$;

-- What an activation or recovery page shows before the link is used: whose account, which
-- school, whether a password is to be chosen. Nothing for an invalid link.
CREATE FUNCTION platform.peek_token(p_purpose text, p_token_hash text)
RETURNS TABLE (name text, email text, username text, school text, needs_password boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT u.name::text, u.email::text, m.username, t.name, u.password IS NULL OR x.purpose = 'recovery'
  FROM public.account_token x JOIN public.users u ON u.id = x.user_id
  LEFT JOIN school.membership m ON m.tenant_id = x.tenant_id AND m.user_id = x.user_id
  LEFT JOIN platform.tenant t ON t.id = x.tenant_id
  WHERE x.token_hash = p_token_hash AND x.purpose = p_purpose AND x.used_at IS NULL AND x.expires_at > now()
    AND (x.purpose = 'recovery' OR m.status = 'invited') $$;

-- Sign-in by email, or by username within a school (students without email). Revoked
-- accounts never sign in.
CREATE FUNCTION platform.find_login(p_login text, p_school text) RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT CASE WHEN position('@' IN p_login) > 0
    THEN (SELECT id FROM public.users WHERE email = lower(btrim(p_login)))
    ELSE (SELECT m.user_id FROM school.membership m JOIN platform.tenant t ON t.id = m.tenant_id
          WHERE t.slug = p_school AND m.username = lower(btrim(p_login)) AND m.status <> 'revoked') END $$;

-- FR-IAM-02: MFA is compulsory for whoever holds a sensitive permission in this school.
CREATE FUNCTION school.mfa_required() RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT coalesce(bool_or(sensitive), false) FROM school.my_permissions() $$;

-- A privileged role (any sensitive permission) needs an email: replaces the guard of 005.
CREATE OR REPLACE FUNCTION school.guard_role_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE role_kind text; role_archived timestamptz;
BEGIN
  IF NOT school.is_system() THEN
    IF NOT school.can_everywhere('role.assignment.manage') THEN
      RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
    END IF;
    IF NEW.user_id = school.actor() THEN RAISE EXCEPTION 'self_assignment' USING ERRCODE = '23514'; END IF;
  END IF;
  IF TG_OP = 'UPDATE' THEN
    IF (NEW.user_id, NEW.role_id, NEW.valid_from) IS DISTINCT FROM (OLD.user_id, OLD.role_id, OLD.valid_from) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.archived_at IS NOT NULL THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  END IF;
  SELECT kind, archived_at INTO role_kind, role_archived FROM school.role WHERE tenant_id = NEW.tenant_id AND id = NEW.role_id;
  IF TG_OP = 'INSERT' AND role_archived IS NOT NULL THEN RAISE EXCEPTION 'role_archived' USING ERRCODE = '23514'; END IF;
  IF role_kind IN ('student', 'parent') AND NEW.scope_type <> 'personal' THEN
    RAISE EXCEPTION 'scope_personal_required' USING ERRCODE = '23514';
  END IF;
  IF NEW.scope_type = 'site' AND EXISTS (SELECT 1 FROM unnest(NEW.scope_ids) s
       WHERE NOT EXISTS (SELECT 1 FROM school.site WHERE tenant_id = NEW.tenant_id AND id = s)) THEN
    RAISE EXCEPTION 'scope_unknown' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'INSERT' AND EXISTS (SELECT 1 FROM school.role_permission rp JOIN platform.permission p ON p.code = rp.permission_code AND p.sensitive
       WHERE rp.tenant_id = NEW.tenant_id AND rp.role_id = NEW.role_id)
     AND (SELECT email FROM public.users WHERE id = NEW.user_id) IS NULL THEN
    RAISE EXCEPTION 'email_required' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;

-- School settings (FR-REF-06 base): a catalog of known keys with their type, bounds and the
-- permission that changes them; a school stores only what it changes.
CREATE TABLE platform.setting_definition (
  key text PRIMARY KEY CHECK (key ~ '^[a-z_]+(\.[a-z_]+)+$'),
  value_type text NOT NULL CHECK (value_type IN ('integer', 'boolean')),
  default_value jsonb NOT NULL,
  min_value integer,
  max_value integer,
  permission text NOT NULL REFERENCES platform.permission(code),
  labels jsonb NOT NULL CHECK (jsonb_typeof(labels) = 'object' AND labels ? 'fr')
);

CREATE TABLE school.setting (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  key text NOT NULL REFERENCES platform.setting_definition(key),
  value jsonb NOT NULL,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  UNIQUE (tenant_id, key),
  UNIQUE (tenant_id, id)
);
CALL school.protect('school.setting');
CREATE TRIGGER key_frozen BEFORE UPDATE ON school.setting FOR EACH ROW EXECUTE FUNCTION school.freeze_code('key');

CREATE FUNCTION school.guard_setting() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE def platform.setting_definition; n numeric;
BEGIN
  SELECT * INTO def FROM platform.setting_definition WHERE key = NEW.key;
  IF NOT school.is_system() AND NOT school.can_everywhere(def.permission) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF def.value_type = 'boolean' AND jsonb_typeof(NEW.value) <> 'boolean' THEN
    RAISE EXCEPTION 'setting_invalid' USING ERRCODE = '23514';
  END IF;
  IF def.value_type = 'integer' THEN
    IF jsonb_typeof(NEW.value) <> 'number' THEN RAISE EXCEPTION 'setting_invalid' USING ERRCODE = '23514'; END IF;
    n := (NEW.value #>> '{}')::numeric;
    IF n <> trunc(n) OR n < coalesce(def.min_value, n) OR n > coalesce(def.max_value, n) THEN
      RAISE EXCEPTION 'setting_invalid' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard_setting BEFORE INSERT OR UPDATE ON school.setting FOR EACH ROW EXECUTE FUNCTION school.guard_setting();

-- The school's value, or the default.
CREATE FUNCTION school.setting_value(p_key text) RETURNS jsonb LANGUAGE sql STABLE AS $$
  SELECT coalesce((SELECT s.value FROM school.setting s WHERE s.tenant_id = school.tenant() AND s.key = p_key AND s.archived_at IS NULL),
                  (SELECT d.default_value FROM platform.setting_definition d WHERE d.key = p_key)) $$;

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('session.idle_minutes', 'integer', '30', 15, 120, 'tenant.settings.manage', '{"fr": "Déconnexion après inactivité (minutes)"}'),
  ('finance.allow_self_validation', 'boolean', 'false', NULL, NULL, 'tenant.settings.manage', '{"fr": "Autoriser la validation de ses propres paiements"}');
