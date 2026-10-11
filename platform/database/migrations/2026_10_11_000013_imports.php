<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/013_imports.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER no_delete ON school.membership;
CREATE TRIGGER no_delete BEFORE DELETE ON school.membership FOR EACH ROW EXECUTE FUNCTION school.immutable();
DROP FUNCTION school.guard_membership_delete(), school.discard_account(bigint);
DROP TABLE school.import_row, school.import_batch;
DROP FUNCTION school.guard_import_batch();
SQL);
    }
};
