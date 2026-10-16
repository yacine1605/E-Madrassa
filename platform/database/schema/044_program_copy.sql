-- Lot 2, a programme copied to a later year (SRS 1.2, FR-PRG-02, its edge case; FR-PRG-01): the
-- same level, names, duration, price and payment terms, as a draft to review, with its subjects
-- and their volumes as they stand at the end of the source year, each modifiable afterwards. The
-- copy keeps the programme it came from; the same copy asked again answers the first one.

ALTER TABLE school.program ADD COLUMN copied_from uuid, ADD FOREIGN KEY (tenant_id, copied_from) REFERENCES school.program (tenant_id, id);
CREATE UNIQUE INDEX program_copied_once ON school.program (tenant_id, copied_from, year_id) WHERE copied_from IS NOT NULL;

-- With the caller's rights: `program.program.manage` writes the programme, `program.subject.manage`
-- its subjects (their triggers), in the target year still open (FR-REF-05). A target year must
-- begin after the source year; its code taken by another programme is refused.
CREATE FUNCTION school.copy_program(p_program uuid, p_year uuid) RETURNS uuid
LANGUAGE plpgsql AS $$
DECLARE p school.program; source_year school.academic_year; target_year school.academic_year; copy uuid;
BEGIN
  SELECT * INTO p FROM school.program WHERE tenant_id = school.tenant() AND id = p_program;
  IF p.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  SELECT * INTO source_year FROM school.academic_year WHERE tenant_id = p.tenant_id AND id = p.year_id;
  SELECT * INTO target_year FROM school.academic_year WHERE tenant_id = p.tenant_id AND id = p_year;
  IF target_year.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF target_year.start_date <= source_year.start_date THEN RAISE EXCEPTION 'program_copy_year' USING ERRCODE = '23514'; END IF;
  -- One copy at a time of a programme: the second request finds the first's.
  PERFORM pg_advisory_xact_lock(hashtextextended('program_copy:' || p.id::text, 44));
  SELECT id INTO copy FROM school.program WHERE tenant_id = p.tenant_id AND copied_from = p.id AND year_id = p_year;
  IF copy IS NOT NULL THEN RETURN copy; END IF;
  IF EXISTS (SELECT 1 FROM school.program WHERE tenant_id = p.tenant_id AND year_id = p_year AND code = p.code) THEN
    RAISE EXCEPTION 'program_code_taken' USING ERRCODE = '23514';
  END IF;
  INSERT INTO school.program (tenant_id, year_id, node_id, code, labels, description, duration_value, duration_unit, base_price, payment_terms,
    status, copied_from)
  VALUES (p.tenant_id, p_year, p.node_id, p.code, p.labels, p.description, p.duration_value, p.duration_unit, p.base_price, p.payment_terms,
    'draft', p.id) RETURNING id INTO copy;
  -- Each subject's line in force at the source year's end (the latest one if several), from the target year's first day.
  INSERT INTO school.program_subject (tenant_id, program_id, subject_id, total_hours, weekly_hours, session_count, session_duration_minutes,
    coefficient, valid_from)
  SELECT DISTINCT ON (s.subject_id) p.tenant_id, copy, s.subject_id, s.total_hours, s.weekly_hours, s.session_count, s.session_duration_minutes,
    s.coefficient, target_year.start_date
  FROM school.program_subject s JOIN school.subject c ON c.tenant_id = s.tenant_id AND c.id = s.subject_id AND c.archived_at IS NULL
  WHERE s.tenant_id = p.tenant_id AND s.program_id = p.id AND s.archived_at IS NULL AND s.valid_from <= source_year.end_date
    AND (s.valid_to IS NULL OR s.valid_to >= source_year.end_date)
  ORDER BY s.subject_id, s.valid_from DESC;
  RETURN copy;
END $$;
