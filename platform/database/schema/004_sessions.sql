-- Signing in (W1.1): the application learns which schools a user may open, then works in
-- one of them. The school is chosen from this list on the server, never taken from a request.

-- Active memberships in schools that are not suspended.
CREATE FUNCTION school.my_schools() RETURNS TABLE (tenant_id uuid, slug text, name text, default_locale text, mode text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT t.id, t.slug, t.name, t.default_locale, platform.tenant_mode(t.id)
  FROM school.membership m JOIN platform.tenant t ON t.id = m.tenant_id
  WHERE m.user_id = school.actor() AND m.status = 'active'
    AND platform.tenant_mode(t.id) IS DISTINCT FROM 'suspended'
  ORDER BY t.name, t.slug $$;

-- Modules enabled for the current school, for menus and API guards (ARC-04).
CREATE FUNCTION school.my_modules() RETURNS SETOF text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT m.module_key FROM platform.tenant_module m
  WHERE m.tenant_id = school.tenant() AND school.allowed(school.tenant())
  ORDER BY m.module_key $$;

REVOKE EXECUTE ON FUNCTION school.my_schools(), school.my_modules() FROM PUBLIC;
