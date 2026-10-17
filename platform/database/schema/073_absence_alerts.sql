-- Lot 4, absence thresholds (SRS 1.2, FR-PRE-01): a pupil absent without excuse N times over the
-- last D days, as the school sets them (3 in 30 by default, 0 for no threshold), raises an alert
-- when the mark that reaches it is saved, a mark corrected to an absence too. Late arrivals and
-- excused absences do not count, nor a session cancelled since. A pupil is alerted again only once
-- a further full window has passed. The staff
-- who follow the group are notified: whoever corrects its rolls for the whole school or on its
-- site, and its lead teacher. The guardians are told by their contacts, as the school chooses, in
-- the increments that follow.

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('attendance.absence_threshold', 'integer', '3', 0, 50, 'tenant.settings.manage',
   '{"fr": "Seuil d''absences non justifiées d''un élève (0 : aucun)", "ar": "عتبة الغيابات غير المبرَّرة للتلميذ (0: بلا عتبة)"}'),
  ('attendance.absence_window_days', 'integer', '30', 1, 366, 'tenant.settings.manage',
   '{"fr": "Période du seuil d''absences, en jours", "ar": "مدة عتبة الغيابات بالأيام"}');

INSERT INTO platform.notification_event (code, labels, email_default) VALUES
  ('attendance.absence_threshold', '{"fr": "Un élève a atteint le seuil d''absences", "ar": "بلغ تلميذ عتبة الغيابات"}', false);

-- An alert: the pupil, the group of the mark that reached the threshold, the absences counted over
-- the window ending on the day it was saved, and the threshold then. Written by the database only;
-- never changed.
CREATE TABLE school.absence_alert (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  student_id uuid NOT NULL,
  group_id uuid NOT NULL,
  attendance_id uuid NOT NULL,
  absences integer NOT NULL CHECK (absences > 0),
  threshold integer NOT NULL CHECK (threshold > 0),
  from_day date NOT NULL,
  to_day date NOT NULL CHECK (to_day >= from_day),
  created_at timestamptz NOT NULL DEFAULT now(),
  created_by bigint REFERENCES public.users(id),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, student_id) REFERENCES school.student (tenant_id, id),
  FOREIGN KEY (tenant_id, group_id) REFERENCES school.study_group (tenant_id, id),
  FOREIGN KEY (tenant_id, attendance_id) REFERENCES school.student_attendance (tenant_id, id)
);
CREATE INDEX absence_alert_student ON school.absence_alert (tenant_id, student_id, to_day);
CREATE INDEX absence_alert_recent ON school.absence_alert (tenant_id, created_at DESC);
ALTER TABLE school.absence_alert ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.absence_alert FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.absence_alert USING (tenant_id = (SELECT school.allowed_tenant()));
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.absence_alert FOR EACH ROW EXECUTE FUNCTION school.immutable();
-- Who reads an alert: whoever corrects the group's rolls for the whole school or on its site, and its lead teacher.
CREATE POLICY resource_reader ON school.absence_alert AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  (SELECT school.can_everywhere('attendance.student.entry.update'))
  OR group_id IN (SELECT unnest((SELECT school.site_scope_ids('attendance.student.entry.update', 'study_group'))))
  OR EXISTS (SELECT 1 FROM school.study_group g WHERE g.tenant_id = absence_alert.tenant_id AND g.id = absence_alert.group_id
    AND school.is_own_staff(g.lead_teacher_id)));

-- The members told of a group's alert: those who correct its rolls for the whole school or on its
-- site, and its lead teacher when they have an account.
CREATE FUNCTION school.absence_watchers(p_tenant uuid, p_group uuid) RETURNS bigint[]
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  SELECT coalesce(array_agg(DISTINCT x.user_id), '{}') FROM (
    SELECT m.user_id FROM school.membership m
    JOIN school.study_group g ON g.tenant_id = m.tenant_id AND g.id = p_group
    WHERE m.tenant_id = p_tenant AND m.status = 'active' AND EXISTS (
      SELECT 1 FROM school.effective_permissions(m.user_id, p_tenant) e WHERE e.permission = 'attendance.student.entry.update'
        AND (e.scope_type = 'tenant' OR (e.scope_type = 'site' AND g.site_id = ANY (e.scope_ids))))
    UNION
    SELECT st.user_id FROM school.study_group g JOIN school.staff st ON st.tenant_id = g.tenant_id AND st.id = g.lead_teacher_id
    WHERE g.tenant_id = p_tenant AND g.id = p_group AND st.user_id IS NOT NULL) x $$;

-- A mark `absent` saved, new or changed to it: the pupil's absences over the window ending today;
-- at the threshold, unless alerted within the window, an alert and its notification.
-- With its owner's rights: the alerts are the database's. One pupil's marks are counted one at a
-- time, so that two rolls saved together raise one alert.
CREATE FUNCTION school.check_absence_threshold() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE threshold integer; window_days integer; day date; absences integer; alert uuid;
BEGIN
  IF school.tenant() IS DISTINCT FROM NEW.tenant_id OR NEW.status <> 'absent' THEN RETURN NULL; END IF;
  IF TG_OP = 'UPDATE' AND OLD.status = 'absent' THEN RETURN NULL; END IF;
  threshold := coalesce((school.setting_value('attendance.absence_threshold') #>> '{}')::integer, 0);
  IF threshold = 0 THEN RETURN NULL; END IF;
  window_days := (school.setting_value('attendance.absence_window_days') #>> '{}')::integer;
  PERFORM pg_advisory_xact_lock(hashtextextended('absence_alert:' || NEW.student_id::text, 0));
  day := school.local_day(now());
  IF EXISTS (SELECT 1 FROM school.absence_alert a WHERE a.tenant_id = NEW.tenant_id AND a.student_id = NEW.student_id
             AND a.to_day > day - window_days) THEN
    RETURN NULL;
  END IF;
  SELECT count(*) INTO absences FROM school.student_attendance s
  JOIN school.class_session c ON c.tenant_id = s.tenant_id AND c.id = s.session_id
  WHERE s.tenant_id = NEW.tenant_id AND s.student_id = NEW.student_id AND s.archived_at IS NULL AND s.status = 'absent'
    AND c.status <> 'cancelled' AND school.local_day(c.starts_at) BETWEEN day - window_days + 1 AND day;
  IF absences < threshold THEN RETURN NULL; END IF;
  INSERT INTO school.absence_alert (tenant_id, student_id, group_id, attendance_id, absences, threshold, from_day, to_day, created_by)
  VALUES (NEW.tenant_id, NEW.student_id, NEW.group_id, NEW.id, absences, threshold, day - window_days + 1, day, school.actor())
  RETURNING id INTO alert;
  PERFORM school.notify('attendance.absence_threshold', school.absence_watchers(NEW.tenant_id, NEW.group_id), jsonb_build_object('alert', alert));
  RETURN NULL;
END $$;
CREATE TRIGGER absence_threshold AFTER INSERT OR UPDATE OF status ON school.student_attendance
  FOR EACH ROW EXECUTE FUNCTION school.check_absence_threshold();

