-- Run as database administrator AFTER migrations. No password in this file.
-- Provision login password securely outside the application.
CREATE ROLE school_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
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
 school.invoices, school.payments, school.refunds, school.disbursements,
 school.payroll_runs, school.attendance, school.grades, school.audit_events, school.outbox TO school_runtime;
GRANT UPDATE(status) ON school.enrollments, school.sessions TO school_runtime;
GRANT UPDATE(published_at) ON school.assessments, school.lesson_versions, school.announcements TO school_runtime;
GRANT UPDATE(validated_at) ON school.work_logs TO school_runtime;
-- SELECT FOR UPDATE needs UPDATE privilege. Immutable triggers still reject changes.
GRANT UPDATE(id) ON school.cohorts, school.invoices, school.payments, school.expenses TO school_runtime;
GRANT UPDATE(session_id,enrollment_id,status,reason) ON school.attendance TO school_runtime;
GRANT UPDATE(assessment_id,enrollment_id,status,score,reason) ON school.grades TO school_runtime;
GRANT UPDATE(role) ON school.staff TO school_runtime;
-- Do not grant DELETE, ownership, BYPASSRLS, membership writes or schema CREATE.
