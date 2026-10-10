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
        // Same SQL file as tests/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/002_deployment.sql')));
        if ($schools->isNotEmpty()) DB::table('school.deployment')->insert(['id'=>1,'institution_id'=>$schools->first()]);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE school.deployment');
    }
};
