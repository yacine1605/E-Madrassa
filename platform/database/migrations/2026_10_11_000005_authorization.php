<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/005_authorization.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER last_manager ON school.membership;
DROP POLICY school_members ON school.membership;
DROP TABLE school.role_assignment, school.role_permission, school.role CASCADE;
DROP TABLE platform.sod_rule, platform.role_template_permission, platform.role_template, platform.permission;
DROP FUNCTION school.check_segregation(text, bigint, boolean), school.guard_last_manager(),
 school.manager_count_before(uuid, text, jsonb), school.guard_role_assignment(), school.guard_role_permission(),
 school.guard_role(), school.role_manages(uuid, uuid), school.manager_count(uuid), school.can_everywhere(text),
 school.can(text), school.my_permissions(), school.effective_permissions(bigint, uuid), school.is_system();
SQL);
    }
};
