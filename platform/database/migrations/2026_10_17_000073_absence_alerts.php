<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/073_absence_alerts.sql')));
    }

    public function down(): void
    {
        // No threshold again: the alerts, their notifications and the settings go.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER absence_threshold ON school.student_attendance;
            DROP FUNCTION school.check_absence_threshold();
            DROP FUNCTION school.absence_watchers(uuid, uuid);
            DROP TABLE school.absence_alert;
            DELETE FROM school.outbox WHERE event = 'attendance.absence_threshold';
            DELETE FROM school.notification WHERE event = 'attendance.absence_threshold';
            DELETE FROM school.notification_preference WHERE event = 'attendance.absence_threshold';
            DELETE FROM platform.notification_event WHERE code = 'attendance.absence_threshold';
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key IN ('attendance.absence_threshold', 'attendance.absence_window_days');
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key IN ('attendance.absence_threshold', 'attendance.absence_window_days');
            SQL);
    }
};
