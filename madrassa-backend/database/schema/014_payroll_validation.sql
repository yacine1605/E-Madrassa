-- BR-08 / FR-REM-03: a pay run is calculated as a draft, then validated by another person.
-- Only a validated run can produce the salary expense that disbursements pay.
DROP TRIGGER immutable ON school.payroll_runs;
ALTER TABLE school.payroll_runs
 ADD COLUMN status text NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','validated')),
 ADD COLUMN created_by bigint REFERENCES public.users(id),
 ADD COLUMN validated_by bigint REFERENCES public.users(id);
-- Runs created before this step were validated at creation. Schema backfill, not a business event.
ALTER TABLE school.payroll_runs DISABLE TRIGGER audit;
SET LOCAL row_security = off;
UPDATE school.payroll_runs SET status='validated' WHERE validated_at IS NOT NULL;
SET LOCAL row_security = on;
ALTER TABLE school.payroll_runs ENABLE TRIGGER audit;
ALTER TABLE school.payroll_runs ADD CONSTRAINT payroll_run_validation CHECK((status='validated')=(validated_at IS NOT NULL));

CREATE FUNCTION school.guard_payroll_run() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
 IF NEW.status<>'draft' OR NEW.validated_at IS NOT NULL OR NEW.validated_by IS NOT NULL THEN
 RAISE EXCEPTION 'payroll_starts_as_draft' USING ERRCODE='23514'; END IF;
 NEW.created_by:=school.actor();
 RETURN NEW;
 END IF;
 IF TG_OP='DELETE' THEN
 -- A draft may be discarded to recalculate; a validated run never changes.
 IF OLD.status<>'draft' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE='23514'; END IF;
 RETURN OLD;
 END IF;
 IF OLD.status<>'draft' OR NEW.status<>'validated'
 OR (to_jsonb(NEW)-'status'-'validated_at'-'validated_by') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'validated_at'-'validated_by') THEN
 RAISE EXCEPTION 'immutable_record' USING ERRCODE='23514'; END IF;
 IF NEW.validated_by IS NULL OR NEW.validated_by IS DISTINCT FROM school.actor() THEN
 RAISE EXCEPTION 'validator_must_be_actor' USING ERRCODE='23514'; END IF;
 IF NEW.validated_by=OLD.created_by THEN RAISE EXCEPTION 'segregation_of_duties' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER payroll_run_guard BEFORE INSERT OR UPDATE OR DELETE ON school.payroll_runs FOR EACH ROW EXECUTE FUNCTION school.guard_payroll_run();

CREATE FUNCTION school.guard_payroll_expense() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.payroll_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM school.payroll_runs WHERE tenant_id=NEW.tenant_id
 AND id=NEW.payroll_id AND status='validated' AND amount_minor=NEW.amount_minor) THEN
 RAISE EXCEPTION 'payroll_not_validated' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER payroll_expense_guard BEFORE INSERT ON school.expenses FOR EACH ROW EXECUTE FUNCTION school.guard_payroll_expense();

DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
  GRANT UPDATE(status, validated_at, validated_by), DELETE ON school.payroll_runs TO school_runtime;
END IF; END $$;
