-- Madrassa backend, PostgreSQL 16+. Run as migration owner, never as runtime role.
CREATE SCHEMA school;
CREATE FUNCTION school.tenant() RETURNS uuid LANGUAGE sql STABLE AS $$
 SELECT nullif(current_setting('app.tenant_id', true), '')::uuid $$;
CREATE FUNCTION school.actor() RETURNS bigint LANGUAGE sql STABLE AS $$
 SELECT nullif(current_setting('app.user_id', true), '')::bigint $$;
CREATE TABLE school.institutions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), name text NOT NULL,
 kind text NOT NULL CHECK(kind IN ('school','training','mixed')),
 timezone text NOT NULL DEFAULT 'Africa/Algiers' CHECK(timezone='Africa/Algiers'),
 currency char(3) NOT NULL DEFAULT 'DZD' CHECK(currency='DZD'),
 settings jsonb NOT NULL DEFAULT '{}', created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE school.memberships (
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 user_id bigint NOT NULL REFERENCES public.users(id),
 permissions jsonb NOT NULL DEFAULT '[]' CHECK(jsonb_typeof(permissions)='array'),
 active boolean NOT NULL DEFAULT true, PRIMARY KEY(tenant_id,user_id)
);
ALTER TABLE school.memberships ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.memberships FORCE ROW LEVEL SECURITY;
CREATE POLICY own_memberships ON school.memberships USING(user_id=school.actor());
CREATE FUNCTION school.allowed(t uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT t=school.tenant() AND EXISTS(SELECT 1 FROM school.memberships m
 WHERE m.tenant_id=t AND m.user_id=school.actor() AND m.active) $$;
ALTER TABLE school.institutions ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.institutions FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.institutions USING(school.allowed(id)) WITH CHECK(school.allowed(id));

CREATE TABLE school.campuses (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 name text NOT NULL,
 wilaya_code varchar(2),
 address text,
 UNIQUE(tenant_id,name),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id)
);

ALTER TABLE school.campuses ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.campuses FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.campuses USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.periods (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 name text NOT NULL,
 starts_on date NOT NULL,
 ends_on date NOT NULL,
 CHECK(ends_on>=starts_on),
 UNIQUE(tenant_id,name),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id)
);

ALTER TABLE school.periods ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.periods FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.periods USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.programs (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 code varchar(40) NOT NULL,
 name text NOT NULL,
 kind text NOT NULL CHECK(kind IN ('school','vocational','language','short_course','other')),
 description text,
 active boolean NOT NULL DEFAULT true,
 UNIQUE(tenant_id,code),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id)
);

ALTER TABLE school.programs ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.programs FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.programs USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.levels (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 program_id uuid NOT NULL,
 code text NOT NULL,
 name text NOT NULL,
 position smallint NOT NULL DEFAULT 0,
 UNIQUE(tenant_id,program_id,code),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,program_id) REFERENCES school.programs(tenant_id,id)
);

ALTER TABLE school.levels ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.levels FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.levels USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.subjects (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 code text NOT NULL,
 name text NOT NULL,
 UNIQUE(tenant_id,code),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id)
);

ALTER TABLE school.subjects ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.subjects FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.subjects USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.curriculum (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 level_id uuid NOT NULL,
 subject_id uuid NOT NULL,
 hours numeric(8,2) CHECK(hours>=0),
 coefficient numeric(6,2) NOT NULL DEFAULT 1 CHECK(coefficient>0),
 UNIQUE(tenant_id,level_id,subject_id),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,level_id) REFERENCES school.levels(tenant_id,id),
 FOREIGN KEY(tenant_id,subject_id) REFERENCES school.subjects(tenant_id,id)
);

ALTER TABLE school.curriculum ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.curriculum FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.curriculum USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX curriculum_subject_id_fk ON school.curriculum(tenant_id,subject_id);

CREATE TABLE school.offerings (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 level_id uuid NOT NULL,
 period_id uuid NOT NULL,
 campus_id uuid NOT NULL,
 name text NOT NULL,
 price_minor bigint NOT NULL CHECK(price_minor>=0),
 active boolean NOT NULL DEFAULT true,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,level_id) REFERENCES school.levels(tenant_id,id),
 FOREIGN KEY(tenant_id,period_id) REFERENCES school.periods(tenant_id,id),
 FOREIGN KEY(tenant_id,campus_id) REFERENCES school.campuses(tenant_id,id)
);

