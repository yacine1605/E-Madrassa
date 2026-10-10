<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/017_audit_values.sql')));
    }

    public function down(): void
    {
        // The audit log is append-only; rolling back removes the recorded values.
        DB::unprepared(<<<'SQL'
DROP TRIGGER audit ON school.teacher_assignments;
DROP TRIGGER audit ON school.teacher_qualifications;
DROP TRIGGER audit ON school.report_card_terms;
DROP TRIGGER audit ON school.report_card_observations;
CREATE OR REPLACE FUNCTION school.audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE row_data jsonb; changed jsonb;
BEGIN
 row_data:=CASE WHEN TG_OP='DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
 SELECT COALESCE(jsonb_agg(key),'[]') INTO changed FROM jsonb_each(row_data) WHERE TG_OP<>'UPDATE' OR value IS DISTINCT FROM to_jsonb(OLD)->key;
 INSERT INTO school.audit_events(tenant_id,actor_id,entity,entity_id,action,changed_fields)
 VALUES((row_data->>'tenant_id')::uuid,school.actor(),TG_TABLE_NAME,(row_data->>'id')::uuid,TG_OP,changed);
 RETURN COALESCE(NEW,OLD);
END $$;
ALTER TABLE school.audit_events DROP COLUMN before, DROP COLUMN after, DROP COLUMN reason, DROP COLUMN request_id, DROP COLUMN ip;
SQL);
    }
};
