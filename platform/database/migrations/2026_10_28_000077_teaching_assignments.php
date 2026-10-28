<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/077_teaching_assignments.sql')));
    }

    public function down(): void
    {
        // The assignments go with the permission that kept them; a pupil's account stays linked, its rules
        // go; a teacher's personal grant reads the groups they teach and lead, as 063 had it.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER guard_account ON school.student;
            DROP TRIGGER rules_account ON school.student;
            DROP FUNCTION school.guard_student_account();
            DROP FUNCTION school.check_student_account();
            DROP FUNCTION school.staff_account(bigint);
            DROP FUNCTION school.learner_groups();
            DROP FUNCTION school.teaches(uuid, uuid, date);
            DROP TABLE school.teaching_assignment;
            DROP FUNCTION school.check_teaching_assignment();
            DROP FUNCTION school.guard_teaching_assignment();
            DELETE FROM platform.audit_domain WHERE object_type = 'teaching_assignment';
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission WHERE permission_code = 'lms.assignment.manage';
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission WHERE permission_code = 'lms.assignment.manage';
            DELETE FROM platform.permission WHERE code = 'lms.assignment.manage';
            SQL);
        $source = file_get_contents(database_path('schema/063_teacher_groups.sql'));
        preg_match('/CREATE OR REPLACE FUNCTION school\.core_ids\(p_permission text, p_entity text\).*?END \$\$;/s', $source, $function);
        DB::unprepared($function[0]);
    }
};
