-- Lot 2, the annual progression (SRS 1.2, FR-INS-05, ND-03): at the end of a year, for each
-- validated enrolment, a decision: promoted, repeated, left or completed, with its reason. A
-- promotion or a repetition enrols the pupil, pending, in a class of a later year (FR-INS-01),
-- with the day its first instalment falls due, for lot 3's invoice. The source enrolment, its
-- history and groups stay as they are (AC3, BR-03); one decision per enrolment, the same one
-- again making nothing (AC2); a batch is the request's, all or nothing (AC1).

CREATE TABLE school.progression (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  enrollment_id uuid NOT NULL,
  decision text NOT NULL CHECK (decision IN ('promoted', 'repeated', 'left', 'completed')),
  reason text NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
  target_group_id uuid,
  due_date date,
  target_enrollment_id uuid,
  created_at timestamptz NOT NULL,
  created_by bigint REFERENCES public.users(id),
  updated_at timestamptz NOT NULL,
  updated_by bigint REFERENCES public.users(id),
  version integer NOT NULL DEFAULT 1,
  CHECK ((decision IN ('promoted', 'repeated')) = (target_group_id IS NOT NULL)),
  CHECK ((target_group_id IS NULL) = (due_date IS NULL) AND (target_group_id IS NULL) = (target_enrollment_id IS NULL)),
  UNIQUE (tenant_id, id),
  UNIQUE (tenant_id, enrollment_id),
  UNIQUE (tenant_id, target_enrollment_id),
  FOREIGN KEY (tenant_id, enrollment_id) REFERENCES school.enrollment (tenant_id, id),
  FOREIGN KEY (tenant_id, target_group_id) REFERENCES school.study_group (tenant_id, id),
  FOREIGN KEY (tenant_id, target_enrollment_id) REFERENCES school.enrollment (tenant_id, id)
);
CREATE INDEX progression_target_group ON school.progression (tenant_id, target_group_id) WHERE target_group_id IS NOT NULL;
CALL school.protect('school.progression');

-- Why a decision cannot be taken, or null: the source enrolment validated; for a promotion or a
-- repetition, a class or session of a later year, at the next level for a promotion, at the same
-- level for a repetition (AC4). With the caller's rights: what they cannot see is not found.
CREATE FUNCTION school.progression_refusal(p_enrollment uuid, p_decision text, p_group uuid) RETURNS text
LANGUAGE plpgsql STABLE AS $$
DECLARE e school.enrollment; source_year school.academic_year; g school.study_group; target_year school.academic_year; source_node uuid; target_node uuid;
BEGIN
  SELECT * INTO e FROM school.enrollment WHERE tenant_id = school.tenant() AND id = p_enrollment;
  IF e.id IS NULL THEN RETURN 'permission_denied'; END IF;
  IF e.status <> 'validated' THEN RETURN 'progression_not_validated'; END IF;
  IF p_decision NOT IN ('promoted', 'repeated') THEN RETURN CASE WHEN p_group IS NULL THEN NULL ELSE 'progression_target_invalid' END; END IF;
  IF p_group IS NULL THEN RETURN 'progression_target_required'; END IF;
  SELECT * INTO g FROM school.study_group WHERE tenant_id = e.tenant_id AND id = p_group;
  IF g.id IS NULL OR g.group_type NOT IN ('class', 'session') OR g.program_id IS NULL OR g.archived_at IS NOT NULL OR g.status = 'closed' THEN
    RETURN 'progression_target_invalid';
  END IF;
  SELECT * INTO source_year FROM school.academic_year WHERE tenant_id = e.tenant_id AND id = e.year_id;
  SELECT * INTO target_year FROM school.academic_year WHERE tenant_id = e.tenant_id AND id = g.year_id;
  IF target_year.start_date <= source_year.start_date THEN RETURN 'progression_same_year'; END IF;
  SELECT node_id INTO source_node FROM school.program WHERE tenant_id = e.tenant_id AND id = e.program_id;
  SELECT node_id INTO target_node FROM school.program WHERE tenant_id = e.tenant_id AND id = g.program_id;
  IF p_decision = 'repeated' AND target_node IS DISTINCT FROM source_node THEN RETURN 'progression_level_changed'; END IF;
  IF p_decision = 'promoted' AND target_node = source_node THEN RETURN 'progression_level_same'; END IF;
  RETURN NULL;
END $$;

