<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE school.staff ADD COLUMN role text');
        if (DB::table('pg_roles')->where('rolname', 'school_runtime')->exists()) {
            DB::statement('GRANT UPDATE (role) ON school.staff TO school_runtime');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE school.staff DROP COLUMN role');
    }
};
