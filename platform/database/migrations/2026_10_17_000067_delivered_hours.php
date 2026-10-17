<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/067_delivered_hours.sql')));
    }

    public function down(): void
    {
        // The corrections and the hours go; a pay's units are again those entered by hand, as 059 had it.
        $payroll = file_get_contents(database_path('schema/059_payroll_runs.sql'));
        preg_match('/CREATE FUNCTION school\.payroll_detail\(.*?END \$\$;/s', $payroll, $detail);
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $detail[0]));
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.delivered_sessions(uuid, date, date);
            DROP VIEW school.attendance_effective;
            DROP TABLE school.attendance_correction;
            DROP FUNCTION school.guard_attendance_correction();
            DROP FUNCTION school.month_paid(uuid, date);
            SQL);
    }
};
