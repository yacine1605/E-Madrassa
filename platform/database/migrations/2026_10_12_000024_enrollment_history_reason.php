<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/024_enrollment_history_reason.sql')));
    }

    public function down(): void
    {
        // The function as 021 wrote it.
        preg_match('/CREATE FUNCTION school\.follow_enrollment\(.*?\$\$;/s', file_get_contents(database_path('schema/021_pupils_and_enrolments.sql')), $previous);
        DB::unprepared(preg_replace('/^CREATE FUNCTION/', 'CREATE OR REPLACE FUNCTION', $previous[0]));
    }
};
