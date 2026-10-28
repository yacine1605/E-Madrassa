<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/078_courses.sql')));
    }

    public function down(): void
    {
        // The courses go with their permissions and settings; settings are checked as 039 had them.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.submit_lesson(uuid);
            DROP FUNCTION school.publish_lessons(text, uuid, timestamptz);
            DROP FUNCTION school.publication_preview(text, uuid);
            DROP FUNCTION school.published_lessons(uuid);
            DROP TABLE school.lesson_version;
            DROP TABLE school.lesson;
            DROP TABLE school.course_chapter;
            DROP TABLE school.course;
            DROP FUNCTION school.course_readable(uuid);
            DROP FUNCTION school.lms_course_ids();
            DROP FUNCTION school.guard_lesson();
            DROP FUNCTION school.guard_course_chapter();
            DROP FUNCTION school.check_course();
            DROP FUNCTION school.guard_course();
            DROP FUNCTION school.lms_content_error(jsonb);
            DROP FUNCTION school.lms_node_error(jsonb, text, integer);
            DROP FUNCTION school.lms_link_error(text);
            DELETE FROM platform.audit_domain WHERE object_type IN ('course', 'course_chapter', 'lesson');
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission WHERE permission_code IN ('lms.course.manage', 'lms.course.read');
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission WHERE permission_code IN ('lms.course.manage', 'lms.course.read');
            DELETE FROM platform.permission WHERE code IN ('lms.course.manage', 'lms.course.read');
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key IN ('lms.link_domains', 'lms.lesson.review_required');
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key IN ('lms.link_domains', 'lms.lesson.review_required');
            ALTER TABLE platform.setting_definition DROP CONSTRAINT setting_definition_value_type_check;
            ALTER TABLE platform.setting_definition ADD CONSTRAINT setting_definition_value_type_check
              CHECK (value_type IN ('integer', 'boolean', 'text', 'day_set', 'language_set', 'code_set', 'code'));
            SQL);
        $source = file_get_contents(database_path('schema/039_access_policy.sql'));
        preg_match('/CREATE OR REPLACE FUNCTION school\.guard_setting\(\).*?END \$\$;/s', $source, $function);
        DB::unprepared($function[0]);
    }
};
