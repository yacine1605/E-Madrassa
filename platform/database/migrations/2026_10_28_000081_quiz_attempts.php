<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/081_quiz_attempts.sql')));
    }

    public function down(): void
    {
        // The attempts go with their answers.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.my_attempt(uuid);
            DROP FUNCTION school.my_attempts(uuid);
            DROP FUNCTION platform.close_expired_attempts(integer);
            DROP FUNCTION school.submit_attempt(uuid, uuid);
            DROP FUNCTION school.save_answer(uuid, uuid, jsonb, text);
            DROP FUNCTION school.start_attempt(uuid, uuid);
            DROP FUNCTION school.close_my_expired(uuid);
            DROP FUNCTION school.close_attempt(uuid, text, timestamptz);
            DROP FUNCTION school.my_student();
            DROP TABLE school.quiz_answer;
            DROP TABLE school.quiz_attempt;
            DELETE FROM platform.audit_domain WHERE object_type = 'quiz_attempt';
            SQL);
    }
};
