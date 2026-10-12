-- A pupil may have enrolments outside the updater's read scope. Report only their count,
-- for the confirmation of a block (FR-ELV-03); do not expose the other programmes or prices.
CREATE FUNCTION school.student_enrollment_count(p_student uuid) RETURNS integer
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); n integer;
BEGIN
  IF t IS NULL OR NOT (school.can_everywhere('student.record.update') OR
      p_student = ANY (school.core_ids('student.record.update', 'student'))) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM school.student WHERE tenant_id = t AND id = p_student) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  SELECT count(*) INTO n FROM school.enrollment WHERE tenant_id = t AND student_id = p_student AND status <> 'cancelled';
  RETURN n;
END $$;
