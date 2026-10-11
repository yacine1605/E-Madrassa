<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/014_console.sql')));
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION platform.supervision(), platform.ensure_user(text, text); DROP TABLE platform.operator;');
    }
};
