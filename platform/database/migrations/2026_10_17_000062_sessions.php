<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/062_sessions.sql')));
    }

    public function down(): void
    {
        // The sessions and their series go; the calendar's guard is 061's again.
        $calendar = file_get_contents(database_path('schema/061_calendar.sql'));
        preg_match('/CREATE FUNCTION school\.guard_calendar_exception\(\).*?END \$\$;/s', $calendar, $guard);
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.session_volume(uuid);
            DROP FUNCTION school.end_series(uuid, date, integer);
            DROP FUNCTION school.change_series(uuid, date, uuid, uuid, time, time, integer);
            DROP FUNCTION school.plan_series(uuid, uuid, uuid, uuid, integer, time, time, uuid, date, date, uuid);
            DROP FUNCTION school.series_conflicts(uuid, uuid, uuid, uuid, integer, time, time, date, date);
            DROP FUNCTION school.series_days(integer, date, date);
            DROP TABLE school.class_session;
            DROP TABLE school.session_series;
            DROP FUNCTION school.check_class_session();
            DROP FUNCTION school.guard_class_session();
            DROP FUNCTION school.guard_session_series();
            DROP FUNCTION school.session_conflicts(uuid, uuid, uuid, uuid, timestamptz, timestamptz, uuid);
            SQL);
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $guard[0]));
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.sessions_planned_on(uuid, date, date);
            DROP FUNCTION school.local_at(date, time);
            DROP FUNCTION school.local_day(timestamptz);
            DELETE FROM platform.audit_domain WHERE object_type IN ('class_session', 'session_series');
            DELETE FROM school.setting WHERE key = 'planning.travel_minutes';
            DELETE FROM platform.setting_definition WHERE key = 'planning.travel_minutes';
            SQL);
    }
};
