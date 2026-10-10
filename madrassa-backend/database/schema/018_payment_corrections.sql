-- FR-FIN-03 / BR-03: a payment is never edited. An error is corrected by a linked
-- counter-operation that cancels its effect on the balance. A refund stays what it
-- is: money handed back, which makes the amount due again.
CREATE TABLE school.payment_reversals (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL REFERENCES school.institutions(id),
 payment_id uuid NOT NULL,
 reason text NOT NULL CHECK(length(trim(reason))>=3),
 created_by bigint REFERENCES public.users(id),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,payment_id),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,payment_id) REFERENCES school.payments(tenant_id,id)
);
ALTER TABLE school.payment_reversals ENABLE ROW LEVEL SECURITY;
ALTER TABLE school.payment_reversals FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant ON school.payment_reversals USING(school.allowed(tenant_id)) WITH CHECK(school.allowed(tenant_id));
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.payment_reversals FOR EACH ROW EXECUTE FUNCTION school.immutable();
CREATE TRIGGER audit AFTER INSERT OR UPDATE OR DELETE ON school.payment_reversals FOR EACH ROW EXECUTE FUNCTION school.audit();

CREATE FUNCTION school.guard_payment_reversal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE invoice uuid;
BEGIN
 SELECT invoice_id INTO invoice FROM school.payments WHERE tenant_id=NEW.tenant_id AND id=NEW.payment_id;
 PERFORM 1 FROM school.invoices WHERE tenant_id=NEW.tenant_id AND id=invoice FOR UPDATE;
 IF EXISTS(SELECT 1 FROM school.refunds WHERE tenant_id=NEW.tenant_id AND payment_id=NEW.payment_id) THEN
 RAISE EXCEPTION 'reversal_after_refund' USING ERRCODE='23514'; END IF;
 NEW.created_by:=school.actor();
 RETURN NEW;
END $$;
CREATE TRIGGER payment_reversal_guard BEFORE INSERT ON school.payment_reversals FOR EACH ROW EXECUTE FUNCTION school.guard_payment_reversal();

-- FR-FIN-02 AC3: a payment reference is unique within the school and never reused,
-- even after a reversal. Checked on insert so existing rows are left untouched.
CREATE INDEX payments_reference ON school.payments(tenant_id,lower(reference)) WHERE reference IS NOT NULL;
CREATE OR REPLACE FUNCTION school.guard_payment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE due bigint; net bigint; enrollment_state text;
BEGIN
 NEW.reference:=nullif(btrim(NEW.reference),'');
 IF NEW.reference IS NOT NULL THEN
 PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text||':'||lower(NEW.reference),4));
 IF EXISTS(SELECT 1 FROM school.payments WHERE tenant_id=NEW.tenant_id AND lower(reference)=lower(NEW.reference)) THEN
 RAISE EXCEPTION 'duplicate_payment_reference' USING ERRCODE='23514'; END IF;
 END IF;
 SELECT amount_minor INTO due FROM school.invoices WHERE tenant_id=NEW.tenant_id AND id=NEW.invoice_id FOR UPDATE;
 SELECT e.status INTO enrollment_state FROM school.enrollments e JOIN school.invoices i ON i.tenant_id=e.tenant_id AND i.enrollment_id=e.id
 WHERE i.tenant_id=NEW.tenant_id AND i.id=NEW.invoice_id FOR UPDATE OF e;
 IF enrollment_state='cancelled' THEN RAISE EXCEPTION 'cancelled_enrollment' USING ERRCODE='23514'; END IF;
 SELECT COALESCE(sum(p.amount_minor),0) INTO net FROM school.payments p WHERE p.tenant_id=NEW.tenant_id AND p.invoice_id=NEW.invoice_id
 AND NOT EXISTS(SELECT 1 FROM school.payment_reversals v WHERE v.tenant_id=p.tenant_id AND v.payment_id=p.id);
 net:=net-COALESCE((SELECT sum(r.amount_minor) FROM school.refunds r JOIN school.payments p ON p.tenant_id=r.tenant_id AND p.id=r.payment_id WHERE p.tenant_id=NEW.tenant_id AND p.invoice_id=NEW.invoice_id),0);
 IF net+NEW.amount_minor>due THEN RAISE EXCEPTION 'overpayment' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION school.guard_refund() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE paid bigint; refunded bigint; invoice uuid;
BEGIN
 SELECT invoice_id INTO invoice FROM school.payments WHERE tenant_id=NEW.tenant_id AND id=NEW.payment_id;
 PERFORM 1 FROM school.invoices WHERE tenant_id=NEW.tenant_id AND id=invoice FOR UPDATE;
 IF EXISTS(SELECT 1 FROM school.payment_reversals WHERE tenant_id=NEW.tenant_id AND payment_id=NEW.payment_id) THEN
 RAISE EXCEPTION 'payment_reversed' USING ERRCODE='23514'; END IF;
 SELECT amount_minor INTO paid FROM school.payments WHERE tenant_id=NEW.tenant_id AND id=NEW.payment_id FOR UPDATE;
 SELECT COALESCE(sum(amount_minor),0) INTO refunded FROM school.refunds WHERE tenant_id=NEW.tenant_id AND payment_id=NEW.payment_id;
 IF refunded+NEW.amount_minor>paid THEN RAISE EXCEPTION 'over_refund' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE VIEW school.invoice_balances WITH (security_invoker=true) AS
 SELECT i.*,COALESCE(p.paid,0)-COALESCE(r.refunded,0) AS paid_minor,
 i.amount_minor-COALESCE(p.paid,0)+COALESCE(r.refunded,0) AS balance_minor
 FROM school.invoices i
 LEFT JOIN (SELECT p.tenant_id,p.invoice_id,sum(p.amount_minor) paid FROM school.payments p
 WHERE NOT EXISTS(SELECT 1 FROM school.payment_reversals v WHERE v.tenant_id=p.tenant_id AND v.payment_id=p.id)
 GROUP BY p.tenant_id,p.invoice_id) p ON p.tenant_id=i.tenant_id AND p.invoice_id=i.id
 LEFT JOIN (SELECT p.tenant_id,p.invoice_id,sum(r.amount_minor) refunded FROM school.refunds r JOIN school.payments p ON p.tenant_id=r.tenant_id AND p.id=r.payment_id GROUP BY p.tenant_id,p.invoice_id) r ON r.tenant_id=i.tenant_id AND r.invoice_id=i.id;

DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='school_runtime') THEN
  GRANT SELECT, INSERT ON school.payment_reversals TO school_runtime;
END IF; END $$;