ALTER TABLE school.offerings ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.offerings FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.offerings USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX offerings_level_id_fk ON school.offerings(tenant_id,level_id);

CREATE INDEX offerings_period_id_fk ON school.offerings(tenant_id,period_id);

CREATE INDEX offerings_campus_id_fk ON school.offerings(tenant_id,campus_id);

CREATE TABLE school.students (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 matricule varchar(40) NOT NULL,
 first_name text NOT NULL,
 last_name text NOT NULL,
 birth_date date,
 phone varchar(30),
 email text,
 user_id bigint REFERENCES public.users(id),
 active boolean NOT NULL DEFAULT true,
 UNIQUE(tenant_id,matricule),
 UNIQUE(tenant_id,user_id),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id)
);

ALTER TABLE school.students ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.students FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.students USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.guardians (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 name text NOT NULL,
 phone varchar(30),
 email text,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id)
);

ALTER TABLE school.guardians ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.guardians FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.guardians USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.student_guardians (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 student_id uuid NOT NULL,
 guardian_id uuid NOT NULL,
 relationship text NOT NULL DEFAULT 'parent',
 can_collect boolean NOT NULL DEFAULT false,
 UNIQUE(tenant_id,student_id,guardian_id),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,student_id) REFERENCES school.students(tenant_id,id),
 FOREIGN KEY(tenant_id,guardian_id) REFERENCES school.guardians(tenant_id,id)
);

ALTER TABLE school.student_guardians ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.student_guardians FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.student_guardians USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX student_guardians_guardian_id_fk ON school.student_guardians(tenant_id,guardian_id);

CREATE TABLE school.staff (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 code text NOT NULL,
 name text NOT NULL,
 user_id bigint REFERENCES public.users(id),
 is_teacher boolean NOT NULL DEFAULT false,
 active boolean NOT NULL DEFAULT true,
 UNIQUE(tenant_id,code),
 UNIQUE(tenant_id,user_id),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id)
);

ALTER TABLE school.staff ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.staff FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.staff USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.rooms (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 campus_id uuid NOT NULL,
 name text NOT NULL,
 capacity integer NOT NULL CHECK(capacity>0),
 UNIQUE(tenant_id,campus_id,name),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,campus_id) REFERENCES school.campuses(tenant_id,id)
);

ALTER TABLE school.rooms ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.rooms FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.rooms USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.cohorts (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 offering_id uuid NOT NULL,
 name text NOT NULL,
 capacity integer NOT NULL CHECK(capacity>0),
 UNIQUE(tenant_id,offering_id,name),
 UNIQUE(tenant_id,id,offering_id),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,offering_id) REFERENCES school.offerings(tenant_id,id)
);

ALTER TABLE school.cohorts ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.cohorts FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.cohorts USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.enrollments (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 student_id uuid NOT NULL,
 offering_id uuid NOT NULL,
 cohort_id uuid NOT NULL,
 status text NOT NULL DEFAULT 'validated' CHECK(status IN ('pending','validated','suspended','cancelled')),
 price_minor bigint NOT NULL CHECK(price_minor>=0),
 enrolled_on date NOT NULL DEFAULT CURRENT_DATE,
 idempotency_key uuid NOT NULL,
 UNIQUE(tenant_id,idempotency_key),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,student_id) REFERENCES school.students(tenant_id,id),
 FOREIGN KEY(tenant_id,offering_id) REFERENCES school.offerings(tenant_id,id),
 FOREIGN KEY(tenant_id,cohort_id,offering_id) REFERENCES school.cohorts(tenant_id,id,offering_id)
);

ALTER TABLE school.enrollments ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.enrollments FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.enrollments USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX enrollments_student_id_fk ON school.enrollments(tenant_id,student_id);

CREATE INDEX enrollments_offering_id_fk ON school.enrollments(tenant_id,offering_id);

