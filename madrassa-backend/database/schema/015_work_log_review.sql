-- BR-08 / FR-PTG-02: hours record who entered them and who reviewed them;
-- the person who entered hours cannot validate them.
ALTER TABLE school.work_logs
 ADD COLUMN entered_by bigint REFERENCES public.users(id),
 ADD COLUMN reviewed_by bigint REFERENCES public.users(id);

CREATE OR REPLACE FUNCTION school.guard_work_log() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE staff uuid;
BEGIN
 SELECT staff_id INTO staff FROM school.contracts WHERE tenant_id=NEW.tenant_id AND id=NEW.contract_id
 AND starts_on<=NEW.worked_on AND (ends_on IS NULL OR ends_on>=NEW.worked_on);
 IF staff IS NULL THEN RAISE EXCEPTION 'work_outside_contract' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text||':'||staff::text||':'||date_trunc('month',NEW.worked_on)::date::text,2));
 IF EXISTS(SELECT 1 FROM school.payroll_runs WHERE tenant_id=NEW.tenant_id AND staff_id=staff AND month=date_trunc('month',NEW.worked_on)::date) THEN
 RAISE EXCEPTION 'payroll_already_closed' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND OLD.validated_at IS NOT NULL THEN RAISE EXCEPTION 'validated_hours_frozen' USING ERRCODE='23514'; END IF;
 IF TG_OP='INSERT' THEN
 NEW.entered_by:=school.actor(); NEW.reviewed_by:=NULL;
 RETURN NEW;
 END IF;
 IF NEW.validated_at IS NULL AND NEW.rejected_at IS NULL THEN
 -- A new or corrected declaration: its author is whoever wrote it, and it awaits review.
 IF NEW.minutes IS DISTINCT FROM OLD.minutes OR OLD.rejected_at IS NOT NULL THEN NEW.entered_by:=school.actor(); END IF;
 NEW.reviewed_by:=NULL;
 ELSIF NEW.validated_at IS DISTINCT FROM OLD.validated_at OR NEW.rejected_at IS DISTINCT FROM OLD.rejected_at THEN
 IF NEW.minutes IS DISTINCT FROM OLD.minutes THEN RAISE EXCEPTION 'review_changes_hours' USING ERRCODE='23514'; END IF;
 IF NEW.reviewed_by IS NULL OR NEW.reviewed_by IS DISTINCT FROM school.actor() THEN
 RAISE EXCEPTION 'reviewer_must_be_actor' USING ERRCODE='23514'; END IF;
 IF NEW.validated_at IS NOT NULL AND NEW.reviewed_by=OLD.entered_by THEN
 RAISE EXCEPTION 'segregation_of_duties' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;

DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
  GRANT UPDATE(reviewed_by) ON school.work_logs TO school_runtime;
END IF; END $$;
