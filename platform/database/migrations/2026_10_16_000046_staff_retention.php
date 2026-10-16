<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/046_staff_retention.sql')));
    }

    public function down(): void
    {
        // The staff's guards and rules as 031, 034 and 035 wrote them, and the counts of the next run as 042 did, first.
        $sources = [['031_staff.sql', 'guard_staff', false], ['035_contracts_and_leave.sql', 'guard_staff_contract', false],
            ['035_contracts_and_leave.sql', 'guard_staff_leave', false], ['035_contracts_and_leave.sql', 'guard_unavailability', true],
            ['034_availability.sql', 'guard_availability', false], ['035_contracts_and_leave.sql', 'check_staff_terms', false],
            ['035_contracts_and_leave.sql', 'check_availability', true], ['042_lead_retention.sql', 'retention_due', true]];
        foreach ($sources as [$file, $function, $last]) {
            preg_match_all('/CREATE (?:OR REPLACE )?FUNCTION school\.'.$function.'\(\).*?END \$\$;/s', file_get_contents(database_path('schema/'.$file)), $found);
            DB::unprepared(preg_replace('/^CREATE (OR REPLACE )?FUNCTION/', 'CREATE OR REPLACE FUNCTION', $last ? end($found[0]) : $found[0][0]));
        }
        // Members anonymised stay so.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION platform.apply_staff_retention(integer);
            DROP FUNCTION platform.staff_retention_candidates(uuid);
            DROP INDEX school.staff_retention;
            ALTER TABLE school.staff DROP COLUMN anonymised_at;
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'retention.staff_years';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'retention.staff_years';
            SQL);
    }
};
