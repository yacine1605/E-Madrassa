<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/045_program_group_imports.sql')));
    }

    public function down(): void
    {
        // The programmes and groups imported stay; their prices are never deleted again, as 021 wrote it.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.discard_group(uuid);
            DROP FUNCTION school.discard_program(uuid);
            DROP TRIGGER immutable ON school.program_price;
            CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.program_price FOR EACH ROW EXECUTE FUNCTION school.immutable();
            DROP FUNCTION school.guard_program_price();
            SQL);
    }
};
