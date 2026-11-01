<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/084_grading_rules.sql')));
    }

    public function down(): void
    {
        // The rules go with the permission that kept them; activities show their marks on 20 again.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.my_marks();
            DROP FUNCTION school.my_averages(date, date);
            DROP FUNCTION school.gradebook_averages(uuid, uuid, date, date);
            DROP FUNCTION school.gradebook(uuid, uuid, date, date);
            DROP FUNCTION school.average_of(uuid, uuid, uuid, uuid, date, date);
            DROP TRIGGER grading_rule ON school.activity_version;
            DROP FUNCTION school.keep_grading_rule();
            ALTER TABLE school.activity_version DROP COLUMN grading_rule_id;
            DROP FUNCTION school.on_scale(numeric, uuid);
            DROP FUNCTION school.round_mark(numeric, numeric, text);
            DROP FUNCTION school.rule_values(uuid);
            DROP FUNCTION school.grading_rule_for(uuid, uuid, date);
            DROP TABLE school.grading_rule;
            DROP FUNCTION school.guard_grading_rule();
            DELETE FROM platform.audit_domain WHERE object_type = 'grading_rule';
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission WHERE permission_code = 'lms.grading.manage';
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission WHERE permission_code = 'lms.grading.manage';
            DELETE FROM platform.permission WHERE code = 'lms.grading.manage';
            SQL);
    }
};
