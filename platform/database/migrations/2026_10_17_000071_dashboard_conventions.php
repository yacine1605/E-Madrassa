<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/071_dashboard_conventions.sql')));
    }

    public function down(): void
    {
        // The dashboards read on the cash basis again, the common costs kept apart, as 069's.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.program_costs_paid(date, date);
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key IN ('dashboard.result_basis', 'dashboard.common_costs');
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key IN ('dashboard.result_basis', 'dashboard.common_costs');
            SQL);
    }
};
