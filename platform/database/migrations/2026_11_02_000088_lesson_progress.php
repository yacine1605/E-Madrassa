<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/088_lesson_progress.sql')));
    }

    public function down(): void
    {
        // The progression goes with its table; an activity's lesson is no longer checked against its course.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.course_progress(uuid, uuid);
            DROP FUNCTION school.my_progress(uuid);
            DROP FUNCTION school.lesson_mastery(uuid, uuid, uuid);
            DROP FUNCTION school.declare_lesson(uuid, boolean);
            DROP FUNCTION school.open_lesson(uuid);
            DROP FUNCTION school.my_lesson_course(uuid);
            DROP FUNCTION school.activity_lessons(uuid, uuid);
            DROP TRIGGER lesson_in_course ON school.activity;
            DROP FUNCTION school.guard_activity_lesson();
            DROP TABLE school.lesson_progress;
            DELETE FROM platform.audit_domain WHERE object_type = 'lesson_progress';
            SQL);
    }
};
