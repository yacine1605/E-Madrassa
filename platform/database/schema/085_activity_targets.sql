-- Lot 5, the activities' screens (FR-LMS-02, FR-LMS-03, FR-LMS-04): what the caller may write an
-- activity for today, each group still open with a subject its programme teaches today: the ones
-- they teach, or every one for the pedagogy manager. Named whatever the caller may read of the
-- groups (a teacher limited to their own may not read them through the groups' policy).
CREATE FUNCTION school.activity_targets() RETURNS TABLE (group_id uuid, group_code text, group_labels jsonb, subject_id uuid, subject_code text, subject_labels jsonb)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT g.id, g.code, g.labels, s.id, s.code, s.labels
  FROM school.study_group g
  JOIN school.program_subject ps ON ps.tenant_id = g.tenant_id AND ps.program_id = g.program_id AND ps.archived_at IS NULL
    AND ps.valid_from <= current_date AND (ps.valid_to IS NULL OR ps.valid_to >= current_date)
  JOIN school.subject s ON s.tenant_id = ps.tenant_id AND s.id = ps.subject_id
  WHERE g.tenant_id = school.allowed_tenant() AND g.archived_at IS NULL AND g.status <> 'closed'
    AND (school.can_everywhere('lms.activity.manage') OR g.id::text || ':' || s.id::text = ANY (school.taught_keys()))
  ORDER BY g.code, s.code $$;
