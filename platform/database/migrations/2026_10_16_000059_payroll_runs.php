<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/059_payroll_runs.sql')));
    }

    public function down(): void
    {
        // The pays go, with their units, adjustments and lines; the rules are read by 058's readers again.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.adjust_payroll(uuid, text, numeric, text, uuid, uuid);
            DROP FUNCTION school.save_payroll_units(uuid, jsonb);
            DROP FUNCTION school.generate_payroll(date, uuid);
            DROP TABLE school.payroll_line;
            DROP TABLE school.payroll_adjustment;
            DROP TABLE school.payroll_unit;
            DROP TABLE school.payroll_run;
            DROP FUNCTION school.guard_payroll_part();
            DROP FUNCTION school.guard_payroll_run();
            DROP FUNCTION school.refresh_payroll_run(uuid, uuid);
            DROP FUNCTION school.payroll_stale(uuid, uuid);
            DROP FUNCTION school.payroll_detail(uuid, uuid);
            DROP FUNCTION school.payroll_unit_kind(text, text);
            DROP FUNCTION school.payroll_basis(uuid, uuid, date);
            DROP FUNCTION school.payroll_staff(uuid, uuid);
            DROP FUNCTION school.lock_payroll_run(uuid, date);
            ALTER POLICY resource_reader ON school.pay_rule USING ((SELECT school.can('payroll.rule.manage')) OR (SELECT school.can('payroll.amount.read')));
            ALTER POLICY resource_reader ON school.pay_rule_rate USING ((SELECT school.can('payroll.rule.manage')) OR (SELECT school.can('payroll.amount.read')));
            ALTER POLICY resource_reader ON school.pay_rule_bonus USING ((SELECT school.can('payroll.rule.manage')) OR (SELECT school.can('payroll.amount.read')));
            DELETE FROM platform.audit_domain WHERE object_type IN ('payroll_run', 'payroll_unit', 'payroll_adjustment', 'payroll_line');
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'payroll.prorate_fixed';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'payroll.prorate_fixed';
            SQL);
    }
};