CREATE INDEX enrollments_cohort_id_offering_id_fk ON school.enrollments(tenant_id,cohort_id,offering_id);

CREATE UNIQUE INDEX enrollment_active ON school.enrollments(tenant_id,student_id,offering_id) WHERE status <> 'cancelled';

CREATE TABLE school.invoices (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 enrollment_id uuid NOT NULL,
 number text NOT NULL,
 amount_minor bigint NOT NULL CHECK(amount_minor>=0),
 due_on date NOT NULL,
 UNIQUE(tenant_id,enrollment_id),
 UNIQUE(tenant_id,number),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,enrollment_id) REFERENCES school.enrollments(tenant_id,id)
);

ALTER TABLE school.invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.invoices FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.invoices USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.payments (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 invoice_id uuid NOT NULL,
 amount_minor bigint NOT NULL CHECK(amount_minor>0),
 method text NOT NULL CHECK(method IN ('cash','bank','ccp','card')),
 reference text,
 received_at timestamptz NOT NULL DEFAULT now(),
 idempotency_key uuid NOT NULL,
 UNIQUE(tenant_id,idempotency_key),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,invoice_id) REFERENCES school.invoices(tenant_id,id)
);

ALTER TABLE school.payments ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.payments FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.payments USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX payments_invoice_id_fk ON school.payments(tenant_id,invoice_id);

CREATE TABLE school.refunds (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 payment_id uuid NOT NULL,
 amount_minor bigint NOT NULL CHECK(amount_minor>0),
 reason text NOT NULL CHECK(length(trim(reason))>=3),
 idempotency_key uuid NOT NULL,
 UNIQUE(tenant_id,idempotency_key),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,payment_id) REFERENCES school.payments(tenant_id,id)
);

ALTER TABLE school.refunds ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.refunds FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.refunds USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX refunds_payment_id_fk ON school.refunds(tenant_id,payment_id);

CREATE TABLE school.sessions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 cohort_id uuid NOT NULL,
 subject_id uuid NOT NULL,
 teacher_id uuid NOT NULL,
 room_id uuid NOT NULL,
 starts_at timestamptz NOT NULL,
 ends_at timestamptz NOT NULL,
 status text NOT NULL DEFAULT 'planned' CHECK(status IN ('planned','published','cancelled')),
 CHECK(ends_at>starts_at),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,cohort_id) REFERENCES school.cohorts(tenant_id,id),
 FOREIGN KEY(tenant_id,subject_id) REFERENCES school.subjects(tenant_id,id),
 FOREIGN KEY(tenant_id,teacher_id) REFERENCES school.staff(tenant_id,id),
 FOREIGN KEY(tenant_id,room_id) REFERENCES school.rooms(tenant_id,id)
);

ALTER TABLE school.sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.sessions FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.sessions USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX sessions_cohort_id_fk ON school.sessions(tenant_id,cohort_id);

CREATE INDEX sessions_subject_id_fk ON school.sessions(tenant_id,subject_id);

CREATE INDEX sessions_teacher_id_fk ON school.sessions(tenant_id,teacher_id);

CREATE INDEX sessions_room_id_fk ON school.sessions(tenant_id,room_id);

CREATE TABLE school.attendance (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 session_id uuid NOT NULL,
 enrollment_id uuid NOT NULL,
 status text NOT NULL CHECK(status IN ('present','absent','late','excused')),
 reason text,
 UNIQUE(tenant_id,session_id,enrollment_id),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,session_id) REFERENCES school.sessions(tenant_id,id),
 FOREIGN KEY(tenant_id,enrollment_id) REFERENCES school.enrollments(tenant_id,id)
);

ALTER TABLE school.attendance ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.attendance FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.attendance USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX attendance_enrollment_id_fk ON school.attendance(tenant_id,enrollment_id);

CREATE TABLE school.lesson_versions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 subject_id uuid NOT NULL,
 title text NOT NULL,
 version integer NOT NULL CHECK(version>0),
 body text NOT NULL,
 published_at timestamptz,
 UNIQUE(tenant_id,subject_id,title,version),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,subject_id) REFERENCES school.subjects(tenant_id,id)
);

