-- Lot 2, the pupil's photo (FR-ELV-01, its photo rules; AC0). Optional: nothing depends on it. The
-- application reduces it (at most 1 024 pixels, and a thumbnail of 128) and writes it again without
-- its metadata; the database keeps which files are the pupil's photo, refuses any other file, and
-- retires the previous image as soon as another replaces it or it is removed (BR-17: minimization).
-- A photo is read by whoever may read the pupil's record, never through a public link.

-- A school may turn the photos off: they are hidden, not deleted.
INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('student.photo.enabled', 'boolean', 'true', NULL, NULL, 'tenant.settings.manage', '{"fr": "Photos des élèves", "ar": "صور التلاميذ"}');

-- The photo and its thumbnail, both or neither.
ALTER TABLE school.student ADD COLUMN photo_thumb_file_id uuid,
  ADD FOREIGN KEY (tenant_id, photo_thumb_file_id) REFERENCES school.file_object (tenant_id, id),
  ADD CONSTRAINT student_photo_pair CHECK ((photo_file_id IS NULL) = (photo_thumb_file_id IS NULL));

-- A new photo: two distinct images of the category `student_photo`, made for this pupil, in use;
-- and the school has not turned photos off. Removing one is always possible.
CREATE FUNCTION school.check_student_photo() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF NEW.photo_file_id IS NULL OR (TG_OP = 'UPDATE'
     AND (NEW.photo_file_id, NEW.photo_thumb_file_id) IS NOT DISTINCT FROM (OLD.photo_file_id, OLD.photo_thumb_file_id)) THEN
    RETURN NEW;
  END IF;
  IF TG_OP = 'INSERT' OR NEW.photo_file_id = NEW.photo_thumb_file_id
     OR (SELECT count(*) FROM school.file_object f WHERE f.tenant_id = NEW.tenant_id AND f.id IN (NEW.photo_file_id, NEW.photo_thumb_file_id)
           AND f.category = 'student_photo' AND f.owner_type = 'student' AND f.owner_id = NEW.id
           AND f.archived_at IS NULL AND f.purged_at IS NULL) <> 2 THEN
    RAISE EXCEPTION 'student_photo_invalid' USING ERRCODE = '23514';
  END IF;
  IF NOT (school.setting_value('student.photo.enabled') #>> '{}')::boolean THEN
    RAISE EXCEPTION 'student_photo_disabled' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER photo BEFORE INSERT OR UPDATE OF photo_file_id, photo_thumb_file_id ON school.student
  FOR EACH ROW EXECUTE FUNCTION school.check_student_photo();

-- The images a pupil no longer shows are retired in the same transaction: nobody reads them any
-- more, and they are due for deletion now. The application deletes their bytes once the change is
-- committed; the daily purge (`platform:purge-files`) finishes what it could not.
CREATE FUNCTION school.retire_student_photo() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  UPDATE school.file_object SET archived_at = coalesce(archived_at, now()), expires_at = now()
  WHERE tenant_id = OLD.tenant_id AND id IN (OLD.photo_file_id, OLD.photo_thumb_file_id) AND purged_at IS NULL
    AND id IS DISTINCT FROM NEW.photo_file_id AND id IS DISTINCT FROM NEW.photo_thumb_file_id;
  RETURN NULL;
END $$;
CREATE TRIGGER retire_photo AFTER UPDATE OF photo_file_id, photo_thumb_file_id ON school.student
  FOR EACH ROW WHEN ((OLD.photo_file_id, OLD.photo_thumb_file_id) IS DISTINCT FROM (NEW.photo_file_id, NEW.photo_thumb_file_id))
  EXECUTE FUNCTION school.retire_student_photo();

-- A photo is seen, added or changed only with the pupil it shows in the reader's scope: the
-- category's permission alone is the whole school's (TST-03). Replaces 019's policies.
ALTER POLICY reader ON school.file_object USING (tenant_id = (SELECT school.tenant())
  AND category = ANY ((SELECT school.file_categories('read'))::text[])
  AND (created_by = (SELECT school.actor()) OR category <> ALL ((SELECT school.file_categories('author'))::text[]))
  AND (category <> 'student_photo' OR EXISTS (SELECT 1 FROM school.student s WHERE s.tenant_id = file_object.tenant_id AND s.id = file_object.owner_id)));
ALTER POLICY writer ON school.file_object WITH CHECK (tenant_id = (SELECT school.tenant())
  AND category = ANY ((SELECT school.file_categories('write'))::text[])
  AND (category <> 'student_photo' OR (owner_type = 'student'
    AND EXISTS (SELECT 1 FROM school.student s WHERE s.tenant_id = file_object.tenant_id AND s.id = file_object.owner_id))));
ALTER POLICY updater ON school.file_object USING (tenant_id = (SELECT school.tenant())
  AND category = ANY ((SELECT school.file_categories('write'))::text[])
  AND (created_by = (SELECT school.actor()) OR category <> ALL ((SELECT school.file_categories('author'))::text[]))
  AND (category <> 'student_photo' OR EXISTS (SELECT 1 FROM school.student s WHERE s.tenant_id = file_object.tenant_id AND s.id = file_object.owner_id)));

-- Never a public link to a pupil's photo (FR-ELV-01). Replaces 011's.
CREATE OR REPLACE FUNCTION school.file_for_link(p_tenant uuid, p_id uuid)
RETURNS TABLE (storage_key text, original_name text, media_type text, size_bytes bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT f.storage_key, f.original_name, f.media_type, f.size_bytes FROM school.file_object f
  WHERE f.tenant_id = p_tenant AND f.id = p_id AND f.archived_at IS NULL AND f.purged_at IS NULL AND f.category <> 'student_photo'
    AND platform.tenant_mode(p_tenant) IS DISTINCT FROM 'suspended' $$;

-- BR-17: the journal says a photo changed, never which image. Replaces 025's.
CREATE OR REPLACE FUNCTION school.audit() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE row_data jsonb; old_data jsonb; changed jsonb; before_values jsonb; after_values jsonb; masked text[];
  ctx school.request_context := school.context();
BEGIN
  row_data := (CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END) - 'name_key';
  old_data := (CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE to_jsonb(OLD) END) - 'name_key';
  SELECT COALESCE(jsonb_agg(key), '[]') INTO changed FROM jsonb_each(row_data)
  WHERE TG_OP <> 'UPDATE' OR value IS DISTINCT FROM old_data -> key;
  masked := ARRAY['first_name', 'last_name', 'birth_date', 'birth_place', 'phone', 'email', 'address', 'matricule',
                  'photo', 'observation', 'body', 'payload', 'rendered', 'rules', 'calculation', 'password',
                  'last_name_ar', 'first_name_ar', 'last_name_lat', 'first_name_lat', 'photo_file_id', 'origin_school', 'profession', 'custom',
                  'external_ref', 'photo_thumb_file_id'];
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
