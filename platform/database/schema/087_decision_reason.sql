-- Lot 5, exit review (SPECIFICATION_LOT5_PEDAGOGIE.md §8, BR-09): a teacher's decision, a zero for a
-- missing work or an excused absence, kept its reason on the mark but not in the journal, whose
-- entries said who and when only. Replaces 083's: the reason now goes with the journal's entries
-- of that change, the mark's insertion and its recomputation (its old and new state).

CREATE OR REPLACE FUNCTION school.decide_mark(p_activity uuid, p_student uuid, p_decision text, p_reason text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); a school.activity; v_reason text := nullif(btrim(p_reason), '');
BEGIN
  SELECT * INTO a FROM school.activity x WHERE x.tenant_id = t AND x.id = p_activity;
  IF a.id IS NULL OR NOT school.manages_activity(a.group_id, a.subject_id) THEN RAISE EXCEPTION 'lms_group_forbidden' USING ERRCODE = '42501'; END IF;
  IF p_decision IS NOT NULL AND p_decision NOT IN ('zero_missing', 'excused') THEN RAISE EXCEPTION 'status_invalid' USING ERRCODE = '23514'; END IF;
  IF p_decision IS NOT NULL AND (v_reason IS NULL OR length(v_reason) < 3) THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  -- The reason journaled with the mark's change, as the re-correction's is (BR-09).
  IF v_reason IS NOT NULL THEN PERFORM set_config('app.reason', v_reason, true); END IF;
  IF NOT EXISTS (SELECT 1 FROM school.activity_audience x WHERE x.tenant_id = t AND x.activity_id = a.id AND x.student_id = p_student) THEN
    RAISE EXCEPTION 'lms_audience_not_member' USING ERRCODE = '23514';
  END IF;
  IF p_decision = 'zero_missing' THEN
    IF now() < school.pupil_deadline(t, a.id, p_student) THEN RAISE EXCEPTION 'lms_deadline_not_passed' USING ERRCODE = '23514'; END IF;
    IF EXISTS (SELECT 1 FROM school.quiz_attempt x WHERE x.tenant_id = t AND x.activity_id = a.id AND x.student_id = p_student)
       OR EXISTS (SELECT 1 FROM school.homework_submission x WHERE x.tenant_id = t AND x.activity_id = a.id AND x.student_id = p_student) THEN
      RAISE EXCEPTION 'lms_work_submitted' USING ERRCODE = '23514';
    END IF;
  END IF;
  INSERT INTO school.activity_mark (tenant_id, activity_id, student_id, group_id, subject_id, status, max_points, coefficient, decision, decision_reason)
  SELECT t, a.id, p_student, a.group_id, a.subject_id, 'not_submitted', v.max_points, v.coefficient, p_decision, CASE WHEN p_decision IS NOT NULL THEN v_reason END
  FROM school.activity_version v WHERE v.tenant_id = t AND v.activity_id = a.id AND v.version = a.published_version
  ON CONFLICT (tenant_id, activity_id, student_id) DO UPDATE SET decision = EXCLUDED.decision, decision_reason = EXCLUDED.decision_reason;
  PERFORM school.refresh_mark(t, a.id, p_student);
END $$;