ALTER TABLE school.lesson_versions ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.lesson_versions FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.lesson_versions USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.assessments (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 cohort_id uuid NOT NULL,
 subject_id uuid NOT NULL,
 title text NOT NULL,
 max_score numeric(8,2) NOT NULL DEFAULT 20 CHECK(max_score>0),
 coefficient numeric(6,2) NOT NULL DEFAULT 1 CHECK(coefficient>0),
 held_on date NOT NULL,
 published_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,cohort_id) REFERENCES school.cohorts(tenant_id,id),
 FOREIGN KEY(tenant_id,subject_id) REFERENCES school.subjects(tenant_id,id)
);

ALTER TABLE school.assessments ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.assessments FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.assessments USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX assessments_cohort_id_fk ON school.assessments(tenant_id,cohort_id);

CREATE INDEX assessments_subject_id_fk ON school.assessments(tenant_id,subject_id);

CREATE TABLE school.grades (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 assessment_id uuid NOT NULL,
 enrollment_id uuid NOT NULL,
 score numeric(8,2) CHECK(score>=0),
 status text NOT NULL CHECK(status IN ('marked','absent','exempt','missing')),
 CHECK((status='marked')=(score IS NOT NULL)),
 reason text,
 UNIQUE(tenant_id,assessment_id,enrollment_id),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,assessment_id) REFERENCES school.assessments(tenant_id,id),
 FOREIGN KEY(tenant_id,enrollment_id) REFERENCES school.enrollments(tenant_id,id)
);

ALTER TABLE school.grades ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.grades FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.grades USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX grades_enrollment_id_fk ON school.grades(tenant_id,enrollment_id);

CREATE TABLE school.contracts (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 staff_id uuid NOT NULL,
 starts_on date NOT NULL,
 ends_on date,
 basis text NOT NULL CHECK(basis IN ('monthly','hourly')),
 rate_minor bigint NOT NULL CHECK(rate_minor>=0),
 CHECK(ends_on IS NULL OR ends_on>=starts_on),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,staff_id) REFERENCES school.staff(tenant_id,id)
);

ALTER TABLE school.contracts ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.contracts FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.contracts USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX contracts_staff_id_fk ON school.contracts(tenant_id,staff_id);

CREATE TABLE school.work_logs (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 contract_id uuid NOT NULL,
 worked_on date NOT NULL,
 minutes integer NOT NULL CHECK(minutes>0 AND minutes<=1440),
 validated_at timestamptz,
 UNIQUE(tenant_id,contract_id,worked_on),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,contract_id) REFERENCES school.contracts(tenant_id,id)
);

ALTER TABLE school.work_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.work_logs FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.work_logs USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.payroll_runs (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 staff_id uuid NOT NULL,
 month date NOT NULL CHECK(EXTRACT(DAY FROM month)=1),
 amount_minor bigint NOT NULL CHECK(amount_minor>=0),
 calculation jsonb NOT NULL,
 validated_at timestamptz,
 UNIQUE(tenant_id,staff_id,month),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,staff_id) REFERENCES school.staff(tenant_id,id)
);

ALTER TABLE school.payroll_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.payroll_runs FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.payroll_runs USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.expenses (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 label text NOT NULL,
 amount_minor bigint NOT NULL CHECK(amount_minor>0),
 incurred_on date NOT NULL,
 payroll_id uuid,
 UNIQUE(tenant_id,payroll_id),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,payroll_id) REFERENCES school.payroll_runs(tenant_id,id)
);

ALTER TABLE school.expenses ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.expenses FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.expenses USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.disbursements (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 expense_id uuid NOT NULL,
 amount_minor bigint NOT NULL CHECK(amount_minor>0),
 paid_on date NOT NULL,
 idempotency_key uuid NOT NULL,
 UNIQUE(tenant_id,idempotency_key),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,expense_id) REFERENCES school.expenses(tenant_id,id)
);

ALTER TABLE school.disbursements ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.disbursements FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.disbursements USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX disbursements_expense_id_fk ON school.disbursements(tenant_id,expense_id);

