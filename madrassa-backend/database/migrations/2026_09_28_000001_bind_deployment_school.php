<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Never silently choose one school from an existing shared database.
        $schools = DB::table('school.institutions')->pluck('id');
        if ($schools->count() > 1) {
            throw new RuntimeException('This database contains multiple schools. Split them into separate databases before enabling a dedicated deployment.');
        }
        DB::statement('CREATE TABLE school.deployment (id integer PRIMARY KEY CHECK (id = 1), institution_id uuid NOT NULL UNIQUE REFERENCES school.institutions(id))');
        if ($schools->isNotEmpty()) DB::table('school.deployment')->insert(['id'=>1,'institution_id'=>$schools->first()]);
        if (DB::table('pg_roles')->where('rolname', 'school_runtime')->exists()) {
            DB::statement('GRANT SELECT ON school.deployment TO school_runtime');
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE school.deployment');
    }
};
