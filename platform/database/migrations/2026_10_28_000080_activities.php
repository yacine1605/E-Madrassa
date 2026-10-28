<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/080_activities.sql')));
    }

    public function down(): void
    {
        // The activities go with their versions, questions, audience and permissions.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.my_activities();
            DROP FUNCTION school.activity_state(boolean, timestamptz, timestamptz, timestamptz, timestamptz);
            DROP FUNCTION school.add_to_audience(uuid, uuid, timestamptz, text);
            DROP FUNCTION school.publish_activity(uuid);
            DROP FUNCTION school.activity_problems(uuid);
            DROP TABLE school.activity_audience;
            DROP TABLE school.activity_version;
            DROP TABLE school.activity_question;
            DROP TABLE school.activity;
            DROP FUNCTION school.guard_activity_audience();
            DROP FUNCTION school.guard_activity_question();
            DROP FUNCTION school.activity_keys(uuid);
            DROP FUNCTION school.guard_activity();
            DROP FUNCTION school.question_error(text, jsonb);
            DROP FUNCTION school.group_subject(uuid, uuid);
            DROP FUNCTION school.taught_keys();
            DROP FUNCTION school.manages_activity(uuid, uuid);
            DELETE FROM platform.audit_domain WHERE object_type IN ('activity', 'activity_question', 'activity_audience');
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission WHERE permission_code IN ('lms.activity.manage', 'lms.activity.take');
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission WHERE permission_code IN ('lms.activity.manage', 'lms.activity.take');
            DELETE FROM platform.permission WHERE code IN ('lms.activity.manage', 'lms.activity.take');
            SQL);
    }
};
