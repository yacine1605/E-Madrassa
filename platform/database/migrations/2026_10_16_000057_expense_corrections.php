<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/057_expense_corrections.sql')));
    }

    public function down(): void
    {
        // The expenses' corrections go; the payment state, the reconciliation and what waits are 056's again.
        $payments = file_get_contents(database_path('schema/056_expense_payments.sql'));
        $restored = [];
        foreach (['school\.expense_payment_state\(p_status', 'platform\.reconciliation\(p_tenant', 'platform\.finance_pending\(p_tenant'] as $name) {
            preg_match('/CREATE (OR REPLACE )?FUNCTION '.$name.'.*?\$\$;/s', $payments, $function);
            $restored[] = preg_replace('/^CREATE (OR REPLACE )?FUNCTION/', 'CREATE OR REPLACE FUNCTION', $function[0]);
        }
        DB::unprepared(implode("\n", $restored));
        DB::unprepared(<<<'SQL'
            DROP TRIGGER "limit" ON school.expense_payment;
            DROP FUNCTION school.limit_expense_payment();
            DROP FUNCTION school.correct_expense(uuid, text, numeric, text, uuid);
            DROP FUNCTION school.expense_committed(uuid, uuid);
            DROP TABLE school.expense_correction;
            DROP FUNCTION school.guard_expense_correction();
            DELETE FROM platform.audit_domain WHERE object_type = 'expense_correction';
            DELETE FROM platform.sod_rule WHERE entity = 'expense_correction';
            SQL);
    }
};
