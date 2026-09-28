-- psql variables required: tenant_name, tenant_kind, user_id.
-- Execute with the provisioning administrator, never runtime.
-- Existing Laravel user must already exist. This intentionally does not create passwords.
BEGIN;
SELECT pg_advisory_xact_lock(hashtextextended('school-deployment-provision', 0));
SELECT EXISTS(SELECT 1 FROM school.deployment WHERE id=1) AS configured \gset
\if :configured
SELECT institution_id AS tenant_id FROM school.deployment WHERE id=1 \gset
\else
INSERT INTO school.institutions(name,kind) VALUES(:'tenant_name',:'tenant_kind') RETURNING id AS tenant_id \gset
INSERT INTO school.deployment(id,institution_id) VALUES(1,:'tenant_id');
\endif
INSERT INTO school.memberships(tenant_id,user_id,permissions) VALUES(:'tenant_id',:'user_id',
 '["catalog.read","catalog.write","students.read","students.write","enrollments.read","enrollments.write","pedagogy.read","pedagogy.write","finance.read","finance.write","hr.read","hr.write","payroll.read","payroll.write","audit.read"]') ON CONFLICT (tenant_id,user_id) DO NOTHING;
COMMIT;
\echo Created institution :tenant_id
