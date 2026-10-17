<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/061_calendar.sql')));
    }

    public function down(): void
    {
        // The calendar's exceptions and time slots go, with the permission that kept them.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.day_open(date, uuid);
            DROP TABLE school.time_slot;
            DROP TABLE school.calendar_exception;
            DROP FUNCTION school.guard_time_slot();
            DROP FUNCTION school.guard_calendar_exception();
            DELETE FROM platform.audit_domain WHERE object_type IN ('calendar_exception', 'time_slot');
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission WHERE permission_code = 'schedule.calendar.manage';
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission WHERE permission_code = 'schedule.calendar.manage';
            DELETE FROM platform.permission WHERE code = 'schedule.calendar.manage';
            SQL);
    }
};
