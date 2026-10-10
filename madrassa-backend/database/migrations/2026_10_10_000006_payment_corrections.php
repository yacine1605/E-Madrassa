<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/018_payment_corrections.sql')));
    }

    public function down(): void
    {
        // Refuses to run once a reversal exists: dropping it would silently change balances.
        DB::unprepared(<<<'SQL'
DO $$ BEGIN IF EXISTS (SELECT 1 FROM school.payment_reversals) THEN
  RAISE EXCEPTION 'Payment reversals exist; restore a backup instead of rolling back.';
END IF; END $$;
CREATE OR REPLACE VIEW school.invoice_balances WITH (security_invoker=true) AS
 SELECT i.*,COALESCE(p.paid,0)-COALESCE(r.refunded,0) AS paid_minor,
 i.amount_minor-COALESCE(p.paid,0)+COALESCE(r.refunded,0) AS balance_minor
 FROM school.invoices i
 LEFT JOIN (SELECT tenant_id,invoice_id,sum(amount_minor) paid FROM school.payments GROUP BY tenant_id,invoice_id) p ON p.tenant_id=i.tenant_id AND p.invoice_id=i.id
 LEFT JOIN (SELECT p.tenant_id,p.invoice_id,sum(r.amount_minor) refunded FROM school.refunds r JOIN school.payments p ON p.tenant_id=r.tenant_id AND p.id=r.payment_id GROUP BY p.tenant_id,p.invoice_id) r ON r.tenant_id=i.tenant_id AND r.invoice_id=i.id;
CREATE OR REPLACE FUNCTION school.guard_refund() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE paid bigint; refunded bigint; invoice uuid;
BEGIN
 SELECT invoice_id INTO invoice FROM school.payments WHERE tenant_id=NEW.tenant_id AND id=NEW.payment_id;
 PERFORM 1 FROM school.invoices WHERE tenant_id=NEW.tenant_id AND id=invoice FOR UPDATE;
 SELECT amount_minor INTO paid FROM school.payments WHERE tenant_id=NEW.tenant_id AND id=NEW.payment_id FOR UPDATE;
 SELECT COALESCE(sum(amount_minor),0) INTO refunded FROM school.refunds WHERE tenant_id=NEW.tenant_id AND payment_id=NEW.payment_id;
 IF refunded+NEW.amount_minor>paid THEN RAISE EXCEPTION 'over_refund' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION school.guard_payment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE due bigint; net bigint; enrollment_state text;
BEGIN
 SELECT amount_minor INTO due FROM school.invoices WHERE tenant_id=NEW.tenant_id AND id=NEW.invoice_id FOR UPDATE;
 SELECT e.status INTO enrollment_state FROM school.enrollments e JOIN school.invoices i ON i.tenant_id=e.tenant_id AND i.enrollment_id=e.id
 WHERE i.tenant_id=NEW.tenant_id AND i.id=NEW.invoice_id FOR UPDATE OF e;
 IF enrollment_state='cancelled' THEN RAISE EXCEPTION 'cancelled_enrollment' USING ERRCODE='23514'; END IF;
 SELECT COALESCE(sum(p.amount_minor),0) INTO net FROM school.payments p WHERE p.tenant_id=NEW.tenant_id AND p.invoice_id=NEW.invoice_id;
 net:=net-COALESCE((SELECT sum(r.amount_minor) FROM school.refunds r JOIN school.payments p ON p.tenant_id=r.tenant_id AND p.id=r.payment_id WHERE p.tenant_id=NEW.tenant_id AND p.invoice_id=NEW.invoice_id),0);
 IF net+NEW.amount_minor>due THEN RAISE EXCEPTION 'overpayment' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
DROP INDEX school.payments_reference;
DROP TABLE school.payment_reversals;
DROP FUNCTION school.guard_payment_reversal();
SQL);
    }
};
