-- Languages (FR-I18-01, NFR-I18N-01): each person's language, with the school's then French
-- as fallbacks; searches tolerant of how a name is written; Arabic-Indic digits on request.

-- The person's own choice; none follows the school.
ALTER TABLE public.users ADD COLUMN locale text CHECK (locale IN ('fr', 'ar', 'en'));

-- The person's language if the school offers it, else the school's language, else French.
-- Without a school (the console, a recovery link), the person's language or French.
CREATE FUNCTION platform.locale_for(p_user bigint, p_tenant uuid) RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT coalesce(
    (SELECT u.locale FROM public.users u WHERE u.id = p_user
       AND (p_tenant IS NULL OR coalesce(
             (SELECT s.value FROM school.setting s WHERE s.tenant_id = p_tenant AND s.key = 'locale.languages' AND s.archived_at IS NULL),
             (SELECT d.default_value FROM platform.setting_definition d WHERE d.key = 'locale.languages')) ? u.locale)),
    (SELECT t.default_locale FROM platform.tenant t WHERE t.id = p_tenant),
    'fr') $$;

-- The key a search compares (FR-I18-01 AC2: « احمد » finds « أحمد »): alif and hamza forms,
-- alif maqsura and ta marbuta folded, diacritics and tatweel dropped; Latin case and accents
-- ignored; Arabic-Indic digits read as digits; hyphens and apostrophes as spaces.
CREATE FUNCTION school.search_key(p text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT nullif(btrim(regexp_replace(lower(translate(
    replace(replace(replace(replace(regexp_replace(p, '[\u064B-\u065F\u0670\u0640\u06D6-\u06ED]', '', 'g'), 'œ', 'oe'), 'Œ', 'oe'), 'æ', 'ae'), 'Æ', 'ae'),
    'أإآٱؤئىةیکÀÁÂÃÄÅàáâãäåÇçÈÉÊËèéêëÌÍÎÏìíîïÑñÒÓÔÕÖòóôõöÙÚÛÜùúûüÝýÿ٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹',
    'ااااوييهيكaaaaaaaaaaaacceeeeeeeeiiiiiiiinnoooooooooouuuuuuuuyyy01234567890123456789')),
    '[\s''’ʼ.-]+', ' ', 'g')), '') $$;

-- Uniqueness of labels (008) dropped Arabic-Indic digits with the diacritics: « قسم ١ » and
-- « قسم ٢ » were one label. Digits now count, read as digits; diacritics alone are dropped.
-- No label written before this version holds such digits (the profiles have none).
CREATE OR REPLACE FUNCTION school.label_key(p text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT nullif(lower(regexp_replace(btrim(regexp_replace(translate(p, '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', '01234567890123456789'),
    '[\u064B-\u065F\u0670\u0640]', '', 'g')), '\s+', ' ', 'g')), '') $$;

-- A site is named like every other label, in French or Arabic at least (BR-18): replaces 003's
-- French-only rule.
ALTER TABLE school.site DROP CONSTRAINT site_labels_check;
ALTER TABLE school.site ADD CONSTRAINT site_labels_check CHECK (school.valid_labels(labels));

-- FR-I18-01: Western digits by default; Arabic-Indic digits in Arabic on the school's request.
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('locale.arabic_digits', 'boolean', 'false', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Chiffres arabes-indiens (٠١٢٣) en arabe", "ar": "الأرقام الهندية (٠١٢٣) في الواجهة العربية"}');

-- Emails go out in each recipient's language. Replaces 010's.
CREATE OR REPLACE FUNCTION platform.claim_outbox(p_limit integer, p_lease_seconds integer)
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
  SELECT c.id, c.tenant_id, u.email::text, u.name::text, c.event, t.name, platform.locale_for(u.id, c.tenant_id)
  FROM claimed c JOIN public.users u ON u.id = c.user_id JOIN platform.tenant t ON t.id = c.tenant_id;
END $$;

-- The default site is named in both languages (BR-18). Replaces 009's.
CREATE OR REPLACE FUNCTION platform.create_tenant(p_creation_key uuid, p_slug text, p_name text, p_default_locale text, p_plan text,
  p_valid_from date, p_valid_until date, p_quota_active_students integer, p_quota_storage_mb integer,
  p_site_code text, p_site_name text, p_admin_user_id bigint, p_profile text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid; m text;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended(p_creation_key::text, 10));
  SELECT id INTO t FROM platform.tenant WHERE creation_key = p_creation_key;
  IF t IS NOT NULL THEN
    IF NOT EXISTS (SELECT 1 FROM platform.tenant WHERE id = t AND slug = p_slug AND name = p_name) THEN
      RAISE EXCEPTION 'creation_key_reused' USING ERRCODE = '23514';
    END IF;
    RETURN t;
  END IF;
  INSERT INTO platform.tenant (slug, name, default_locale, creation_key, created_by)
  VALUES (p_slug, p_name, p_default_locale, p_creation_key, school.actor()) RETURNING id INTO t;
  INSERT INTO platform.tenant_entitlement (tenant_id, plan_code, quota_active_students, quota_storage_mb, valid_from, valid_until)
  VALUES (t, p_plan, p_quota_active_students, p_quota_storage_mb, p_valid_from, p_valid_until);
  PERFORM platform.enable_module(t, 'core');
  FOR m IN SELECT module_key FROM platform.plan_module WHERE plan_code = p_plan LOOP
    PERFORM platform.enable_module(t, m);
  END LOOP;
  -- Named in both languages unless the publisher gave a name, then in the school's language.
  INSERT INTO school.site (tenant_id, code, labels, is_default) VALUES (t, p_site_code,
    CASE WHEN nullif(btrim(p_site_name), '') IS NULL THEN '{"fr": "Site principal", "ar": "الموقع الرئيسي"}'::jsonb
         ELSE jsonb_build_object(CASE WHEN p_default_locale = 'ar' THEN 'ar' ELSE 'fr' END, p_site_name) END, true);
  IF p_profile IS NOT NULL THEN PERFORM platform.apply_profile(t, p_profile); END IF;
  INSERT INTO school.role (tenant_id, code, kind, labels, template_code)
  SELECT t, code, kind, labels, code FROM platform.role_template ORDER BY sort_order;
  INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
  SELECT t, r.id, tp.permission_code FROM platform.role_template_permission tp
  JOIN school.role r ON r.tenant_id = t AND r.template_code = tp.role_code;
  IF p_admin_user_id IS NOT NULL THEN
    INSERT INTO school.membership (tenant_id, user_id, status) VALUES (t, p_admin_user_id, 'invited');
    INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type)
    SELECT t, p_admin_user_id, r.id, 'tenant' FROM school.role r
    JOIN platform.role_template rt ON rt.code = r.template_code AND rt.initial_admin
    WHERE r.tenant_id = t;
  END IF;
  RETURN t;
END $$;
