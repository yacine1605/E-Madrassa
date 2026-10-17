<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/063_teacher_groups.sql')));
    }

    public function down(): void
    {
        // A teacher's personal grant reads the groups they lead only, as 033 had it.
        $source = file_get_contents(database_path('schema/033_lead_teachers.sql'));
        preg_match('/CREATE OR REPLACE FUNCTION school\.core_ids\(p_permission text, p_entity text\).*?END \$\$;/s', $source, $function);
        DB::unprepared($function[0]);
    }
};
