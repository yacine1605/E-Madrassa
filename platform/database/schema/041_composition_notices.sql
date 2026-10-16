-- Lot 2, a group's composition (SRS 1.2, FR-GRP-02, step 4; annex B, `group.membership.changed`):
-- a pupil joining or leaving a group notifies its lead teacher, through their account, if they
-- have one, still read the groups, and did not make the change themselves. The notification names
-- the group only (BR-19); the teacher opens it with their own rights. The waiting list of a full
-- group (OD-14, SHOULD) waits for its decision.

INSERT INTO platform.notification_event (code, labels, email_default) VALUES
  ('group.membership_changed', '{"fr": "La composition d’un de vos groupes a changé", "ar": "تغيّرت تشكيلة أحد أفواجك"}', false);

-- After the place is taken (`place`) or given back: with its owner's rights, since the writer may
-- not read the staff's records nor the teacher's roles.
CREATE FUNCTION school.notify_composition() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE teacher bigint; change text;
BEGIN
  IF school.tenant() IS DISTINCT FROM NEW.tenant_id THEN RETURN NULL; END IF;
  IF TG_OP = 'INSERT' THEN change := 'joined';
  ELSIF NEW.valid_to IS NOT NULL AND OLD.valid_to IS NULL THEN change := 'left';
  ELSE RETURN NULL;
  END IF;
  SELECT s.user_id INTO teacher FROM school.study_group g JOIN school.staff s ON s.tenant_id = g.tenant_id AND s.id = g.lead_teacher_id
  WHERE g.tenant_id = NEW.tenant_id AND g.id = NEW.group_id AND s.status <> 'left' AND s.archived_at IS NULL;
  IF teacher IS NULL OR teacher IS NOT DISTINCT FROM school.actor() THEN RETURN NULL; END IF;
  IF NOT EXISTS (SELECT 1 FROM school.effective_permissions(teacher, NEW.tenant_id) e WHERE e.permission = 'group.group.read') THEN RETURN NULL; END IF;
  PERFORM school.notify('group.membership_changed', ARRAY[teacher], jsonb_build_object('group_id', NEW.group_id, 'change', change));
  RETURN NULL;
END $$;
CREATE TRIGGER notify_composition AFTER INSERT OR UPDATE OF valid_to ON school.group_membership
  FOR EACH ROW EXECUTE FUNCTION school.notify_composition();
