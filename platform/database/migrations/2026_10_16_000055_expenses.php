<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/055_expenses.sql')));
    }

    public function down(): void
    {
        // The expenses and their categories go; enable_module and the files' reader are 038's and 054's again.
        $leads = file_get_contents(database_path('schema/038_leads.sql'));
        preg_match('/CREATE OR REPLACE FUNCTION platform\.enable_module.*?END \$\$;/s', $leads, $enable);
        DB::unprepared(<<<'SQL'
            DROP TRIGGER expense ON school.file_object;
            DROP FUNCTION school.guard_expense_file();
            ALTER POLICY reader ON school.file_object USING (tenant_id = (SELECT school.tenant())
              AND (created_by = (SELECT school.actor()) OR category <> ALL ((SELECT school.file_categories('author'))::text[]))
              AND (category <> 'student_photo' OR EXISTS (SELECT 1 FROM school.student s WHERE s.tenant_id = file_object.tenant_id AND s.id = file_object.owner_id))
              AND CASE WHEN owner_type = 'payment' THEN category = 'finance_attachment'
                         AND EXISTS (SELECT 1 FROM school.payment p WHERE p.tenant_id = file_object.tenant_id AND p.id = file_object.owner_id)
                       ELSE category = ANY ((SELECT school.file_categories('read'))::text[]) END);
            DROP FUNCTION school.record_expense(date, numeric, uuid, text, text, text, uuid, uuid);
            DROP FUNCTION school.check_expense(school.expense);
            DROP TABLE school.expense;
            DROP FUNCTION school.guard_expense();
            DROP TABLE school.expense_category;
            DROP FUNCTION school.guard_expense_category();
            DROP FUNCTION school.expense_category_system(text);
            DROP TABLE platform.expense_category_default;
            DELETE FROM platform.audit_domain WHERE object_type IN ('expense', 'expense_category');
            ALTER TABLE school.setting DISABLE TRIGGER USER;
            DELETE FROM school.setting WHERE key = 'expense.approval_amount';
            ALTER TABLE school.setting ENABLE TRIGGER USER;
            DELETE FROM platform.setting_definition WHERE key = 'expense.approval_amount';
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission WHERE permission_code = 'expense.expense.approve';
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission WHERE permission_code = 'expense.expense.approve';
            DELETE FROM platform.permission WHERE code = 'expense.expense.approve';
            SQL);
        DB::unprepared($enable[0]);
    }
};
