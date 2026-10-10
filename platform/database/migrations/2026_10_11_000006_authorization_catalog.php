<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/006_authorization_catalog.sql')));
    }

    public function down(): void
    {
        // School creation as it was before roles: the definition in 003_school_core.sql.
        preg_match('/CREATE FUNCTION platform\.create_tenant\(.*?END \$\$;/s',
            file_get_contents(database_path('schema/003_school_core.sql')), $previous);
        DB::unprepared('CREATE OR REPLACE '.substr($previous[0], strlen('CREATE ')));
        // The schools' roles are copies of this catalog and go with it (development rollbacks only).
        DB::unprepared(<<<'SQL'
TRUNCATE school.role_assignment, school.role_permission, school.role,
  platform.sod_rule, platform.role_template_permission, platform.role_template, platform.permission;
SQL);
    }
};