CREATE TABLE school.documents (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 student_id uuid,
 storage_key text NOT NULL,
 original_name text NOT NULL,
 mime text NOT NULL,
 size_bytes bigint NOT NULL CHECK(size_bytes>=0),
 sha256 char(64) NOT NULL,
 UNIQUE(tenant_id,storage_key),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,student_id) REFERENCES school.students(tenant_id,id)
);

ALTER TABLE school.documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.documents FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.documents USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE INDEX documents_student_id_fk ON school.documents(tenant_id,student_id);

CREATE TABLE school.announcements (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 title text NOT NULL,
 body text NOT NULL,
 published_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id)
);

ALTER TABLE school.announcements ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.announcements FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.announcements USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.outbox (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 topic text NOT NULL,
 payload jsonb NOT NULL,
 processed_at timestamptz,
 attempts integer NOT NULL DEFAULT 0,
 available_at timestamptz NOT NULL DEFAULT now(),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id)
);

ALTER TABLE school.outbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.outbox FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.outbox USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));

CREATE TABLE school.audit_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 actor_id bigint REFERENCES public.users(id),
 entity text NOT NULL,
 entity_id uuid NOT NULL,
 action text NOT NULL,
 changed_fields jsonb NOT NULL DEFAULT '[]',
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id)
);

ALTER TABLE school.audit_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.audit_events FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.audit_events USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));


-- Tenant-leading indexes match list pages, locks and FK lookups.
CREATE INDEX students_name ON school.students(tenant_id,last_name,first_name,id);
CREATE INDEX enrollment_cohort ON school.enrollments(tenant_id,cohort_id,status);
CREATE INDEX invoice_due ON school.invoices(tenant_id,due_on,id);
CREATE INDEX sessions_time ON school.sessions(tenant_id,starts_at,ends_at);
CREATE INDEX outbox_pending ON school.outbox(tenant_id,available_at,id) WHERE processed_at IS NULL;
CREATE INDEX audit_time ON school.audit_events(tenant_id,created_at DESC,id);

CREATE FUNCTION school.immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'immutable_record' USING ERRCODE='23514'; END $$;
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.payments FOR EACH ROW EXECUTE FUNCTION school.immutable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.refunds FOR EACH ROW EXECUTE FUNCTION school.immutable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.invoices FOR EACH ROW EXECUTE FUNCTION school.immutable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.disbursements FOR EACH ROW EXECUTE FUNCTION school.immutable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.audit_events FOR EACH ROW EXECUTE FUNCTION school.immutable();

-- One advisory lock per institution serializes timetable + enrollment mutations.
-- Keep transactions short; replace with finer locks only after measured contention.
CREATE FUNCTION school.guard_enrollment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cap integer; other_count integer; catalog_price bigint;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text,0));
 SELECT capacity INTO cap FROM school.cohorts WHERE tenant_id=NEW.tenant_id AND id=NEW.cohort_id FOR UPDATE;
 IF TG_OP='UPDATE' AND (NEW.student_id,NEW.offering_id,NEW.cohort_id,NEW.price_minor,NEW.idempotency_key,NEW.tenant_id) IS DISTINCT FROM
 (OLD.student_id,OLD.offering_id,OLD.cohort_id,OLD.price_minor,OLD.idempotency_key,OLD.tenant_id) THEN
 RAISE EXCEPTION 'enrollment_identity_frozen' USING ERRCODE='23514'; END IF;
 IF TG_OP='INSERT' THEN
 SELECT price_minor INTO catalog_price FROM school.offerings WHERE tenant_id=NEW.tenant_id AND id=NEW.offering_id AND active;
 IF catalog_price IS NULL OR NEW.price_minor<>catalog_price THEN RAISE EXCEPTION 'invalid_price_snapshot' USING ERRCODE='23514'; END IF;
 END IF;
 IF NEW.status<>'cancelled' THEN
 SELECT count(*) INTO other_count FROM school.enrollments WHERE tenant_id=NEW.tenant_id AND cohort_id=NEW.cohort_id AND status<>'cancelled' AND id<>NEW.id;
 IF other_count>=cap THEN RAISE EXCEPTION 'cohort_full' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM school.sessions a JOIN school.sessions b ON a.tenant_id=b.tenant_id AND a.id<>b.id
 JOIN school.enrollments e ON e.tenant_id=b.tenant_id AND e.cohort_id=b.cohort_id
 WHERE a.tenant_id=NEW.tenant_id AND a.cohort_id=NEW.cohort_id AND e.student_id=NEW.student_id
 AND e.id<>NEW.id AND e.status<>'cancelled' AND a.status<>'cancelled' AND b.status<>'cancelled'
 AND a.starts_at<b.ends_at AND b.starts_at<a.ends_at) THEN RAISE EXCEPTION 'student_schedule_conflict' USING ERRCODE='23514'; END IF;
 END IF; RETURN NEW;
