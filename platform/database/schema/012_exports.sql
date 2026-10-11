-- Exports (FR-IMP-02): every export is logged, who made it, of what, with which filters and
-- how many rows, with its file, kept seven days. The log only grows.
CREATE TABLE school.export_log (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  user_id bigint NOT NULL REFERENCES public.users(id),
  kind text NOT NULL CHECK (kind ~ '^[a-z_]+(\.[a-z_]+)*$'),
  format text NOT NULL CHECK (format IN ('csv', 'xlsx', 'pdf')),
  filters jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(filters) = 'object'),
  columns text[] NOT NULL,
  row_count integer NOT NULL CHECK (row_count >= 0),
  file_id uuid NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, file_id) REFERENCES school.file_object (tenant_id, id)
);
CREATE INDEX export_log_time ON school.export_log (tenant_id, created_at DESC);
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.export_log FOR EACH ROW EXECUTE FUNCTION school.immutable();
ALTER TABLE school.export_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.export_log FORCE ROW LEVEL SECURITY;
-- Written by its author; read by its author and by those who read the school's journal.
CREATE POLICY author_writes ON school.export_log FOR INSERT WITH CHECK (tenant_id = school.tenant() AND user_id = school.actor());
CREATE POLICY readers ON school.export_log FOR SELECT USING (tenant_id = school.tenant()
  AND (user_id = school.actor() OR (SELECT school.can_everywhere('audit.log.read'))));
