<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/003_school_core.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP FUNCTION platform.create_tenant(uuid, text, text, text, text, date, date, integer, integer, text, text, bigint);
DROP TABLE school.site, school.audit_log, school.membership CASCADE;
DROP TRIGGER audit ON platform.tenant;
DROP POLICY console ON platform.tenant;
DROP POLICY own_school ON platform.tenant;
ALTER TABLE platform.tenant NO FORCE ROW LEVEL SECURITY;
ALTER TABLE platform.tenant DISABLE ROW LEVEL SECURITY;
DROP PROCEDURE school.protect(regclass);
DROP FUNCTION school.guard_last_site(), platform.audit_tenant(), school.audit(), school.guard_writable(),
 school.module_enabled(text), school.allowed(uuid), school.guard_membership();
SQL);
    }
};
