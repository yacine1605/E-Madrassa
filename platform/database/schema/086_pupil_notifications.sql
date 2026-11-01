-- Lot 5, what tells the pupil (FR-LMS-02, FR-LMS-03; SPECIFICATION_LOT5_PEDAGOGIE.md §5, §6, §8):
-- an activity published for them (at its first publication, or once they are added to its audience),
-- and a link of theirs the teacher could not open, with the time it may be replaced until (« une
-- demande de remplacement datée »). The message names the activity, never a mark nor the teacher's
-- reason, which the pupil reads on the activity. A pupil without an account in use is told nothing.

INSERT INTO platform.notification_event (code, labels, email_default) VALUES
  ('lms.activity_published', '{"fr": "Une activité vous a été donnée", "ar": "أُسند إليك نشاط"}', false),
  ('lms.submission_flagged', '{"fr": "Un lien remis ne s’ouvre pas : remplacez-le", "ar": "رابط مسلَّم يتعذر فتحه: استبدله"}', true);

-- A pupil told of one of their activities, the data given, by e-mail too as they chose. Also from
-- the background run, outside any school's request.
CREATE FUNCTION school.tell_pupil(p_tenant uuid, p_student uuid, p_event text, p_data jsonb) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE recipient bigint; note uuid;
BEGIN
  SELECT s.user_id INTO recipient FROM school.student s JOIN school.membership m ON m.tenant_id = s.tenant_id AND m.user_id = s.user_id AND m.status = 'active'
  WHERE s.tenant_id = p_tenant AND s.id = p_student;
  IF recipient IS NULL THEN RETURN; END IF;
  INSERT INTO school.notification (tenant_id, user_id, event, data) VALUES (p_tenant, recipient, p_event, p_data) RETURNING id INTO note;
  INSERT INTO school.outbox (tenant_id, notification_id, user_id, event)
  SELECT p_tenant, note, recipient, e.code FROM platform.notification_event e JOIN public.users u ON u.id = recipient AND u.email IS NOT NULL
  LEFT JOIN school.notification_preference p ON p.tenant_id = p_tenant AND p.user_id = recipient AND p.event = e.code
  WHERE e.code = p_event AND coalesce(p.email, e.email_default);
END $$;

CREATE FUNCTION school.tell_activity_published() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  PERFORM school.tell_pupil(NEW.tenant_id, NEW.student_id, 'lms.activity_published', jsonb_build_object('activity_id', NEW.activity_id));
  RETURN NULL;
END $$;
CREATE TRIGGER told AFTER INSERT ON school.activity_audience FOR EACH ROW EXECUTE FUNCTION school.tell_activity_published();

CREATE FUNCTION school.tell_submission_flagged() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  PERFORM school.tell_pupil(NEW.tenant_id, NEW.student_id, 'lms.submission_flagged',
    jsonb_build_object('activity_id', NEW.activity_id, 'replace_until', NEW.replace_until));
  RETURN NULL;
END $$;
CREATE TRIGGER told AFTER UPDATE OF status ON school.homework_submission FOR EACH ROW
  WHEN (NEW.status = 'inaccessible' AND OLD.status <> 'inaccessible') EXECUTE FUNCTION school.tell_submission_flagged();
