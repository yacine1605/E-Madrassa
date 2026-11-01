<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/085_activity_targets.sql')));
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION school.activity_targets();');
    }
};
