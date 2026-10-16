<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/048_schedules_adjustments.sql')));
    }

    public function down(): void
    {
        // What is paid as 039 read it, first; the adjustments and schedules go, with the permission they introduced.
        preg_match('/CREATE FUNCTION school\.enrollment_paid\(.*?\$\$;/s', file_get_contents(database_path('schema/039_access_policy.sql')), $paid);
        DB::unprepared('DROP FUNCTION school.enrollment_paid(uuid, text); '.$paid[0]);
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.set_schedule(uuid, jsonb, text, uuid);
            DROP FUNCTION school.current_schedule(uuid);
            DROP TABLE school.installment;
            DROP TABLE school.payment_schedule;
            DROP TABLE school.adjustment;
            DROP FUNCTION school.guard_adjustment();
            DROP FUNCTION school.enrollment_due(uuid);
            DROP FUNCTION platform.enrollment_due_of(uuid, uuid);
            DROP FUNCTION school.finance_in_scope(text, uuid);
            DELETE FROM platform.audit_domain WHERE object_type IN ('adjustment', 'payment_schedule');
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'finance.discount.approval_percent';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'finance.discount.approval_percent';
            DELETE FROM platform.sod_rule WHERE entity = 'adjustment';
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission WHERE permission_code = 'finance.discount.approve';
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission WHERE permission_code = 'finance.discount.approve';
            DELETE FROM platform.permission WHERE code = 'finance.discount.approve';
            SQL);
    }
};
