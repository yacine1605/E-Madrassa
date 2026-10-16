<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/056_expense_payments.sql')));
    }

    public function down(): void
    {
        // The expenses' payments go; the reconciliation, the closure and the expense's checks are 053's and 055's again.
        $periods = file_get_contents(database_path('schema/053_finance_periods.sql'));
        $expenses = file_get_contents(database_path('schema/055_expenses.sql'));
        $restored = [];
        foreach ([[$periods, 'platform\.reconciliation\(p_tenant'], [$periods, 'school\.reconciliation\(p_month'], [$periods, 'school\.close_period\(p_month'],
            [$expenses, 'school\.check_expense\(e']] as [$file, $name]) {
            preg_match('/CREATE FUNCTION '.$name.'.*?\$\$;/s', $file, $function);
            $restored[] = preg_replace('/^CREATE FUNCTION/', 'CREATE OR REPLACE FUNCTION', $function[0]);
        }
        DB::unprepared(implode("\n", $restored));
        DB::unprepared(<<<'SQL'
            DROP FUNCTION platform.finance_pending(uuid, date, date);
            ALTER POLICY resource_reader ON school.expense
              USING (created_by = (SELECT school.actor()) OR (SELECT school.can('expense.expense.read')) OR (SELECT school.can('expense.expense.validate'))
                OR (SELECT school.can('expense.expense.approve')));
            ALTER POLICY resource_reader ON school.expense_category
              USING ((SELECT school.can('expense.expense.read')) OR (SELECT school.can('expense.expense.create')) OR (SELECT school.can('expense.expense.validate'))
                OR (SELECT school.can('expense.expense.approve')));
            DROP FUNCTION school.reverse_expense_payment(uuid, text, uuid);
            DROP FUNCTION school.record_expense_payment(uuid, numeric, date, text, text, uuid);
            DROP FUNCTION school.expense_paid(uuid, uuid);
            DROP TABLE school.expense_payment;
            DROP FUNCTION school.apply_expense_payment_reversal();
            DROP FUNCTION school.guard_expense_payment();
            DROP FUNCTION school.expense_payment_state(text, numeric, numeric);
            DELETE FROM platform.audit_domain WHERE object_type = 'expense_payment';
            DELETE FROM platform.sod_rule WHERE entity = 'expense_payment_reversal';
            SQL);
    }
};
