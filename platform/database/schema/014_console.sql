-- The publisher's console (W1.2): its operators, the first administrator of a new school named
-- by email (FR-PLT-01), and supervision by aggregated counts, without any school data (FR-PLT-05).

-- Operators of the console (the `super_admin` of section 3.2). Added and revoked from the
-- command line, as the database owner.
CREATE TABLE platform.operator (
  user_id bigint PRIMARY KEY REFERENCES public.users(id),
  created_at timestamptz NOT NULL DEFAULT now(),
  revoked_at timestamptz
);

-- FR-PLT-01 step 1: the person named by email, created without password if not yet known; the
-- invitation lets them choose it. A known person keeps their name.
CREATE FUNCTION platform.ensure_user(p_email text, p_name text) RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE u bigint; email_norm text := lower(btrim(p_email));
BEGIN
  SELECT id INTO u FROM public.users WHERE email = email_norm;
  IF u IS NOT NULL THEN RETURN u; END IF;
  IF length(btrim(coalesce(p_name, ''))) = 0 THEN RAISE EXCEPTION 'name_required' USING ERRCODE = '23514'; END IF;
  INSERT INTO public.users (name, email, created_at, updated_at) VALUES (btrim(p_name), email_norm, now(), now()) RETURNING id INTO u;
  RETURN u;
END $$;

-- FR-PLT-05: each school by its numbers only (modules, active members, storage against quota,
-- jobs, failed emails, last change); nothing a school writes is shown.
CREATE FUNCTION platform.supervision()
RETURNS TABLE (tenant_id uuid, slug text, name text, status text, mode text, plan text, valid_until date, modules integer, members integer,
               storage_used bigint, storage_quota bigint, jobs_active integer, jobs_failed integer, emails_failed integer, last_activity timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT t.id, t.slug, t.name, t.status, platform.tenant_mode(t.id), e.plan_code, e.valid_until,
    (SELECT count(*) FROM platform.tenant_module m WHERE m.tenant_id = t.id)::integer,
    (SELECT count(*) FROM school.membership m WHERE m.tenant_id = t.id AND m.status = 'active')::integer,
    (SELECT coalesce(sum(f.size_bytes), 0) FROM school.file_object f WHERE f.tenant_id = t.id AND f.purged_at IS NULL)::bigint,
    e.quota_storage_mb::bigint * 1048576,
    (SELECT count(*) FROM school.job j WHERE j.tenant_id = t.id AND j.status IN ('queued', 'running'))::integer,
    (SELECT count(*) FROM school.job j WHERE j.tenant_id = t.id AND j.status = 'failed' AND j.updated_at > now() - interval '7 days')::integer,
    (SELECT count(*) FROM school.outbox o WHERE o.tenant_id = t.id AND o.status = 'failed')::integer,
    (SELECT max(a.occurred_at) FROM school.audit_log a WHERE a.tenant_id = t.id)
  FROM platform.tenant t JOIN platform.tenant_entitlement e ON e.tenant_id = t.id
  ORDER BY t.name $$;
