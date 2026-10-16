<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/042_lead_retention.sql')));
    }

    public function down(): void
    {
        // The prospects' guards as 038 wrote them, and the counts of the next run as 030 did, first.
        $leads = file_get_contents(database_path('schema/038_leads.sql'));
        foreach (['guard_lead', 'guard_lead_child', 'guard_lead_interaction', 'guard_lead_reminder'] as $function) {
            preg_match('/CREATE FUNCTION school\.'.$function.'\(\).*?END \$\$;/s', $leads, $body);
            DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $body[0]));
        }
        preg_match('/CREATE FUNCTION school\.retention_due\(\).*?END \$\$;/s', file_get_contents(database_path('schema/030_retention.sql')), $due);
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $due[0]));
        // Prospects anonymised stay so: their phone and e-mail are not checked again.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION platform.apply_lead_retention(integer);
            DROP FUNCTION platform.lead_retention_candidates(uuid);
            DROP INDEX school.lead_retention;
            ALTER TABLE school.lead DROP CONSTRAINT lead_reachable;
            ALTER TABLE school.lead ADD CHECK (phone IS NOT NULL OR email IS NOT NULL) NOT VALID;
            ALTER TABLE school.lead DROP COLUMN anonymised_at;
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'retention.lead_months';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'retention.lead_months';
            SQL);
    }
};
