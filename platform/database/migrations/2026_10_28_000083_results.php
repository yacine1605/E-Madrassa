<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/083_results.sql')));
    }

    public function down(): void
    {
        // The results go with their publications, the corrections' columns and the event that told them.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER follow_work ON school.quiz_attempt;
            DROP TRIGGER follow_work ON school.homework_submission;
            DROP FUNCTION school.my_review(uuid);
            DROP FUNCTION school.key_visible(uuid, uuid, uuid);
            DROP FUNCTION school.my_results();
            DROP FUNCTION school.activity_results(uuid);
            DROP FUNCTION platform.publish_due_marks(integer);
            DROP FUNCTION school.regrade_activity(uuid, text);
            DROP FUNCTION school.publish_key(uuid);
            DROP FUNCTION school.publish_marks(uuid, uuid[], text);
            DROP FUNCTION school.decide_mark(uuid, uuid, text, text);
            DROP FUNCTION school.grade_homework(uuid, uuid, numeric, text);
            DROP FUNCTION school.correct_answer(uuid, uuid, numeric, text);
            DROP FUNCTION school.follow_work();
            DROP FUNCTION school.refresh_mark(uuid, uuid, uuid, text);
            DROP FUNCTION school.publish_mark(uuid, text);
            DROP FUNCTION school.tell_result(uuid, uuid, uuid);
            DROP FUNCTION school.pupil_deadline(uuid, uuid, uuid);
            DROP TABLE school.activity_mark_publication;
            DROP TABLE school.activity_mark;
            ALTER TABLE school.quiz_answer DROP COLUMN corrected_by, DROP COLUMN corrected_at, DROP COLUMN comment;
            ALTER TABLE school.activity DROP COLUMN key_published_at;
            DELETE FROM platform.audit_domain WHERE object_type = 'activity_mark';
            ALTER TABLE school.outbox DISABLE TRIGGER USER;
            ALTER TABLE school.notification DISABLE TRIGGER USER;
            DELETE FROM school.outbox WHERE event = 'lms.result_published';
            DELETE FROM school.notification WHERE event = 'lms.result_published';
            ALTER TABLE school.notification ENABLE TRIGGER USER;
            ALTER TABLE school.outbox ENABLE TRIGGER USER;
            DELETE FROM school.notification_preference WHERE event = 'lms.result_published';
            DELETE FROM platform.notification_event WHERE code = 'lms.result_published';
            SQL);
    }
};
