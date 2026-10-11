-- Files and attachments (FR-FIC-01): private storage under `tenant/{id}/…`, a register of every
-- file with its category of rights, its real type, size and fingerprint, within the school's
-- quota (FR-PLT-02). The bytes live in S3-compatible storage, never only in a container.

-- Categories of rights. A file is read by whoever holds one of `read_any`, added by whoever
-- holds one of `write_any`; exports and imports belong to their author alone. Types: fixed for
-- some categories, otherwise the school's setting `files.types`.
CREATE TABLE platform.file_category (
  code text PRIMARY KEY CHECK (code ~ '^[a-z_]+$'),
  read_any text[] NOT NULL DEFAULT '{}',
  write_any text[] NOT NULL CHECK (cardinality(write_any) >= 1),
  author_only boolean NOT NULL DEFAULT false,
  types text[],
  labels jsonb NOT NULL CHECK (school.valid_labels(labels))
);
INSERT INTO platform.file_category (code, read_any, write_any, author_only, types, labels) VALUES
  ('finance_attachment', '{finance.payment.read,expense.attachment.read}', '{finance.payment.create,expense.expense.create}', false, NULL,
   '{"fr": "Justificatif financier", "ar": "وثيقة مالية"}'),
  ('student_document', '{student.record.read}', '{student.record.update}', false, NULL, '{"fr": "Document d’élève", "ar": "وثيقة التلميذ"}'),
  ('student_photo', '{student.record.read}', '{student.record.update}', false, '{jpeg,png,webp}', '{"fr": "Photo d’élève", "ar": "صورة التلميذ"}'),
  ('staff_document', '{staff.profile.read}', '{staff.profile.update}', false, NULL, '{"fr": "Document du personnel", "ar": "وثيقة الموظف"}'),
  ('pedagogy_resource', '{program.program.read}', '{program.program.manage}', false, NULL, '{"fr": "Support pédagogique", "ar": "سند بيداغوجي"}'),
  ('export', '{}', '{export.job.create}', true, '{csv,xlsx,pdf}', '{"fr": "Export", "ar": "تصدير"}'),
  ('import', '{}', '{import.job.create}', true, '{csv,xlsx}', '{"fr": "Fichier importé", "ar": "ملف مستورد"}');

CREATE TABLE school.file_object (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  category text NOT NULL REFERENCES platform.file_category(code),
  storage_key text NOT NULL UNIQUE,
  original_name text NOT NULL CHECK (length(original_name) BETWEEN 1 AND 200),
  file_type text NOT NULL CHECK (file_type IN ('pdf', 'jpeg', 'png', 'webp', 'docx', 'xlsx', 'csv')),
  media_type text NOT NULL,
  size_bytes bigint NOT NULL CHECK (size_bytes > 0),
  sha256 text NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
  -- The record the file belongs to (a payment, a student…), set by the modules that use files.
  owner_type text,
  owner_id uuid,
  -- Temporary files (exports): removed from storage once expired.
  expires_at timestamptz,
  purged_at timestamptz,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  archived_at timestamptz,
  CHECK (storage_key LIKE 'tenant/' || tenant_id::text || '/' || category || '/%'),
  CHECK ((owner_type IS NULL) = (owner_id IS NULL)),
  UNIQUE (tenant_id, id)
);
CREATE INDEX file_object_owner ON school.file_object (tenant_id, owner_type, owner_id);
CREATE INDEX file_object_expiry ON school.file_object (expires_at) WHERE purged_at IS NULL AND expires_at IS NOT NULL;

