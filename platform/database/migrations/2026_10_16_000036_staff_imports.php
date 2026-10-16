<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/036_staff_imports.sql')));
    }

    public function down(): void
    {
        // The members keep everything but the reference of their file; the imports' reports keep theirs.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.discard_staff(uuid);
            DROP TRIGGER external_ref_frozen ON school.staff;
            ALTER TABLE school.staff DROP COLUMN external_ref;
            SQL);
    }
};
