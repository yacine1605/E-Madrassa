<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/032_qualifications.sql')));
    }

    public function down(): void
    {
        // The journal's policy as 031 wrote it, first: this one reads the qualifications.
        preg_match('/ALTER POLICY core_resource_reader ON school\.audit_log.*?;\r?\n/s', file_get_contents(database_path('schema/031_staff.sql')), $policy);
        DB::unprepared($policy[0]);
        // The lines go with their table; the journal keeps its entries, which nobody reads without their domain.
        DB::unprepared(<<<'SQL'
            DROP TABLE school.qualification;
            DROP FUNCTION school.qualified(uuid, uuid, uuid, date);
            DROP FUNCTION school.check_qualification();
            DROP FUNCTION school.guard_qualification();
            DELETE FROM platform.audit_domain WHERE object_type = 'qualification';
            SQL);
    }
};
