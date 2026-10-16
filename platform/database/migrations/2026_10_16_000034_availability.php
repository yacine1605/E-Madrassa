<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/034_availability.sql')));
    }

    public function down(): void
    {
        // The journal's policy as 032 wrote it, first: this one reads the declarations and days off.
        preg_match('/ALTER POLICY core_resource_reader ON school\.audit_log.*?;\r?\n/s', file_get_contents(database_path('schema/032_qualifications.sql')), $policy);
        DB::unprepared($policy[0]);
        // The declarations go with their tables; the journal keeps its entries, which nobody reads without their domain.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.available(uuid, date, time, time);
            DROP FUNCTION school.availability_on(uuid, date);
            DROP TABLE school.unavailability;
            DROP TABLE school.availability;
            DROP FUNCTION school.check_availability();
            DROP FUNCTION school.guard_unavailability();
            DROP FUNCTION school.guard_availability();
            DROP FUNCTION school.availability_hours(jsonb);
            DROP FUNCTION school.availability_slots(jsonb);
            DROP FUNCTION school.availability_in_force(uuid);
            DROP FUNCTION school.is_own_staff(uuid);
            DELETE FROM platform.audit_domain WHERE object_type IN ('availability', 'unavailability');
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'staff.availability.require_approval';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'staff.availability.require_approval';
            SQL);
    }
};
