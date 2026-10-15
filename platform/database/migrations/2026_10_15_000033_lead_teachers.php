<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/033_lead_teachers.sql')));
    }

    public function down(): void
    {
        // The scopes as 029 computed them, first: this one reads the groups' lead teachers.
        preg_match('/CREATE OR REPLACE FUNCTION school\.core_ids\(.*?END \$\$;/s', file_get_contents(database_path('schema/029_pupil_sites.sql')), $scopes);
        DB::unprepared($scopes[0]);
        // The groups keep everything but their lead teacher; the journal keeps its entries.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER lead_teacher ON school.study_group;
            DROP TRIGGER lead_teacher_rules ON school.study_group;
            ALTER TABLE school.study_group DROP COLUMN lead_teacher_id;
            DROP FUNCTION school.check_lead_teacher();
            DROP FUNCTION school.guard_lead_teacher();
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission rp USING school.role r
              WHERE r.tenant_id = rp.tenant_id AND r.id = rp.role_id AND r.template_code = 'teacher'
              AND rp.permission_code IN ('student.record.read', 'guardian.record.read', 'staff.profile.read');
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission
              WHERE role_code = 'teacher' AND permission_code IN ('student.record.read', 'guardian.record.read', 'staff.profile.read');
            SQL);
    }
};
