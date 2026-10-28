<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/079_lesson_resources.sql')));
    }

    public function down(): void
    {
        // The teachers' resources go with the permission that added them.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.lesson_resources(uuid);
            DROP TABLE school.lesson_resource;
            DROP FUNCTION school.guard_lesson_resource();
            DROP FUNCTION school.lesson_course(uuid);
            DROP FUNCTION school.teaches_course(uuid);
            DROP FUNCTION school.lesson_visible(uuid);
            DELETE FROM platform.audit_domain WHERE object_type = 'lesson_resource';
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission WHERE permission_code = 'lms.resource.manage';
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission WHERE permission_code = 'lms.resource.manage';
            DELETE FROM platform.permission WHERE code = 'lms.resource.manage';
            SQL);
    }
};
