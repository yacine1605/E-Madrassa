<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/003_allow_cohort_edits.sql')));
    }

    public function down(): void
    {
        DB::unprepared('CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.cohorts FOR EACH ROW EXECUTE FUNCTION school.immutable()');
    }
};
