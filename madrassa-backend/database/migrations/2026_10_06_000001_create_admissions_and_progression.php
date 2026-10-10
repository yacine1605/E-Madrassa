<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/011_admissions_progression.sql')));
    }

    public function down(): void { DB::unprepared('DROP TABLE school.annual_progressions; DROP TABLE school.admissions;'); }
};
