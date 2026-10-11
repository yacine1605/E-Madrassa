-- Imports (FR-IMP-01): a batch per file, its rows as the preview found them, then as the import
-- applied them, with what each row created or changed, so that a complete import can be undone
-- while nothing depends on it. The original file stays in private storage.
CREATE TABLE school.import_batch (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  user_id bigint NOT NULL REFERENCES public.users(id),
  kind text NOT NULL CHECK (kind ~ '^[a-z_]+(\.[a-z_]+)*$'),
  file_id uuid NOT NULL,
  options jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(options) = 'object'),
  status text NOT NULL DEFAULT 'previewing'
    CHECK (status IN ('previewing', 'previewed', 'importing', 'imported', 'failed', 'rolled_back', 'cancelled')),
  error_message text CHECK (length(error_message) <= 1000),
  created_at timestamptz NOT NULL DEFAULT now(),
  previewed_at timestamptz,
  confirmed_at timestamptz,
  imported_at timestamptz,
  rolled_back_at timestamptz,
  rolled_back_by bigint REFERENCES public.users(id),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, file_id) REFERENCES school.file_object (tenant_id, id)
);

CREATE TABLE school.import_row (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  batch_id uuid NOT NULL,
  line integer NOT NULL CHECK (line >= 1),
  external_ref text,
  data jsonb NOT NULL CHECK (jsonb_typeof(data) = 'object'),
  -- Preview: valid with the action it would take, or rejected with its cause. Import: what it did.
  outcome text NOT NULL CHECK (outcome IN ('valid', 'rejected', 'created', 'updated', 'unchanged', 'undone')),
  action text CHECK (action IN ('create', 'update', 'unchanged')),
  error_code text,
  error_message text CHECK (length(error_message) <= 1000),
  target_type text,
  target_id text,
  previous jsonb,
  UNIQUE (batch_id, line),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, batch_id) REFERENCES school.import_batch (tenant_id, id),
  CHECK ((outcome = 'rejected') = (error_code IS NOT NULL))
);
CREATE INDEX import_row_outcome ON school.import_row (batch_id, outcome, line);

CREATE FUNCTION school.guard_import_batch() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF (NEW.tenant_id, NEW.user_id, NEW.kind, NEW.file_id, NEW.options, NEW.created_at)
     IS DISTINCT FROM (OLD.tenant_id, OLD.user_id, OLD.kind, OLD.file_id, OLD.options, OLD.created_at) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  IF NEW.status <> OLD.status AND (OLD.status, NEW.status) NOT IN (('previewing', 'previewed'), ('previewing', 'failed'),
       ('previewed', 'importing'), ('previewed', 'cancelled'), ('importing', 'imported'), ('importing', 'failed'), ('imported', 'rolled_back')) THEN
    RAISE EXCEPTION 'status_invalid' USING ERRCODE = '23514';
  END IF;
  NEW.previewed_at := CASE WHEN NEW.status = 'previewed' AND OLD.status = 'previewing' THEN now() ELSE OLD.previewed_at END;
  NEW.confirmed_at := CASE WHEN NEW.status = 'importing' AND OLD.status = 'previewed' THEN now() ELSE OLD.confirmed_at END;
  NEW.imported_at := CASE WHEN NEW.status = 'imported' AND OLD.status = 'importing' THEN now() ELSE OLD.imported_at END;
  IF NEW.status = 'rolled_back' AND OLD.status = 'imported' THEN NEW.rolled_back_at := now(); NEW.rolled_back_by := school.actor(); END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE UPDATE ON school.import_batch FOR EACH ROW EXECUTE FUNCTION school.guard_import_batch();

ALTER TABLE school.import_batch ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.import_batch FORCE ROW LEVEL SECURITY;
ALTER TABLE school.import_row ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.import_row FORCE ROW LEVEL SECURITY;
-- Its author works on a batch; whoever follows the school's imports (import.job.read) sees them all.
CREATE POLICY author ON school.import_batch USING (tenant_id = school.tenant() AND user_id = school.actor())
  WITH CHECK (tenant_id = school.tenant() AND user_id = school.actor());
CREATE POLICY follower ON school.import_batch FOR SELECT USING (tenant_id = school.tenant() AND (SELECT school.can_everywhere('import.job.read')));
CREATE POLICY rows ON school.import_row USING (tenant_id = school.tenant()
  AND EXISTS (SELECT 1 FROM school.import_batch b WHERE b.tenant_id = import_row.tenant_id AND b.id = import_row.batch_id));

-- Undoing an imported account (FR-IMP-01 rollback): only an account nobody has used yet, of
-- this school alone, invited and never activated, without any role; it leaves no trace but
-- the journal's. Anything else is an operation that depends on the import.
CREATE FUNCTION school.discard_account(p_user bigint) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.tenant();
BEGIN
  PERFORM school.require_everywhere('user.account.create');
  IF NOT EXISTS (SELECT 1 FROM school.membership WHERE tenant_id = t AND user_id = p_user AND status = 'invited' AND activated_at IS NULL)
     OR EXISTS (SELECT 1 FROM school.role_assignment WHERE tenant_id = t AND user_id = p_user)
     OR EXISTS (SELECT 1 FROM school.membership WHERE user_id = p_user AND tenant_id <> t)
     OR EXISTS (SELECT 1 FROM public.users WHERE id = p_user AND password IS NOT NULL)
     OR EXISTS (SELECT 1 FROM public.account_token WHERE user_id = p_user AND used_at IS NOT NULL) THEN
    RAISE EXCEPTION 'dependent_operation' USING ERRCODE = '23514';
  END IF;
  PERFORM set_config('app.reason', 'import_rollback', true);
  PERFORM school.audit_account(t, p_user, 'DISCARD');
  DELETE FROM public.account_token WHERE user_id = p_user;
  DELETE FROM school.membership WHERE tenant_id = t AND user_id = p_user;
  DELETE FROM public.users WHERE id = p_user;
END $$;

-- Memberships are never deleted, except by that function, which runs as the provisioner.
CREATE FUNCTION school.guard_membership_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF current_user <> 'platform_provisioner' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  RETURN OLD;
END $$;
DROP TRIGGER no_delete ON school.membership;
CREATE TRIGGER no_delete BEFORE DELETE ON school.membership FOR EACH ROW EXECUTE FUNCTION school.guard_membership_delete();