-- The categories the signed-in user may read, may add to, and those kept to their author.
CREATE FUNCTION school.file_categories(p_mode text) RETURNS text[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  WITH mine AS (SELECT DISTINCT e.permission FROM school.effective_permissions(school.actor(), school.tenant()) e
                WHERE school.allowed(school.tenant()))
  SELECT coalesce(array_agg(c.code), '{}') FROM platform.file_category c
  WHERE CASE p_mode
    WHEN 'read' THEN c.author_only OR EXISTS (SELECT 1 FROM mine WHERE permission = ANY (c.read_any))
    WHEN 'write' THEN EXISTS (SELECT 1 FROM mine WHERE permission = ANY (c.write_any))
    WHEN 'author' THEN c.author_only END $$;

ALTER TABLE school.file_object ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.file_object FORCE ROW LEVEL SECURITY;
-- FR-FIC-01 AC2: a file is seen only with its category's read permission; an export or an
-- import only by its author.
CREATE POLICY reader ON school.file_object FOR SELECT USING (tenant_id = school.tenant()
  AND category = ANY ((SELECT school.file_categories('read'))::text[])
  AND (created_by = school.actor() OR category <> ALL ((SELECT school.file_categories('author'))::text[])));
CREATE POLICY writer ON school.file_object FOR INSERT WITH CHECK (tenant_id = school.tenant()
  AND category = ANY ((SELECT school.file_categories('write'))::text[]));
CREATE POLICY updater ON school.file_object FOR UPDATE USING (tenant_id = school.tenant()
  AND category = ANY ((SELECT school.file_categories('write'))::text[])
  AND (created_by = school.actor() OR category <> ALL ((SELECT school.file_categories('author'))::text[])));

-- The school's storage in use and its quota, in bytes; files purged from storage no longer count.
CREATE FUNCTION school.storage_use(p_tenant uuid) RETURNS TABLE (used bigint, quota bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT (SELECT coalesce(sum(size_bytes), 0)::bigint FROM school.file_object WHERE tenant_id = p_tenant AND purged_at IS NULL),
         (SELECT quota_storage_mb::bigint * 1024 * 1024 FROM platform.tenant_entitlement WHERE tenant_id = p_tenant) $$;

CREATE FUNCTION school.guard_file() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c platform.file_category; use record;
BEGIN
  IF TG_OP = 'UPDATE' THEN
    IF (NEW.tenant_id, NEW.category, NEW.storage_key, NEW.file_type, NEW.media_type, NEW.size_bytes, NEW.sha256, NEW.original_name)
       IS DISTINCT FROM (OLD.tenant_id, OLD.category, OLD.storage_key, OLD.file_type, OLD.media_type, OLD.size_bytes, OLD.sha256, OLD.original_name)
       OR (OLD.owner_id IS NOT NULL AND (NEW.owner_type, NEW.owner_id) IS DISTINCT FROM (OLD.owner_type, OLD.owner_id)) THEN
      RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF OLD.purged_at IS NOT NULL THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
    RETURN NEW;
  END IF;
  SELECT * INTO c FROM platform.file_category WHERE code = NEW.category;
  -- An expired or archived school still exports; it adds no other file (FR-PLT-02 AC3).
  IF NEW.category <> 'export' AND platform.tenant_mode(NEW.tenant_id) IS DISTINCT FROM 'active' THEN
    RAISE EXCEPTION 'tenant_read_only' USING ERRCODE = '25006';
  END IF;
  IF NOT (NEW.file_type = ANY (coalesce(c.types, ARRAY(SELECT jsonb_array_elements_text(school.setting_value('files.types')))))) THEN
    RAISE EXCEPTION 'file_type_refused' USING ERRCODE = '23514';
  END IF;
  IF c.types IS NULL AND NEW.size_bytes > (school.setting_value('files.max_mb') #>> '{}')::bigint * 1024 * 1024 THEN
    RAISE EXCEPTION 'file_too_large' USING ERRCODE = '23514';
  END IF;
  -- One file at a time per school past this point, so that two uploads cannot both fit.
  PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text, 16));
  SELECT * INTO use FROM school.storage_use(NEW.tenant_id);
  IF use.used + NEW.size_bytes > use.quota THEN RAISE EXCEPTION 'storage_quota_exceeded' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE ON school.file_object FOR EACH ROW EXECUTE FUNCTION school.guard_file();
CREATE TRIGGER stamp BEFORE INSERT OR UPDATE ON school.file_object FOR EACH ROW EXECUTE FUNCTION school.stamp();
CREATE TRIGGER audit AFTER INSERT OR UPDATE ON school.file_object FOR EACH ROW EXECUTE FUNCTION school.audit();
-- File names can say much about a person: their journal is the school's administrators'.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('file_object', 'settings', '{tenant.settings.manage}');

-- A temporary signed link (≤ 15 min) is checked by the application before this is called:
-- the file it names, still in storage, without a school session.
CREATE FUNCTION school.file_for_link(p_tenant uuid, p_id uuid)
RETURNS TABLE (storage_key text, original_name text, media_type text, size_bytes bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT f.storage_key, f.original_name, f.media_type, f.size_bytes FROM school.file_object f
  WHERE f.tenant_id = p_tenant AND f.id = p_id AND f.archived_at IS NULL AND f.purged_at IS NULL
    AND platform.tenant_mode(p_tenant) IS DISTINCT FROM 'suspended' $$;

-- The purge's side (FR-IMP-02: exports kept 7 days): expired files, then each one marked removed.
CREATE FUNCTION platform.expired_files(p_limit integer) RETURNS TABLE (id uuid, storage_key text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT f.id, f.storage_key FROM school.file_object f
  WHERE f.purged_at IS NULL AND f.expires_at < now() ORDER BY f.expires_at LIMIT p_limit $$;

CREATE FUNCTION platform.mark_purged(p_id uuid) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  UPDATE school.file_object SET purged_at = now(), archived_at = coalesce(archived_at, now()) WHERE id = p_id AND purged_at IS NULL $$;

-- Settings: which types the school accepts and their size (FR-FIC-01: configurable; by
-- default PDF, JPEG, PNG, DOCX, XLSX, 10 MB). A set of codes checked against its list.
ALTER TABLE platform.setting_definition ADD COLUMN allowed_values text[];
ALTER TABLE platform.setting_definition DROP CONSTRAINT setting_definition_value_type_check;
ALTER TABLE platform.setting_definition ADD CONSTRAINT setting_definition_value_type_check
  CHECK (value_type IN ('integer', 'boolean', 'text', 'day_set', 'language_set', 'code_set'));
ALTER TABLE platform.setting_definition ADD CHECK ((value_type = 'code_set') = (allowed_values IS NOT NULL));
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels, allowed_values) VALUES
  ('files.types', 'code_set', '["pdf", "jpeg", "png", "docx", "xlsx"]', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Types de fichiers acceptés", "ar": "أنواع الملفات المقبولة"}', '{pdf,jpeg,png,webp,docx,xlsx,csv}'),
  ('files.max_mb', 'integer', '10', 1, 50, 'tenant.settings.manage', '{"fr": "Taille maximale d’un fichier (Mo)", "ar": "الحجم الأقصى للملف (ميغابايت)"}', NULL);

CREATE OR REPLACE FUNCTION school.guard_setting() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE def platform.setting_definition; n numeric;
BEGIN
  SELECT * INTO def FROM platform.setting_definition WHERE key = NEW.key;
  IF NOT school.is_system() AND NOT school.can_everywhere(def.permission) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF NOT (CASE def.value_type
       WHEN 'boolean' THEN jsonb_typeof(NEW.value) = 'boolean'
       WHEN 'integer' THEN jsonb_typeof(NEW.value) = 'number'
       WHEN 'text' THEN jsonb_typeof(NEW.value) = 'string' AND length(NEW.value #>> '{}') <= coalesce(def.max_value, 1000)
       -- Days 0 (Sunday) to 6 (Saturday), each once, at least one.
       WHEN 'day_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) BETWEEN 1 AND 7
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) d WHERE jsonb_typeof(d) <> 'number' OR (d #>> '{}') NOT IN ('0', '1', '2', '3', '4', '5', '6'))
         AND (SELECT count(DISTINCT d #>> '{}') FROM jsonb_array_elements(NEW.value) d) = jsonb_array_length(NEW.value)
       WHEN 'language_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) BETWEEN 1 AND 3
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) l WHERE jsonb_typeof(l) <> 'string' OR (l #>> '{}') NOT IN ('fr', 'ar', 'en'))
         AND (SELECT count(DISTINCT l #>> '{}') FROM jsonb_array_elements(NEW.value) l) = jsonb_array_length(NEW.value)
       -- Codes of the definition's list, each once, at least one.
       WHEN 'code_set' THEN jsonb_typeof(NEW.value) = 'array' AND jsonb_array_length(NEW.value) >= 1
         AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.value) v WHERE jsonb_typeof(v) <> 'string' OR NOT ((v #>> '{}') = ANY (def.allowed_values)))
         AND (SELECT count(DISTINCT v #>> '{}') FROM jsonb_array_elements(NEW.value) v) = jsonb_array_length(NEW.value)
     END) THEN
    RAISE EXCEPTION 'setting_invalid' USING ERRCODE = '23514';
  END IF;
  IF def.value_type = 'integer' THEN
    n := (NEW.value #>> '{}')::numeric;
    IF n <> trunc(n) OR n < coalesce(def.min_value, n) OR n > coalesce(def.max_value, n) THEN
      RAISE EXCEPTION 'setting_invalid' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
