<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/012_report_card_archives.sql')));
    }

    public function down(): void
    {
        DB::statement('DROP TABLE school.report_card_archives');
        DB::statement('DROP FUNCTION school.guard_report_card_archive()');
    }
};
