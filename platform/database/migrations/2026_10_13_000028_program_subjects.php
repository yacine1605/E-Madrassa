<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/028_program_subjects.sql')));
    }

    public function down(): void
    {
        // The journal's policy as 023 wrote it, first: this one reads the lines.
        preg_match('/ALTER POLICY core_resource_reader ON school\.audit_log.*?;\r?\n/s', file_get_contents(database_path('schema/023_scope_cost.sql')), $policy);
        DB::unprepared($policy[0]);
        // The journal keeps its entries; without their domain, nobody reads them.
        DB::unprepared(<<<'SQL'
            DROP TABLE school.program_subject_volume;
            DROP TABLE school.program_subject;
            DROP FUNCTION school.version_program_subject();
            DROP FUNCTION school.check_program_subject();
            DROP FUNCTION school.guard_program_subject();
            DELETE FROM platform.audit_domain WHERE object_type = 'program_subject';
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'planning.session_minutes';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'planning.session_minutes';
            SQL);
    }
};
