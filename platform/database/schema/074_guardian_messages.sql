-- Lot 4, the guardians told of an absence alert (SRS 1.2, FR-PRE-01: the guardians are notified
-- only when the portal or the contacts are turned on), as decided on 17 October 2026: by email when
-- the school turns it on (`attendance.notify_guardians_email`, off by default); by WhatsApp next.
-- Only a minor's guardians, the primary contacts on the pupil's record: an adult learner's alert
-- stays within the school. The message gives the pupil's first name, the absences and their window,
-- and asks the family to contact the school; nothing more (BR-19). Each message is kept with its
-- state, so that the school sees a family was told, or why not.

INSERT INTO platform.setting_definition (key, value_type, default_value, min_value, max_value, permission, labels) VALUES
  ('attendance.notify_guardians_email', 'boolean', 'false', NULL, NULL, 'tenant.settings.manage',
   '{"fr": "Prévenir par e-mail les responsables d''un élève mineur qui atteint le seuil d''absences", "ar": "إعلام أولياء التلميذ القاصر الذي يبلغ عتبة الغيابات عبر البريد الإلكتروني"}');

-- A message to a guardian about an alert, on one channel: queued with the alert, sent by the worker,
-- tried five times at most; kept once sent, with when.
CREATE TABLE school.guardian_message (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL REFERENCES platform.tenant(id),
  alert_id uuid NOT NULL,
  guardian_id uuid NOT NULL,
  channel text NOT NULL CHECK (channel IN ('email', 'whatsapp')),
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sending', 'sent', 'failed')),
  attempts smallint NOT NULL DEFAULT 0,
  next_attempt_at timestamptz NOT NULL DEFAULT now(),
  locked_until timestamptz,
  last_error text CHECK (length(last_error) <= 500),
  sent_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, alert_id, guardian_id, channel),
  FOREIGN KEY (tenant_id, alert_id) REFERENCES school.absence_alert (tenant_id, id),
  FOREIGN KEY (tenant_id, guardian_id) REFERENCES school.guardian (tenant_id, id)
);
CREATE INDEX guardian_message_due ON school.guardian_message (channel, next_attempt_at) WHERE status IN ('pending', 'sending');
CREATE INDEX guardian_message_alert ON school.guardian_message (tenant_id, alert_id);
ALTER TABLE school.guardian_message ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.guardian_message FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.guardian_message USING (tenant_id = (SELECT school.allowed_tenant()));
-- Read with the alert it tells of, by whoever reads that alert.
CREATE POLICY resource_reader ON school.guardian_message AS RESTRICTIVE FOR SELECT TO school_runtime USING (
  alert_id IN (SELECT a.id FROM school.absence_alert a));

-- An alert raised for a minor: a message to each of their primary contacts with an address, on each
-- channel the school turned on. With its owner's rights: the queue is the database's.
CREATE FUNCTION school.tell_guardians() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
DECLARE majority integer;
BEGIN
  IF NOT coalesce((school.setting_value('attendance.notify_guardians_email') #>> '{}')::boolean, false) THEN RETURN NULL; END IF;
  majority := (school.setting_value('student.majority_age') #>> '{}')::integer;
  INSERT INTO school.guardian_message (tenant_id, alert_id, guardian_id, channel)
  SELECT NEW.tenant_id, NEW.id, g.id, 'email' FROM school.student s
  JOIN school.student_guardian l ON l.tenant_id = s.tenant_id AND l.student_id = s.id AND l.is_primary_contact AND l.archived_at IS NULL
  JOIN school.guardian g ON g.tenant_id = l.tenant_id AND g.id = l.guardian_id AND g.archived_at IS NULL AND g.email IS NOT NULL
  WHERE s.tenant_id = NEW.tenant_id AND s.id = NEW.student_id AND s.birth_date > NEW.to_day - make_interval(years => majority);
  RETURN NULL;
END $$;
CREATE TRIGGER tell_guardians AFTER INSERT ON school.absence_alert FOR EACH ROW EXECUTE FUNCTION school.tell_guardians();

-- The worker's side, across schools, as the email outbox's (SQL 010): the messages due on a channel
-- claimed without blocking another worker, with what the message says. One whose guardian has no
-- address any more fails at once, never sent.
CREATE FUNCTION platform.claim_guardian_messages(p_channel text, p_limit integer, p_lease_seconds integer)
RETURNS TABLE (id uuid, email text, phone text, first_name_lat text, first_name_ar text, absences integer, from_day date, to_day date,
  school text, school_phone text, locale text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  UPDATE school.guardian_message m SET status = 'failed', last_error = 'guardian_address_missing', locked_until = NULL
  FROM school.guardian g WHERE g.tenant_id = m.tenant_id AND g.id = m.guardian_id AND m.channel = p_channel AND m.status = 'pending'
    AND (g.archived_at IS NOT NULL OR (p_channel = 'email' AND g.email IS NULL) OR (p_channel = 'whatsapp' AND g.phone IS NULL));
  RETURN QUERY
  WITH picked AS (
    SELECT m.id FROM school.guardian_message m
    WHERE m.channel = p_channel AND ((m.status = 'pending' AND m.next_attempt_at <= now()) OR (m.status = 'sending' AND m.locked_until < now()))
    ORDER BY m.next_attempt_at LIMIT p_limit FOR UPDATE SKIP LOCKED),
  claimed AS (
    UPDATE school.guardian_message m SET status = 'sending', locked_until = now() + make_interval(secs => p_lease_seconds), attempts = m.attempts + 1
    FROM picked WHERE m.id = picked.id RETURNING m.id, m.tenant_id, m.alert_id, m.guardian_id)
  SELECT c.id, g.email, g.phone, s.first_name_lat, s.first_name_ar, a.absences, a.from_day, a.to_day, t.name,
    (SELECT v.value #>> '{}' FROM school.setting v WHERE v.tenant_id = c.tenant_id AND v.key = 'identity.phone' AND v.archived_at IS NULL),
    t.default_locale
  FROM claimed c
  JOIN school.guardian g ON g.tenant_id = c.tenant_id AND g.id = c.guardian_id
  JOIN school.absence_alert a ON a.tenant_id = c.tenant_id AND a.id = c.alert_id
  JOIN school.student s ON s.tenant_id = a.tenant_id AND s.id = a.student_id
  JOIN platform.tenant t ON t.id = c.tenant_id;
END $$;

-- Sent, or failed: tried again later, at most five times.
CREATE FUNCTION platform.finish_guardian_message(p_id uuid, p_error text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF p_error IS NULL THEN
    UPDATE school.guardian_message SET status = 'sent', sent_at = now(), locked_until = NULL, last_error = NULL WHERE id = p_id AND status = 'sending';
    RETURN;
  END IF;
  UPDATE school.guardian_message SET last_error = left(p_error, 500), locked_until = NULL,
    status = CASE WHEN attempts >= 5 THEN 'failed' ELSE 'pending' END,
    next_attempt_at = now() + make_interval(mins => (2 ^ attempts)::integer)
  WHERE id = p_id AND status = 'sending';
END $$;
