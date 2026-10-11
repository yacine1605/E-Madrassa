<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/015_support.sql')));
    }

    public function down(): void
    {
        // The functions as they were before: allowed and guard_writable of 003, effective_permissions of 005, file_categories of 011.
        foreach ([['003_school_core.sql', 'school\.allowed\(t uuid\)'], ['003_school_core.sql', 'school\.guard_writable\(\)'],
                  ['005_authorization.sql', 'school\.effective_permissions\('], ['011_files.sql', 'school\.file_categories\(']] as [$file, $function]) {
            preg_match('/CREATE (?:OR REPLACE )?FUNCTION '.$function.'.*?\$\$;/s', file_get_contents(database_path('schema/'.$file)), $previous);
            DB::unprepared('CREATE OR REPLACE '.preg_replace('/^CREATE (OR REPLACE )?/', '', $previous[0]));
        }
        DB::unprepared(<<<'SQL'
DROP FUNCTION platform.support_grants(uuid), school.log_support_access(text), school.support_grant_in_use();
DELETE FROM platform.audit_domain WHERE object_type = 'support_grant';
DROP TABLE school.support_grant;
DROP FUNCTION school.guard_support_grant();
ALTER TABLE school.role_permission DISABLE TRIGGER USER;
DELETE FROM school.role_permission WHERE permission_code = 'support.access.grant';
ALTER TABLE school.role_permission ENABLE TRIGGER USER;
DELETE FROM platform.role_template_permission WHERE permission_code = 'support.access.grant';
DELETE FROM platform.permission WHERE code = 'support.access.grant';
SQL);
    }
};
