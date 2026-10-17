<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/069_finance_dashboard.sql')));
    }

    public function down(): void
    {
        // The dashboard's functions go; the operations they summed stay as they are.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.program_profitability(date, date);
            DROP FUNCTION school.finance_dashboard(date, date);
            SQL);
    }
};
