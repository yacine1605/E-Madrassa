-- Lot 4, the guardians told by WhatsApp (SRS 1.2, FR-PRE-01), as decided on 17 October 2026: when
-- the school turns it on (`attendance.notify_guardians_whatsapp`, off by default) and its WhatsApp
-- Business account is set up (DEPLOY.md), a minor's alert also queues a message to each primary
-- contact with a phone, sent as the school's approved template; the same words as the email.

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('attendance.notify_guardians_whatsapp', 'boolean', 'false', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Prévenir par WhatsApp les responsables d''un élève mineur qui atteint le seuil d''absences", "ar": "إعلام أولياء التلميذ القاصر الذي يبلغ عتبة الغيابات عبر واتساب"}');

-- A minor's alert: a message to each primary contact with an address on each channel the school
-- turned on, email (SQL 074) and WhatsApp.
CREATE OR REPLACE FUNCTION school.tell_guardians() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE majority integer;
BEGIN
  majority := (school.setting_value('student.majority_age') #>> '{}')::integer;
  INSERT INTO school.guardian_message (tenant_id, alert_id, guardian_id, channel)
  SELECT NEW.tenant_id, NEW.id, g.id, c.channel FROM school.student s
  JOIN school.student_guardian l ON l.tenant_id = s.tenant_id AND l.student_id = s.id AND l.is_primary_contact AND l.archived_at IS NULL
  JOIN school.guardian g ON g.tenant_id = l.tenant_id AND g.id = l.guardian_id AND g.archived_at IS NULL
  CROSS JOIN (VALUES ('email'), ('whatsapp')) c (channel)
  WHERE s.tenant_id = NEW.tenant_id AND s.id = NEW.student_id AND s.birth_date > NEW.to_day - make_interval(years => majority)
    AND coalesce((school.setting_value('attendance.notify_guardians_' || c.channel) #>> '{}')::boolean, false)
    AND CASE c.channel WHEN 'email' THEN g.email IS NOT NULL ELSE g.phone IS NOT NULL END;
  RETURN NULL;
END $$;

-- A message that cannot be sent, whatever the attempts: a phone WhatsApp cannot take, or no account
-- set up. Failed at once, with why.
CREATE FUNCTION platform.fail_guardian_message(p_id uuid, p_error text) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
  UPDATE school.guardian_message SET status = 'failed', last_error = left(p_error, 500), locked_until = NULL
  WHERE id = p_id AND status = 'sending' $$;
