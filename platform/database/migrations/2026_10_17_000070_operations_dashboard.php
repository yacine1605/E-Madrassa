<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/070_operations_dashboard.sql')));
    }

    public function down(): void
    {
        // The dashboard's functions go, with the grant it gave the direction, the academic head and the teachers.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.operations_sessions(uuid, date, date);
            DROP FUNCTION school.operations_dashboard(date, date);
            DROP FUNCTION school.dashboard_group_ids(text);
            -- Every school's roles, the read-only ones too.
            ALTER TABLE school.role_permission DISABLE TRIGGER USER;
            DELETE FROM school.role_permission p USING school.role r
            WHERE p.permission_code = 'dashboard.operations.read' AND r.tenant_id = p.tenant_id AND r.id = p.role_id
              AND r.template_code IN ('direction', 'academic_hr', 'teacher');
            ALTER TABLE school.role_permission ENABLE TRIGGER USER;
            DELETE FROM platform.role_template_permission WHERE permission_code = 'dashboard.operations.read'
              AND role_code IN ('direction', 'academic_hr', 'teacher');
            SQL);
    }
};
