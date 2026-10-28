<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/082_homework_submissions.sql')));
    }

    public function down(): void
    {
        // The submissions go with their versions.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.my_submissions(uuid);
            DROP FUNCTION school.flag_submission(uuid, text);
            DROP FUNCTION school.submit_homework(uuid, uuid, jsonb);
            DROP FUNCTION school.links_error(jsonb);
            DROP TABLE school.homework_submission;
            DELETE FROM platform.audit_domain WHERE object_type = 'homework_submission';
            SQL);
    }
};