END $$;
CREATE TRIGGER enrollment_guard BEFORE INSERT OR UPDATE ON school.enrollments FOR EACH ROW EXECUTE FUNCTION school.guard_enrollment();

CREATE FUNCTION school.guard_payment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE due bigint; net bigint; enrollment_state text;
BEGIN
 SELECT amount_minor INTO due FROM school.invoices WHERE tenant_id=NEW.tenant_id AND id=NEW.invoice_id FOR UPDATE;
 SELECT e.status INTO enrollment_state FROM school.enrollments e JOIN school.invoices i ON i.tenant_id=e.tenant_id AND i.enrollment_id=e.id
 WHERE i.tenant_id=NEW.tenant_id AND i.id=NEW.invoice_id FOR UPDATE OF e;
 IF enrollment_state='cancelled' THEN RAISE EXCEPTION 'cancelled_enrollment' USING ERRCODE='23514'; END IF;
 SELECT COALESCE(sum(p.amount_minor),0) INTO net FROM school.payments p WHERE p.tenant_id=NEW.tenant_id AND p.invoice_id=NEW.invoice_id;
 net:=net-COALESCE((SELECT sum(r.amount_minor) FROM school.refunds r JOIN school.payments p ON p.tenant_id=r.tenant_id AND p.id=r.payment_id WHERE p.tenant_id=NEW.tenant_id AND p.invoice_id=NEW.invoice_id),0);
 IF net+NEW.amount_minor>due THEN RAISE EXCEPTION 'overpayment' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER payment_guard BEFORE INSERT ON school.payments FOR EACH ROW EXECUTE FUNCTION school.guard_payment();

CREATE FUNCTION school.guard_refund() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE paid bigint; refunded bigint; invoice uuid;
BEGIN
 SELECT invoice_id INTO invoice FROM school.payments WHERE tenant_id=NEW.tenant_id AND id=NEW.payment_id;
 PERFORM 1 FROM school.invoices WHERE tenant_id=NEW.tenant_id AND id=invoice FOR UPDATE;
 SELECT amount_minor INTO paid FROM school.payments WHERE tenant_id=NEW.tenant_id AND id=NEW.payment_id FOR UPDATE;
 SELECT COALESCE(sum(amount_minor),0) INTO refunded FROM school.refunds WHERE tenant_id=NEW.tenant_id AND payment_id=NEW.payment_id;
 IF refunded+NEW.amount_minor>paid THEN RAISE EXCEPTION 'over_refund' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER refund_guard BEFORE INSERT ON school.refunds FOR EACH ROW EXECUTE FUNCTION school.guard_refund();

CREATE FUNCTION school.guard_session() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text,0));
 IF NEW.status='cancelled' THEN RETURN NEW; END IF;
 IF NOT EXISTS(SELECT 1 FROM school.staff WHERE tenant_id=NEW.tenant_id AND id=NEW.teacher_id AND active AND is_teacher) THEN
 RAISE EXCEPTION 'invalid_teacher' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM school.rooms r JOIN school.cohorts c ON c.tenant_id=r.tenant_id
 JOIN school.offerings o ON o.tenant_id=c.tenant_id AND o.id=c.offering_id
 WHERE r.tenant_id=NEW.tenant_id AND r.id=NEW.room_id AND c.id=NEW.cohort_id AND r.campus_id=o.campus_id AND r.capacity>=c.capacity) THEN
 RAISE EXCEPTION 'invalid_room' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM school.sessions s WHERE s.tenant_id=NEW.tenant_id AND s.id<>NEW.id AND s.status<>'cancelled'
 AND s.starts_at<NEW.ends_at AND NEW.starts_at<s.ends_at
 AND (s.cohort_id=NEW.cohort_id OR s.teacher_id=NEW.teacher_id OR s.room_id=NEW.room_id OR EXISTS(
 SELECT 1 FROM school.enrollments a JOIN school.enrollments b ON a.tenant_id=b.tenant_id AND a.student_id=b.student_id
 WHERE a.tenant_id=NEW.tenant_id AND a.cohort_id=NEW.cohort_id AND b.cohort_id=s.cohort_id AND a.status<>'cancelled' AND b.status<>'cancelled'))) THEN
 RAISE EXCEPTION 'schedule_conflict' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER session_guard BEFORE INSERT OR UPDATE ON school.sessions FOR EACH ROW EXECUTE FUNCTION school.guard_session();

