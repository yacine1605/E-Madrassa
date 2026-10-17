-- Lot 4, the dashboards' cache (SRS 1.2, FR-TDB-03): the figures computed for a reader are kept a
-- while (App\Support\DashboardCache) and computed again as soon as anything changes in the school.
-- Every write to a school table leaves its trace in the audit log (SQL 003), so the school's stamp
-- is its last trace, and the set of its traces of the last quarter of an hour: a transaction that
-- began before the last trace but commits after it changes that set. Its modules, its mode and its
-- settings complete it. The stamp tells when something changed, never what.

CREATE FUNCTION school.dashboard_stamp() RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH t AS (SELECT school.allowed_tenant() AS id)
  SELECT concat_ws('/',
    (SELECT max(a.occurred_at)::text FROM school.audit_log a, t WHERE a.tenant_id = t.id),
    (SELECT count(*) || ':' || coalesce(sum(hashtextextended(a.id::text, 0)), 0) FROM school.audit_log a, t
      WHERE a.tenant_id = t.id AND a.occurred_at > now() - interval '15 minutes'),
    (SELECT string_agg(m.module_key, ',' ORDER BY m.module_key) FROM platform.tenant_module m, t WHERE m.tenant_id = t.id),
    (SELECT platform.tenant_mode(t.id) FROM t),
    (SELECT max(s.updated_at)::text FROM school.setting s, t WHERE s.tenant_id = t.id)) $$;
