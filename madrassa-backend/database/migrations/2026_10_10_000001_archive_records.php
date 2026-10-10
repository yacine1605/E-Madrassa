<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/013_archiving.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER code_frozen ON school.programs;
DROP TRIGGER code_frozen ON school.levels;
DROP TRIGGER code_frozen ON school.subjects;
DROP TRIGGER code_frozen ON school.staff;
DROP TRIGGER code_frozen ON school.students;
DROP FUNCTION school.freeze_code();
ALTER TABLE school.campuses DROP COLUMN archived_at;
ALTER TABLE school.periods DROP COLUMN archived_at;
ALTER TABLE school.programs DROP COLUMN archived_at;
ALTER TABLE school.levels DROP COLUMN archived_at;
ALTER TABLE school.subjects DROP COLUMN archived_at;
ALTER TABLE school.curriculum DROP COLUMN archived_at;
ALTER TABLE school.offerings DROP COLUMN archived_at;
ALTER TABLE school.cohorts DROP COLUMN archived_at;
ALTER TABLE school.rooms DROP COLUMN archived_at;
ALTER TABLE school.students DROP COLUMN archived_at;
ALTER TABLE school.guardians DROP COLUMN archived_at;
ALTER TABLE school.student_guardians DROP COLUMN archived_at;
ALTER TABLE school.staff DROP COLUMN archived_at;
SQL);
    }
};