CREATE FUNCTION school.guard_result() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target_cohort uuid; actual_cohort uuid; maximum numeric; published timestamptz;
BEGIN
 SELECT cohort_id INTO actual_cohort FROM school.enrollments WHERE tenant_id=NEW.tenant_id AND id=NEW.enrollment_id AND status<>'cancelled';
 IF TG_OP='UPDATE' AND (to_jsonb(NEW)-'status'-'score'-'reason') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'score'-'reason') THEN
 RAISE EXCEPTION 'result_identity_frozen' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='attendance' THEN
 SELECT cohort_id INTO target_cohort FROM school.sessions WHERE tenant_id=NEW.tenant_id AND id=NEW.session_id;
 ELSE
 SELECT cohort_id,max_score,published_at INTO target_cohort,maximum,published FROM school.assessments WHERE tenant_id=NEW.tenant_id AND id=NEW.assessment_id FOR UPDATE;
 IF NEW.score>maximum OR published IS NOT NULL THEN RAISE EXCEPTION 'invalid_or_published_grade' USING ERRCODE='23514'; END IF;
 END IF;
 IF target_cohort IS DISTINCT FROM actual_cohort OR actual_cohort IS NULL THEN RAISE EXCEPTION 'wrong_cohort' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND (NEW.reason IS NULL OR length(trim(NEW.reason))<3) THEN RAISE EXCEPTION 'correction_reason_required' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER result_guard BEFORE INSERT OR UPDATE ON school.grades FOR EACH ROW EXECUTE FUNCTION school.guard_result();
CREATE TRIGGER attendance_guard BEFORE INSERT OR UPDATE ON school.attendance FOR EACH ROW EXECUTE FUNCTION school.guard_result();

CREATE FUNCTION school.guard_disbursement() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE total bigint; paid bigint;
BEGIN
 SELECT amount_minor INTO total FROM school.expenses WHERE tenant_id=NEW.tenant_id AND id=NEW.expense_id FOR UPDATE;
 SELECT COALESCE(sum(amount_minor),0) INTO paid FROM school.disbursements WHERE tenant_id=NEW.tenant_id AND expense_id=NEW.expense_id;
 IF paid+NEW.amount_minor>total THEN RAISE EXCEPTION 'expense_overpayment' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER expense_payment_guard BEFORE INSERT ON school.disbursements FOR EACH ROW EXECUTE FUNCTION school.guard_disbursement();

CREATE FUNCTION school.audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE row_data jsonb; changed jsonb;
BEGIN
 row_data:=CASE WHEN TG_OP='DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
 SELECT COALESCE(jsonb_agg(key),'[]') INTO changed FROM jsonb_each(row_data) WHERE TG_OP<>'UPDATE' OR value IS DISTINCT FROM to_jsonb(OLD)->key;
 INSERT INTO school.audit_events(tenant_id,actor_id,entity,entity_id,action,changed_fields)
 VALUES((row_data->>'tenant_id')::uuid,school.actor(),TG_TABLE_NAME,(row_data->>'id')::uuid,TG_OP,changed);
 RETURN COALESCE(NEW,OLD);
