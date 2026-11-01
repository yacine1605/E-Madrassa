<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/086_pupil_notifications.sql')));
    }

    public function down(): void
    {
        // The notifications already sent go with their events, as their preferences and messages do.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER told ON school.homework_submission;
            DROP FUNCTION school.tell_submission_flagged();
            DROP TRIGGER told ON school.activity_audience;
            DROP FUNCTION school.tell_activity_published();
            DROP FUNCTION school.tell_pupil(uuid, uuid, text, jsonb);
            ALTER TABLE school.outbox DISABLE TRIGGER USER;
            DELETE FROM school.outbox WHERE event IN ('lms.activity_published', 'lms.submission_flagged');
            ALTER TABLE school.outbox ENABLE TRIGGER USER;
            ALTER TABLE school.notification DISABLE TRIGGER USER;
            DELETE FROM school.notification WHERE event IN ('lms.activity_published', 'lms.submission_flagged');
            ALTER TABLE school.notification ENABLE TRIGGER USER;
            DELETE FROM school.notification_preference WHERE event IN ('lms.activity_published', 'lms.submission_flagged');
            DELETE FROM platform.notification_event WHERE code IN ('lms.activity_published', 'lms.submission_flagged');
            SQL);
    }
};
