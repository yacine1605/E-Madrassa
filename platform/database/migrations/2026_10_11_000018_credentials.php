<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/018_credentials.sql')));
    }

    public function down(): void
    {
        // The role stays, like the others: it belongs to the cluster, and deploy/roles.sql names it.
        DB::unprepared(<<<'SQL'
REVOKE ALL ON public.users, public.mfa_recovery_code FROM platform_auth;
ALTER TABLE public.users DROP COLUMN has_password;
SQL);
    }
};
