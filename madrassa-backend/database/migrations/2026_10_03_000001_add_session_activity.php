<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/008_session_activity.sql')));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE school.sessions DROP COLUMN activity');
    }
};
