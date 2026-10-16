<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/050_refunds.sql')));
    }

    public function down(): void
    {
        // What is owed and paid, the payments' checks and the adjustments' rules as 048 and 049 wrote them, their owners kept.
        $earlier = fn (string $file, string $pattern) => preg_match($pattern, file_get_contents(database_path("schema/$file")), $m)
            ? preg_replace('/^CREATE FUNCTION/', 'CREATE OR REPLACE FUNCTION', $m[0]) : throw new RuntimeException($pattern);
        DB::unprepared(<<<'SQL'
            ALTER TABLE school.payment DISABLE TRIGGER USER;
            DELETE FROM school.payment WHERE carried_from_refund_id IS NOT NULL;
            ALTER TABLE school.payment ENABLE TRIGGER USER;
            ALTER TABLE school.adjustment DISABLE TRIGGER USER;
            DELETE FROM school.adjustment WHERE kind = 'credit_retained';
            ALTER TABLE school.adjustment ENABLE TRIGGER USER;
            SQL);
        DB::unprepared($earlier('049_payments.sql', '/CREATE FUNCTION platform\.enrollment_paid_of\(.*?\$\$;/s'));
        DB::unprepared($earlier('048_schedules_adjustments.sql', '/CREATE FUNCTION platform\.enrollment_due_of\(.*?\$\$;/s'));
        DB::unprepared($earlier('048_schedules_adjustments.sql', '/CREATE FUNCTION school\.guard_adjustment\(\).*?END \$\$;/s'));
        DB::unprepared($earlier('049_payments.sql', '/CREATE FUNCTION school\.check_payment\(.*?END \$\$;/s'));
        DB::unprepared('DROP FUNCTION school.payment_balance(uuid, uuid); '
            .$earlier('049_payments.sql', '/CREATE FUNCTION school\.payment_balance\(.*?END \$\$;/s'));
        DB::unprepared(<<<'SQL'
            ALTER TABLE school.adjustment DROP CONSTRAINT adjustment_kind_check;
            ALTER TABLE school.adjustment ADD CONSTRAINT adjustment_kind_check CHECK (kind IN ('discount', 'debt_waiver', 'penalty'));
            DROP FUNCTION school.request_refund(uuid, text, uuid, numeric, text, uuid);
            DROP TRIGGER carried ON school.payment;
            DROP FUNCTION school.guard_carried_payment();
            ALTER TABLE school.payment DROP COLUMN carried_from_refund_id;
            DROP TABLE school.refund;
            DROP FUNCTION school.apply_carry_over();
            DROP FUNCTION school.guard_refund();
            DROP FUNCTION school.next_refund_reference(uuid);
            DROP TABLE school.refund_counter;
            DELETE FROM platform.audit_domain WHERE object_type = 'refund';
            SQL);
    }
};
