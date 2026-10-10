CREATE TABLE school.admissions (
    id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES school.institutions(id),
    student_id uuid, offering_id uuid NOT NULL, enrollment_id uuid,
    matricule varchar(40), first_name text, last_name text, birth_date date,
    phone varchar(30), email text, guardian_name text, guardian_phone varchar(30), previous_school text,
    status text NOT NULL DEFAULT 'submitted' CHECK(status IN ('submitted','waiting','accepted','rejected','withdrawn','enrolled')),
    documents_complete boolean NOT NULL DEFAULT false, review_reason text,
    idempotency_key uuid NOT NULL, request_hash char(64) NOT NULL, created_by bigint NOT NULL REFERENCES public.users(id),
    created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id), UNIQUE(tenant_id,idempotency_key),
    FOREIGN KEY(tenant_id,student_id) REFERENCES school.students(tenant_id,id),
    FOREIGN KEY(tenant_id,offering_id) REFERENCES school.offerings(tenant_id,id),
    FOREIGN KEY(tenant_id,enrollment_id) REFERENCES school.enrollments(tenant_id,id),
    CHECK(student_id IS NOT NULL OR (matricule IS NOT NULL AND first_name IS NOT NULL AND last_name IS NOT NULL)),
    CHECK((status='enrolled')=(enrollment_id IS NOT NULL))
);
CREATE TABLE school.annual_progressions (
    id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES school.institutions(id),
    source_enrollment_id uuid NOT NULL, target_cohort_id uuid, new_enrollment_id uuid, due_on date,
    decision text NOT NULL CHECK(decision IN ('promoted','repeated','left','completed')), reason text NOT NULL,
    decided_by bigint NOT NULL REFERENCES public.users(id), created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(tenant_id,id), UNIQUE(tenant_id,source_enrollment_id), UNIQUE(tenant_id,new_enrollment_id),
    FOREIGN KEY(tenant_id,source_enrollment_id) REFERENCES school.enrollments(tenant_id,id),
    FOREIGN KEY(tenant_id,target_cohort_id) REFERENCES school.cohorts(tenant_id,id),
    FOREIGN KEY(tenant_id,new_enrollment_id) REFERENCES school.enrollments(tenant_id,id),
    CHECK((decision IN ('promoted','repeated'))=(target_cohort_id IS NOT NULL AND new_enrollment_id IS NOT NULL AND due_on IS NOT NULL)),
    CHECK(decision IN ('promoted','repeated') OR (target_cohort_id IS NULL AND new_enrollment_id IS NULL AND due_on IS NULL))
);
ALTER TABLE school.admissions ENABLE ROW LEVEL SECURITY; ALTER TABLE school.admissions FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.admissions USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));
ALTER TABLE school.annual_progressions ENABLE ROW LEVEL SECURITY; ALTER TABLE school.annual_progressions FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.annual_progressions USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));
CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.admissions FOR EACH ROW EXECUTE FUNCTION school.audit();
CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.annual_progressions FOR EACH ROW EXECUTE FUNCTION school.audit();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.annual_progressions FOR EACH ROW EXECUTE FUNCTION school.immutable();
DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
    GRANT SELECT,INSERT ON school.admissions,school.annual_progressions TO school_runtime;
    GRANT UPDATE(status,documents_complete,review_reason,student_id,enrollment_id) ON school.admissions TO school_runtime;
END IF; END $$;
