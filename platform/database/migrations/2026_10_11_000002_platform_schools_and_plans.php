<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/002_platform.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE platform.tenant_module, platform.tenant_entitlement, platform.tenant, platform.plan_module, platform.plan,
 platform.module_dependency, platform.module CASCADE;
DROP FUNCTION platform.guard_tenant(), platform.guard_tenant_module(), platform.tenant_mode(uuid),
 platform.module_enabled(uuid, text), platform.enable_module(uuid, text), platform.disable_module(uuid, text),
 platform.set_tenant_status(uuid, text, text), platform.set_entitlement(uuid, date, date, integer, integer, integer, integer);
SQL);
    }
};
