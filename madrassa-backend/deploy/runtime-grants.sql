-- Run as database administrator AFTER migrations. No password in this file.
-- Provision login password securely outside the application. Safe to run again.
DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
  CREATE ROLE school_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
END IF; END $$;
GRANT USAGE ON SCHEMA public, school TO school_runtime;
GRANT SELECT ON public.users TO school_runtime;
GRANT UPDATE (password, remember_token, updated_at) ON public.users TO school_runtime;
GRANT SELECT ON ALL TABLES IN SCHEMA school TO school_runtime;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA school TO school_runtime;
-- Memberships and institutions are provisioned separately; runtime cannot grant itself permissions.
GRANT INSERT ON school.campuses, school.periods, school.programs, school.levels,
 school.subjects, school.curriculum, school.offerings, school.cohorts, school.rooms,
 school.students, school.guardians, school.student_guardians, school.staff, school.teacher_assignments,
 school.contracts, school.work_logs, school.sessions, school.lesson_versions,
 school.assessments, school.announcements, school.expenses, school.enrollments,
 school.invoices, school.payments, school.refunds, school.disbursements, school.payment_reversals,
 school.payroll_runs, school.attendance, school.grades, school.audit_events, school.outbox TO school_runtime;
-- Catalog edits and archiving. Codes and registration numbers are deliberately not updatable.
GRANT UPDATE(name, wilaya_code, address, archived_at) ON school.campuses TO school_runtime;
GRANT UPDATE(name, starts_on, ends_on, archived_at) ON school.periods TO school_runtime;
GRANT UPDATE(name, kind, description, active, archived_at) ON school.programs TO school_runtime;
GRANT UPDATE(program_id, name, position, archived_at) ON school.levels TO school_runtime;
GRANT UPDATE(name, archived_at) ON school.subjects TO school_runtime;
GRANT UPDATE(level_id, subject_id, hours, coefficient, archived_at) ON school.curriculum TO school_runtime;
GRANT UPDATE(level_id, period_id, campus_id, name, price_minor, active, archived_at) ON school.offerings TO school_runtime;
GRANT UPDATE(offering_id, name, capacity, archived_at) ON school.cohorts TO school_runtime;
GRANT UPDATE(campus_id, name, capacity, archived_at) ON school.rooms TO school_runtime;
GRANT UPDATE(first_name, last_name, birth_date, phone, email, active, archived_at) ON school.students TO school_runtime;
GRANT UPDATE(name, phone, email, archived_at) ON school.guardians TO school_runtime;
GRANT UPDATE(student_id, guardian_id, relationship, can_collect, archived_at) ON school.student_guardians TO school_runtime;
GRANT UPDATE(name, role, is_teacher, active, archived_at) ON school.staff TO school_runtime;
GRANT SELECT, INSERT, UPDATE, DELETE ON school.teacher_qualifications TO school_runtime;
GRANT UPDATE(status) ON school.enrollments, school.sessions TO school_runtime;
GRANT UPDATE(published_at) ON school.assessments, school.lesson_versions, school.announcements TO school_runtime;
GRANT UPDATE(validated_at, minutes, rejected_at, review_reason, reviewed_by) ON school.work_logs TO school_runtime;
-- A draft pay run can be validated by another person, or discarded.
GRANT UPDATE(status, validated_at, validated_by), DELETE ON school.payroll_runs TO school_runtime;
-- SELECT FOR UPDATE needs UPDATE privilege. Immutable triggers still reject changes.
GRANT UPDATE(id) ON school.cohorts, school.invoices, school.payments, school.expenses TO school_runtime;
GRANT UPDATE(session_id,enrollment_id,status,reason) ON school.attendance TO school_runtime;
GRANT UPDATE(assessment_id,enrollment_id,status,score,reason) ON school.grades TO school_runtime;
GRANT SELECT, INSERT ON school.report_card_terms TO school_runtime;
GRANT SELECT, INSERT, UPDATE(observation) ON school.report_card_observations TO school_runtime;
GRANT SELECT, INSERT, UPDATE(status,payload,content_hash,rendered,validated_by,validated_at,closed_by,closed_at) ON school.report_card_archives TO school_runtime;
GRANT SELECT, INSERT ON school.admissions, school.annual_progressions TO school_runtime;
GRANT UPDATE(status,documents_complete,review_reason,student_id,enrollment_id) ON school.admissions TO school_runtime;
-- Do not grant DELETE on business records, ownership, BYPASSRLS, membership writes or schema CREATE.
