-- Lot 2, a pupil's history on their sheet (SRS 1.2, FR-ELV-01: « consulter la fiche d'un élève avec
-- son historique »). What happened to the record itself is the journal's, which only its readers
-- read (FR-AUD-01): whoever reads the pupil reads here their record's own entries, by when, what,
-- which fields (never their values, masked anyway, BR-17), the state reached and its reason, and
-- who. The enrolments, groups, guardians and decisions come from their own tables, as the reader
-- may see them.

CREATE FUNCTION school.student_history(p_student uuid)
RETURNS TABLE (occurred_at timestamptz, action text, fields text[], status text, reason text, actor_id bigint)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE t uuid := school.allowed_tenant(); site uuid;
BEGIN
  SELECT s.site_id INTO site FROM school.student s WHERE s.tenant_id = t AND s.id = p_student;
  -- As the pupils' policy reads them (029): the whole school, the grant's sites, or a scope that reaches the pupil.
  IF t IS NULL OR site IS NULL OR NOT (school.can_everywhere('student.record.read') OR site = ANY (school.site_scope_ids('student.record.read', 'site'))
      OR p_student = ANY (school.core_ids('student.record.read', 'student'))) THEN
    RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501';
  END IF;
  RETURN QUERY SELECT a.occurred_at, a.action,
    ARRAY(SELECT f FROM jsonb_array_elements_text(a.changed_fields) f
          WHERE f NOT IN ('id', 'tenant_id', 'version', 'created_at', 'created_by', 'updated_at', 'updated_by', 'name_key') ORDER BY f),
    CASE WHEN a.action = 'INSERT' OR a.changed_fields ? 'status' THEN coalesce(a.after ->> 'status', 'active') END, a.reason, a.actor_id
  FROM school.audit_log a WHERE a.tenant_id = t AND a.object_type = 'student' AND a.object_id = p_student
  ORDER BY a.occurred_at DESC, a.id LIMIT 200;
END $$;
