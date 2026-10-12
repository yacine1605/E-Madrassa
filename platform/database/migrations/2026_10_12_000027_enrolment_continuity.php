<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/027_enrolment_continuity.sql')));
    }

    public function down(): void
    {
        // The transfers' links stay in their column (021): only the way to make them goes.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION school.transfer_enrollment(uuid, uuid, uuid, text, integer);
            DROP TRIGGER transfer ON school.enrollment;
            DROP FUNCTION school.link_transfer();
            DROP INDEX school.enrollment_transfer_once;
            DROP TRIGGER primary_contact ON school.student;
            DROP TRIGGER primary_contact ON school.guardian;
            DROP TRIGGER primary_contact ON school.student_guardian;
            DROP FUNCTION school.check_primary_contact();
            DROP FUNCTION school.keep_primary_contact(uuid, uuid);
            SQL);
    }
};