-- Who decides: `enrollment.progression.decide` within a scope that reaches the source enrolment.
-- A decision is written once, by the decision's function, and never changes. Its target is an
-- enrolment of the same pupil in the target class's programme.
CREATE FUNCTION school.guard_progression() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE refusal text;
BEGIN
  IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'immutable_record' USING ERRCODE = '23514'; END IF;
  PERFORM school.require_core('enrollment.progression.decide', 'enrollment', NEW.enrollment_id);
  NEW.reason := nullif(btrim(NEW.reason), '');
  IF NEW.reason IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  refusal := school.progression_refusal(NEW.enrollment_id, NEW.decision, NEW.target_group_id);
  IF refusal = 'permission_denied' THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  IF refusal IS NOT NULL THEN RAISE EXCEPTION '%', refusal USING ERRCODE = '23514'; END IF;
  IF (NEW.target_group_id IS NULL) <> (NEW.due_date IS NULL) THEN RAISE EXCEPTION 'progression_target_required' USING ERRCODE = '23514'; END IF;
  IF NEW.target_enrollment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM school.enrollment t
      JOIN school.enrollment s ON s.tenant_id = t.tenant_id AND s.id = NEW.enrollment_id
      JOIN school.study_group g ON g.tenant_id = t.tenant_id AND g.id = NEW.target_group_id
      WHERE t.tenant_id = NEW.tenant_id AND t.id = NEW.target_enrollment_id AND t.student_id = s.student_id AND t.program_id = g.program_id) THEN
    RAISE EXCEPTION 'identity_immutable' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard BEFORE INSERT OR UPDATE OR DELETE ON school.progression FOR EACH ROW EXECUTE FUNCTION school.guard_progression();

-- FR-INS-05: one decision, with the caller's rights: a promotion or a repetition also enrols the
-- pupil (`enrollment.enrollment.create`) and assigns them to the target class
-- (`group.membership.manage`), from its first day if it has not begun. The same decision again
-- answers the first one (AC2); another one is refused. Within a request, a batch's decisions
-- stand or fall together (AC1): the target's capacity, a pupil already enrolled there.
CREATE FUNCTION school.decide_progression(p_enrollment uuid, p_decision text, p_reason text, p_group uuid, p_due date) RETURNS uuid
LANGUAGE plpgsql AS $$
DECLARE e school.enrollment; p school.progression; g school.study_group; refusal text; target uuid;
BEGIN
  SELECT * INTO e FROM school.enrollment WHERE tenant_id = school.tenant() AND id = p_enrollment;
  IF e.id IS NULL THEN RAISE EXCEPTION 'permission_denied' USING ERRCODE = '42501'; END IF;
  PERFORM school.require_core('enrollment.progression.decide', 'enrollment', e.id);
  -- One decision at a time for an enrolment: the second request finds the first's.
  PERFORM pg_advisory_xact_lock(hashtextextended('progression:' || e.id::text, 40));
  SELECT * INTO p FROM school.progression WHERE tenant_id = e.tenant_id AND enrollment_id = e.id;
  IF p.id IS NOT NULL THEN
    IF (p.decision, p.target_group_id, p.due_date) IS DISTINCT FROM (p_decision, p_group, p_due) THEN
      RAISE EXCEPTION 'progression_decided' USING ERRCODE = '23514';
    END IF;
    RETURN p.id;
  END IF;
  IF nullif(btrim(p_reason), '') IS NULL THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = '23514'; END IF;
  refusal := school.progression_refusal(e.id, p_decision, p_group);
  IF refusal IS NOT NULL THEN RAISE EXCEPTION '%', refusal USING ERRCODE = '23514'; END IF;
  IF p_group IS NOT NULL THEN
    IF p_due IS NULL THEN RAISE EXCEPTION 'progression_target_required' USING ERRCODE = '23514'; END IF;
    SELECT * INTO g FROM school.study_group WHERE tenant_id = e.tenant_id AND id = p_group;
    IF EXISTS (SELECT 1 FROM school.enrollment x WHERE x.tenant_id = e.tenant_id AND x.student_id = e.student_id AND x.program_id = g.program_id
               AND x.status <> 'cancelled') THEN
      RAISE EXCEPTION 'progression_target_enrolled' USING ERRCODE = '23514';
    END IF;
    target := school.enroll(e.student_id, g.program_id, gen_random_uuid());
    -- A class or session is its enrolment's main group (021).
    INSERT INTO school.group_membership (tenant_id, enrollment_id, group_id, valid_from)
    VALUES (e.tenant_id, target, g.id, greatest(current_date, g.valid_from));
  END IF;
  INSERT INTO school.progression (tenant_id, enrollment_id, decision, reason, target_group_id, due_date, target_enrollment_id)
  VALUES (e.tenant_id, e.id, p_decision, p_reason, p_group, p_due, target) RETURNING * INTO p;
  RETURN p.id;
