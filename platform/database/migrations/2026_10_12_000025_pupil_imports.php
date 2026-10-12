<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Same SQL file as tests/sql/database.mjs, so tests run on the production schema.
        DB::unprepared(file_get_contents(database_path('schema/025_pupil_imports.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER immutable ON school.enrollment_status_history;
            CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON school.enrollment_status_history FOR EACH ROW EXECUTE FUNCTION school.immutable();
            DROP FUNCTION school.guard_history_delete();
            DROP FUNCTION school.discard_enrollment(uuid);
            DROP FUNCTION school.discard_guardian_link(uuid);
            DROP FUNCTION school.discard_guardian(uuid);
            DROP FUNCTION school.discard_student(uuid);
            DROP FUNCTION school.import_created(text, uuid);
            DROP TRIGGER external_ref_frozen ON school.guardian;
            DROP TRIGGER external_ref_frozen ON school.student;
            ALTER TABLE school.guardian DROP COLUMN external_ref;
            ALTER TABLE school.student DROP COLUMN external_ref;
            SQL);
        // The journal's function as 021 wrote it.
        preg_match('/CREATE OR REPLACE FUNCTION school\.audit\(\).*?\$\$;/s', file_get_contents(database_path('schema/021_pupils_and_enrolments.sql')), $previous);
        DB::unprepared($previous[0]);
    }
};
