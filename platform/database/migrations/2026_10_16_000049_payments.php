<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/049_payments.sql')));
    }

    public function down(): void
    {
        // What is paid as 048 read it, its owner kept; the payments go, with their setting.
        preg_match('/CREATE OR REPLACE FUNCTION school\.enrollment_paid\(.*?\$\$;/s', file_get_contents(database_path('schema/048_schedules_adjustments.sql')), $paid);
        DB::unprepared($paid[0]);
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.reverse_payment(uuid, text, uuid);
            DROP FUNCTION school.record_payment(uuid, numeric, date, text, text, boolean, uuid);
            DROP FUNCTION school.check_payment(school.payment);
            DROP TABLE school.payment;
            DROP FUNCTION school.apply_reversal();
            DROP FUNCTION school.guard_payment();
            DROP FUNCTION school.next_payment_reference(uuid);
            DROP FUNCTION school.payment_balance(uuid, uuid);
            DROP FUNCTION platform.enrollment_paid_of(uuid, uuid);
            DROP TABLE school.payment_counter;
            DELETE FROM platform.audit_domain WHERE object_type = 'payment';
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'finance.payment.methods';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'finance.payment.methods';
            SQL);
    }
};