END $$;

-- Read with the enrolments it decides about, as far as the reader sees them; written within the
-- deciding grant's scope.
CREATE POLICY resource_reader ON school.progression AS RESTRICTIVE FOR SELECT TO school_runtime
  USING (enrollment_id IN (SELECT e.id FROM school.enrollment e));
CREATE POLICY resource_creator ON school.progression AS RESTRICTIVE FOR INSERT TO school_runtime WITH CHECK (
  (SELECT school.can_everywhere('enrollment.progression.decide'))
  OR enrollment_id IN (SELECT unnest((SELECT school.core_ids('enrollment.progression.decide', 'enrollment')))));

-- The journal: in the pupils' domain, read with the enrolments; a scoped reader reads the
-- decisions of the enrolments they see. Replaces 038's.
INSERT INTO platform.audit_domain (object_type, domain, read_any) VALUES ('progression', 'pedagogy', '{enrollment.enrollment.read,audit.log.read}');
ALTER POLICY core_resource_reader ON school.audit_log USING (
  object_type NOT IN ('student', 'guardian', 'student_guardian', 'program', 'program_subject', 'study_group', 'enrollment', 'group_membership', 'staff',
    'qualification', 'availability', 'unavailability', 'staff_contract', 'staff_leave', 'lead', 'lead_child', 'lead_interaction', 'lead_reminder',
    'progression') OR
  (SELECT school.can_everywhere('audit.log.read')) OR
  (object_type = 'student' AND ((SELECT school.can_everywhere('student.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('student.record.read', 'student')))))) OR
  (object_type = 'guardian' AND ((SELECT school.can_everywhere('guardian.record.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('guardian.record.read', 'guardian')))))) OR
  (object_type = 'student_guardian' AND (SELECT school.can_everywhere('guardian.record.read'))) OR
  (object_type = 'program' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('program.program.read', 'program')))))) OR
  (object_type = 'program_subject' AND ((SELECT school.can_everywhere('program.program.read')) OR object_id IN (SELECT s.id FROM school.program_subject s))) OR
  (object_type = 'study_group' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'study_group')))))) OR
  (object_type = 'enrollment' AND ((SELECT school.can_everywhere('enrollment.enrollment.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('enrollment.enrollment.read', 'enrollment')))))) OR
  (object_type = 'group_membership' AND ((SELECT school.can_everywhere('group.group.read')) OR object_id IN (SELECT unnest((SELECT school.core_ids('group.group.read', 'group_membership')))))) OR
  (object_type = 'staff' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT s.id FROM school.staff s))) OR
  (object_type = 'qualification' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT q.id FROM school.qualification q))) OR
  (object_type = 'availability' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT a.id FROM school.availability a))) OR
  (object_type = 'unavailability' AND ((SELECT school.can_everywhere('staff.profile.read')) OR object_id IN (SELECT u.id FROM school.unavailability u))) OR
  (object_type = 'staff_contract' AND ((SELECT school.can_everywhere('staff.contract.read')) OR object_id IN (SELECT c.id FROM school.staff_contract c))) OR
  (object_type = 'staff_leave' AND ((SELECT school.can_everywhere('staff.leave.read')) OR object_id IN (SELECT l.id FROM school.staff_leave l))) OR
  (object_type = 'lead' AND ((SELECT school.can_everywhere('crm.lead.read')) OR object_id IN (SELECT l.id FROM school.lead l))) OR
  (object_type = 'lead_child' AND ((SELECT school.can_everywhere('crm.lead.read')) OR object_id IN (SELECT c.id FROM school.lead_child c))) OR
  (object_type = 'lead_interaction' AND ((SELECT school.can_everywhere('crm.lead.read')) OR object_id IN (SELECT i.id FROM school.lead_interaction i))) OR
  (object_type = 'lead_reminder' AND ((SELECT school.can_everywhere('crm.lead.read')) OR object_id IN (SELECT r.id FROM school.lead_reminder r))) OR
  (object_type = 'progression' AND ((SELECT school.can_everywhere('enrollment.enrollment.read')) OR object_id IN (SELECT p.id FROM school.progression p))));
