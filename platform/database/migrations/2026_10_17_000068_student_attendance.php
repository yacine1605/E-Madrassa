<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/068_student_attendance.sql')));
    }

    public function down(): void
    {
        // The roll calls and their history go.
        DB::unprepared(<<<'SQL'
            DROP TABLE school.student_attendance_change;
            DROP TABLE school.student_attendance;
            DROP FUNCTION school.record_student_attendance();
            DROP FUNCTION school.guard_student_attendance();
            DROP FUNCTION school.roll_pupils(uuid);
            DELETE FROM platform.audit_domain WHERE object_type = 'student_attendance';
            SQL);
    }
};
