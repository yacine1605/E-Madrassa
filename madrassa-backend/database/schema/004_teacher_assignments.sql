CREATE TABLE school.teacher_assignments (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 cohort_id uuid NOT NULL,
 subject_id uuid NOT NULL,
 teacher_id uuid NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,cohort_id,subject_id),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,cohort_id) REFERENCES school.cohorts(tenant_id,id),
 FOREIGN KEY(tenant_id,subject_id) REFERENCES school.subjects(tenant_id,id),
 FOREIGN KEY(tenant_id,teacher_id) REFERENCES school.staff(tenant_id,id)
);
ALTER TABLE school.teacher_assignments ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.teacher_assignments FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.teacher_assignments USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));
CREATE INDEX teacher_assignments_teacher_fk ON school.teacher_assignments(tenant_id,teacher_id);
CREATE INDEX teacher_assignments_subject_fk ON school.teacher_assignments(tenant_id,subject_id);
DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
  GRANT SELECT, INSERT ON school.teacher_assignments TO school_runtime;
END IF; END $$;
