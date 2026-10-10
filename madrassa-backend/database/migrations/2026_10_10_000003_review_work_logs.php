<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/015_work_log_review.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION school.guard_work_log() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE staff uuid;
BEGIN
 SELECT staff_id INTO staff FROM school.contracts WHERE tenant_id=NEW.tenant_id AND id=NEW.contract_id
 AND starts_on<=NEW.worked_on AND (ends_on IS NULL OR ends_on>=NEW.worked_on);
 IF staff IS NULL THEN RAISE EXCEPTION 'work_outside_contract' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended(NEW.tenant_id::text||':'||staff::text||':'||date_trunc('month',NEW.worked_on)::date::text,2));
 IF EXISTS(SELECT 1 FROM school.payroll_runs WHERE tenant_id=NEW.tenant_id AND staff_id=staff AND month=date_trunc('month',NEW.worked_on)::date) THEN
 RAISE EXCEPTION 'payroll_already_closed' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND OLD.validated_at IS NOT NULL THEN RAISE EXCEPTION 'validated_hours_frozen' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
ALTER TABLE school.work_logs DROP COLUMN entered_by, DROP COLUMN reviewed_by;
SQL);
    }
};
