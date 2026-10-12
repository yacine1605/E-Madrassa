-- SRS §7.2, BR-12: every change of an enrolment's state keeps its reason, a validation's too. A
-- validated enrolment holds no `status_reason` (it is the reason of a suspension or a
-- cancellation); the history takes the reason the request gave (`app.reason`) when the row has none.
CREATE OR REPLACE FUNCTION school.follow_enrollment() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF TG_OP = 'INSERT' OR NEW.status IS DISTINCT FROM OLD.status THEN
    INSERT INTO school.enrollment_status_history (tenant_id, enrollment_id, from_status, to_status, reason, changed_by)
    VALUES (NEW.tenant_id, NEW.id, CASE WHEN TG_OP = 'UPDATE' THEN OLD.status END, NEW.status,
            coalesce(NEW.status_reason, CASE WHEN TG_OP = 'UPDATE' THEN nullif(btrim(current_setting('app.reason', true)), '') END), school.actor());
  END IF;
  IF TG_OP = 'UPDATE' AND NEW.status = 'cancelled' AND OLD.status <> 'cancelled' THEN
    UPDATE school.group_membership SET valid_to = greatest(current_date, valid_from)
    WHERE tenant_id = NEW.tenant_id AND enrollment_id = NEW.id AND valid_to IS NULL;
  END IF;
  RETURN NEW;
END $$;
