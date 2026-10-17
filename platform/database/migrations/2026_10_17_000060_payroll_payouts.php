<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/060_payroll_payouts.sql')));
    }

    public function down(): void
    {
        // The payouts go; the expenses they wrote stay, as validated `salaries` expenses. The expenses'
        // guards are 055 to 057's again, the reconciliation and what waits 057's, the pays' readers 059's.
        $restored = [];
        foreach ([['055_expenses', 'school\.guard_expense\(\)'], ['056_expense_payments', 'school\.guard_expense_payment\(\)'],
            ['057_expense_corrections', 'school\.guard_expense_correction\(\)'], ['057_expense_corrections', 'platform\.reconciliation\(p_tenant'],
            ['057_expense_corrections', 'platform\.finance_pending\(p_tenant']] as [$file, $name]) {
            preg_match('/CREATE (OR REPLACE )?FUNCTION '.$name.'.*?\$\$;/s', file_get_contents(database_path("schema/$file.sql")), $function);
            $restored[] = preg_replace('/^CREATE (OR REPLACE )?FUNCTION/', 'CREATE OR REPLACE FUNCTION', $function[0]);
        }
        DB::unprepared(implode("\n", $restored));
        $readers = "(SELECT school.can('payroll.amount.read')) OR (SELECT school.can('payroll.run.generate')) OR (SELECT school.can('payroll.run.validate'))";
        DB::unprepared(<<<SQL
            ALTER POLICY resource_reader ON school.payroll_run USING ($readers);
            ALTER POLICY resource_reader ON school.payroll_line USING ($readers);
            ALTER POLICY resource_reader ON school.payroll_unit USING ($readers);
            ALTER POLICY resource_reader ON school.payroll_adjustment USING ($readers);
            DROP POLICY payroll_reader ON school.expense;
            ALTER TABLE school.expense DROP COLUMN payroll_payout_id;
            DROP FUNCTION school.reverse_payroll_payout(uuid, text, uuid);
            DROP FUNCTION school.record_payroll_payout(uuid, numeric, date, text, text, uuid);
            DROP TABLE school.payroll_payout;
            DROP FUNCTION school.book_payroll_payout();
            DROP FUNCTION school.guard_payroll_payout();
            DROP FUNCTION school.own_pay(uuid, text);
            DROP FUNCTION school.payroll_state(text, numeric, numeric);
            DROP FUNCTION school.payroll_paid(uuid, uuid);
            DELETE FROM platform.audit_domain WHERE object_type = 'payroll_payout';
            DELETE FROM platform.sod_rule WHERE entity = 'payroll_payout_reversal';
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'payroll.self_view';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'payroll.self_view';
            SQL);
    }
};
