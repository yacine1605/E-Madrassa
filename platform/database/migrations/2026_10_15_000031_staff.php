<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/031_staff.sql')));
    }

    public function down(): void
    {
        $schema = fn (string $file) => file_get_contents(database_path('schema/'.$file));
        // The journal's policy as 028 wrote it, first: this one reads the staff's records.
        preg_match('/ALTER POLICY core_resource_reader ON school\.audit_log.*?;\r?\n/s', $schema('028_program_subjects.sql'), $policy);
        DB::unprepared($policy[0]);
        // A pupil's site as 029 attached it.
        preg_match('/CREATE FUNCTION school\.attach_site\(.*?END \$\$;/s', $schema('029_pupil_sites.sql'), $attach);
        DB::unprepared(preg_replace('/^CREATE FUNCTION/', 'CREATE OR REPLACE FUNCTION', $attach[0]));
        // The records go with their table; the journal keeps its entries, which nobody reads without their domain.
        DB::unprepared(<<<'SQL'
            DROP TABLE school.staff;
            DROP FUNCTION school.check_staff_account();
            DROP FUNCTION school.guard_staff();
            DROP FUNCTION school.next_staff_number(uuid);
            DROP FUNCTION school.require_staff(text, uuid);
            DROP FUNCTION school.staff_ids(text);
            DELETE FROM platform.audit_domain WHERE object_type = 'staff';
            SQL);
    }
};
