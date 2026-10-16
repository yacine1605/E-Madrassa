-- Lot 3, a payment's supporting document (SRS 1.2, FR-FIN-02 « justificatif optionnel »; FR-FIC-01
-- AC2): one file of the category `finance_attachment` made for the payment, added or replaced by
-- its author while it waits for its validation, never changed afterwards (TST-11). It is read with
-- the payment, by whoever reads it, never by a teacher. A replaced document is archived, not
-- deleted: it stays a financial record (BR-15).

-- Whoever collects a payment on delegation adds its document too.
UPDATE platform.file_category SET write_any = write_any || '{finance.payment.create.delegated}' WHERE code = 'finance_attachment';

ALTER TABLE school.payment ADD COLUMN attachment_file_id uuid,
  ADD FOREIGN KEY (tenant_id, attachment_file_id) REFERENCES school.file_object (tenant_id, id);

-- A document: a file of `finance_attachment` made for this payment, in use; set or changed only
-- while the payment waits (the payment's guard checks it is its author), never once decided; a
-- payment is recorded without one, which comes next.
CREATE FUNCTION school.check_payment_attachment() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.attachment_file_id IS NOT NULL THEN RAISE EXCEPTION 'payment_attachment_invalid' USING ERRCODE = '23514'; END IF;
    RETURN NEW;
  END IF;
  IF NEW.attachment_file_id IS NOT DISTINCT FROM OLD.attachment_file_id THEN RETURN NEW; END IF;
  IF OLD.status <> 'pending_validation' OR NEW.status <> 'pending_validation' THEN RAISE EXCEPTION 'payment_immutable' USING ERRCODE = '23514'; END IF;
  IF NEW.attachment_file_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM school.file_object f WHERE f.tenant_id = NEW.tenant_id AND f.id = NEW.attachment_file_id
       AND f.category = 'finance_attachment' AND f.owner_type = 'payment' AND f.owner_id = NEW.id AND f.archived_at IS NULL AND f.purged_at IS NULL) THEN
    RAISE EXCEPTION 'payment_attachment_invalid' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
-- Before the payment's own guard (« attachment » sorts first), which then checks its author.
CREATE TRIGGER attachment BEFORE INSERT OR UPDATE ON school.payment FOR EACH ROW EXECUTE FUNCTION school.check_payment_attachment();

-- The document a payment no longer shows is archived in the same transaction, its bytes kept.
CREATE FUNCTION school.retire_payment_attachment() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
BEGIN
  UPDATE school.file_object SET archived_at = coalesce(archived_at, now())
  WHERE tenant_id = OLD.tenant_id AND id = OLD.attachment_file_id AND archived_at IS NULL;
  RETURN NULL;
END $$;
CREATE TRIGGER retire_attachment AFTER UPDATE OF attachment_file_id ON school.payment
  FOR EACH ROW WHEN (OLD.attachment_file_id IS NOT NULL AND OLD.attachment_file_id IS DISTINCT FROM NEW.attachment_file_id)
  EXECUTE FUNCTION school.retire_payment_attachment();

-- AC2: a payment's document follows its payment: seen, added or changed by whoever sees the
-- payment (its author, a cashier on delegation included, or the finance of the pupil's site), by
-- nobody else, whatever the category's permissions say; no other file is owned by a payment.
-- Replaces 026's policies, which they keep.
ALTER POLICY reader ON school.file_object USING (tenant_id = (SELECT school.tenant())
  AND (created_by = (SELECT school.actor()) OR category <> ALL ((SELECT school.file_categories('author'))::text[]))
  AND (category <> 'student_photo' OR EXISTS (SELECT 1 FROM school.student s WHERE s.tenant_id = file_object.tenant_id AND s.id = file_object.owner_id))
  AND CASE WHEN owner_type = 'payment' THEN category = 'finance_attachment'
             AND EXISTS (SELECT 1 FROM school.payment p WHERE p.tenant_id = file_object.tenant_id AND p.id = file_object.owner_id)
           ELSE category = ANY ((SELECT school.file_categories('read'))::text[]) END);
ALTER POLICY writer ON school.file_object WITH CHECK (tenant_id = (SELECT school.tenant())
  AND category = ANY ((SELECT school.file_categories('write'))::text[])
  AND (category <> 'student_photo' OR (owner_type = 'student'
    AND EXISTS (SELECT 1 FROM school.student s WHERE s.tenant_id = file_object.tenant_id AND s.id = file_object.owner_id)))
  AND (owner_type IS DISTINCT FROM 'payment' OR (category = 'finance_attachment'
    AND EXISTS (SELECT 1 FROM school.payment p WHERE p.tenant_id = file_object.tenant_id AND p.id = file_object.owner_id))));
ALTER POLICY updater ON school.file_object USING (tenant_id = (SELECT school.tenant())
  AND category = ANY ((SELECT school.file_categories('write'))::text[])
  AND (created_by = (SELECT school.actor()) OR category <> ALL ((SELECT school.file_categories('author'))::text[]))
  AND (category <> 'student_photo' OR EXISTS (SELECT 1 FROM school.student s WHERE s.tenant_id = file_object.tenant_id AND s.id = file_object.owner_id))
  AND (owner_type IS DISTINCT FROM 'payment' OR EXISTS (SELECT 1 FROM school.payment p WHERE p.tenant_id = file_object.tenant_id AND p.id = file_object.owner_id)));
