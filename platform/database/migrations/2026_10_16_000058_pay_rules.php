<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/058_pay_rules.sql')));
    }

    public function down(): void
    {
        // The remuneration rules go, with their rates and bonuses.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.pay_rate(uuid, uuid, uuid);
            DROP FUNCTION school.pay_rule_on(uuid, uuid, date);
            DROP FUNCTION school.save_pay_rule(uuid, text, numeric, text, numeric, date, jsonb, jsonb, uuid);
            DROP TABLE school.pay_rule_bonus;
            DROP TABLE school.pay_rule_rate;
            DROP TABLE school.pay_rule;
            DROP FUNCTION school.check_pay_rule_complete();
            DROP FUNCTION school.check_pay_rule(uuid, uuid);
            DROP FUNCTION school.guard_pay_rule_part();
            DROP FUNCTION school.reopen_pay_rule();
            DROP FUNCTION school.check_pay_rule_staff();
            DROP FUNCTION school.pay_staff(uuid, uuid);
            DROP FUNCTION school.guard_pay_rule();
            DROP FUNCTION school.pay_month_start();
            DELETE FROM platform.audit_domain WHERE object_type IN ('pay_rule', 'pay_rule_rate', 'pay_rule_bonus');
            SQL);
    }
};
