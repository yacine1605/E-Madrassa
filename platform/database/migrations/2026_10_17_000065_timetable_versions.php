<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/065_timetable_versions.sql')));
    }

    public function down(): void
    {
        // The versions and their notices go; a session's change is told as 064 had it, whatever its day.
        $replacements = file_get_contents(database_path('schema/064_replacements.sql'));
        preg_match('/CREATE FUNCTION school\.record_session_change\(\).*?END \$\$;/s', $replacements, $notice);
        DB::unprepared(str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $notice[0]));
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.discard_timetable(uuid, integer);
            DROP FUNCTION school.publish_timetable(uuid, integer);
            DROP FUNCTION school.notify_timetable(uuid, uuid[]);
            DROP FUNCTION school.validate_timetable(uuid, integer);
            DROP FUNCTION school.check_timetable(uuid);
            DROP FUNCTION school.refresh_timetable(uuid, integer);
            DROP FUNCTION school.timetable_version_for(uuid, integer);
            DROP FUNCTION school.prepare_timetable(date, date, text);
            DROP FUNCTION school.timetable_conflicts(uuid);
            DROP FUNCTION school.timetable_diff(uuid, uuid);
            DROP FUNCTION school.copy_timetable(uuid);
            DROP FUNCTION school.published_on(date);
            DROP TABLE school.timetable_version_session;
            DROP TABLE school.timetable_version;
            DROP FUNCTION school.guard_timetable_version();
            DELETE FROM platform.audit_domain WHERE object_type = 'timetable_version';
            DELETE FROM school.outbox WHERE event = 'schedule.timetable_published';
            DELETE FROM school.notification WHERE event = 'schedule.timetable_published';
            DELETE FROM school.notification_preference WHERE event = 'schedule.timetable_published';
            DELETE FROM platform.notification_event WHERE code = 'schedule.timetable_published';
            SQL);
    }
};
