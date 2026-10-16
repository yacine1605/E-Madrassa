<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/044_program_copy.sql')));
    }

    public function down(): void
    {
        // The copies stay, as programmes of their year, without the link to their source.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.copy_program(uuid, uuid);
            DROP INDEX school.program_copied_once;
            ALTER TABLE school.program DROP COLUMN copied_from;
            SQL);
    }
};
