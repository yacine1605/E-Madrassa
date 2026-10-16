<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/040_progression.sql')));
    }

    public function down(): void
    {
        // The journal read as 038 left it, first: it names the decisions.
        preg_match('/ALTER POLICY core_resource_reader ON school\.audit_log.*?;\r?\n/s', file_get_contents(database_path('schema/038_leads.sql')), $policy);
        DB::unprepared($policy[0]);
        // The decisions go; the enrolments they made stay, as any other. The journal keeps its entries.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.decide_progression(uuid, text, text, uuid, date);
            DROP TABLE school.progression;
            DROP FUNCTION school.guard_progression();
            DROP FUNCTION school.progression_refusal(uuid, text, uuid);
            DELETE FROM platform.audit_domain WHERE object_type = 'progression';
            SQL);
    }
};
