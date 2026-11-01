<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/087_decision_reason.sql')));
    }

    public function down(): void
    {
        // The decision as 083 wrote it, its reason kept on the mark only.
        preg_match('/CREATE FUNCTION school\.decide_mark\(.*?END \$\$;/s', file_get_contents(database_path('schema/083_results.sql')), $previous);
        DB::unprepared('CREATE OR REPLACE '.substr($previous[0], strlen('CREATE ')));
    }
};