END $$;

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.campuses FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.periods FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.programs FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.levels FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.subjects FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.curriculum FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.offerings FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.students FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.guardians FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.student_guardians FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.staff FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.rooms FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.cohorts FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.enrollments FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.invoices FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.payments FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.refunds FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.sessions FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.attendance FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.lesson_versions FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.assessments FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.grades FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.contracts FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.work_logs FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.payroll_runs FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.expenses FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.disbursements FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.documents FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.announcements FOR EACH ROW EXECUTE FUNCTION school.audit();


-- Views run under caller RLS, not schema owner.
CREATE FUNCTION school.guard_invoice() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM school.enrollments WHERE tenant_id=NEW.tenant_id AND id=NEW.enrollment_id AND price_minor=NEW.amount_minor) THEN
 RAISE EXCEPTION 'invoice_snapshot_mismatch' USING ERRCODE='23514'; END IF; RETURN NEW;
END $$;
CREATE TRIGGER invoice_guard BEFORE INSERT ON school.invoices FOR EACH ROW EXECUTE FUNCTION school.guard_invoice();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.cohorts FOR EACH ROW EXECUTE FUNCTION school.immutable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.expenses FOR EACH ROW EXECUTE FUNCTION school.immutable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.contracts FOR EACH ROW EXECUTE FUNCTION school.immutable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.payroll_runs FOR EACH ROW EXECUTE FUNCTION school.immutable();

CREATE FUNCTION school.guard_work_log() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE staff uuid;
BEGIN
 SELECT staff_id INTO staff FROM school.contracts WHERE tenant_id=NEW.tenant_id AND id=NEW.contract_id
 AND starts_on<=NEW.worked_on AND (ends_on IS NULL OR ends_on>=NEW.worked_on);
 IF staff IS NULL THEN RAISE EXCEPTION 'work_outside_contract' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text||':'||staff::text||':'||date_trunc('month',NEW.worked_on)::date::text,2));
 IF EXISTS(SELECT 1 FROM school.payroll_runs WHERE tenant_id=NEW.tenant_id AND staff_id=staff AND month=date_trunc('month',NEW.worked_on)::date) THEN
 RAISE EXCEPTION 'payroll_already_closed' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND OLD.validated_at IS NOT NULL THEN RAISE EXCEPTION 'validated_hours_frozen' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER work_log_guard BEFORE INSERT OR UPDATE ON school.work_logs FOR EACH ROW EXECUTE FUNCTION school.guard_work_log();

CREATE FUNCTION school.guard_contract() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text||':'||NEW.staff_id::text,3));
 IF EXISTS(SELECT 1 FROM school.contracts WHERE tenant_id=NEW.tenant_id AND staff_id=NEW.staff_id
 AND starts_on<=COALESCE(NEW.ends_on,'infinity'::date) AND COALESCE(ends_on,'infinity'::date)>=NEW.starts_on) THEN
 RAISE EXCEPTION 'overlapping_contract' USING ERRCODE='23514'; END IF; RETURN NEW;
END $$;
CREATE TRIGGER contract_guard BEFORE INSERT ON school.contracts FOR EACH ROW EXECUTE FUNCTION school.guard_contract();

CREATE VIEW school.invoice_balances WITH (security_invoker=true) AS
 SELECT i.*,COALESCE(p.paid,0)-COALESCE(r.refunded,0) AS paid_minor,
 i.amount_minor-COALESCE(p.paid,0)+COALESCE(r.refunded,0) AS balance_minor
 FROM school.invoices i
 LEFT JOIN (SELECT tenant_id,invoice_id,sum(amount_minor) paid FROM school.payments GROUP BY tenant_id,invoice_id) p ON p.tenant_id=i.tenant_id AND p.invoice_id=i.id
 LEFT JOIN (SELECT p.tenant_id,p.invoice_id,sum(r.amount_minor) refunded FROM school.refunds r JOIN school.payments p ON p.tenant_id=r.tenant_id AND p.id=r.payment_id GROUP BY p.tenant_id,p.invoice_id) r ON r.tenant_id=i.tenant_id AND r.invoice_id=i.id;
REVOKE ALL ON SCHEMA school FROM PUBLIC;
REVOKE ALL ON ALL TABLES IN SCHEMA school FROM PUBLIC;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA school FROM PUBLIC;
