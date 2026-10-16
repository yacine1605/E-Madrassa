<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/053_finance_periods.sql')));
    }

    public function down(): void
    {
        // The closures go, and the closed months' guard with them.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER closed ON school.payment;
            DROP TRIGGER closed ON school.refund;
            DROP FUNCTION school.guard_closed_period();
            DROP FUNCTION school.close_period(date);
            DROP FUNCTION school.reconciliation(date);
            DROP FUNCTION platform.reconciliation(uuid, date, date);
            DROP TABLE school.finance_period;
            DROP FUNCTION school.guard_finance_period();
            DROP FUNCTION school.period_closed(uuid, date);
            DELETE FROM platform.audit_domain WHERE object_type = 'finance_period';
            SQL);
    }
};
